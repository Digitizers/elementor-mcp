<?php
/**
 * Functional — save_page_data() feeds the collateral verdict with the real
 * three trees: the page as stored before the save, what the tool asked for,
 * and the page as stored after — on the native save path and the fallback
 * path alike (P5.1, elementor-mcp#67).
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

/** A typed prop that advertises `content` as an alias of its canonical name. */
class Collateral_Aliased_Prop {
	public function get_key(): string {
		return 'string';
	}
	public function get_meta_item( string $item ) {
		return 'aliases' === $item ? array( 'content' ) : null;
	}
	public function validate( $value ): bool {
		return is_array( $value ) && 'string' === ( $value['$$type'] ?? '' ) && is_string( $value['value'] ?? null );
	}
}

/** An atomic widget whose schema aliases `content` onto `text`. */
class Collateral_Aliased_Widget {
	public static function get_props_schema(): array {
		return array( 'text' => new Collateral_Aliased_Prop() );
	}
}

class SavePageDataCollateralTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_snap'] = array( 'fail_snapshot' => false, 'fail_restore' => false, 'snapshot_calls' => array(), 'restore_calls' => array(), 'seq' => 0 );
		$GLOBALS['_aura_grant'] = array( 'enforced' => false, 'verify_result' => true, 'verify_calls' => array() );
		$GLOBALS['_aura_rules'] = array( 'verdict' => array( 'effect' => null ), 'calls' => array(), 'current' => null, 'throw' => false );
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']   = false;
		$GLOBALS['_actions_fired']       = array();
		$GLOBALS['_wp_meta_calls']       = array();
		$GLOBALS['_widget_types']        = array(
			'heading'                    => new \stdClass(), // available, no schema → no coercion
			'emcp-collateral-aliased' => new Collateral_Aliased_Widget(),
		);
		$GLOBALS['_post_meta'][55]       = array( '_elementor_data' => wp_json_encode( $this->page() ) );
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_aura_rules'], $GLOBALS['_widget_types'], $GLOBALS['_post_meta'][55] );
		\Elementor\Plugin::$instance->documents = new class {
			public function get( int $post_id ) {
				return null;
			}
		};
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
		parent::tearDown();
	}

	private function page(): array {
		return array(
			array( 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'One' ), 'elements' => array() ),
			array( 'id' => 'h2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Two' ), 'elements' => array() ),
		);
	}

	/**
	 * A document whose save() behaves like Elementor's: it persists the tree it
	 * was handed — after $mutate, which stands in for whatever the save pipeline
	 * does to it — and returns $return.
	 */
	private function inject_document( $return, ?callable $mutate = null ): void {
		$doc = new class( $return, $mutate ) {
			private $ret;
			private $mutate;
			public function __construct( $ret, $mutate ) {
				$this->ret    = $ret;
				$this->mutate = $mutate;
			}
			public function save( array $data ) {
				if ( $this->ret ) {
					$tree = $data['elements'];
					if ( $this->mutate ) {
						$tree = call_user_func( $this->mutate, $tree );
					}
					$GLOBALS['_post_meta'][55]['_elementor_data'] = wp_json_encode( $tree );
				}
				return $this->ret;
			}
			public function get_settings(): array {
				return array();
			}
		};
		\Elementor\Plugin::$instance->documents = new class( $doc ) {
			private $doc;
			public function __construct( $doc ) {
				$this->doc = $doc;
			}
			public function get( int $post_id ) {
				return $this->doc;
			}
		};
	}

	private function governed_save( array $requested ) {
		return \Elementor_MCP_Governance::run_governed(
			'elementor-mcp/update-element',
			static function ( $input ) use ( $requested ) {
				$data = new \Elementor_MCP_Data();
				$r    = $data->save_page_data( (int) $input['post_id'], $requested );
				return is_wp_error( $r ) ? $r : array( 'saved' => $r );
			},
			array( 'post_id' => 55 )
		);
	}

	public function test_native_save_that_damages_an_untargeted_node_is_reported(): void {
		// The tool changes h1; Elementor's save (stood in for here) also empties h2.
		$this->inject_document( true, static function ( array $tree ) {
			$tree[1]['settings']['title'] = '';
			return $tree;
		} );
		$requested = $this->page();
		$requested[0]['settings']['title'] = 'Uno';

		$result = $this->governed_save( $requested );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['saved'] );
		$this->assertSame( 'collateral', $result['warnings'][0]['rule'] );
		$this->assertStringContainsString( 'heading h2 (changed)', $result['warnings'][0]['reason'] );
	}

	public function test_native_save_that_persists_exactly_what_was_asked_is_clean(): void {
		$this->inject_document( true );
		$requested = $this->page();
		$requested[0]['settings']['title'] = 'Uno';

		$result = $this->governed_save( $requested );

		$this->assertSame( array( 'saved' => true ), $result );
	}

	public function test_native_save_that_drops_a_requested_setting_is_reported_as_not_landed(): void {
		$this->inject_document( true, static function ( array $tree ) {
			unset( $tree[0]['settings']['custom_css'] ); // the save silently discards it
			return $tree;
		} );
		$requested = $this->page();
		$requested[0]['settings']['custom_css'] = 'h1{color:red}';

		$result = $this->governed_save( $requested );

		$this->assertSame( 'collateral', $result['warnings'][0]['rule'] );
		$this->assertStringContainsString( 'h1: custom_css', $result['warnings'][0]['reason'] );
	}

	public function test_an_alias_the_coercion_renamed_is_not_reported_as_a_dropped_setting(): void {
		// End to end through the real coerce_tree(): the tool writes `content`,
		// the coercion renames it onto `text` and drops the alias key, Elementor
		// persists that. Judged on the pre-coercion keys this reads as a dropped
		// setting and, in refuse mode, reverts a perfectly good write (Codex
		// round-1 P2).
		$page = array(
			array( 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'emcp-collateral-aliased', 'settings' => array(), 'elements' => array() ),
		);
		$GLOBALS['_post_meta'][55]['_elementor_data'] = wp_json_encode( $page );
		$this->inject_document( true );

		$requested = $page;
		$requested[0]['settings']['content'] = 'Body';

		$result = $this->governed_save( $requested );

		$this->assertSame( array( 'saved' => true ), $result, 'No warnings: the alias landed under its canonical name.' );
		$persisted = json_decode( $GLOBALS['_post_meta'][55]['_elementor_data'], true );
		$this->assertArrayHasKey( 'text', $persisted[0]['settings'], 'The coercion really did rename it.' );
		$this->assertArrayNotHasKey( 'content', $persisted[0]['settings'] );
	}

	public function test_fallback_path_is_judged_too(): void {
		// Document::save() returns null (non-browser context) → direct meta write.
		// The bootstrap's update_post_meta() only logs, so the re-read still sees
		// the pre-save page: the target's requested keys all exist there and no
		// untargeted node changed — the fallback write is judged, and is clean.
		$this->inject_document( null );
		$requested = $this->page();
		$requested[0]['settings']['title'] = 'Uno';

		$result = $this->governed_save( $requested );

		$this->assertSame( array( 'saved' => true ), $result );
		$this->assertContains( '_elementor_data', array_column( $GLOBALS['_wp_meta_calls'], 'meta_key' ), 'The fallback wrote the meta.' );
	}
}
