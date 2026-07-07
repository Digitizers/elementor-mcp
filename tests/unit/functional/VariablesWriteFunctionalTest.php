<?php
/**
 * Functional — Variables write tools (list/get/create/edit/delete/restore) drive
 * a fake in-memory Elementor Variables Repository (declared in bootstrap.php)
 * end to end.
 *
 * @group functional
 * @group variables
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;
use Elementor\Modules\Variables\Storage\Repository;

class VariablesWriteFunctionalTest extends Ability_Test_Case {

	private \Elementor_MCP_Variables_Write_Abilities $ability;

	protected function setUp(): void {
		parent::setUp();
		Repository::__reset( array() );
		// is_available() requires the e_variables experiment + atomic support.
		$GLOBALS['_active_experiments'] = array( 'e_variables', 'e_atomic_elements' );
		// active_kit() resolves via kits_manager->get_active_kit(), which the
		// bootstrap stub reads from $GLOBALS['_active_kit'].
		$GLOBALS['_active_kit'] = new \stdClass();
		$data                   = $this->createStub( \Elementor_MCP_Data::class );
		$this->ability          = new \Elementor_MCP_Variables_Write_Abilities( $data );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_active_kit'], $GLOBALS['_has_pro'] );
		Repository::__reset( array() );
		parent::tearDown();
	}

	public function test_create_size_requires_pro(): void {
		$GLOBALS['_has_pro'] = false;
		$res = $this->ability->execute_create( array( 'label' => 'Gap', 'type' => 'size', 'value' => '24px' ) );
		$this->assertWPError( $res, 'requires_pro' );
		// color/font still fine on Free.
		$this->assertNotWPError( $this->ability->execute_create( array( 'label' => 'C', 'type' => 'color', 'value' => '#111111' ) ) );
	}

	public function test_non_pro_size_tokens_are_hidden_from_reads(): void {
		// A stored size token on a non-Pro site: Elementor hides it, so must we.
		Repository::__reset( array(
			'e-gv-sz'  => array( 'type' => 'global-size-variable', 'label' => 'Sz', 'value' => '24px', 'order' => 1 ),
			'e-gv-col' => array( 'type' => 'global-color-variable', 'label' => 'Col', 'value' => '#111111', 'order' => 2 ),
		) );
		$GLOBALS['_has_pro'] = false;

		$list = $this->ability->execute_list( array() );
		$this->assertSame( 1, $list['count'], 'size token hidden on non-Pro' );
		$this->assertSame( 'color', $list['variables'][0]['type'] );
		$this->assertWPError( $this->ability->execute_get( array( 'variable_id' => 'e-gv-sz' ) ), 'not_found' );

		// With Pro, the size token is visible again.
		$GLOBALS['_has_pro'] = true;
		$this->assertSame( 2, $this->ability->execute_list( array() )['count'] );
	}

	public function test_list_unwraps_prop_typed_array_values(): void {
		// Tokens created via Elementor's own manager store prop-typed ARRAY values.
		Repository::__reset( array(
			'e-gv-c' => array( 'type' => 'global-color-variable', 'label' => 'C', 'value' => array( '$$type' => 'color', 'value' => '#abcdef' ), 'order' => 1 ),
			'e-gv-s' => array( 'type' => 'global-size-variable', 'label' => 'S', 'value' => array( '$$type' => 'size', 'value' => array( 'size' => 24, 'unit' => 'px' ) ), 'order' => 2 ),
		) );
		$res    = $this->ability->execute_list( array() );
		$byId   = array();
		foreach ( $res['variables'] as $v ) {
			$byId[ $v['id'] ] = $v['value'];
		}
		$this->assertSame( '#abcdef', $byId['e-gv-c'], 'color prop array unwrapped to hex' );
		$this->assertSame( '24px', $byId['e-gv-s'], 'size prop array unwrapped to <n><unit>' );
	}

	/** @return array The stored raw record for an id. */
	private function stored( string $id ): array {
		return Repository::$store[ $id ];
	}

	/** Seed the raw store with N active records. */
	private function seed_active( int $n, string $prefix = 'e-gv-seed' ): void {
		$store = Repository::$store;
		for ( $i = 0; $i < $n; $i++ ) {
			$store[ "$prefix$i" ] = array( 'type' => 'global-color-variable', 'label' => "$prefix$i", 'value' => '#000000', 'order' => $i );
		}
		Repository::__reset( $store );
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	public function test_create_color_variable(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Primary', 'type' => 'color', 'value' => '#ff0000' ) );
		$this->assertNotWPError( $res );
		$this->assertTrue( $res['created'] );
		$this->assertStringStartsWith( 'e-gv-', $res['id'] );
		$this->assertSame( 'color', $res['type'] );

		$stored = $this->stored( $res['id'] );
		$this->assertSame( 'global-color-variable', $stored['type'] );
		$this->assertSame( '#ff0000', $stored['value'] );
	}

	public function test_create_size_dimension_is_size_type(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Gap', 'type' => 'size', 'value' => '24px' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( 'global-size-variable', $this->stored( $res['id'] )['type'] );
	}

	public function test_create_size_expression_uses_registered_size_type_and_custom_shape(): void {
		// Expressions store under the registered `global-size-variable` type (the
		// custom-size key is an adapter-internal alias, not a stored type), with
		// the value in the atomic custom-size shape so Elementor preserves it.
		$res = $this->ability->execute_create( array( 'label' => 'Fluid', 'type' => 'size', 'value' => 'clamp(1rem, 2vw, 3rem)' ) );
		$this->assertNotWPError( $res );
		$stored = $this->stored( $res['id'] );
		$this->assertSame( 'global-size-variable', $stored['type'] );
		$this->assertSame( array( '$$type' => 'size', 'value' => array( 'size' => 'clamp(1rem, 2vw, 3rem)', 'unit' => 'custom' ) ), $stored['value'] );
		// And it round-trips back to the plain expression on read.
		$this->assertSame( 'clamp(1rem, 2vw, 3rem)', $this->ability->execute_get( array( 'variable_id' => $res['id'] ) )['value'] );
	}

	public function test_create_plain_dimension_stays_a_string(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Gap', 'type' => 'size', 'value' => '24px' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( '24px', $this->stored( $res['id'] )['value'] );
	}

	public function test_create_font_variable(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Body', 'type' => 'font', 'value' => 'Inter' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( 'global-font-variable', $this->stored( $res['id'] )['type'] );
	}

	public function test_create_rejects_bad_type(): void {
		$res = $this->ability->execute_create( array( 'label' => 'X', 'type' => 'spacing', 'value' => '1px' ) );
		$this->assertWPError( $res, 'invalid_type' );
	}

	public function test_create_rejects_named_color(): void {
		$res = $this->ability->execute_create( array( 'label' => 'X', 'type' => 'color', 'value' => 'red' ) );
		$this->assertWPError( $res );
	}

	public function test_create_rejects_label_with_spaces(): void {
		$res = $this->ability->execute_create( array( 'label' => 'has space', 'type' => 'color', 'value' => '#111111' ) );
		$this->assertWPError( $res, 'invalid_variable' );
	}

	public function test_create_rejects_duplicate_label(): void {
		$this->assertNotWPError( $this->ability->execute_create( array( 'label' => 'Dup', 'type' => 'color', 'value' => '#111111' ) ) );
		// Case-insensitive uniqueness.
		$res = $this->ability->execute_create( array( 'label' => 'dup', 'type' => 'color', 'value' => '#222222' ) );
		$this->assertWPError( $res, 'label_not_unique' );
	}

	public function test_create_rejects_css_injecting_label(): void {
		// Label becomes a raw custom-property name in :root — must be ident-safe.
		foreach ( array( 'brand;color:red', 'brand}html{a', 'a:b', 'a{b' ) as $bad ) {
			$res = $this->ability->execute_create( array( 'label' => $bad, 'type' => 'color', 'value' => '#111111' ) );
			$this->assertWPError( $res, 'invalid_variable' );
		}
	}

	public function test_create_rejects_css_injecting_value(): void {
		// Font + size-expression values are emitted raw into a CSS declaration.
		$font = $this->ability->execute_create( array( 'label' => 'F', 'type' => 'font', 'value' => 'Arial;color:red' ) );
		$this->assertWPError( $font, 'invalid_value' );
		$size = $this->ability->execute_create( array( 'label' => 'S', 'type' => 'size', 'value' => 'calc(1px)}html{a:b' ) );
		$this->assertWPError( $size, 'invalid_value' );
	}

	public function test_create_rejects_css_comment_delimiters_in_value(): void {
		$open = $this->ability->execute_create( array( 'label' => 'F', 'type' => 'font', 'value' => 'Arial/*' ) );
		$this->assertWPError( $open, 'invalid_value' );
		$close = $this->ability->execute_create( array( 'label' => 'S', 'type' => 'size', 'value' => 'calc(1px)*/' ) );
		$this->assertWPError( $close, 'invalid_value' );
		// calc division/multiplication (single / or *) stays valid.
		$this->assertNotWPError( $this->ability->execute_create( array( 'label' => 'Div', 'type' => 'size', 'value' => 'calc(100% / 3)' ) ) );
	}

	public function test_create_rejects_at_limit(): void {
		$this->seed_active( 1000, 'e-gv-lim' );
		$res = $this->ability->execute_create( array( 'label' => 'OneTooMany', 'type' => 'color', 'value' => '#000000' ) );
		$this->assertWPError( $res, 'limit_reached' );
	}

	// -------------------------------------------------------------------------
	// list / get
	// -------------------------------------------------------------------------

	public function test_list_returns_active_variables_in_public_shape(): void {
		$this->ability->execute_create( array( 'label' => 'A', 'type' => 'color', 'value' => '#111111' ) );
		$this->ability->execute_create( array( 'label' => 'B', 'type' => 'size', 'value' => '8px' ) );

		$res = $this->ability->execute_list( array() );
		$this->assertNotWPError( $res );
		$this->assertSame( 2, $res['count'] );
		$types = array_column( $res['variables'], 'type' );
		sort( $types );
		$this->assertSame( array( 'color', 'size' ), $types );
	}

	public function test_get_returns_public_type_for_custom_size(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Fluid', 'type' => 'size', 'value' => 'calc(1rem + 1vw)' ) );
		$res     = $this->ability->execute_get( array( 'variable_id' => $created['id'] ) );
		$this->assertNotWPError( $res );
		// Internal custom-size collapses back to the public "size".
		$this->assertSame( 'size', $res['type'] );
	}

	public function test_get_missing_is_not_found(): void {
		$res = $this->ability->execute_get( array( 'variable_id' => 'e-gv-nope' ) );
		$this->assertWPError( $res, 'not_found' );
	}

	public function test_get_soft_deleted_is_not_found(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Gone', 'type' => 'color', 'value' => '#111111' ) );
		$this->ability->execute_delete( array( 'variable_id' => $created['id'] ) );
		$res = $this->ability->execute_get( array( 'variable_id' => $created['id'] ) );
		$this->assertWPError( $res, 'not_found' );
	}

	// -------------------------------------------------------------------------
	// edit
	// -------------------------------------------------------------------------

	public function test_edit_label_and_value(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Old', 'type' => 'color', 'value' => '#111111' ) );
		$id      = $created['id'];

		$res = $this->ability->execute_edit( array( 'variable_id' => $id, 'label' => 'New', 'value' => '#222222' ) );
		$this->assertNotWPError( $res );
		$this->assertTrue( $res['updated'] );
		$this->assertSame( 'New', $this->stored( $id )['label'] );
		$this->assertSame( '#222222', $this->stored( $id )['value'] );
	}

	public function test_edit_requires_a_field(): void {
		$created = $this->ability->execute_create( array( 'label' => 'X', 'type' => 'color', 'value' => '#111111' ) );
		$res     = $this->ability->execute_edit( array( 'variable_id' => $created['id'] ) );
		$this->assertWPError( $res, 'nothing_to_update' );
	}

	public function test_edit_rejects_invalid_new_label(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Ok', 'type' => 'color', 'value' => '#111111' ) );
		$spaces  = $this->ability->execute_edit( array( 'variable_id' => $created['id'], 'label' => 'has space' ) );
		$this->assertWPError( $spaces, 'invalid_variable' );
		$long = $this->ability->execute_edit( array( 'variable_id' => $created['id'], 'label' => str_repeat( 'x', 51 ) ) );
		$this->assertWPError( $long, 'invalid_variable' );
		$this->assertSame( 'Ok', $this->stored( $created['id'] )['label'] );
	}

	public function test_edit_missing_is_not_found(): void {
		$res = $this->ability->execute_edit( array( 'variable_id' => 'e-gv-nope', 'label' => 'X' ) );
		$this->assertWPError( $res, 'not_found' );
	}

	// -------------------------------------------------------------------------
	// delete (soft) / restore
	// -------------------------------------------------------------------------

	public function test_delete_sets_both_tombstone_flags_and_is_excluded_then_restored(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Temp', 'type' => 'color', 'value' => '#111111' ) );
		$id      = $created['id'];

		$del = $this->ability->execute_delete( array( 'variable_id' => $id ) );
		$this->assertNotWPError( $del );
		$this->assertTrue( $del['deleted'] );
		// Canonical tombstone: BOTH `deleted` and `deleted_at`, so every consumer
		// (which filter on `deleted`) sees it.
		$this->assertTrue( $this->stored( $id )['deleted'] );
		$this->assertArrayHasKey( 'deleted_at', $this->stored( $id ) );
		$this->assertSame( 0, $this->ability->execute_list( array() )['count'] );

		$restore = $this->ability->execute_restore( array( 'variable_id' => $id ) );
		$this->assertNotWPError( $restore );
		$this->assertTrue( $restore['restored'] );
		$this->assertArrayNotHasKey( 'deleted', $this->stored( $id ), 'restore clears the tombstone' );
		$this->assertArrayNotHasKey( 'deleted_at', $this->stored( $id ) );
		$this->assertSame( 1, $this->ability->execute_list( array() )['count'] );
	}

	public function test_deleted_at_only_tombstone_is_honored(): void {
		// A token deleted via Elementor's entity/service path can carry `deleted_at`
		// without a `deleted` flag — it must still count as tombstoned everywhere.
		Repository::__reset( array(
			'e-gv-old' => array( 'type' => 'global-color-variable', 'label' => 'Old', 'value' => '#111111', 'order' => 1, 'deleted_at' => '2025-01-01 00:00:00' ),
		) );
		$this->assertSame( 0, $this->ability->execute_list( array() )['count'], 'deleted_at-only token hidden from list' );
		$this->assertWPError( $this->ability->execute_get( array( 'variable_id' => 'e-gv-old' ) ), 'not_found' );
		// restore treats it as tombstoned (not a no-op) and clears it.
		$res = $this->ability->execute_restore( array( 'variable_id' => 'e-gv-old' ) );
		$this->assertNotWPError( $res );
		$this->assertArrayNotHasKey( 'already_active', $res );
		$this->assertSame( 1, $this->ability->execute_list( array() )['count'] );
	}

	public function test_create_reuses_label_of_deleted_at_only_tombstone(): void {
		// A token soft-deleted via Elementor's entity/service path carries only
		// `deleted_at`. Recreating its label must succeed (the raw Repository's own
		// dup check skips only `deleted === true`, so we normalize first).
		Repository::__reset( array(
			'e-gv-ghost' => array( 'type' => 'global-color-variable', 'label' => 'Ghost', 'value' => '#111111', 'order' => 1, 'deleted_at' => '2025-01-01 00:00:00' ),
		) );
		// Label of a deleted_at-only token is reusable.
		$res = $this->ability->execute_create( array( 'label' => 'Ghost', 'type' => 'color', 'value' => '#222222' ) );
		$this->assertNotWPError( $res );
		// The legacy tombstone was normalized to carry `deleted` too.
		$this->assertTrue( ! empty( Repository::$store['e-gv-ghost']['deleted'] ) );
	}

	public function test_delete_missing_is_not_found(): void {
		$res = $this->ability->execute_delete( array( 'variable_id' => 'e-gv-nope' ) );
		$this->assertWPError( $res, 'not_found' );
	}

	public function test_restore_rejects_when_label_now_taken(): void {
		$a = $this->ability->execute_create( array( 'label' => 'Shared', 'type' => 'color', 'value' => '#111111' ) );
		$this->ability->execute_delete( array( 'variable_id' => $a['id'] ) );
		$this->ability->execute_create( array( 'label' => 'Shared', 'type' => 'color', 'value' => '#222222' ) );

		$res = $this->ability->execute_restore( array( 'variable_id' => $a['id'] ) );
		$this->assertWPError( $res, 'label_not_unique' );
		$this->assertTrue( $this->stored( $a['id'] )['deleted'], 'stays deleted on rejected restore' );
	}

	public function test_restore_rejects_when_at_limit(): void {
		$victim = $this->ability->execute_create( array( 'label' => 'Victim', 'type' => 'color', 'value' => '#111111' ) );
		$this->ability->execute_delete( array( 'variable_id' => $victim['id'] ) );
		$this->seed_active( 1000, 'e-gv-fill' ); // fills the active set to the cap (keeps the deleted victim)

		$res = $this->ability->execute_restore( array( 'variable_id' => $victim['id'] ) );
		$this->assertWPError( $res, 'limit_reached' );
	}

	public function test_restore_already_active_is_idempotent_noop_even_at_cap(): void {
		$active = $this->ability->execute_create( array( 'label' => 'Live', 'type' => 'color', 'value' => '#111111' ) );
		$this->seed_active( 999, 'e-gv-pad' ); // 999 + the active target = 1000 at cap

		// Restoring an already-active token no-ops instead of hitting the cap.
		$res = $this->ability->execute_restore( array( 'variable_id' => $active['id'] ) );
		$this->assertNotWPError( $res );
		$this->assertTrue( $res['restored'] );
		$this->assertTrue( $res['already_active'] );
	}

	public function test_restore_non_pro_size_is_blocked(): void {
		Repository::__reset( array(
			'e-gv-sz' => array( 'type' => 'global-size-variable', 'label' => 'Sz', 'value' => '24px', 'order' => 1, 'deleted' => true, 'deleted_at' => '2025-01-01 00:00:00' ),
		) );
		$GLOBALS['_has_pro'] = false;
		$res = $this->ability->execute_restore( array( 'variable_id' => 'e-gv-sz' ) );
		$this->assertWPError( $res, 'requires_pro' );
	}

	public function test_edit_reuses_label_of_deleted_at_only_tombstone(): void {
		Repository::__reset( array(
			'e-gv-ghost'  => array( 'type' => 'global-color-variable', 'label' => 'Ghost', 'value' => '#111111', 'order' => 1, 'deleted_at' => '2025-01-01 00:00:00' ),
			'e-gv-active' => array( 'type' => 'global-color-variable', 'label' => 'Active', 'value' => '#222222', 'order' => 2 ),
		) );
		// Rename the active token to the hidden ghost's label — must succeed.
		$res = $this->ability->execute_edit( array( 'variable_id' => 'e-gv-active', 'label' => 'Ghost' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( 'Ghost', Repository::$store['e-gv-active']['label'] );
	}

	public function test_restore_missing_is_not_found(): void {
		$res = $this->ability->execute_restore( array( 'variable_id' => 'e-gv-nope' ) );
		$this->assertWPError( $res, 'not_found' );
	}
}
