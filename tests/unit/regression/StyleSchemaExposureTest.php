<?php
/**
 * Regression — the published schema must describe what the builders accept.
 *
 * Field report #4, part 2, and the costliest finding in either report.
 *
 * `build_common_props()` has always emitted correct atomic shapes for borders,
 * radii, widths, gradients and per-side spacing, and the execute paths forward
 * the whole input array into it. But `add-flexbox` advertised 14 parameters and
 * none of those. Agents read the schema, concluded atomic containers could not
 * express borders or gradients, shipped a flattened design, and wrote the
 * "limitation" into the client's design-system document.
 *
 * The real cost: those apparent limits became the primary evidence in a
 * recommendation to build the client's site on Elementor 3.x containers instead
 * of 4.x atomic. Three of the four cited blockers were schema-discovery
 * failures, not engine limits.
 *
 * An agent's entire model of a tool is its schema, so the schema is pinned to
 * the builders behaviourally, in both directions: everything published must
 * actually produce a prop, and the capabilities that were wrongly reported
 * impossible must be published.
 *
 * @group regression
 * @group atomic
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class StyleSchemaExposureTest extends TestCase {

	/**
	 * A plausible value for a parameter, by name.
	 *
	 * @param string $key Parameter name.
	 * @return mixed
	 */
	private function probe_value( string $key ) {
		$fixed = array(
			'border_style'      => 'solid',
			'direction'         => 'row',
			'justify'           => 'center',
			'align'             => 'center',
			'wrap'              => 'wrap',
			'gradient_type'     => 'linear',
			'gradient_position' => 'center center',
		);

		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}
		if ( '_unit' === substr( $key, -5 ) ) {
			return 'px';
		}
		if ( in_array( $key, array( 'color', 'background_color', 'border_color', 'gradient_from', 'gradient_to' ), true ) ) {
			return '#ff0000';
		}

		return 16;
	}

	/**
	 * Every published parameter must actually reach the builders.
	 *
	 * The inverse of the reported bug and the same class of lie: advertising a
	 * parameter the engine ignores sends an agent hunting a rendering fault
	 * that isn't there.
	 *
	 * @dataProvider published_style_params
	 */
	public function test_every_published_param_produces_a_prop( string $key ): void {
		$params = array( $key => $this->probe_value( $key ) );

		// `_unit` keys and the gradient partners only take effect alongside
		// their principal, so probe them as pairs.
		if ( '_unit' === substr( $key, -5 ) ) {
			// `shadow_unit` has no same-named principal: it scales whichever
			// shadow members are set. A unit on its own must NOT conjure a
			// shadow, so pair it with one that would.
			$principal            = 'shadow_unit' === $key ? 'shadow_blur' : substr( $key, 0, -5 );
			$params[ $principal ] = $this->probe_value( $principal );
		}
		if ( 0 === strpos( $key, 'gradient_' ) ) {
			$params['gradient_from'] = '#ff0000';
			$params['gradient_to']   = '#0000ff';
		}

		$props = array_merge(
			\Elementor_MCP_Atomic_Styles::build_common_props( $params ),
			\Elementor_MCP_Atomic_Styles::build_flex_props( $params )
		);

		$this->assertNotEmpty(
			$props,
			"`$key` is published but the style builders ignore it — an advertised no-op is the same lie as a hidden capability."
		);
	}

	public static function published_style_params(): array {
		$cases = array();
		foreach ( array_keys( \Elementor_MCP_Atomic_Styles::style_props_schema() ) as $key ) {
			$cases[ $key ] = array( $key );
		}
		return $cases;
	}

	/**
	 * The specific capabilities the field report records as reported
	 * impossible. Each one worked the whole time.
	 *
	 * @dataProvider capabilities_reported_impossible
	 */
	public function test_capabilities_wrongly_believed_unsupported_are_published( string $param ): void {
		$this->assertArrayHasKey(
			$param,
			\Elementor_MCP_Atomic_Styles::style_props_schema(),
			"`$param` works and always did; not publishing it is what made agents report it impossible."
		);
	}

	public static function capabilities_reported_impossible(): array {
		return array(
			'border width'  => array( 'border_width' ),
			'border colour' => array( 'border_color' ),
			'border style'  => array( 'border_style' ),
			'radius'        => array( 'border_radius' ),
			'width'         => array( 'width' ),
			'gradient from' => array( 'gradient_from' ),
			'gradient to'   => array( 'gradient_to' ),
			'margin'        => array( 'margin' ),
		);
	}

	/**
	 * The execute paths must forward every published parameter.
	 *
	 * The builders accepting a key is not enough: `execute_add_flexbox()`
	 * filters input through an allowlist first, and that list was
	 * hand-maintained — it omitted borders and gradients, so publishing them
	 * without widening it would have advertised a no-op. Testing the builders
	 * in isolation cannot see this; only the tool call can.
	 *
	 * @dataProvider published_style_params
	 */
	public function test_the_flexbox_tool_forwards_every_published_param( string $key ): void {
		$captured = null;

		$factory = new class( $captured ) extends \Elementor_MCP_Element_Factory {
			public $seen = array();
			public function __construct( &$captured ) {}
			public function create_flexbox( array $settings = array(), array $children = array(), array $style_props = array() ): array {
				$this->seen = $style_props;
				return parent::create_flexbox( $settings, $children, $style_props );
			}
		};

		$data = new class extends \Elementor_MCP_Data {
			public function __construct() {}
			public function get_page_data( int $post_id ): array {
				return array();
			}
			public function save_page_data( int $post_id, array $data ) {
				return true;
			}
		};

		$ability = new \Elementor_MCP_Atomic_Layout_Abilities( $data, $factory );

		$input = array( 'post_id' => 5, $key => $this->probe_value( $key ) );
		if ( '_unit' === substr( $key, -5 ) ) {
			$principal          = substr( $key, 0, -5 );
			$input[ $principal ] = $this->probe_value( $principal );
		}

		$ability->execute_add_flexbox( $input );

		$this->assertArrayHasKey(
			$key,
			$factory->seen,
			"`$key` is published by add-flexbox but dropped before the style builders — the allowlist in the execute path must be derived from the schema, not hand-maintained."
		);
	}

	public function test_both_container_tools_publish_the_shared_schema(): void {
		$source = file_get_contents( ELEMENTOR_MCP_DIR . 'includes/abilities/class-atomic-layout-abilities.php' );

		$this->assertIsString( $source );
		$this->assertSame(
			2,
			substr_count( $source, 'style_props_schema(' ),
			'add-flexbox and add-div-block must both publish the shared schema, or one of them drifts again.'
		);
		$this->assertSame(
			2,
			substr_count( $source, 'style_param_keys(' ),
			'Both execute paths must derive their forwarding allowlist from the schema too — publishing a param the execute path drops is an advertised no-op.'
		);
	}

	/**
	 * A unit or an offset on its own must not invent a rule: Elementor requires
	 * every shadow member, and an offset without a non-static position does
	 * nothing. Emitting either from a stray param would be a silent surprise in
	 * the other direction.
	 */
	public function test_partial_input_does_not_invent_a_shadow(): void {
		$this->assertSame(
			array(),
			\Elementor_MCP_Atomic_Styles::build_common_props( array( 'shadow_unit' => 'px' ) ),
			'A unit alone describes nothing to render.'
		);
	}

	public function test_a_single_shadow_param_emits_a_complete_shadow(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( array( 'shadow_color' => 'rgba(0,0,0,.25)' ) );

		$shadow = $props['box-shadow']['value'][0]['value'];

		// Elementor rejects a partial shadow: hOffset, vOffset, blur, spread
		// and color are all required.
		foreach ( array( 'hOffset', 'vOffset', 'blur', 'spread', 'color' ) as $member ) {
			$this->assertArrayHasKey( $member, $shadow, "Elementor requires `$member` on every shadow." );
		}
		$this->assertSame( 'rgba(0,0,0,.25)', $shadow['color']['value'] );
	}

	public function test_css_position_is_distinct_from_the_insert_index(): void {
		$schema = \Elementor_MCP_Atomic_Styles::style_props_schema();

		$this->assertArrayHasKey( 'css_position', $schema );
		$this->assertArrayNotHasKey(
			'position',
			$schema,
			'`position` is the layout tools\' insert index; publishing the CSS property under that name would forward an integer into a string enum.'
		);
		$this->assertNotContains( 'position', \Elementor_MCP_Atomic_Styles::style_param_keys(), 'The insert index must never be forwarded as a style param.' );
	}

	/**
	 * Elementor declares LOGICAL inset properties, and the inline axis follows
	 * text direction: `inset-inline-end` is the LEFT edge on an RTL page.
	 * Publishing these as `offset_right`/`offset_left` would name the wrong
	 * edge on every Hebrew or Arabic page while looking correct in LTR review —
	 * a silent failure that only shows up in the one locale nobody re-checks.
	 */
	public function test_offsets_are_published_under_logical_names(): void {
		$schema = \Elementor_MCP_Atomic_Styles::style_props_schema();

		foreach ( array( 'offset_block_start', 'offset_inline_end', 'offset_block_end', 'offset_inline_start' ) as $key ) {
			$this->assertArrayHasKey( $key, $schema );
		}

		foreach ( array( 'offset_top', 'offset_right', 'offset_bottom', 'offset_left' ) as $physical ) {
			$this->assertArrayNotHasKey(
				$physical,
				$schema,
				"`$physical` names a physical edge for a property that is logical — it would lie in RTL."
			);
		}
	}

	public function test_the_inline_offsets_map_to_logical_css_properties(): void {
		$props = \Elementor_MCP_Atomic_Styles::build_common_props( array(
			'css_position'        => 'absolute',
			'offset_inline_start' => 8,
			'offset_block_start'  => 12,
		) );

		$this->assertArrayHasKey( 'inset-inline-start', $props );
		$this->assertArrayHasKey( 'inset-block-start', $props );
		$this->assertArrayNotHasKey( 'left', $props, 'Elementor drops physical inset keys; emitting them would be a silent no-op.' );
	}

	/**
	 * The container tools were not the only place the schema drifted from the
	 * builders: atomic WIDGETS (headings, buttons, images) publish their own
	 * list, and it hand-listed a subset too.
	 *
	 * @dataProvider capabilities_reported_impossible
	 */
	public function test_atomic_widgets_publish_the_same_style_capabilities( string $param ): void {
		$source = file_get_contents( ELEMENTOR_MCP_DIR . 'includes/abilities/class-atomic-widget-abilities.php' );

		$this->assertIsString( $source );
		$this->assertStringContainsString(
			'style_props_schema(',
			$source,
			'Atomic widget tools must derive their style schema from the shared source, not a parallel hand-list.'
		);
	}

	public function test_flex_params_are_excluded_for_non_flex_containers(): void {
		$without = \Elementor_MCP_Atomic_Styles::style_props_schema( false );

		$this->assertArrayNotHasKey( 'gap', $without, 'A div-block is not a flex container; advertising flex params would be the same mistake inverted.' );
		$this->assertArrayHasKey( 'border_width', $without, 'Box styling still applies to a div-block.' );
	}

	public function test_every_published_prop_is_typed_and_described(): void {
		foreach ( \Elementor_MCP_Atomic_Styles::style_props_schema() as $name => $prop ) {
			$this->assertArrayHasKey( 'type', $prop, "`$name` must declare a type." );
			$this->assertNotEmpty( $prop['description'] ?? '', "`$name` must be described — an undocumented param reads as unsupported." );
		}
	}
}
