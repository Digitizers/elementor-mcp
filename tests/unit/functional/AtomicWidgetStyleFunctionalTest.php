<?php
/**
 * Functional — atomic widgets carry a styles map when style props are passed.
 *
 * @group functional
 * @group atomic
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class AtomicWidgetStyleFunctionalTest extends Ability_Test_Case {

	public function test_factory_widget_without_style_props_has_empty_styles(): void {
		$el = $this->make_factory()->create_atomic_widget( 'e-heading', array() );
		$this->assertSame( array(), $el['styles'] );
	}

	public function test_factory_widget_with_style_props_populates_styles_and_classes(): void {
		$el = $this->make_factory()->create_atomic_widget(
			'e-heading',
			array(),
			array( 'color' => '#112233', 'font_size' => 40 )
		);
		$this->assertNotEmpty( $el['styles'], 'styles map should be populated' );
		// apply_to_element adds the local class id to settings.classes.
		$this->assertArrayHasKey( 'classes', $el['settings'] );
		$this->assertNotEmpty( $el['settings']['classes']['value'] );
	}
}
