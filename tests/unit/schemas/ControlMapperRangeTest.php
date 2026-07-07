<?php
/**
 * Numeric range enrichment: number/slider controls carry their range into the
 * generated JSON Schema so an agent sees the valid bounds without a second lookup.
 *
 * @group schemas
 * @package Elementor_MCP\Tests\Schemas
 */

namespace Elementor_MCP\Tests\Schemas;

use PHPUnit\Framework\TestCase;

class ControlMapperRangeTest extends TestCase {

	private function map( array $control ): array {
		return \Elementor_MCP_Control_Mapper::map( $control );
	}

	public function test_number_emits_min_max_step_when_grid_aligned(): void {
		$out = $this->map(
			array(
				'type' => 'number',
				'min'  => 0,
				'max'  => 12,
				'step' => 2,
			)
		);
		$this->assertSame( 'number', $out['type'] );
		$this->assertSame( 0, $out['minimum'] );
		$this->assertSame( 12, $out['maximum'] );
		$this->assertSame( 2, $out['multipleOf'], 'min=0 is a multiple of step, so multipleOf is valid.' );
	}

	public function test_number_offset_range_omits_multipleof(): void {
		// min=1, step=2 → valid values 1, 3, 5 … which are NOT multiples of 2.
		// multipleOf would reject every one of them, so it must be omitted.
		$out = $this->map(
			array(
				'type' => 'number',
				'min'  => 1,
				'max'  => 11,
				'step' => 2,
			)
		);
		$this->assertSame( 1, $out['minimum'] );
		$this->assertSame( 11, $out['maximum'] );
		$this->assertArrayNotHasKey( 'multipleOf', $out, 'An offset grid (min not a multiple of step) must not emit multipleOf.' );
	}

	public function test_number_emits_multipleof_when_min_is_a_multiple_of_step(): void {
		$out = $this->map(
			array(
				'type' => 'number',
				'min'  => 4,
				'step' => 2,
			)
		);
		$this->assertSame( 2, $out['multipleOf'], 'min=4 is a multiple of step=2, so multipleOf is valid.' );
	}

	public function test_number_without_range_stays_bare(): void {
		$out = $this->map( array( 'type' => 'number' ) );
		$this->assertSame( 'number', $out['type'] );
		$this->assertArrayNotHasKey( 'minimum', $out );
		$this->assertArrayNotHasKey( 'maximum', $out );
		$this->assertArrayNotHasKey( 'multipleOf', $out );
	}

	public function test_number_ignores_non_numeric_and_zero_step(): void {
		$out = $this->map(
			array(
				'type' => 'number',
				'min'  => '',
				'max'  => 'x',
				'step' => 0,
			)
		);
		$this->assertArrayNotHasKey( 'minimum', $out );
		$this->assertArrayNotHasKey( 'maximum', $out );
		$this->assertArrayNotHasKey( 'multipleOf', $out, 'A zero/omitted step must not emit multipleOf (0 is invalid JSON Schema).' );
	}

	public function test_slider_single_unit_range_constrains_size(): void {
		$out = $this->map(
			array(
				'type'       => 'slider',
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 500 ) ),
			)
		);
		$this->assertSame( 'object', $out['type'] );
		$this->assertSame( 0, $out['properties']['size']['minimum'] );
		$this->assertSame( 500, $out['properties']['size']['maximum'] );
		$this->assertSame( array( 'px' ), $out['properties']['unit']['enum'] );
	}

	public function test_slider_multi_unit_range_leaves_size_unconstrained(): void {
		$out = $this->map(
			array(
				'type'       => 'slider',
				'size_units' => array( 'px', '%', 'em' ),
				'range'      => array(
					'px' => array( 'min' => 0, 'max' => 1000 ),
					'%'  => array( 'min' => 0, 'max' => 100 ),
				),
			)
		);
		// Bounds differ per unit → no hard size min/max, but expose the unit set.
		$this->assertArrayNotHasKey( 'minimum', $out['properties']['size'] );
		$this->assertArrayNotHasKey( 'maximum', $out['properties']['size'] );
		$this->assertSame( array( 'px', '%', 'em' ), $out['properties']['unit']['enum'] );
	}

	public function test_slider_advertising_many_units_but_range_for_one_does_not_borrow_bounds(): void {
		// The control offers px AND % but only defines a range for px. The px
		// bounds must NOT be applied to `size`, since the user may pick %.
		$out = $this->map(
			array(
				'type'       => 'slider',
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 500 ) ),
			)
		);
		$this->assertArrayNotHasKey( 'minimum', $out['properties']['size'], 'Single-unit range must not constrain a multi-unit slider.' );
		$this->assertArrayNotHasKey( 'maximum', $out['properties']['size'] );
		$this->assertSame( array( 'px', '%' ), $out['properties']['unit']['enum'] );
	}

	public function test_slider_without_range_or_units_stays_open(): void {
		$out = $this->map( array( 'type' => 'slider' ) );
		$this->assertSame( 'number', $out['properties']['size']['type'] );
		$this->assertSame( 'string', $out['properties']['unit']['type'] );
		$this->assertArrayNotHasKey( 'enum', $out['properties']['unit'] );
	}
}
