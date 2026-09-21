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

	// -------------------------------------------------------------------------
	// Declared intent (P5.4, 1.36.0): what the ability SAID it would touch,
	// against what the tool actually changed before→requested.
	// -------------------------------------------------------------------------

	/** A targeted declaration for the given ids. */
	private function targeted( string ...$ids ): array {
		return array( 'scope' => 'targeted', 'ids' => $ids );
	}

	public function test_the_declaration_keys_are_always_present(): void {
		$before = $this->page();
		$report = \Elementor_MCP_Collateral::report( $before, $before, $before );

		$this->assertSame( 'id', $report['compared_by'], 'Nodes are only ever matched by id; silence must not read as more.' );
		$this->assertSame( 'undeclared', $report['intent'] );
		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_not_comparable_report_still_carries_them(): void {
		$report = \Elementor_MCP_Collateral::report( null, $this->page(), $this->page(), null, $this->targeted( 'h1' ) );

		$this->assertFalse( $report['comparable'] );
		$this->assertSame( 'id', $report['compared_by'] );
		$this->assertSame( 'targeted', $report['intent'], 'What was declared is reported even when nothing could be checked.' );
		$this->assertSame( array(), $report['undeclared'], 'Nothing was compared, so nothing is accused.' );
	}

	public function test_a_targeted_write_that_stays_inside_its_declaration_is_clean(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $this->targeted( 'h1' ) );

		$this->assertSame( 'targeted', $report['intent'] );
		$this->assertSame( array( 'h1' ), $report['targets'] );
		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_sibling_the_tool_changed_outside_its_declaration_is_undeclared(): void {
		// The ability said "h1"; it also rewrote h2. Elementor never saw h2 as
		// anything but a faithful save, so the existing collateral check — which
		// derives its targets from this same diff — cannot see this at all.
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$requested = $this->with( $requested, 'h2', static function ( $el ) { $el['settings']['title'] = 'Dos'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $this->targeted( 'h1' ) );

		$this->assertSame( array( 'h2' ), $report['undeclared'] );
		$this->assertSame( array(), $report['collateral'], 'The save did exactly as asked — the over-reach is the TOOL\'s.' );
		$this->assertFalse( \Elementor_MCP_Collateral::has_findings( $report ), 'Warn-only in this release: never a refusal on its own.' );
	}

	public function test_removing_a_declared_container_covers_its_children(): void {
		$before    = $this->page();
		$requested = array(); // the whole container went, children and all
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $this->targeted( 'c1' ) );

		$this->assertSame( array( 'c1', 'h1', 'h2' ), $report['targets'] );
		$this->assertSame( array(), $report['undeclared'], 'A descendant in the BEFORE tree is covered by its declared ancestor.' );
	}

	public function test_a_changed_child_of_a_declared_parent_is_covered(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h1', static function ( $el ) { $el['settings']['title'] = 'Uno'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $this->targeted( 'c1' ) );

		$this->assertSame( array( 'h1' ), $report['targets'] );
		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_node_added_under_a_declared_parent_is_covered_and_one_added_elsewhere_is_not(): void {
		$before                     = $this->page();
		$requested                  = $before;
		$requested[0]['elements'][] = array( 'id' => 'h3', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Three' ), 'elements' => array() );
		$requested[]                = array( 'id' => 'c2', 'elType' => 'container', 'settings' => array(), 'elements' => array() );
		$report                     = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $this->targeted( 'c1' ) );

		$this->assertSame( array( 'h3', 'c2' ), $report['targets'] );
		$this->assertSame( array( 'c2' ), $report['undeclared'], 'h3 is a descendant of c1 in the REQUESTED tree; c2 is nobody\'s.' );
	}

	/** A container with the given id and children. */
	private function box( string $id, array $children = array() ): array {
		return array( 'id' => $id, 'elType' => 'container', 'settings' => array(), 'elements' => $children );
	}

	/** A heading with the given id and title. */
	private function head( string $id, string $title = 'One' ): array {
		return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => $title ), 'elements' => array() );
	}

	public function test_a_node_stolen_into_a_declared_container_and_rewritten_is_undeclared(): void {
		// Y lived under an undeclared A. The write declared only X, then moved Y
		// into X and rewrote it. Judged on the requested tree alone, X's
		// declaration would cover it — the relocation would launder the
		// coverage, and the one check that exists to catch this would say
		// nothing.
		$before = array( $this->box( 'A', array( $this->head( 'Y', 'orig' ) ) ), $this->box( 'X' ) );
		$after  = array( $this->box( 'A' ), $this->box( 'X', array( $this->head( 'Y', 'HIJACKED' ) ) ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'Y' ), $report['undeclared'] );
	}

	public function test_a_node_evicted_from_a_declared_container_and_rewritten_is_undeclared(): void {
		// The mirror: judged on the BEFORE tree alone, X's declaration covers a
		// node the write threw out of X and rewrote at the top level.
		$before = array( $this->box( 'X', array( $this->head( 'Y', 'orig' ) ) ), $this->box( 'A' ) );
		$after  = array( $this->box( 'X' ), $this->box( 'A' ), $this->head( 'Y', 'EVICTED' ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'Y' ), $report['undeclared'] );
	}

	public function test_a_pure_move_out_of_an_undeclared_parent_is_undeclared(): void {
		// Nothing's payload changed, so there is no derived target at all — a
		// move is invisible to the diff that finds targets. It is still the
		// write taking a node out of a container it never named.
		$before = array( $this->box( 'A', array( $this->head( 'Y' ) ) ), $this->box( 'B' ) );
		$after  = array( $this->box( 'A' ), $this->box( 'B', array( $this->head( 'Y' ) ) ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'B' ) );

		$this->assertSame( array(), $report['targets'], 'How targets are derived does not change.' );
		$this->assertSame( array( 'Y' ), $report['undeclared'] );
		$this->assertFalse( \Elementor_MCP_Collateral::has_findings( $report ), 'Still warn-only.' );
	}

	public function test_a_move_inside_the_declared_subtree_is_not_undeclared(): void {
		// Both ancestries place Y inside c1, so the write stayed where it said.
		$before = array( $this->box( 'c1', array( $this->box( 'c2', array( $this->head( 'Y' ) ) ) ) ) );
		$after  = array( $this->box( 'c1', array( $this->box( 'c2' ), $this->head( 'Y' ) ) ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'c1' ) );

		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_sibling_reorder_under_one_parent_is_not_seen(): void {
		// Honest limitation, documented in the class docblock: a reorder changes
		// no payload and no ancestry, so nothing here can see it.
		$before = array( $this->box( 'A', array( $this->head( 'Y1' ), $this->head( 'Y2' ) ) ), $this->head( 'Z' ) );
		$after  = array( $this->box( 'A', array( $this->head( 'Y2' ), $this->head( 'Y1' ) ) ), $this->head( 'Z', 'changed' ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'Z' ) );

		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_duplicated_id_vouches_for_no_descendant(): void {
		// X (declared) and an undeclared Y each hold a node with id D. Resolving
		// D to its first occurrence would put Y's whole subtree under X, so a
		// rewrite deep inside Y reads as declared work. An ambiguous id proves
		// nothing about ancestry, so it is trusted for nothing.
		$before = array(
			$this->box( 'X', array( $this->box( 'D', array( $this->head( 'W1' ) ) ) ) ),
			$this->box( 'Y', array( $this->box( 'D', array( $this->head( 'W2', 'orig' ) ) ) ) ),
		);
		$after  = $before;
		$after[1]['elements'][0]['elements'][0]['settings']['title'] = 'REWRITTEN';
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'W2' ), $report['undeclared'] );
	}

	public function test_removing_a_declared_container_whose_subtree_holds_a_duplicated_id_is_clean(): void {
		// A page that already carries a duplicate id — Elementor pages do — and
		// an ordinary remove-element on it. W really was inside the declared X;
		// resolving coverage through the id `D` rather than through W's own
		// position accused a legitimate call of over-reach on a page it did not
		// corrupt. `D` itself is not accused either: which D moved where cannot
		// be proven, and an ambiguous id accuses nothing (index()'s rule).
		$before = array(
			$this->box( 'X', array( $this->box( 'D', array( $this->head( 'W' ) ) ) ) ),
			$this->box( 'Y', array( $this->box( 'D', array( $this->head( 'V' ) ) ) ) ),
		);
		$after  = array( $before[1] );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_removing_a_declared_container_whose_own_id_is_duplicated_is_clean(): void {
		$before = array(
			$this->box( 'X', array( $this->head( 'a' ), $this->head( 'b' ) ) ),
			$this->box( 'X', array( $this->head( 'q' ) ) ),
		);
		$after  = array( $before[1] );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array(), $report['undeclared'], 'Every X on the page is declared, so everything under one is too.' );
	}

	public function test_a_node_is_judged_by_the_occurrence_it_actually_sits_under(): void {
		// h1 sits under the M that is inside the declared c1. A second, unrelated
		// M at the root makes the id ambiguous but changes nothing about where
		// h1 is — coverage follows h1's own path, not a lookup by ancestor id.
		$before = array(
			$this->box( 'c1', array( $this->box( 'M', array( $this->head( 'h1', 'One' ) ) ) ) ),
			$this->box( 'M', array( $this->head( 'z' ) ) ),
		);
		$after  = $before;
		$after[0]['elements'][0]['elements'][0]['settings']['title'] = 'Uno';
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'c1' ) );

		$this->assertSame( array( 'h1' ), $report['targets'] );
		$this->assertSame( array(), $report['undeclared'] );
	}

	// Ambiguity the page ALREADY had is tolerated (the three tests above, and
	// the one below). Ambiguity THIS WRITE created is not: forging a copy of a
	// declared id, or planting a decoy that reuses one, would otherwise buy
	// both an exemption for the forgery and cover for whatever is parked under
	// it. Both sides are pinned so neither can be changed silently.

	public function test_a_duplicate_already_on_the_page_is_still_tolerated(): void {
		$before = array( $this->box( 'X' ), $this->head( 'Y', 'orig' ), $this->head( 'Y', 'orig' ) );
		$after  = array(
			array( 'id' => 'X', 'elType' => 'container', 'settings' => array( 'gap' => 1 ), 'elements' => array() ),
			$this->head( 'Y', 'REWRITTEN' ),
			$this->head( 'Y', 'orig' ),
		);
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array(), $report['undeclared'], 'Inherited corruption: which Y is which was never knowable.' );
	}

	/**
	 * KNOWN LIMITATION, pinned so that tightening it is a deliberate act and
	 * not a silent one. When the DECLARED id is itself already duplicated in
	 * the before tree, every occurrence still confers coverage — including one
	 * this write adds — so content parked under a further copy goes unreported.
	 *
	 * It stands because the only way to close it is to stop an inherited
	 * duplicate conferring coverage at all, which re-opens the false positive
	 * the inherited/introduced split exists to prevent: an ordinary
	 * remove-element of an inherited-duplicated container would accuse itself.
	 * The precondition is narrow — the page must ALREADY carry two nodes with
	 * the exact id being edited — and the next test shows it does not extend to
	 * ids the write never declared.
	 */
	public function test_known_limitation_an_inherited_duplicate_of_the_declared_id_still_covers(): void {
		$before = array(
			$this->box( 'X', array( $this->head( 'r1' ) ) ),
			$this->box( 'X', array( $this->head( 'r2' ) ) ),
			$this->box( 'A' ),
		);
		$after   = $before;
		$after[] = $this->box( 'X', array( $this->head( 'smuggled' ) ) );
		$report  = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'smuggled' ), $report['targets'] );
		$this->assertSame( array(), $report['undeclared'], 'Known limitation — see this test\'s docblock before changing it.' );
	}

	public function test_an_inherited_duplicate_the_write_never_declared_covers_nothing(): void {
		// The control for the limitation above: `D` is ambiguous in before too,
		// but nobody declared it, so it confers no coverage and a third copy
		// carrying new content is reported like any other unnamed work.
		$before = array(
			$this->box( 'X' ),
			$this->box( 'D', array( $this->head( 'p' ) ) ),
			$this->box( 'D', array( $this->head( 'q' ) ) ),
		);
		$after     = $before;
		$after[0]  = array( 'id' => 'X', 'elType' => 'container', 'settings' => array( 'gap' => 1 ), 'elements' => array() );
		$after[]   = $this->box( 'D', array( $this->head( 'smuggled2' ) ) );
		$report    = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'smuggled2' ), $report['undeclared'] );
	}

	public function test_a_forged_copy_of_the_declared_id_cannot_smuggle_content_in(): void {
		// The write declares X, then ADDS a second node claiming id X and parks
		// new content under it. Treating "ambiguous" as an exemption would hand
		// the write both a free pass for the forgery and cover for everything
		// beneath it — a declaration that can be minted at will declares nothing.
		$before = array(
			$this->box( 'X', array( $this->head( 'real' ) ) ),
			$this->box( 'A', array( $this->head( 'Y', 'orig' ) ) ),
		);
		$after  = array(
			$this->box( 'X', array( $this->head( 'real' ) ) ),
			$this->box( 'A', array( $this->head( 'Y', 'orig' ) ) ),
			$this->box( 'X', array( $this->head( 'smuggled1' ), $this->box( 'deep', array( $this->head( 'smuggled2' ) ) ) ) ),
		);
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'X', 'smuggled1', 'deep', 'smuggled2' ), $report['undeclared'] );
	}

	public function test_a_decoy_duplicate_cannot_hide_a_rewrite(): void {
		// Y is rewritten; a second node reusing id Y is planted in the same
		// write purely to make Y ambiguous. The decoy must not buy the rewrite
		// an exemption — and nothing else notices, because the save is faithful.
		$before  = array( $this->box( 'X', array( $this->head( 'real' ) ) ), $this->head( 'Y', 'orig' ) );
		$decoyed = array(
			$this->box( 'X', array( $this->head( 'real', 'edited' ) ) ),
			$this->head( 'Y', 'REWRITTEN' ),
			$this->head( 'Y', 'decoy' ),
		);
		$plain   = array( $this->box( 'X', array( $this->head( 'real', 'edited' ) ) ), $this->head( 'Y', 'REWRITTEN' ) );

		$with    = \Elementor_MCP_Collateral::report( $before, $decoyed, $decoyed, null, $this->targeted( 'X' ) );
		$without = \Elementor_MCP_Collateral::report( $before, $plain, $plain, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'Y' ), $without['undeclared'] );
		$this->assertSame( $without['undeclared'], $with['undeclared'], 'Planting a decoy changes nothing.' );
		$this->assertSame( array(), $with['collateral'], 'And nothing else was ever going to see it.' );
	}

	public function test_cloning_the_declared_node_reports_the_forgery_and_what_it_holds(): void {
		$before = array( $this->box( 'X', array( $this->head( 'a' ) ) ) );
		$after  = array( $this->box( 'X', array( $this->head( 'a' ) ) ), $this->box( 'X', array( $this->head( 'a2' ) ) ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'X', 'a2' ), $report['undeclared'] );
	}

	public function test_a_document_declaration_is_untouched_by_a_forged_id(): void {
		$before = array( $this->box( 'X', array( $this->head( 'a' ) ) ) );
		$after  = array( $this->box( 'X', array( $this->head( 'a' ) ) ), $this->box( 'X', array( $this->head( 'a2' ) ) ) );
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, array( 'scope' => 'document' ) );

		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_declared_subtree_thousands_deep_is_covered(): void {
		// Coverage is computed once per node as the tree is walked, not by
		// climbing the ancestry per suspect — 3000 nested nodes, all removed
		// under one declared root, must come back clean rather than quadratic.
		$node = $this->head( 'd2999' );
		for ( $i = 2998; $i >= 0; $i-- ) {
			$node = $this->box( 'd' . $i, array( $node ) );
		}
		$before = array( $node );
		$report = \Elementor_MCP_Collateral::report( $before, array(), array(), null, $this->targeted( 'd0' ) );

		$this->assertCount( 3000, $report['targets'], 'The whole chain was removed, so all of it is a target.' );
		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_a_unique_ancestor_still_covers_its_descendant(): void {
		// The control for the case above: with distinct ids nothing changes.
		$before = array(
			$this->box( 'X', array( $this->box( 'D1', array( $this->head( 'W1' ) ) ) ) ),
			$this->box( 'Y', array( $this->box( 'D2', array( $this->head( 'W2', 'orig' ) ) ) ) ),
		);
		$after  = $before;
		$after[0]['elements'][0]['elements'][0]['settings']['title'] = 'REWRITTEN';
		$report = \Elementor_MCP_Collateral::report( $before, $after, $after, null, $this->targeted( 'X' ) );

		$this->assertSame( array( 'W1' ), $report['targets'] );
		$this->assertSame( array(), $report['undeclared'] );
	}

	public function test_every_id_the_report_names_is_a_string(): void {
		// Element ids are strings, but PHP turns an all-digit array key into an
		// int — so a list built from array keys silently changes type for a page
		// whose ids happen to be numeric, and a consumer comparing with === is
		// wrong on exactly those pages.
		$before    = array( $this->head( '123' ), $this->head( '456' ), $this->head( '789' ) );
		$requested = $before;
		$requested[0]['settings']['title']      = 'changed';
		$requested[0]['settings']['custom_css'] = 'a{}';
		$requested[1]['settings']['title']      = 'changed';
		$persisted = $requested;
		unset( $persisted[0]['settings']['custom_css'] ); // not landed
		$persisted[2]['settings']['title'] = '';          // collateral
		$persisted[]                       = $this->head( '999' ); // gained

		$report = \Elementor_MCP_Collateral::report( $before, $requested, $persisted, null, $this->targeted( '123' ) );

		$this->assertSame( array( '123', '456' ), $report['targets'] );
		$this->assertSame( array( '456' ), $report['undeclared'] );
		$this->assertSame( '789', $report['collateral'][0]['id'] );
		$this->assertSame( '999', $report['gained'][0]['id'] );
		$this->assertSame( '123', $report['not_landed'][0]['id'] );
	}

	public function test_a_document_declaration_never_yields_undeclared(): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h2', static function ( $el ) { $el['settings']['title'] = 'Dos'; return $el; } );
		$requested = $this->with( $requested, 'c1', static function ( $el ) { $el['settings']['gap'] = 40; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, array( 'scope' => 'document' ) );

		$this->assertSame( 'document', $report['intent'] );
		$this->assertSame( array(), $report['undeclared'], 'The whole document was declared; nothing can be outside it.' );
	}

	/**
	 * Malformed intent is treated as undeclared — exactly today's behaviour. It
	 * never throws and never blocks a write: a declaration this class cannot
	 * read is a bug in the caller, not a reason to refuse the caller's write.
	 *
	 * @dataProvider malformed_intents
	 * @param mixed $intent Declaration to reject.
	 */
	public function test_a_malformed_declaration_is_treated_as_undeclared( $intent ): void {
		$before    = $this->page();
		$requested = $this->with( $before, 'h2', static function ( $el ) { $el['settings']['title'] = 'Dos'; return $el; } );
		$report    = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $intent );

		$this->assertSame( 'undeclared', $report['intent'] );
		$this->assertSame( array(), $report['undeclared'] );
		$this->assertSame( array( 'h2' ), $report['targets'], 'Everything else is unchanged.' );
	}

	public static function malformed_intents(): array {
		return array(
			'unknown scope'              => array( array( 'scope' => 'partial', 'ids' => array( 'h1' ) ) ),
			'no scope'                   => array( array( 'ids' => array( 'h1' ) ) ),
			'targeted with no ids'       => array( array( 'scope' => 'targeted' ) ),
			'targeted with an empty list' => array( array( 'scope' => 'targeted', 'ids' => array() ) ),
			'targeted with a non-string' => array( array( 'scope' => 'targeted', 'ids' => array( 'h1', 17 ) ) ),
			'targeted with an empty id'  => array( array( 'scope' => 'targeted', 'ids' => array( '' ) ) ),
			'targeted with a map'        => array( array( 'scope' => 'targeted', 'ids' => array( 'a' => 'h1' ) ) ),
			'ids that are not a list'    => array( array( 'scope' => 'targeted', 'ids' => 'h1' ) ),
			'not an array'               => array( 'h1' ),
			'null'                       => array( null ),
		);
	}

	public function test_the_undeclared_summary_names_the_ids_and_caps_the_list(): void {
		$before = array(
			array( 'id' => 'c1', 'elType' => 'container', 'settings' => array(), 'elements' => array() ),
		);
		for ( $i = 1; $i <= 7; $i++ ) {
			$before[] = array( 'id' => 'h' . $i, 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'N' ), 'elements' => array() );
		}
		$requested = $before;
		foreach ( $requested as $k => $node ) {
			if ( 'c1' !== $node['id'] ) {
				$requested[ $k ]['settings']['title'] = 'changed';
			}
		}
		$report  = \Elementor_MCP_Collateral::report( $before, $requested, $requested, null, $this->targeted( 'h1' ) );
		$summary = \Elementor_MCP_Collateral::summarize_undeclared( $report );

		$this->assertSame( array( 'h2', 'h3', 'h4', 'h5', 'h6', 'h7' ), $report['undeclared'] );
		$this->assertStringContainsString( '6 elements', $summary );
		$this->assertStringContainsString( 'h2', $summary );
		$this->assertStringContainsString( 'h6', $summary );
		$this->assertStringNotContainsString( 'h7', $summary, 'Capped at five, like summarize().' );
		$this->assertStringContainsString( 'declare', $summary, 'The reason says the write never declared them.' );
	}

	public function test_the_undeclared_summary_is_empty_when_there_is_nothing_to_say(): void {
		$before = $this->page();
		$report = \Elementor_MCP_Collateral::report( $before, $before, $before, null, $this->targeted( 'h1' ) );

		$this->assertSame( '', \Elementor_MCP_Collateral::summarize_undeclared( $report ) );
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
