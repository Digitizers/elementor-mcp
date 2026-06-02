<?php
/**
 * Unit tests — Atomic_Styles::build_common_props() color/background shapes.
 *
 * Atomic's CSS engine validates style props against its style-schema and drops
 * invalid keys silently. `color` must be a Color_Prop_Type ($$type: color) and
 * backgrounds must use the `background` key (Background_Prop_Type shape), NOT a
 * flat `background-color` string — those were dropped, blanking every container
 * and button background. These tests lock the valid shapes. Verified live.
 *
 * @group unit
 * @group atomic
 * @package Elementor_MCP\Tests
 */

namespace Elementor_MCP\Tests;

use PHPUnit\Framework\TestCase;

class AtomicStylesCommonTest extends TestCase {

	public function test_color_is_emitted_as_color_prop_type(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [ 'color' => '#0a0a0a' ] );
		$this->assertArrayHasKey( 'color', $props );
		$this->assertSame( 'color', $props['color']['$$type'] );
		$this->assertSame( '#0a0a0a', $props['color']['value'] );
	}

	public function test_color_is_not_emitted_as_plain_string(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [ 'color' => '#fff' ] );
		$this->assertIsArray( $props['color'], 'color must be a typed prop, not a plain string' );
	}

	public function test_background_color_emits_background_shape_not_flat_key(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [ 'background_color' => '#112233' ] );
		$this->assertArrayNotHasKey( 'background-color', $props, 'flat background-color is dropped by atomic' );
		$this->assertArrayHasKey( 'background', $props );
		$this->assertSame( 'background', $props['background']['$$type'] );
		$this->assertSame( 'color', $props['background']['value']['color']['$$type'] );
		$this->assertSame( '#112233', $props['background']['value']['color']['value'] );
	}

	public function test_max_width_emitted_as_size_prop(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [ 'max_width' => 1360 ] );
		$this->assertArrayHasKey( 'max-width', $props );
		$this->assertSame( 'size', $props['max-width']['$$type'] );
		$this->assertSame( 1360.0, (float) $props['max-width']['value']['size'] );
		$this->assertSame( 'px', $props['max-width']['value']['unit'] );
	}

	public function test_border_props_emit_valid_schema_keys(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [
			'border_width' => 1,
			'border_color' => '#E6EAF0',
			'border_style' => 'solid',
		] );
		$this->assertSame( 'size', $props['border-width']['$$type'] );
		$this->assertSame( 1.0, (float) $props['border-width']['value']['size'] );
		$this->assertSame( 'color', $props['border-color']['$$type'] );
		$this->assertSame( '#E6EAF0', $props['border-color']['value'] );
		$this->assertSame( 'string', $props['border-style']['$$type'] );
		$this->assertSame( 'solid', $props['border-style']['value'] );
	}

	public function test_gradient_emits_background_overlay_shape(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [
			'gradient_from' => '#0B0F1A',
			'gradient_to'   => '#15203F',
			'gradient_type' => 'radial',
		] );
		$this->assertArrayHasKey( 'background', $props );
		$overlay = $props['background']['value']['background-overlay'];
		$this->assertSame( 'background-overlay', $overlay['$$type'] );
		$item = $overlay['value'][0];
		$this->assertSame( 'background-gradient-overlay', $item['$$type'] );
		$this->assertSame( 'radial', $item['value']['type']['value'] );
		$stops = $item['value']['stops'];
		$this->assertSame( 'gradient-color-stop', $stops['$$type'] );
		$this->assertSame( '#0B0F1A', $stops['value'][0]['value']['color']['value'] );
		$this->assertSame( '#15203F', $stops['value'][1]['value']['color']['value'] );
	}

	public function test_gradient_preserves_solid_background_color_base(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( [
			'background_color' => '#0B0F1A',
			'gradient_from'    => '#0B0F1A',
			'gradient_to'      => '#15203F',
		] );
		$this->assertSame( '#0B0F1A', $props['background']['value']['color']['value'], 'solid base color kept' );
		$this->assertArrayHasKey( 'background-overlay', $props['background']['value'], 'overlay added on top' );
	}
}
