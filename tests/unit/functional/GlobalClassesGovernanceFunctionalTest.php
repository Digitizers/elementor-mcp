<?php
/**
 * Functional — the REAL global-classes writer, run under governance, snapshots
 * the whole transaction at its repository write site (apply_change) and rolls
 * back a failed write. This exercises the writer's Elementor-specific resolution
 * (active kit id, the class→post id-map, and the pages a delete cascades to)
 * end to end, which the pure Governance unit tests simulate.
 *
 * @group functional
 * @group global-classes
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;
use Elementor\Modules\GlobalClasses\Global_Classes_Repository;

class GlobalClassesGovernanceFunctionalTest extends Ability_Test_Case {

	private \Elementor_MCP_Global_Classes_Write_Abilities $ability;

	protected function setUp(): void {
		parent::setUp();
		Global_Classes_Repository::__reset( array(), array() );
		$GLOBALS['_aura_snap']  = array(
			'fail_snapshot'  => false,
			'fail_restore'   => false,
			'snapshot_calls' => array(),
			'restore_calls'  => array(),
			'seq'            => 0,
		);
		$GLOBALS['_aura_grant'] = array( 'enforced' => false, 'verify_result' => true, 'verify_calls' => array() );
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']   = false;
		$GLOBALS['_post_meta']           = array();
		$GLOBALS['_gc_relations']        = array();
		unset( $GLOBALS['_gc_relations_throw'], $GLOBALS['_active_kit'] );
		\Elementor_MCP_Governance::reset_state();

		$data          = $this->createStub( \Elementor_MCP_Data::class );
		$this->ability = new \Elementor_MCP_Global_Classes_Write_Abilities( $data );
	}

	protected function tearDown(): void {
		\Elementor_MCP_Governance::reset_state();
		unset( $GLOBALS['_active_kit'], $GLOBALS['_gc_relations_throw'] );
		parent::tearDown();
	}

	/** Point the stub kits_manager at an active kit with the given id. */
	private function set_active_kit( int $id ): void {
		$GLOBALS['_active_kit'] = new class( $id ) {
			private int $id;
			public function __construct( int $id ) {
				$this->id = $id; }
			public function get_id() {
				return $this->id; }
		};
	}

	/** Seed one existing class in the repo store + its kit id-map + relations. */
	private function seed_class( string $class_id, int $kit_id, int $post_id, array $pages ): void {
		Global_Classes_Repository::__reset(
			array( $class_id => array( 'id' => $class_id, 'type' => 'class', 'label' => 'card', 'variants' => array() ) ),
			array( $class_id )
		);
		$this->set_active_kit( $kit_id );
		$GLOBALS['_post_meta'][ $kit_id ]['_elementor_global_classes_post_ids'] = array( $class_id => $post_id );
		$GLOBALS['_gc_relations'][ $class_id ]                                  = $pages;
	}

	/** Run an ability's execute callback under a governed (design-token) run. */
	private function run_governed( string $name, string $method, array $input ) {
		return \Elementor_MCP_Governance::run_governed( $name, array( $this->ability, $method ), $input, false, true );
	}

	public function test_delete_snapshots_kit_class_post_and_cascade_pages(): void {
		$this->seed_class( 'g-aaaaaa1', 7, 333, array( 501, 502 ) );

		$result = $this->run_governed( 'elementor-mcp/delete-global-class', 'execute_delete', array( 'class_id' => 'g-aaaaaa1' ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$call = $GLOBALS['_aura_snap']['snapshot_calls'][0];
		$this->assertSame( array( 7, 333, 501, 502 ), $call['post_ids'], 'kit + class CPT post + cascade pages.' );
		$this->assertSame( \Elementor_MCP_Governance::GC_SNAPSHOT_META_KEYS, $call['keys'] );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
		// The delete actually landed in the store.
		$this->assertArrayNotHasKey( 'g-aaaaaa1', Global_Classes_Repository::$store_items );
	}

	public function test_failed_repo_write_is_rolled_back(): void {
		$this->seed_class( 'g-aaaaaa1', 7, 333, array( 501 ) );
		Global_Classes_Repository::$fail_write = true; // apply_changes() throws

		$result = $this->run_governed( 'elementor-mcp/delete-global-class', 'execute_delete', array( 'class_id' => 'g-aaaaaa1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'], 'Snapshotted before the write that then failed.' );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'], 'A failed GC write is rolled back.' );
	}

	public function test_create_snapshots_only_the_kit_when_no_prior_class_posts(): void {
		// A create touches no existing class post and cascades to no page, so only
		// the kit anchor is captured (its id-map revert drops the new class on
		// rollback). Verifies the writer resolves an empty class/page set cleanly.
		$this->set_active_kit( 7 );

		$result = $this->run_governed(
			'elementor-mcp/create-global-class',
			'execute_create',
			array( 'label' => 'hero', 'styles' => array( 'color' => '#111111' ) )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['created'] );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( array( 7 ), $GLOBALS['_aura_snap']['snapshot_calls'][0]['post_ids'] );
	}

	public function test_delete_fails_closed_when_cascade_pages_cannot_be_resolved(): void {
		// The relations index throws → we cannot know which pages the delete rewrites
		// → refuse (no snapshot, no repo mutation) rather than an unreversible cascade.
		$this->seed_class( 'g-aaaaaa1', 7, 333, array( 501 ) );
		$GLOBALS['_gc_relations_throw'] = true;

		$result = $this->run_governed( 'elementor-mcp/delete-global-class', 'execute_delete', array( 'class_id' => 'g-aaaaaa1' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_snapshot_failed', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertArrayHasKey( 'g-aaaaaa1', Global_Classes_Repository::$store_items, 'The delete was refused before the repo write.' );
	}

	public function test_ungoverned_write_proceeds_without_snapshot(): void {
		// No governed run in flight (standalone / SiteAgent absent path): the gate is
		// a no-op and the write proceeds normally.
		$this->seed_class( 'g-aaaaaa1', 7, 333, array( 501 ) );

		$result = $this->ability->execute_delete( array( 'class_id' => 'g-aaaaaa1' ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['snapshot_calls'] );
	}
}
