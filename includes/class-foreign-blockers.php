<?php
/**
 * Foreign blockers on the adapter's pre-call filter.
 *
 * `mcp_adapter_pre_tool_call` is a GLOBAL WordPress filter: every MCP Adapter
 * server on the site applies it in `ToolsHandler::call_tool()`, after the tool
 * lookup and its permission check and before execute, and a WP_Error returned
 * from it ends the call. A callback another plugin registers there without
 * checking which server it is on therefore refuses calls to THIS plugin's
 * server too — and the agent is told only the other plugin's reason.
 *
 * Angie 1.1.17 is the case that exists (verified in Digitizers/references:
 * `angie/modules/mcp/module.php`): until `angie_external_scripts_consent` is
 * 'yes', every call returns `angie_consent_required`. This class does not undo
 * that — another vendor's consent gate is theirs to lift, and routing around
 * it would depend on their internals. It makes the block legible instead:
 *
 * - `explain()` rewrites a KNOWN foreign refusal, on THIS plugin's server only,
 *   into a message that says who refused and how to clear it. The call stays
 *   refused, and the arguments are never touched.
 * - `report()` is the `foreign_call_blockers` block of `server-info`.
 *
 * @package Elementor_MCP
 * @since   1.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects and explains foreign `mcp_adapter_pre_tool_call` refusals.
 *
 * @since 1.39.0
 */
class Elementor_MCP_Foreign_Blockers {

	/**
	 * The adapter filter every server applies before execute.
	 */
	const HOOK = 'mcp_adapter_pre_tool_call';

	/**
	 * Refusals we know how to name, keyed by the error code they return.
	 *
	 * Add a row only for a blocker read in the vendor's own source: the class
	 * and method identify its callback, the option says whether it is blocking
	 * right now, and `ready` is the value that lifts it.
	 */
	const KNOWN = array(
		'angie_consent_required' => array(
			'plugin'     => 'angie',
			'label'      => 'Angie (Elementor)',
			'class'      => 'Angie\\Modules\\Mcp\\Module',
			'method'     => 'require_consent_before_tool_call',
			'option'     => 'angie_external_scripts_consent',
			'ready'      => 'yes',
			'remedy_url' => 'admin.php?page=angie-app',
		),
	);

	/**
	 * Hook the explainer. Last priority, so it sees what every other callback
	 * decided.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( self::HOOK, array( __CLASS__, 'explain' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Re-word a known foreign refusal of a call on this plugin's server.
	 *
	 * Anything else — arguments, an unknown error, a call on another server —
	 * is returned exactly as received.
	 *
	 * @param array|WP_Error $args      Tool arguments, or a prior callback's refusal.
	 * @param string         $tool_name The tool being called.
	 * @param mixed          $mcp_tool  The adapter's tool object (unused).
	 * @param mixed          $server    The adapter's server object.
	 * @return array|WP_Error
	 */
	public static function explain( $args, $tool_name = '', $mcp_tool = null, $server = null ) {
		if ( ! is_wp_error( $args ) || ! static::is_own_server( $server ) ) {
			return $args;
		}

		$code = (string) $args->get_error_code();
		if ( ! isset( self::KNOWN[ $code ] ) ) {
			return $args;
		}

		$known = self::KNOWN[ $code ];

		return new WP_Error(
			'elementor_mcp_blocked_by_foreign_plugin',
			sprintf(
				/* translators: 1: the other plugin's name, 2: URL where its block is lifted, 3: that plugin's own message. */
				__( 'This call was refused by another plugin, %1$s, not by MCP Tools for Elementor. It blocks every MCP tool call on this site, on every MCP server, until its own consent is granted. A site administrator can grant it at %2$s or deactivate that plugin. Its message: %3$s', 'elementor-mcp' ),
				$known['label'],
				admin_url( $known['remedy_url'] ),
				$args->get_error_message()
			),
			array(
				'blocked_by'    => $known['plugin'],
				'original_code' => $code,
				'tool'          => (string) $tool_name,
			)
		);
	}

	/**
	 * The `foreign_call_blockers` block of `server-info`.
	 *
	 * @param array|null $callbacks Callback descriptors (see describe_callbacks());
	 *                              null reads the live filter.
	 * @return array{known: array<int, array<string, mixed>>, other_callbacks: string[]}
	 */
	public static function report( ?array $callbacks = null ): array {
		if ( null === $callbacks ) {
			$callbacks = self::describe_callbacks();
		}

		$known = array();
		$other = array();
		$ours  = __CLASS__ . '::explain';

		foreach ( $callbacks as $name ) {
			if ( $ours === $name ) {
				continue;
			}
			$match = null;
			foreach ( self::KNOWN as $code => $row ) {
				if ( $row['class'] . '::' . $row['method'] === $name ) {
					$match = array( $code, $row );
					break;
				}
			}
			if ( null === $match ) {
				$other[] = $name;
				continue;
			}
			list( $code, $row ) = $match;
			$known[]            = array(
				'plugin'     => $row['plugin'],
				'callback'   => $name,
				'error_code' => $code,
				'blocking'   => $row['ready'] !== get_option( $row['option'], '' ),
				'remedy_url' => admin_url( $row['remedy_url'] ),
			);
		}

		return array(
			'known'           => $known,
			'other_callbacks' => $other,
		);
	}

	/**
	 * Name every callback on the live filter as `Class::method` (or the
	 * function name; `closure` for anything else).
	 *
	 * @return string[]
	 */
	public static function describe_callbacks(): array {
		$hook = $GLOBALS['wp_filter'][ self::HOOK ] ?? null;
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return array();
		}

		$names = array();
		foreach ( $hook->callbacks as $at_priority ) {
			foreach ( (array) $at_priority as $registered ) {
				$names[] = self::callback_name( $registered['function'] ?? null );
			}
		}
		return $names;
	}

	/**
	 * A readable name for one callback.
	 *
	 * @param mixed $callback The registered callable.
	 * @return string
	 */
	public static function callback_name( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ) ) {
			$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return ltrim( $owner, '\\' ) . '::' . $callback[1];
		}
		return 'closure';
	}

	/**
	 * Is this the adapter server this plugin registered?
	 *
	 * @param mixed $server The adapter's server object.
	 * @return bool
	 */
	protected static function is_own_server( $server ): bool {
		$own = static::own_server_id();
		return '' !== $own
			&& is_object( $server )
			&& method_exists( $server, 'get_server_id' )
			&& $own === $server->get_server_id();
	}

	/**
	 * This plugin's adapter server id, or '' when the plugin class did not load.
	 *
	 * The explainer is registered before the core files are known to have
	 * loaded, and the guarded loader lets a quarantined class-plugin.php boot
	 * nothing further. Reading the constant unguarded would then fatal inside a
	 * FOREIGN refusal on another server — breaking it instead of passing it on.
	 * Without the class no server of ours exists, so '' (never ours) is exact.
	 *
	 * @return string
	 */
	protected static function own_server_id(): string {
		return class_exists( 'Elementor_MCP_Plugin' ) ? Elementor_MCP_Plugin::SERVER_ID : '';
	}
}
