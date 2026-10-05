<?php
/**
 * Factory for building valid Elementor element JSON structures.
 *
 * @package Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds properly structured Elementor element arrays.
 *
 * @since 1.0.0
 */
class Elementor_MCP_Element_Factory {

	/**
	 * Navigator label on a CLASSIC LAYOUT element (container, section,
	 * column): classic Elementor serializes the Navigator name as
	 * `settings._title`, while an agent naturally sends the atomic spelling,
	 * `editor_settings.title`. Remap that one key; leave any other
	 * `editor_settings` member where the agent put it. The canonical key
	 * wins when both are present, and the alias is removed either way.
	 *
	 * ONE normalizer for creation AND update (Codex round-5 P2 on #74):
	 * `create_container()` (through normalize_container_settings()),
	 * `create_section()`, `create_column()` and
	 * `Elementor_MCP_Data::update_element_settings()` all go through here,
	 * so a payload that works on update also works on add-container and
	 * build-page. Never called for a widget: there `editor_settings` can be
	 * an ordinary compound control (this repo's widget builder registers
	 * controls with arbitrary names). Mirrors EMCP 3.16.x (#133); P6.2.
	 *
	 * @param array $settings Settings as the agent sent them.
	 * @return array Settings with the label where classic Elementor reads it.
	 */
	public static function normalize_classic_navigator_title( array $settings ): array {
		if ( ! isset( $settings['editor_settings'] ) || ! is_array( $settings['editor_settings'] ) || ! array_key_exists( 'title', $settings['editor_settings'] ) ) {
			return $settings;
		}
		if ( ! array_key_exists( '_title', $settings ) ) {
			$settings['_title'] = $settings['editor_settings']['title'];
		}
		unset( $settings['editor_settings']['title'] );
		if ( empty( $settings['editor_settings'] ) ) {
			unset( $settings['editor_settings'] );
		}
		return $settings;
	}

	/**
	 * Sub-keys of a classic `dimensions` / `gaps` value. Elementor's editor
	 * serialises every side as a string ("40", not 40); the CSS is the same
	 * either way, but the Layout panel hydrates strictly and shows 0 for a
	 * numeric side (P7.1, EMCP 3.17.1 #146).
	 *
	 * @since 1.40.0
	 */
	const DIMENSION_SIDES = array( 'top', 'right', 'bottom', 'left', 'column', 'row' );

	/**
	 * Registered control types whose values carry dimension sides.
	 *
	 * @since 1.40.0
	 */
	const DIMENSION_CONTROL_TYPES = array( 'dimensions', 'gaps' );

	/**
	 * Which setting keys of an element's control stack hold dimension values.
	 *
	 * Read from the REGISTERED control types, never from a value's shape: a
	 * custom widget may keep `{ unit, top }` in a control that is not a
	 * dimension control and read its numbers strictly (Codex r1 on #85).
	 * Repeater fields are mapped under their repeater's key.
	 *
	 * @since 1.40.0
	 *
	 * @param array $controls A control stack (`get_controls()`), keyed by control name.
	 * @return array{keys: array<string, true>, repeaters: array<string, array<string, true>>}
	 */
	public static function dimension_keys_from_controls( array $controls ): array {
		$map = array( 'keys' => array(), 'repeaters' => array() );
		foreach ( $controls as $name => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$name = isset( $control['name'] ) && is_string( $control['name'] ) ? $control['name'] : (string) $name;
			$type = isset( $control['type'] ) ? (string) $control['type'] : '';
			if ( in_array( $type, self::DIMENSION_CONTROL_TYPES, true ) ) {
				$map['keys'][ $name ] = true;
			} elseif ( 'repeater' === $type && ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
				$fields = self::dimension_keys_from_controls( $control['fields'] );
				if ( $fields['keys'] ) {
					$map['repeaters'][ $name ] = $fields['keys'];
				}
			}
		}
		return $map;
	}

	/**
	 * Cast numeric sides to strings in the settings the control map names.
	 *
	 * Only keys the map lists as dimension controls are touched, and only when
	 * the value is a plain side array (no `$$type` — atomic props have their
	 * own typed shape). Every other key, a slider's `size` included, is left
	 * exactly as given.
	 *
	 * @since 1.40.0
	 *
	 * @param array $settings Element settings.
	 * @param array $map      dimension_keys_from_controls() of the element's stack.
	 * @return array
	 */
	public static function normalize_dimension_settings( array $settings, array $map ): array {
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! is_array( $value ) ) {
				continue;
			}
			if ( isset( $map['keys'][ $key ] ) ) {
				$settings[ $key ] = self::stringify_sides( $value );
			} elseif ( isset( $map['repeaters'][ $key ] ) ) {
				foreach ( $value as $index => $item ) {
					if ( is_array( $item ) ) {
						$value[ $index ] = self::normalize_dimension_settings( $item, array( 'keys' => $map['repeaters'][ $key ], 'repeaters' => array() ) );
					}
				}
				$settings[ $key ] = $value;
			}
		}
		return $settings;
	}

	/**
	 * Apply normalize_dimension_settings() to the elements of a tree that a
	 * write adds or changes.
	 *
	 * An element whose id is in $before with identical settings is left as it
	 * is stored: normalising it would rewrite a node the write never touched,
	 * which the collateral diff reports as damage. An element whose control
	 * stack $controls_for cannot name (null) is left alone. Children are
	 * always walked.
	 *
	 * @since 1.40.0
	 *
	 * @param array    $elements     Element tree about to be saved.
	 * @param array    $before       Settings of the stored tree by element id (settings_by_id()).
	 * @param callable $controls_for fn( array $element ): ?array — the element's
	 *                               dimension_keys_from_controls() map, or null.
	 * @return array
	 */
	public static function normalize_dimension_tree( array $elements, array $before, callable $controls_for ): array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$id        = isset( $element['id'] ) && is_scalar( $element['id'] ) ? (string) $element['id'] : null;
				$untouched = null !== $id && array_key_exists( $id, $before ) && $before[ $id ] === $element['settings'];
				if ( ! $untouched ) {
					$map = $controls_for( $element );
					if ( is_array( $map ) && ( ! empty( $map['keys'] ) || ! empty( $map['repeaters'] ) ) ) {
						$element['settings'] = self::normalize_dimension_settings( $element['settings'], $map );
					}
				}
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::normalize_dimension_tree( $element['elements'], $before, $controls_for );
			}
			$elements[ $index ] = $element;
		}
		return $elements;
	}

	/**
	 * Settings of every element in a tree, keyed by element id. An id that
	 * occurs more than once maps to null, so none of its copies counts as
	 * untouched.
	 *
	 * @since 1.40.0
	 *
	 * @param array $elements Element tree.
	 * @return array<string, array|null>
	 */
	public static function settings_by_id( array $elements ): array {
		$map = array();
		self::collect_settings_by_id( $elements, $map );
		return $map;
	}

	/**
	 * @param array $elements Element tree.
	 * @param array $map      Accumulator.
	 * @return void
	 */
	private static function collect_settings_by_id( array $elements, array &$map ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && is_scalar( $element['id'] ) ) {
				$id         = (string) $element['id'];
				$settings   = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$map[ $id ] = array_key_exists( $id, $map ) ? null : $settings;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_settings_by_id( $element['elements'], $map );
			}
		}
	}

	/**
	 * Numeric sides of one dimension value as strings; anything else unchanged.
	 *
	 * @param array $value A dimension control's value.
	 * @return array
	 */
	private static function stringify_sides( array $value ): array {
		if ( isset( $value['$$type'] ) ) {
			return $value;
		}
		foreach ( self::DIMENSION_SIDES as $side ) {
			if ( isset( $value[ $side ] ) && ( is_int( $value[ $side ] ) || is_float( $value[ $side ] ) ) ) {
				$value[ $side ] = (string) $value[ $side ];
			}
		}
		return $value;
	}

	/** The four sides of a classic box dimension. */
	private const SIDES = array( 'top', 'right', 'bottom', 'left' );

	/**
	 * Setting keys treated as a classic box dimension when Elementor's own
	 * control cannot be asked. Deliberately closed: the universal update tool
	 * reaches custom widgets whose controls have arbitrary names, and a
	 * `padding_config` control with a `top` member is not a dimension (Codex
	 * round-4 P2 on #74).
	 */
	private const CLASSIC_BOX_KEY = '/^_?(?:margin|padding|border_radius|border_width)(?:_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile))?$/';

	/**
	 * Clean classic settings before they are written, and say what was done.
	 *
	 * Elementor's CSS generator drops the WHOLE rule for an element and
	 * breakpoint when one side of a dimension is blank — the valid sides
	 * included — and a blank side is never inherited. So a classic dimension
	 * with some, but not all, of its required sides blank is never written as
	 * sent (EMCP #151; references 2026-10-05 re-verification, P9.2):
	 *
	 * - Update (`$stored` is the element's current settings): the blank sides
	 *   are filled from the stored value of the same key when that value is
	 *   complete and in the same unit. Otherwise the key is not written and the
	 *   stored value stays as it is.
	 * - Create (`$stored` is null): there is nothing to fill from, so the key
	 *   is not written.
	 *
	 * Either way the caller is told, in `warnings`. A value with EVERY required
	 * side blank means "unset" and is written as sent; a complete value only
	 * has a blank unit resolved to px. A typed atomic prop (`$$type`) is a
	 * different data model and is not inspected, and neither is a slider.
	 *
	 * Until 1.40.x this was a warning beside a value left exactly as sent
	 * (settings_warnings(), mirroring EMCP 3.16): the agent was told, and the
	 * page still lost the rule. Owner decision 2026-10-05: fill or do not
	 * write.
	 *
	 * Also here, unchanged: a grid container created without `grid_rows_grid`
	 * gets Elementor's two-row default; warned at creation only.
	 *
	 * @since 1.41.0
	 *
	 * @param array      $settings The settings as the agent sent them.
	 * @param array|null $stored   The element's current settings on an update; null on creation.
	 * @param array      $context  The element, or its `elType` / `widgetType`.
	 * @return array{settings: array, warnings: string[]}
	 */
	public static function guard_settings( array $settings, ?array $stored = null, array $context = array() ): array {
		$warnings = array();
		$creating = null === $stored;

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! is_array( $value ) || ! self::is_guarded_dimension( $key, $value, $context ) ) {
				continue;
			}
			$required = self::required_sides( $key, $context );
			$blank    = self::blank_sides( $value, $required );
			if ( count( $blank ) === count( $required ) ) {
				continue; // Every required side blank: "unset", written as sent.
			}
			if ( 0 === count( $blank ) ) {
				$settings[ $key ] = self::with_resolved_unit( $value );
				continue;
			}

			$sides  = implode( ', ', $blank );
			$supply = 4 === count( $required ) ? 'all four sides' : implode( ' and ', $required );
			$saved  = $creating ? null : ( $stored[ $key ] ?? null );
			$usable = is_array( $saved ) && ! isset( $saved['$$type'] ) && 0 === count( self::blank_sides( $saved, $required ) );

			// Filling across units would change what the saved sides mean. A
			// missing or blank unit is Elementor's default, px, on either side.
			if ( $usable && self::dimension_unit( $saved ) === self::dimension_unit( $value ) ) {
				foreach ( $blank as $side ) {
					$value[ $side ] = $saved[ $side ];
				}
				$value = self::with_resolved_unit( $value );
				if ( ! array_key_exists( 'isLinked', $value ) && array_key_exists( 'isLinked', $saved ) ) {
					$value['isLinked'] = $saved['isLinked'];
				}
				$filled = array();
				foreach ( $required as $side ) {
					$filled[] = (string) $value[ $side ];
				}
				if ( count( array_unique( $filled ) ) > 1 ) {
					$value['isLinked'] = false;
				}
				$settings[ $key ] = $value;
				$warnings[]       = sprintf( '%1$s had blank sides (%2$s): filled from the saved value.', $key, $sides );
				continue;
			}

			unset( $settings[ $key ] );
			if ( $creating ) {
				$warnings[] = sprintf( '%1$s had blank sides (%2$s) and was not written, because Elementor drops the whole CSS rule when a side is blank. Supply %3$s (use 0 where intended).', $key, $sides, $supply );
			} elseif ( $usable ) {
				$warnings[] = sprintf( '%1$s had blank sides (%2$s) and a different unit from the saved value (%3$s vs %4$s); it was not written and the saved value is unchanged. Supply %5$s (use 0 where intended).', $key, $sides, self::dimension_unit( $value ), self::dimension_unit( $saved ), $supply );
			} elseif ( is_array( $saved ) && ! isset( $saved['$$type'] ) ) {
				$warnings[] = sprintf( '%1$s had blank sides (%2$s) and the saved value has blank sides too; it was not written and the saved value is unchanged. Supply %3$s (use 0 where intended).', $key, $sides, $supply );
			} else {
				$warnings[] = sprintf( '%1$s had blank sides (%2$s) and no saved value to fill from; it was not written. Supply %3$s (use 0 where intended).', $key, $sides, $supply );
			}
		}

		if ( $creating && 'grid' === ( $settings['container_type'] ?? '' ) && ! isset( $settings['grid_rows_grid'] ) ) {
			$warnings[] = 'Elementor defaults grid_rows_grid to 2 rows. For a single-row grid, explicitly set grid_rows_grid: {"unit":"fr","size":1} inside settings.';
		}

		return array(
			'settings' => $settings,
			'warnings' => $warnings,
		);
	}

	/**
	 * Whether a setting is a classic box dimension the guard applies to.
	 *
	 * Elementor's own control decides when it can be asked: a `dimensions`
	 * control is one, any other control is not. When the control cannot be
	 * found — outside the editor Elementor leaves style controls out of the
	 * stack — the closed key list decides instead.
	 *
	 * @param string $key     Setting key.
	 * @param array  $value   Setting value.
	 * @param array  $context The element, or its `elType` / `widgetType`.
	 * @return bool
	 */
	private static function is_guarded_dimension( string $key, array $value, array $context ): bool {
		if ( isset( $value['$$type'] ) || array_key_exists( 'size', $value ) ) {
			return false;
		}
		$control = self::live_control( $key, $context );
		if ( is_array( $control ) ) {
			return 'dimensions' === ( $control['type'] ?? '' );
		}
		return 1 === preg_match( self::CLASSIC_BOX_KEY, $key );
	}

	/**
	 * Sides a control needs for Elementor to emit its CSS: the control's own
	 * `allowed_dimensions` when it can be read, all four otherwise. A classic
	 * section's margin is vertical-only.
	 *
	 * @param string $key     Setting key.
	 * @param array  $context The element, or its `elType` / `widgetType`.
	 * @return string[]
	 */
	private static function required_sides( string $key, array $context ): array {
		$control = self::live_control( $key, $context );
		$allowed = is_array( $control ) ? ( $control['allowed_dimensions'] ?? 'all' ) : null;
		if ( null === $allowed && 'section' === ( $context['elType'] ?? '' ) && preg_match( '/^margin(?:_[a-z_]+)?$/', $key ) ) {
			$allowed = 'vertical';
		}
		if ( 'vertical' === $allowed ) {
			return array( 'top', 'bottom' );
		}
		if ( 'horizontal' === $allowed ) {
			return array( 'right', 'left' );
		}
		if ( is_array( $allowed ) ) {
			$sides = array_values( array_intersect( self::SIDES, $allowed ) );
			if ( count( $sides ) > 0 ) {
				return $sides;
			}
		}
		return self::SIDES;
	}

	/**
	 * The live Elementor control for a setting key, or null when Elementor, the
	 * element type or the control is not available. A responsive key
	 * (`margin_tablet`) falls back to its base control.
	 *
	 * @param string $key     Setting key.
	 * @param array  $context The element, or its `elType` / `widgetType`.
	 * @return array|null
	 */
	private static function live_control( string $key, array $context ): ?array {
		$el_type = isset( $context['elType'] ) && is_string( $context['elType'] ) ? $context['elType'] : '';
		if ( '' === $el_type || ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return null;
		}
		try {
			$plugin = \Elementor\Plugin::$instance;
			$stack  = null;
			if ( 'widget' === $el_type ) {
				$type    = isset( $context['widgetType'] ) && is_string( $context['widgetType'] ) ? $context['widgetType'] : '';
				$manager = $plugin->widgets_manager ?? null;
				if ( '' !== $type && is_object( $manager ) && method_exists( $manager, 'get_widget_types' ) ) {
					$stack = $manager->get_widget_types( $type );
				}
			} else {
				$manager = $plugin->elements_manager ?? null;
				if ( is_object( $manager ) && method_exists( $manager, 'get_element_types' ) ) {
					$stack = $manager->get_element_types( $el_type );
				}
			}
			if ( ! is_object( $stack ) || ! method_exists( $stack, 'get_controls' ) ) {
				return null;
			}
			$control = $stack->get_controls( $key );
			if ( ! is_array( $control ) || ! isset( $control['type'] ) ) {
				$base    = preg_replace( '/_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', '', $key );
				$control = $base !== $key ? $stack->get_controls( $base ) : null;
			}
			return is_array( $control ) && isset( $control['type'] ) ? $control : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Unit of a classic dimension value; missing or blank means px.
	 *
	 * @param array $value Dimension value.
	 * @return string
	 */
	private static function dimension_unit( array $value ): string {
		$unit = isset( $value['unit'] ) && is_string( $value['unit'] ) ? trim( $value['unit'] ) : '';
		return '' === $unit ? 'px' : $unit;
	}

	/**
	 * The value with a missing or blank unit set to px; Elementor emits no
	 * valid CSS for a blank unit.
	 *
	 * @param array $value Dimension value.
	 * @return array
	 */
	private static function with_resolved_unit( array $value ): array {
		$value['unit'] = self::dimension_unit( $value );
		return $value;
	}

	/**
	 * Sides that are missing or blank: null, false, an array, or a string that
	 * is empty after trim(). 0 and "0" are values.
	 *
	 * @param array    $value Dimension value.
	 * @param string[] $sides Sides to check.
	 * @return string[]
	 */
	private static function blank_sides( array $value, array $sides ): array {
		$blank = array();
		foreach ( $sides as $side ) {
			$v = $value[ $side ] ?? null;
			if ( null === $v || false === $v || is_array( $v ) || ( is_string( $v ) && '' === trim( $v ) ) ) {
				$blank[] = $side;
			}
		}
		return $blank;
	}

	/**
	 * Creates a container element.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings The container settings.
	 * @param array $children Child elements array.
	 * @return array The container element structure.
	 */
	public function create_container( array $settings = array(), array $children = array() ): array {
		$settings = self::normalize_container_settings( $settings );

		$defaults = array(
			'container_type' => 'flex',
			'content_width'  => 'boxed',
		);

		$merged = array_merge( $defaults, $settings );

		$is_grid   = ( 'grid' === ( $merged['container_type'] ?? 'flex' ) );
		$direction = $merged['flex_direction'] ?? '';
		$is_row    = ( 'row' === $direction || 'row-reverse' === $direction );

		// Auto-center alignment for flex column containers so widgets like
		// headings, icons, and text are centered on the page. Row
		// containers rely on Elementor's default flex behavior.
		// Grid containers handle alignment via grid_justify_items/grid_align_items.
		if ( ! $is_grid && ! $is_row && ! isset( $settings['flex_align_items'] ) ) {
			$merged['flex_align_items'] = 'center';
		}

		return array(
			'id'         => Elementor_MCP_Id_Generator::generate(),
			'elType'     => 'container',
			'widgetType' => null,
			'isInner'    => false,
			'settings'   => $merged,
			'elements'   => $children,
		);
	}

	/**
	 * Creates a widget element.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name (e.g. 'heading', 'button').
	 * @param array  $settings    The widget settings.
	 * @return array The widget element structure.
	 */
	public function create_widget( string $widget_type, array $settings = array() ): array {
		return array(
			'id'         => Elementor_MCP_Id_Generator::generate(),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'isInner'    => false,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Creates a section element (legacy layout).
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings The section settings.
	 * @param array $columns  Child column elements.
	 * @return array The section element structure.
	 */
	public function create_section( array $settings = array(), array $columns = array() ): array {
		return array(
			'id'         => Elementor_MCP_Id_Generator::generate(),
			'elType'     => 'section',
			'widgetType' => null,
			'isInner'    => false,
			'settings'   => self::normalize_classic_navigator_title( $settings ),
			'elements'   => $columns,
		);
	}

	/**
	 * Creates a column element (legacy layout).
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings The column settings.
	 * @param array $widgets  Child widget elements.
	 * @return array The column element structure.
	 */
	public function create_column( array $settings = array(), array $widgets = array() ): array {
		$defaults = array(
			'_column_size' => 100,
		);

		return array(
			'id'         => Elementor_MCP_Id_Generator::generate(),
			'elType'     => 'column',
			'widgetType' => null,
			'isInner'    => false,
			'settings'   => array_merge( $defaults, self::normalize_classic_navigator_title( $settings ) ),
			'elements'   => $widgets,
		);
	}

	/**
	 * Map of MCP-shorthand container keys to the keys Elementor's flex group
	 * actually reads. Without this remap, settings like `justify_content` and
	 * `align_items` are persisted under names Elementor's CSS generator never
	 * looks at, so the corresponding `--justify-content` / `--align-items`
	 * custom properties never get emitted and the container renders with
	 * default alignment on the front-end (issue #32).
	 *
	 * @since 1.4.4
	 *
	 * @var array<string, string>
	 */
	private const CONTAINER_KEY_ALIASES = array(
		'justify_content' => 'flex_justify_content',
		'align_items'     => 'flex_align_items',
		'align_content'   => 'flex_align_content',
	);

	/**
	 * Rewrites the unprefixed flex shorthand keys (`justify_content`,
	 * `align_items`, `align_content`) to the prefixed keys that Elementor's
	 * container schema reads.
	 *
	 * Caller-supplied prefixed keys win over the aliased shorthand if both
	 * are provided in the same payload.
	 *
	 * @since 1.4.4
	 *
	 * @param array $settings Raw container settings.
	 * @return array Settings with shorthand keys remapped.
	 */
	public static function normalize_container_settings( array $settings ): array {
		// The Navigator label alias is a classic-layout alias like the flex
		// shorthands below, normalized in the same pass (creation and update).
		$settings = self::normalize_classic_navigator_title( $settings );
		foreach ( self::CONTAINER_KEY_ALIASES as $shorthand => $flex_key ) {
			if ( ! array_key_exists( $shorthand, $settings ) ) {
				continue;
			}

			if ( ! array_key_exists( $flex_key, $settings ) ) {
				$settings[ $flex_key ] = $settings[ $shorthand ];
			}

			unset( $settings[ $shorthand ] );
		}

		return $settings;
	}

	// =========================================================================
	// Atomic elements (Elementor 4.0+)
	// =========================================================================

	/**
	 * Creates an atomic widget element (Elementor 4.0+).
	 *
	 * Atomic widgets use the same elType=widget structure but with $$type-wrapped
	 * settings and additional top-level keys (styles, interactions).
	 *
	 * @since 1.5.0
	 *
	 * @param string $widget_type The atomic widget type (e.g. 'e-heading', 'e-button').
	 * @param array  $settings    The widget settings (already $$type-wrapped).
	 * @return array The atomic widget element structure.
	 */
	public function create_atomic_widget( string $widget_type, array $settings = array(), array $style_props = array() ): array {
		$id = Elementor_MCP_Id_Generator::generate();

		if ( ! isset( $settings['classes'] ) ) {
			$settings['classes'] = Elementor_MCP_Atomic_Props::classes();
		}

		$element = array(
			'id'              => $id,
			'elType'          => 'widget',
			'widgetType'      => $widget_type,
			'isInner'         => false,
			'settings'        => $settings,
			'elements'        => array(),
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		// Build and apply widget styles if provided (mirrors create_flexbox).
		$common_css = Elementor_MCP_Atomic_Styles::build_common_props( $style_props );
		$typo_css   = Elementor_MCP_Atomic_Styles::build_typography_props( $style_props );
		$all_css    = array_merge( $common_css, $typo_css );

		if ( ! empty( $all_css ) ) {
			$style = Elementor_MCP_Atomic_Styles::create_local_class( $id, $all_css );
			Elementor_MCP_Atomic_Styles::apply_to_element( $element, $style['class_id'], $style['style_def'] );
		}

		return $element;
	}

	/**
	 * Creates an atomic flexbox container (Elementor 4.0+).
	 *
	 * @since 1.5.0
	 *
	 * @param array  $settings    Container settings ($$type-wrapped props).
	 * @param array  $children    Child elements.
	 * @param array  $style_props Flat layout params to convert into a local style class.
	 * @return array The flexbox element structure.
	 */
	public function create_flexbox( array $settings = array(), array $children = array(), array $style_props = array() ): array {
		$id = Elementor_MCP_Id_Generator::generate();

		if ( ! isset( $settings['tag'] ) ) {
			$settings['tag'] = Elementor_MCP_Atomic_Props::string( 'div' );
		}
		if ( ! isset( $settings['classes'] ) ) {
			$settings['classes'] = Elementor_MCP_Atomic_Props::classes();
		}

		$element = array(
			'id'              => $id,
			'elType'          => 'e-flexbox',
			'settings'        => $settings,
			'elements'        => $children,
			'isInner'         => false,
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		// Build and apply flex layout styles if provided.
		$flex_css = Elementor_MCP_Atomic_Styles::build_flex_props( $style_props );
		$common_css = Elementor_MCP_Atomic_Styles::build_common_props( $style_props );
		$all_css = array_merge( $flex_css, $common_css );

		if ( ! empty( $all_css ) ) {
			$style = Elementor_MCP_Atomic_Styles::create_local_class( $id, $all_css );
			Elementor_MCP_Atomic_Styles::apply_to_element( $element, $style['class_id'], $style['style_def'] );
		}

		return $element;
	}

	/**
	 * Creates an atomic div-block container (Elementor 4.0+).
	 *
	 * @since 1.5.0
	 *
	 * @param array $settings    Container settings ($$type-wrapped props).
	 * @param array $children    Child elements.
	 * @param array $style_props Flat style params to convert into a local style class.
	 * @return array The div-block element structure.
	 */
	public function create_div_block( array $settings = array(), array $children = array(), array $style_props = array() ): array {
		$id = Elementor_MCP_Id_Generator::generate();

		if ( ! isset( $settings['tag'] ) ) {
			$settings['tag'] = Elementor_MCP_Atomic_Props::string( 'div' );
		}
		if ( ! isset( $settings['classes'] ) ) {
			$settings['classes'] = Elementor_MCP_Atomic_Props::classes();
		}

		$element = array(
			'id'              => $id,
			'elType'          => 'e-div-block',
			'settings'        => $settings,
			'elements'        => $children,
			'isInner'         => false,
			'styles'          => array(),
			'interactions'    => array(),
			'editor_settings' => array(),
			'version'         => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		);

		$common_css = Elementor_MCP_Atomic_Styles::build_common_props( $style_props );

		if ( ! empty( $common_css ) ) {
			$style = Elementor_MCP_Atomic_Styles::create_local_class( $id, $common_css );
			Elementor_MCP_Atomic_Styles::apply_to_element( $element, $style['class_id'], $style['style_def'] );
		}

		return $element;
	}
}
