<?php
/**
 * Auto-generates JSON Schema from Elementor widget control definitions.
 *
 * @package Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates JSON Schema for widget settings based on Elementor's control registry.
 *
 * @since 1.0.0
 */
class Elementor_MCP_Schema_Generator {

	/**
	 * Generates a JSON Schema for a widget type's settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name (e.g. 'heading', 'button').
	 * @return array|\WP_Error JSON Schema array on success, WP_Error if widget not found.
	 */
	public function generate( string $widget_type ) {
		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
		$widget          = $widgets_manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			// Schema-in-error: return the nearest valid widget type names so an
			// agent that used a wrong name can self-correct in one round trip. The
			// MCP adapter surfaces only the message + code (it drops WP_Error data),
			// so the suggestions go into the MESSAGE; data is kept for REST callers.
			$suggestions = $this->suggest_types( $widget_type );
			return new \WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget type name */
					__( 'Widget type "%s" not found.', 'elementor-mcp' ),
					$widget_type
				) . self::format_suggestions( $suggestions ),
				array(
					'requested'   => $widget_type,
					'suggestions' => $suggestions,
				)
			);
		}

		$controls   = $this->get_full_controls( $widget );
		$properties = array();

		if ( is_array( $controls ) ) {
			foreach ( $controls as $control_id => $control ) {
				$control_type = $control['type'] ?? '';

				if ( Elementor_MCP_Control_Mapper::should_skip( $control_type ) ) {
					continue;
				}

				$schema_fragment = Elementor_MCP_Control_Mapper::map( $control );
				if ( ! empty( $schema_fragment ) ) {
					$properties[ $control_id ] = $schema_fragment;
				}
			}
		}

		return array(
			'type'        => 'object',
			'description' => sprintf(
				/* translators: %s: widget title */
				__( 'Settings for the %s widget.', 'elementor-mcp' ),
				$widget->get_title()
			),
			'properties'  => $properties,
		);
	}

	/**
	 * Suggest the valid widget type names closest to a (typically mistyped) one,
	 * ranked: exact, then substring/contained, then smallest edit distance. Used to
	 * put "did you mean…" candidates inline in a widget_not_found error so an agent
	 * can correct a wrong widget name without a second round trip.
	 *
	 * @since 1.20.0
	 *
	 * @param string $bad   The requested (unknown) widget type.
	 * @param int    $limit Max suggestions to return.
	 * @return string[] Closest valid widget type names, best first.
	 */
	public function suggest_types( string $bad, int $limit = 6 ): array {
		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
		$all             = $widgets_manager->get_widget_types();
		if ( ! is_array( $all ) || empty( $all ) ) {
			return array();
		}

		$bad_l  = strtolower( trim( $bad ) );
		$scored = array();
		foreach ( array_keys( $all ) as $name ) {
			$name = (string) $name;
			$n    = strtolower( $name );
			if ( '' === $n ) {
				continue;
			}
			if ( '' === $bad_l ) {
				$score = 0; // no basis to rank — keep registration order
			} elseif ( $n === $bad_l ) {
				$score = 0;
			} elseif ( false !== strpos( $n, $bad_l ) || false !== strpos( $bad_l, $n ) ) {
				$score = 1; // one contains the other
			} else {
				$score = 3 + levenshtein( $bad_l, $n );
			}
			$scored[ $name ] = $score;
		}
		asort( $scored, SORT_NUMERIC );
		return array_slice( array_keys( $scored ), 0, max( 0, $limit ) );
	}

	/**
	 * Render a "did you mean" suffix for an error MESSAGE (the MCP adapter drops
	 * WP_Error data, so suggestions must live in the message to reach agents).
	 *
	 * @since 1.21.0
	 *
	 * @param string[] $suggestions Candidate widget type names.
	 * @return string Leading-space-prefixed suffix, or '' when there are none.
	 */
	public static function format_suggestions( array $suggestions ): string {
		$suggestions = array_values( array_filter( array_map( 'strval', $suggestions ) ) );
		if ( empty( $suggestions ) ) {
			return '';
		}
		return ' Did you mean: ' . implode( ', ', $suggestions ) . '?';
	}

	/**
	 * Returns a widget's COMPLETE control set, including the style controls
	 * (typography, colours, alignment, shadows…) that Elementor's "Optimized
	 * Control Loading" strips from get_controls() outside the editor.
	 *
	 * Elementor stores those controls separately and only merges them back when
	 * Performance::is_use_style_controls() is true — the same supported toggle
	 * its own CSS generator (core/files/css/base.php) uses. Without this, the
	 * schema is incomplete in non-editor contexts (notably the WP-CLI/stdio MCP
	 * bridge and any non-REST execution), so agents can't discover styling
	 * controls and settings validation can't recognise them.
	 *
	 * @since 2.2.0
	 *
	 * @param object $widget The Elementor widget instance.
	 * @return array The full controls array.
	 */
	private function get_full_controls( $widget ): array {
		$perf = '\Elementor\Core\Frontend\Performance';

		// Older Elementor without the Performance toggle: nothing to do.
		if ( ! class_exists( $perf ) || ! method_exists( $perf, 'set_use_style_controls' ) ) {
			$controls = $widget->get_controls();
			return is_array( $controls ) ? $controls : array();
		}

		$previous = method_exists( $perf, 'is_use_style_controls' ) ? $perf::is_use_style_controls() : false;
		$perf::set_use_style_controls( true );

		try {
			$controls = $widget->get_controls();
		} finally {
			// Always restore so we don't change CSS generation / rendering for
			// the rest of the request.
			$perf::set_use_style_controls( $previous );
		}

		return is_array( $controls ) ? $controls : array();
	}

	/**
	 * Generates schemas for all registered widgets.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of widget_type => JSON Schema.
	 */
	public function generate_all(): array {
		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
		$widgets         = $widgets_manager->get_widget_types();
		$schemas         = array();

		foreach ( $widgets as $name => $widget ) {
			$schema = $this->generate( $name );
			if ( ! is_wp_error( $schema ) ) {
				$schemas[ $name ] = $schema;
			}
		}

		return $schemas;
	}
}
