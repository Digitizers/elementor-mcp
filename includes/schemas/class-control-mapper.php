<?php
/**
 * Maps Elementor control types to JSON Schema definitions.
 *
 * @package Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps individual Elementor control definitions to JSON Schema fragments.
 *
 * @since 1.0.0
 */
class Elementor_MCP_Control_Mapper {

	/**
	 * Control types that are structural/UI-only and should be skipped.
	 *
	 * @var string[]
	 */
	private static $skip_types = array(
		'section',
		'tab',
		'tabs',
		'divider',
		'heading',
		'raw_html',
		'notice',
		'deprecated_notice',
		'alert',
		'button',
	);

	/**
	 * Checks whether a control type should be skipped (structural/UI-only).
	 *
	 * @since 1.0.0
	 *
	 * @param string $type The control type string.
	 * @return bool True if the control should be skipped.
	 */
	public static function should_skip( string $type ): bool {
		return in_array( $type, self::$skip_types, true );
	}

	/**
	 * Maps an Elementor control array to a JSON Schema fragment.
	 *
	 * @since 1.0.0
	 *
	 * @param array $control The Elementor control definition array.
	 * @return array JSON Schema fragment, or empty array if unmappable.
	 */
	public static function map( array $control ): array {
		$type = $control['type'] ?? '';

		if ( self::should_skip( $type ) ) {
			return array();
		}

		$schema = self::map_type( $type, $control );

		if ( empty( $schema ) ) {
			return array();
		}

		if ( ! empty( $control['label'] ) ) {
			$schema['description'] = $control['label'];
		}

		if ( isset( $control['default'] ) && ! is_array( $control['default'] ) ) {
			$schema['default'] = $control['default'];
		}

		return $schema;
	}

	/**
	 * Maps a control type string to its JSON Schema representation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    The control type string.
	 * @param array  $control The full control definition array.
	 * @return array JSON Schema fragment.
	 */
	private static function map_type( string $type, array $control ): array {
		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'wysiwyg':
			case 'code':
			case 'font':
			case 'animation':
			case 'hover_animation':
			case 'exit_animation':
				return array( 'type' => 'string' );

			case 'number':
				$number = array( 'type' => 'number' );
				// Elementor's number control carries min/max/step directly (no unit),
				// so the range is unambiguous — emit it as JSON-Schema constraints.
				if ( isset( $control['min'] ) && is_numeric( $control['min'] ) ) {
					$number['minimum'] = $control['min'] + 0;
				}
				if ( isset( $control['max'] ) && is_numeric( $control['max'] ) ) {
					$number['maximum'] = $control['max'] + 0;
				}
				if ( isset( $control['step'] ) && is_numeric( $control['step'] ) && (float) $control['step'] > 0 ) {
					$number['multipleOf'] = $control['step'] + 0;
				}
				return $number;

			case 'hidden':
				return array( 'type' => 'string' );

			case 'slider':
				$size = array( 'type' => 'number' );
				$range = ( isset( $control['range'] ) && is_array( $control['range'] ) ) ? $control['range'] : array();
				// The units the control actually offers: size_units if declared,
				// otherwise inferred from the range's unit keys.
				$units = array();
				if ( ! empty( $control['size_units'] ) && is_array( $control['size_units'] ) ) {
					$units = array_values( array_unique( array_filter( $control['size_units'], 'is_string' ) ) );
				} elseif ( ! empty( $range ) ) {
					$units = array_values( array_unique( array_filter( array_keys( $range ), 'is_string' ) ) );
				}
				// Slider bounds are keyed by unit and differ per unit, so a hard size
				// min/max is only unambiguous when the control offers exactly ONE unit
				// and the range defines it. A slider advertising several size_units but
				// a range for only one must NOT borrow that unit's bounds for the others.
				if ( 1 === count( $units ) && isset( $range[ $units[0] ] ) && is_array( $range[ $units[0] ] ) ) {
					$bounds = $range[ $units[0] ];
					if ( isset( $bounds['min'] ) && is_numeric( $bounds['min'] ) ) {
						$size['minimum'] = $bounds['min'] + 0;
					}
					if ( isset( $bounds['max'] ) && is_numeric( $bounds['max'] ) ) {
						$size['maximum'] = $bounds['max'] + 0;
					}
				}
				$unit = array( 'type' => 'string' );
				if ( ! empty( $units ) ) {
					$unit['enum'] = $units;
				}
				return array(
					'type'       => 'object',
					'properties' => array(
						'size' => $size,
						'unit' => $unit,
					),
				);

			case 'select':
			case 'select2':
				return self::map_select( $control );

			case 'choose':
			case 'visual_choice':
				return self::map_choose( $control );

			case 'switcher':
				return array(
					'type'        => 'string',
					'enum'        => array( 'yes' ),
					'description' => __( 'Toggle switch. Use "yes" to enable, omit or send empty string to disable.', 'elementor-mcp' ),
				);

			case 'color':
				return array(
					'type'        => 'string',
					'description' => 'Hex or RGBA color value',
				);

			case 'url':
				return array(
					'type'       => 'object',
					'properties' => array(
						'url'         => array( 'type' => 'string' ),
						'is_external' => array( 'type' => 'boolean' ),
						'nofollow'    => array( 'type' => 'boolean' ),
					),
				);

			case 'media':
				return array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string' ),
						'id'  => array( 'type' => 'integer' ),
					),
				);

			case 'icons':
			case 'icon':
				return array(
					'type'        => 'object',
					'description' => 'Font Awesome: { "value": "fas fa-star", "library": "fa-solid" }. SVG: { "value": { "id": 123, "url": "..." }, "library": "svg" }.',
					'properties'  => array(
						'value'   => array(
							'description' => 'Font Awesome class string (e.g. "fas fa-star") or SVG object { "id": attachment_id, "url": "..." }.',
						),
						'library' => array(
							'type'        => 'string',
							'description' => 'Icon library: fa-solid, fa-regular, fa-brands, or svg.',
						),
					),
				);

			case 'dimensions':
				return array(
					'type'       => 'object',
					'properties' => array(
						'top'      => array( 'type' => 'string' ),
						'right'    => array( 'type' => 'string' ),
						'bottom'   => array( 'type' => 'string' ),
						'left'     => array( 'type' => 'string' ),
						'unit'     => array( 'type' => 'string' ),
						'isLinked' => array( 'type' => 'boolean' ),
					),
				);

			case 'repeater':
				return self::map_repeater( $control );

			case 'date_time':
				return array(
					'type'   => 'string',
					'format' => 'date-time',
				);

			case 'gallery':
				return array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'  => array( 'type' => 'integer' ),
							'url' => array( 'type' => 'string' ),
						),
					),
				);

			case 'image_dimensions':
				return array(
					'type'       => 'object',
					'properties' => array(
						'width'  => array( 'type' => 'integer' ),
						'height' => array( 'type' => 'integer' ),
					),
				);

			case 'box_shadow':
				return array(
					'type'       => 'object',
					'properties' => array(
						'horizontal' => array( 'type' => 'integer' ),
						'vertical'   => array( 'type' => 'integer' ),
						'blur'       => array( 'type' => 'integer' ),
						'spread'     => array( 'type' => 'integer' ),
						'color'      => array( 'type' => 'string' ),
					),
				);

			case 'text_shadow':
				return array(
					'type'       => 'object',
					'properties' => array(
						'horizontal' => array( 'type' => 'integer' ),
						'vertical'   => array( 'type' => 'integer' ),
						'blur'       => array( 'type' => 'integer' ),
						'color'      => array( 'type' => 'string' ),
					),
				);

			case 'popover_toggle':
				return array(
					'type'        => 'string',
					'enum'        => array( 'yes' ),
					'description' => __( 'Popover toggle. Use "yes" to enable, omit or send empty string to disable.', 'elementor-mcp' ),
				);

			case 'gaps':
				return array(
					'type'       => 'object',
					'properties' => array(
						'column'   => array( 'type' => 'string' ),
						'row'      => array( 'type' => 'string' ),
						'unit'     => array( 'type' => 'string' ),
						'isLinked' => array( 'type' => 'boolean' ),
					),
				);

			default:
				// Fallback for unknown types.
				return array( 'type' => 'string' );
		}
	}

	/**
	 * Maps a select control to JSON Schema with enum values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $control The control definition.
	 * @return array JSON Schema fragment.
	 */
	private static function map_select( array $control ): array {
		$schema = array( 'type' => 'string' );

		if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
			$enum = array_values(
				array_filter(
					array_keys( $control['options'] ),
					function ( $value ) {
						return '' !== $value;
					}
				)
			);
			if ( ! empty( $enum ) ) {
				$schema['enum'] = $enum;
			}
		}

		return $schema;
	}

	/**
	 * Maps a choose control to JSON Schema with enum values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $control The control definition.
	 * @return array JSON Schema fragment.
	 */
	private static function map_choose( array $control ): array {
		$schema = array( 'type' => 'string' );

		if ( ! empty( $control['options'] ) && is_array( $control['options'] ) ) {
			$enum = array_values(
				array_filter(
					array_keys( $control['options'] ),
					function ( $value ) {
						return '' !== $value;
					}
				)
			);
			if ( ! empty( $enum ) ) {
				$schema['enum'] = $enum;
			}
		}

		return $schema;
	}

	/**
	 * Maps a repeater control to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @param array $control The control definition.
	 * @return array JSON Schema fragment.
	 */
	private static function map_repeater( array $control ): array {
		$schema = array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(),
			),
		);

		if ( ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
			foreach ( $control['fields'] as $field ) {
				$field_name = $field['name'] ?? '';
				if ( empty( $field_name ) ) {
					continue;
				}

				$field_schema = self::map( $field );
				if ( ! empty( $field_schema ) ) {
					$schema['items']['properties'][ $field_name ] = $field_schema;
				}
			}
		}

		return $schema;
	}
}
