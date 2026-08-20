<?php
/**
 * Security — the second door: a co-installed MCP server reaching this plugin's
 * write tools over a transport the Aura gateway never sees.
 *
 * The exposure
 * ------------
 * `wp_register_ability()` publishes to a site-wide registry. A second MCP server
 * on the same site enumerates that registry and serves whatever it finds. That
 * is not hypothetical: Elementor's Angie 1.1.12 ships `/mcp/angie`, and its
 * `execute-ability` proxy runs any third-party ability by name.
 *
 * On a managed site that means every mutating Elementor tool is callable without
 * the queue-approve-snapshot-audit path the gateway enforces, because the
 * gateway is not on that path at all.
 *
 * Two independent guards, because they cover different sites
 * ---------------------------------------------------------
 * 1. Registration: write tools declare `meta.mcp.type = 'private'`, which is not
 *    a type Angie serves, so they are neither listed nor executable there. This
 *    is the ONLY guard on a site without SiteAgent, where governance wraps
 *    nothing.
 * 2. Execution: a governed write that arrives on any route other than this
 *    plugin's own MCP server must present a valid approval grant. This covers
 *    servers that ignore the meta, and any transport that does not exist yet.
 *
 * The Angie rules mirrored below are read from Angie 1.1.12 source
 * (`modules/wp-abilities/classes/mcp-adapter-ability-discovery.php` and
 * `mcp-adapter-ability-permissions.php`), not from documentation.
 *
 * @group security
 * @group governance
 * @package Elementor_MCP\Tests\Security
 */

namespace Elementor_MCP\Tests\Security;

use PHPUnit\Framework\TestCase;

class ForeignMcpTransportTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Elementor_MCP_Call_Context::reset();
		$GLOBALS['_filters']    = array();
		$GLOBALS['_aura_snap']  = array(
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
		$GLOBALS['_emcp_render_check']   = false;
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
	}

	protected function tearDown(): void {
		\Elementor_MCP_Call_Context::reset();
		$GLOBALS['_filters'] = array();
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
		parent::tearDown();
	}

	/**
	 * Drive the real recorder through the real hook, the way a REST request does.
	 *
	 * Going through `Call_Context::init()` + `apply_filters()` rather than calling
	 * `record()` directly is the point: it proves the callback is registered on
	 * `rest_pre_dispatch` with enough accepted args to actually see the request.
	 */
	private function arrive_on( string $route ): void {
		\Elementor_MCP_Call_Context::init();
		$request  = new \WP_REST_Request( array(), null, $route );
		$response = apply_filters( 'rest_pre_dispatch', null, null, $request );
		$this->assertNull( $response, 'The recorder must never alter the dispatch result.' );
	}

	/** A write-capable ability, annotated the way every mutating tool here is. */
	private function write_args( $callback = null ): array {
		return array(
			'label'            => 'Update element',
			'execute_callback' => $callback ?? static function () {
				return array( 'ok' => true );
			},
			'meta'             => array(
				'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
			),
		);
	}

	private function read_args(): array {
		$args = $this->write_args();
		$args['meta']['annotations']['readonly'] = true;
		return $args;
	}

	/**
	 * Angie 1.1.12's exposure rule for a third-party ability, transcribed.
	 *
	 * `Mcp_Adapter_Ability_Discovery::is_discoverable_ability_of_type()`: an
	 * ability is exposed as a tool when `meta.mcp.type` is absent or equal to
	 * 'tool'. `Mcp_Adapter_Ability_Permissions::validate_target_ability()` runs
	 * the same check before executing, so failing it closes both.
	 *
	 * @param array $args Ability args as registered.
	 */
	private function angie_would_expose( array $args ): bool {
		$type = $args['meta']['mcp']['type'] ?? 'tool';
		return 'tool' === $type;
	}

	// --- Guard 1: registration ---------------------------------------------

	public function test_write_ability_is_withheld_from_foreign_mcp_servers(): void {
		$args = \Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $this->write_args() );

		$this->assertSame( 'private', $args['meta']['mcp']['type'] ?? null );
		$this->assertFalse(
			$this->angie_would_expose( $args ),
			'A mutating tool must not be reachable from Angie\'s /mcp/angie server.'
		);
	}

	public function test_read_ability_stays_available_to_foreign_mcp_servers(): void {
		$args = \Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $this->read_args() );

		$this->assertArrayNotHasKey( 'mcp', $args['meta'], 'Read tools are deliberately left exposed.' );
		$this->assertTrue( $this->angie_would_expose( $args ) );
	}

	public function test_unclassifiable_ability_is_left_alone(): void {
		$args = $this->write_args();
		unset( $args['meta'] );
		$shielded = \Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $args );

		$this->assertSame( $args, $shielded, 'An ability that never declared readonly is not known to write.' );
	}

	public function test_declared_mcp_type_is_not_overwritten(): void {
		$args                        = $this->write_args();
		$args['meta']['mcp']['type'] = 'resource';
		$shielded                    = \Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $args );

		$this->assertSame( 'resource', $shielded['meta']['mcp']['type'] );
	}

	public function test_operator_can_re_expose_writes_through_the_filter(): void {
		add_filter( 'elementor_mcp_expose_writes_to_foreign_mcp', static fn() => true );
		$args = \Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $this->write_args() );

		$this->assertArrayNotHasKey( 'mcp', $args['meta'] );
		$this->assertTrue( $this->angie_would_expose( $args ) );
	}

	public function test_the_shield_runs_at_the_real_registration_seam(): void {
		$GLOBALS['_registered_abilities'] = array();
		elementor_mcp_register_ability( 'elementor-mcp/update-element', $this->write_args() );
		elementor_mcp_register_ability( 'elementor-mcp/get-page-structure', $this->read_args() );

		$registered = $GLOBALS['_registered_abilities'];
		$this->assertFalse( $this->angie_would_expose( $registered['elementor-mcp/update-element'] ) );
		$this->assertTrue( $this->angie_would_expose( $registered['elementor-mcp/get-page-structure'] ) );
	}

	// --- Guard 2: execution -------------------------------------------------

	public function test_governed_write_from_a_foreign_route_is_refused_before_it_runs(): void {
		$ran    = false;
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static function () use ( &$ran ) {
				$ran = true;
				return array( 'updated' => true );
			},
			array( 'post_id' => 55 )
		);

		// No route recorded yet → this is the own-server baseline; arrive first.
		$this->assertTrue( $ran, 'Baseline: a non-REST call is trusted.' );

		$ran = false;
		$this->arrive_on( '/mcp/angie' );
		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static function () use ( &$ran ) {
				$ran = true;
				return array( 'updated' => true );
			},
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_untrusted_transport', $result->get_error_code() );
		$this->assertFalse( $ran, 'The write must be refused BEFORE the callback runs, not rolled back after.' );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_governed_write_on_our_own_server_route_runs(): void {
		$this->arrive_on( \Elementor_MCP_Call_Context::own_server_route() );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static fn() => array( 'updated' => true ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'updated' => true ), $result );
	}

	public function test_a_valid_grant_lets_a_foreign_transport_through(): void {
		// A grant IS gateway context: it was minted by the approval flow and is
		// bound to this exact tool and params. A transport carrying one is not
		// bypassing governance, whatever route it came in on.
		$this->arrive_on( '/mcp/angie' );
		$GLOBALS['_aura_grant']['enforced']     = true;
		$GLOBALS['_aura_grant']['verify_result'] = true;
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT']   = 'header.signature';

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static fn() => array( 'updated' => true ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'updated' => true ), $result );
		$this->assertNotEmpty( $GLOBALS['_aura_grant']['verify_calls'] );
	}

	public function test_an_invalid_grant_does_not_let_a_foreign_transport_through(): void {
		$this->arrive_on( '/mcp/angie' );
		$GLOBALS['_aura_grant']['enforced']      = true;
		$GLOBALS['_aura_grant']['verify_result'] = 'expired';
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT']   = 'header.signature';

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static fn() => array( 'updated' => true ),
			array( 'post_id' => 55 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_grant_invalid', $result->get_error_code() );
	}

	public function test_a_dry_run_preview_is_not_blocked_by_transport(): void {
		// A preview-capable tool called with apply falsy writes nothing, so there
		// is nothing for the gateway to approve.
		$this->arrive_on( '/mcp/angie' );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/audit-seo',
			static fn() => array( 'findings' => array() ),
			array( 'apply' => false ),
			true
		);

		$this->assertSame( array( 'findings' => array() ), $result );
	}

	public function test_operator_can_trust_a_specific_foreign_route(): void {
		$this->arrive_on( '/my-proxy/v1/run' );
		add_filter(
			'elementor_mcp_trusted_write_context',
			static fn( $trusted, $route ) => '/my-proxy/v1/run' === $route ? true : $trusted,
			10,
			2
		);

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static fn() => array( 'updated' => true ),
			array( 'post_id' => 55 )
		);

		$this->assertSame( array( 'updated' => true ), $result );
	}

	// --- Context classification --------------------------------------------

	public function test_own_server_route_is_derived_from_the_registration_constants(): void {
		// register_mcp_server() creates the route from these two constants. If
		// this derivation is ever replaced by a literal that drifts, every
		// governed write on our own server is denied as foreign.
		$this->assertSame(
			'/' . \Elementor_MCP_Plugin::SERVER_ROUTE_NAMESPACE . '/' . \Elementor_MCP_Plugin::SERVER_ROUTE,
			\Elementor_MCP_Call_Context::own_server_route()
		);
	}

	public function test_route_is_matched_regardless_of_trailing_slash(): void {
		$this->arrive_on( \Elementor_MCP_Call_Context::own_server_route() . '/' );

		$this->assertTrue( \Elementor_MCP_Call_Context::is_own_server() );
	}

	public function test_a_non_rest_context_is_trusted(): void {
		$this->assertTrue( \Elementor_MCP_Call_Context::is_trusted_for_writes() );
		$this->assertSame( 'non-rest', \Elementor_MCP_Call_Context::describe() );
	}

	public function test_the_denial_names_the_transport_it_refused(): void {
		$this->arrive_on( '/mcp/angie' );

		$this->assertSame( 'rest:/mcp/angie', \Elementor_MCP_Call_Context::describe() );

		$result = \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static fn() => array( 'updated' => true ),
			array( 'post_id' => 55 )
		);

		$this->assertStringContainsString(
			'/mcp/angie',
			$result->get_error_message(),
			'An operator reading the error must be able to tell which door the call came through.'
		);
	}

	public function test_our_own_read_only_angie_bridge_route_is_not_a_write_transport(): void {
		// The bridge enforces a read-only invariant of its own, so no mutating
		// ability can reach it — but if one ever did, it must not be treated as
		// our MCP server just because we wrote it.
		$this->arrive_on( '/emcp/angie/v1/execute/list-pages' );

		$this->assertFalse( \Elementor_MCP_Call_Context::is_own_server() );
		$this->assertFalse( \Elementor_MCP_Call_Context::is_trusted_for_writes() );
	}
}
