<?php
/**
 * Unit — Elementor_MCP_Collateral, the pure differ behind the collateral
 * verdict (P5.1, elementor-mcp#67). Three trees in, one report out; no
 * WordPress. Each fixture pins one thing the differ must or must not say.
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

class CollateralTest extends TestCase {

	/** A page: one container holding two headings. */
	private function page(): array {
		return array(
			array(
				'id'       => 'c1',
				'elType'   => 'container',
				'settings' => array( 'gap' => 10 ),
				'elements' => array(
					array( 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'One', 'size' => 'large' ), 'elements' => array() ),
					array( 'id' => 'h2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Two' ), 'elements' => array() ),
				),
			),
		);
	}

	/** Return $tree with $mutate applied to the node whose id is $id (payload only). */
	private function with( array $tree, string $id, callable $mutate ): array {
		foreach ( $tree as &$el ) {
			if ( isset( $el['id'] ) && $el['id'] === $id ) {
				$el = $mutate( $el );
			}
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = $this->with( $el['elements'], $id, $mutate );
			}
		}
		return $tree;
	}

	public function test_a_targeted_change_that_persisted_as_asked_has_no_findings(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested );

		$this->assertTrue( $report['comparable'] );
		$this->assertSame( array( 'h1' ), $report['targets'] );
		$this->assertSame( 2, $report['checked'], 'c1 and h2 were checked; h1 is the target.' );
		$this->assertSame( array(), $report['collateral'] );
		$this->assertSame( array(), $report['not_landed'] );
		$this->assertFalse( \Elementor_MCP_Collateral::has_findings( $report ) );
	}

	public function test_changing_a_child_does_not_make_its_parent_a_target(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h2', static function ( $el ) { $el['settings']['title'] = 'Dos'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested );

		$this->assertSame( array( 'h2' ), $report['targets'], 'The container payload (sans children) is unchanged, so it is not a target.' );
	}

	public function test_an_untargeted_node_whose_settings_changed_is_collateral(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		// The pipeline also rewrote h2, which nobody asked for.
		$persisted = $this->with( $requested, 'h2', static function ( $el ) { $el['settings']['title'] = ''; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( array( array( 'id' => 'h2', 'kind' => 'changed', 'type' => 'heading', 'path' => '0.1' ) ), $report['collateral'] );
		$this->assertTrue( \Elementor_MCP_Collateral::has_findings( $report ) );
	}

	public function test_an_untargeted_node_that_vanished_is_collateral(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$persisted = $requested;
		array_pop( $persisted[0]['elements'] ); // h2 gone
		$report = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( 'vanished', $report['collateral'][0]['kind'] );
		$this->assertSame( 'h2', $report['collateral'][0]['id'] );
	}

	public function test_an_untargeted_node_that_was_retyped_is_collateral(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$persisted = $this->with( $requested, 'h2', static function ( $el ) { $el['widgetType'] = 'text-editor'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( 'retyped', $report['collateral'][0]['kind'] );
		$this->assertSame( 'heading→text-editor', $report['collateral'][0]['type'] );
	}

	public function test_a_node_the_tool_deleted_is_a_target_not_collateral(): void {
		$before    = $this->page();
		$requested = $before;
		array_pop( $requested[0]['elements'] ); // the tool removed h2
		$report = \Elementor_MCP_Collateral::report( $before, $requested, $requested );

		$this->assertSame( array( 'h2' ), $report['targets'] );
		$this->assertSame( array(), $report['collateral'] );
		$this->assertSame( array(), $report['not_landed'], 'A deleted target has nothing to land.' );
	}

	public function test_a_node_the_tool_added_is_a_target_and_a_persisted_extra_is_gained(): void {
		$before    = $this->page();
		$requested = $before;
		$requested[0]['elements'][] = array( 'id' => 'h3', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Three' ), 'elements' => array() );
		$persisted = $requested;
		$persisted[] = array( 'id' => 'wrap9', 'elType' => 'container', 'settings' => array(), 'elements' => array() );
		$report = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( array( 'h3' ), $report['targets'] );
		$this->assertSame( array( array( 'id' => 'wrap9', 'type' => 'container', 'path' => '1' ) ), $report['gained'] );
		$this->assertFalse( \Elementor_MCP_Collateral::has_findings( $report ), 'Gained is logged, never a finding.' );
	}

	public function test_a_requested_setting_absent_after_the_save_is_not_landed(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['custom_css'] = 'h1{color:red}'; return $el; } );
		$persisted = $before; // Elementor dropped the key entirely
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( array( array( 'id' => 'h1', 'missing' => array( 'custom_css' ) ) ), $report['not_landed'] );
		$this->assertTrue( \Elementor_MCP_Collateral::has_findings( $report ) );
	}

	public function test_an_alias_the_coercion_renamed_is_landed_not_missing(): void {
		// coerce_tree()'s apply_prop_aliases() renames an advertised alias onto
		// its canonical prop and REMOVES the alias key. Reading the pre-coercion
		// keys would call every aliased write a dropped setting — and in refuse
		// mode revert it (Codex round-1 P2).
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['content'] = 'Body'; return $el; } );
		$coerced   = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['text'] = 'Body'; return $el; } );
		$persisted = $coerced;

		$report = \Elementor_MCP_Collateral::report( $before, $requested, $persisted, $coerced );

		$this->assertSame( array( 'h1' ), $report['targets'], 'The target is still derived from the pre-coercion tree.' );
		$this->assertSame( array(), $report['not_landed'] );
		$this->assertFalse( \Elementor_MCP_Collateral::has_findings( $report ) );
	}

	public function test_a_coercion_repair_on_an_untargeted_node_is_still_collateral(): void {
		// The mirror of the case above, and why targets stay on the pre-coercion
		// tree: the coercion rewrote h2, which the tool never touched. Judged
		// against the coerced tree it would vanish into the targets; judged
		// against what the tool asked for, it is reported.
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$coerced   = $this->with( $requested, 'h2', static function ( $el ) { $el['settings']['title'] = array( '$$type' => 'string', 'value' => 'Two' ); return $el; } );
		$persisted = $coerced;

		$report = \Elementor_MCP_Collateral::report( $before, $requested, $persisted, $coerced );

		$this->assertSame( array( 'h1' ), $report['targets'] );
		$this->assertSame( array( array( 'id' => 'h2', 'kind' => 'changed', 'type' => 'heading', 'path' => '0.1' ) ), $report['collateral'] );
	}

	public function test_an_omitted_coerced_tree_falls_back_to_the_requested_one(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['custom_css'] = 'h1{}'; return $el; } );

		$this->assertSame(
			\Elementor_MCP_Collateral::report( $before, $requested, $before, $requested ),
			\Elementor_MCP_Collateral::report( $before, $requested, $before ),
			'No coercion ran → the requested tree answers both questions.'
		);
	}

	public function test_a_requested_setting_rewritten_by_elementor_is_landed(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'raw'; return $el; } );
		$persisted = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = array( '$$type' => 'string', 'value' => 'raw' ); return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( array(), $report['not_landed'], 'Only ABSENCE counts; a canonicalised value is Elementor doing its job.' );
	}

	public function test_key_order_is_not_a_change(): void {
		$before    = $this->page();
		$persisted = $this->with( $before, 'h1', static function ( $el ) { $el['settings'] = array( 'size' => 'large', 'title' => 'One' ); return $el; } );
		$requested = $this->with( $before, 'h2', static function ( $el ) { $el['settings']['title'] = 'Dos'; return $el; } );
		$persisted = $this->with( $persisted, 'h2', static function ( $el ) { $el['settings']['title'] = 'Dos'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( array(), $report['collateral'], 'h1 only had its keys reordered.' );
	}

	public function test_a_duplicated_id_is_dropped_from_the_comparison(): void {
		$before = $this->page();
		$before[0]['elements'][1]['id'] = 'h1'; // two nodes claim h1
		$requested = $before;
		$persisted = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'X'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );

		$this->assertSame( array(), $report['collateral'], 'An ambiguous id cannot accuse anything.' );
		$this->assertSame( 1, $report['checked'], 'Only the container was comparable.' );
	}

	public function test_an_unreadable_tree_makes_the_report_not_comparable(): void {
		$report = \Elementor_MCP_Collateral::report( null, $this->page(), $this->page() );

		$this->assertFalse( $report['comparable'] );
		$this->assertFalse( \Elementor_MCP_Collateral::has_findings( $report ) );
	}

	public function test_summary_names_the_nodes(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; $el['settings']['nope'] = 1; return $el; } );
		$persisted = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$persisted = $this->with( $persisted, 'h2', static function ( $el ) { $el['settings']['title'] = ''; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $persisted );
		$summary   = \Elementor_MCP_Collateral::summarize( $report );

		$this->assertStringContainsString( 'heading h2 (changed)', $summary );
		$this->assertStringContainsString( 'h1: nope', $summary );
	}
}
