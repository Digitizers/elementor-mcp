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
		$tree = array( array( 'id' => 's1', 'elType' => 'section', 'settings' => array(), 'elements' => array() ) );

		$data->update_element_settings( $tree, 's1', array( 'editor_settings' => array( 'title' => 'H', 'other' => 1 ) ) );

		$this->assertSame( 'H', $tree[0]['settings']['_title'] );
		$this->assertSame( array( 'other' => 1 ), $tree[0]['settings']['editor_settings'], 'only title is remapped; the rest stays where the agent put it' );
	}

	/** @test */
	public function test_an_explicit_canonical_title_wins_over_the_alias(): void {
		$data = new \Elementor_MCP_Data();
		$tree = array( array( 'id' => 'c1', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) );

		$data->update_element_settings( $tree, 'c1', array( '_title' => 'Canonical', 'editor_settings' => array( 'title' => 'Stale alias' ) ) );

		$this->assertSame( 'Canonical', $tree[0]['settings']['_title'], 'the canonical key wins, as in every other alias normalizer here' );
		$this->assertArrayNotHasKey( 'editor_settings', $tree[0]['settings'], 'the alias is still removed' );
	}

	/**
	 * A label an older build wrote to the dead nested key must not survive
	 * beside the new `_title` after a top-level merge (Codex round-10 P2).
	 * @test
	 */
	public function test_a_stale_nested_title_stored_by_an_older_build_is_dropped_when_the_label_is_set(): void {
		$data = new \Elementor_MCP_Data();
		$tree = array( array( 'id' => 'c1', 'elType' => 'container', 'settings' => array( 'editor_settings' => array( 'title' => 'Old', 'other' => 1 ) ), 'elements' => array() ) );

		$data->update_element_settings( $tree, 'c1', array( 'editor_settings' => array( 'title' => 'New' ) ) );
		$this->assertSame( 'New', $tree[0]['settings']['_title'] );
		$this->assertSame( array( 'other' => 1 ), $tree[0]['settings']['editor_settings'], 'the stale title is gone, the other stored member is kept' );

		$tree2 = array( array( 'id' => 'c2', 'elType' => 'section', 'settings' => array( 'editor_settings' => array( 'title' => 'Old' ) ), 'elements' => array() ) );
		$data->update_element_settings( $tree2, 'c2', array( '_title' => 'Explicit' ) );
		$this->assertSame( 'Explicit', $tree2[0]['settings']['_title'] );
		$this->assertArrayNotHasKey( 'editor_settings', $tree2[0]['settings'], 'an explicit _title clears the dead key too, and an emptied editor_settings is dropped' );

		$tree3 = array( array( 'id' => 'c3', 'elType' => 'container', 'settings' => array( 'editor_settings' => array( 'title' => 'Old' ) ), 'elements' => array() ) );
		$data->update_element_settings( $tree3, 'c3', array( 'flex_direction' => 'row' ) );
		$this->assertSame( array( 'title' => 'Old' ), $tree3[0]['settings']['editor_settings'], 'an update that does not touch the label leaves the stored settings alone' );
	}

	/**
	 * Creation goes through the same normalizer as update: add-container and
	 * build-page build classic containers with the factory, and the legacy
	 * section/column creators too (Codex round-5 P2 on #74).
	 * @test
	 */
	public function test_creating_a_classic_layout_element_normalizes_the_navigator_label_too(): void {
		$factory = $this->make_factory();

		$c = $factory->create_container( array( 'editor_settings' => array( 'title' => 'Hero' ), 'justify_content' => 'center' ) );
		$this->assertSame( 'Hero', $c['settings']['_title'] );
		$this->assertArrayNotHasKey( 'editor_settings', $c['settings'] );
		$this->assertSame( 'center', $c['settings']['flex_justify_content'], 'the flex shorthand pass still runs' );

		$s = $factory->create_section( array( 'editor_settings' => array( 'title' => 'Sec' ) ) );
		$this->assertSame( 'Sec', $s['settings']['_title'] );
		$this->assertArrayNotHasKey( 'editor_settings', $s['settings'] );

		$col = $factory->create_column( array( '_title' => 'Keep', 'editor_settings' => array( 'title' => 'Alias', 'other' => 1 ) ) );
		$this->assertSame( 'Keep', $col['settings']['_title'], 'canonical wins at creation too' );
		$this->assertSame( array( 'other' => 1 ), $col['settings']['editor_settings'] );
		$this->assertSame( 100, $col['settings']['_column_size'], 'defaults untouched' );
	}

	/**
	 * On a classic WIDGET `editor_settings` can be an ordinary compound control
	 * name (this repo's widget builder registers controls with arbitrary names),
	 * so nothing is lifted out of it — the same reason the atomic hoist leaves
	 * classic widgets alone (Codex round-1 P2 on #74).
	 * @test
	 */
	public function test_a_classic_widget_keeps_an_editor_settings_control_intact(): void {
		$data = new \Elementor_MCP_Data();
		$tree = array( array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'my-custom', 'settings' => array(), 'elements' => array() ) );

		$data->update_element_settings( $tree, 'w1', array( 'editor_settings' => array( 'title' => 'Control value', 'size' => 12 ) ) );

		$this->assertSame( array( 'title' => 'Control value', 'size' => 12 ), $tree[0]['settings']['editor_settings'], 'the control payload is saved exactly as sent' );
		$this->assertArrayNotHasKey( '_title', $tree[0]['settings'] );
		$this->assertArrayNotHasKey( 'editor_settings', $tree[0], 'and nothing is hoisted either' );
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
	// 2. Partial classic dimensions: fill from the saved value, or do not write
	//    (1.41.0, EMCP #151 — until 1.40.x: warned and left exactly as sent,
	//    which told the agent and still lost the CSS rule)
	// ---------------------------------------------------------------------

	private const FULL = array( 'top' => '10', 'right' => '20', 'bottom' => '30', 'left' => '40', 'unit' => 'px', 'isLinked' => false );

	/** @test */
	public function test_an_update_fills_the_blank_sides_from_the_saved_value(): void {
		$sent = array( 'padding' => array( 'top' => '5', 'right' => '', 'bottom' => '5', 'unit' => 'px' ) );
		$g    = \Elementor_MCP_Element_Factory::guard_settings( $sent, array( 'padding' => self::FULL ) );

		$this->assertSame(
			array( 'top' => '5', 'right' => '20', 'bottom' => '5', 'unit' => 'px', 'left' => '40', 'isLinked' => false ),
			$g['settings']['padding'],
			'the sides that were sent win; the blank ones come from the saved value'
		);
		$this->assertCount( 1, $g['warnings'] );
		$this->assertStringContainsString( 'padding had blank sides (right, left): filled from the saved value.', $g['warnings'][0] );
	}

	/** @test */
	public function test_filling_unequal_sides_unlinks_them(): void {
		$saved = array( 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'unit' => 'px', 'isLinked' => true );
		$g     = \Elementor_MCP_Element_Factory::guard_settings( array( 'margin' => array( 'top' => '0', 'unit' => 'px' ) ), array( 'margin' => $saved ) );
		$this->assertSame( '0', $g['settings']['margin']['top'] );
		$this->assertSame( '8', $g['settings']['margin']['left'] );
		$this->assertFalse( $g['settings']['margin']['isLinked'], 'four equal sides stopped being equal' );
	}

	/** @test */
	public function test_an_update_with_nothing_usable_to_fill_from_does_not_write_the_key(): void {
		$sent = array( 'padding' => array( 'top' => '5', 'unit' => 'px' ), 'title' => 'kept' );

		$none = \Elementor_MCP_Element_Factory::guard_settings( $sent, array() );
		$this->assertSame( array( 'title' => 'kept' ), $none['settings'], 'the partial value is not written; everything else is' );
		$this->assertStringContainsString( 'no saved value to fill from; it was not written', $none['warnings'][0] );

		$partial_saved = \Elementor_MCP_Element_Factory::guard_settings( $sent, array( 'padding' => array( 'top' => '1', 'right' => '', 'bottom' => '1', 'left' => '', 'unit' => 'px' ) ) );
		$this->assertArrayNotHasKey( 'padding', $partial_saved['settings'] );
		$this->assertStringContainsString( 'the saved value has blank sides too', $partial_saved['warnings'][0] );
		$this->assertStringContainsString( 'the saved value is unchanged', $partial_saved['warnings'][0] );
	}

	/** Filling 5 (em) around 20 (px) would change what the saved sides mean. @test */
	public function test_a_different_unit_is_never_filled_across(): void {
		$g = \Elementor_MCP_Element_Factory::guard_settings( array( 'padding' => array( 'top' => '5', 'unit' => 'em' ) ), array( 'padding' => self::FULL ) );
		$this->assertArrayNotHasKey( 'padding', $g['settings'] );
		$this->assertStringContainsString( 'a different unit from the saved value (em vs px)', $g['warnings'][0] );
		// A missing unit is Elementor's default, px — the same unit as a saved px value.
		$px = \Elementor_MCP_Element_Factory::guard_settings( array( 'padding' => array( 'top' => '5' ) ), array( 'padding' => self::FULL ) );
		$this->assertSame( '20', $px['settings']['padding']['right'] );
		$this->assertSame( 'px', $px['settings']['padding']['unit'] );
	}

	/** @test */
	public function test_on_creation_a_partial_dimension_is_not_written_and_says_why(): void {
		$g = \Elementor_MCP_Element_Factory::guard_settings( array( 'padding' => array( 'top' => '10', 'right' => '', 'bottom' => '10', 'unit' => 'px' ), 'flex_direction' => 'row' ) );
		$this->assertSame( array( 'flex_direction' => 'row' ), $g['settings'] );
		$this->assertCount( 1, $g['warnings'] );
		$this->assertStringContainsString( 'padding had blank sides (right, left) and was not written', $g['warnings'][0] );
		$this->assertStringContainsString( 'Supply all four sides (use 0 where intended)', $g['warnings'][0] );
	}

	/** @test */
	public function test_complete_or_fully_blank_dimensions_are_written_as_sent_and_do_not_warn(): void {
		$full  = array( 'top' => '1', 'right' => '2', 'bottom' => '3', 'left' => '4', 'unit' => 'px' );
		$blank = array( 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' );
		$zero  = array( 'top' => 0, 'right' => '0', 'bottom' => 0, 'left' => 0, 'unit' => 'px' );
		$g     = \Elementor_MCP_Element_Factory::guard_settings( array( 'margin' => $full, 'border_radius' => $blank, 'padding' => $zero ), array() );
		$this->assertSame( array(), $g['warnings'] );
		$this->assertSame( $full, $g['settings']['margin'] );
		$this->assertSame( $blank, $g['settings']['border_radius'], 'every side blank means "unset" and is written as sent' );
		$this->assertSame( $zero, $g['settings']['padding'], '0 and "0" are values, not blanks' );
	}

	/** A complete value with no unit gets px — Elementor emits no valid CSS for a blank unit. @test */
	public function test_a_complete_value_only_has_its_blank_unit_resolved(): void {
		$g = \Elementor_MCP_Element_Factory::guard_settings( array( 'margin' => array( 'top' => '1', 'right' => '2', 'bottom' => '3', 'left' => '4' ) ), array() );
		$this->assertSame( 'px', $g['settings']['margin']['unit'] );
		$this->assertSame( array(), $g['warnings'] );
	}

	/**
	 * Only Elementor's own responsive suffixes count as a dimension control.
	 * The universal update tool reaches custom widgets with arbitrary control
	 * names, and `padding_config` with a `top` member is not a dimension —
	 * guarding it would drop a valid compound value (Codex round-4 P2 on #74).
	 * @test
	 */
	public function test_responsive_and_underscored_variants_are_guarded_but_typed_props_and_lookalike_controls_are_not(): void {
		$partial = array( 'top' => '1', 'right' => '', 'bottom' => '', 'left' => '' );
		$sent    = array(
			'padding_mobile'             => $partial,
			'_margin_tablet'             => $partial,
			'border_radius_tablet_extra' => $partial,
			'border_width'               => array( '$$type' => 'dimensions', 'value' => array( 'top' => '1' ) ),
			'padding_config'             => array( 'top' => 'x', 'mode' => 'auto' ),
			'margin_something_else'      => $partial,
			'gap'                        => array( 'size' => 10, 'unit' => 'px', 'top' => '' ),
			'flex_direction'             => 'row',
			'padding_scalar'             => '10px',
		);
		$g = \Elementor_MCP_Element_Factory::guard_settings( $sent, array() );

		$this->assertCount( 3, $g['warnings'] );
		$this->assertStringContainsString( 'padding_mobile', $g['warnings'][0] );
		$this->assertStringContainsString( '_margin_tablet', $g['warnings'][1] );
		$this->assertStringContainsString( 'border_radius_tablet_extra', $g['warnings'][2] );
		foreach ( array( 'padding_mobile', '_margin_tablet', 'border_radius_tablet_extra' ) as $dropped ) {
			$this->assertArrayNotHasKey( $dropped, $g['settings'] );
		}
		foreach ( array( 'border_width', 'padding_config', 'margin_something_else', 'gap', 'flex_direction', 'padding_scalar' ) as $kept ) {
			$this->assertSame( $sent[ $kept ], $g['settings'][ $kept ], "$kept is not a classic box dimension and passes through untouched" );
		}
	}

	/** When Elementor can be asked, its control decides — not the key's name. @test */
	public function test_the_live_control_decides_what_is_a_dimension_and_which_sides_it_needs(): void {
		$GLOBALS['_widget_types'] = array(
			'button' => new class {
				public function get_controls( $id = null ) {
					$controls = array(
						'text_padding' => array( 'type' => 'dimensions' ),
						'row_margin'   => array( 'type' => 'dimensions', 'allowed_dimensions' => 'vertical' ),
						'padding'      => array( 'type' => 'text' ),
					);
					return null === $id ? $controls : ( $controls[ $id ] ?? null );
				}
			},
		);
		try {
			$context = array( 'elType' => 'widget', 'widgetType' => 'button' );
			$partial = array( 'top' => '4', 'right' => '', 'bottom' => '4', 'left' => '', 'unit' => 'px' );

			$g = \Elementor_MCP_Element_Factory::guard_settings( array( 'text_padding' => $partial, 'text_padding_tablet' => $partial, 'row_margin' => $partial, 'padding' => $partial ), array(), $context );

			$this->assertArrayNotHasKey( 'text_padding', $g['settings'], 'a dimensions control by a name the key list does not know' );
			$this->assertArrayNotHasKey( 'text_padding_tablet', $g['settings'], 'a responsive key falls back to its base control' );
			$this->assertSame( $partial, $g['settings']['row_margin'], 'vertical-only: top and bottom are all it needs, and both are there' );
			$this->assertSame( $partial, $g['settings']['padding'], 'a control of that name that is NOT a dimensions control is left alone' );
			$this->assertCount( 2, $g['warnings'] );
		} finally {
			unset( $GLOBALS['_widget_types'] );
		}
	}

	// ---------------------------------------------------------------------
	// 3. Grid's two-row default: warn at creation only
	// ---------------------------------------------------------------------

	/** @test */
	public function test_a_grid_created_without_rows_warns_about_the_two_row_default(): void {
		$g = \Elementor_MCP_Element_Factory::guard_settings( array( 'container_type' => 'grid', 'grid_columns_grid' => array( 'unit' => 'fr', 'size' => 3 ) ) );
		$this->assertCount( 1, $g['warnings'] );
		$this->assertStringContainsString( 'grid_rows_grid', $g['warnings'][0] );
		$this->assertStringContainsString( '"size":1', $g['warnings'][0] );
	}

	/** @test */
	public function test_a_grid_with_rows_or_a_flex_container_or_an_update_does_not_warn(): void {
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::guard_settings( array( 'container_type' => 'grid', 'grid_rows_grid' => array( 'unit' => 'fr', 'size' => 1 ) ) )['warnings'] );
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::guard_settings( array( 'container_type' => 'flex' ) )['warnings'] );
		$this->assertSame( array(), \Elementor_MCP_Element_Factory::guard_settings( array( 'container_type' => 'grid' ), array() )['warnings'], 'an update never re-warns about a default the element already has' );
	}

	// ---------------------------------------------------------------------
	// The settings_warnings channel on the four layout write tools
	// (its own key: `warnings` is the governance wrapper's, Codex round-2 P1)
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
		$this->assertSame( array(), $clean['settings_warnings'], 'the key is always present so a schema consumer can rely on it' );

		$grid = $ability->execute_add_container( array( 'post_id' => 7, 'settings' => array( 'container_type' => 'grid', 'padding' => array( 'top' => '4' ) ) ) );
		$this->assertCount( 2, $grid['settings_warnings'] );
		$this->assertStringContainsString( 'padding', $grid['settings_warnings'][0] );
		$this->assertStringContainsString( 'grid_rows_grid', $grid['settings_warnings'][1] );
		$this->assertArrayHasKey( 'element_id', $grid, 'the write still succeeds — a warning is not a refusal' );
	}

	/** @test */
	public function test_update_container_and_update_element_carry_warnings(): void {
		$ability = $this->ability_with_tree( $this->container_tree() );
		$partial = array( 'margin' => array( 'top' => '0', 'bottom' => '0' ) );

		$uc = $ability->execute_update_container( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => $partial ) );
		$this->assertTrue( $uc['success'] );
		$this->assertCount( 1, $uc['settings_warnings'] );

		$ue = $ability->execute_update_element( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => $partial ) );
		$this->assertTrue( $ue['success'] );
		$this->assertCount( 1, $ue['settings_warnings'] );

		$ok = $ability->execute_update_element( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => array( 'container_type' => 'grid' ) ) );
		$this->assertSame( array(), $ok['settings_warnings'], 'an update never warns about the grid default' );
	}

	/** @test */
	public function test_batch_update_prefixes_each_warning_with_its_element_id(): void {
		$ability = $this->ability_with_tree( $this->container_tree() );
		$out     = $ability->execute_batch_update( array( 'post_id' => 7, 'operations' => array(
			array( 'element_id' => 'c1', 'settings' => array( 'padding' => array( 'left' => '1' ) ) ),
			array( 'element_id' => 'c1', 'settings' => array( 'flex_direction' => 'row' ) ),
		) ) );
		$this->assertTrue( $out['success'] );
		$this->assertCount( 1, $out['settings_warnings'] );
		$this->assertStringStartsWith( 'c1: padding', $out['settings_warnings'][0] );
	}

	/**
	 * Later operations on the same element override earlier ones before the
	 * single save, so the warnings are judged on the merged payload: a
	 * partial dimension a later operation completes does not warn, and a
	 * complete one a later operation makes partial does (Codex round-7 P2).
	 * @test
	 */
	public function test_batch_warnings_are_judged_on_the_merged_per_element_payload(): void {
		$ability  = $this->ability_with_tree( $this->container_tree() );
		$partial  = array( 'padding' => array( 'left' => '1' ) );
		$complete = array( 'padding' => array( 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1' ) );

		$fixed = $ability->execute_batch_update( array( 'post_id' => 7, 'operations' => array(
			array( 'element_id' => 'c1', 'settings' => $partial ),
			array( 'element_id' => 'c1', 'settings' => $complete ),
		) ) );
		$this->assertSame( array(), $fixed['settings_warnings'], 'the later, complete value is what persists' );

		$broken = $ability->execute_batch_update( array( 'post_id' => 7, 'operations' => array(
			array( 'element_id' => 'c1', 'settings' => $complete ),
			array( 'element_id' => 'c1', 'settings' => $partial ),
		) ) );
		$this->assertCount( 1, $broken['settings_warnings'], 'the later, partial value is what persists' );
	}

	/**
	 * The dedicated widget path carries the same channel as update-element —
	 * the same write must not warn through one tool and stay silent through
	 * the other (Codex round-7 P2 on #74).
	 * @test
	 */
	public function test_update_widget_carries_settings_warnings_too(): void {
		$tree = array( array( 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(), 'elements' => array() ) );
		$data = $this->createStub( \Elementor_MCP_Data::class );
		$data->method( 'get_page_data' )->willReturn( $tree );
		$data->method( 'save_page_data' )->willReturn( true );
		$data->method( 'find_element_by_id' )->willReturn( $tree[0] );
		$data->method( 'update_element_settings' )->willReturn( true );
		$schema    = $this->createStub( \Elementor_MCP_Schema_Generator::class );
		$validator = $this->createStub( \Elementor_MCP_Settings_Validator::class );
		$validator->method( 'validate' )->willReturn( true );
		$this->allow_all_caps();
		$ability = new \Elementor_MCP_Widget_Abilities( $data, $this->make_factory(), $schema, $validator );

		$out = $ability->execute_update_widget( array( 'post_id' => 7, 'element_id' => 'w1', 'settings' => array( 'margin' => array( 'top' => '0' ) ) ) );
		$this->assertTrue( $out['success'] );
		$this->assertCount( 1, $out['settings_warnings'] );

		$GLOBALS['_registered_abilities'] = array();
		$ability->register();
		$this->assertSame( array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), $GLOBALS['_registered_abilities']['elementor-mcp/update-widget']['output_schema']['properties']['settings_warnings'] );
	}

	/**
	 * Creation paths warn like the update paths: add-widget and build-page
	 * (whose containers and widgets come straight from the factory), so the
	 * first write is never the silent one (Codex round-9 P2 on #74).
	 * @test
	 */
	public function test_add_widget_and_build_page_carry_settings_warnings_too(): void {
		$data = $this->createStub( \Elementor_MCP_Data::class );
		$data->method( 'get_page_data' )->willReturn( $this->container_tree() );
		$data->method( 'save_page_data' )->willReturn( true );
		$data->method( 'save_page_settings' )->willReturn( true );
		$data->method( 'insert_element' )->willReturn( true );
		$schema    = $this->createStub( \Elementor_MCP_Schema_Generator::class );
		$validator = $this->createStub( \Elementor_MCP_Settings_Validator::class );
		$validator->method( 'validate' )->willReturn( true );
		$this->allow_all_caps();

		$widgets = new \Elementor_MCP_Widget_Abilities( $data, $this->make_factory(), $schema, $validator );
		$GLOBALS['_widget_types'] = array( 'heading' => new \stdClass() ); // the Plugin stub's registry: `heading` exists
		$out     = $widgets->execute_add_widget( array( 'post_id' => 7, 'parent_id' => 'c1', 'widget_type' => 'heading', 'settings' => array( 'title' => 'x', '_padding' => array( 'top' => '10' ) ) ) );
		$this->assertArrayHasKey( 'element_id', $out );
		$this->assertCount( 1, $out['settings_warnings'] );
		$this->assertStringContainsString( '_padding', $out['settings_warnings'][0] );

		$composite = new \Elementor_MCP_Composite_Abilities( $data, $this->make_factory() );
		$page      = $composite->execute_build_page( array( 'title' => 'T', 'structure' => array(
			array( 'type' => 'container', 'settings' => array( 'container_type' => 'grid' ), 'children' => array(
				array( 'type' => 'widget', 'widget_type' => 'heading', 'settings' => array( 'title' => 'x', 'margin' => array( 'top' => '0' ) ) ),
			) ),
		) ) );
		$this->assertIsArray( $page );
		$this->assertCount( 2, $page['settings_warnings'], 'the grid default and the widget\'s partial margin, each prefixed with its created id' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+: /', $page['settings_warnings'][0] );
		$this->assertStringContainsString( 'margin', $page['settings_warnings'][0] . $page['settings_warnings'][1] );
		$this->assertStringContainsString( 'grid_rows_grid', $page['settings_warnings'][0] . $page['settings_warnings'][1] );

		$clean = $composite->execute_build_page( array( 'title' => 'T', 'structure' => array( array( 'type' => 'container', 'settings' => array( 'container_type' => 'flex' ) ) ) ) );
		$this->assertSame( array(), $clean['settings_warnings'] );

		$GLOBALS['_registered_abilities'] = array();
		$widgets->register();
		$composite->register();
		foreach ( array( 'elementor-mcp/add-widget', 'elementor-mcp/build-page', 'elementor-mcp/add-heading' ) as $name ) {
			$this->assertSame( array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), $GLOBALS['_registered_abilities'][ $name ]['output_schema']['properties']['settings_warnings'], $name . ' (a convenience tool delegates to add-widget and must declare the channel too)' );
		}
		unset( $GLOBALS['_widget_types'] );
	}

	/**
	 * Atomic elements have flat scalar spacing, not {top,right,bottom,left}
	 * controls — a classic-shaped partial object is not a partial dimension
	 * there and the "supply all four sides" advice would not apply, so the
	 * classic check is skipped for them everywhere (Codex round-12 P2).
	 * @test
	 */
	public function test_atomic_elements_never_get_the_classic_dimension_warning(): void {
		$atomic_tree = array( array( 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array(), 'styles' => array(), 'editor_settings' => array(), 'elements' => array() ) );
		$ability     = $this->ability_with_tree( $atomic_tree );
		$partial     = array( 'padding' => array( 'top' => '1' ) );

		$ue = $ability->execute_update_element( array( 'post_id' => 7, 'element_id' => 'a1', 'settings' => $partial ) );
		$this->assertSame( array(), $ue['settings_warnings'] );

		$b = $ability->execute_batch_update( array( 'post_id' => 7, 'operations' => array( array( 'element_id' => 'a1', 'settings' => $partial ) ) ) );
		$this->assertSame( array(), $b['settings_warnings'] );

		$data = $this->createStub( \Elementor_MCP_Data::class );
		$data->method( 'save_page_data' )->willReturn( true );
		$data->method( 'save_page_settings' )->willReturn( true );
		$composite = new \Elementor_MCP_Composite_Abilities( $data, $this->make_factory() );
		$page      = $composite->execute_build_page( array( 'title' => 'T', 'structure' => array(
			array( 'type' => 'container', 'settings' => array(), 'children' => array(
				array( 'type' => 'widget', 'widget_type' => 'e-heading', 'settings' => array( 'title' => 'x', 'padding' => array( 'top' => '1' ) ) ),
			) ),
		) ) );
		$this->assertIsArray( $page );
		$this->assertSame( array(), $page['settings_warnings'], 'the atomic widget in a built page is skipped too' );

		// add-widget accepts any registered type, an atomic one included.
		$data->method( 'get_page_data' )->willReturn( $this->container_tree() );
		$data->method( 'insert_element' )->willReturn( true );
		$schema    = $this->createStub( \Elementor_MCP_Schema_Generator::class );
		$validator = $this->createStub( \Elementor_MCP_Settings_Validator::class );
		$validator->method( 'validate' )->willReturn( true );
		$this->allow_all_caps();
		$widgets = new \Elementor_MCP_Widget_Abilities( $data, $this->make_factory(), $schema, $validator );
		$GLOBALS['_widget_types'] = array( 'e-heading' => new \stdClass() );
		$added = $widgets->execute_add_widget( array( 'post_id' => 7, 'parent_id' => 'c1', 'widget_type' => 'e-heading', 'settings' => array( 'title' => 'x', 'padding' => array( 'top' => '1' ) ) ) );
		unset( $GLOBALS['_widget_types'] );
		$this->assertArrayHasKey( 'element_id', $added );
		$this->assertSame( array(), $added['settings_warnings'], 'add-widget of an atomic type is skipped as well' );
	}

	/**
	 * The governance wrapper attaches its OWN `warnings` ({rule, reason}
	 * entries) to a governed outcome; the settings channel must not sit on
	 * that key or one of the two is lost (Codex round-2 P1 on #74).
	 * @test
	 */
	public function test_settings_warnings_survive_beside_the_governance_warnings_key(): void {
		$ability = $this->ability_with_tree( $this->container_tree() );
		$out     = $ability->execute_update_element( array( 'post_id' => 7, 'element_id' => 'c1', 'settings' => array( 'padding' => array( 'left' => '1' ) ) ) );
		$out['warnings'] = array( array( 'rule' => 'rule/site', 'reason' => 'freeze soon' ) ); // what with_run_warnings() does on a governed run
		$this->assertCount( 1, $out['settings_warnings'], 'still there' );
		$this->assertSame( 'rule/site', $out['warnings'][0]['rule'], 'and so is governance\'s' );
	}

	/** @test */
	public function test_the_four_output_schemas_declare_settings_warnings(): void {
		$ability = new \Elementor_MCP_Layout_Abilities( $this->createStub( \Elementor_MCP_Data::class ), $this->make_factory() );
		$GLOBALS['_registered_abilities'] = array();
		$ability->register();
		$names = array( 'elementor-mcp/add-container', 'elementor-mcp/update-container', 'elementor-mcp/update-element', 'elementor-mcp/batch-update' );
		foreach ( $names as $name ) {
			$args = $GLOBALS['_registered_abilities'][ $name ] ?? null;
			$this->assertNotNull( $args, $name . ' registered' );
			$this->assertSame( array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), $args['output_schema']['properties']['settings_warnings'], $name );
		}
		$this->assertStringContainsString( 'grid_rows_grid: {"unit":"fr","size":1}', $GLOBALS['_registered_abilities']['elementor-mcp/add-container']['description'] );
		$this->assertStringContainsString( 'all four sides', $GLOBALS['_registered_abilities']['elementor-mcp/add-container']['description'] );
	}
}
