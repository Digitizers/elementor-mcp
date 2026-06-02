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
		$class_id = 'e-' . $element_id . '-' . substr( bin2hex( random_bytes( 4 ) ), 0, 7 );

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

		// string-typed props: input key => css prop.
		$string_props = array(
			'font_family' => 'font-family',
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
}
