<?php
/**
 * Functional — SiteAgent governance bridge. A governed run arms itself for the
 * target post; the snapshot is captured only when the tool actually writes page
 * data (calls before_page_write, as Elementor_MCP_Data::save_page_data does),
 * and the page is rolled back if the write fails.
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

class GovernanceFunctionalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_snap'] = array(
			'fail_snapshot'  => false,
			'fail_restore'   => false,
			'snapshot_calls' => array(),
			'restore_calls'  => array(),
			'seq'            => 0,
		);
		$GLOBALS['_aura_grant'] = array(
			'enforced'      => false,
			'verify_result' => true,
			'verify_calls'  => array(),
		);
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']  = false;
		unset( $GLOBALS['_http_response'] );
		unset( $GLOBALS['_http_response_queue'] );
		unset( $GLOBALS['_http_last_url'] );
		unset( $GLOBALS['_permalink'] );
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
		parent::tearDown();
	}

	/** Turn on SiteAgent's grant regime AND this plugin's opt-in. */
	private function require_grants( ?string $header = null ): void {
		$GLOBALS['_aura_grant']['enforced'] = true;
		$GLOBALS['_emcp_require_grants']    = true;
		if ( null !== $header ) {
			$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = $header;
		}
	}

	/** A write-capable ability (readonly=false) with the given annotations override. */
	private function write_args( $callback, array $annotations = array() ): array {
		return array(
			'label'            => 'Update element',
			'execute_callback' => $callback,
			'meta'             => array(
				'annotations' => array_merge(
					array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					$annotations
				),
			),
		);
	}

	/**
	 * A callback that behaves like a page-data write: it calls before_page_write()
	 * (as save_page_data does) and fails closed if governance refuses.
	 *
	 * @param mixed $return Value to return after the (successful) snapshot.
	 */
	private function page_writer( $return ): callable {
		return static function ( $input ) use ( $return ) {
			$gate = \Elementor_MCP_Governance::before_page_write( $input['post_id'] ?? 0 );
			if ( is_wp_error( $gate ) ) {
				return $gate; // mirrors save_page_data() returning the gate error
			}
			return $return;
		};
	}

	// --- wrap_ability decision logic ---------------------------------------

	public function test_write_capable_ability_is_wrapped(): void {
		$original = static function ( $input ) {
			return array( 'ok' => true ); };
		$wrapped  = \Elementor_MCP_Governance::wrap_ability( 'elementor-mcp/update-element', $this->write_args( $original ) );

		$this->assertNotSame( $original, $wrapped['execute_callback'] );
		$this->assertIsCallable( $wrapped['execute_callback'] );
	}

	public function test_readonly_ability_is_not_wrapped(): void {
		$original = static function ( $input ) {
			return array( 'ok' => true ); };
		$wrapped  = \Elementor_MCP_Governance::wrap_ability( 'elementor-mcp/export-page', $this->write_args( $original, array( 'readonly' => true ) ) );

		$this->assertSame( $original, $wrapped['execute_callback'], 'Read-only tools stay untouched.' );
	}

	public function test_ability_without_annotations_is_not_wrapped(): void {
		$original = static function ( $input ) {
			return array( 'ok' => true ); };
		$args     = $this->write_args( $original );
		unset( $args['meta'] );
		$wrapped = \Elementor_MCP_Governance::wrap_ability( 'elementor-mcp/x', $args );

		$this->assertSame( $original, $wrapped['execute_callback'] );
	}

	public function test_ability_without_callback_is_not_wrapped(): void {
		$args = $this->write_args( 'not-callable-#' );
		unset( $args['execute_callback'] );
		$wrapped = \Elementor_MCP_Governance::wrap_ability( 'elementor-mcp/update-element', $args );

		$this->assertArrayNotHasKey( 'execute_callback', $wrapped );
	}

	// --- run_governed behaviour --------------------------------------------

	public function test_page_write_is_snapshotted_before_success(): void {
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'updated' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'updated' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( 55, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'] );
		$this->assertSame( \Elementor_MCP_Governance::PAGE_META_KEYS, $GLOBALS['_aura_snap']['snapshot_calls'][0]['keys'] );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_tool_that_writes_no_page_data_is_not_snapshotted(): void {
		// A readonly=false tool that never calls save_page_data (template
		// conditions, SEO meta, a preview) must NOT be snapshotted — this is the
		// whole point of the chokepoint trigger (Codex R2/R3).
		$called = false;
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/set-template-conditions',
			static function ( $input ) use ( &$called ) {
				$called = true;
				return array( 'ok' => true ); // no before_page_write() call
			},
			array( 'post_id' => 55 )
		);

		$this->assertTrue( $called );
		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_snapshot_is_captured_once_per_run(): void {
		// A tool that writes both tree and settings triggers before_page_write
		// twice; only one snapshot should be taken.
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/build-page',
			static function ( $input ) {
				\Elementor_MCP_Governance::before_page_write( $input['post_id'] );
				\Elementor_MCP_Governance::before_page_write( $input['post_id'] );
				return array( 'built' => true );
			},
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'built' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_write_without_post_id_passes_through_ungoverned(): void {
		$called = false;
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/create-variable',
			static function ( $input ) use ( &$called ) {
				$called = true;
				return array( 'created' => true );
			},
			array( 'label' => 'brand' )
		);

		$this->assertTrue( $called );
		$this->assertSame( array( 'created' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_negative_post_id_is_normalized_before_snapshot(): void {
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => -55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( 55, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'], 'abs(post_id) is snapshotted.' );
	}

	public function test_snapshot_failure_denies_the_write(): void {
		$GLOBALS['_aura_snap']['fail_snapshot'] = true;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'should' => 'not reach' ) ),
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_snapshot_failed', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'], 'Nothing to restore when snapshot failed.' );
	}

	public function test_failed_write_is_rolled_back(): void {
		$error  = new \WP_Error( 'save_rejected', 'Elementor rejected the data.' );
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( $error ),
			array( 'post_id' => 77 )
		);

		$this->assertSame( $error, $result, 'The original failure is returned unchanged after a clean rollback.' );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_thrown_write_is_rolled_back(): void {
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static function ( $input ) {
				\Elementor_MCP_Governance::before_page_write( $input['post_id'] );
				throw new \RuntimeException( 'boom' );
			},
			array( 'post_id' => 88 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_write_threw', $result->get_error_code() );
		$this->assertStringContainsString( 'boom', $result->get_error_message() );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_thrown_write_without_page_snapshot_is_not_rolled_back(): void {
		// A tool that throws BEFORE writing any page data has no snapshot, so there
		// is nothing to restore.
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static function ( $input ) {
				throw new \RuntimeException( 'early failure' );
			},
			array( 'post_id' => 88 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_write_threw', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_failed_rollback_after_wp_error_is_surfaced(): void {
		$GLOBALS['_aura_snap']['fail_restore'] = true;
		$result                                = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( new \WP_Error( 'save_rejected', 'bad data' ) ),
			array( 'post_id' => 77 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_rollback_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'may be partially written', $result->get_error_message() );
	}

	public function test_failed_rollback_after_throw_is_surfaced(): void {
		$GLOBALS['_aura_snap']['fail_restore'] = true;
		$result                                = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static function ( $input ) {
				\Elementor_MCP_Governance::before_page_write( $input['post_id'] );
				throw new \RuntimeException( 'kaboom' );
			},
			array( 'post_id' => 88 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_rollback_failed', $result->get_error_code() );
	}

	// --- server-enforced approval grants -----------------------------------

	public function test_no_grant_required_when_regime_inactive(): void {
		// Grant regime off (no gateway key) → writes proceed without a grant.
		$this->assertFalse( \Elementor_MCP_Governance::grants_required() );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);
		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_grant']['verify_calls'] );
	}

	public function test_no_grant_required_when_enforced_but_opt_in_off(): void {
		// Gateway key present, but this plugin has NOT opted in → no grant needed
		// (the anti-brick default).
		$GLOBALS['_aura_grant']['enforced'] = true;
		$GLOBALS['_emcp_require_grants']    = false;
		$this->assertFalse( \Elementor_MCP_Governance::grants_required() );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);
		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_grant']['verify_calls'] );
	}

	public function test_missing_grant_denies_the_write_before_it_runs(): void {
		$this->require_grants(); // enforced + opted in, but NO header
		$called = false;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			function ( $input ) use ( &$called ) {
				$called = true;
				return array( 'ok' => true );
			},
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_grant_required', $result->get_error_code() );
		$this->assertFalse( $called, 'The write callback must not run without a grant (before any insert).' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_preview_call_needs_no_grant(): void {
		// An a11y/SEO generator (preview-capable: its schema declares `apply`) run
		// with apply=false is a dry run that writes nothing, so grant enforcement
		// must not block it (Codex R2 P2). Fourth arg = preview_capable.
		$this->require_grants(); // enforced + opted in, but NO header
		$called = false;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/generate-meta-tags',
			static function ( $input ) use ( &$called ) {
				$called = true;
				return array( 'preview' => 'title/desc' );
			},
			array( 'post_id' => 55, 'apply' => false ),
			true
		);

		$this->assertTrue( $called );
		$this->assertSame( array( 'preview' => 'title/desc' ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_grant']['verify_calls'], 'Preview → no grant needed.' );
	}

	public function test_preview_capable_tool_applying_changes_requires_grant(): void {
		// Same preview-capable tool, but apply=true → it writes → grant required.
		$this->require_grants(); // no header
		$called = false;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/generate-meta-tags',
			function ( $input ) use ( &$called ) {
				$called = true;
				return array( 'applied' => true );
			},
			array( 'post_id' => 55, 'apply' => true ),
			true
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_grant_required', $result->get_error_code() );
		$this->assertFalse( $called );
	}

	public function test_valid_grant_allows_the_write_and_binds_tool_and_params(): void {
		$this->require_grants( 'payload.signature' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55, 'foo' => 'bar' )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_grant']['verify_calls'] );
		$call = $GLOBALS['_aura_grant']['verify_calls'][0];
		$this->assertSame( 'payload.signature', $call['header'] );
		// Bound to the EXPOSED MCP tool name (slashes → dashes), which is what the
		// gateway signs — not the internal ability name.
		$this->assertSame( 'elementor-mcp-update-element', $call['tool'] );
		$this->assertSame( array( 'post_id' => 55, 'foo' => 'bar' ), $call['params'] );
		// The snapshot still happens after the grant clears.
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_invalid_grant_denies_the_write_before_it_runs(): void {
		$this->require_grants( 'payload.signature' );
		$GLOBALS['_aura_grant']['verify_result'] = 'grant already used';
		$called                                  = false;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			function ( $input ) use ( &$called ) {
				$called = true;
				return array( 'ok' => true );
			},
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_grant_invalid', $result->get_error_code() );
		$this->assertStringContainsString( 'grant already used', $result->get_error_message() );
		$this->assertFalse( $called, 'A rejected grant must not run the write.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_create_style_write_without_input_post_id_requires_grant(): void {
		// create-page / build-page / theme-template creation persist page data to a
		// NEW post whose id is not in the input — they must still be grant-gated
		// (Codex R3 P1). No input post_id, so the run arms without one and the grant
		// is enforced when the tool writes to the new post.
		$this->require_grants(); // enforced + opted in, but NO header
		$inserted = false;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/create-page',
			function ( $input ) use ( &$inserted ) {
				$inserted = true; // stands in for wp_insert_post()
				\Elementor_MCP_Governance::before_page_write( 4242 );
				return array( 'created' => 4242 );
			},
			array( 'title' => 'New Page' ) // NO post_id
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_grant_required', $result->get_error_code() );
		$this->assertFalse( $inserted, 'The callback (and its wp_insert_post) must not run before approval.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_create_style_write_with_valid_grant_proceeds_and_snapshots_new_post(): void {
		$this->require_grants( 'payload.signature' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/create-page',
			static function ( $input ) {
				$gate = \Elementor_MCP_Governance::before_page_write( 4242 );
				if ( is_wp_error( $gate ) ) {
					return $gate;
				}
				return array( 'created' => 4242 );
			},
			array( 'title' => 'New Page' )
		);

		$this->assertSame( array( 'created' => 4242 ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_grant']['verify_calls'] );
		$this->assertSame( 'elementor-mcp-create-page', $GLOBALS['_aura_grant']['verify_calls'][0]['tool'] );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( 4242, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'] );
	}

	// --- post-write render check -------------------------------------------

	/** Publish a post so the render check treats it as publicly served. */
	private function publish( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array( 'ID' => $id, 'post_status' => 'publish' );
	}

	private function enable_render_check(): void {
		$GLOBALS['_emcp_render_check'] = true;
	}

	private function fake_http( int $code, string $body ): void {
		$GLOBALS['_http_response'] = array( 'response' => array( 'code' => $code ), 'body' => $body );
	}

	private function resp( int $code, string $body ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body );
	}

	/** A render sequence: baseline probe first, then the post-write probe. */
	private function fake_http_seq( array $responses ): void {
		$GLOBALS['_http_response_queue'] = $responses;
	}

	public function test_render_check_off_by_default_keeps_the_write(): void {
		$this->publish( 55 );
		$this->fake_http( 500, '' ); // would be "broken" if checked
		// render check NOT enabled

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'], 'Disabled → no render check, no revert.' );
	}

	public function test_broken_page_is_reverted_on_5xx(): void {
		$this->publish( 55 );
		$this->enable_render_check();
		// Healthy before the write, 5xx after → the write caused it → revert.
		$this->fake_http_seq( array( $this->resp( 200, 'ok' ), $this->resp( 500, 'Internal Server Error' ) ) );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_render_failed', $result->get_error_code() );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_broken_page_is_reverted_on_white_screen(): void {
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http_seq( array( $this->resp( 200, 'ok' ), $this->resp( 200, '   ' ) ) ); // WSOD after

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( 'governance_render_failed', $result->get_error_code() );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_critical_error_text_in_page_copy_is_not_reverted(): void {
		// A valid 200 page whose COPY contains WordPress's "critical error" sentence
		// (e.g. docs/support content) must NOT be reverted — a real fatal is a 500,
		// not a 200 body match (Codex R9 P2).
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http_seq( array(
			$this->resp( 200, 'ok' ),
			$this->resp( 200, '<html><body>Guide: what to do when "There has been a critical error on this website" appears.</body></html>' ),
		) );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_same_host_different_port_permalink_is_off_origin(): void {
		// home_url() is http://example.com (port 80); a permalink on :8080 is a
		// different origin and must not be probed (Codex R9 P2, SSRF).
		$this->publish( 55 );
		$this->enable_render_check();
		$GLOBALS['_permalink'] = 'http://example.com:8080/foo';
		$this->fake_http( 500, '' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertArrayNotHasKey( '_http_last_url', $GLOBALS );
	}

	public function test_pre_existing_5xx_is_not_the_edits_fault_and_keeps_the_write(): void {
		// The page is ALREADY 5xx before the write (maintenance mode / unrelated
		// upstream 503). Both probes see 500 → not the edit's fault → keep the
		// write, don't block editing until the 5xx clears (Codex R4 P2).
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http( 500, '' ); // every probe → 500

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_healthy_page_keeps_the_write(): void {
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http( 200, '<html><body>My lovely page</body></html>' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_empty_4xx_response_is_inconclusive_and_keeps_the_write(): void {
		// A blocked/protected permalink returning an empty 403 (WAF/staging) is not
		// a WSOD — only an empty 2xx is (Codex R1 P2). Keep the write.
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http( 403, '' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_elementor_library_template_is_not_render_checked(): void {
		// save-as-template / create-theme-template / create-popup publish
		// elementor_library posts; their standalone permalink is not a page a
		// visitor browses, so a 5xx there must not revert a valid write (R3 P2).
		$GLOBALS['_posts'][55] = (object) array( 'ID' => 55, 'post_status' => 'publish', 'post_type' => 'elementor_library' );
		$this->enable_render_check();
		$this->fake_http( 500, '' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/save-as-template',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_non_viewable_post_type_is_not_render_checked(): void {
		$GLOBALS['_posts'][55]     = (object) array( 'ID' => 55, 'post_status' => 'publish', 'post_type' => 'secret_cpt' );
		$GLOBALS['_non_viewable']  = array( 'secret_cpt' );
		$this->enable_render_check();
		$this->fake_http( 500, '' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
		unset( $GLOBALS['_non_viewable'] );
	}

	public function test_inconclusive_baseline_never_reverts_even_if_post_write_5xx(): void {
		// Baseline probe is inconclusive (transient WP_Error / WAF), so the page was
		// never proven healthy. Even if the post-write probe returns 5xx, we can't
		// attribute it to the write → keep it (Codex R5 P2).
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http_seq( array(
			new \WP_Error( 'http_request_failed', 'baseline timeout' ), // inconclusive baseline
			$this->resp( 500, 'now 500' ),                              // broken after
		) );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result, 'No confirmed-healthy baseline → never revert.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_create_style_run_is_not_render_checked(): void {
		// A create-style tool (no input post_id) inserts a new post; reverting it on
		// a render check would restore absent meta yet leave the post behind, so
		// creates are not render-checked at all (Codex R10 P2).
		$this->publish( 4242 );
		$this->enable_render_check();
		$this->fake_http( 500, '' ); // would "break" if probed

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/create-page',
			static function ( $input ) {
				\Elementor_MCP_Governance::before_page_write( 4242 ); // new post id
				return array( 'created' => 4242 );
			},
			array( 'title' => 'New Page' ) // NO post_id → create run
		);

		$this->assertSame( array( 'created' => 4242 ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
		$this->assertArrayNotHasKey( '_http_last_url', $GLOBALS, 'A create run must not probe a render.' );
	}

	public function test_render_probe_is_cache_busted(): void {
		// The probe must carry a cache-buster so a warm page cache can't serve the
		// pre-write page and hide a fatal (Codex R2 P2).
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http( 200, 'healthy' );

		\Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertStringContainsString( 'emcp_render_check=', $GLOBALS['_http_last_url'] ?? '' );
	}

	public function test_render_probe_bounds_the_response_size(): void {
		// The probe must cap the buffered body so a huge healthy page can't make the
		// write request download megabytes / exhaust memory (Codex R6 P2).
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http( 200, 'ok' );

		\Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$args = $GLOBALS['_http_last_args'] ?? array();
		$this->assertArrayHasKey( 'limit_response_size', $args );
		$this->assertLessThanOrEqual( 512 * 1024, $args['limit_response_size'] );
		// Must not follow redirects — an off-origin redirect would be an SSRF.
		$this->assertSame( 0, $args['redirection'] ?? -1 );
		// TLS verification must NOT be forced off (would allow a spoofed cert).
		$this->assertNotSame( false, $args['sslverify'] ?? null );
	}

	public function test_off_origin_permalink_is_never_probed(): void {
		// A get_permalink filter can point a post at an external URL; probing it
		// would be an SSRF, so an off-origin permalink is inconclusive and is never
		// fetched (Codex R8 P2, security).
		$this->publish( 55 );
		$this->enable_render_check();
		$GLOBALS['_permalink'] = 'http://evil.example.net/internal';
		$this->fake_http( 500, '' ); // would "break" if it were ever probed

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
		$this->assertArrayNotHasKey( '_http_last_url', $GLOBALS, 'Off-origin permalink must not be fetched at all.' );
	}

	public function test_redirecting_page_is_inconclusive_and_never_reverts(): void {
		// A page that responds with a redirect (potential open redirect) must not
		// be followed server-side; the 3xx is inconclusive, so the write stands
		// and no SSRF hop is made (Codex R7 P2, security).
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http( 302, '' ); // both probes see a redirect (not followed)

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_transient_loopback_failure_never_reverts(): void {
		// wp_remote_get returns a WP_Error (timeout/DNS) → inconclusive, keep write.
		$this->publish( 55 );
		$this->enable_render_check();
		$GLOBALS['_http_response'] = new \WP_Error( 'http_request_failed', 'timeout' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result, 'A flaky loopback must never revert a good write.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_draft_page_is_not_render_checked(): void {
		// Unpublished page isn't served anonymously; a non-200 there is not a
		// breakage signal, so the check is skipped even with a 500 fake response.
		$GLOBALS['_posts'][55] = (object) array( 'ID' => 55, 'post_status' => 'draft' );
		$this->enable_render_check();
		$this->fake_http( 500, '' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_render_revert_that_fails_to_restore_is_surfaced(): void {
		$this->publish( 55 );
		$this->enable_render_check();
		$this->fake_http_seq( array( $this->resp( 200, 'ok' ), $this->resp( 500, '' ) ) );
		$GLOBALS['_aura_snap']['fail_restore'] = true;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			$this->page_writer( array( 'ok' => true ) ),
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_rollback_failed', $result->get_error_code() );
	}

	// --- kit-scoped (design-token) governance ------------------------------

	/** Point the stub kits_manager at an active kit with the given id. */
	private function set_active_kit( int $id ): void {
		$GLOBALS['_active_kit'] = new class( $id ) {
			private int $id;
			public function __construct( int $id ) {
				$this->id = $id; }
			public function get_id() {
				return $this->id; }
		};
	}

	/** Write-capable args tagged as a kit-scoped design-token write. */
	private function kit_write_args( $callback ): array {
		$args                       = $this->write_args( $callback );
		$args['meta']['governance'] = array( 'scope' => 'kit' );
		return $args;
	}

	/** Run a kit-scoped tool through the real wrap → execute path. */
	private function run_kit_tool( string $name, $callback, $input = array() ) {
		$wrapped = \Elementor_MCP_Governance::wrap_ability( $name, $this->kit_write_args( $callback ) );
		return call_user_func( $wrapped['execute_callback'], $input );
	}

	public function test_kit_write_snapshots_the_kit_before_success(): void {
		$this->set_active_kit( 7 );
		$result = $this->run_kit_tool(
			'elementor-mcp/create-variable',
			static function ( $input ) {
				return array( 'created' => true ); }
		);

		$this->assertSame( array( 'created' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( 7, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'], 'Snapshots the active kit post.' );
		$this->assertSame( \Elementor_MCP_Governance::KIT_META_KEYS, $GLOBALS['_aura_snap']['snapshot_calls'][0]['keys'] );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_kit_write_rolls_back_on_failure(): void {
		$this->set_active_kit( 7 );
		$result = $this->run_kit_tool(
			'elementor-mcp/replace-system-colors',
			static function ( $input ) {
				return new \WP_Error( 'boom', 'kit write failed' ); }
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['restore_calls'], 'A failed kit write is rolled back.' );
	}

	public function test_kit_write_fails_closed_when_no_active_kit(): void {
		$GLOBALS['_active_kit'] = null;
		$ran                    = false;
		$result                 = $this->run_kit_tool(
			'elementor-mcp/create-variable',
			static function ( $input ) use ( &$ran ) {
				$ran = true;
				return array( 'created' => true );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_snapshot_failed', $result->get_error_code() );
		$this->assertFalse( $ran, 'The tool must not run when the kit cannot be snapshotted.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_kit_write_fails_closed_when_snapshot_fails(): void {
		$this->set_active_kit( 7 );
		$GLOBALS['_aura_snap']['fail_snapshot'] = true;
		$ran                                    = false;
		$result                                 = $this->run_kit_tool(
			'elementor-mcp/replace-system-typography',
			static function ( $input ) use ( &$ran ) {
				$ran = true;
				return array( 'ok' => true );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_snapshot_failed', $result->get_error_code() );
		$this->assertFalse( $ran, 'A snapshot failure must refuse the write, not mutate design tokens.' );
	}

	public function test_kit_write_requires_grant_when_enforced(): void {
		$this->set_active_kit( 7 );
		$this->require_grants(); // enforced, no grant header
		$ran    = false;
		$result = $this->run_kit_tool(
			'elementor-mcp/create-variable',
			static function ( $input ) use ( &$ran ) {
				$ran = true;
				return array( 'created' => true );
			}
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_grant_required', $result->get_error_code() );
		$this->assertFalse( $ran, 'No grant → the kit write never runs.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'], 'Grant is checked before the kit snapshot.' );
	}
}
