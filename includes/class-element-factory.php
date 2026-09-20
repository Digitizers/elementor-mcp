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
	 * Warnings about settings that PERSIST but will probably not do what the
	 * agent meant — a channel beside success, never a refusal, never a
	 * coercion (mirrors EMCP 3.16.x; P6.2 of the 2026-09-18 reverification).
	 *
	 * - Partial classic dimensions (`margin`, `padding`, `border_radius`,
	 *   `border_width`, their `_`-prefixed variants and Elementor's own
	 *   responsive suffixes — `_widescreen`, `_laptop`, `_tablet_extra`,
	 *   `_tablet`, `_mobile_extra`, `_mobile` — and NOTHING else: the universal
	 *   update tool reaches custom widgets whose controls have arbitrary
	 *   names, and a `padding_config` control with a `top` member is not a
	 *   dimension, so an open suffix produced false warnings, Codex round-4
	 *   P2 on #74): when 1–3 of the four sides are blank or missing, Elementor
	 *   may omit the ENTIRE CSS rule. The value is left exactly as sent — a
	 *   blank side coerced to 0 would silently destroy inheritance, which is
	 *   worse than the rule being dropped (upstream #134). A typed atomic prop
	 *   (`$$type`) is a different data model and is not inspected.
	 * - A grid container created without `grid_rows_grid` gets Elementor's
	 *   two-row default; warned at creation only — an update never re-warns
	 *   about a default the element already has (upstream #135).
	 *
	 * @param array $settings The settings as the agent sent them.
	 * @param bool  $creating Whether this is element creation (add-container).
	 * @return string[] Human-readable warnings; empty when there is nothing to say.
	 */
	public static function settings_warnings( array $settings, bool $creating = false ): array {
		$warnings = array();
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^_?(?:margin|padding|border_radius|border_width)(?:_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile))?$/', $key ) ) {
				continue;
			}
			if ( ! is_array( $value ) || isset( $value['$$type'] ) ) {
				continue;
			}
			$blank = array();
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				if ( ! isset( $value[ $side ] ) || '' === $value[ $side ] ) {
					$blank[] = $side;
				}
			}
			if ( count( $blank ) > 0 && count( $blank ) < 4 ) {
				$warnings[] = sprintf(
					'%s has blank or missing sides (%s). Elementor may omit the entire CSS rule. Supply all four sides (use 0 where intended); values were left unchanged to preserve inheritance.',
					$key,
					implode( ', ', $blank )
				);
			}
		}
		if ( $creating && 'grid' === ( $settings['container_type'] ?? '' ) && ! isset( $settings['grid_rows_grid'] ) ) {
			$warnings[] = 'Elementor defaults grid_rows_grid to 2 rows. For a single-row grid, explicitly set grid_rows_grid: {"unit":"fr","size":1} inside settings.';
		}
		return $warnings;
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
