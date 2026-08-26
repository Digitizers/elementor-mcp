<?php
/**
 * Bundled MCP Adapter bootstrap.
 *
 * The plugin ships a copy of the WordPress MCP Adapter (`wordpress/mcp-adapter`)
 * under includes/vendors/mcp-adapter/ so users never have to install it as a
 * separate plugin. The Abilities API is core in WordPress 6.9+/7.0, but core
 * does NOT expose abilities over MCP — the adapter is still what creates the
 * `/wp-json/mcp/...` server endpoint. Bundling it makes EMCP self-contained.
 *
 * If a standalone MCP Adapter plugin is already active, we defer to it (its
 * classes are loaded first at plugin-include time, before this runs on
 * plugins_loaded) and do nothing — so there's never a double-load or version
 * clash. We only boot the bundled copy when nothing else has.
 *
 * Two source trees are bundled, and BOTH are required at runtime. Until 0.4.1
 * the adapter had no runtime dependencies and only its `includes/` needed
 * shipping. Since 0.5.0 it is built on `wordpress/php-mcp-schema` — every MCP
 * response is a typed DTO from `WP\McpSchema\*` — so shipping `includes/`
 * alone would fatal on the first tool call with a missing DTO class. We ship
 * the schema package's `src/` too and register a PSR-4 prefix for each,
 * rather than loading the adapter's Composer autoloader.
 *
 * @package Elementor_MCP
 * @since   1.7.4
 * @since   1.33.0 Bundled adapter 0.4.1 -> 0.6.1; php-mcp-schema bundled with it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads/boots the MCP Adapter — bundled copy or already-active external one.
 *
 * @since 1.7.4
 */
final class Elementor_MCP_Adapter_Bootstrap {

	/**
	 * Version of the bundled adapter (keep in sync with the copied source).
	 */
	const BUNDLED_VERSION = '0.6.1';

	/**
	 * Version of the adapter we actually deferred to, when an external one won.
	 *
	 * An external adapter is whatever another plugin loaded FIRST — not the
	 * newest one installed. On a real site (2026-08-26) four plugins each
	 * bundled a copy and the OLDEST won, because load order decides and
	 * pre-0.6.0 copies carry no Jetpack Autoloader to arbitrate. `ensure()`
	 * cannot fix that, but it can stop the state being invisible: the
	 * connection screen can say which version is live.
	 *
	 * @var string
	 */
	private static $external_version = '';

	/**
	 * Where the adapter came from: 'external', 'bundled', or 'none'.
	 *
	 * @var string
	 */
	private static $source = 'none';

	/**
	 * Ensures the MCP Adapter is available, booting the bundled copy if needed.
	 *
	 * Safe to call once, early in plugin init (before the dependency check).
	 *
	 * @since 1.7.4
	 */
	public static function ensure(): void {
		// A standalone MCP Adapter plugin is already active — defer to it.
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			self::$source           = 'external';
			self::$external_version = self::detect_external_version();
			return;
		}

		$base = ELEMENTOR_MCP_DIR . 'includes/vendors/mcp-adapter/includes/';
		if ( ! is_dir( $base ) ) {
			self::$source = 'none';
			return;
		}

		// Minimal PSR-4 autoloader for both bundled trees. `WP\McpSchema\` must
		// be registered too: since adapter 0.5.0 every MCP response is a DTO from
		// that package, so a missing prefix is a fatal on the first tool call
		// rather than a quietly degraded feature.
		$schema = ELEMENTOR_MCP_DIR . 'includes/vendors/mcp-adapter/vendor/wordpress/php-mcp-schema/src/';
		$roots  = array(
			'WP\\McpSchema\\' => $schema, // longest prefix first — WP\MCP\ would otherwise swallow it
			'WP\\MCP\\'       => $base,
		);
		spl_autoload_register(
			static function ( $class ) use ( $roots ) {
				foreach ( $roots as $prefix => $root ) {
					if ( 0 !== strpos( $class, $prefix ) ) {
						continue;
					}
					$relative = substr( $class, strlen( $prefix ) );
					$file     = $root . str_replace( '\\', '/', $relative ) . '.php';
					if ( is_readable( $file ) ) {
						require_once $file;
					}
					return;
				}
			}
		);

		// The standalone plugin defines these in its bootstrap; replicate for
		// parity. WP_MCP_AUTOLOAD=false stops the adapter's own Autoloader from
		// looking for a Composer vendor/autoload.php we intentionally didn't ship.
		if ( ! defined( 'WP_MCP_DIR' ) ) {
			define( 'WP_MCP_DIR', ELEMENTOR_MCP_DIR . 'includes/vendors/mcp-adapter/' );
		}
		if ( ! defined( 'WP_MCP_VERSION' ) ) {
			define( 'WP_MCP_VERSION', self::BUNDLED_VERSION );
		}
		if ( ! defined( 'WP_MCP_AUTOLOAD' ) ) {
			define( 'WP_MCP_AUTOLOAD', false );
		}

		// Boot the adapter exactly as its standalone plugin would: Plugin::instance()
		// wires McpAdapter onto rest_api_init / init, which fires mcp_adapter_init.
		if ( class_exists( '\WP\MCP\Plugin' ) ) {
			\WP\MCP\Plugin::instance();
			self::$source = self::is_loaded() ? 'bundled' : 'none';
		}
	}

	/**
	 * The version of an external adapter, read from the class before the constant.
	 *
	 * ORDER IS THE POINT. `McpAdapter::VERSION` is compiled into the class that
	 * actually loaded; `WP_MCP_VERSION` is defined by whichever copy ran its
	 * bootstrap, which on a site with several bundled copies need not be the same
	 * one. The class constant is therefore the authoritative answer and the
	 * global is the fallback, not the other way round.
	 *
	 * A copy bundled inside another plugin defines no global at all — and that is
	 * exactly the case this diagnostic exists for. Observed on a production site:
	 * Rank Math's bundled 0.4.1 won the load-order race, defined no
	 * `WP_MCP_VERSION`, and exposed `McpAdapter::VERSION = '0.4.1'`. Reading only
	 * the global would have reported "unknown" for the very site the feature was
	 * written for.
	 *
	 * @since 1.33.0
	 *
	 * @return string Version string, or '' when neither source answers.
	 */
	private static function detect_external_version(): string {
		if ( defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ) {
			$version = (string) constant( '\WP\MCP\Core\McpAdapter::VERSION' );
			if ( '' !== $version ) {
				return $version;
			}
		}
		return defined( 'WP_MCP_VERSION' ) ? (string) WP_MCP_VERSION : '';
	}

	/**
	 * Whether the MCP Adapter core class is available (from either source).
	 *
	 * @since 1.7.4
	 *
	 * @return bool
	 */
	public static function is_loaded(): bool {
		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/**
	 * Where the adapter was loaded from: 'external', 'bundled', or 'none'.
	 *
	 * @since 1.7.4
	 *
	 * @return string
	 */
	public static function source(): string {
		return self::$source;
	}

	/**
	 * The adapter version actually in force.
	 *
	 * Empty when an external adapter won but declared no `WP_MCP_VERSION` — a
	 * pre-0.6.0 copy bundled inside another plugin does not define it, which is
	 * itself the signal that something older than what we ship is live.
	 *
	 * @since 1.33.0
	 *
	 * @return string
	 */
	public static function active_version(): string {
		if ( 'bundled' === self::$source ) {
			return self::BUNDLED_VERSION;
		}
		return 'external' === self::$source ? self::$external_version : '';
	}

	/**
	 * Whether an older adapter than the one we bundle is in force.
	 *
	 * An unknown external version counts as older: it means no `WP_MCP_VERSION`
	 * was defined, which only happens for a copy bundled by another plugin, and
	 * every such copy observed in the wild predates what we ship.
	 *
	 * @since 1.33.0
	 *
	 * @return bool
	 */
	public static function is_outdated(): bool {
		if ( 'external' !== self::$source ) {
			return false;
		}
		if ( '' === self::$external_version ) {
			return true;
		}
		return version_compare( self::$external_version, self::BUNDLED_VERSION, '<' );
	}
}
