<?php
/**
 * Plugin Name:       MCP Tools for Elementor (Digitizers fork)
 * Plugin URI:        https://github.com/Digitizers/elementor-mcp
 * Description:       A Digitizers fork of elementor-mcp (originally by Mian Shahzad Raza / msrbuilds) — extends the WordPress MCP Adapter to expose Elementor data, widgets, and page-design tools as MCP tools for AI agents. Elementor 4.x-correct; bundles the MCP Adapter.
 * Version:           1.22.0
 * Requires at least: 6.9
 * Tested up to:      6.9
 * Requires PHP:      8.0
 * Author:            Digitizers
 * Author URI:        https://github.com/Digitizers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elementor-mcp
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'ELEMENTOR_MCP_VERSION', '1.22.0' );
define( 'ELEMENTOR_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ELEMENTOR_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'ELEMENTOR_MCP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Whether the fork's premium-tier GPL tools are enabled.
 *
 * Upstream gated these 19 GPL tools (brand kits, SEO audits, a11y audits,
 * Widget Builder) plus the generated-widget loader/store behind a Freemius
 * paid license that this fork never carried; the SDK has since been removed,
 * and the pack is enabled for everyone, filterable off.
 *
 * @since 1.13.0
 *
 * @return bool
 */
function emcp_fork_premium_tools_enabled(): bool {
	/**
	 * Filters whether the fork's premium-tier tools register.
	 *
	 * @since 1.13.0
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'emcp_fork_premium_tools_enabled', true );
}

/**
 * Whether governed Elementor writes must present a valid SiteAgent approval grant.
 *
 * Grant enforcement for this plugin's tools is OPT-IN even when SiteAgent's grant
 * regime is otherwise active (a gateway key is provisioned). This is deliberate:
 * SiteAgent enforces grants for its own mutating tools as soon as a key exists,
 * but the gateway must also be minting grants for THIS plugin's tool names before
 * we can require them — otherwise every governed Elementor page edit would be
 * denied. Operators turn this on once the gateway is issuing Elementor-tool grants.
 *
 * Default OFF. Filterable, and driven by the `elementor_mcp_require_grants` option.
 *
 * @since 1.18.0
 *
 * @return bool
 */
function emcp_governance_require_grants(): bool {
	$enabled = (bool) get_option( 'elementor_mcp_require_grants', false );

	/**
	 * Filters whether governed Elementor writes require an approval grant.
	 *
	 * @since 1.18.0
	 *
	 * @param bool $enabled Default: the elementor_mcp_require_grants option (off).
	 */
	return (bool) apply_filters( 'elementor_mcp_require_grants', $enabled );
}

/**
 * Whether a governed page write is verified by a post-write render check.
 *
 * When on, after a successful governed write the edited page's front end is
 * fetched and, if it comes back definitively broken (HTTP 5xx, an empty body /
 * white screen, or WordPress's "critical error" fatal page), the write is rolled
 * back to its pre-write snapshot. OPT-IN (default OFF): the check adds a loopback
 * request per write, and a transient/ambiguous response is treated as
 * inconclusive (never rolls back a good write), but operators opt in explicitly.
 *
 * @since 1.19.0
 *
 * @return bool
 */
function emcp_governance_render_check(): bool {
	$enabled = (bool) get_option( 'elementor_mcp_render_check', false );

	/**
	 * Filters whether governed page writes run a post-write render check.
	 *
	 * @since 1.19.0
	 *
	 * @param bool $enabled Default: the elementor_mcp_render_check option (off).
	 */
	return (bool) apply_filters( 'elementor_mcp_render_check', $enabled );
}

/**
 * URL of the most-recently-modified Elementor page (builder mode), or the
 * site homepage as a fallback. Used by the apply/restore toasts so the user
 * lands somewhere that actually showcases the change.
 *
 * @since 1.8.0
 *
 * @return string
 */
function elementor_mcp_recent_elementor_page_url(): string {
    $query = new WP_Query(
        array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'   => '_elementor_edit_mode',
                    'value' => 'builder',
                ),
            ),
        )
    );

    if ( ! empty( $query->posts ) ) {
        $permalink = get_permalink( $query->posts[0] );
        if ( $permalink ) {
            return $permalink;
        }
    }

    return home_url( '/' );
}

/**
 * Recursively removes empty strings from enum arrays in a JSON Schema.
 *
 * Some MCP clients (e.g. Gemini/Antigravity) reject empty string values
 * inside enum arrays. This sanitizer strips them from any schema structure,
 * including nested properties, items, and allOf/oneOf/anyOf.
 *
 * Also ensures empty `properties` objects serialize as JSON `{}` not `[]`.
 *
 * @since 1.4.3
 *
 * @param array $schema A JSON Schema array.
 * @return array The sanitized schema.
 */
function elementor_mcp_sanitize_schema( array $schema ): array {
	// Strip empty strings from enum arrays.
	if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
		$schema['enum'] = array_values(
			array_filter(
				$schema['enum'],
				function ( $value ) {
					return '' !== $value;
				}
			)
		);
		if ( empty( $schema['enum'] ) ) {
			unset( $schema['enum'] );
		}
	}

	// Recurse into properties.
	if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
		if ( empty( $schema['properties'] ) ) {
			$schema['properties'] = new \stdClass();
		} else {
			foreach ( $schema['properties'] as $key => $prop ) {
				if ( is_array( $prop ) ) {
					$schema['properties'][ $key ] = elementor_mcp_sanitize_schema( $prop );
				}
			}
		}
	}

	// Recurse into items.
	if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
		$schema['items'] = elementor_mcp_sanitize_schema( $schema['items'] );
	}

	// Recurse into allOf, oneOf, anyOf.
	foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $keyword ) {
		if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) ) {
			foreach ( $schema[ $keyword ] as $i => $sub ) {
				if ( is_array( $sub ) ) {
					$schema[ $keyword ][ $i ] = elementor_mcp_sanitize_schema( $sub );
				}
			}
		}
	}

	return $schema;
}

/**
 * Wrapper around wp_register_ability that sanitizes schemas for cross-client compatibility.
 *
 * @since 1.4.3
 *
 * @param string $name    The ability name.
 * @param array  $args    The ability arguments.
 * @return mixed The result of wp_register_ability().
 */
function elementor_mcp_register_ability( string $name, array $args ) {
	if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) ) {
		$args['input_schema'] = elementor_mcp_sanitize_schema( $args['input_schema'] );
	}
	if ( isset( $args['output_schema'] ) && is_array( $args['output_schema'] ) ) {
		$args['output_schema'] = elementor_mcp_sanitize_schema( $args['output_schema'] );
	}
	// When SiteAgent is installed alongside us, bring destructive page writes
	// under its capture-before-write governance. No-op when SiteAgent is absent.
	if ( class_exists( 'Elementor_MCP_Governance' ) ) {
		$args = Elementor_MCP_Governance::wrap_ability( $name, $args );
	}
	return wp_register_ability( $name, $args );
}

/**
 * Checks that all required dependencies are available.
 *
 * @since 1.0.0
 *
 * @return bool True if all dependencies are met.
 */
function elementor_mcp_check_dependencies(): bool {
	$missing = array();

	// Elementor must be active.
	if ( ! did_action( 'elementor/loaded' ) ) {
		$missing[] = 'Elementor';
	}

	// WordPress Abilities API must be available. Core in WordPress 6.9+ (and
	// 7.0); only missing on older WordPress, which the plugin doesn't support.
	if ( ! function_exists( 'wp_register_ability' ) ) {
		$missing[] = 'WordPress Abilities API (requires WordPress 6.9+)';
	}

	// MCP Adapter: as of v1.8.0 the adapter is bundled with the plugin
	// (Elementor_MCP_Adapter_Bootstrap::ensure() ran in elementor_mcp_init,
	// loading either an active standalone adapter or our bundled copy). So this
	// is normally satisfied without any separate install. It only fails if the
	// bundled source is missing/corrupt — a broken build, not a user action.
	if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		$missing[] = 'WordPress MCP Adapter (bundled — reinstall the plugin if this persists)';
	}

	if ( ! empty( $missing ) ) {
		add_action( 'admin_notices', function () use ( $missing ) {
			$list = implode( ', ', $missing );
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				sprintf(
					/* translators: %s: comma-separated list of missing dependencies */
					esc_html__( 'MCP Tools for Elementor requires the following to be installed and active: %s', 'elementor-mcp' ),
					'<strong>' . esc_html( $list ) . '</strong>'
				)
			);
		} );

		return false;
	}

	return true;
}

/**
 * Initializes the plugin.
 *
 * Hooked to `plugins_loaded` at priority 20 to ensure Elementor and
 * other dependencies are loaded first.
 *
 * @since 1.0.0
 */
function elementor_mcp_init(): void {
	// Make the MCP Adapter available (active standalone plugin, else our bundled
	// copy) BEFORE the dependency check, so the adapter is never a "go install
	// this" blocker. The Abilities API is core in WordPress 6.9+/7.0.
	require_once ELEMENTOR_MCP_DIR . 'includes/class-mcp-adapter-bootstrap.php';
	Elementor_MCP_Adapter_Bootstrap::ensure();

	if ( ! elementor_mcp_check_dependencies() ) {
		return;
	}

	// Load class files.
	require_once ELEMENTOR_MCP_DIR . 'includes/class-id-generator.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-elementor-data.php';
	// SiteAgent governance bridge — must load before abilities register so
	// elementor_mcp_register_ability() can wrap destructive page writes.
	require_once ELEMENTOR_MCP_DIR . 'includes/class-governance.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-element-factory.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/schemas/class-control-mapper.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/schemas/class-schema-generator.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/validators/class-element-validator.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/validators/class-settings-validator.php';
	// SEO / A11y toolkit shared helpers (used by the Pro audit abilities).
	require_once ELEMENTOR_MCP_DIR . 'includes/class-color-contrast.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-content-extractor.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-seo-meta.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-query-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-page-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-layout-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-widget-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-template-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-global-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-composite-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-openverse-client.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-stock-image-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-svg-icon-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-custom-code-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-media-library-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-global-classes-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-global-classes-write-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-variables-write-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-interactions-write-abilities.php';
	// Performance Analyzer (read-only page + server + WordPress audit → scored
	// report). Independent of Elementor version; gated on manage_options.
	require_once ELEMENTOR_MCP_DIR . 'includes/performance/class-performance-finding.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/performance/class-performance-server-audit.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/performance/class-performance-page-audit.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/performance/class-performance-analyzer.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-performance-abilities.php';
	// Security & Malware Scanner (read-only 4-dimension scan → scored report:
	// malware heuristics, core-integrity checksum diff, hardening audit,
	// outdated/abandoned software). Independent of Elementor version; gated on
	// manage_options.
	require_once ELEMENTOR_MCP_DIR . 'includes/security/class-security-finding.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/security/class-security-malware-audit.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/security/class-security-integrity-audit.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/security/class-security-hardening-audit.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/security/class-security-software-audit.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/security/class-security-scanner.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-security-abilities.php';
	// Brand Kits (Pro). The writer + backup store + fetcher + abilities load
	// unconditionally (no admin dependency) so the MCP REST/CLI/proxy surface
	// can reach them; every write method is independently Pro-gated.
	require_once ELEMENTOR_MCP_DIR . 'includes/class-system-kit-writer.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-kit-backup-store.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-free-brand-kits.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-system-kit-abilities.php';
	add_action( 'init', array( 'Elementor_MCP_Kit_Backup_Store', 'register_post_type' ) );
	// Widget Builder (Pro) — sandboxed AI-generated Elementor widgets. The
	// generator/store/loader load unconditionally so the MCP surface can reach
	// them; every write + the loader itself are independently Pro-gated.
	require_once ELEMENTOR_MCP_DIR . 'includes/class-widget-generator.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-widget-store.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-widget-loader.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-widget-builder-abilities.php';
	add_action( 'init', array( 'Elementor_MCP_Widget_Store', 'register_post_type' ) );
	( new Elementor_MCP_Widget_Loader() )->register_hooks();
	// SEO toolkit abilities (Pro only; self-guards on license at registration).
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-seo-abilities.php';
	// Accessibility toolkit abilities (Pro only; self-guards on license).
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-a11y-abilities.php';
	// Atomic elements support (Elementor 4.0+).
	require_once ELEMENTOR_MCP_DIR . 'includes/class-atomic-props.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-atomic-styles.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-atomic-widget-abilities.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-atomic-layout-abilities.php';

	require_once ELEMENTOR_MCP_DIR . 'includes/abilities/class-ability-registrar.php';
	require_once ELEMENTOR_MCP_DIR . 'includes/class-plugin.php';

	// Admin.
	if ( is_admin() ) {
		require_once ELEMENTOR_MCP_DIR . 'includes/admin/class-admin.php';
	}

	// Boot the plugin.
	Elementor_MCP_Plugin::instance();
}
add_action( 'plugins_loaded', 'elementor_mcp_init', 20 );
