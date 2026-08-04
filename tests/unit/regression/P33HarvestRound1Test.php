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
		$GLOBALS['_wp_meta_calls'] = [];
		$GLOBALS['_post_meta']     = [];
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
}
