<?php
/**
 * Unit tests for the Angie browser-MCP bridge (read-only v1).
 *
 * Covers the route permission matrix, the server-side allowlist + read-only
 * invariant, ability delegation, the CallToolResult response shape (incl. the
 * source: angie-bridge attribution tag), and the enqueue guards.
 *
 * @package Elementor_MCP\Tests
 * @since   1.26.0
 */

namespace Elementor_MCP\Tests;

require_once __DIR__ . '/class-ability-test-case.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-angie-bridge.php';

use Elementor_MCP_Angie_Bridge;
use WP_REST_Request;

/**
 * A minimal WP_Ability lookalike for the wp_get_ability() stub registry.
 */
class Fake_Bridge_Ability {

	public $meta;
	public $input_schema;
	public $description;
	public $execute_result;
	public $executed_with = null;

	public function __construct( array $meta, $execute_result = array( 'ok' => true ), array $input_schema = array(), string $description = 'Fake ability.' ) {
		$this->meta           = $meta;
		$this->execute_result = $execute_result;
		$this->input_schema   = $input_schema;
		$this->description    = $description;
	}

	public function get_meta(): array {
		return $this->meta;
	}

	public function get_input_schema(): array {
		return $this->input_schema;
	}

	public function get_description(): string {
		return $this->description;
	}

	public $permission_result = true;

	public function check_permissions( $input = null ) {
		return $this->permission_result;
	}

	public function execute( $input = null ) {
		$this->executed_with = $input;
		return $this->execute_result;
	}
}

class AngieBridgeTest extends Ability_Test_Case {

	/**
	 * @var Elementor_MCP_Angie_Bridge
	 */
	private $bridge;

	protected function setUp(): void {
		parent::setUp();
		$this->bridge                = new Elementor_MCP_Angie_Bridge();
		$GLOBALS['_options']         = array();
		$GLOBALS['_abilities']       = array();
		$GLOBALS['_logged_in']       = true;
		$GLOBALS['_is_admin']        = false;
		$GLOBALS['_rest_routes']     = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_options'],
			$GLOBALS['_abilities'],
			$GLOBALS['_logged_in'],
			$GLOBALS['_is_admin'],
			$GLOBALS['_rest_routes']
		);
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function enable_bridge(): void {
		$GLOBALS['_options'][ Elementor_MCP_Angie_Bridge::OPTION_ENABLED ] = '1';
	}

	private function read_meta(): array {
		return array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);
	}

	private function write_meta(): array {
		return array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
		);
	}

	private function register_fake( string $full_name, Fake_Bridge_Ability $ability ): void {
		$GLOBALS['_abilities'][ $full_name ] = $ability;
	}

	// -------------------------------------------------------------------------
	// Default state + allowlist composition
	// -------------------------------------------------------------------------

	public function test_bridge_is_disabled_by_default(): void {
		$this->assertFalse( Elementor_MCP_Angie_Bridge::is_enabled() );
	}

	public function test_default_allowlist_is_exactly_the_six_read_tools(): void {
		$this->assertSame(
			array(
				'elementor-mcp/list-widgets',
				'elementor-mcp/get-widget-schema',
				'elementor-mcp/get-page-structure',
				'elementor-mcp/list-pages',
				'elementor-mcp/list-global-classes',
				'elementor-mcp/list-variables',
			),
			Elementor_MCP_Angie_Bridge::get_allowed_tools(),
			'The v1 allowlist must stay read-only; adding a mutating ability requires the Phase B approval-queue mechanism (see angie-bridge-spec).'
		);
	}

	// -------------------------------------------------------------------------
	// Route permission matrix
	// -------------------------------------------------------------------------

	public function test_routes_refuse_when_bridge_disabled(): void {
		$result = $this->bridge->route_permission_check();
		$this->assertWPError( $result, 'emcp_angie_bridge_disabled' );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_routes_refuse_when_logged_out(): void {
		$this->enable_bridge();
		$GLOBALS['_logged_in'] = false;

		$result = $this->bridge->route_permission_check();
		$this->assertWPError( $result, 'emcp_angie_bridge_auth_required' );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_routes_refuse_without_edit_posts(): void {
		$this->enable_bridge();
		$this->deny_all_caps();

		$result = $this->bridge->route_permission_check();
		$this->assertWPError( $result, 'emcp_angie_bridge_forbidden' );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_routes_allow_editor_when_enabled(): void {
		$this->enable_bridge();
		$this->allow_caps( 'edit_posts' );

		$this->assertTrue( $this->bridge->route_permission_check() );
	}

	public function test_both_routes_are_registered(): void {
		$this->bridge->register_routes();

		$routes = array_column( $GLOBALS['_rest_routes'], 'route' );
		$this->assertCount( 2, $routes );
		$this->assertSame( 'emcp/angie/v1', $GLOBALS['_rest_routes'][0]['namespace'] );
		$this->assertContains( '/tools', $routes );
	}

	// -------------------------------------------------------------------------
	// GET /tools
	// -------------------------------------------------------------------------

	public function test_get_tools_lists_only_registered_read_abilities(): void {
		$this->enable_bridge();
		$this->register_fake(
			'elementor-mcp/list-widgets',
			new Fake_Bridge_Ability( $this->read_meta(), array(), array( 'type' => 'object' ), 'Lists widgets.' )
		);
		// list-variables absent (experiment-gated site) — must drop out silently.

		$data  = $this->bridge->get_tools()->get_data();
		$names = array_column( $data['tools'], 'name' );

		$this->assertSame( array( 'list-widgets' ), $names );
		$this->assertSame( 'Lists widgets.', $data['tools'][0]['description'] );
		$this->assertTrue( $data['tools'][0]['annotations']['readOnlyHint'] );
	}

	public function test_get_tools_drops_an_ability_that_is_not_read_only(): void {
		$this->enable_bridge();
		// A mutating ability sitting under an allowlisted name must NOT surface —
		// the read-only invariant is enforced against the registry, not the list.
		$this->register_fake( 'elementor-mcp/list-widgets', new Fake_Bridge_Ability( $this->write_meta() ) );

		$data = $this->bridge->get_tools()->get_data();
		$this->assertSame( array(), $data['tools'] );
	}

	public function test_get_tools_defaults_empty_input_schema_to_object(): void {
		$this->enable_bridge();
		$this->register_fake( 'elementor-mcp/list-pages', new Fake_Bridge_Ability( $this->read_meta(), array(), array() ) );

		$data = $this->bridge->get_tools()->get_data();
		$this->assertSame( array( 'type' => 'object' ), $data['tools'][0]['inputSchema'] );
	}

	// -------------------------------------------------------------------------
	// POST /execute — delegation + response shape
	// -------------------------------------------------------------------------

	public function test_execute_delegates_params_and_tags_the_source(): void {
		$this->enable_bridge();
		$ability = new Fake_Bridge_Ability( $this->read_meta(), array( 'widgets' => array( 'heading' ) ) );
		$this->register_fake( 'elementor-mcp/list-widgets', $ability );

		$request  = new WP_REST_Request( array( 'tool' => 'list-widgets' ), array( 'category' => 'basic' ) );
		$response = $this->bridge->execute_tool( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'category' => 'basic' ), $ability->executed_with );
		$this->assertFalse( $data['isError'] );
		$this->assertSame( 'angie-bridge', $data['_meta']['source'] );
		$this->assertStringContainsString( 'heading', $data['content'][0]['text'] );
	}

	public function test_execute_wraps_wp_error_as_mcp_error_result(): void {
		$this->enable_bridge();
		$ability = new Fake_Bridge_Ability( $this->read_meta(), new \WP_Error( 'nope', 'It broke.' ) );
		$this->register_fake( 'elementor-mcp/get-page-structure', $ability );

		$request = new WP_REST_Request( array( 'tool' => 'get-page-structure' ), array( 'page_id' => 1 ) );
		$data    = $this->bridge->execute_tool( $request )->get_data();

		$this->assertTrue( $data['isError'] );
		$this->assertSame( 'It broke.', $data['content'][0]['text'] );
		$this->assertSame( 'angie-bridge', $data['_meta']['source'] );
	}

	public function test_execute_refuses_a_write_ability_with_phase_b_error(): void {
		$this->enable_bridge();
		// Even if a mutating ability ends up under an allowlisted name (bad
		// filter, future drift), execute must refuse with the Phase B error.
		$this->register_fake( 'elementor-mcp/list-widgets', new Fake_Bridge_Ability( $this->write_meta() ) );

		$request  = new WP_REST_Request( array( 'tool' => 'list-widgets' ), array() );
		$response = $this->bridge->execute_tool( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'bridge_writes_phase_b', $response->get_data()['code'] );
	}

	public function test_execute_refuses_when_the_ability_denies_permission(): void {
		$this->enable_bridge();
		// A filter-added read ability with a stronger requirement (e.g.
		// manage_options) must refuse before execute — the bridge checks the
		// ability's own permission callback explicitly, not just the route gate.
		$ability                    = new Fake_Bridge_Ability( $this->read_meta() );
		$ability->permission_result = new \WP_Error( 'forbidden', 'Admins only.' );
		$this->register_fake( 'elementor-mcp/list-widgets', $ability );

		$request  = new WP_REST_Request( array( 'tool' => 'list-widgets' ), array() );
		$response = $this->bridge->execute_tool( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'emcp_angie_tool_forbidden', $response->get_data()['code'] );
		$this->assertNull( $ability->executed_with, 'The ability must not execute after a permission denial.' );
	}

	public function test_execute_returns_404_for_unknown_or_unlisted_tools(): void {
		$this->enable_bridge();
		// A genuine fork write tool that is NOT on the allowlist: registered,
		// mutating, but outside the curated list → plain not-found (the bridge
		// does not advertise what else exists).
		$this->register_fake( 'elementor-mcp/add-widget', new Fake_Bridge_Ability( $this->write_meta() ) );

		foreach ( array( 'add-widget', 'does-not-exist' ) as $tool ) {
			$response = $this->bridge->execute_tool( new WP_REST_Request( array( 'tool' => $tool ), array() ) );
			$this->assertSame( 404, $response->get_status(), "Tool {$tool} must 404." );
			$this->assertSame( 'emcp_angie_tool_not_found', $response->get_data()['code'] );
		}
	}

	// -------------------------------------------------------------------------
	// Read-only annotation predicate
	// -------------------------------------------------------------------------

	public function test_ability_is_read_only_requires_readonly_true_and_not_destructive(): void {
		$this->assertTrue( Elementor_MCP_Angie_Bridge::ability_is_read_only( new Fake_Bridge_Ability( $this->read_meta() ) ) );
		$this->assertFalse( Elementor_MCP_Angie_Bridge::ability_is_read_only( new Fake_Bridge_Ability( $this->write_meta() ) ) );
		// Missing annotations → fail closed.
		$this->assertFalse( Elementor_MCP_Angie_Bridge::ability_is_read_only( new Fake_Bridge_Ability( array() ) ) );
		// readonly present but destructive too → fail closed.
		$this->assertFalse(
			Elementor_MCP_Angie_Bridge::ability_is_read_only(
				new Fake_Bridge_Ability( array( 'annotations' => array( 'readonly' => true, 'destructive' => true ) ) )
			)
		);
		$this->assertFalse( Elementor_MCP_Angie_Bridge::ability_is_read_only( null ) );
	}

	// -------------------------------------------------------------------------
	// Enqueue guards (early-outs only; the positive path needs a WP env)
	// -------------------------------------------------------------------------

	public function test_enqueue_is_a_noop_outside_admin_or_when_disabled(): void {
		// Not admin, enabled.
		$this->enable_bridge();
		$GLOBALS['_is_admin'] = false;
		$this->assertNull( $this->bridge->maybe_enqueue_bridge() );

		// Admin, disabled.
		$GLOBALS['_options'][ Elementor_MCP_Angie_Bridge::OPTION_ENABLED ] = '0';
		$GLOBALS['_is_admin'] = true;
		$this->assertNull( $this->bridge->maybe_enqueue_bridge() );

		// Admin, enabled, but no capability.
		$this->enable_bridge();
		$this->deny_all_caps();
		$this->assertNull( $this->bridge->maybe_enqueue_bridge() );
	}
}
