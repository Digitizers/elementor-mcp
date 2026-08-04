<?php
/**
 * Tool-result shape normalizer.
 *
 * MCP's `structuredContent` must be a JSON object; strict clients reject a
 * top-level list or scalar. Normalization lives in the plugin — not in the
 * vendored MCP adapter — so it survives adapter updates (upstream 3.6.1).
 *
 * @package Elementor_MCP
 * @since   1.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes ability results into JSON-object shape.
 *
 * @since 1.27.0
 */
class Elementor_MCP_Result_Normalizer {

	/**
	 * Coerces a tool result into a JSON object, leaving anything already
	 * object-shaped untouched.
	 *
	 * Associative arrays and objects pass through unchanged, which is what the
	 * overwhelming majority of abilities return. Lists, scalars and null are
	 * wrapped in a `data` key. `WP_Error` is returned untouched so the
	 * adapter's error handling still sees it.
	 *
	 * Note that PHP cannot distinguish an empty list from an empty map, so
	 * `array()` is wrapped too. That is deliberate: unwrapped it serialises to
	 * `[]`, which is exactly the invalid shape this guards against.
	 *
	 * @since 1.27.0
	 *
	 * @param mixed $result The raw ability result.
	 * @return mixed
	 */
	public static function normalize( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Already a JSON object: a string-keyed array, or any object that is
		// not an error. Leave the shape the ability intended.
		if ( is_array( $result ) && ! array_is_list( $result ) ) {
			return $result;
		}
		if ( is_object( $result ) ) {
			return $result;
		}

		return array( 'data' => $result );
	}
}
