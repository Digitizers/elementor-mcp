<?php
/**
 * The dimension guard is in the WRITE, not beside it.
 *
 * Until 1.40.x the tools warned about a partial classic dimension and saved it
 * exactly as sent, so the agent was told and the page still lost the whole CSS
 * rule. These tests go through the abilities and read what reached
 * save_page_data() (EMCP #151; references 2026-10-05 re-verification, P9.2).
 *
 * @package Elementor_MCP
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class PartialDimensionGuardWritePathsTest extends TestCase {

	private const FULL = array( 'top' => '10', 'right' => '20', 'bottom' => '30', 'left' => '40', 'unit' => 'px', 'isLinked' => false );

	/** A data layer over an in-memory page: real find/update, captured save. */
	private function data( array $tree ): \Elementor_MCP_Data {
		return new class( $tree ) extends \Elementor_MCP_Data {
			/** @var array */
			public $tree;
			/** @var array|null */
			public $saved = null;
			public function __construct( array $tree ) {
				$this->tree = $tree;
			}
			public function get_page_data( int $post_id ) {
				return $this->tree;
			}
			public function save_page_data( int $post_id, array $data, $intent = null ) {
				$this->saved = $data;
				return true;
			}
		};
	}

	private function page(): array {
		return array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array( 'padding' => self::FULL ),
				'elements' => array(
					array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Hi' ), 'elements' => array() ),
				),
			),
		);
	}

	public function test_update_container_saves_the_filled_value_not_the_partial_one(): void {
		$data   = $this->data( $this->page() );
		$layout = new \Elementor_MCP_Layout_Abilities( $data, new \Elementor_MCP_Element_Factory() );

		$out = $layout->execute_update_container( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => array( 'padding' => array( 'top' => '0', 'unit' => 'px' ) ) ) );

		$this->assertTrue( $out['success'] );
		$this->assertSame( '0', $data->saved[0]['settings']['padding']['top'] );
		$this->assertSame( '20', $data->saved[0]['settings']['padding']['right'], 'the blank sides came from the saved value' );
		$this->assertSame( '40', $data->saved[0]['settings']['padding']['left'] );
		$this->assertStringContainsString( 'filled from the saved value', $out['settings_warnings'][0] );
	}

	public function test_update_widget_does_not_write_a_partial_value_it_cannot_fill(): void {
		$data    = $this->data( $this->page() );
		$schema  = new \Elementor_MCP_Schema_Generator();
		$widgets = new \Elementor_MCP_Widget_Abilities( $data, new \Elementor_MCP_Element_Factory(), $schema, new \Elementor_MCP_Settings_Validator( $schema ) );

		$out = $widgets->execute_update_widget( array( 'post_id' => 7, 'element_id' => 'w1', 'settings' => array( '_margin' => array( 'top' => '12', 'unit' => 'px' ), 'title' => 'Hello' ) ) );

		$this->assertTrue( $out['success'] );
		$saved = $data->saved[0]['elements'][0]['settings'];
		$this->assertSame( 'Hello', $saved['title'], 'the rest of the call is written' );
		$this->assertArrayNotHasKey( '_margin', $saved, 'a partial value with nothing to fill from never reaches the page' );
		$this->assertStringContainsString( '_margin had blank sides (right, bottom, left) and no saved value to fill from', $out['settings_warnings'][0] );
	}

	public function test_update_element_goes_through_the_same_guard(): void {
		$data   = $this->data( $this->page() );
		$layout = new \Elementor_MCP_Layout_Abilities( $data, new \Elementor_MCP_Element_Factory() );

		$out = $layout->execute_update_element( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => array( 'padding' => array( 'bottom' => '99' ) ) ) );

		$this->assertSame( '99', $data->saved[0]['settings']['padding']['bottom'] );
		$this->assertSame( '10', $data->saved[0]['settings']['padding']['top'] );
		$this->assertCount( 1, $out['settings_warnings'] );
	}

	/**
	 * A later operation that completes the same key leaves no warning about the
	 * earlier, overwritten one (Codex round-7 P2 on #74) — and fills from the
	 * element as the earlier operation left it.
	 */
	public function test_batch_update_judges_each_operation_against_the_page_as_it_then_stands(): void {
		$data   = $this->data( $this->page() );
		$layout = new \Elementor_MCP_Layout_Abilities( $data, new \Elementor_MCP_Element_Factory() );
		$whole  = array( 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'unit' => 'px' );

		$out = $layout->execute_batch_update(
			array(
				'post_id'    => 7,
				'operations' => array(
					array( 'element_id' => 'c1', 'settings' => array( 'padding' => array( 'top' => '5', 'unit' => 'px' ) ) ),
					array( 'element_id' => 'c1', 'settings' => array( 'padding' => $whole ) ),
					array( 'element_id' => 'w1', 'settings' => array( '_padding' => array( 'left' => '3', 'unit' => 'px' ) ) ),
				),
			)
		);

		$this->assertSame( 3, $out['updated'] );
		$this->assertSame( '1', $data->saved[0]['settings']['padding']['top'], 'the later, complete value is what was saved' );
		$this->assertArrayNotHasKey( '_padding', $data->saved[0]['elements'][0]['settings'] );
		$this->assertCount( 1, $out['settings_warnings'], 'only the widget: the container key was completed by a later operation' );
		$this->assertStringStartsWith( 'w1: _padding had blank sides', $out['settings_warnings'][0] );
	}

	public function test_add_container_does_not_create_an_element_carrying_a_partial_dimension(): void {
		$data   = $this->data( array() );
		$layout = new \Elementor_MCP_Layout_Abilities( $data, new \Elementor_MCP_Element_Factory() );

		$out = $layout->execute_add_container( array( 'post_id' => 7, 'settings' => array( 'padding' => array( 'top' => '10', 'unit' => 'px' ), 'flex_direction' => 'row' ) ) );

		$this->assertNotEmpty( $out['element_id'] );
		$this->assertArrayNotHasKey( 'padding', $data->saved[0]['settings'] );
		$this->assertSame( 'row', $data->saved[0]['settings']['flex_direction'] );
		$this->assertStringContainsString( 'padding had blank sides (right, bottom, left) and was not written', $out['settings_warnings'][0] );
	}
}
