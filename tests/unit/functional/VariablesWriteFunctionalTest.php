<?php
/**
 * Functional — Variables write tools (list/get/create/edit/delete/restore) drive
 * a fake in-memory Variables_Repository/Collection/Variable (declared in
 * bootstrap.php) end to end.
 *
 * @group functional
 * @group variables
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;
use Elementor\Modules\Variables\Storage\Variables_Repository;

class VariablesWriteFunctionalTest extends Ability_Test_Case {

	private \Elementor_MCP_Variables_Write_Abilities $ability;

	protected function setUp(): void {
		parent::setUp();
		Variables_Repository::__reset( array() );
		// active_kit() resolves via kits_manager->get_active_kit(), which the
		// bootstrap stub reads from $GLOBALS['_active_kit'].
		$GLOBALS['_active_kit'] = new \stdClass();
		$data                   = $this->createStub( \Elementor_MCP_Data::class );
		$this->ability          = new \Elementor_MCP_Variables_Write_Abilities( $data );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_active_kit'] );
		Variables_Repository::__reset( array() );
		parent::tearDown();
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

		$stored = Variables_Repository::$store[ $res['id'] ];
		$this->assertSame( 'global-color-variable', $stored->type() );
		$this->assertSame( '#ff0000', $stored->value() );
	}

	public function test_create_size_dimension_is_size_type(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Gap', 'type' => 'size', 'value' => '24px' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( 'global-size-variable', Variables_Repository::$store[ $res['id'] ]->type() );
	}

	public function test_create_size_expression_is_custom_size_type(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Fluid', 'type' => 'size', 'value' => 'clamp(1rem, 2vw, 3rem)' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( 'global-custom-size-variable', Variables_Repository::$store[ $res['id'] ]->type() );
	}

	public function test_create_font_variable(): void {
		$res = $this->ability->execute_create( array( 'label' => 'Body', 'type' => 'font', 'value' => 'Inter' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( 'global-font-variable', Variables_Repository::$store[ $res['id'] ]->type() );
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

	public function test_create_rejects_at_limit(): void {
		$store = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$store[ "e-gv-lim$i" ] = \Elementor\Modules\Variables\Storage\Entities\Variable::create_new(
				array( 'id' => "e-gv-lim$i", 'type' => 'global-color-variable', 'label' => "c$i", 'value' => '#000000', 'order' => $i )
			);
		}
		Variables_Repository::__reset( $store );

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
		// Consistent with list-variables hiding it.
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
		$this->assertSame( 'New', Variables_Repository::$store[ $id ]->label() );
		$this->assertSame( '#222222', Variables_Repository::$store[ $id ]->value() );
	}

	public function test_edit_size_value_flips_internal_type_to_custom(): void {
		$created = $this->ability->execute_create( array( 'label' => 'S', 'type' => 'size', 'value' => '10px' ) );
		$id      = $created['id'];
		$this->assertSame( 'global-size-variable', Variables_Repository::$store[ $id ]->type() );

		$this->ability->execute_edit( array( 'variable_id' => $id, 'value' => 'clamp(1rem, 2vw, 3rem)' ) );
		$this->assertSame( 'global-custom-size-variable', Variables_Repository::$store[ $id ]->type() );
	}

	public function test_edit_rejects_invalid_new_label(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Ok', 'type' => 'color', 'value' => '#111111' ) );
		// apply_changes() validates the OLD label first, so the new one must be
		// pre-validated by our edit path.
		$spaces = $this->ability->execute_edit( array( 'variable_id' => $created['id'], 'label' => 'has space' ) );
		$this->assertWPError( $spaces, 'invalid_variable' );
		$long = $this->ability->execute_edit( array( 'variable_id' => $created['id'], 'label' => str_repeat( 'x', 51 ) ) );
		$this->assertWPError( $long, 'invalid_variable' );
		// Original label untouched.
		$this->assertSame( 'Ok', Variables_Repository::$store[ $created['id'] ]->label() );
	}

	public function test_restore_rejects_when_label_now_taken(): void {
		$a  = $this->ability->execute_create( array( 'label' => 'Shared', 'type' => 'color', 'value' => '#111111' ) );
		$this->ability->execute_delete( array( 'variable_id' => $a['id'] ) );
		// A new active variable reuses the freed label.
		$this->ability->execute_create( array( 'label' => 'Shared', 'type' => 'color', 'value' => '#222222' ) );

		$res = $this->ability->execute_restore( array( 'variable_id' => $a['id'] ) );
		$this->assertWPError( $res, 'label_not_unique' );
		$this->assertTrue( Variables_Repository::$store[ $a['id'] ]->is_deleted(), 'stays deleted on rejected restore' );
	}

	public function test_restore_rejects_when_at_limit(): void {
		$victim = $this->ability->execute_create( array( 'label' => 'Victim', 'type' => 'color', 'value' => '#111111' ) );
		$this->ability->execute_delete( array( 'variable_id' => $victim['id'] ) );
		// Fill the active set to the cap while the victim is soft-deleted.
		$store = Variables_Repository::$store;
		for ( $i = 0; $i < 1000; $i++ ) {
			$store[ "e-gv-fill$i" ] = \Elementor\Modules\Variables\Storage\Entities\Variable::create_new(
				array( 'id' => "e-gv-fill$i", 'type' => 'global-color-variable', 'label' => "fill$i", 'value' => '#000000', 'order' => $i )
			);
		}
		Variables_Repository::__reset( $store );

		$res = $this->ability->execute_restore( array( 'variable_id' => $victim['id'] ) );
		$this->assertWPError( $res, 'limit_reached' );
	}

	public function test_edit_requires_a_field(): void {
		$created = $this->ability->execute_create( array( 'label' => 'X', 'type' => 'color', 'value' => '#111111' ) );
		$res     = $this->ability->execute_edit( array( 'variable_id' => $created['id'] ) );
		$this->assertWPError( $res, 'nothing_to_update' );
	}

	// -------------------------------------------------------------------------
	// delete (soft) / restore
	// -------------------------------------------------------------------------

	public function test_delete_is_soft_and_excluded_from_list_then_restored(): void {
		$created = $this->ability->execute_create( array( 'label' => 'Temp', 'type' => 'color', 'value' => '#111111' ) );
		$id      = $created['id'];

		$del = $this->ability->execute_delete( array( 'variable_id' => $id ) );
		$this->assertNotWPError( $del );
		$this->assertTrue( $del['deleted'] );
		$this->assertTrue( Variables_Repository::$store[ $id ]->is_deleted(), 'soft delete keeps the record' );
		$this->assertSame( 0, $this->ability->execute_list( array() )['count'], 'deleted variable excluded from list' );

		$restore = $this->ability->execute_restore( array( 'variable_id' => $id ) );
		$this->assertNotWPError( $restore );
		$this->assertTrue( $restore['restored'] );
		$this->assertFalse( Variables_Repository::$store[ $id ]->is_deleted() );
		$this->assertSame( 1, $this->ability->execute_list( array() )['count'], 'restored variable back in list' );
	}

	public function test_delete_missing_is_not_found(): void {
		$res = $this->ability->execute_delete( array( 'variable_id' => 'e-gv-nope' ) );
		$this->assertWPError( $res, 'not_found' );
	}
}
