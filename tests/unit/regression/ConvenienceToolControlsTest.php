<?php
/**
 * Every setting a convenience tool advertises must be a real control of the
 * widget it creates.
 *
 * Elementor saves an unknown setting and ignores it, so a wrong name is the
 * quietest failure this plugin has: the tool answers success and the page does
 * not change. 52 advertised parameters were that, and seven switcher values
 * (`display_percentage: "yes"` on a control whose on-value is `"show"`), found
 * by EMCP in its own catalog (#152) and shared by this fork (references
 * 2026-10-05 re-verification, P9.2).
 *
 * The control names come from a live Elementor — see tests/fixtures/README.md.
 *
 * @package Elementor_MCP
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * Pro and WooCommerce tools register only when those plugins are present
 * (ELEMENTOR_PRO_VERSION, class WooCommerce). Both are process-wide once
 * declared, and WidgetCapabilityTest asserts the constant is NOT defined — so
 * this class runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ConvenienceToolControlsTest extends TestCase {

	/** @var array */
	private static $fixture;

	/** @var array<string, array> */
	private $tools;

	public static function setUpBeforeClass(): void {
		self::$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/elementor-controls.json' ), true );
	}

	protected function setUp(): void {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '3.99.0' );
		}
		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a marker class for the registration gate, test-only.
		}
		$schema    = new \Elementor_MCP_Schema_Generator();
		$abilities = new \Elementor_MCP_Widget_Abilities(
			new \Elementor_MCP_Data(),
			new \Elementor_MCP_Element_Factory(),
			$schema,
			new \Elementor_MCP_Settings_Validator( $schema )
		);
		$abilities->register();
		$this->tools = $abilities->convenience_tools();
	}

	/** @return array<string, true>|null Control names of a widget, or null when the fixture has no such widget. */
	private function controls( string $widget_type ): ?array {
		if ( ! isset( self::$fixture['widgets'][ $widget_type ] ) ) {
			return null;
		}
		return array_fill_keys( array_merge( self::$fixture['common'], self::$fixture['widgets'][ $widget_type ]['controls'] ), true );
	}

	public function test_the_fixture_is_the_one_this_test_was_written_against(): void {
		$this->assertIsArray( self::$fixture );
		$this->assertNotEmpty( self::$fixture['common'] );
		$this->assertGreaterThan( 50, count( self::$fixture['widgets'] ) );
	}

	/** The tools exist, and nearly all of them are checked — a test that skips everything proves nothing. */
	public function test_the_tools_are_registered_and_covered(): void {
		$this->assertGreaterThan( 55, count( $this->tools ) );
		$unchecked = array();
		foreach ( $this->tools as $name => $tool ) {
			if ( null === $this->controls( $tool['widget_type'] ) ) {
				$unchecked[] = $name;
			}
		}
		sort( $unchecked );
		// Not on the site the fixture was read from (no WooCommerce there).
		$this->assertSame(
			array(
				'elementor-mcp/add-wc-add-to-cart',
				'elementor-mcp/add-wc-cart',
				'elementor-mcp/add-wc-checkout',
				'elementor-mcp/add-wc-menu-cart',
				'elementor-mcp/add-wc-products',
			),
			$unchecked,
			'a tool whose widget is missing from the fixture is unchecked, not passed — refresh the fixture or name it here'
		);
	}

	public function test_every_advertised_parameter_is_a_control_of_its_widget(): void {
		$wrong = array();
		foreach ( $this->tools as $name => $tool ) {
			$controls = $this->controls( $tool['widget_type'] );
			if ( null === $controls ) {
				continue;
			}
			foreach ( $tool['params'] as $param ) {
				if ( ! isset( $controls[ $param ] ) ) {
					$wrong[] = $name . ' → ' . $param;
				}
			}
		}
		$this->assertSame( array(), $wrong, 'advertised, saved, ignored by Elementor' );
	}

	public function test_every_default_is_a_control_of_its_widget(): void {
		$wrong = array();
		foreach ( $this->tools as $name => $tool ) {
			$controls = $this->controls( $tool['widget_type'] );
			if ( null === $controls ) {
				continue;
			}
			foreach ( array_keys( $tool['defaults'] ) as $key ) {
				if ( ! isset( $controls[ $key ] ) ) {
					$wrong[] = $name . ' → ' . $key;
				}
			}
		}
		$this->assertSame( array(), $wrong, 'a default applied on every call and ignored on every call' );
	}

	/**
	 * A switcher is on only at its own return value. Anything else — "yes" on a
	 * control whose on-value is "show" — is stored and reads as off.
	 */
	public function test_a_switcher_is_offered_and_defaulted_only_at_its_own_on_value(): void {
		// Legacy: these tools offer "no" for off. Elementor reads any value
		// other than the on-value as off, so "no" works; "" is the stored form.
		$off   = array( '', 'no' );
		$wrong = array();
		foreach ( $this->tools as $name => $tool ) {
			$switchers = self::$fixture['widgets'][ $tool['widget_type'] ]['switchers'] ?? null;
			if ( null === $switchers ) {
				continue;
			}
			foreach ( $tool['enums'] as $param => $values ) {
				if ( ! isset( $switchers[ $param ] ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					if ( $value !== $switchers[ $param ] && ! in_array( $value, $off, true ) ) {
						$wrong[] = sprintf( '%s → %s offers "%s"; the on-value is "%s"', $name, $param, $value, $switchers[ $param ] );
					}
				}
			}
			foreach ( $tool['defaults'] as $key => $value ) {
				if ( isset( $switchers[ $key ] ) && is_string( $value ) && $value !== $switchers[ $key ] && ! in_array( $value, $off, true ) ) {
					$wrong[] = sprintf( '%s → default %s is "%s"; the on-value is "%s"', $name, $key, $value, $switchers[ $key ] );
				}
			}
		}
		$this->assertSame( array(), $wrong );
	}

	/** The renames themselves, so a revert of one is named rather than merely counted. */
	public function test_the_corrected_names_are_the_ones_advertised(): void {
		$expect = array(
			'elementor-mcp/add-heading'  => array( 'text_stroke_text_stroke_type', 'text_stroke_text_stroke', 'text_shadow_text_shadow_type', 'text_shadow_text_shadow' ),
			'elementor-mcp/add-image'    => array( 'space', 'object-fit', 'opacity_hover' ),
			'elementor-mcp/add-button'   => array( 'text_padding' ),
			'elementor-mcp/add-hotspot'  => array( 'style_hotspot_color', 'style_tooltip_color', 'style_tooltip_typography_typography' ),
			'elementor-mcp/add-flip-box' => array( 'background_a_background', 'background_a_color', 'icon_primary_color', 'button_text_color' ),
		);
		$gone   = array(
			'elementor-mcp/add-heading' => array( 'text_stroke_stroke_width', 'title_text_shadow_text_shadow' ),
			'elementor-mcp/add-image'   => array( 'max_width', 'object_fit', 'hover_opacity' ),
			'elementor-mcp/add-button'  => array( 'button_padding' ),
			'elementor-mcp/add-video'   => array( 'modestbranding' ),
		);
		foreach ( $expect as $name => $params ) {
			foreach ( $params as $param ) {
				$this->assertContains( $param, $this->tools[ $name ]['params'], "$name must advertise $param" );
			}
		}
		foreach ( $gone as $name => $params ) {
			foreach ( $params as $param ) {
				$this->assertNotContains( $param, $this->tools[ $name ]['params'], "$name must not advertise $param" );
			}
		}
		$this->assertSame( 'show', $this->tools['elementor-mcp/add-progress']['defaults']['display_percentage'] );
	}
}
