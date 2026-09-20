<?php
/**
 * P6.2 — three Elementor correctness fixes mirrored from EMCP 3.16.x
 * (references reverification-2026-09-18 §2.2; EMCP issues #133, #134, #135).
 *
 * All three are the "silent no-op" class this repo's field reports keep
 * finding: a write that returns success and does not do what the agent
 * meant. The fixes are one data remap and one WARNINGS channel — "this
 * persisted, but it probably will not do what you meant" — surfaced on the
 * layout write tools. Values are never coerced: a blank side is left blank
 * to preserve inheritance.
 *
 * @package Elementor_MCP\Tests
 */
namespace Elementor_MCP\Tests;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

class ElementMirrorFixesTest extends Ability_Test_Case {

	// ---------------------------------------------------------------------
	// 1. Navigator label on a CLASSIC element: editor_settings.title → _title
	// ---------------------------------------------------------------------

	/** @test */
	public function test_a_classic_element_gets_its_navigator_label_as_settings_title(): void {
		$data = new \Elementor_MCP_Data();
		$tree = array( array( 'id' => 'c1', 'elType' => 'container', 'settings' => array( 'flex_direction' => 'row' ), 'elements' => array() ) );

		$this->assertTrue( $data->update_element_settings( $tree, 'c1', array( 'editor_settings' => array( 'title' => 'Hero' ) ) ) );

		$this->assertSame( 'Hero', $tree[0]['settings']['_title'], 'classic Elementor reads the Navigator name from settings._title' );
		$this->assertArrayNotHasKey( 'editor_settings', $tree[0]['settings'], 'the dead nested key is not left behind' );
		$this->assertArrayNotHasKey( 'editor_settings', $tree[0], 'and nothing is hoisted to the root of a classic element' );
		$this->assertSame( 'row', $tree[0]['settings']['flex_direction'], 'other settings survive' );
	}

	/** @test */
	public function test_a_classic_remap_keeps_other_editor_settings_keys_nested(): void {
		$data = new \Elementor_MCP_Data();
		$tree = array( array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array() ) );

		$data->update_element_settings( $tree, 'w1', array( 'editor_settings' => array( 'title' => 'H', 'other' => 1 ) ) );

		$this->assertSame( 'H', $tree[0]['settings']['_title'] );
		$this->assertSame( array( 'other' => 1 ), $tree[0]['settings']['editor_settings'], 'only title is remapped; the rest stays where the agent put it' );
	}

	/** @test */
	public function test_an_atomic_element_keeps_editor_settings_at_the_root_and_gets_no_title_remap(): void {
		$data = new \Elementor_MCP_Data();
		$tree = array( array( 'id' => 'a1', 'elType' => 'e-flexbox', 'settings' => array(), 'styles' => array(), 'editor_settings' => array(), 'elements' => array() ) );

		$data->update_element_settings( $tree, 'a1', array( 'editor_settings' => array( 'title' => 'Atomic' ) ) );

		$this->assertSame( 'Atomic', $tree[0]['editor_settings']['title'], 'atomic: hoisted to the root, as before' );
		$this->assertArrayNotHasKey( '_title', $tree[0]['settings'], 'no classic remap on an atomic element' );
	}

	// ---------------------------------------------------------------------
	// 2. Partial classic dimensions: warn, never coerce
	// ---------------------------------------------------------------------

	/** @test */
	public function test_partial_dimensions_warn_and_name_the_blank_sides(): void {
		$w = \Elementor_MCP_Element_Factory::settings_warnings( array( 'padding' => array( 'top' => '10', 'right' => '', 'bottom' => '10', 'unit' => 'px' ) ) );
		$this->assertCount( 1, $w );
		$this->assertStringContainsString( 'padding', $w[0] );
		$this->assertStringContainsString( 'right, left', $w[0] );
		$this->assertStringContainsString( 'left unchanged', $w[0] );
	}

	/** @test */
	public function test_complete_or_fully_blank_dimensions_do_not_warn(): void {
		$full  = array( 'top' => '1', 'right' => '2', 'bottom' => '3', 'left' => '4' );
		$blank = array( 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' );
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::settings_warnings( array( 'margin' => $full, 'border_radius' => $blank ) ) );
	}

	/** @test */
	public function test_responsive_and_underscored_variants_are_checked_but_typed_atomic_props_are_not(): void {
		$partial = array( 'top' => '1', 'right' => '', 'bottom' => '', 'left' => '' );
		$w = \Elementor_MCP_Element_Factory::settings_warnings( array(
			'padding_mobile'  => $partial,
			'_margin_tablet'  => $partial,
			'border_width'    => array( '$$type' => 'dimensions', 'value' => array( 'top' => '1' ) ),
			'flex_direction'  => 'row',
			'padding_scalar'  => '10px',
		) );
		$this->assertCount( 2, $w );
		$this->assertStringContainsString( 'padding_mobile', $w[0] );
		$this->assertStringContainsString( '_margin_tablet', $w[1] );
	}

	// ---------------------------------------------------------------------
	// 3. Grid's two-row default: warn at creation only
	// ---------------------------------------------------------------------

	/** @test */
	public function test_a_grid_created_without_rows_warns_about_the_two_row_default(): void {
		$w = \Elementor_MCP_Element_Factory::settings_warnings( array( 'container_type' => 'grid', 'grid_columns_grid' => array( 'unit' => 'fr', 'size' => 3 ) ), true );
		$this->assertCount( 1, $w );
		$this->assertStringContainsString( 'grid_rows_grid', $w[0] );
		$this->assertStringContainsString( '"size":1', $w[0] );
	}

	/** @test */
	public function test_a_grid_with_rows_or_a_flex_container_or_an_update_does_not_warn(): void {
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::settings_warnings( array( 'container_type' => 'grid', 'grid_rows_grid' => array( 'unit' => 'fr', 'size' => 1 ) ), true ) );
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::settings_warnings( array( 'container_type' => 'flex' ), true ) );
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::settings_warnings( array( 'container_type' => 'grid' ), false ), 'an update never re-warns about a default the element already has' );
	}

	// ---------------------------------------------------------------------
	// The warnings channel on the four layout write tools
	// ---------------------------------------------------------------------

	private function ability_with_tree( array $tree ): \Elementor_MCP_Layout_Abilities {
		$data = $this->createStub( \Elementor_MCP_Data::class );
		$data->method( 'get_page_data' )->willReturn( $tree );
		$data->method( 'save_page_data' )->willReturn( true );
		$data->method( 'find_element_by_id' )->willReturn( $tree[0] );
		$data->method( 'update_element_settings' )->willReturn( true );
		$data->method( 'insert_element' )->willReturn( true );
		$this->allow_all_caps();
		return new \Elementor_MCP_Layout_Abilities( $data, $this->make_factory() );
	}

	private function container_tree(): array {
		return array( array( 'id' => 'c1', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) );
	}

	/** @test */
	public function test_add_container_carries_warnings_and_an_empty_list_when_there_is_nothing_to_say(): void {
		$ability = $this->ability_with_tree( $this->container_tree() );

		$clean = $ability->execute_add_container( array( 'post_id' => 7, 'settings' => array( 'container_type' => 'flex' ) ) );
		$this->assertSame( array(), $clean['warnings'], 'the key is always present so a schema consumer can rely on it' );

		$grid = $ability->execute_add_container( array( 'post_id' => 7, 'settings' => array( 'container_type' => 'grid', 'padding' => array( 'top' => '4' ) ) ) );
		$this->assertCount( 2, $grid['warnings'] );
		$this->assertStringContainsString( 'padding', $grid['warnings'][0] );
		$this->assertStringContainsString( 'grid_rows_grid', $grid['warnings'][1] );
		$this->assertArrayHasKey( 'element_id', $grid, 'the write still succeeds — a warning is not a refusal' );
	}

	/** @test */
	public function test_update_container_and_update_element_carry_warnings(): void {
		$ability = $this->ability_with_tree( $this->container_tree() );
		$partial = array( 'margin' => array( 'top' => '0', 'bottom' => '0' ) );

		$uc = $ability->execute_update_container( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => $partial ) );
		$this->assertTrue( $uc['success'] );
		$this->assertCount( 1, $uc['warnings'] );

		$ue = $ability->execute_update_element( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => $partial ) );
		$this->assertTrue( $ue['success'] );
		$this->assertCount( 1, $ue['warnings'] );

		$ok = $ability->execute_update_element( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => array( 'container_type' => 'grid' ) ) );
		$this->assertSame( array(), $ok['warnings'], 'an update never warns about the grid default' );
	}

	/** @test */
	public function test_batch_update_prefixes_each_warning_with_its_element_id(): void {
		$ability = $this->ability_with_tree( $this->container_tree() );
		$out     = $ability->execute_batch_update( array( 'post_id' => 7, 'operations' => array(
			array( 'element_id' => 'c1', 'settings' => array( 'padding' => array( 'left' => '1' ) ) ),
			array( 'element_id' => 'c1', 'settings' => array( 'flex_direction' => 'row' ) ),
		) ) );
		$this->assertTrue( $out['success'] );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertStringStartsWith( 'c1: padding', $out['warnings'][0] );
	}

	/** @test */
	public function test_the_four_output_schemas_declare_warnings(): void {
		$ability = new \Elementor_MCP_Layout_Abilities( $this->createStub( \Elementor_MCP_Data::class ), $this->make_factory() );
		$GLOBALS['_registered_abilities'] = array();
		$ability->register();
		$names = array( 'elementor-mcp/add-container', 'elementor-mcp/update-container', 'elementor-mcp/update-element', 'elementor-mcp/batch-update' );
		foreach ( $names as $name ) {
			$args = $GLOBALS['_registered_abilities'][ $name ] ?? null;
			$this->assertNotNull( $args, $name . ' registered' );
			$this->assertSame( array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), $args['output_schema']['properties']['warnings'], $name );
		}
		$this->assertStringContainsString( 'grid_rows_grid: {"unit":"fr","size":1}', $GLOBALS['_registered_abilities']['elementor-mcp/add-container']['description'] );
		$this->assertStringContainsString( 'all four sides', $GLOBALS['_registered_abilities']['elementor-mcp/add-container']['description'] );
	}
}
