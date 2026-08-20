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

	// --- which other servers actually reach us -------------------------------

	/** A server double whose declared tool list is $tools. */
	private function server_publishing( array $tools ): object {
		return new class( $tools ) {
			private array $tools;
			public function __construct( array $tools ) {
				$this->tools = $tools;
			}
			public function get_tools(): array {
				return $this->tools;
			}
		};
	}

	/**
	 * A server double shaped like a LIVE adapter server: `get_tools()` returns
	 * McpTool-ish objects keyed by the MCP tool name, which is the ability name
	 * with "/" replaced by "-" — not the ability name itself.
	 */
	private function live_server_publishing( array $ability_names ): object {
		$tools = array();
		foreach ( $ability_names as $ability ) {
			$mcp_name           = str_replace( '/', '-', $ability );
			$tools[ $mcp_name ] = new class( $mcp_name ) {
				private string $name;
				public function __construct( string $name ) {
					$this->name = $name;
				}
				public function get_name(): string {
					return $this->name;
				}
			};
		}
		return $this->server_publishing( $tools );
	}

	public function test_a_stock_adapter_server_is_listed_but_not_warned_about(): void {
		// A normal install already carries the bundled adapter's default server,
		// which reaches nothing here — its proxy tools require `meta.mcp.public`,
		// which none of these abilities set. Warning about it as a second door on
		// every site would train operators to skip this report entirely.
		$result = \Elementor_MCP_Server_Info_Abilities::classify_servers(
			array(
				'elementor-mcp-server'      => $this->server_publishing( array( 'elementor-mcp/update-element' ) ),
				'mcp-adapter-default-server' => $this->server_publishing( array( 'mcp-adapter/execute-ability' ) ),
			),
			'elementor-mcp-server',
			array( 'elementor-mcp/update-element' )
		);

		$this->assertSame( array( 'mcp-adapter-default-server' ), $result['ids'], 'Still reported as present.' );
		$this->assertSame( array(), $result['publishing'], 'But not as publishing our tools.' );
	}

	public function test_a_server_that_lists_our_tools_is_named(): void {
		$result = \Elementor_MCP_Server_Info_Abilities::classify_servers(
			array(
				'some-other-server' => $this->server_publishing(
					array( 'their/tool', 'elementor-mcp/update-element' )
				),
			),
			'elementor-mcp-server',
			array( 'elementor-mcp/update-element', 'elementor-mcp/get-page-structure' )
		);

		$this->assertSame( array( 'some-other-server' ), $result['ids'] );
		$this->assertSame( array( 'some-other-server' ), $result['publishing'] );
	}

	public function test_a_live_server_publishing_our_tools_is_matched_despite_the_name_transform(): void {
		// The shape that actually comes back from an adapter-created server: the
		// tool list is keyed by the MCP tool name, which the adapter derives by
		// replacing "/" with "-". Comparing raw ability names against those keys
		// never matches — so this field would have reported a genuinely exposed
		// site as clean, which is the one answer it exists to give.
		$result = \Elementor_MCP_Server_Info_Abilities::classify_servers(
			array( 'some-other-server' => $this->live_server_publishing( array( 'elementor-mcp/update-element' ) ) ),
			'elementor-mcp-server',
			array( 'elementor-mcp/update-element' )
		);

		$this->assertSame( array( 'some-other-server' ), $result['publishing'] );
	}

	public function test_a_live_server_publishing_only_its_own_tools_is_not_matched(): void {
		$result = \Elementor_MCP_Server_Info_Abilities::classify_servers(
			array( 'angie' => $this->live_server_publishing( array( 'angie/execute-ability' ) ) ),
			'elementor-mcp-server',
			array( 'elementor-mcp/update-element' )
		);

		$this->assertSame( array( 'angie' ), $result['ids'] );
		$this->assertSame( array(), $result['publishing'] );
	}

	public function test_our_own_server_is_never_reported_as_foreign(): void {
		$result = \Elementor_MCP_Server_Info_Abilities::classify_servers(
			array( 'elementor-mcp-server' => $this->server_publishing( array( 'elementor-mcp/update-element' ) ) ),
			'elementor-mcp-server',
			array( 'elementor-mcp/update-element' )
		);

		$this->assertSame( array(), $result['ids'] );
		$this->assertSame( array(), $result['publishing'] );
	}

	// --- The fail-closed path must survive the class being absent ------------

	public function test_denial_path_never_reaches_for_the_context_class_unguarded(): void {
		// call_context_trusted() fails closed when the context class did not load
		// — which sends the request straight into the denial branch. If that
		// branch then names the class, the partial install fatals instead of
		// returning a WP_Error, i.e. the fail-closed path fails open-ended.
		//
		// The class cannot be unloaded inside a running suite, so this pins the
		// invariant at the source: every mention of it in the governance file
		// sits inside a class_exists() guard.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-governance.php' );
		$lines  = explode( "\n", $source );
		$guard  = -10;

		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, "class_exists( 'Elementor_MCP_Call_Context' )" ) ) {
				$guard = $i;
				continue;
			}
			if ( false === strpos( $line, 'Elementor_MCP_Call_Context::' ) ) {
				continue;
			}
			$this->assertLessThanOrEqual(
				4,
				$i - $guard,
				sprintf(
					'class-governance.php:%d calls Elementor_MCP_Call_Context with no class_exists() guard above it. '
					. 'Route it through Elementor_MCP_Governance::describe_call_context() instead.',
					$i + 1
				)
			);
		}
	}

	public function test_context_description_has_a_value_for_every_transport(): void {
		$this->assertSame( 'non-rest', \Elementor_MCP_Governance::describe_call_context() );

		$this->arrive_on( '/mcp/angie' );
		$this->assertSame( 'rest:/mcp/angie', \Elementor_MCP_Governance::describe_call_context() );
	}

	public function test_a_selectively_opened_write_is_reported_by_name(): void {
		// The filter receives each ability's own args, so a caller can open one
		// write tool and close the rest. The diagnostic used to answer by calling
		// the filter with an empty array, which reproduces none of those
		// decisions and reported such a site as fully hidden.
		add_filter(
			'elementor_mcp_expose_writes_to_foreign_mcp',
			static fn( $expose, $args ) => 'Update element' === ( $args['label'] ?? '' ) ? true : $expose,
			10,
			2
		);

		elementor_mcp_register_ability( 'elementor-mcp/update-element', $this->write_args() );
		$other          = $this->write_args();
		$other['label'] = 'Delete element';
		elementor_mcp_register_ability( 'elementor-mcp/delete-element', $other );

		$info = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame(
			array( 'elementor-mcp/update-element' ),
			$info['write_exposure']['exposed_write_tools']
		);
		$this->assertFalse( $info['write_exposure']['writes_hidden_from_other_mcp_servers'] );
		$this->assertNotEmpty(
			array_filter(
				$info['notes'],
				static fn( $note ) => false !== strpos( $note, 'elementor-mcp/update-element' )
			),
			'The report must name the tool that is open, not just say "some are".'
		);
	}

	public function test_an_ability_declaring_itself_a_tool_is_counted_as_exposed(): void {
		// `mcp.type = 'tool'` is kept rather than overwritten — the declaration is
		// the author's. That leaves it exposed, so the report must say so instead
		// of counting "we did not touch it" as "it is hidden".
		$args                        = $this->write_args();
		$args['meta']['mcp']['type'] = 'tool';
		elementor_mcp_register_ability( 'elementor-mcp/legacy-write', $args );

		$this->assertSame(
			array( 'elementor-mcp/legacy-write' ),
			\Elementor_MCP_Call_Context::writes_left_exposed()
		);
	}

	public function test_the_filter_cannot_expose_an_ability_that_declared_a_non_tool_type(): void {
		// The filter opening an ability does not write anything — so an ability
		// that already declared `resource` or `private` keeps that type, and a
		// foreign server still refuses to serve it. Recording it as exposed would
		// have the report naming a tool nothing can reach: the same false report
		// as the reverse, and the one that makes operators chase ghosts.
		add_filter( 'elementor_mcp_expose_writes_to_foreign_mcp', static fn() => true );

		foreach ( array( 'private', 'resource', 'prompt' ) as $type ) {
			\Elementor_MCP_Call_Context::reset();
			$args                        = $this->write_args();
			$args['meta']['mcp']['type'] = $type;

			elementor_mcp_register_ability( 'elementor-mcp/declared-' . $type, $args );

			$this->assertSame(
				array(),
				\Elementor_MCP_Call_Context::writes_left_exposed(),
				sprintf( 'A write declaring mcp.type = %s stays hidden whatever the filter says.', $type )
			);
			$this->assertSame( array( 'elementor-mcp/declared-' . $type ), \Elementor_MCP_Call_Context::writes_seen() );
		}
	}

	public function test_the_filter_does_expose_an_ability_that_declared_nothing(): void {
		// The counterpart, so the test above cannot pass by the recorder simply
		// never firing.
		add_filter( 'elementor_mcp_expose_writes_to_foreign_mcp', static fn() => true );
		elementor_mcp_register_ability( 'elementor-mcp/undeclared', $this->write_args() );

		$this->assertSame( array( 'elementor-mcp/undeclared' ), \Elementor_MCP_Call_Context::writes_left_exposed() );
	}

	public function test_a_shielded_write_is_not_counted_as_exposed(): void {
		elementor_mcp_register_ability( 'elementor-mcp/update-element', $this->write_args() );
		elementor_mcp_register_ability( 'elementor-mcp/get-page-structure', $this->read_args() );

		$this->assertSame( array( 'elementor-mcp/update-element' ), \Elementor_MCP_Call_Context::writes_seen() );
		$this->assertSame( array(), \Elementor_MCP_Call_Context::writes_left_exposed() );
	}

	public function test_grant_status_requires_the_governance_wrapper_to_be_active(): void {
		// The transport check lives inside the governance wrapper, which wraps
		// nothing unless SiteAgent's snapshot engine is present. Reporting the
		// guard as active on a fork-only site is exactly the false assurance this
		// tool exists to prevent. Injected inputs again: `is_active()` reads
		// `class_exists()`, and a loaded class cannot be unloaded mid-suite.
		$this->assertTrue( \Elementor_MCP_Server_Info_Abilities::execution_guard_active( true, true, true ) );
		$this->assertFalse(
			\Elementor_MCP_Server_Info_Abilities::execution_guard_active( true, true, false ),
			'No SiteAgent snapshot engine ⇒ the wrapper is inert ⇒ no transport check runs.'
		);
		$this->assertFalse( \Elementor_MCP_Server_Info_Abilities::execution_guard_active( true, false, true ) );
		$this->assertFalse( \Elementor_MCP_Server_Info_Abilities::execution_guard_active( false, true, true ) );
	}

	public function test_a_fork_only_site_is_reported_as_closed_not_as_exposed(): void {
		// Before 1.31.0 the absence of SiteAgent meant no execution-side check at
		// all, and the report said a metadata-ignoring server "would not be
		// stopped a second time". The transport gate now stops it at the
		// permission stage on every site, so that sentence described a closed
		// site as open — the exact false security state this tool exists to
		// prevent. The two guards are reported separately now.
		$info = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertTrue(
			$info['write_exposure']['foreign_writes_refused_at_permission'],
			'The transport gate needs only the context class, which is loaded here.'
		);
		$this->assertArrayHasKey( 'governed_writes_require_grant_off_own_server', $info['write_exposure'] );
		$this->assertEmpty(
			array_filter(
				$info['notes'],
				static fn( $note ) => false !== strpos( $note, 'would not be stopped a second time' )
			),
			'That sentence is gone; it was only ever true before the gate existed.'
		);
	}

	public function test_a_fork_only_site_is_described_as_closed_not_as_exposed(): void {
		// The wording this replaced said a metadata-ignoring server "would not be
		// stopped a second time" — which, once the transport gate exists,
		// describes a closed site as open. Injected state: a suite with
		// SiteAgent's stubs loaded cannot reach the fork-only combination.
		$notes = \Elementor_MCP_Server_Info_Abilities::guard_notes( true, false );

		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'refused outright at the permission stage', $notes[0] );
		$this->assertStringNotContainsString( 'would not be stopped a second time', $notes[0] );
	}

	public function test_a_fully_guarded_site_gets_no_note_at_all(): void {
		$this->assertSame( array(), \Elementor_MCP_Server_Info_Abilities::guard_notes( true, true ) );
	}

	public function test_a_missing_transport_gate_is_the_only_cause_reported(): void {
		// One cause per state, as elsewhere in this report: with the gate not
		// running, the grant-verification detail is noise on top of the thing
		// the operator must fix.
		$notes = \Elementor_MCP_Server_Info_Abilities::guard_notes( false, false );

		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'transport gate is NOT running', $notes[0] );
	}

	public function test_grant_status_is_reported_from_the_live_state(): void {
		// The suite stubs SiteAgent, so the guard is genuinely active here — this
		// pins the wiring between the predicate and the report.
		$info = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertTrue( \Elementor_MCP_Governance::is_active() );
		$this->assertTrue( $info['write_exposure']['governed_writes_require_grant_off_own_server'] );
		$this->assertEmpty(
			array_filter(
				$info['notes'],
				static fn( $note ) => false !== strpos( $note, 'transport check on governed writes is NOT running' )
			)
		);
	}

	public function test_a_missing_component_is_reported_as_one_cause_not_two(): void {
		// When the component did not load the shield never ran, so the exposure
		// filter had nothing to do with it. Emitting its explanation as well
		// would give the operator two conflicting causes for one finding — and
		// the wrong one is the one that looks actionable.
		$notes = \Elementor_MCP_Server_Info_Abilities::exposure_notes( false, array( 'elementor-mcp/update-element' ) );

		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'did not load', $notes[0] );
		$this->assertStringNotContainsString( 'elementor_mcp_expose_writes_to_foreign_mcp', $notes[0] );
	}

	public function test_the_filter_cause_is_reported_only_when_the_shield_actually_ran(): void {
		$this->assertSame( array(), \Elementor_MCP_Server_Info_Abilities::exposure_notes( true, array() ) );

		$notes = \Elementor_MCP_Server_Info_Abilities::exposure_notes( true, array( 'elementor-mcp/update-element' ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'elementor-mcp/update-element', $notes[0] );
	}

	public function test_shield_is_reported_off_when_the_guard_component_did_not_load(): void {
		// The registrar skips the shield behind its own class_exists() guard and
		// says nothing, so a partial install goes out with writes exposed while
		// every setting still reads as safe. This is the branch the diagnostic
		// exists for, and it is unreachable through the class itself — the suite
		// cannot unload a loaded class — hence the injected inputs.
		$this->assertFalse(
			\Elementor_MCP_Server_Info_Abilities::writes_are_shielded( false, false ),
			'Writes are not hidden when the component that hides them is missing.'
		);
		$this->assertFalse( \Elementor_MCP_Server_Info_Abilities::writes_are_shielded( false, true ) );
		$this->assertFalse( \Elementor_MCP_Server_Info_Abilities::writes_are_shielded( true, true ) );
		$this->assertTrue( \Elementor_MCP_Server_Info_Abilities::writes_are_shielded( true, false ) );
	}

	public function test_server_info_reports_the_shield_as_off_when_the_filter_opens_it(): void {
		add_filter( 'elementor_mcp_expose_writes_to_foreign_mcp', static fn() => true );
		elementor_mcp_register_ability( 'elementor-mcp/update-element', $this->write_args() );

		$info = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertFalse( $info['write_exposure']['writes_hidden_from_other_mcp_servers'] );
		$this->assertNotEmpty(
			array_filter(
				$info['notes'],
				static fn( $note ) => false !== strpos( $note, 'exposed to OTHER MCP servers' )
			),
			'An operator reading server-info must be told in words, not just in a boolean.'
		);
	}

	public function test_an_open_filter_alone_does_not_make_the_report_cry_wolf(): void {
		// The filter is consulted per ability at registration. With nothing
		// registered under it, nothing was actually exposed — and a report that
		// said otherwise would train operators to ignore it.
		add_filter( 'elementor_mcp_expose_writes_to_foreign_mcp', static fn() => true );

		$info = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( array(), $info['write_exposure']['exposed_write_tools'] );
		$this->assertTrue( $info['write_exposure']['writes_hidden_from_other_mcp_servers'] );
	}

	public function test_server_info_reports_our_own_server_route(): void {
		$info = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertTrue( $info['write_exposure']['writes_hidden_from_other_mcp_servers'] );
		$this->assertSame(
			\Elementor_MCP_Call_Context::own_server_route(),
			$info['write_exposure']['own_server_route']
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
