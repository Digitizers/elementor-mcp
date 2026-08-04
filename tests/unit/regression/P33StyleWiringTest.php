<?php
/**
 * Unit tests — P3.3 harvest item #3: atomic style-class auto-wiring,
 * sibling-root hoisting and deep-merge (upstream #72/#73/#92).
 *
 * On v4 atomic elements the local `styles` map and `editor_settings` live at
 * the element ROOT as siblings of `settings`. An agent naturally nests them
 * under `settings`, where they land on a dead key and render nothing; and a
 * `styles` write whose class id is missing from `settings.classes` persists
 * but never applies. update_element_settings() must hoist, deep-merge, and
 * wire class refs.
 *
 * @group unit
 * @group regression
 * @package Elementor_MCP\Tests
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class P33StyleWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_meta_calls'] = [];
		$GLOBALS['_post_meta']     = [];
	}

	private function tree_with_element( array $element ): array {
		return [ $element ];
	}

	private function base_element( array $overrides = [] ): array {
		return array_merge(
			[
				'id'       => 'el1',
				'elType'   => 'e-heading',
				'settings' => [],
				'elements' => [],
			],
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// Sibling-root hoisting (#72/#73)
	// -------------------------------------------------------------------------

	public function test_styles_nested_under_settings_is_hoisted_to_the_element_root(): void {
		$data    = new \Elementor_MCP_Data();
		$tree    = $this->tree_with_element( $this->base_element() );
		$styles  = [
			'e-abc-1' => [
				'id'       => 'e-abc-1',
				'type'     => 'class',
				'variants' => [],
			],
		];
		$updated = $data->update_element_settings( $tree, 'el1', [ 'styles' => $styles ] );

		$this->assertTrue( $updated );
		$this->assertSame( $styles, $tree[0]['styles'], 'styles must live at the element ROOT (upstream #72).' );
		$this->assertArrayNotHasKey(
			'styles',
			$tree[0]['settings'],
			'settings.styles is a dead key — the nested map must be hoisted out, not persisted there.'
		);
	}

	public function test_editor_settings_is_hoisted_too(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element() );

		$data->update_element_settings( $tree, 'el1', [ 'editor_settings' => [ 'title' => 'Hero' ] ] );

		$this->assertSame( [ 'title' => 'Hero' ], $tree[0]['editor_settings'] );
		$this->assertArrayNotHasKey( 'editor_settings', $tree[0]['settings'] );
	}

	public function test_non_array_root_value_replaces_wholesale(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element( [ 'editor_settings' => [ 'title' => 'Old' ] ] ) );

		$data->update_element_settings( $tree, 'el1', [ 'editor_settings' => null ] );

		$this->assertNull( $tree[0]['editor_settings'], 'A non-array incoming root value replaces, never merges.' );
	}

	// -------------------------------------------------------------------------
	// deep_merge semantics
	// -------------------------------------------------------------------------

	public function test_partial_styles_update_preserves_sibling_classes(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->base_element(
				[
					'styles' => [
						'e-keep-1' => [
							'id'   => 'e-keep-1',
							'type' => 'class',
							'variants' => [ [ 'props' => [ 'color' => 'red' ] ] ],
						],
					],
				]
			)
		);

		$data->update_element_settings(
			$tree,
			'el1',
			[
				'styles' => [
					'e-new-2' => [
						'id'   => 'e-new-2',
						'type' => 'class',
						'variants' => [],
					],
				],
			]
		);

		$this->assertArrayHasKey( 'e-keep-1', $tree[0]['styles'], 'A partial styles update must not drop sibling classes (deep merge).' );
		$this->assertArrayHasKey( 'e-new-2', $tree[0]['styles'] );
	}

	public function test_variants_list_is_replaced_wholesale_not_merged(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->base_element(
				[
					'styles' => [
						'e-abc-1' => [
							'id'       => 'e-abc-1',
							'type'     => 'class',
							'variants' => [
								[ 'meta' => [ 'state' => null ], 'props' => [ 'color' => 'red' ] ],
								[ 'meta' => [ 'state' => 'hover' ], 'props' => [ 'color' => 'blue' ] ],
							],
						],
					],
				]
			)
		);

		$new_variants = [ [ 'meta' => [ 'state' => null ], 'props' => [ 'color' => 'green' ] ] ];
		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-abc-1' => [ 'variants' => $new_variants ] ] ]
		);

		$this->assertSame(
			$new_variants,
			$tree[0]['styles']['e-abc-1']['variants'],
			'A variants LIST supplied in full must replace wholesale, not index-merge with the old list.'
		);
		$this->assertSame( 'class', $tree[0]['styles']['e-abc-1']['type'], 'Untouched sibling keys of the class survive the merge.' );
	}

	// -------------------------------------------------------------------------
	// sync_local_class_refs (#92)
	// -------------------------------------------------------------------------

	public function test_styles_write_wires_class_id_into_settings_classes(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element() );

		$data->update_element_settings(
			$tree,
			'el1',
			[
				'styles' => [
					'e-abc-1' => [ 'id' => 'e-abc-1', 'type' => 'class', 'variants' => [] ],
				],
			]
		);

		$this->assertSame(
			[ '$$type' => 'classes', 'value' => [ 'e-abc-1' ] ],
			$tree[0]['settings']['classes'],
			'A styles write must be self-contained: the class id goes into settings.classes or nothing renders (upstream #92).'
		);
	}

	public function test_existing_class_refs_are_kept_and_not_duplicated(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->base_element(
				[
					'settings' => [
						'classes' => [ '$$type' => 'classes', 'value' => [ 'e-abc-1', 'g-global-9' ] ],
					],
					'styles'   => [
						'e-abc-1' => [ 'id' => 'e-abc-1', 'type' => 'class', 'variants' => [] ],
					],
				]
			)
		);

		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-abc-1' => [ 'variants' => [] ] ] ]
		);

		$this->assertSame(
			[ 'e-abc-1', 'g-global-9' ],
			$tree[0]['settings']['classes']['value'],
			'Sync is idempotent: existing refs (including globals) survive, nothing is duplicated.'
		);
	}

	public function test_non_class_style_defs_are_not_wired(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element() );

		$data->update_element_settings(
			$tree,
			'el1',
			[
				'styles' => [
					'e-cls-1'  => [ 'id' => 'e-cls-1', 'type' => 'class', 'variants' => [] ],
					'e-misc-2' => [ 'id' => 'e-misc-2', 'type' => 'custom', 'variants' => [] ],
				],
			]
		);

		$this->assertSame(
			[ 'e-cls-1' ],
			$tree[0]['settings']['classes']['value'],
			'Only type:class style defs are applied classes; other def types must not be wired.'
		);
	}

	public function test_style_key_is_used_when_def_has_no_id(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element() );

		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-key-only-3' => [ 'type' => 'class', 'variants' => [] ] ] ]
		);

		$this->assertSame( [ 'e-key-only-3' ], $tree[0]['settings']['classes']['value'] );
	}

	public function test_raw_list_classes_value_is_preserved_when_wiring(): void {
		// An agent may have written classes as a bare id list. The sync must
		// fold that list into the wrapper, not clobber it into a malformed
		// shape (divergence from upstream, which loses this edge).
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->base_element(
				[ 'settings' => [ 'classes' => [ 'e-existing-7' ] ] ]
			)
		);

		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-new-8' => [ 'id' => 'e-new-8', 'type' => 'class', 'variants' => [] ] ] ]
		);

		$this->assertSame(
			[ '$$type' => 'classes', 'value' => [ 'e-existing-7', 'e-new-8' ] ],
			$tree[0]['settings']['classes'],
			'A bare id list is the VALUE — it must be folded into the wrapper, ids intact.'
		);
	}

	public function test_update_without_styles_leaves_classes_untouched(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element() );

		$data->update_element_settings( $tree, 'el1', [ 'tag' => 'h2' ] );

		$this->assertArrayNotHasKey( 'classes', $tree[0]['settings'], 'No styles touched — no classes wiring.' );
		$this->assertSame( 'h2', $tree[0]['settings']['tag'] );
	}

	public function test_nested_elements_are_reached(): void {
		$data  = new \Elementor_MCP_Data();
		$inner = $this->base_element( [ 'id' => 'inner1' ] );
		$tree  = [
			[
				'id'       => 'root1',
				'elType'   => 'e-flexbox',
				'settings' => [],
				'elements' => [ $inner ],
			],
		];

		$updated = $data->update_element_settings(
			$tree,
			'inner1',
			[ 'styles' => [ 'e-deep-4' => [ 'id' => 'e-deep-4', 'type' => 'class', 'variants' => [] ] ] ]
		);

		$this->assertTrue( $updated );
		$this->assertSame( [ 'e-deep-4' ], $tree[0]['elements'][0]['settings']['classes']['value'] );
		$this->assertArrayHasKey( 'e-deep-4', $tree[0]['elements'][0]['styles'] );
	}

	public function test_container_shorthand_normalization_still_runs(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->base_element( [ 'elType' => 'container' ] )
		);

		$data->update_element_settings( $tree, 'el1', [ 'justify_content' => 'center' ] );

		$this->assertArrayHasKey(
			'flex_justify_content',
			$tree[0]['settings'],
			'The pre-existing container shorthand rewrite must survive the hoisting refactor.'
		);
	}
}
