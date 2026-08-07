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

	/**
	 * A real atomic element as the factory writes it — with the sibling-root
	 * `styles` / `editor_settings` keys, which are themselves the structural
	 * atomic signal the hoisting gate reads.
	 */
	private function base_element( array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => 'el1',
				'elType'          => 'e-heading',
				'settings'        => [],
				'elements'        => [],
				'styles'          => [],
				'editor_settings' => [],
			],
			$overrides
		);
	}

	/** A classic/custom element: none of the atomic root keys. */
	private function classic_element( array $overrides = [] ): array {
		return array_merge(
			[
				'id'       => 'el1',
				'elType'   => 'widget',
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

	// -------------------------------------------------------------------------
	// Hoisting is ATOMIC-ONLY (Codex retro-round)
	// -------------------------------------------------------------------------

	public function test_classic_widget_keeps_styles_control_in_its_own_settings(): void {
		// This repo's widget builder lets a custom widget register controls
		// named `styles` / `editor_settings`. Hoisting those to the element
		// root would delete the control's value from settings while reporting
		// success — the widget would render its old value forever.
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->classic_element( [ 'widgetType' => 'my_custom_widget' ] )
		);

		$data->update_element_settings(
			$tree,
			'el1',
			[
				'styles'          => 'compact',
				'editor_settings' => 'inline',
			]
		);

		$this->assertSame( 'compact', $tree[0]['settings']['styles'], 'A classic widget control named styles must stay in settings.' );
		$this->assertSame( 'inline', $tree[0]['settings']['editor_settings'] );
		$this->assertArrayNotHasKey( 'styles', $tree[0], 'Nothing may be hoisted to the root of a classic widget.' );
		$this->assertArrayNotHasKey( 'classes', $tree[0]['settings'], 'No class wiring on a classic widget either.' );
	}

	public function test_prefix_colliding_custom_widget_is_not_treated_as_atomic(): void {
		// A registered classic/custom widget may legitimately use an e-* slug.
		// The naming convention alone must NOT authorize hoisting — that is the
		// data loss this gate exists to prevent (Codex round-2).
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->classic_element( [ 'widgetType' => 'e-my-custom-widget' ] ) );

		$data->update_element_settings( $tree, 'el1', [ 'styles' => 'compact' ] );

		$this->assertSame( 'compact', $tree[0]['settings']['styles'], 'An e-* slug with no atomic signal stays classic.' );
		$this->assertArrayNotHasKey( 'styles', $tree[0] );
	}

	public function test_typed_settings_alone_are_an_atomic_signal(): void {
		// An element with no root keys yet but typed $$type props is atomic.
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->classic_element(
				[
					'widgetType' => 'e-heading',
					'settings'   => [ 'tag' => [ '$$type' => 'string', 'value' => 'h2' ] ],
				]
			)
		);

		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-typed-9' => [ 'id' => 'e-typed-9', 'type' => 'class', 'variants' => [] ] ] ]
		);

		$this->assertArrayHasKey( 'e-typed-9', $tree[0]['styles'] );
	}

	public function test_registered_atomic_schema_is_an_atomic_signal(): void {
		// No structural markers at all — the registry decides. A classic widget
		// has no get_props_schema(); an atomic one does.
		$widget = new class() {
			public static function get_props_schema(): array {
				return [ 'tag' => new \stdClass() ];
			}
		};
		$GLOBALS['_widget_types'] = [ 'e-registry-probe' => $widget ];

		try {
			$data = new \Elementor_MCP_Data();
			$tree = $this->tree_with_element( $this->classic_element( [ 'widgetType' => 'e-registry-probe' ] ) );

			$data->update_element_settings(
				$tree,
				'el1',
				[ 'styles' => [ 'e-reg-1' => [ 'id' => 'e-reg-1', 'type' => 'class', 'variants' => [] ] ] ]
			);

			$this->assertArrayHasKey( 'e-reg-1', $tree[0]['styles'], 'A registered atomic prop schema is authoritative.' );
		} finally {
			unset( $GLOBALS['_widget_types'] );
		}
	}

	public function test_atomic_widget_still_hoists(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->base_element( [ 'elType' => 'widget', 'widgetType' => 'e-heading' ] )
		);

		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-abc-1' => [ 'id' => 'e-abc-1', 'type' => 'class', 'variants' => [] ] ] ]
		);

		$this->assertArrayHasKey( 'e-abc-1', $tree[0]['styles'], 'An atomic WIDGET (elType=widget, e-* widgetType) must still hoist.' );
		$this->assertSame( [ 'e-abc-1' ], $tree[0]['settings']['classes']['value'] );
	}

	public function test_atomic_container_still_hoists(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->base_element( [ 'elType' => 'e-flexbox' ] ) );

		$data->update_element_settings(
			$tree,
			'el1',
			[ 'styles' => [ 'e-box-2' => [ 'id' => 'e-box-2', 'type' => 'class', 'variants' => [] ] ] ]
		);

		$this->assertArrayHasKey( 'e-box-2', $tree[0]['styles'], 'An atomic CONTAINER carries e-* in elType itself.' );
	}

	public function test_classic_container_does_not_hoist(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element( $this->classic_element( [ 'elType' => 'container' ] ) );

		$data->update_element_settings( $tree, 'el1', [ 'styles' => 'legacy-value' ] );

		$this->assertSame( 'legacy-value', $tree[0]['settings']['styles'] );
		$this->assertArrayNotHasKey( 'styles', $tree[0] );
	}

	public function test_container_shorthand_normalization_still_runs(): void {
		$data = new \Elementor_MCP_Data();
		$tree = $this->tree_with_element(
			$this->classic_element( [ 'elType' => 'container' ] )
		);

		$data->update_element_settings( $tree, 'el1', [ 'justify_content' => 'center' ] );

		$this->assertArrayHasKey(
			'flex_justify_content',
			$tree[0]['settings'],
			'The pre-existing container shorthand rewrite must survive the hoisting refactor.'
		);
	}
}
