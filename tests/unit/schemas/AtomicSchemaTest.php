<?php
/**
 * P1.1 atomic schema-in-error: a bad-settings rejection (save_rejected) on an
 * atomic write carries the target atomic type's compact prop schema, distilled
 * from Elementor's own static get_props_schema().
 *
 * @group schemas
 * @group schema-in-error
 * @package Elementor_MCP\Tests\Schemas
 */

namespace Elementor_MCP\Tests\Schemas;

use PHPUnit\Framework\TestCase;

/** Minimal Prop_Type double: JsonSerializable → { key, settings:{enum?} }. */
class StubProp implements \JsonSerializable {
	public function __construct( private string $key, private array $enum = array() ) {}
	public function jsonSerialize(): mixed {
		$settings = array();
		if ( ! empty( $this->enum ) ) {
			$settings['enum'] = $this->enum;
		}
		return array( 'key' => $this->key, 'settings' => $settings );
	}
}

/** Minimal atomic widget double exposing the static get_props_schema() contract. */
class StubAtomicHeading {
	public static function get_props_schema(): array {
		return array(
			'classes' => new StubProp( 'classes' ),
			'tag'     => new StubProp( 'string', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ),
			'title'   => new StubProp( 'html' ),
			'link'    => new StubProp( 'link' ),
		);
	}
}

class AtomicSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_widget_types'] = array( 'e-heading' => new StubAtomicHeading() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_widget_types'] );
		parent::tearDown();
	}

	public function test_schema_for_distills_type_and_enum(): void {
		$schema = \Elementor_MCP_Atomic_Props::schema_for( 'e-heading' );

		$this->assertSame( 'string', $schema['tag']['type'] );
		$this->assertSame( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), $schema['tag']['enum'] );
		$this->assertSame( 'html', $schema['title']['type'] );
		$this->assertSame( 'classes', $schema['classes']['type'] );
		$this->assertArrayNotHasKey( 'enum', $schema['title'], 'Non-enum props carry no enum.' );
	}

	public function test_schema_for_unknown_type_is_empty(): void {
		$this->assertSame( array(), \Elementor_MCP_Atomic_Props::schema_for( 'e-nope' ) );
	}

	public function test_enrich_attaches_schema_to_save_rejected(): void {
		$err = new \WP_Error( 'save_rejected', 'Elementor rejected the element data: bad tag.' );

		$out = \Elementor_MCP_Atomic_Props::enrich_save_rejection( $err, 'e-heading' );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'save_rejected', $out->get_error_code() );
		$data = $out->get_error_data();
		$this->assertSame( 'e-heading', $data['widget_type'] );
		$this->assertSame( 'string', $data['schema']['tag']['type'] );
	}

	public function test_enrich_leaves_other_error_codes_unchanged(): void {
		$err = new \WP_Error( 'not_found', 'nope' );
		$this->assertSame( $err, \Elementor_MCP_Atomic_Props::enrich_save_rejection( $err, 'e-heading' ) );
	}

	public function test_enrich_leaves_non_errors_unchanged(): void {
		$ok = array( 'element_id' => 'abc' );
		$this->assertSame( $ok, \Elementor_MCP_Atomic_Props::enrich_save_rejection( $ok, 'e-heading' ) );
	}

	public function test_enrich_leaves_save_rejected_unchanged_when_no_schema(): void {
		$err = new \WP_Error( 'save_rejected', 'rejected' );
		// Unknown type → no schema → return the original error object untouched.
		$this->assertSame( $err, \Elementor_MCP_Atomic_Props::enrich_save_rejection( $err, 'e-nope' ) );
	}
}
