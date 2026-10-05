<?php
/**
 * A setting that is not a control of the widget is named in the response.
 *
 * Elementor saves it and ignores it. validate() cannot say so — it is
 * advisory, logs only under WP_DEBUG, and its group-prefix heuristic accepts
 * `text_stroke_stroke_width` because other `text_stroke_*` controls exist.
 * unknown_setting_warnings() compares exact names against the widget's full
 * control stack (references 2026-10-05 re-verification, P9.2).
 *
 * @package Elementor_MCP
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class UnknownSettingWarningsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_widget_types'] = array(
			'image' => new class {
				public function get_controls(): array {
					return array_fill_keys(
						array( 'image', 'align', 'align_tablet', 'space', 'object-fit', 'opacity_hover', '_margin', 'text_stroke_text_stroke_type', 'text_stroke_text_stroke', 'text_stroke_stroke_color' ),
						array( 'type' => 'text' )
					);
				}
			},
			'broken' => new class {
				public function get_controls(): array {
					throw new \RuntimeException( 'control stack exploded' );
				}
			},
			'empty' => new class {
				public function get_controls(): array {
					return array();
				}
			},
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_widget_types'] );
	}

	private function warnings( string $widget, array $settings ): array {
		return ( new \Elementor_MCP_Settings_Validator( new \Elementor_MCP_Schema_Generator() ) )->unknown_setting_warnings( $widget, $settings );
	}

	public function test_real_controls_produce_no_warning(): void {
		$this->assertSame(
			array(),
			$this->warnings( 'image', array( 'image' => array( 'url' => 'x' ), 'space' => array( 'size' => 50 ), 'object-fit' => 'cover', 'align_tablet' => 'center', '_margin' => array() ) )
		);
	}

	public function test_a_wrong_name_is_named_with_the_real_one(): void {
		$w = $this->warnings( 'image', array( 'object_fit' => 'cover', 'hover_opacity' => array( 'size' => 0.5 ) ) );
		$this->assertCount( 2, $w );
		$this->assertStringContainsString( 'object_fit is not a control of the image widget', $w[0] );
		$this->assertStringContainsString( 'Elementor ignores it', $w[0] );
		$this->assertStringContainsString( 'Did you mean: object-fit', $w[0] );
		$this->assertStringContainsString( 'hover_opacity is not a control', $w[1] );
	}

	/** The case validate()'s group-prefix heuristic lets through. */
	public function test_a_sibling_of_a_real_group_is_not_thereby_a_control(): void {
		$w = $this->warnings( 'image', array( 'text_stroke_stroke_width' => array( 'size' => 2 ) ) );
		$this->assertCount( 1, $w );
		$this->assertStringContainsString( 'text_stroke_stroke_width is not a control', $w[0] );
	}

	public function test_a_name_with_nothing_near_it_points_at_the_schema_tool(): void {
		$w = $this->warnings( 'image', array( 'zzzzzzzzzzzz' => 1 ) );
		$this->assertStringContainsString( 'Call get-widget-schema for the real names.', $w[0] );
	}

	public function test_internal_keys_and_the_navigator_label_are_not_controls_and_not_warned(): void {
		$this->assertSame( array(), $this->warnings( 'image', array( '__globals__' => array(), '__dynamic__' => array(), '_title' => 'Hero image' ) ) );
	}

	/** An unread control stack is not an empty one: say nothing rather than call every key unknown. */
	public function test_no_warnings_when_the_control_stack_cannot_be_read(): void {
		$settings = array( 'anything' => 1 );
		$this->assertSame( array(), $this->warnings( 'no-such-widget', $settings ) );
		$this->assertSame( array(), $this->warnings( 'broken', $settings ) );
		$this->assertSame( array(), $this->warnings( 'empty', $settings ) );
	}
}
