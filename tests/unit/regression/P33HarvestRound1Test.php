<?php
/**
 * Unit tests — P3.3 harvest round 1 (upstream 3.x correctness ports).
 *
 * Covers four mechanisms ported from upstream:
 *  - Silent-save verification (#98): Document::save() returning truthy while
 *    persisting nothing must trigger the direct-meta fallback, never a
 *    phantom success.
 *  - Local style-class re-mint on duplicate (#97): a duplicated v4 element
 *    must not share the source's `e-<id>-<hash>` local classes.
 *  - Fatal-proof ability registration (#100): a throwing group aborts only
 *    the remainder of registration, never the request.
 *  - structuredContent normalization (3.6.1): lists/scalars wrapped under
 *    `data`, objects and WP_Error untouched.
 *
 * @group unit
 * @group regression
 * @package Elementor_MCP\Tests
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class P33HarvestRound1Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_meta_calls']            = [];
		$GLOBALS['_post_meta']                = [];
		$GLOBALS['_registered_element_types'] = [];
	}

	// -------------------------------------------------------------------------
	// #98 — silent-save verification
	// -------------------------------------------------------------------------

	private function make_data_with_document( $save_return ): \Elementor_MCP_Data {
		$document = new class( $save_return ) {
			private $ret;
			public function __construct( $ret ) {
				$this->ret = $ret;
			}
			public function save( array $args ) {
				return $this->ret;
			}
		};

		\Elementor\Plugin::$instance->documents = new class( $document ) {
			private $doc;
			public function __construct( $doc ) {
				$this->doc = $doc;
			}
			public function get( int $post_id, bool $from_cache = true ) {
				return $this->doc;
			}
		};

		return new \Elementor_MCP_Data();
	}

	public function test_truthy_save_with_nothing_persisted_falls_back_to_meta_write(): void {
		$data = $this->make_data_with_document( true );

		// _post_meta stays empty: the re-read sees nothing persisted.
		$result = $data->save_page_data( 123, [ [ 'id' => 'abc', 'elType' => 'container' ] ] );

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'Truthy Document::save() with an empty _elementor_data re-read must trigger the direct meta fallback (upstream #98).'
		);
	}

	public function test_truthy_save_with_data_persisted_skips_fallback(): void {
		$data = $this->make_data_with_document( true );

		// Simulate Elementor having really persisted the elements.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode( [ [ 'id' => 'abc' ] ] );

		$result = $data->save_page_data( 123, [ [ 'id' => 'abc', 'elType' => 'container' ] ] );

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertEmpty(
			$writes,
			'A verified native save must not take the fallback meta-write path.'
		);
	}

	// -------------------------------------------------------------------------
	// #97 — local class re-mint on duplicate
	// -------------------------------------------------------------------------

	public function test_remap_local_classes_remints_ids_and_repoints_refs(): void {
		$element = [
			'id'       => 'newid99',
			'styles'   => [
				'e-oldid-abc1234' => [
					'id'    => 'e-oldid-abc1234',
					'label' => 'local',
					'type'  => 'class',
				],
			],
			'settings' => [
				'classes' => [
					'$$type' => 'classes',
					'value'  => [ 'e-oldid-abc1234', 'g-global-1' ],
				],
			],
		];

		\Elementor_MCP_Atomic_Styles::remap_local_classes( $element );

		$new_keys = array_keys( $element['styles'] );
		$this->assertCount( 1, $new_keys );
		$this->assertStringStartsWith( 'e-newid99-', $new_keys[0], 'Re-minted class embeds the NEW element id.' );
		$this->assertSame( $new_keys[0], $element['styles'][ $new_keys[0] ]['id'], 'Style def id updated to the new class id.' );
		$this->assertSame(
			[ $new_keys[0], 'g-global-1' ],
			$element['settings']['classes']['value'],
			'Local ref repointed; global class ref untouched.'
		);
	}

	public function test_duplicate_via_reassign_element_ids_gets_fresh_local_classes(): void {
		$data    = new \Elementor_MCP_Data();
		$element = [
			'id'     => 'srcid11',
			'styles' => [
				'e-srcid11-dead123' => [ 'id' => 'e-srcid11-dead123', 'type' => 'class' ],
			],
		];

		$dup = $data->reassign_element_ids( $element );

		$this->assertNotSame( 'srcid11', $dup['id'] );
		$dup_class = array_keys( $dup['styles'] )[0];
		$this->assertStringStartsWith( 'e-' . $dup['id'] . '-', $dup_class, 'Duplicate must not share the source local class (upstream #97).' );
	}

	public function test_remap_is_a_noop_without_styles(): void {
		$element = [ 'id' => 'x1', 'settings' => [] ];
		\Elementor_MCP_Atomic_Styles::remap_local_classes( $element );
		$this->assertSame( [ 'id' => 'x1', 'settings' => [] ], $element );
	}

	// -------------------------------------------------------------------------
	// #100 — fatal-proof registration
	// -------------------------------------------------------------------------

	public function test_throwing_group_does_not_escape_register_all(): void {
		$schema_generator = new \Elementor_MCP_Schema_Generator();
		$registrar        = new class(
			new \Elementor_MCP_Data(),
			new \Elementor_MCP_Element_Factory(),
			$schema_generator,
			new \Elementor_MCP_Settings_Validator( $schema_generator )
		) extends \Elementor_MCP_Ability_Registrar {
			protected function register_groups(): void {
				throw new \Error( 'Class "Quarantined_Group" not found' );
			}
		};

		$names = $registrar->register_all();

		$this->assertIsArray( $names, 'A throwing group must degrade to partial registration, never fatal (upstream #100).' );
	}

	// -------------------------------------------------------------------------
	// 3.6.1 — structuredContent normalization
	// -------------------------------------------------------------------------

	public function test_normalize_wraps_lists_scalars_null_and_empty_array(): void {
		$this->assertSame( [ 'data' => [ 1, 2, 3 ] ], \Elementor_MCP_Result_Normalizer::normalize( [ 1, 2, 3 ] ) );
		$this->assertSame( [ 'data' => 'ok' ], \Elementor_MCP_Result_Normalizer::normalize( 'ok' ) );
		$this->assertSame( [ 'data' => null ], \Elementor_MCP_Result_Normalizer::normalize( null ) );
		$this->assertSame( [ 'data' => [] ], \Elementor_MCP_Result_Normalizer::normalize( [] ) );
	}

	public function test_normalize_passes_objects_and_assoc_arrays_through(): void {
		$assoc = [ 'pages' => [ 1, 2 ], 'total' => 2 ];
		$this->assertSame( $assoc, \Elementor_MCP_Result_Normalizer::normalize( $assoc ) );

		$obj = (object) [ 'a' => 1 ];
		$this->assertSame( $obj, \Elementor_MCP_Result_Normalizer::normalize( $obj ) );
	}

	public function test_normalize_passes_wp_error_through(): void {
		$err = new \WP_Error( 'code', 'message' );
		$this->assertSame( $err, \Elementor_MCP_Result_Normalizer::normalize( $err ) );
	}

	// -------------------------------------------------------------------------
	// Codex round-1 hardening
	// -------------------------------------------------------------------------

	public function test_truthy_save_leaving_stale_content_falls_back(): void {
		$data = $this->make_data_with_document( true );

		// Page already populated with DIFFERENT element ids: a save that
		// silently drops our tree leaves this stale content in place.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode( [ [ 'id' => 'stale-old' ] ] );

		$result = $data->save_page_data( 123, [ [ 'id' => 'fresh-new', 'elType' => 'container' ] ] );

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'Stale pre-save content (requested ids missing from the re-read) must trigger the fallback.'
		);
	}

	public function test_truthy_save_of_empty_tree_leaving_old_content_falls_back(): void {
		$data = $this->make_data_with_document( true );

		// delete-page-content path: requested tree is empty, but the silent
		// drop left the old elements in place.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode( [ [ 'id' => 'old-content' ] ] );

		$result = $data->save_page_data( 123, [] );

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'An empty-tree save that leaves old content persisted must trigger the fallback (delete-page-content silent drop).'
		);
	}

	public function test_truthy_save_of_empty_tree_with_empty_page_skips_fallback(): void {
		$data = $this->make_data_with_document( true );

		// Page really is empty after the save — no fallback needed.
		$result = $data->save_page_data( 123, [] );

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertEmpty( $writes, 'A verified empty save must not take the fallback path.' );
	}

	public function test_truthy_save_dropping_nested_change_falls_back(): void {
		$data = $this->make_data_with_document( true );

		// Same top-level id, but the persisted (stale) tree is missing the
		// nested child we just added — top-level comparison would miss this.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'root1', 'elements' => [] ] ]
		);

		$result = $data->save_page_data(
			123,
			[ [ 'id' => 'root1', 'elements' => [ [ 'id' => 'new-child', 'elType' => 'widget' ] ] ] ]
		);

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'A silent drop of a nested-only change must trigger the fallback (recursive id-sequence comparison).'
		);
	}

	public function test_truthy_save_dropping_reorder_falls_back(): void {
		$data = $this->make_data_with_document( true );

		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'a1' ], [ 'id' => 'b2' ] ]
		);

		$result = $data->save_page_data( 123, [ [ 'id' => 'b2' ], [ 'id' => 'a1' ] ] );

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'A silently dropped reorder (same id set, different order) must trigger the fallback.'
		);
	}

	public function test_sanitized_unavailable_widget_does_not_trigger_fallback(): void {
		$data = $this->make_data_with_document( true );

		// Elementor deliberately stripped the unknown widget type on save —
		// the bootstrap widgets_manager stub reports every type unavailable.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'root1', 'elements' => [] ] ]
		);

		$result = $data->save_page_data(
			123,
			[
				[
					'id'       => 'root1',
					'elements' => [ [ 'id' => 'ghost1', 'elType' => 'widget', 'widgetType' => 'ghost-widget' ] ],
				],
			]
		);

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertEmpty(
			$writes,
			'Deliberate sanitization of an unavailable widget type must NOT be resurrected by the raw-meta fallback.'
		);
	}

	public function test_dropped_available_widget_still_triggers_fallback(): void {
		$data = $this->make_data_with_document( true );

		// Make this widget type AVAILABLE: manager returns an object for it.
		// Restore the shared stub afterwards — Plugin::$instance is global.
		$original_manager = \Elementor\Plugin::$instance->widgets_manager;

		\Elementor\Plugin::$instance->widgets_manager = new class() {
			public function get_widget_types( $type = null ) {
				return 'real-widget' === $type ? new \stdClass() : null;
			}
		};

		try {
			$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
				[ [ 'id' => 'root1', 'elements' => [] ] ]
			);

			$result = $data->save_page_data(
				123,
				[
					[
						'id'       => 'root1',
						'elements' => [ [ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'real-widget' ] ],
					],
				]
			);

			$this->assertTrue( $result );
			$writes = array_filter(
				$GLOBALS['_wp_meta_calls'],
				static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
			);
			$this->assertNotEmpty(
				$writes,
				'A silently dropped AVAILABLE widget is a real write failure and must trigger the fallback.'
			);
		} finally {
			\Elementor\Plugin::$instance->widgets_manager = $original_manager;
		}
	}

	public function test_mixed_drop_fallback_strips_unavailable_elements(): void {
		$data = $this->make_data_with_document( true );

		// Mixed tree: a real (available) dropped element + an unavailable one.
		// Fallback must fire AND must not resurrect the unavailable widget.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'root1', 'elements' => [] ] ]
		);

		$result = $data->save_page_data(
			123,
			[
				[
					'id'       => 'root1',
					'elements' => [
						[ 'id' => 'plain1', 'elType' => 'container' ],
						[ 'id' => 'ghost1', 'elType' => 'widget', 'widgetType' => 'ghost-widget' ],
					],
				],
			]
		);

		$this->assertTrue( $result );
		$writes = array_values( array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		) );
		$this->assertNotEmpty( $writes, 'Mixed drop must still trigger the fallback.' );
		$written   = json_decode( stripslashes( (string) ( $writes[0]["meta_value"] ?? "" ) ), true );
		$child_ids = array_map(
			static fn( $el ) => $el['id'],
			$written[0]['elements'] ?? []
		);
		$this->assertContains( 'plain1', $child_ids, 'Available element written.' );
		$this->assertNotContains( 'ghost1', $child_ids, 'Unavailable widget must be stripped from the fallback write.' );
	}

	public function test_falsy_save_preserves_unavailable_widgets_in_fallback(): void {
		$data = $this->make_data_with_document( null );

		// Classic CLI/REST falsy return: no native save ran — a widget from a
		// temporarily inactive plugin is data and must be written as-is.
		$result = $data->save_page_data(
			123,
			[
				[
					'id'       => 'root1',
					'elements' => [ [ 'id' => 'ghost1', 'elType' => 'widget', 'widgetType' => 'inactive-plugin-widget' ] ],
				],
			]
		);

		$this->assertTrue( $result );
		$writes = array_values( array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		) );
		$this->assertNotEmpty( $writes );
		$written   = json_decode( stripslashes( (string) ( $writes[0]['meta_value'] ?? '' ) ), true );
		$child_ids = array_map( static fn( $el ) => $el['id'], $written[0]['elements'] ?? [] );
		$this->assertContains(
			'ghost1',
			$child_ids,
			'Falsy-save fallback must preserve unavailable widgets — that is data, not sanitization.'
		);
	}

	public function test_sanitized_unregistered_atomic_container_not_resurrected(): void {
		$data = $this->make_data_with_document( true );

		// e-flexbox is NOT in $GLOBALS['_registered_element_types'] — Elementor
		// sanitized it away; no fallback despite the id-sequence mismatch.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'root1', 'elements' => [] ] ]
		);

		$result = $data->save_page_data(
			123,
			[
				[
					'id'       => 'root1',
					'elements' => [ [ 'id' => 'flex1', 'elType' => 'e-flexbox' ] ],
				],
			]
		);

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertEmpty(
			$writes,
			'An unregistered atomic container (no widgetType) sanitized by Elementor must not be resurrected.'
		);
	}

	public function test_registered_atomic_container_drop_triggers_fallback(): void {
		$data = $this->make_data_with_document( true );

		$GLOBALS['_registered_element_types'] = [ 'e-flexbox' ];
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'root1', 'elements' => [] ] ]
		);

		$result = $data->save_page_data(
			123,
			[
				[
					'id'       => 'root1',
					'elements' => [ [ 'id' => 'flex1', 'elType' => 'e-flexbox' ] ],
				],
			]
		);

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'A silently dropped REGISTERED atomic container is a real write failure.'
		);
	}

	public function test_silent_drop_fallback_preserves_preexisting_unavailable_widget(): void {
		$data = $this->make_data_with_document( true );

		// The page ALREADY contains an inactive-plugin widget (persisted), and a
		// truthy-but-stale save dropped an unrelated available addition. The
		// fallback must fire — and must keep the pre-existing unavailable widget.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[
				[
					'id'       => 'root1',
					'elements' => [ [ 'id' => 'legacy1', 'elType' => 'widget', 'widgetType' => 'inactive-plugin-widget' ] ],
				],
			]
		);

		$result = $data->save_page_data(
			123,
			[
				[
					'id'       => 'root1',
					'elements' => [
						[ 'id' => 'legacy1', 'elType' => 'widget', 'widgetType' => 'inactive-plugin-widget' ],
						[ 'id' => 'newbox1', 'elType' => 'container' ],
					],
				],
			]
		);

		$this->assertTrue( $result );
		$writes = array_values( array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		) );
		$this->assertNotEmpty( $writes, 'Dropped available addition must trigger the fallback.' );
		$written   = json_decode( stripslashes( (string) ( $writes[0]['meta_value'] ?? '' ) ), true );
		$child_ids = array_map( static fn( $el ) => $el['id'], $written[0]['elements'] ?? [] );
		$this->assertContains( 'legacy1', $child_ids, 'Pre-existing unavailable widget preserved.' );
		$this->assertContains( 'newbox1', $child_ids, 'The dropped available addition written.' );
	}

	public function test_parentage_only_move_drop_triggers_fallback(): void {
		$data = $this->make_data_with_document( true );

		// Persisted (stale): B nested inside A. Requested: B moved to top level
		// right after A — flat DFS order is identical (A, B); only parentage
		// differs. A silent drop of this move must still be detected.
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'elA', 'elements' => [ [ 'id' => 'elB' ] ] ] ]
		);

		$result = $data->save_page_data(
			123,
			[ [ 'id' => 'elA', 'elements' => [] ], [ 'id' => 'elB' ] ]
		);

		$this->assertTrue( $result );
		$writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertNotEmpty(
			$writes,
			'A silently dropped parentage-only move (same flat order) must trigger the fallback.'
		);
	}

	public function test_mixed_reorder_with_unavailable_addition_still_falls_back(): void {
		$data = $this->make_data_with_document( true );

		// Persisted: [A, B]. Requested: [B, A, ghost] — a reorder (real edit)
		// arriving together with an unavailable widget. The available-only
		// projection [B, A] differs from persisted [A, B], so the drop must be
		// detected; the fallback writes the projection (no ghost).
		$GLOBALS['_post_meta'][123]['_elementor_data'] = wp_json_encode(
			[ [ 'id' => 'elA' ], [ 'id' => 'elB' ] ]
		);

		$result = $data->save_page_data(
			123,
			[
				[ 'id' => 'elB' ],
				[ 'id' => 'elA' ],
				[ 'id' => 'ghostX', 'elType' => 'widget', 'widgetType' => 'ghost-widget' ],
			]
		);

		$this->assertTrue( $result );
		$writes = array_values( array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		) );
		$this->assertNotEmpty( $writes, 'Reorder hidden behind an unavailable addition must still fall back.' );
		$written = json_decode( stripslashes( (string) ( $writes[0]['meta_value'] ?? '' ) ), true );
		$ids     = array_map( static fn( $el ) => $el['id'], $written );
		$this->assertSame( [ 'elB', 'elA' ], $ids, 'Fallback writes the sanitized projection: reorder applied, ghost stripped.' );
	}

	public function test_reassign_ids_remints_child_local_classes(): void {
		$data = new \Elementor_MCP_Data();
		$tree = [
			[
				'id'       => 'parent1',
				'elements' => [
					[
						'id'     => 'child22',
						'styles' => [
							'e-child22-cafe123' => [ 'id' => 'e-child22-cafe123', 'type' => 'class' ],
						],
					],
				],
			],
		];

		$out   = $data->reassign_ids( $tree );
		$child = $out[0]['elements'][0];
		$class = array_keys( $child['styles'] )[0];

		$this->assertStringStartsWith(
			'e-' . $child['id'] . '-',
			$class,
			'Children reassigned through reassign_ids() (duplicate + template import paths) must get re-minted local classes too.'
		);
	}

	public function test_normalizer_is_list_fallback_matches_native(): void {
		// Exercise the private fallback logic shape indirectly: both a list and
		// a map normalize correctly regardless of array_is_list availability.
		$this->assertSame( [ 'data' => [ 'a', 'b' ] ], \Elementor_MCP_Result_Normalizer::normalize( [ 'a', 'b' ] ) );
		$this->assertSame( [ 'k' => 'v' ], \Elementor_MCP_Result_Normalizer::normalize( [ 'k' => 'v' ] ) );
		// Non-zero-based keys are NOT a list — must pass through unwrapped.
		$this->assertSame( [ 1 => 'x' ], \Elementor_MCP_Result_Normalizer::normalize( [ 1 => 'x' ] ) );
	}
}
