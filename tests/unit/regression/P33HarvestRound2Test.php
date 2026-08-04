<?php
/**
 * Unit tests — P3.3 harvest round 2 (upstream 3.x atomic-correctness core).
 *
 * Covers the two mechanisms ported from upstream in this round:
 *  - Whole-tree atomic prop coercion (upstream #101/#102): raw scalar values
 *    written into typed v4 props are wrapped into the $$type envelope their
 *    prop type accepts, using Elementor's own validate() as the oracle. The
 *    sweep runs over the WHOLE tree on save, so any write repairs a page a
 *    previous raw write poisoned.
 *  - Alias prop mapping (upstream #102): alias keys Elementor's widgets
 *    advertise (e-heading `title` accepts `text`/`content`/...) are renamed
 *    onto the canonical prop before Elementor's Props_Parser would silently
 *    delete them; a canonical value always wins.
 *
 * Plus the save_page_data() integration: coercion runs before the native
 * save (and before the governance snapshot / pre-save capture), and never
 * changes element ids or structure — so the round-1 projection verification
 * compares like against like.
 *
 * @group unit
 * @group regression
 * @package Elementor_MCP\Tests
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * Prop-type stub accepting exactly one envelope key with a scalar value of a
 * given PHP type. Mirrors the surface coercion relies on: validate() + get_key().
 */
class R2_Envelope_Prop {
	private $key;
	private $php_type;

	public function __construct( string $key, string $php_type = 'string' ) {
		$this->key      = $key;
		$this->php_type = $php_type;
	}

	public function get_key(): string {
		return $this->key;
	}

	public function validate( $value ): bool {
		if ( ! is_array( $value ) || ( $value['$$type'] ?? '' ) !== $this->key ) {
			return false;
		}
		$inner = $value['value'] ?? null;
		switch ( $this->php_type ) {
			case 'string':
				return is_string( $inner );
			case 'number':
				return is_int( $inner ) || is_float( $inner );
			case 'boolean':
				return is_bool( $inner );
			default:
				return null !== $inner;
		}
	}
}

/** Envelope prop that also advertises aliases via get_meta_item(). */
class R2_Aliased_Prop extends R2_Envelope_Prop {
	private $aliases;

	public function __construct( string $key, array $aliases, string $php_type = 'string' ) {
		parent::__construct( $key, $php_type );
		$this->aliases = $aliases;
	}

	public function get_meta_item( string $item ) {
		return 'aliases' === $item ? $this->aliases : null;
	}
}

/** Union prop: exposes members via get_prop_types(); accepts what any member accepts. */
class R2_Union_Prop {
	private $members;

	public function __construct( array $members ) {
		$this->members = $members;
	}

	public function get_prop_types(): array {
		return $this->members;
	}

	public function validate( $value ): bool {
		foreach ( $this->members as $member ) {
			if ( $member->validate( $value ) ) {
				return true;
			}
		}
		return false;
	}
}

/** Object-shaped prop (e.g. link): get_key() + get_shape() of sub-props. */
class R2_Shape_Prop {
	private $key;
	private $shape;

	public function __construct( string $key, array $shape ) {
		$this->key   = $key;
		$this->shape = $shape;
	}

	public function get_key(): string {
		return $this->key;
	}

	public function get_shape(): array {
		return $this->shape;
	}

	public function validate( $value ): bool {
		if ( ! is_array( $value ) || ( $value['$$type'] ?? '' ) !== $this->key ) {
			return false;
		}
		$inner = $value['value'] ?? null;
		if ( ! is_array( $inner ) ) {
			return false;
		}
		foreach ( $inner as $field => $sub_value ) {
			if ( ! isset( $this->shape[ $field ] ) ) {
				return false;
			}
			if ( ! $this->shape[ $field ]->validate( $sub_value ) ) {
				return false;
			}
		}
		return ! empty( $inner );
	}
}

/** Prop that rejects everything — the nothing-fits case. */
class R2_Reject_All_Prop {
	public function get_key(): string {
		return 'never';
	}

	public function validate( $value ): bool {
		return false;
	}
}

class P33HarvestRound2Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_meta_calls']            = [];
		$GLOBALS['_post_meta']                = [];
		$GLOBALS['_registered_element_types'] = [];
		$GLOBALS['_widget_types']             = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_widget_types'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// coerce_with_schema — the coercion core (#101/#102)
	// -------------------------------------------------------------------------

	public function test_raw_string_is_wrapped_into_the_envelope_the_prop_accepts(): void {
		$schema = [ 'tag' => new R2_Envelope_Prop( 'string' ) ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'tag' => 'h2' ] );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'h2' ],
			$out['tag'],
			'A raw scalar on a typed prop must be wrapped into the envelope validate() accepts (upstream #101).'
		);
	}

	public function test_already_valid_envelope_is_returned_untouched(): void {
		$schema  = [ 'tag' => new R2_Envelope_Prop( 'string' ) ];
		$wrapped = [ '$$type' => 'string', 'value' => 'h3' ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'tag' => $wrapped ] );

		$this->assertSame( $wrapped, $out['tag'], 'Anything Elementor already accepts must pass through unchanged.' );
	}

	public function test_value_nothing_fits_is_left_alone(): void {
		$schema = [ 'weird' => new R2_Reject_All_Prop() ];
		$value  = [ 'unrecognised' => 'shape' ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'weird' => $value ] );

		$this->assertSame(
			$value,
			$out['weird'],
			'When no candidate envelope validates, the original value must be left alone so Elementor reports a precise error.'
		);
	}

	public function test_keys_not_in_schema_pass_through_untouched(): void {
		$schema = [ 'tag' => new R2_Envelope_Prop( 'string' ) ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ '_custom' => 'x' ] );

		$this->assertSame( 'x', $out['_custom'] );
	}

	public function test_empty_schema_returns_settings_unchanged(): void {
		$settings = [ 'title' => 'raw' ];

		$this->assertSame(
			$settings,
			\Elementor_MCP_Atomic_Props::coerce_with_schema( [], $settings ),
			'No schema means no atomic widget — settings must not be touched.'
		);
	}

	public function test_union_prop_coerces_via_first_accepting_member(): void {
		$schema = [
			'level' => new R2_Union_Prop(
				[
					new R2_Envelope_Prop( 'number', 'number' ),
					new R2_Envelope_Prop( 'string' ),
				]
			),
		];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'level' => 2 ] );

		$this->assertSame(
			[ '$$type' => 'number', 'value' => 2 ],
			$out['level'],
			'A union prop must be coerced through its own members (get_prop_types), first accepted envelope wins.'
		);
	}

	public function test_prop_without_get_key_still_coerced_via_common_fallbacks(): void {
		// A prop exposing only validate(): candidates_for() has no member keys,
		// so the common-envelope fallbacks must still produce a match.
		$prop = new class() {
			public function validate( $value ): bool {
				return is_array( $value )
					&& 'string' === ( $value['$$type'] ?? '' )
					&& is_string( $value['value'] ?? null );
			}
		};

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( [ 'txt' => $prop ], [ 'txt' => 'hello' ] );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'hello' ],
			$out['txt'],
			'A prop type that does not describe itself must still be coverable by the common-envelope fallbacks.'
		);
	}

	// -------------------------------------------------------------------------
	// Object-shaped props (link) — legacy keys and bare scalars (#102)
	// -------------------------------------------------------------------------

	private function make_link_prop(): R2_Shape_Prop {
		return new R2_Shape_Prop(
			'link',
			[
				'destination'   => new R2_Envelope_Prop( 'url' ),
				'isTargetBlank' => new R2_Envelope_Prop( 'boolean', 'boolean' ),
				'tag'           => new R2_Envelope_Prop( 'string' ),
			]
		);
	}

	public function test_legacy_v3_link_keys_are_mapped_onto_the_atomic_shape(): void {
		$schema = [ 'link' => $this->make_link_prop() ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema(
			$schema,
			[
				'link' => [
					'url'         => 'https://example.com',
					'is_external' => true,
				],
			]
		);

		$this->assertSame(
			[
				'$$type' => 'link',
				'value'  => [
					'destination'   => [ '$$type' => 'url', 'value' => 'https://example.com' ],
					'isTargetBlank' => [ '$$type' => 'boolean', 'value' => true ],
				],
			],
			$out['link'],
			'Legacy v3 link keys (url / is_external) must be renamed onto the atomic shape (destination / isTargetBlank) and their values coerced (upstream #102).'
		);
	}

	public function test_bare_scalar_lands_in_the_shapes_principal_field(): void {
		$schema = [ 'link' => $this->make_link_prop() ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'link' => 'https://example.com' ] );

		$this->assertSame(
			[
				'$$type' => 'link',
				'value'  => [
					'destination' => [ '$$type' => 'url', 'value' => 'https://example.com' ],
				],
			],
			$out['link'],
			'A bare scalar offered to an object-shaped prop must land in the shape\'s principal field.'
		);
	}

	// -------------------------------------------------------------------------
	// Alias prop mapping (#102)
	// -------------------------------------------------------------------------

	public function test_alias_key_is_renamed_onto_the_canonical_prop_and_coerced(): void {
		$schema = [ 'title' => new R2_Aliased_Prop( 'string', [ 'text', 'content' ] ) ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'content' => 'Hello' ] );

		$this->assertArrayNotHasKey(
			'content',
			$out,
			'The alias key must be consumed — left in place, Elementor\'s Props_Parser silently deletes it (upstream #102).'
		);
		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Hello' ],
			$out['title'],
			'The alias value must be recovered under the canonical prop name and then coerced.'
		);
	}

	public function test_canonical_value_always_wins_over_an_alias(): void {
		$schema = [ 'title' => new R2_Aliased_Prop( 'string', [ 'content' ] ) ];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema(
			$schema,
			[
				'title'   => 'Real',
				'content' => 'Impostor',
			]
		);

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Real' ],
			$out['title'],
			'A canonical value already present must never be overwritten by an alias.'
		);
	}

	public function test_alias_that_is_itself_a_real_prop_is_not_consumed(): void {
		$schema = [
			'title'   => new R2_Aliased_Prop( 'string', [ 'content' ] ),
			'content' => new R2_Envelope_Prop( 'string' ),
		];

		$out = \Elementor_MCP_Atomic_Props::coerce_with_schema( $schema, [ 'content' => 'Body' ] );

		$this->assertArrayNotHasKey( 'title', $out, 'An alias that names a real prop belongs to that prop, not the aliasing one.' );
		$this->assertSame( [ '$$type' => 'string', 'value' => 'Body' ], $out['content'] );
	}

	// -------------------------------------------------------------------------
	// coerce_tree — whole-tree sweep (#102)
	// -------------------------------------------------------------------------

	/**
	 * Registers a widget stub whose static get_props_schema() returns the given
	 * schema, under a UNIQUE type name — two reasons the name must never be
	 * reused across tests: (1) props_schema() caches per type for the whole PHP
	 * process; (2) all registrations share ONE anonymous class, so its static
	 * $schema holds only the LAST registered schema. Each test registers its
	 * type immediately before first use, so the per-type cache snapshots the
	 * right schema exactly once.
	 */
	private function register_widget_type( string $type, array $schema ): void {
		$widget = new class( $schema ) {
			public static $schema = [];
			public function __construct( array $s ) {
				self::$schema = $s;
			}
			public static function get_props_schema(): array {
				return self::$schema;
			}
		};
		$GLOBALS['_widget_types'][ $type ] = $widget;
	}

	public function test_coerce_tree_repairs_nested_widgets_and_leaves_structure_alone(): void {
		$this->register_widget_type( 'e-r2-tree-heading', [ 'title' => new R2_Envelope_Prop( 'string' ) ] );

		$tree = [
			[
				'id'       => 'root1',
				'elType'   => 'e-flexbox',
				'settings' => [ 'anything' => 'raw' ],
				'elements' => [
					[
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'e-r2-tree-heading',
						'settings'   => [ 'title' => 'Deep raw' ],
						'elements'   => [],
					],
				],
			],
		];

		$out = \Elementor_MCP_Atomic_Props::coerce_tree( $tree );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Deep raw' ],
			$out[0]['elements'][0]['settings']['title'],
			'coerce_tree must reach widgets at any depth — one un-converted widget anywhere blocks the whole save (upstream #102).'
		);
		// Non-widget settings and structure untouched.
		$this->assertSame( [ 'anything' => 'raw' ], $out[0]['settings'], 'Non-widget elements must not be coerced.' );
		$this->assertSame( 'root1', $out[0]['id'] );
		$this->assertSame( 'w1', $out[0]['elements'][0]['id'] );
	}

	public function test_coerce_tree_with_unknown_widget_type_is_a_noop(): void {
		$tree = [
			[
				'id'         => 'w9',
				'elType'     => 'widget',
				'widgetType' => 'e-r2-unregistered',
				'settings'   => [ 'title' => 'raw' ],
			],
		];

		$this->assertSame(
			$tree,
			\Elementor_MCP_Atomic_Props::coerce_tree( $tree ),
			'An unknown widget type has no schema — its settings must pass through untouched.'
		);
	}

	// -------------------------------------------------------------------------
	// save_page_data integration — coercion runs before the native save
	// -------------------------------------------------------------------------

	/**
	 * Document stub that records the elements it was asked to save and
	 * "persists" them, so the round-1 projection verification sees a healthy
	 * save and takes no fallback.
	 */
	private function make_persisting_document( int $post_id ): object {
		return new class( $post_id ) {
			public $saved_elements = null;
			private $post_id;
			public function __construct( int $post_id ) {
				$this->post_id = $post_id;
			}
			public function save( array $args ) {
				$this->saved_elements = $args['elements'] ?? null;
				$GLOBALS['_post_meta'][ $this->post_id ]['_elementor_data'] =
					wp_json_encode( $this->saved_elements );
				return true;
			}
		};
	}

	private function make_data_with_document( object $document ): \Elementor_MCP_Data {
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

	public function test_save_page_data_coerces_the_tree_before_the_native_save(): void {
		$this->register_widget_type( 'e-r2-save-heading', [ 'title' => new R2_Aliased_Prop( 'string', [ 'content' ] ) ] );
		// The widget must count as "available" for the projection verification.
		$document = $this->make_persisting_document( 321 );
		$data     = $this->make_data_with_document( $document );

		$result = $data->save_page_data(
			321,
			[
				[
					'id'         => 'w1',
					'elType'     => 'widget',
					'widgetType' => 'e-r2-save-heading',
					// Raw scalar under an ALIAS key: both mechanisms must fire
					// before Elementor sees the tree.
					'settings'   => [ 'content' => 'Hi' ],
					'elements'   => [],
				],
			]
		);

		$this->assertTrue( $result );
		$this->assertIsArray( $document->saved_elements, 'The native save must have received the tree.' );
		$settings = $document->saved_elements[0]['settings'];
		$this->assertArrayNotHasKey( 'content', $settings, 'The alias key must be renamed before the native save sees the settings.' );
		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Hi' ],
			$settings['title'],
			'save_page_data must hand Elementor the COERCED tree — coercing after the save would leave the raw value to poison the page (upstream #101).'
		);
	}

	public function test_coercion_preserves_element_ids_so_projection_verification_still_passes(): void {
		$this->register_widget_type( 'e-r2-ids-heading', [ 'title' => new R2_Envelope_Prop( 'string' ) ] );
		$document = $this->make_persisting_document( 322 );
		$data     = $this->make_data_with_document( $document );

		$result = $data->save_page_data(
			322,
			[
				[
					'id'       => 'p1',
					'elType'   => 'container',
					'elements' => [
						[
							'id'         => 'w2',
							'elType'     => 'widget',
							'widgetType' => 'e-r2-ids-heading',
							'settings'   => [ 'title' => 'raw' ],
							'elements'   => [],
						],
					],
				],
			]
		);

		$this->assertTrue( $result );
		// A healthy persisting save + id-stable coercion ⇒ NO direct-meta fallback.
		$fallback_writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_elementor_data' === $c['meta_key']
		);
		$this->assertEmpty(
			$fallback_writes,
			'Coercion rewrites settings only — ids and structure are unchanged, so the projection verification must not misread a coerced save as a silent drop.'
		);
		$this->assertSame( 'p1', $document->saved_elements[0]['id'] );
		$this->assertSame( 'w2', $document->saved_elements[0]['elements'][0]['id'] );
	}
}
