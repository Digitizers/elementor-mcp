<?php
/**
 * Server-info ability — the one tool that is always exposed.
 *
 * A fresh install once presented to a client as "the MCP server exposes zero
 * tools", with nothing anywhere to explain it: no server-info response, no log
 * line, nothing saying "N abilities registered, M suppressed by option". The
 * agent's only recourse was to read the plugin source to discover that the
 * option existed at all (field report #5, part 2a).
 *
 * The same invisibility bites after upgrades: the defaults seeder re-disables
 * the Pro-badged set whenever its version counter falls behind, so tools can
 * vanish from a working install and nothing in `tools/list` hints that anything
 * was withheld. It took an ability-vs-tool diff to notice 36 tools missing.
 *
 * This ability is therefore **never suppressible** — a diagnostic that can be
 * switched off by the thing it diagnoses is no diagnostic at all.
 *
 * @package Elementor_MCP
 * @since   1.28.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the always-on server-info ability.
 *
 * @since 1.28.1
 */
class Elementor_MCP_Server_Info_Abilities {

	/** The ability that must survive every filter. */
	const ABILITY = 'elementor-mcp/server-info';

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers the ability.
	 *
	 * @since 1.28.1
	 */
	public function register(): void {
		$this->ability_names[] = self::ABILITY;

		elementor_mcp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Server Info', 'elementor-mcp' ),
				'description'         => __( 'Reports plugin, Elementor and MCP-adapter versions plus how many abilities are registered versus actually exposed, and which option is withholding the rest. Always available — it cannot be disabled. Call this first when tools appear to be missing.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_server_info' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin_version'    => array( 'type' => 'string' ),
						'elementor_version' => array( 'type' => 'string' ),
						'elementor_pro'     => array( 'type' => 'string' ),
						'atomic_active'     => array( 'type' => 'boolean' ),
						'abilities'         => array( 'type' => 'object' ),
						'mcp_adapter'       => array( 'type' => 'object' ),
						'server_enabled'    => array( 'type' => 'boolean' ),
						'notes'             => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
	 * Read-only diagnostic; the same capability the other read tools use.
	 *
	 * @since 1.28.1
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Builds the report.
	 *
	 * @since 1.28.1
	 *
	 * @return array
	 */
	public function execute_server_info(): array {
		$registered = Elementor_MCP_Ability_Registrar::get_registered_names();
		$surviving  = Elementor_MCP_Ability_Registrar::get_exposed_names();
		$suppressed = array_values( array_diff( $registered, $surviving ) );

		// The Connection toggle is a bigger switch than the disabled-tools
		// option: with it off, register_mcp_server() returns before
		// create_server(), so NOTHING is exposed regardless of what survived
		// the filter. Reporting the post-filter count as `exposed` there would
		// have this tool answering "87 exposed" to a client seeing zero —
		// precisely the lie it exists to prevent.
		$server_enabled = Elementor_MCP_Plugin::is_server_enabled();
		$exposed        = $server_enabled ? $surviving : array();

		$disabled_option = get_option( 'elementor_mcp_disabled_tools', array() );
		$low_tools       = '1' === (string) get_option( 'elementor_mcp_low_tool_mode', '0' );

		$notes = array();

		if ( ! empty( $suppressed ) ) {
			$notes[] = sprintf(
				/* translators: 1: number of suppressed tools, 2: the option key. */
				__( '%1$d registered abilities are not exposed as tools. They are withheld by the "%2$s" option and/or low-tools mode — not missing from the plugin.', 'elementor-mcp' ),
				count( $suppressed ),
				'elementor_mcp_disabled_tools'
			);
		}

		if ( $low_tools ) {
			$notes[] = __( 'Low-tools mode is ON: everything outside the curated essentials list is withheld to stay under a client tool cap.', 'elementor-mcp' );
		}

		if ( ! $server_enabled ) {
			$notes[] = sprintf(
				/* translators: %d: how many tools would be exposed once re-enabled. */
				__( 'The MCP server endpoint is switched OFF on the Connection tab, so NO tools are exposed no matter what else is configured. %d would be exposed once it is switched back on.', 'elementor-mcp' ),
				count( $surviving )
			);
		}

		// The seeder re-disables the Pro-badged set whenever this counter falls
		// behind its DEFAULTS_VERSION, which is how tools vanish after an
		// upgrade on a site that had cleared the list by hand.
		$defaults_applied = get_option( 'elementor_mcp_defaults_applied', 0 );

		return array(
			'plugin_version'    => defined( 'ELEMENTOR_MCP_VERSION' ) ? ELEMENTOR_MCP_VERSION : '',
			'elementor_version' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'elementor_pro'     => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			'atomic_active'     => class_exists( 'Elementor_MCP_Atomic_Props' ) && Elementor_MCP_Atomic_Props::is_atomic_supported(),
			'abilities'         => array(
				'registered'      => count( $registered ),
				'exposed'         => count( $exposed ),
				// What the option/low-tools mode leave standing, independent of
				// the server toggle — so an operator can tell the two apart.
				'would_expose'    => count( $surviving ),
				'suppressed'      => count( $suppressed ),
				'suppressed_list' => $suppressed,
				'controlled_by'   => array(
					'option'                   => 'elementor_mcp_disabled_tools',
					'disabled_count'           => is_array( $disabled_option ) ? count( $disabled_option ) : 0,
					'low_tools_mode'           => $low_tools,
					'defaults_applied_version' => $defaults_applied,
				),
			),
			'mcp_adapter'       => array(
				'source'  => class_exists( 'Elementor_MCP_Adapter_Bootstrap' ) ? Elementor_MCP_Adapter_Bootstrap::source() : 'unknown',
				'version' => defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : '',
			),
			'server_enabled'    => $server_enabled,
			'notes'             => $notes,
		);
	}
}
