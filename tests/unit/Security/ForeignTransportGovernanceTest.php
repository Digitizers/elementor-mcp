<?php
/**
 * Security — K7 step 1b: the governed guarantees must survive a call that comes
 * through the second door anyway.
 *
 * Step 1 (fork #57) closed the door: a governed write arriving on any route but
 * this plugin's own MCP server is refused unless it carries a valid approval
 * grant. But a grant *is* gateway context, so a properly approved call may still
 * arrive over a foreign transport — and everything that makes a governed write
 * safe has to work when it does.
 *
 * The architectural argument says it does: the wrapper replaces the ability's
 * own `execute_callback`, so snapshotting and the render check are downstream of
 * the transport and cannot know what carried the request. That is an argument,
 * not evidence, and "correct by construction" is precisely what field reports #4
 * and #5 kept disproving — three of their bugs were invisible to builder-level
 * tests and only appeared when the real execute path was driven.
 *
 * So every governed family is driven here through `wrap_ability()` →
 * `execute_callback` with the request recorded as arriving on `/mcp/angie` and a
 * grant presented, and asserted to snapshot, roll back and render-check exactly
 * as it does on our own route.
 *
 * J5 (dual-registering SiteAgent's tools as WP Abilities) is gated on this,
 * because it puts the same question to a much larger tool surface.
 *
 * @group security
 * @group governance
 * @package Elementor_MCP\Tests\Security
 */

namespace Elementor_MCP\Tests\Security;

use PHPUnit\Framework\TestCase;

class ForeignTransportGovernanceTest extends TestCase {

	/** The route a co-installed MCP server dispatches on (Angie 1.1.12). */
	private const FOREIGN_ROUTE = '/mcp/angie';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_snap']  = array(
			'fail_snapshot'  => false,
			'fail_restore'   => false,
			'snapshot_calls' => array(),
			'restore_calls'  => array(),
			'seq'            => 0,
		);
		$GLOBALS['_aura_grant'] = array(
			'enforced'      => true,
			'verify_result' => true,
			'verify_calls'  => array(),
		);
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']   = false;
		$GLOBALS['_filters']             = array();
		$GLOBALS['_posts']               = array();
		unset( $GLOBALS['_http_response'], $GLOBALS['_http_response_queue'], $GLOBALS['_http_last_url'], $GLOBALS['_permalink'], $GLOBALS['_active_kit'] );
		\Elementor_MCP_Call_Context::reset();
		\Elementor_MCP_Governance::reset_state();

		// Every test in this class is a call through the second door, carrying an
		// approval grant. Note `_emcp_require_grants` stays OFF: the grant is
		// demanded by the transport guard, not by the opt-in enforcement flag —
		// which is the whole point of #57.
		$this->arrive_on_foreign_route();
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = 'header.signature';
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		// Clear the stubs this class installs. `_active_kit` in particular is a
		// minimal double with only get_id(), and other suites hand their kit to
		// code that calls get_settings() — leaving it behind fails them in some
		// orders and not others, which is the least useful kind of red.
		unset(
			$GLOBALS['_active_kit'],
			$GLOBALS['_posts'],
			$GLOBALS['_http_response'],
			$GLOBALS['_http_response_queue'],
			$GLOBALS['_http_last_url'],
			$GLOBALS['_permalink']
		);
		$GLOBALS['_filters'] = array();
		\Elementor_MCP_Call_Context::reset();
		\Elementor_MCP_Governance::reset_state();
		parent::tearDown();
	}

	private function arrive_on_foreign_route(): void {
		\Elementor_MCP_Call_Context::init();
		apply_filters( 'rest_pre_dispatch', null, null, new \WP_REST_Request( array(), null, self::FOREIGN_ROUTE ) );
	}

	private function write_args( $callback, array $meta = array() ): array {
		return array(
			'label'            => 'Governed write',
			'execute_callback' => $callback,
			'meta'             => array_merge(
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ),
				$meta
			),
		);
	}

	/** Run a tool through the real wrap → execute path, as the adapter does. */
	private function run_tool( string $name, callable $callback, array $input = array(), array $meta = array() ) {
		$wrapped = \Elementor_MCP_Governance::wrap_ability( $name, $this->write_args( $callback, $meta ) );
		return call_user_func( $wrapped['execute_callback'], $input );
	}

	private function page_writer( $return ): callable {
		return static function ( $input ) use ( $return ) {
			$gate = \Elementor_MCP_Governance::before_page_write( $input['post_id'] ?? 0 );
			return is_wp_error( $gate ) ? $gate : $return;
		};
	}

	private function kit_writer( $return ): callable {
		return static function () use ( $return ) {
			$gate = \Elementor_MCP_Governance::before_kit_write();
			return is_wp_error( $gate ) ? $gate : $return;
		};
	}

	private function gc_writer( $return, int $kit = 7, array $classes = array( 333 ), array $pages = array() ): callable {
		return static function () use ( $return, $kit, $classes, $pages ) {
			$gate = \Elementor_MCP_Governance::before_global_classes_write( $kit, $classes, $pages );
			return is_wp_error( $gate ) ? $gate : $return;
		};
	}

	private function set_active_kit( int $id ): void {
		$GLOBALS['_active_kit'] = new class( $id ) {
			private int $id;
			public function __construct( int $id ) {
				$this->id = $id;
			}
			public function get_id() {
				return $this->id;
			}
		};
	}

	private function publish( int $id ): void {
		$GLOBALS['_posts'][ $id ] = (object) array( 'ID' => $id, 'post_status' => 'publish' );
	}

	private function resp( int $code, string $body ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body );
	}

	// --- the transport really is foreign ------------------------------------

	public function test_the_baseline_is_a_genuinely_foreign_transport(): void {
		// If this ever passes for the wrong reason — the route not recorded, the
		// context class treating /mcp/angie as ours — every other test in this
		// class silently becomes a duplicate of the own-route suite.
		$this->assertSame( self::FOREIGN_ROUTE, \Elementor_MCP_Call_Context::rest_route() );
		$this->assertFalse( \Elementor_MCP_Call_Context::is_own_server() );
		$this->assertFalse( \Elementor_MCP_Call_Context::is_trusted_for_writes() );
		$this->assertFalse(
			\Elementor_MCP_Governance::grants_required(),
			'The opt-in enforcement flag is OFF here — the grant is demanded by the transport guard alone.'
		);
	}

	// --- page writes ---------------------------------------------------------

	public function test_page_write_over_a_foreign_transport_is_snapshotted(): void {
		$result = $this->run_tool( 'elementor-mcp/update-element', $this->page_writer( array( 'ok' => true ) ), array( 'post_id' => 55 ) );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'], 'v1.17 snapshot must fire regardless of transport.' );
		$this->assertSame( 55, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'] );
		$this->assertSame( \Elementor_MCP_Governance::PAGE_META_KEYS, $GLOBALS['_aura_snap']['snapshot_calls'][0]['keys'] );
	}

	public function test_the_grant_is_verified_against_the_tool_and_params(): void {
		$this->run_tool( 'elementor-mcp/update-element', $this->page_writer( array( 'ok' => true ) ), array( 'post_id' => 55 ) );

		$this->assertCount( 1, $GLOBALS['_aura_grant']['verify_calls'] );
		$call = $GLOBALS['_aura_grant']['verify_calls'][0];
		// The gateway mints against the exposed MCP tool name (slashes → dashes).
		$this->assertSame( 'elementor-mcp-update-element', $call['tool'] );
		$this->assertSame( array( 'post_id' => 55 ), $call['params'] );
	}

	public function test_failed_page_write_over_a_foreign_transport_is_rolled_back(): void {
		$result = $this->run_tool(
			'elementor-mcp/update-element',
			static function ( $input ) {
				$gate = \Elementor_MCP_Governance::before_page_write( $input['post_id'] ?? 0 );
				return is_wp_error( $gate ) ? $gate : new \WP_Error( 'save_failed', 'nope' );
			},
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_snapshot_failure_still_denies_the_write_over_a_foreign_transport(): void {
		$GLOBALS['_aura_snap']['fail_snapshot'] = true;

		$result = $this->run_tool( 'elementor-mcp/update-element', $this->page_writer( array( 'ok' => true ) ), array( 'post_id' => 55 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	// --- render check (v1.19) ------------------------------------------------

	public function test_render_check_runs_and_reverts_a_broken_page_over_a_foreign_transport(): void {
		$this->publish( 55 );
		$GLOBALS['_emcp_render_check']   = true;
		$GLOBALS['_http_response_queue'] = array( $this->resp( 200, 'ok' ), $this->resp( 500, 'Internal Server Error' ) );

		$result = $this->run_tool( 'elementor-mcp/update-element', $this->page_writer( array( 'ok' => true ) ), array( 'post_id' => 55 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_render_failed', $result->get_error_code() );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_render_check_keeps_a_healthy_page_over_a_foreign_transport(): void {
		$this->publish( 55 );
		$GLOBALS['_emcp_render_check']   = true;
		$GLOBALS['_http_response_queue'] = array( $this->resp( 200, 'ok' ), $this->resp( 200, 'still fine' ) );

		$result = $this->run_tool( 'elementor-mcp/update-element', $this->page_writer( array( 'ok' => true ) ), array( 'post_id' => 55 ) );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	// --- kit (design-token) writes ------------------------------------------

	public function test_kit_write_over_a_foreign_transport_is_snapshotted(): void {
		$this->set_active_kit( 7 );

		$result = $this->run_tool(
			'elementor-mcp/create-variable',
			$this->kit_writer( array( 'created' => true ) ),
			array(),
			array( 'governance' => array( 'scope' => 'kit' ) )
		);

		$this->assertSame( array( 'created' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( 7, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'] );
		$this->assertSame( \Elementor_MCP_Governance::KIT_META_KEYS, $GLOBALS['_aura_snap']['snapshot_calls'][0]['keys'] );
	}

	public function test_kit_write_over_a_foreign_transport_rolls_back_on_failure(): void {
		$this->set_active_kit( 7 );

		$result = $this->run_tool(
			'elementor-mcp/replace-system-colors',
			static function () {
				$gate = \Elementor_MCP_Governance::before_kit_write();
				return is_wp_error( $gate ) ? $gate : new \WP_Error( 'write_failed', 'nope' );
			},
			array(),
			array( 'governance' => array( 'scope' => 'kit' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_kit_write_without_a_grant_is_refused_on_a_foreign_transport(): void {
		// The counterpart of the above: the same family, same route, no grant.
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		$this->set_active_kit( 7 );
		$ran = false;

		$result = $this->run_tool(
			'elementor-mcp/create-variable',
			function () use ( &$ran ) {
				$ran = true;
				return array( 'created' => true );
			},
			array(),
			array( 'governance' => array( 'scope' => 'kit' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_untrusted_transport', $result->get_error_code() );
		$this->assertFalse( $ran );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	// --- global-classes writes ----------------------------------------------

	public function test_global_classes_write_over_a_foreign_transport_snapshots_the_transaction(): void {
		$result = $this->run_tool(
			'elementor-mcp/delete-global-class',
			$this->gc_writer( array( 'deleted' => true ), 7, array( 333 ), array( 501, 502 ) ),
			array(),
			array( 'governance' => array( 'scope' => 'global-classes' ) )
		);

		$this->assertSame( array( 'deleted' => true ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$call = $GLOBALS['_aura_snap']['snapshot_calls'][0];
		$this->assertSame( array( 7, 333, 501, 502 ), $call['post_ids'] );
		$this->assertSame( \Elementor_MCP_Governance::GC_SNAPSHOT_META_KEYS, $call['keys'] );
	}

	public function test_global_classes_write_over_a_foreign_transport_rolls_back_on_failure(): void {
		$result = $this->run_tool(
			'elementor-mcp/delete-global-class',
			static function () {
				$gate = \Elementor_MCP_Governance::before_global_classes_write( 7, array( 333 ), array() );
				return is_wp_error( $gate ) ? $gate : new \WP_Error( 'write_failed', 'nope' );
			},
			array(),
			array( 'governance' => array( 'scope' => 'global-classes' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	// --- create-style writes (no post_id in the input) -----------------------

	public function test_create_style_write_over_a_foreign_transport_snapshots_the_new_post(): void {
		// The snapshot fires from the write site, which learns the id there — so a
		// tool that inserts a post is covered even though its input names none.
		$result = $this->run_tool(
			'elementor-mcp/create-page',
			static function () {
				$gate = \Elementor_MCP_Governance::before_page_write( 909 );
				return is_wp_error( $gate ) ? $gate : array( 'post_id' => 909 );
			},
			array( 'title' => 'New' )
		);

		$this->assertSame( array( 'post_id' => 909 ), $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( 909, $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_id'] );
	}

	// --- previews ------------------------------------------------------------

	public function test_a_preview_over_a_foreign_transport_needs_no_grant_and_snapshots_nothing(): void {
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/audit-seo',
			static fn() => array( 'findings' => array() ),
			array( 'apply' => false ),
			true
		);

		$this->assertSame( array( 'findings' => array() ), $result );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertCount( 0, $GLOBALS['_aura_grant']['verify_calls'] );
	}

	public function test_a_preview_tool_that_applies_still_needs_a_grant_on_a_foreign_transport(): void {
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		$ran = false;

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/audit-seo',
			function () use ( &$ran ) {
				$ran = true;
				return array( 'applied' => true );
			},
			array( 'apply' => true, 'post_id' => 55 ),
			true
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_untrusted_transport', $result->get_error_code() );
		$this->assertFalse( $ran );
	}

	// --- an invalid grant is not a grant ------------------------------------

	public function test_an_expired_grant_denies_every_family(): void {
		$GLOBALS['_aura_grant']['verify_result'] = 'expired';
		$this->set_active_kit( 7 );

		$families = array(
			'page'           => array( $this->page_writer( array( 'ok' => true ) ), array( 'post_id' => 55 ), array() ),
			'kit'            => array( $this->kit_writer( array( 'ok' => true ) ), array(), array( 'governance' => array( 'scope' => 'kit' ) ) ),
			'global-classes' => array( $this->gc_writer( array( 'ok' => true ) ), array(), array( 'governance' => array( 'scope' => 'global-classes' ) ) ),
		);

		foreach ( $families as $family => $spec ) {
			list( $callback, $input, $meta ) = $spec;
			$result                          = $this->run_tool( 'elementor-mcp/x-' . $family, $callback, $input, $meta );

			$this->assertInstanceOf( \WP_Error::class, $result, $family );
			$this->assertSame( 'governance_grant_invalid', $result->get_error_code(), $family );
		}

		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'], 'A rejected grant never reaches a write site.' );
	}
}
