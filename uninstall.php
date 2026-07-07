<?php
/**
 * Uninstall cleanup for MCP Tools for Elementor.
 *
 * This native uninstall handler was restored when the Freemius SDK was removed
 * from the plugin. Previously the SDK ran this cleanup for us via its
 * `after_uninstall` hook (calling elementor_mcp_after_uninstall()); with the
 * SDK gone, WordPress runs this file directly when the plugin is deleted.
 *
 * WordPress loads uninstall.php in isolation — the plugin bootstrap does NOT
 * run — so this file must be fully self-contained.
 *
 * @package Elementor_MCP
 */

// Bail unless WordPress is actually uninstalling this plugin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ELEMENTOR_MCP_DIR' ) ) {
	define( 'ELEMENTOR_MCP_DIR', plugin_dir_path( __FILE__ ) );
}

// Plugin-owned options.
delete_option( 'elementor_mcp_disabled_tools' );
delete_option( 'elementor_mcp_low_tool_mode' );
delete_option( 'elementor_mcp_defaults_applied' );
delete_option( 'elementor_mcp_premium_unlock_applied' );
// Governance opt-ins — clear them so a reinstall never inherits a stale
// "require grants" / "render check" state on a governed site.
delete_option( 'elementor_mcp_require_grants' );
delete_option( 'elementor_mcp_render_check' );

// Plugin-owned transients.
delete_transient( 'elementor_mcp_pro_prompts_bundle' );
delete_transient( 'elementor_mcp_pro_templates_bundle' );
delete_transient( 'elementor_mcp_pro_brand_kits_bundle' );

// Drop the upgrade-notice dismissal flag from every user.
delete_metadata( 'user', 0, 'elementor_mcp_upgrade_notice_dismissed', '', true );

// Brand-kit backups (emcp_kit_backup CPT) are intentionally LEFT in place
// on uninstall — treated as recoverable user content so a user who removes
// the plugin can still roll back their pre-kit brand after reinstalling.

// NOTE: we deliberately do NOT delete leftover Freemius options here. Options
// like `fs_accounts`, `fs_active_plugins`, `fs_api_cache`, and `fs_gdpr` are
// Freemius-WIDE — shared by every Freemius-powered plugin/theme on the site,
// not scoped to this plugin. Deleting them on our uninstall would erase other
// products' license/account state, and Freemius keeps our per-product data
// nested inside those shared structures (no safe per-product delete without the
// SDK we just removed). Any of our residual entries are harmless if no other
// Freemius product remains, and must not be touched if one does.

// Widget Builder: generated executable PHP must NOT survive uninstall —
// delete every emcp_widget post and remove the uploads sandbox tree.
if ( ! class_exists( 'Elementor_MCP_Widget_Store' ) ) {
	require_once ELEMENTOR_MCP_DIR . 'includes/class-widget-store.php';
}
if ( class_exists( 'Elementor_MCP_Widget_Store' ) ) {
	Elementor_MCP_Widget_Store::uninstall_cleanup();
}
