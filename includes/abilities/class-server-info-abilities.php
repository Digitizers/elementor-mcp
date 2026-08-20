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
 * @since   1.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the always-on server-info ability.
 *
 * @since 1.29.0
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
	 * @since 1.29.0
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
	 * @since 1.29.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Builds the report.
	 *
	 * @since 1.29.0
	 *
	 * @return array
	 */
	public function execute_server_info(): array {
		// Resolve names HERE, not at registration. A third party may register
		// its hook-added ability later in the same wp_abilities_api_init action
		// than this plugin's callback, so checking during registration would
		// discard a legitimate name. By the time this report is generated every
		// registration has run — and a name that still resolves to nothing is
		// one the MCP adapter skips too, so counting it would overstate.
		$resolves = static function ( array $names ): array {
			if ( ! function_exists( 'wp_get_ability' ) ) {
				return $names;
			}

			return array_values( array_filter(
				$names,
				static function ( $name ) {
					return null !== wp_get_ability( (string) $name );
				}
			) );
		};

		$registered = $resolves( Elementor_MCP_Ability_Registrar::get_registered_names() );
		$surviving  = $resolves( Elementor_MCP_Ability_Registrar::get_exposed_names() );
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

		// `elementor_mcp_ability_names` is a documented public hook, so another
		// plugin can remove abilities too. Blaming the local option for every
		// removal would send an operator hunting slugs that are not in it.
		$by_settings   = array_values( array_intersect( $suppressed, Elementor_MCP_Plugin::get_removed_by_settings() ) );
		$by_other      = array_values( array_diff( $suppressed, $by_settings ) );

		$notes = array();

		if ( ! empty( $by_settings ) ) {
			$notes[] = sprintf(
				/* translators: 1: number of withheld tools, 2: the option key. */
				__( '%1$d registered abilities are withheld by this plugin\'s own settings — the "%2$s" option and/or low-tools mode. They are configured off, not missing.', 'elementor-mcp' ),
				count( $by_settings ),
				'elementor_mcp_disabled_tools'
			);
		}

		if ( ! empty( $by_other ) ) {
			$notes[] = sprintf(
				/* translators: 1: number of tools removed elsewhere, 2: the filter name. */
				__( '%1$d registered abilities were removed by something other than this plugin\'s settings — another callback on the "%2$s" filter. Looking for them in the disabled-tools option will not find them.', 'elementor-mcp' ),
				count( $by_other ),
				'elementor_mcp_ability_names'
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
				// Split by cause: an operator can only act on the first group.
				'withheld_by'     => array(
					'plugin_settings' => $by_settings,
					'other_filters'   => $by_other,
				),
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
