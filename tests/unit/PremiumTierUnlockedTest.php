<?php
/**
 * Regression tests for the fork's premium-tier unlock (1.13.0).
 *
 * Upstream gated 19 GPL tools (brand kits, SEO, a11y, Widget Builder) plus
 * the generated-widget loader/store behind a Freemius paid license the fork
 * cannot activate (has_paid_plans => false) — leaving in-repo code dormant.
 * The fork replaces that gate with emcp_fork_premium_tools_enabled()
 * (default true, filterable). These tests pin the unlocked surface AND the
 * kill-switch path so neither silently regresses.
 *
 * @group premium-unlock
 * @package Elementor_MCP\Tests
 */

namespace Elementor_MCP\Tests;

require_once __DIR__ . '/class-ability-test-case.php';

use Elementor_MCP_Data;
use Elementor_MCP_Seo_Abilities;
use Elementor_MCP_A11y_Abilities;
use Elementor_MCP_System_Kit_Abilities;
use Elementor_MCP_Widget_Builder_Abilities;
use Elementor_MCP_Widget_Store;

class PremiumTierUnlockedTest extends Ability_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_emcp_fork_premium_enabled'] ); // default: enabled
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_emcp_fork_premium_enabled'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// The 19 tools register by default
	// -------------------------------------------------------------------------

	public function test_brand_kit_tools_register_by_default(): void {
		$abilities = new Elementor_MCP_System_Kit_Abilities();
		$this->assertSame(
			array(
				'elementor-mcp/list-brand-kits',
				'elementor-mcp/apply-brand-kit',
				'elementor-mcp/replace-system-colors',
				'elementor-mcp/replace-system-typography',
			),
			$abilities->get_ability_names()
		);
	}

	public function test_seo_tools_register_by_default(): void {
		$abilities = new Elementor_MCP_Seo_Abilities( new Elementor_MCP_Data() );
		$this->assertSame(
			array(
				'elementor-mcp/audit-page-seo',
				'elementor-mcp/extract-keywords-from-content',
				'elementor-mcp/generate-meta-tags',
				'elementor-mcp/generate-schema-markup',
			),
			$abilities->get_ability_names()
		);
	}

	public function test_a11y_tools_register_by_default(): void {
		$abilities = new Elementor_MCP_A11y_Abilities( new Elementor_MCP_Data() );
		$this->assertSame(
			array(
				'elementor-mcp/audit-page-a11y',
				'elementor-mcp/fix-color-contrast',
				'elementor-mcp/add-alt-text-from-context',
			),
			$abilities->get_ability_names()
		);
	}

	public function test_widget_builder_tools_register_by_default(): void {
		$abilities = new Elementor_MCP_Widget_Builder_Abilities();
		$this->assertSame(
			array(
				'elementor-mcp/list-control-types',
				'elementor-mcp/validate-widget-spec',
				'elementor-mcp/create-custom-widget',
				'elementor-mcp/update-custom-widget',
				'elementor-mcp/get-custom-widget',
				'elementor-mcp/list-custom-widgets',
				'elementor-mcp/set-widget-status',
				'elementor-mcp/delete-custom-widget',
			),
			$abilities->get_ability_names()
		);
	}

	// -------------------------------------------------------------------------
	// Kill switch: the filter can turn the whole pack off
	// -------------------------------------------------------------------------

	public function test_kill_switch_empties_every_pack(): void {
		$GLOBALS['_emcp_fork_premium_enabled'] = false;

		$this->assertSame( array(), ( new Elementor_MCP_System_Kit_Abilities() )->get_ability_names() );
		$this->assertSame( array(), ( new Elementor_MCP_Seo_Abilities( new Elementor_MCP_Data() ) )->get_ability_names() );
		$this->assertSame( array(), ( new Elementor_MCP_A11y_Abilities( new Elementor_MCP_Data() ) )->get_ability_names() );
		$this->assertSame( array(), ( new Elementor_MCP_Widget_Builder_Abilities() )->get_ability_names() );
	}

	// -------------------------------------------------------------------------
	// Widget store: capability is the remaining guard
	// -------------------------------------------------------------------------

	public function test_widget_store_access_requires_manage_options(): void {
		$GLOBALS['_caps'] = array( 'manage_options' );
		$this->assertTrue( Elementor_MCP_Widget_Store::user_has_access() );

		$GLOBALS['_caps'] = array( 'edit_posts' ); // no manage_options
		$this->assertFalse( Elementor_MCP_Widget_Store::user_has_access() );
	}

	public function test_widget_store_access_denied_when_pack_disabled(): void {
		$GLOBALS['_caps']                      = array( 'manage_options' );
		$GLOBALS['_emcp_fork_premium_enabled'] = false;
		$this->assertFalse( Elementor_MCP_Widget_Store::user_has_access() );
	}
}
