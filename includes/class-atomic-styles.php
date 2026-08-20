<?php
/**
 * Atomic element style builder.
 *
 * Builds local style class structures for Elementor 4.0 atomic elements.
 * In v4, visual styling (flex layout, spacing, colors, typography) is stored
 * in a `styles` map on each element, referenced via class IDs in settings.
 *
 * @package Elementor_MCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds local style classes for atomic elements.
 *
 * @since 1.5.0
 */
class Elementor_MCP_Atomic_Styles {

	/**
	 * Creates a local style class structure for an element.
	 *
	 * @param string $element_id The element's ID.
	 * @param array  $props      CSS properties as $$type-wrapped values.
	 * @param string $breakpoint The responsive breakpoint (desktop, tablet, mobile).
	 * @param string $state      The CSS state (null, hover, focus, active).
	 * @return array { class_id: string, style_def: array } ready to merge into element.
	 */
	public static function create_local_class(
		string $element_id,
		array $props,
		string $breakpoint = 'desktop',
		?string $state = null
	): array {
		$class_id = self::mint_class_id( $element_id );

		$style_def = array(
			'id'       => $class_id,
			'label'    => 'local',
			'type'     => 'class',
			'variants' => array(
				array(
					'meta'       => array(
						'breakpoint' => $breakpoint,
						'state'      => $state,
					),
					'props'      => $props,
					'custom_css' => null,
				),
			),
		);

		return array(
			'class_id'  => $class_id,
			'style_def' => $style_def,
		);
	}

	/**
	 * Mints a fresh local style class id for an element.
	 *
	 * @since 1.27.0
	 *
	 * @param string $element_id The element's ID.
	 * @return string Class id in the `e-<element-id>-<hash>` form Elementor uses.
	 */
	public static function mint_class_id( string $element_id ): string {
		return 'e-' . $element_id . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Re-mints an element's local style classes in place.
	 *
	 * When an element is duplicated with a fresh `id`, its v4 local style classes
	 * (`e-<oldid>-<hash>`) still embed the SOURCE id and remain shared with the
	 * source — so a later styles-map write bleeds across both elements, and the
	 * editor's Style Origin popover shows doubled entries (upstream issue #97).
	 * This regenerates the `styles` map keys (and each style def's `id`) against
	 * the element's current id, and repoints `settings.classes.value` from the
	 * old IDs to the new ones. Only classes defined in this element's own
	 * `styles` map are remapped; global classes (`g-…`) referenced in
	 * `settings.classes` are left untouched.
	 *
	 * @since 1.27.0
	 *
	 * @param array $element The element array (modified in place).
	 * @return void
	 */
	public static function remap_local_classes( array &$element ): void {
		if ( empty( $element['styles'] ) || ! is_array( $element['styles'] ) ) {
			return;
		}

		$new_id = isset( $element['id'] ) ? (string) $element['id'] : '';
		if ( '' === $new_id ) {
			return;
		}

		$map        = array();
		$new_styles = array();
		foreach ( $element['styles'] as $old_class_id => $style_def ) {
			$new_class_id                  = self::mint_class_id( $new_id );
			$map[ (string) $old_class_id ] = $new_class_id;

			if ( is_array( $style_def ) ) {
				$style_def['id'] = $new_class_id;
			}
			$new_styles[ $new_class_id ] = $style_def;
		}
		$element['styles'] = $new_styles;

		// Repoint the element's own local-class references; leave globals alone.
		if ( isset( $element['settings']['classes']['value'] ) && is_array( $element['settings']['classes']['value'] ) ) {
			$element['settings']['classes']['value'] = array_values( array_map(
				static function ( $cid ) use ( $map ) {
					return $map[ (string) $cid ] ?? $cid;
				},
				$element['settings']['classes']['value']
			) );
		}
	}

	/**
	 * Builds flexbox layout style props from AI-friendly parameters.
	 *
	 * Accepts plain values and returns $$type-wrapped CSS properties
	 * using CSS property names (kebab-case).
	 *
	 * @param array $params Flat layout parameters from AI agent input.
	 * @return array CSS props in $$type format (e.g., flex-direction, justify-content, etc.)
	 */
	public static function build_flex_props( array $params ): array {
		$props = array();

		$string_mappings = array(
			'direction'       => 'flex-direction',
			'flex_direction'  => 'flex-direction',
			'justify'         => 'justify-content',
			'justify_content' => 'justify-content',
			'align'           => 'align-items',
			'align_items'     => 'align-items',
			'wrap'            => 'flex-wrap',
			'flex_wrap'       => 'flex-wrap',
		);

		foreach ( $string_mappings as $input_key => $css_prop ) {
			if ( isset( $params[ $input_key ] ) && '' !== $params[ $input_key ] ) {
				$props[ $css_prop ] = Elementor_MCP_Atomic_Props::string( (string) $params[ $input_key ] );
			}
		}

		// Flex gap. 4.x GA: `gap` = Layout_Direction { row, column } (Size each); flat
		// `gap`/`row-gap`/`column-gap` are dropped. 3.x: flat Size + row-gap/column-gap.
		$gap = $params['gap'] ?? null;
		$rg  = $params['row_gap'] ?? $gap;
		$cg  = $params['column_gap'] ?? $gap;
		if ( null !== $rg || null !== $cg ) {
			$gu = $params['gap_unit'] ?? 'px';
			if ( Elementor_MCP_Atomic_Props::is_v4() ) {
				$val = array();
				if ( null !== $rg ) {
					$val['row'] = Elementor_MCP_Atomic_Props::size( (float) $rg, $gu );
				}
				if ( null !== $cg ) {
					$val['column'] = Elementor_MCP_Atomic_Props::size( (float) $cg, $gu );
				}
				$props['gap'] = array( '$$type' => 'layout-direction', 'value' => $val );
			} else {
				if ( null !== $gap ) {
					$props['gap'] = Elementor_MCP_Atomic_Props::size( (float) $gap, $gu );
				}
				if ( isset( $params['row_gap'] ) ) {
					$props['row-gap'] = Elementor_MCP_Atomic_Props::size( (float) $params['row_gap'], $params['row_gap_unit'] ?? 'px' );
				}
				if ( isset( $params['column_gap'] ) ) {
					$props['column-gap'] = Elementor_MCP_Atomic_Props::size( (float) $params['column_gap'], $params['column_gap_unit'] ?? 'px' );
				}
			}
		}

		return $props;
	}

	/**
	 * Builds common style props (padding, margin, background, etc.) from AI input.
	 *
	 * @param array $params Flat style parameters.
	 * @return array CSS props in $$type format.
	 */
	/**
	 * Builds padding/margin for the live Elementor major.
	 *
	 *  - 3.x: per-side `<prop>-block-start|inline-end|block-end|inline-start` (Size).
	 *  - 4.x: a single `<prop>` = Size (uniform) or a `dimensions` shape (per-side),
	 *         since 4.x dropped the per-side keys (schema = Union(Size | Dimensions)).
	 *         Verified against Elementor 4.1.1 style-schema.
	 *
	 * @param string $prop   'padding' or 'margin'.
	 * @param array  $params Flat params: `<prop>` (uniform) + `<prop>_top/right/bottom/left`.
	 * @return array CSS-prop map.
	 */
	private static function build_spacing( string $prop, array $params ): array {
		$unit  = $params[ $prop . '_unit' ] ?? 'px';
		$sides = array(
			'block-start'  => $params[ $prop . '_top' ]    ?? null,
			'inline-end'   => $params[ $prop . '_right' ]  ?? null,
			'block-end'    => $params[ $prop . '_bottom' ] ?? null,
			'inline-start' => $params[ $prop . '_left' ]   ?? null,
		);
		$uniform  = $params[ $prop ] ?? null;
		$has_side = array_filter( $sides, static function ( $v ) {
			return null !== $v;
		} );

		if ( null === $uniform && ! $has_side ) {
			return array();
		}

		if ( Elementor_MCP_Atomic_Props::is_v4() ) {
			if ( null !== $uniform && ! $has_side ) {
				return array( $prop => Elementor_MCP_Atomic_Props::size( (float) $uniform, $unit ) );
			}
			$dim = array();
			foreach ( $sides as $css => $val ) {
				$v = $val ?? $uniform;
				if ( null !== $v ) {
					$dim[ $css ] = Elementor_MCP_Atomic_Props::size( (float) $v, $unit );
				}
			}
			return array( $prop => array( '$$type' => 'dimensions', 'value' => $dim ) );
		}

		// 3.x per-side keys.
		$out = array();
		foreach ( $sides as $css => $val ) {
			$v = $val ?? $uniform;
			if ( null !== $v ) {
				$out[ $prop . '-' . $css ] = Elementor_MCP_Atomic_Props::size( (float) $v, $unit );
			}
		}
		return $out;
	}

	public static function build_common_props( array $params ): array {
		$props = array();

		// Plain Size keys (same in 3.x and 4.x). `height` (4.x) is additive + harmless.
		$size_mappings = array(
			'width'         => 'width',
			'max_width'     => 'max-width',
			'min_height'    => 'min-height',
			'height'        => 'height',
			'border_radius' => 'border-radius',
		);

		foreach ( $size_mappings as $input_key => $css_prop ) {
			if ( isset( $params[ $input_key ] ) ) {
				$unit = $params[ $input_key . '_unit' ] ?? 'px';
				$props[ $css_prop ] = Elementor_MCP_Atomic_Props::size(
					(float) $params[ $input_key ],
					$unit
				);
			}
		}

		// padding + margin — the schema differs by Elementor major (see build_spacing).
		$props += self::build_spacing( 'padding', $params );
		$props += self::build_spacing( 'margin', $params );

		$props += self::build_position_props( $params );
		$props += self::build_box_shadow_props( $params );

		// Atomic's CSS engine validates every style prop against its style-schema
		// and silently drops invalid keys. `background-color` is NOT a valid key —
		// atomic uses `background` (Background_Prop_Type, shape { color, ... }).
		// A flat `background-color` was dropped, so every container/button bg fell
		// back to the atomic default. Emit the valid `background` shape instead.
		if ( isset( $params['background_color'] ) ) {
			$props['background'] = array(
				'$$type' => 'background',
				'value'  => array(
					'color' => array( '$$type' => 'color', 'value' => $params['background_color'] ),
				),
			);
		}

		// `color` is a Color_Prop_Type ($$type: color), not a plain string — a
		// string value is rejected by the schema and dropped.
		if ( isset( $params['color'] ) ) {
			$props['color'] = array( '$$type' => 'color', 'value' => $params['color'] );
		}

		// Border — atomic schema keys: border-width (Size), border-color (Color),
		// border-style (String enum: solid/dashed/...).
		if ( isset( $params['border_width'] ) ) {
			$unit = $params['border_width_unit'] ?? 'px';
			$props['border-width'] = Elementor_MCP_Atomic_Props::size( (float) $params['border_width'], $unit );
		}
		if ( isset( $params['border_color'] ) ) {
			$props['border-color'] = array( '$$type' => 'color', 'value' => $params['border_color'] );
		}
		if ( isset( $params['border_style'] ) ) {
			$props['border-style'] = Elementor_MCP_Atomic_Props::string( $params['border_style'] );
		}

		// Gradient — emit a `background.background-overlay[]` gradient item. A flat
		// `background-color` shape (above) is preserved as the base if both are set.
		if ( isset( $params['gradient_from'], $params['gradient_to'] ) ) {
			$type     = $params['gradient_type'] ?? 'linear';
			$angle    = isset( $params['gradient_angle'] ) ? (float) $params['gradient_angle'] : 135;
			$pos      = $params['gradient_position'] ?? 'center center';
			$from_off = isset( $params['gradient_from_offset'] ) ? (float) $params['gradient_from_offset'] : 0;
			$to_off   = isset( $params['gradient_to_offset'] ) ? (float) $params['gradient_to_offset'] : 100;
			$overlay  = array(
				'type'      => Elementor_MCP_Atomic_Props::string( $type ),
				'angle'     => array( '$$type' => 'number', 'value' => $angle ),
				'stops'     => array(
					'$$type' => 'gradient-color-stop',
					'value'  => array(
						array(
							'$$type' => 'color-stop',
							'value'  => array(
								'color'  => array( '$$type' => 'color', 'value' => $params['gradient_from'] ),
								'offset' => array( '$$type' => 'number', 'value' => $from_off ),
							),
						),
						array(
							'$$type' => 'color-stop',
							'value'  => array(
								'color'  => array( '$$type' => 'color', 'value' => $params['gradient_to'] ),
								'offset' => array( '$$type' => 'number', 'value' => $to_off ),
							),
						),
					),
				),
				'positions' => Elementor_MCP_Atomic_Props::string( $pos ),
			);
			$bg = isset( $props['background'] ) ? $props['background'] : array( '$$type' => 'background', 'value' => array() );
			$bg['value']['background-overlay'] = array(
				'$$type' => 'background-overlay',
				'value'  => array( array( '$$type' => 'background-gradient-overlay', 'value' => $overlay ) ),
			);
			$props['background'] = $bg;
		}

		return $props;
	}

	/**
	 * Builds typography CSS props from flat params.
	 *
	 * Sibling to build_common_props() — covers the text-styling props that
	 * one (color/spacing) does not. Only keys present in $params produce
	 * output; unknown keys are ignored.
	 *
	 * @param array $params Flat typography params.
	 * @return array Map of CSS prop name => $$type-wrapped value.
	 */
	public static function build_typography_props( array $params ): array {
		$props = array();

		// size-typed props: input key => [ css prop, default unit ].
		$size_props = array(
			'font_size'      => array( 'font-size', 'px' ),
			'line_height'    => array( 'line-height', 'em' ),
			'letter_spacing' => array( 'letter-spacing', 'px' ),
		);
		foreach ( $size_props as $input_key => $meta ) {
			if ( isset( $params[ $input_key ] ) ) {
				$unit              = $params[ $input_key . '_unit' ] ?? $meta[1];
				$props[ $meta[0] ] = Elementor_MCP_Atomic_Props::size( (float) $params[ $input_key ], $unit );
			}
		}

		// `font-family` is NOT a plain string prop: Elementor types it as
		// Font_Family_Prop_Type, whose key is `font-family`. The atomic CSS
		// engine validates each prop against the style schema and silently
		// drops any whose $$type doesn't match — so emitting `string` here
		// meant no font set through any tool ever applied (field report #4).
		if ( isset( $params['font_family'] ) ) {
			$props['font-family'] = Elementor_MCP_Atomic_Props::font_family( (string) $params['font_family'] );
		}

		// string-typed props: input key => css prop.
		$string_props = array(
			'font_weight' => 'font-weight',
			'text_align'  => 'text-align',
		);
		foreach ( $string_props as $input_key => $css_prop ) {
			if ( isset( $params[ $input_key ] ) ) {
				$props[ $css_prop ] = Elementor_MCP_Atomic_Props::string( (string) $params[ $input_key ] );
			}
		}

		return $props;
	}

	/**
	 * Applies a local style class to an element structure.
	 *
	 * Adds the class to settings.classes and the style definition to the styles map.
	 *
	 * @param array  $element  The element array (passed by reference).
	 * @param string $class_id The style class ID.
	 * @param array  $style_def The style definition array.
	 */
	public static function apply_to_element( array &$element, string $class_id, array $style_def ): void {
		// Add class reference to settings.
		if ( ! isset( $element['settings']['classes'] ) ) {
			$element['settings']['classes'] = Elementor_MCP_Atomic_Props::classes( array() );
		}
		$element['settings']['classes']['value'][] = $class_id;

		// Add style definition to styles map.
		if ( ! isset( $element['styles'] ) ) {
			$element['styles'] = array();
		}
		$element['styles'][ $class_id ] = $style_def;
	}

	/**
	 * Builds a `styles`-map patch that carries per-element custom CSS on an
	 * atomic element.
	 *
	 * Atomic elements never read `settings.custom_css` — that is the Elementor
	 * 3.x Pro control. Their CSS comes from `styles[].variants[]`, compiled by
	 * the atomic CSS engine, and a variant carries free-form CSS in
	 * `custom_css.raw`.
	 *
	 * **That value must be base64.** Elementor validates it with
	 * `Utils::decode_string()` = `base64_decode( $raw, true )` and sanitizes
	 * with the same call (`modules/atomic-widgets/parsers/style-parser.php`).
	 * A plain CSS string contains characters outside the base64 alphabet, so
	 * strict decoding returns `false` — which is not `null`, so validation
	 * passes — and the rule is then dropped to nothing. Success is reported,
	 * the data is stored, and no CSS renders (field report #4, parts 1.1/1.3).
	 *
	 * Reuses the element's existing local class when it has one, so repeated
	 * calls don't pile up classes, and edits the desktop/no-state variant.
	 *
	 * @since 1.29.0
	 *
	 * @param array  $element The target element (read-only).
	 * @param string $css     Raw CSS to store.
	 * @param bool   $replace Replace existing custom CSS instead of appending.
	 * @return array { class_id: string, styles: array, css: string } — `styles`
	 *               is the patch to hand to update_element_settings(), and
	 *               `css` the resulting decoded CSS.
	 */
	public static function build_custom_css_patch( array $element, string $css, bool $replace = false ): array {
		// prefer_css: on an element with several local classes, edit the one
		// that already holds custom CSS. Picking any other would leave the
		// original rule in place under the deep merge, so `replace` would add a
		// rule rather than replace one.
		$target   = self::target_local_class( $element, true );
		$variants = $target['variants'];
		$index    = $target['index'];

		$existing_raw = $variants[ $index ]['custom_css']['raw'] ?? '';
		$existing_css = '';
		if ( is_string( $existing_raw ) && '' !== $existing_raw ) {
			$decoded      = base64_decode( $existing_raw, true );
			$existing_css = is_string( $decoded ) ? $decoded : '';
		}

		$new_css = $replace ? $css : trim( $existing_css . "\n" . $css );

		$variants[ $index ]['custom_css'] = array( 'raw' => base64_encode( $new_css ) );

		$style_def             = $target['style_def'];
		$style_def['variants'] = $variants;

		return array(
			'class_id' => $target['class_id'],
			'styles'   => array( $target['class_id'] => $style_def ),
			'css'      => $new_css,
		);
	}

	/**
	 * Index of a style def's base (desktop / no-state) variant.
	 *
	 * A base variant is stored with `breakpoint = 'desktop'`, but a persisted
	 * one may carry `null` — this fork's own reads fold the two together
	 * (`norm_breakpoint()` in the global-classes writer, which documents the
	 * same equivalence). Matching only the literal 'desktop' would miss a
	 * null-breakpoint base and append a SECOND base variant, leaving the
	 * original rule in place: a `replace` that doesn't replace.
	 *
	 * @since 1.29.0
	 *
	 * @param array $style_def       A style definition (or `[ 'variants' => [...] ]`).
	 * @param bool  $require_css     Only match a base variant that already holds custom CSS.
	 * @return int|null Index into the variants list, or null when absent.
	 */
	private static function find_base_variant_index( array $style_def, bool $require_css = false ): ?int {
		$variants = ( isset( $style_def['variants'] ) && is_array( $style_def['variants'] ) )
			? array_values( $style_def['variants'] )
			: array();

		foreach ( $variants as $i => $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$breakpoint = $variant['meta']['breakpoint'] ?? null;
			$state      = $variant['meta']['state'] ?? null;

			$is_base = ( null === $breakpoint || '' === $breakpoint || 'desktop' === $breakpoint )
				&& ( null === $state || '' === $state );

			if ( ! $is_base ) {
				continue;
			}

			if ( $require_css && empty( $variant['custom_css']['raw'] ) ) {
				continue;
			}

			return (int) $i;
		}

		return null;
	}

	/**
	 * JSON-schema properties for everything build_common_props() and
	 * build_flex_props() actually accept.
	 *
	 * The abilities that reach those builders published a much smaller list —
	 * `add-flexbox` advertised 14 params and silently accepted borders, radii,
	 * widths and gradients besides. Agents read the schema, concluded the
	 * engine could not express those, and shipped a flattened design; on the
	 * build behind field report #4 those apparent limitations became the
	 * primary evidence in a recommendation to build the client's site on
	 * Elementor 3.x containers instead of 4.x atomic. Three of the four cited
	 * blockers were schema-discovery failures, not engine limits.
	 *
	 * Published from one place so the schema cannot drift from the builder
	 * again: an agent's entire model of a tool is its schema.
	 *
	 * @since 1.29.0
	 *
	 * @param bool $include_flex Include the flex-container params.
	 * @return array JSON-schema property map.
	 */
	public static function style_props_schema( bool $include_flex = true ): array {
		$size = static function ( string $label ): array {
			return array(
				'type'        => 'number',
				'description' => $label,
			);
		};
		$unit = static function ( string $for ): array {
			return array(
				'type'        => 'string',
				'description' => sprintf(
					/* translators: %s: the parameter the unit applies to. */
					__( 'CSS unit for %s (px, em, rem, %%, vw, vh). Default: px.', 'elementor-mcp' ),
					$for
				),
			);
		};

		$props = array(
			// Box size.
			'width'                => $size( __( 'Width.', 'elementor-mcp' ) ),
			'width_unit'           => $unit( 'width' ),
			'max_width'            => $size( __( 'Maximum width.', 'elementor-mcp' ) ),
			'max_width_unit'       => $unit( 'max_width' ),
			'height'               => $size( __( 'Height.', 'elementor-mcp' ) ),
			'height_unit'          => $unit( 'height' ),
			'min_height'           => $size( __( 'Minimum height.', 'elementor-mcp' ) ),
			'min_height_unit'      => $unit( 'min_height' ),

			// Border.
			'border_radius'        => $size( __( 'Corner radius.', 'elementor-mcp' ) ),
			'border_radius_unit'   => $unit( 'border_radius' ),
			'border_width'         => $size( __( 'Border width. Set border_style too — a width alone renders nothing.', 'elementor-mcp' ) ),
			'border_width_unit'    => $unit( 'border_width' ),
			'border_color'         => array( 'type' => 'string', 'description' => __( 'Border colour (hex).', 'elementor-mcp' ) ),
			'border_style'         => array( 'type' => 'string', 'description' => __( 'Border style: solid, dashed, dotted, none.', 'elementor-mcp' ) ),

			// Colour + background.
			'color'                => array( 'type' => 'string', 'description' => __( 'Text colour (hex).', 'elementor-mcp' ) ),
			'background_color'     => array( 'type' => 'string', 'description' => __( 'Background colour (hex).', 'elementor-mcp' ) ),

			// Gradient background. from + to are both required to emit one.
			'gradient_from'        => array( 'type' => 'string', 'description' => __( 'Gradient start colour (hex). Requires gradient_to.', 'elementor-mcp' ) ),
			'gradient_to'          => array( 'type' => 'string', 'description' => __( 'Gradient end colour (hex). Requires gradient_from.', 'elementor-mcp' ) ),
			'gradient_type'        => array( 'type' => 'string', 'description' => __( 'Gradient type: linear (default) or radial.', 'elementor-mcp' ) ),
			'gradient_angle'       => array( 'type' => 'number', 'description' => __( 'Linear gradient angle in degrees. Default: 135.', 'elementor-mcp' ) ),
			'gradient_position'    => array( 'type' => 'string', 'description' => __( 'Radial gradient position, e.g. "center center".', 'elementor-mcp' ) ),
			'gradient_from_offset' => array( 'type' => 'number', 'description' => __( 'Start colour stop offset (%). Default: 0.', 'elementor-mcp' ) ),
			'gradient_to_offset'   => array( 'type' => 'number', 'description' => __( 'End colour stop offset (%). Default: 100.', 'elementor-mcp' ) ),

			// Spacing: uniform value or per-side.
			'padding'              => $size( __( 'Uniform padding. Use the per-side keys for different sides.', 'elementor-mcp' ) ),
			'padding_unit'         => $unit( 'padding' ),
			'padding_top'          => $size( __( 'Padding, top.', 'elementor-mcp' ) ),
			'padding_right'        => $size( __( 'Padding, right.', 'elementor-mcp' ) ),
			'padding_bottom'       => $size( __( 'Padding, bottom.', 'elementor-mcp' ) ),
			'padding_left'         => $size( __( 'Padding, left.', 'elementor-mcp' ) ),
			'margin'               => $size( __( 'Uniform margin. Use the per-side keys for different sides.', 'elementor-mcp' ) ),
			'margin_unit'          => $unit( 'margin' ),
			'margin_top'           => $size( __( 'Margin, top.', 'elementor-mcp' ) ),
			'margin_right'         => $size( __( 'Margin, right.', 'elementor-mcp' ) ),
			'margin_bottom'        => $size( __( 'Margin, bottom.', 'elementor-mcp' ) ),
			'margin_left'          => $size( __( 'Margin, left.', 'elementor-mcp' ) ),

			// Positioning. NOTE the name: the layout tools publish `position`
			// as the insert index, so the CSS property is `css_position`.
			'css_position'         => array(
				'type'        => 'string',
				'enum'        => array( 'static', 'relative', 'absolute', 'fixed', 'sticky' ),
				'description' => __( 'CSS position. Use with offset_* to place an element out of flow. (Named css_position because `position` is this tool\'s insert index.)', 'elementor-mcp' ),
			),
			'offset_block_start'       => $size( __( 'Offset from the block start (the top in horizontal writing). Needs a non-static css_position.', 'elementor-mcp' ) ),
			'offset_block_start_unit'  => $unit( 'offset_block_start' ),
			'offset_inline_end'        => $size( __( 'Offset from the inline end — the RIGHT edge in LTR, the LEFT edge in RTL. Needs a non-static css_position.', 'elementor-mcp' ) ),
			'offset_inline_end_unit'   => $unit( 'offset_inline_end' ),
			'offset_block_end'         => $size( __( 'Offset from the block end (the bottom in horizontal writing). Needs a non-static css_position.', 'elementor-mcp' ) ),
			'offset_block_end_unit'    => $unit( 'offset_block_end' ),
			'offset_inline_start'      => $size( __( 'Offset from the inline start — the LEFT edge in LTR, the RIGHT edge in RTL. Needs a non-static css_position.', 'elementor-mcp' ) ),
			'offset_inline_start_unit' => $unit( 'offset_inline_start' ),
			'z_index'              => array( 'type' => 'integer', 'description' => __( 'Stacking order.', 'elementor-mcp' ) ),

			// Box shadow. Setting any one of these emits a complete shadow;
			// Elementor requires every member, so the rest take its defaults.
			'shadow_color'         => array( 'type' => 'string', 'description' => __( 'Shadow colour (hex/rgba). Default: rgba(0, 0, 0, 1).', 'elementor-mcp' ) ),
			'shadow_x'             => $size( __( 'Shadow horizontal offset. Default: 0.', 'elementor-mcp' ) ),
			'shadow_y'             => $size( __( 'Shadow vertical offset. Default: 0.', 'elementor-mcp' ) ),
			'shadow_blur'          => $size( __( 'Shadow blur. Default: 10.', 'elementor-mcp' ) ),
			'shadow_spread'        => $size( __( 'Shadow spread. Default: 0.', 'elementor-mcp' ) ),
			'shadow_unit'          => $unit( 'the shadow offsets, blur and spread' ),
			'shadow_inset'         => array( 'type' => 'boolean', 'description' => __( 'Render the shadow inside the element.', 'elementor-mcp' ) ),
		);

		if ( ! $include_flex ) {
			return $props;
		}

		return array_merge(
			$props,
			array(
				'direction'       => array( 'type' => 'string', 'description' => __( 'Flex direction: row, column, row-reverse, column-reverse.', 'elementor-mcp' ) ),
				'justify'         => array( 'type' => 'string', 'description' => __( 'justify-content value.', 'elementor-mcp' ) ),
				'align'           => array( 'type' => 'string', 'description' => __( 'align-items value.', 'elementor-mcp' ) ),
				'wrap'            => array( 'type' => 'string', 'description' => __( 'flex-wrap: wrap or nowrap.', 'elementor-mcp' ) ),
				'gap'             => $size( __( 'Gap between children.', 'elementor-mcp' ) ),
				'gap_unit'        => $unit( 'gap' ),
				'row_gap'         => $size( __( 'Row gap (overrides gap for rows).', 'elementor-mcp' ) ),
				'column_gap'      => $size( __( 'Column gap (overrides gap for columns).', 'elementor-mcp' ) ),
			)
		);
	}

	/**
	 * Input keys the execute paths must forward to the style builders.
	 *
	 * Derived from the published schema plus the accepted aliases, so the
	 * allowlist cannot drift from what the tools advertise. It drifted once
	 * already, in the opposite direction from the schema: `add-flexbox`
	 * filtered input through a hand-maintained list that omitted borders and
	 * gradients, so those params were dropped before ever reaching
	 * build_common_props(). Publishing them without this would have advertised
	 * a no-op — the same lie as hiding a capability, inverted.
	 *
	 * @since 1.29.0
	 *
	 * @param bool $include_flex Include the flex-container params.
	 * @return string[]
	 */
	public static function style_param_keys( bool $include_flex = true ): array {
		$keys = array_keys( self::style_props_schema( $include_flex ) );

		if ( $include_flex ) {
			// build_flex_props() accepts these alongside the published names.
			$keys = array_merge( $keys, array( 'flex_direction', 'justify_content', 'align_items', 'flex_wrap', 'row_gap_unit', 'column_gap_unit' ) );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * CSS positioning props.
	 *
	 * Elementor's style schema types `position` as a String enum
	 * (static/relative/absolute/fixed/sticky) with Size offsets under the
	 * logical property names, plus a Number `z-index`
	 * (`modules/atomic-widgets/styles/style-schema.php`). None of it was
	 * mapped, so absolutely-positioned compositions could not be expressed at
	 * all — on the build behind field report #4 the design's four orbiting
	 * badges were shipped as a row and eventually replaced by a Lottie to get
	 * the arrangement back.
	 *
	 * The input key is `css_position`, NOT `position`: the layout tools already
	 * publish `position` as the INSERT INDEX (-1 = append). Reusing the name
	 * would forward an integer into a string enum and store `"-1"` as a CSS
	 * position.
	 *
	 * @since 1.29.0
	 *
	 * @param array $params Flat style params.
	 * @return array CSS props in $$type format.
	 */
	private static function build_position_props( array $params ): array {
		$props = array();

		if ( isset( $params['css_position'] ) ) {
			$props['position'] = Elementor_MCP_Atomic_Props::string( (string) $params['css_position'] );
		}

		// Logical inset names, as the schema declares them. Offsets only apply
		// to a non-static position — Elementor declares that dependency itself,
		// so a caller that sets one without the other simply gets no offset
		// rather than a broken rule.
		// LOGICAL names, matching the properties Elementor declares. The
		// inline axis follows text direction: on an RTL page inset-inline-end
		// is the LEFT edge. Publishing these as `offset_right`/`offset_left`
		// would have named the wrong edge on every Hebrew or Arabic page while
		// looking correct in LTR review.
		$offsets = array(
			'offset_block_start'  => 'inset-block-start',
			'offset_inline_end'   => 'inset-inline-end',
			'offset_block_end'    => 'inset-block-end',
			'offset_inline_start' => 'inset-inline-start',
		);

		foreach ( $offsets as $input_key => $css_prop ) {
			if ( isset( $params[ $input_key ] ) ) {
				$unit               = $params[ $input_key . '_unit' ] ?? 'px';
				$props[ $css_prop ] = Elementor_MCP_Atomic_Props::size( (float) $params[ $input_key ], $unit );
			}
		}

		if ( isset( $params['z_index'] ) ) {
			$props['z-index'] = Elementor_MCP_Atomic_Props::number( (int) $params['z_index'] );
		}

		return $props;
	}

	/**
	 * A single box-shadow.
	 *
	 * `box-shadow` is an ARRAY of `shadow` objects, and every shadow requires
	 * hOffset, vOffset, blur, spread and color — a partial shape is rejected
	 * (`modules/atomic-widgets/prop-types/{box-shadow,shadow}-prop-type.php`).
	 * The defaults here match Elementor's own initial values, so a caller can
	 * set just a colour and get a usable shadow.
	 *
	 * Unmapped until now: the design behind field report #4 used seven distinct
	 * shadows and all of them were dropped.
	 *
	 * @since 1.29.0
	 *
	 * @param array $params Flat style params.
	 * @return array CSS props in $$type format.
	 */
	private static function build_box_shadow_props( array $params ): array {
		$keys = array( 'shadow_color', 'shadow_x', 'shadow_y', 'shadow_blur', 'shadow_spread', 'shadow_inset' );

		$requested = false;
		foreach ( $keys as $key ) {
			if ( isset( $params[ $key ] ) ) {
				$requested = true;
				break;
			}
		}

		if ( ! $requested ) {
			return array();
		}

		$unit  = $params['shadow_unit'] ?? 'px';
		$value = array(
			'hOffset' => Elementor_MCP_Atomic_Props::size( (float) ( $params['shadow_x'] ?? 0 ), $unit ),
			'vOffset' => Elementor_MCP_Atomic_Props::size( (float) ( $params['shadow_y'] ?? 0 ), $unit ),
			'blur'    => Elementor_MCP_Atomic_Props::size( (float) ( $params['shadow_blur'] ?? 10 ), $unit ),
			'spread'  => Elementor_MCP_Atomic_Props::size( (float) ( $params['shadow_spread'] ?? 0 ), $unit ),
			'color'   => array(
				'$$type' => 'color',
				'value'  => (string) ( $params['shadow_color'] ?? 'rgba(0, 0, 0, 1)' ),
			),
		);

		if ( ! empty( $params['shadow_inset'] ) ) {
			$value['position'] = Elementor_MCP_Atomic_Props::string( 'inset' );
		}

		return array(
			'box-shadow' => array(
				'$$type' => 'box-shadow',
				'value'  => array(
					array(
						'$$type' => 'shadow',
						'value'  => $value,
					),
				),
			),
		);
	}

	/**
	 * Merges style props into an element's base style variant.
	 *
	 * `update-atomic-widget` only ever wrote `settings`, but on an atomic
	 * element size, spacing and appearance are NOT settings — they live in the
	 * `styles` map. A padding or width sent as a setting was merged, saved,
	 * reported successful and dropped by Elementor's parser, which is why three
	 * separate agents on the build behind field report #4 independently
	 * concluded that delete-and-recreate was the only reliable way to change a
	 * style. Correcting one card's padding meant rebuilding the card and
	 * everything inside it.
	 *
	 * Existing props on the variant are preserved — a partial update must not
	 * silently drop the styles it doesn't mention.
	 *
	 * @since 1.29.0
	 *
	 * @param array $element The target element (read-only).
	 * @param array $props   CSS props already in $$type form.
	 * @return array { class_id: string, styles: array } patch for update_element_settings().
	 */
	public static function build_props_patch( array $element, array $props ): array {
		$target   = self::target_local_class( $element );
		$variants = $target['variants'];
		$index    = $target['index'];

		$existing = ( isset( $variants[ $index ]['props'] ) && is_array( $variants[ $index ]['props'] ) )
			? $variants[ $index ]['props']
			: array();

		// Per-key merge is not enough for COMPOSITE props. On Elementor 4 the
		// four padding sides collapse into ONE `padding` prop holding a
		// `dimensions` shape, and `background` holds a nested colour plus any
		// gradient overlay. Replacing the whole envelope when the caller
		// touched one member would silently drop the other three sides — the
		// same partial-update data loss this tool exists to end, one level
		// down. Members are merged; LISTS (a box-shadow array, a gradient's
		// colour stops) are replaced wholesale, since a new list is a new
		// value rather than an edit.
		$merged = $existing;
		foreach ( $props as $key => $value ) {
			$merged[ $key ] = ( isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) && is_array( $value ) )
				? self::merge_prop_value( $existing[ $key ], $value )
				: $value;
		}

		$variants[ $index ]['props'] = $merged;

		$style_def             = $target['style_def'];
		$style_def['variants'] = $variants;

		return array(
			'class_id' => $target['class_id'],
			'styles'   => array( $target['class_id'] => $style_def ),
		);
	}

	/**
	 * Resolves the local class and base variant a style write should target.
	 *
	 * Shared by the custom-CSS and prop writers so they cannot disagree about
	 * which class owns an element's styles.
	 *
	 * @since 1.29.0
	 *
	 * @param array $element     The element.
	 * @param bool  $prefer_css  Prefer the class that already carries custom CSS.
	 * @return array { class_id, style_def, variants, index }
	 */
	private static function target_local_class( array $element, bool $prefer_css = false ): array {
		$element_id = isset( $element['id'] ) ? (string) $element['id'] : '';
		$styles     = ( isset( $element['styles'] ) && is_array( $element['styles'] ) ) ? $element['styles'] : array();

		$owner    = '';
		$fallback = '';
		foreach ( $styles as $existing_id => $existing_def ) {
			$existing_id = (string) $existing_id;
			if ( '' === $element_id || 0 !== strpos( $existing_id, 'e-' . $element_id . '-' ) ) {
				continue;
			}

			if ( '' === $fallback ) {
				$fallback = $existing_id;
			}

			if ( $prefer_css && '' === $owner && is_array( $existing_def ) && null !== self::find_base_variant_index( $existing_def, true ) ) {
				$owner = $existing_id;
			}
		}

		$class_id = $owner ?: $fallback;
		if ( '' === $class_id ) {
			$class_id = self::mint_class_id( $element_id );
		}

		$style_def = isset( $styles[ $class_id ] ) && is_array( $styles[ $class_id ] )
			? $styles[ $class_id ]
			: self::create_local_class( $element_id, array() )['style_def'];

		$style_def['id'] = $class_id;

		$variants = ( isset( $style_def['variants'] ) && is_array( $style_def['variants'] ) )
			? array_values( $style_def['variants'] )
			: array();

		$index = self::find_base_variant_index( array( 'variants' => $variants ) );

		if ( null === $index ) {
			$variants[] = array(
				'meta'       => array(
					'breakpoint' => 'desktop',
					'state'      => null,
				),
				'props'      => array(),
				'custom_css' => null,
			);
			$index      = count( $variants ) - 1;
		}

		return array(
			'class_id'  => $class_id,
			'style_def' => $style_def,
			'variants'  => $variants,
			'index'     => $index,
		);
	}

	/**
	 * Style params accepted by update-atomic-widget, including typography.
	 *
	 * @since 1.29.0
	 *
	 * @return string[]
	 */
	public static function widget_style_param_keys(): array {
		return array_merge(
			self::style_param_keys( false ),
			array( 'font_size', 'font_size_unit', 'font_family', 'font_weight', 'line_height', 'line_height_unit', 'letter_spacing', 'letter_spacing_unit', 'text_align' )
		);
	}

	/**
	 * Merges one typed prop value into another, member-wise.
	 *
	 * Associative maps (a `dimensions` shape's sides, a `background`'s colour)
	 * are merged key by key so a partial update keeps what it did not mention.
	 * Lists are replaced: a box-shadow array or a gradient's colour stops is a
	 * whole value, not a set of independently addressable members. Envelopes of
	 * different `$$type` are replaced too — they are different kinds of value.
	 *
	 * @since 1.29.0
	 *
	 * @param array $existing Stored prop value.
	 * @param array $incoming Incoming prop value.
	 * @return array
	 */
	private static function merge_prop_value( array $existing, array $incoming ): array {
		$existing_type = $existing['$$type'] ?? null;
		$incoming_type = $incoming['$$type'] ?? null;

		if ( $existing_type !== $incoming_type ) {
			return $incoming;
		}

		if ( ! isset( $existing['value'] ) || ! isset( $incoming['value'] ) || ! is_array( $existing['value'] ) || ! is_array( $incoming['value'] ) ) {
			return $incoming;
		}

		if ( ! self::is_assoc( $existing['value'] ) || ! self::is_assoc( $incoming['value'] ) ) {
			return $incoming;
		}

		$merged = $existing['value'];
		foreach ( $incoming['value'] as $key => $value ) {
			$merged[ $key ] = ( isset( $existing['value'][ $key ] ) && is_array( $existing['value'][ $key ] ) && is_array( $value ) )
				? self::merge_prop_value( $existing['value'][ $key ], $value )
				: $value;
		}

		$incoming['value'] = $merged;

		return $incoming;
	}

	/**
	 * Whether an array is associative. An empty array counts as a list.
	 *
	 * @since 1.29.0
	 *
	 * @param array $arr Array to inspect.
	 * @return bool
	 */
	private static function is_assoc( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}
