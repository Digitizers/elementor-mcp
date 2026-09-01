<?php
/**
 * Plugin Name:       MCP Tools for Elementor (Digitizers fork)
 * Plugin URI:        https://github.com/Digitizers/elementor-mcp
 * Description:       A Digitizers fork of elementor-mcp (originally by Mian Shahzad Raza / msrbuilds) — extends the WordPress MCP Adapter to expose Elementor data, widgets, and page-design tools as MCP tools for AI agents. Elementor 4.x-correct; bundles the MCP Adapter.
 * Version:           1.34.0
 * Requires at least: 6.9
 * Tested up to:      7.1
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
define( 'ELEMENTOR_MCP_VERSION', '1.34.0' );
define( 'ELEMENTOR_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ELEMENTOR_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'ELEMENTOR_MCP_BASENAME', plugin_basename( __FILE__ ) );

// GitHub self-updater: wired as early as the main file loads, so updates are
// offered even when Elementor is missing or inactive. Degrades to a no-op when
// the bundled checker library is absent (same quarantine-tolerance rationale
// as elementor_mcp_require(), which is defined too late to use here).
if ( file_exists( ELEMENTOR_MCP_DIR . 'includes/class-updater.php' ) ) {
	require_once ELEMENTOR_MCP_DIR . 'includes/class-updater.php';
	Elementor_MCP_Updater::init( __FILE__ );
}

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
 * AJAX handler for applying a bundled (free) brand kit from the admin page.
 *
 * Free feature — capability-gated on manage_options, no license. Looks the kit
 * up in the bundled Free_Brand_Kits set, optionally snapshots the current kit
 * into the emcp_kit_backup CPT (backup-before-apply), then applies it through
 * the shared Elementor_MCP_System_Kit_Writer.
 *
 * @since 1.22.0
 */
function elementor_mcp_apply_brand_kit_ajax() {
    check_ajax_referer( 'elementor_mcp_apply_brand_kit', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to apply brand kits.', 'elementor-mcp' ) ), 403 );
    }

    $kit_slug      = isset( $_POST['kit_slug'] ) ? sanitize_key( wp_unslash( $_POST['kit_slug'] ) ) : '';
    $category_slug = isset( $_POST['category_slug'] ) ? sanitize_key( wp_unslash( $_POST['category_slug'] ) ) : '';
    $do_backup     = ! isset( $_POST['backup'] ) || '0' !== (string) wp_unslash( $_POST['backup'] );

    if ( '' === $kit_slug ) {
        wp_send_json_error( array( 'message' => __( 'Missing kit slug.', 'elementor-mcp' ) ), 400 );
    }

    $kit = class_exists( 'Elementor_MCP_Free_Brand_Kits' )
        ? Elementor_MCP_Free_Brand_Kits::find_kit( $kit_slug, $category_slug )
        : null;
    if ( null === $kit ) {
        wp_send_json_error( array( 'message' => __( 'Brand kit not found.', 'elementor-mcp' ) ), 404 );
    }

    $backup_id = null;
    if ( $do_backup && class_exists( 'Elementor_MCP_Kit_Backup_Store' ) ) {
        $backup = Elementor_MCP_Kit_Backup_Store::create( isset( $kit['title'] ) ? (string) $kit['title'] : $kit_slug );
        if ( ! is_wp_error( $backup ) ) {
            $backup_id = (int) $backup;
        }
    }

    $result = Elementor_MCP_System_Kit_Writer::apply_kit( $kit );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    $result['backup_id'] = $backup_id;
    $result['view_url']  = elementor_mcp_recent_elementor_page_url();
    wp_send_json_success( $result );
}

/**
 * AJAX handler for restoring a brand-kit backup from the admin page.
 *
 * Free feature — capability-gated on manage_options. Reads the chosen
 * emcp_kit_backup snapshot and restores it via the shared backup store (which
 * routes through Elementor_MCP_System_Kit_Writer::restore_snapshot()).
 *
 * @since 1.22.0
 */
function elementor_mcp_restore_brand_kit_ajax() {
    check_ajax_referer( 'elementor_mcp_restore_brand_kit', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You do not have permission to restore brand kits.', 'elementor-mcp' ) ), 403 );
    }

    $backup_id    = isset( $_POST['backup_id'] ) ? absint( wp_unslash( $_POST['backup_id'] ) ) : 0;
    $full_clobber = isset( $_POST['full_clobber'] ) && '1' === (string) wp_unslash( $_POST['full_clobber'] );

    if ( $backup_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'Missing or invalid backup.', 'elementor-mcp' ) ), 400 );
    }

    if ( ! class_exists( 'Elementor_MCP_Kit_Backup_Store' ) ) {
        wp_send_json_error( array( 'message' => __( 'Backup store unavailable.', 'elementor-mcp' ) ), 500 );
    }

    $result = Elementor_MCP_Kit_Backup_Store::restore( $backup_id, $full_clobber );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success(
        array(
            'message'  => __( 'Brand restored from backup.', 'elementor-mcp' ),
            'view_url' => elementor_mcp_recent_elementor_page_url(),
        )
    );
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
	// Keep write tools out of any MCP server but our own. See the method's
	// docblock — this is the only protection on a site without SiteAgent, where
	// governance wraps nothing.
	if ( class_exists( 'Elementor_MCP_Call_Context' ) ) {
		// Both write guards, from a single evaluation of the operator opt-out.
		// The registration shield keeps writes out of another server's menu;
		// the permission gate refuses an untrusted caller before the ability is
		// reached, on EVERY site — the governance layer below only exists where
		// SiteAgent is installed, and a fork-only site was relying on the
		// registration metadata alone, i.e. on another plugin honouring a
		// convention.
		$args = Elementor_MCP_Call_Context::apply_write_guards( $args, $name );
	}
	// When SiteAgent is installed alongside us, bring destructive page writes
	// under its capture-before-write governance. No-op when SiteAgent is absent.
	if ( class_exists( 'Elementor_MCP_Governance' ) ) {
		$args = Elementor_MCP_Governance::wrap_ability( $name, $args );
	}
	// MCP Adapter 0.6.0 changed a default: an ability with `meta.public` true is
	// exposed through the adapter's DEFAULT MCP server unless `meta.mcp.public`
	// opts out. None of our abilities set `meta.public` today, so nothing is
	// exposed by that change — but "nothing sets it" is a fact about the code as
	// it stands, not a property anyone maintains. One ability copied from an
	// example that carries `public => true` would silently publish an Elementor
	// write tool on a server we do not control.
	//
	// So the opt-out is declared, not inferred. Set explicitly and only when the
	// caller has not decided for itself, which keeps a deliberate future
	// exception possible without editing this line.
	if ( ! isset( $args['meta']['mcp']['public'] ) ) {
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
			$args['meta'] = array();
		}
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = array();
		}
		$args['meta']['mcp']['public'] = false;
	}

	// Outermost wrap: normalize the result shape so strict MCP clients never
	// see a top-level JSON list/scalar in structuredContent (upstream 3.6.1).
	// Lives here — not in the vendored adapter — so it survives adapter updates.
	if ( class_exists( 'Elementor_MCP_Result_Normalizer' )
		&& isset( $args['execute_callback'] ) && is_callable( $args['execute_callback'] ) ) {
		$inner                    = $args['execute_callback'];
		$args['execute_callback'] = static function () use ( $inner ) {
			return Elementor_MCP_Result_Normalizer::normalize( $inner( ...func_get_args() ) );
		};
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
 * Guarded require for plugin source files.
 *
 * A physically missing or quarantined file (host malware scanners do this —
 * upstream issue #100) must degrade to that feature being absent, never a
 * site-wide fatal on every admin page load and REST request. The registrar's
 * try/catch covers classes that loaded but broke; this covers files that
 * never loaded at all.
 *
 * @since 1.27.0
 *
 * @param string $relative Path relative to the plugin directory.
 * @return bool Whether the file was loaded.
 */
function elementor_mcp_require( string $relative ): bool {
	$path = ELEMENTOR_MCP_DIR . $relative;
	if ( file_exists( $path ) ) {
		require_once $path;
		return true;
	}
	if ( function_exists( 'error_log' ) ) {
		error_log( 'Elementor MCP: missing source file skipped: ' . $relative );
	}
	return false;
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
	if ( ! elementor_mcp_require( 'includes/class-mcp-adapter-bootstrap.php' )
		|| ! class_exists( 'Elementor_MCP_Adapter_Bootstrap' ) ) {
		return; // Nothing to boot without the adapter bootstrap.
	}
	Elementor_MCP_Adapter_Bootstrap::ensure();

	if ( ! elementor_mcp_check_dependencies() ) {
		return;
	}

	// Load class files. The CORE set below is what Elementor_MCP_Plugin's
	// constructor instantiates directly — if any of these are missing, booting
	// would just fatal later inside Plugin::init(), so bail out entirely (the
	// per-group guards only make sense for OPTIONAL tool groups).
	$core_ok = true;
	// Result normalizer is OPTIONAL by design — the registration seam guards
	// it with class_exists, so a quarantined file degrades to unnormalized
	// results, never to a plugin that refuses to boot.
	elementor_mcp_require( 'includes/class-result-normalizer.php' );
	$core_ok = elementor_mcp_require( 'includes/class-id-generator.php' ) && $core_ok;
	$core_ok = elementor_mcp_require( 'includes/class-elementor-data.php' ) && $core_ok;
	// SiteAgent governance bridge — must load before abilities register so
	// elementor_mcp_register_ability() can wrap destructive page writes. The
	// rules bridge loads first: the wrapper asks it before every write.
	elementor_mcp_require( 'includes/class-call-context.php' );
	elementor_mcp_require( 'includes/class-rules.php' );
	elementor_mcp_require( 'includes/class-collateral.php' );
	elementor_mcp_require( 'includes/class-governance.php' );
	$core_ok = elementor_mcp_require( 'includes/class-element-factory.php' ) && $core_ok;
	$core_ok = elementor_mcp_require( 'includes/schemas/class-control-mapper.php' ) && $core_ok;
	$core_ok = elementor_mcp_require( 'includes/schemas/class-schema-generator.php' ) && $core_ok;
	elementor_mcp_require( 'includes/validators/class-element-validator.php' );
	$core_ok = elementor_mcp_require( 'includes/validators/class-settings-validator.php' ) && $core_ok;
	if ( ! $core_ok ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'Elementor MCP: core source files missing — plugin not booted this request.' );
		}
		return;
	}
	// SEO / A11y toolkit shared helpers (used by the Pro audit abilities).
	elementor_mcp_require( 'includes/class-color-contrast.php' );
	elementor_mcp_require( 'includes/class-content-extractor.php' );
	elementor_mcp_require( 'includes/class-seo-meta.php' );
	// Always-on diagnostic; first so it exists even if a later group fails.
	elementor_mcp_require( 'includes/abilities/class-server-info-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-query-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-page-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-layout-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-widget-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-template-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-global-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-composite-abilities.php' );
	elementor_mcp_require( 'includes/class-openverse-client.php' );
	elementor_mcp_require( 'includes/abilities/class-stock-image-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-svg-icon-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-custom-code-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-media-library-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-global-classes-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-global-classes-write-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-variables-write-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-interactions-write-abilities.php' );
	// Performance Analyzer (read-only page + server + WordPress audit → scored
	// report). Independent of Elementor version; gated on manage_options.
	elementor_mcp_require( 'includes/performance/class-performance-finding.php' );
	elementor_mcp_require( 'includes/performance/class-performance-server-audit.php' );
	elementor_mcp_require( 'includes/performance/class-performance-page-audit.php' );
	elementor_mcp_require( 'includes/performance/class-performance-analyzer.php' );
	elementor_mcp_require( 'includes/abilities/class-performance-abilities.php' );
	// Security & Malware Scanner (read-only 4-dimension scan → scored report:
	// malware heuristics, core-integrity checksum diff, hardening audit,
	// outdated/abandoned software). Independent of Elementor version; gated on
	// manage_options.
	elementor_mcp_require( 'includes/security/class-security-finding.php' );
	elementor_mcp_require( 'includes/security/class-security-malware-audit.php' );
	elementor_mcp_require( 'includes/security/class-security-integrity-audit.php' );
	elementor_mcp_require( 'includes/security/class-security-hardening-audit.php' );
	elementor_mcp_require( 'includes/security/class-security-software-audit.php' );
	elementor_mcp_require( 'includes/security/class-security-scanner.php' );
	elementor_mcp_require( 'includes/abilities/class-security-abilities.php' );
	// Brand Kits (Pro). The writer + backup store + fetcher + abilities load
	// unconditionally (no admin dependency) so the MCP REST/CLI/proxy surface
	// can reach them; every write method is independently Pro-gated.
	// The system-kit writer is the dependency of the whole brand-kit surface:
	// abilities and AJAX handlers call it unconditionally, so when its file is
	// missing the dependents must not load/register at all.
	$kit_writer_ok = elementor_mcp_require( 'includes/class-system-kit-writer.php' );
	elementor_mcp_require( 'includes/class-kit-backup-store.php' );
	elementor_mcp_require( 'includes/class-free-brand-kits.php' );
	elementor_mcp_require( 'includes/class-angie-bridge.php' );
	if ( $kit_writer_ok && class_exists( 'Elementor_MCP_System_Kit_Writer' ) ) {
		elementor_mcp_require( 'includes/abilities/class-system-kit-abilities.php' );
	}
	if ( class_exists( 'Elementor_MCP_Kit_Backup_Store' ) ) {
		add_action( 'init', array( 'Elementor_MCP_Kit_Backup_Store', 'register_post_type' ) );
	}
	// Widget Builder (Pro) — sandboxed AI-generated Elementor widgets. The
	// generator/store/loader load unconditionally so the MCP surface can reach
	// them; every write + the loader itself are independently Pro-gated.
	elementor_mcp_require( 'includes/class-widget-generator.php' );
	elementor_mcp_require( 'includes/class-widget-store.php' );
	elementor_mcp_require( 'includes/class-widget-loader.php' );
	elementor_mcp_require( 'includes/abilities/class-widget-builder-abilities.php' );
	if ( class_exists( 'Elementor_MCP_Widget_Store' ) ) {
		add_action( 'init', array( 'Elementor_MCP_Widget_Store', 'register_post_type' ) );
	}
	if ( class_exists( 'Elementor_MCP_Widget_Loader' ) ) {
		( new Elementor_MCP_Widget_Loader() )->register_hooks();
	}
	// SEO toolkit abilities (Pro only; self-guards on license at registration).
	elementor_mcp_require( 'includes/abilities/class-seo-abilities.php' );
	// Accessibility toolkit abilities (Pro only; self-guards on license).
	elementor_mcp_require( 'includes/abilities/class-a11y-abilities.php' );
	// Atomic elements support (Elementor 4.0+).
	elementor_mcp_require( 'includes/class-atomic-props.php' );
	elementor_mcp_require( 'includes/class-atomic-styles.php' );
	elementor_mcp_require( 'includes/class-atomic-widget-map.php' );
	elementor_mcp_require( 'includes/abilities/class-atomic-widget-abilities.php' );
	elementor_mcp_require( 'includes/abilities/class-atomic-layout-abilities.php' );

	// Registrar + Plugin are core too: Plugin::init() constructs the registrar
	// directly, so a skipped registrar file would fatal past every guard.
	if ( ! elementor_mcp_require( 'includes/abilities/class-ability-registrar.php' )
		|| ! elementor_mcp_require( 'includes/class-plugin.php' ) ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'Elementor MCP: registrar/plugin core file missing — plugin not booted this request.' );
		}
		return;
	}

	// Admin.
	if ( is_admin() ) {
		elementor_mcp_require( 'includes/admin/class-admin.php' );

		// Free brand-kit apply/restore AJAX (capability-gated, no license gate).
		// Skipped when the writer failed to load — both handlers call it
		// unconditionally.
		if ( class_exists( 'Elementor_MCP_System_Kit_Writer' ) ) {
			add_action( 'wp_ajax_elementor_mcp_apply_brand_kit', 'elementor_mcp_apply_brand_kit_ajax' );
			add_action( 'wp_ajax_elementor_mcp_restore_brand_kit', 'elementor_mcp_restore_brand_kit_ajax' );
		}
	}

	// Boot the plugin. If the core plugin class itself failed to load there
	// is nothing to boot — the tools are simply absent this request.
	if ( class_exists( 'Elementor_MCP_Plugin' ) ) {
		Elementor_MCP_Plugin::instance();
	}
}
add_action( 'plugins_loaded', 'elementor_mcp_init', 20 );
