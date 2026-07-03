<?php
/**
 * Performance Analyzer MCP ability (read-only).
 *
 * One tool — analyze-performance — that audits server config, WordPress
 * internals, and a target page (default frontpage) and returns a scored report.
 * Gated on `manage_options`; enabled by default. Independent of Elementor
 * version (it audits the server/WP/database, not Elementor internals), so it
 * registers unconditionally — the capability check is the guard.
 *
 * Ported from upstream msrbuilds/elementor-mcp (v3.0.0), adapted to this fork's
 * class/helper naming (the upstream rename to emcp-tools is not adopted),
 * including the same-host-across-redirects SSRF hardening (upstream 0c53be2).
 *
 * @package Elementor_MCP
 * @since   1.11.0
 * @link    https://github.com/msrbuilds/elementor-mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the Performance Analyzer ability.
 *
 * @since 1.11.0
 */
class Elementor_MCP_Performance_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers the Performance Analyzer abilities.
	 */
	public function register(): void {
		$this->register_analyze_performance();
	}

	/**
	 * Permission check — administrators only.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function register_analyze_performance(): void {
		$this->ability_names[] = 'elementor-mcp/analyze-performance';
		elementor_mcp_register_ability(
			'elementor-mcp/analyze-performance',
			array(
				'label'               => __( 'Analyze Performance', 'elementor-mcp' ),
				'description'         => __( 'Scans server configuration, WordPress internals (database size, autoloaded options, cron backlog, object cache, OPcache, plugin count), and a target page (defaults to the frontpage; pass "url" or "post_id" for a specific page) for performance issues and bottlenecks. Returns a scored report with severities and ranked, actionable recommendations. Read-only; analyzes this site only.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_analyze_performance' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'                => array( 'type' => 'string', 'format' => 'uri', 'description' => __( 'A page on THIS site to analyze. External hosts are rejected. Defaults to the frontpage.', 'elementor-mcp' ) ),
						'post_id'            => array( 'type' => 'integer', 'description' => __( 'Analyze the permalink of this post/page. Ignored when url is set.', 'elementor-mcp' ) ),
						'include_page_fetch' => array( 'type' => 'boolean', 'description' => __( 'Set false to skip the loopback page fetch and run the server/database audit only. Default true.', 'elementor-mcp' ) ),
						'deep_assets'        => array( 'type' => 'boolean', 'description' => __( 'Reserved: when true, sample same-host asset sizes for an estimated page weight. Default false.', 'elementor-mcp' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'target'              => array( 'type' => 'object' ),
						'summary'             => array( 'type' => 'object' ),
						'sections'            => array( 'type' => 'object' ),
						'page_fetch'          => array( 'type' => 'object' ),
						'top_recommendations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_analyze_performance( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$analyzer = new Elementor_MCP_Performance_Analyzer();
		return $analyzer->analyze( $input );
	}
}
