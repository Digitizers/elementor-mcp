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

	public function test_local_system_kit_writers_register_but_hosted_tools_stay_gated(): void {
		// The two LOCAL writers act on this site's own kit and unlock; the two
		// HOSTED-library tools (list/apply-brand-kits) fetch upstream licensed
		// content the fork does not unlock, so they stay gated (no emcp_pro_fs
		// in the test env => Pro_Brand_Kits::user_has_access() is false).
		$abilities = new Elementor_MCP_System_Kit_Abilities();
		$this->assertSame(
			array(
				'elementor-mcp/replace-system-colors',
				'elementor-mcp/replace-system-typography',
			),
			$abilities->get_ability_names()
		);
	}

	public function test_hosted_brand_kit_tools_fail_closed_without_license(): void {
		$abilities = new Elementor_MCP_System_Kit_Abilities();
		$list = $abilities->execute_list_brand_kits( array() );
		$this->assertInstanceOf( \WP_Error::class, $list );
		$this->assertSame( 'no_license', $list->get_error_code() );
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

	// -------------------------------------------------------------------------
	// The v4 defaults migration un-disables exactly the unlocked tools. The
	// admin's slug lists drive that migration, so they must stay in lockstep
	// with the ability classes — a new SEO/a11y/widget tool added to a pack but
	// not to its slug list would silently stay disabled after the unlock.
	// -------------------------------------------------------------------------

	public function test_migration_slug_lists_match_the_unlocked_ability_surface(): void {
		$seo  = ( new \Elementor_MCP_Seo_Abilities( new Elementor_MCP_Data() ) )->get_ability_names();
		$a11y = ( new \Elementor_MCP_A11y_Abilities( new Elementor_MCP_Data() ) )->get_ability_names();
		sort( $seo );
		$a11y_seo = array_merge( $seo, $a11y );
		sort( $a11y_seo );
		$slugs = \Elementor_MCP_Admin::seo_a11y_tool_slugs();
		sort( $slugs );
		$this->assertSame( $a11y_seo, $slugs, 'seo_a11y_tool_slugs() must cover exactly the SEO + A11y ability names' );

		$wb    = ( new \Elementor_MCP_Widget_Builder_Abilities() )->get_ability_names();
		$wbslu = \Elementor_MCP_Admin::widget_builder_tool_slugs();
		sort( $wb );
		sort( $wbslu );
		$this->assertSame( $wb, $wbslu, 'widget_builder_tool_slugs() must cover exactly the Widget Builder ability names' );
	}

	public function test_plugin_unlock_slugs_are_the_seo_a11y_and_widget_builder_surface(): void {
		// The always-on unlock reconciliation (runs on every request path, not
		// just admin) derives its slug set straight from the ability classes.
		$expected = array_merge(
			( new \Elementor_MCP_Seo_Abilities( new Elementor_MCP_Data() ) )->get_ability_names(),
			( new \Elementor_MCP_A11y_Abilities( new Elementor_MCP_Data() ) )->get_ability_names(),
			( new \Elementor_MCP_Widget_Builder_Abilities() )->get_ability_names()
		);
		$actual = \Elementor_MCP_Plugin::premium_unlock_slugs();
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
		$this->assertCount( 15, $actual, 'expected 7 SEO/A11y + 8 Widget Builder slugs' );
	}
}
