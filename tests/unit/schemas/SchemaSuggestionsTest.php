<?php
/**
 * P1.1 schema-in-error: a widget_not_found / invalid_widget_type error carries the
 * nearest valid widget type names so an agent can self-correct in one round trip.
 *
 * @group schemas
 * @group schema-in-error
 * @package Elementor_MCP\Tests\Schemas
 */

namespace Elementor_MCP\Tests\Schemas;

use PHPUnit\Framework\TestCase;

class SchemaSuggestionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// A small registry of valid widget types (values are unused by suggest_types).
		$GLOBALS['_widget_types'] = array(
			'heading' => true,
			'button'  => true,
			'image'   => true,
			'icon'    => true,
			'spacer'  => true,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_widget_types'] );
		parent::tearDown();
	}

	private function generator(): \Elementor_MCP_Schema_Generator {
		return new \Elementor_MCP_Schema_Generator();
	}

	public function test_suggests_the_nearest_name_by_edit_distance(): void {
		$out = $this->generator()->suggest_types( 'headline' );
		$this->assertSame( 'heading', $out[0], '"headline" → "heading" should rank first.' );
	}

	public function test_suggests_by_substring_containment(): void {
		$out = $this->generator()->suggest_types( 'head' );
		$this->assertSame( 'heading', $out[0], '"head" is contained in "heading".' );
	}

	public function test_returns_empty_when_no_widgets_registered(): void {
		$GLOBALS['_widget_types'] = array();
		$this->assertSame( array(), $this->generator()->suggest_types( 'heading' ) );
	}

	public function test_respects_the_limit(): void {
		$out = $this->generator()->suggest_types( 'x', 2 );
		$this->assertLessThanOrEqual( 2, count( $out ) );
	}

	public function test_generate_unknown_type_returns_suggestions_in_error_data(): void {
		$result = $this->generator()->generate( 'headline' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'widget_not_found', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'headline', $data['requested'] );
		$this->assertContains( 'heading', $data['suggestions'] );
		// The MCP adapter drops WP_Error data, so suggestions must be in the message.
		$this->assertStringContainsString( 'Did you mean', $result->get_error_message() );
		$this->assertStringContainsString( 'heading', $result->get_error_message() );
	}

	public function test_get_widget_schema_unknown_type_returns_suggestions(): void {
		// The primary discovery tool must also surface suggestions inline, so an
		// agent needn't make a second lookup (Codex R1 P2).
		$data    = $this->createStub( \Elementor_MCP_Data::class );
		$ability = new \Elementor_MCP_Query_Abilities( $data, $this->generator() );

		$result = $ability->execute_get_widget_schema( array( 'widget_type' => 'headline' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'widget_not_found', $result->get_error_code() );
		$this->assertContains( 'heading', $result->get_error_data()['suggestions'] );
	}

	public function test_add_widget_invalid_type_returns_suggestions(): void {
		$data      = $this->createStub( \Elementor_MCP_Data::class );
		$factory   = $this->createStub( \Elementor_MCP_Element_Factory::class );
		$validator = $this->createStub( \Elementor_MCP_Settings_Validator::class );
		$ability   = new \Elementor_MCP_Widget_Abilities( $data, $factory, $this->generator(), $validator );

		$result = $ability->execute_add_widget(
			array(
				'post_id'     => 1,
				'parent_id'   => 'container-1',
				'widget_type' => 'headline', // not registered → invalid
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_widget_type', $result->get_error_code() );
		$err = $result->get_error_data();
		$this->assertContains( 'heading', $err['suggestions'] );
		$this->assertArrayHasKey( 'schema_hint', $err );
		$this->assertStringContainsString( 'Did you mean', $result->get_error_message() );
	}
}
