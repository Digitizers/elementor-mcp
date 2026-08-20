<?php
/**
 * Regression — per-element custom CSS must reach atomic elements.
 *
 * Field report #4, parts 1.1 and 1.3.
 *
 * 1.1 `add-custom-css` wrote `settings.custom_css` unconditionally. That is the
 *     Elementor 3.x Pro control; atomic elements never read it — their CSS is
 *     compiled from `styles[].variants[]`. The write persisted, the tool
 *     reported success, and the compiled stylesheet was unchanged. Every agent
 *     on the build that produced the report reached for this tool first and
 *     believed it had worked.
 *
 * 1.3 A variant's `custom_css.raw` must be **base64**. Elementor validates and
 *     sanitizes it through `Utils::decode_string()` = `base64_decode( $raw,
 *     true )`. Plain CSS contains characters outside the base64 alphabet, so
 *     strict decoding returns `false` — which is not `null`, so validation
 *     passes — and the rule is dropped to nothing.
 *
 * @group regression
 * @group atomic
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Elementor_MCP_Atomic_Styles::build_custom_css_patch
 */
class AtomicCustomCssTest extends TestCase {

	private function atomic_element( array $styles = array() ): array {
		return array(
			'id'         => 'abc1234',
			'elType'     => 'widget',
			'widgetType' => 'e-heading',
			'settings'   => array( 'title' => array( '$$type' => 'string', 'value' => 'Hi' ) ),
			'styles'     => $styles,
			'elements'   => array(),
		);
	}

	public function test_css_is_stored_base64_in_a_style_variant(): void {
		$css   = 'selector{position:absolute;top:0;}';
		$patch = \Elementor_MCP_Atomic_Styles::build_custom_css_patch( $this->atomic_element(), $css );

		$class_id = $patch['class_id'];
		$this->assertArrayHasKey( $class_id, $patch['styles'] );

		$variant = $patch['styles'][ $class_id ]['variants'][0];
		$raw     = $variant['custom_css']['raw'];

		$this->assertSame(
			$css,
			base64_decode( $raw, true ),
			'Elementor decodes custom_css.raw with strict base64; plain CSS is silently dropped to an empty rule.'
		);
		$this->assertNotSame( $css, $raw, 'The raw value must not be the plain CSS string.' );
	}

	public function test_variant_targets_desktop_and_no_state(): void {
		$patch   = \Elementor_MCP_Atomic_Styles::build_custom_css_patch( $this->atomic_element(), 'selector{color:red;}' );
		$variant = $patch['styles'][ $patch['class_id'] ]['variants'][0];

		$this->assertSame( 'desktop', $variant['meta']['breakpoint'] );
		$this->assertNull( $variant['meta']['state'] );
	}

	public function test_class_id_belongs_to_the_element(): void {
		$patch = \Elementor_MCP_Atomic_Styles::build_custom_css_patch( $this->atomic_element(), 'selector{color:red;}' );
		$this->assertStringStartsWith( 'e-abc1234-', $patch['class_id'], 'A local class id embeds its own element id.' );
	}

	public function test_existing_local_class_is_reused_not_duplicated(): void {
		$first  = \Elementor_MCP_Atomic_Styles::build_custom_css_patch( $this->atomic_element(), 'selector{color:red;}' );
		$second = \Elementor_MCP_Atomic_Styles::build_custom_css_patch(
			$this->atomic_element( $first['styles'] ),
			'selector{margin:0;}',
			true
		);

		$this->assertSame(
			$first['class_id'],
			$second['class_id'],
			'Repeated calls must edit the element\'s existing local class, not pile up new ones.'
		);
		$this->assertCount( 1, $second['styles'] );
	}

	public function test_append_preserves_existing_css_and_replace_drops_it(): void {
		$first = \Elementor_MCP_Atomic_Styles::build_custom_css_patch( $this->atomic_element(), 'selector{color:red;}' );

		$appended = \Elementor_MCP_Atomic_Styles::build_custom_css_patch(
			$this->atomic_element( $first['styles'] ),
			'selector:hover{color:blue;}'
		);
		$this->assertStringContainsString( 'color:red', $appended['css'] );
		$this->assertStringContainsString( 'color:blue', $appended['css'] );

		$replaced = \Elementor_MCP_Atomic_Styles::build_custom_css_patch(
			$this->atomic_element( $first['styles'] ),
			'selector{margin:0;}',
			true
		);
		$this->assertSame( 'selector{margin:0;}', $replaced['css'] );
	}

	public function test_existing_props_on_the_class_are_not_dropped(): void {
		$seed = \Elementor_MCP_Atomic_Styles::create_local_class(
			'abc1234',
			array( 'color' => array( '$$type' => 'color', 'value' => '#fff' ) )
		);
		$styles = array( $seed['class_id'] => $seed['style_def'] );

		$patch   = \Elementor_MCP_Atomic_Styles::build_custom_css_patch( $this->atomic_element( $styles ), 'selector{margin:0;}' );
		$variant = $patch['styles'][ $patch['class_id'] ]['variants'][0];

		$this->assertArrayHasKey( 'color', $variant['props'], 'Adding custom CSS must not wipe the variant\'s existing props.' );
	}

	/**
	 * End-to-end through the ability: the atomic branch must be taken, nothing
	 * may land on `settings.custom_css`, and the class must be referenced from
	 * `settings.classes` — a style def nobody references is another silent
	 * no-op.
	 */
	public function test_ability_writes_a_style_variant_and_wires_the_class(): void {
		$element = $this->atomic_element();
		$saved   = null;

		$data = new class( $element, $saved ) extends \Elementor_MCP_Data {
			public array $element;
			public $saved = null;

			public function __construct( array $element, &$saved ) {
				$this->element = $element;
			}

			public function get_page_data( int $post_id ): array {
				return array( $this->element );
			}

			public function save_page_data( int $post_id, array $data ) {
				$this->saved = $data;
				return true;
			}
		};

		$ability = new \Elementor_MCP_Custom_Code_Abilities( $data, new \Elementor_MCP_Element_Factory() );

		$result = $ability->execute_add_custom_css( array(
			'post_id'    => 11,
			'element_id' => 'abc1234',
			'css'        => 'selector{position:absolute;}',
		) );

		$this->assertIsArray( $result, 'The call must succeed.' );
		$this->assertSame( 'style_variant', $result['stored_as'] ?? null, 'An atomic target must report the mechanism used.' );

		$written = $data->saved[0];

		$this->assertArrayNotHasKey(
			'custom_css',
			$written['settings'],
			'Atomic elements never read settings.custom_css — writing it there is the silent no-op this fixes.'
		);

		$class_id = $result['class_id'];
		$this->assertArrayHasKey( $class_id, $written['styles'], 'The style definition must be on the element root.' );
		$this->assertContains(
			$class_id,
			$written['settings']['classes']['value'] ?? array(),
			'A style def that settings.classes never references compiles to nothing.'
		);

		$raw = $written['styles'][ $class_id ]['variants'][0]['custom_css']['raw'];
		$this->assertSame( 'selector{position:absolute;}', base64_decode( $raw, true ) );
	}

	public function test_classic_element_still_uses_settings_custom_css(): void {
		$classic = array(
			'id'         => 'zzz9999',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array( 'title' => 'plain' ),
			'elements'   => array(),
		);

		$data = new class( $classic ) extends \Elementor_MCP_Data {
			public array $element;
			public $saved = null;

			public function __construct( array $element ) {
				$this->element = $element;
			}

			public function get_page_data( int $post_id ): array {
				return array( $this->element );
			}

			public function save_page_data( int $post_id, array $data ) {
				$this->saved = $data;
				return true;
			}
		};

		$ability = new \Elementor_MCP_Custom_Code_Abilities( $data, new \Elementor_MCP_Element_Factory() );

		$result = $ability->execute_add_custom_css( array(
			'post_id'    => 11,
			'element_id' => 'zzz9999',
			'css'        => 'selector{color:red;}',
		) );

		$this->assertArrayNotHasKey( 'stored_as', $result, 'A classic widget keeps the 3.x control path.' );
		$this->assertSame( 'selector{color:red;}', $data->saved[0]['settings']['custom_css'] );
	}

	public function test_atomic_element_is_recognised(): void {
		$this->assertTrue( \Elementor_MCP_Data::is_atomic_element( $this->atomic_element() ) );
		$this->assertFalse(
			\Elementor_MCP_Data::is_atomic_element( array(
				'id'         => 'zzz9999',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'plain' ),
			) ),
			'A classic widget keeps the settings.custom_css path.'
		);
	}
}
