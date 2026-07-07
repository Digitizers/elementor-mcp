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
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
		parent::tearDown();
	}

	/** Turn on SiteAgent's grant regime AND this plugin's opt-in. */
	private function require_grants( string $header = null ): void {
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

	public function test_missing_grant_denies_the_write(): void {
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
		$this->assertFalse( $called, 'The write must not run without a grant.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'], 'Denied before snapshot.' );
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
		$this->assertSame( 'elementor-mcp/update-element', $call['tool'] );
		$this->assertSame( array( 'post_id' => 55, 'foo' => 'bar' ), $call['params'] );
		// The snapshot still happens after the grant clears.
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_invalid_grant_denies_the_write(): void {
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
}
