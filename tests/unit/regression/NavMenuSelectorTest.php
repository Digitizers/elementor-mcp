<?php
/**
 * Regression — `add-nav-menu` must publish the control that picks the menu.
 *
 * Field report #5, part 5. The tool advertised `menu_name` as "Menu name (as
 * registered in WP Menus)". The Nav Menu widget has both controls and they are
 * not the same thing:
 *
 *   - `menu_name` — the widget's own TEXT control, rendered as the nav's
 *     `aria-label` (`modules/nav-menu/widgets/nav-menu.php`: added at ~L121,
 *     consumed at ~L1548).
 *   - `menu` — the SELECT of registered menu slugs, defaulting to the first
 *     one, which is what actually chooses the navigation.
 *
 * `register_convenience_tool()` passes inputs straight through, so
 * `menu_name: "main"` wrote the label and left the widget on its default menu.
 * On a one-menu site the default happened to be right, which hid the bug; on
 * any site with more than one menu it silently rendered the wrong navigation
 * while reporting success.
 *
 * A published parameter whose NAME doesn't match the control it claims to set
 * is worse than an omitted one — the agent has no reason to doubt it.
 *
 * The published props are built by a static helper so this can be asserted
 * without activating Pro — defining ELEMENTOR_PRO_VERSION here would leak into
 * the tests that assert Pro tools are NOT registered without it.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class NavMenuSelectorTest extends Ability_Test_Case {

	private function properties( array $slugs ): array {
		$GLOBALS['_nav_menus'] = $slugs;
		return \Elementor_MCP_Widget_Abilities::nav_menu_selector_props();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_nav_menus'] );
		parent::tearDown();
	}

	public function test_the_menu_selector_is_published(): void {
		$props = $this->properties( array( 'main', 'footer' ) );

		$this->assertArrayHasKey(
			'menu',
			$props,
			'`menu` is the control that chooses which navigation renders; without it the tool cannot target a menu at all.'
		);
	}

	public function test_registered_slugs_are_offered_as_an_enum(): void {
		$props = $this->properties( array( 'main', 'footer' ) );

		$this->assertSame( array( 'main', 'footer' ), $props['menu']['enum'] ?? null );
	}

	public function test_no_enum_when_the_site_has_no_menus(): void {
		$props = $this->properties( array() );

		$this->assertArrayHasKey( 'menu', $props );
		$this->assertArrayNotHasKey(
			'enum',
			$props['menu'],
			'An empty enum would reject every value, making the parameter unusable rather than merely unhelpful.'
		);
	}

	public function test_menu_name_is_described_as_the_label_not_the_selector(): void {
		$props       = $this->properties( array( 'main' ) );
		$description = $props['menu_name']['description'] ?? '';

		$this->assertNotSame(
			'',
			$description,
			'`menu_name` stays published — it is a real control — but must not claim to select the menu.'
		);
		$this->assertMatchesRegularExpression(
			'/aria-label|accessible label/i',
			$description,
			'`menu_name` sets the nav\'s accessible label.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/as registered in WP Menus/i',
			$description,
			'That phrasing is what made agents pass a menu slug to the label field.'
		);
	}
}
