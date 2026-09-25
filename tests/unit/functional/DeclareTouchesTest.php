<?php
namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * declare_touches() answers exactly what run_governed() hands the rules
 * engine for the same call (Aura spec 2026-09-25 §3, §4). Harness as
 * GovernanceCssTouchesTest.
 *
 * @group functional
 * @group governance
 */
class DeclareTouchesTest extends TestCase {

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
			$GLOBALS['_abilities_init']
		);
	}

	private function set_active_kit( int $id ): void {
		$GLOBALS['_active_kit'] = new class( $id ) {
			private int $id;
			public function __construct( int $id ) {
				$this->id = $id; }
			public function get_id() {
				return $this->id; }
		};
	}

	/** Register (wrap) an ability the way elementor-mcp.php does. */
	private function wrap( string $name, array $governance, bool $readonly = false, bool $preview_capable = false ): array {
		$args = array(
			'execute_callback' => static function () { return array( 'ok' => true ); },
			'meta'             => array( 'annotations' => array( 'readonly' => $readonly ), 'governance' => $governance ),
		);
		if ( $preview_capable ) {
			$args['input_schema'] = array( 'properties' => array( 'apply' => array( 'type' => 'boolean' ) ) );
		}
		return \Elementor_MCP_Governance::wrap_ability( $name, $args );
	}

	/** What run_governed() handed the rules engine, or null when it asked nothing. */
	private function gated_touches( array $wrapped, array $input ): ?array {
		$GLOBALS['_aura_rules']['calls'] = array();
		call_user_func( $wrapped['execute_callback'], $input );
		return isset( $GLOBALS['_aura_rules']['calls'][0] ) ? $GLOBALS['_aura_rules']['calls'][0]['touches'] : null;
	}

	public function parity_cases(): array {
		return array(
			'edit_with_css'          => array( 'elementor-mcp/update-page-settings', array( 'writes' => 'edit' ), array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ), 0, false ),
			'edit_without_css'       => array( 'elementor-mcp/update-element', array( 'writes' => 'edit' ), array( 'post_id' => 7, 'element_id' => 'abc', 'settings' => array( 'title' => 'x' ) ), 0, false ),
			'create_with_source'     => array( 'elementor-mcp/save-as-template', array( 'writes' => 'create' ), array( 'post_id' => 7, 'title' => 'copy' ), 0, false ),
			'site_wide_create'       => array( 'elementor-mcp/build-page', array(), array( 'title' => 'x', 'page_settings' => array( 'custom_css' => 'a{}' ) ), 0, false ),
			'kit_write'              => array( 'elementor-mcp/update-global-colors', array( 'scope' => 'kit' ), array( 'custom_css' => 'body{}' ), 900, false ),
			'kit_write_no_kit'       => array( 'elementor-mcp/update-global-colors', array( 'scope' => 'kit' ), array( 'custom_css' => 'body{}' ), 0, false ),
			'global_classes'         => array( 'elementor-mcp/update-global-class', array( 'scope' => 'global-classes' ), array( 'id' => 'g-1', 'label' => 'x' ), 900, false ),
			'seo_apply_true'         => array( 'elementor-mcp/generate-meta-tags', array( 'writes' => 'edit' ), array( 'post_id' => 7, 'apply' => true ), 0, true ),
		);
	}

	/** @dataProvider parity_cases */
	public function test_the_seam_equals_what_the_gate_judged( string $name, array $governance, array $input, int $kit, bool $preview_capable ): void {
		$this->set_active_kit( $kit );
		$wrapped = $this->wrap( $name, $governance, false, $preview_capable );
		$gated   = $this->gated_touches( $wrapped, $input );
		$this->assertNotNull( $gated, 'run_governed() must have asked the rules engine' );

		$declared = \Elementor_MCP_Governance::declare_touches( str_replace( '/', '-', $name ), $input );

		$this->assertSame( array( 'ability' => $name, 'touches' => $gated ), $declared );
	}

	public function test_a_dry_run_declares_nothing_as_the_gate_judges_nothing(): void {
		$wrapped = $this->wrap( 'elementor-mcp/generate-meta-tags', array( 'writes' => 'edit' ), false, true );
		$this->assertNull( $this->gated_touches( $wrapped, array( 'post_id' => 7, 'apply' => false ) ) );
		$this->assertSame(
			array( 'ability' => 'elementor-mcp/generate-meta-tags', 'touches' => array() ),
			\Elementor_MCP_Governance::declare_touches( 'elementor-mcp-generate-meta-tags', array( 'post_id' => 7, 'apply' => false ) )
		);
	}

	public function test_an_unknown_name_is_null(): void {
		$this->wrap( 'elementor-mcp/update-element', array( 'writes' => 'edit' ) );
		$this->assertNull( \Elementor_MCP_Governance::declare_touches( 'elementor-mcp-no-such-tool', array( 'post_id' => 7 ) ) );
	}

	public function test_a_read_only_ability_is_null_not_empty(): void {
		$this->wrap( 'elementor-mcp/get-page-structure', array(), true );
		$this->assertNull( \Elementor_MCP_Governance::declare_touches( 'elementor-mcp-get-page-structure', array( 'post_id' => 7 ) ) );
	}

	public function test_the_ability_name_itself_is_not_an_mcp_name(): void {
		$this->wrap( 'elementor-mcp/update-element', array( 'writes' => 'edit' ) );
		$this->assertNull( \Elementor_MCP_Governance::declare_touches( 'elementor-mcp/update-element', array( 'post_id' => 7 ) ) );
	}

	public function test_inactive_governance_declares_nothing(): void {
		\Elementor_MCP_Governance::reset_state( null, false );
		$this->wrap( 'elementor-mcp/update-element', array( 'writes' => 'edit' ) );
		$this->assertNull( \Elementor_MCP_Governance::declare_touches( 'elementor-mcp-update-element', array( 'post_id' => 7 ) ) );
	}

	public function test_the_seam_asks_no_rules_engine_and_writes_nothing(): void {
		$this->wrap( 'elementor-mcp/update-page-settings', array( 'writes' => 'edit' ) );
		\Elementor_MCP_Governance::declare_touches( 'elementor-mcp-update-page-settings', array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ) );
		$this->assertSame( array(), $GLOBALS['_aura_rules']['calls'] );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'] );
	}

	public function test_the_seam_leaves_run_state_untouched(): void {
		$outer = null;
		$args  = array(
			'execute_callback' => static function () use ( &$outer ) {
				\Elementor_MCP_Governance::declare_touches( 'elementor-mcp-update-page-settings', array( 'post_id' => 9, 'settings' => array( 'custom_css' => 'b{}' ) ) );
				$outer = \Elementor_MCP_Governance::current_run_for_tests();
				return array( 'ok' => true );
			},
			'meta'             => array( 'annotations' => array( 'readonly' => false ), 'governance' => array( 'writes' => 'edit' ) ),
		);
		$wrapped = \Elementor_MCP_Governance::wrap_ability( 'elementor-mcp/update-page-settings', $args );
		call_user_func( $wrapped['execute_callback'], array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ) );
		$this->assertSame( 'elementor-mcp/update-page-settings', $outer['name'] );
		$this->assertSame( array( 'post_id' => 7, 'settings' => array( 'custom_css' => 'a{}' ) ), $outer['input'] );
	}
}
