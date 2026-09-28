<?php
/**
 * Regression — P7.1: three silent data-loss bugs EMCP 3.17.1 fixed and this
 * fork shared (references reverification-2026-09-28 §5).
 *
 * 1. save_page_settings() handed Document::save() the raw patch. Elementor's
 *    page-settings manager writes `_elementor_page_settings` verbatim, so a
 *    one-key patch deleted every other stored setting (EMCP #145).
 * 2. update-global-colors upserted the system slot ids into custom_colors: a
 *    shadow entry that never changed the real color, reported as success
 *    (EMCP #145).
 * 3. Numeric classic dimension sides rendered correctly but showed 0 in the
 *    editor's Layout panel (EMCP #146). Normalised only on elements a write
 *    adds or changes, so an unrelated edit never rewrites a stored node.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class P71DataLossFixesTest extends TestCase {

	/** @var array[] Data arrays passed to the mock Document::save(). */
	private $saved = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_meta_calls'] = array();
		$GLOBALS['_post_meta']     = array();
		$this->saved               = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_post_meta'] );
		parent::tearDown();
	}

	private function inject_document_returning( $return_value ): void {
		$saved    = &$this->saved;
		$mock_doc = new class( $return_value, $saved ) {
			private $ret;
			private $saved;
			public function __construct( $ret, array &$saved ) {
				$this->ret   = $ret;
				$this->saved = &$saved;
			}
			public function save( array $data ) {
				$this->saved[] = $data;
				return $this->ret;
			}
			public function get_settings(): array {
				return array();
			}
		};
		\Elementor\Plugin::$instance->documents = new class( $mock_doc ) {
			private $doc;
			public function __construct( $doc ) {
				$this->doc = $doc;
			}
			public function get( int $post_id ) {
				return $this->doc;
			}
		};
	}

	private function meta_writes( string $key ): array {
		return array_values(
			array_filter(
				$GLOBALS['_wp_meta_calls'],
				static function ( $c ) use ( $key ) {
					return 'update' === $c['action'] && $key === $c['meta_key'];
				}
			)
		);
	}

	// ---------------------------------------------------------------- 1 ----

	public function test_page_settings_patch_is_merged_before_the_native_save(): void {
		$GLOBALS['_post_meta'][7]['_elementor_page_settings'] = array(
			'custom_colors'    => array( array( '_id' => 'brand', 'color' => '#123456' ) ),
			'background_color' => '#000000',
		);
		$this->inject_document_returning( true );

		$result = ( new \Elementor_MCP_Data() )->save_page_settings( 7, array( 'background_color' => '#ffffff', 'padding' => '20px' ) );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->saved );
		$this->assertSame(
			array(
				'custom_colors'    => array( array( '_id' => 'brand', 'color' => '#123456' ) ),
				'background_color' => '#ffffff',
				'padding'          => '20px',
			),
			$this->saved[0]['settings'],
			'Elementor writes the settings it is handed verbatim: anything not in them is deleted.'
		);
	}

	public function test_page_settings_fallback_writes_the_same_merged_settings(): void {
		$GLOBALS['_post_meta'][7]['_elementor_page_settings'] = array( 'a' => '1' );
		$this->inject_document_returning( null );

		( new \Elementor_MCP_Data() )->save_page_settings( 7, array( 'b' => '2' ) );

		$writes = $this->meta_writes( '_elementor_page_settings' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'a' => '1', 'b' => '2' ), $writes[0]['meta_value'] );
		$this->assertSame( array( 'a' => '1', 'b' => '2' ), $this->saved[0]['settings'], 'Both paths write the same thing.' );
	}

	public function test_page_settings_on_a_page_with_none_stored_is_the_patch(): void {
		$this->inject_document_returning( true );

		( new \Elementor_MCP_Data() )->save_page_settings( 7, array( 'b' => '2' ) );

		$this->assertSame( array( 'b' => '2' ), $this->saved[0]['settings'] );
	}

	// ---------------------------------------------------------------- 2 ----

	public function test_system_color_ids_are_refused_before_anything_is_read_or_written(): void {
		$result = ( new \Elementor_MCP_Global_Abilities( new \Elementor_MCP_Data() ) )->execute_update_global_colors(
			array(
				'colors' => array(
					array( '_id' => 'brand_teal', 'title' => 'Teal', 'color' => '#00aaaa' ),
					array( '_id' => 'primary', 'title' => 'Primary', 'color' => '#ff0000' ),
					array( '_id' => 'accent', 'title' => 'Accent', 'color' => '#00ff00' ),
					array( '_id' => 'primary', 'title' => 'Again', 'color' => '#ff0001' ),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'A mixed call is refused whole: writing the rest and reporting success is the bug.' );
		$this->assertSame( 'reserved_color_id', $result->get_error_code() );
		$this->assertSame( array( 'reserved' => array( 'primary', 'accent' ) ), $result->get_error_data() );
		$this->assertStringContainsString( 'replace-system-colors', $result->get_error_message() );
	}

	public function test_every_system_slot_is_reserved(): void {
		foreach ( array( 'primary', 'secondary', 'text', 'accent' ) as $id ) {
			$result = ( new \Elementor_MCP_Global_Abilities( new \Elementor_MCP_Data() ) )->execute_update_global_colors(
				array( 'colors' => array( array( '_id' => $id, 'title' => 'x', 'color' => '#000000' ) ) )
			);
			$this->assertSame( 'reserved_color_id', $result->get_error_code(), $id );
		}
	}

	// ---------------------------------------------------------------- 3 ----

	public function test_numeric_dimension_sides_become_strings(): void {
		$out = \Elementor_MCP_Element_Factory::normalize_dimension_settings(
			array(
				'padding'         => array( 'unit' => 'px', 'top' => 40, 'right' => 0, 'bottom' => 12.5, 'left' => '8', 'isLinked' => false ),
				'flex_gap'        => array( 'unit' => 'px', 'column' => 20, 'row' => 10 ),
				'typography_size' => array( 'unit' => 'px', 'size' => 18 ),
				'title'           => 'Hello',
			)
		);

		$this->assertSame( array( 'unit' => 'px', 'top' => '40', 'right' => '0', 'bottom' => '12.5', 'left' => '8', 'isLinked' => false ), $out['padding'] );
		$this->assertSame( array( 'unit' => 'px', 'column' => '20', 'row' => '10' ), $out['flex_gap'] );
		$this->assertSame( 18, $out['typography_size']['size'], 'A slider size stays numeric, as the editor keeps it.' );
		$this->assertSame( 'Hello', $out['title'] );
	}

	public function test_atomic_props_and_repeaters(): void {
		$atomic = array( '$$type' => 'dimensions', 'unit' => 'px', 'top' => 4 );
		$out    = \Elementor_MCP_Element_Factory::normalize_dimension_settings(
			array(
				'atomic' => $atomic,
				'slides' => array( array( 'padding' => array( 'unit' => 'em', 'top' => 2 ) ) ),
			)
		);

		$this->assertSame( $atomic, $out['atomic'], 'An atomic $$type value has its own shape and is never rewritten.' );
		$this->assertSame( '2', $out['slides'][0]['padding']['top'], 'Repeater items are walked.' );
	}

	public function test_tree_normalises_only_elements_the_write_adds_or_changes(): void {
		$before = array(
			array( 'id' => 'keep', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 5 ) ), 'elements' => array() ),
			array( 'id' => 'edit', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 5 ) ), 'elements' => array() ),
		);
		$after = array(
			array(
				'id'       => 'keep',
				'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 5 ) ),
				'elements' => array(
					array( 'id' => 'new', 'settings' => array( 'margin' => array( 'unit' => 'px', 'top' => 7 ) ) ),
				),
			),
			array( 'id' => 'edit', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 9 ) ), 'elements' => array() ),
		);

		$out = \Elementor_MCP_Element_Factory::normalize_dimension_tree( $after, \Elementor_MCP_Element_Factory::settings_by_id( $before ) );

		$this->assertSame( 5, $out[0]['settings']['padding']['top'], 'An untouched element keeps what it stored: rewriting it is collateral.' );
		$this->assertSame( '7', $out[0]['elements'][0]['settings']['margin']['top'], 'A new child is normalised, under an untouched parent.' );
		$this->assertSame( '9', $out[1]['settings']['padding']['top'], 'A changed element is normalised.' );
	}

	public function test_a_duplicated_stored_id_confers_no_untouched_status(): void {
		$node   = array( 'id' => 'dup', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 3 ) ) );
		$before = \Elementor_MCP_Element_Factory::settings_by_id( array( $node, $node ) );

		$this->assertArrayHasKey( 'dup', $before );
		$this->assertNull( $before['dup'] );
		$out = \Elementor_MCP_Element_Factory::normalize_dimension_tree( array( $node ), $before );
		$this->assertSame( '3', $out[0]['settings']['padding']['top'] );
	}

	public function test_save_page_data_hands_elementor_the_normalised_tree(): void {
		$stored = array(
			array( 'id' => 'a1', 'elType' => 'container', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 5 ) ), 'elements' => array() ),
			array( 'id' => 'b2', 'elType' => 'container', 'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => 5 ) ), 'elements' => array() ),
		);
		$GLOBALS['_post_meta'][9]['_elementor_data'] = wp_json_encode( $stored );
		$this->inject_document_returning( true );

		$requested                                   = $stored;
		$requested[1]['settings']['padding']['top']  = 30;
		( new \Elementor_MCP_Data() )->save_page_data( 9, $requested );

		$this->assertNotEmpty( $this->saved );
		$handed = $this->saved[0]['elements'];
		$this->assertSame( 5, $handed[0]['settings']['padding']['top'], 'The untouched container is handed over exactly as stored.' );
		$this->assertSame( '30', $handed[1]['settings']['padding']['top'], 'The edited container carries the string the editor reads.' );
	}
}
