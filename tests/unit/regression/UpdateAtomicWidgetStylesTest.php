<?php
/**
 * Regression — `update-atomic-widget` must be able to change styles.
 *
 * Field report #4, part 1.2. The tool only ever wrote `settings`, but on an
 * atomic element size, spacing and appearance are not settings — they live in
 * the `styles` map, compiled by the atomic CSS engine. A padding or width sent
 * through `settings` merged, saved, reported success and was dropped by
 * Elementor's parser.
 *
 * Three separate agents on that build independently verified against the
 * compiled CSS and each concluded that **delete-and-recreate is the only
 * reliable path** for a styling change — which meant correcting one card's
 * padding required rebuilding the card and everything inside it.
 *
 * @group regression
 * @group atomic
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class UpdateAtomicWidgetStylesTest extends Ability_Test_Case {

	/**
	 * @param array $styles Pre-existing styles map for the element.
	 * @return object Data stub capturing what was saved.
	 */
	private function make_data( array $styles = array() ) {
		return new class( $styles ) extends \Elementor_MCP_Data {
			public array $element;
			public $saved = null;

			public function __construct( array $styles ) {
				$this->element = array(
					'id'         => 'abc1234',
					'elType'     => 'widget',
					'widgetType' => 'e-heading',
					'settings'   => array( 'title' => array( '$$type' => 'string', 'value' => 'Hi' ) ),
					'styles'     => $styles,
					'elements'   => array(),
				);
			}

			public function get_page_data( int $post_id ): array {
				return array( $this->element );
			}

			public function save_page_data( int $post_id, array $data ) {
				$this->saved = $data;
				return true;
			}
		};
	}

	private function ability( $data ): \Elementor_MCP_Atomic_Widget_Abilities {
		return new \Elementor_MCP_Atomic_Widget_Abilities( $data, new \Elementor_MCP_Element_Factory() );
	}

	private function base_variant( array $saved_element ): array {
		$class_id = array_key_first( $saved_element['styles'] );
		return $saved_element['styles'][ $class_id ]['variants'][0];
	}

	public function test_padding_reaches_the_style_variant_not_settings(): void {
		$data = $this->make_data();

		$result = $this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'    => 3,
			'element_id' => 'abc1234',
			'padding'    => 24,
		) );

		$this->assertIsArray( $result, 'A style-only update must be accepted.' );

		$element = $data->saved[0];

		$this->assertNotEmpty( $element['styles'], 'The style must land in the styles map.' );
		$this->assertArrayNotHasKey(
			'padding',
			$element['settings'],
			'Padding is not a setting on an atomic element — writing it there is the silent no-op this fixes.'
		);
		$this->assertNotEmpty( $this->base_variant( $element )['props'] );
	}

	public function test_the_class_is_referenced_from_settings_classes(): void {
		$data = $this->make_data();

		$this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'    => 3,
			'element_id' => 'abc1234',
			'width'      => 320,
		) );

		$element  = $data->saved[0];
		$class_id = array_key_first( $element['styles'] );

		$this->assertContains(
			$class_id,
			$element['settings']['classes']['value'] ?? array(),
			'A style definition nothing references compiles to nothing.'
		);
	}

	public function test_existing_props_survive_a_partial_style_update(): void {
		$seed = \Elementor_MCP_Atomic_Styles::create_local_class(
			'abc1234',
			array( 'color' => array( '$$type' => 'color', 'value' => '#fff' ) )
		);
		$data = $this->make_data( array( $seed['class_id'] => $seed['style_def'] ) );

		$this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'    => 3,
			'element_id' => 'abc1234',
			'padding'    => 8,
		) );

		$props = $this->base_variant( $data->saved[0] )['props'];

		$this->assertArrayHasKey( 'color', $props, 'A partial update must not drop styles it did not mention.' );
		$this->assertNotEmpty( $props['padding'] ?? $props['padding-block-start'] ?? null );
	}

	public function test_typography_is_accepted_too(): void {
		$data = $this->make_data();

		$this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'     => 3,
			'element_id'  => 'abc1234',
			'font_family' => 'Hanken Grotesk',
		) );

		$props = $this->base_variant( $data->saved[0] )['props'];

		$this->assertSame( 'font-family', $props['font-family']['$$type'], 'Typography goes through the same style path.' );
	}

	public function test_content_settings_still_work_alongside_styles(): void {
		$data = $this->make_data();

		$this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'    => 3,
			'element_id' => 'abc1234',
			'settings'   => array( 'title' => array( '$$type' => 'string', 'value' => 'Changed' ) ),
			'padding'    => 12,
		) );

		$element = $data->saved[0];

		$this->assertSame( 'Changed', $element['settings']['title']['value'] );
		$this->assertNotEmpty( $element['styles'] );
	}

	/**
	 * On Elementor 4 the four padding sides collapse into ONE `padding` prop
	 * holding a `dimensions` shape. A shallow merge would replace that whole
	 * envelope when the caller touched a single side, silently dropping the
	 * other three — the same partial-update data loss this tool exists to end,
	 * one level down.
	 */
	public function test_updating_one_padding_side_keeps_the_others_on_v4(): void {
		$GLOBALS['_elementor_version_override'] = '4.2.3';

		try {
			$seed = \Elementor_MCP_Atomic_Styles::create_local_class(
				'abc1234',
				\Elementor_MCP_Atomic_Styles::build_common_props( array(
					'padding_top'    => 10,
					'padding_right'  => 20,
					'padding_bottom' => 30,
					'padding_left'   => 40,
				) )
			);

			// Sanity: the seed really is one composite prop, not four.
			$this->assertSame( 'dimensions', $seed['style_def']['variants'][0]['props']['padding']['$$type'] );

			$data = $this->make_data( array( $seed['class_id'] => $seed['style_def'] ) );

			$this->ability( $data )->execute_update_atomic_widget( array(
				'post_id'     => 3,
				'element_id'  => 'abc1234',
				'padding_top' => 99,
			) );

			$padding = $this->base_variant( $data->saved[0] )['props']['padding']['value'];

			$this->assertSame( 99.0, $padding['block-start']['value']['size'], 'The requested side must change.' );
			foreach ( array( 'inline-end' => 20.0, 'block-end' => 30.0, 'inline-start' => 40.0 ) as $side => $expected ) {
				$this->assertSame(
					$expected,
					$padding[ $side ]['value']['size'] ?? null,
					"Side `$side` was not mentioned and must survive."
				);
			}
		} finally {
			unset( $GLOBALS['_elementor_version_override'] );
		}
	}

	/**
	 * Lists are whole values, not addressable members: a new box-shadow
	 * replaces the old one rather than merging into it.
	 */
	public function test_a_new_shadow_replaces_rather_than_merges(): void {
		$seed = \Elementor_MCP_Atomic_Styles::create_local_class(
			'abc1234',
			\Elementor_MCP_Atomic_Styles::build_common_props( array( 'shadow_color' => 'rgba(0,0,0,1)', 'shadow_blur' => 4 ) )
		);
		$data = $this->make_data( array( $seed['class_id'] => $seed['style_def'] ) );

		$this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'      => 3,
			'element_id'   => 'abc1234',
			'shadow_color' => 'rgba(255,0,0,1)',
		) );

		$shadows = $this->base_variant( $data->saved[0] )['props']['box-shadow']['value'];

		$this->assertCount( 1, $shadows, 'A shadow list is replaced, not appended to.' );
		$this->assertSame( 'rgba(255,0,0,1)', $shadows[0]['value']['color']['value'] );
	}

	public function test_an_empty_update_is_refused_rather_than_reported_successful(): void {
		$data = $this->make_data();

		$result = $this->ability( $data )->execute_update_atomic_widget( array(
			'post_id'    => 3,
			'element_id' => 'abc1234',
		) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'nothing_to_update', $result->get_error_code() );
	}
}
