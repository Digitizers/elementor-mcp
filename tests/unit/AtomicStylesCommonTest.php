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
}
