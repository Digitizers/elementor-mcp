<?php
namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * The early rules gate declares custom CSS alongside the page/site touches
 * (spec 2026-09-24 §4.1). Harness as GovernanceRulesTest.
 *
 * @group functional
 * @group governance
 */
class GovernanceCssTouchesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_snap']  = array( 'fail_snapshot' => false, 'fail_restore' => false, 'snapshot_calls' => array(), 'restore_calls' => array(), 'seq' => 0 );
		$GLOBALS['_aura_grant'] = array( 'enforced' => false, 'verify_result' => true, 'verify_calls' => array() );
		$GLOBALS['_aura_rules'] = array( 'verdict' => array( 'effect' => null ), 'calls' => array(), 'current' => null, 'throw' => false );
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']   = false;
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'],
			$GLOBALS['_active_kit'],
			$GLOBALS['_aura_rules'],
			$GLOBALS['_posts'],
			$GLOBALS['_http_response_queue']
		);
	}

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

	/**
	 * Named run_tool, not run(): TestCase::run() is final on the PHPUnit
	 * version this harness uses, so the brief's `run()` name is adapted here.
	 */
	private function run_tool( string $name, array $input, array $governance ): array {
		$args    = array(
			'execute_callback' => static function () { return array( 'ok' => true ); },
			'meta'             => array( 'annotations' => array( 'readonly' => false ), 'governance' => $governance ),
		);
		$wrapped = \Elementor_MCP_Governance::wrap_ability( $name, $args );
		call_user_func( $wrapped['execute_callback'], $input );
		return $GLOBALS['_aura_rules']['calls'][0]['touches'];
	}

	public function test_an_edit_carrying_css_declares_page_touches_then_the_exact_css_touch(): void {
		$touches = $this->run_tool( 'elementor-mcp/update-page-settings', array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ), array( 'writes' => 'edit' ) );
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::page_touches( 7 ), array( array( 'type' => 'custom_css', 'id' => '7', 'precise' => true, 'css_only' => true ) ) ),
			$touches
		);
	}

	public function test_an_edit_without_css_declares_exactly_what_it_did_before(): void {
		$this->assertSame( \Elementor_MCP_Rules::page_touches( 7 ), $this->run_tool( 'elementor-mcp/update-element', array( 'post_id' => 7, 'settings' => array( 'title' => 'x' ) ), array( 'writes' => 'edit' ) ) );
	}

	public function test_a_create_carrying_css_declares_the_site_and_the_css_wildcard(): void {
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::site_touches(), array( array( 'type' => 'custom_css', 'id' => '*' ) ) ),
			$this->run_tool( 'elementor-mcp/build-page', array( 'title' => 'x', 'page_settings' => array( 'custom_css' => 'a{}' ) ), array() )
		);
	}

	public function test_a_kit_scoped_write_carrying_css_names_the_active_kit_conservatively(): void {
		// Stub the active kit the way GovernanceFunctionalTest does for
		// before_kit_write (copy its kit stub); kit id 900. A kit-scoped
		// design-token ability is not a CSS writer (not in
		// CSS_PRECISE_ABILITIES), so CSS found in its input is conservative —
		// but on the concrete kit, not '*'.
		$this->set_active_kit( 900 );
		$touches = $this->run_tool( 'elementor-mcp/update-global-colors', array( 'custom_css' => 'body{}' ), array( 'scope' => 'kit' ) );
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::site_touches(), array( array( 'type' => 'custom_css', 'id' => '900' ) ) ),
			$touches
		);
	}

	public function test_the_kits_own_css_is_written_through_page_settings_on_the_kit_post(): void {
		// Kit CSS lives in the kit post's page settings: update-page-settings
		// with post_id = the kit id is an ordinary edit of post 900 — precise.
		$touches = $this->run_tool( 'elementor-mcp/update-page-settings', array( 'post_id' => 900, 'settings' => array( 'custom_css' => 'body{}' ) ), array( 'writes' => 'edit' ) );
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::page_touches( 900 ), array( array( 'type' => 'custom_css', 'id' => '900', 'precise' => true, 'css_only' => true ) ) ),
			$touches
		);
	}

	public function test_a_create_from_a_source_post_is_the_wildcard_not_the_source(): void {
		$touches = $this->run_tool( 'elementor-mcp/add-heading', array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ), array() );
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::site_touches(), \Elementor_MCP_Rules::page_touches( 7 ), array( array( 'type' => 'custom_css', 'id' => '*' ) ) ),
			$touches
		);
	}

	public function test_a_kit_write_with_css_and_no_active_kit_is_the_wildcard(): void {
		$this->set_active_kit( 0 );
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::site_touches(), array( array( 'type' => 'custom_css', 'id' => '*' ) ) ),
			$this->run_tool( 'elementor-mcp/update-global-colors', array( 'custom_css' => 'body{}' ), array( 'scope' => 'kit' ) )
		);
	}

	public function test_a_kit_write_with_css_when_the_kit_lookup_throws_is_the_wildcard(): void {
		$GLOBALS['_active_kit'] = new class() {
			public function get_id() {
				throw new \RuntimeException( 'kit unavailable' );
			}
		};
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::site_touches(), array( array( 'type' => 'custom_css', 'id' => '*' ) ) ),
			$this->run_tool( 'elementor-mcp/update-global-colors', array( 'custom_css' => 'body{}' ), array( 'scope' => 'kit' ) )
		);
	}

	public function test_a_copy_onto_the_edited_page_declares_its_css_on_that_page(): void {
		// apply-template: ALWAYS_CONSERVATIVE_CSS 'target' — no CSS in the input.
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::page_touches( 42 ), array( array( 'type' => 'custom_css', 'id' => '42' ) ) ),
			$this->run_tool( 'elementor-mcp/apply-template', array( 'post_id' => 42, 'template_id' => 7 ), array( 'writes' => 'edit' ) )
		);
	}

	public function test_a_copy_that_reaches_other_pages_declares_the_css_wildcard_through_governance(): void {
		// set-template-conditions: ALWAYS_CONSERVATIVE_CSS '*' — even though
		// governance resolves the template's own digit id.
		$this->assertSame(
			array_merge( \Elementor_MCP_Rules::page_touches( 42 ), array( array( 'type' => 'custom_css', 'id' => '*' ) ) ),
			$this->run_tool( 'elementor-mcp/set-template-conditions', array( 'post_id' => 42, 'conditions' => array( 'include/general' ) ), array( 'writes' => 'edit' ) )
		);
	}
}
