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
						'write_exposure'    => array( 'type' => 'object' ),
						'rules'             => array( 'type' => 'object' ),
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
	 * Are write tools actually withheld from other MCP servers right now?
	 *
	 * Both inputs must hold. The class-availability one is easy to forget,
	 * because the registrar skips the shield behind its own `class_exists()`
	 * guard and says nothing: the abilities go out exposed while every setting
	 * still reads as safe. A diagnostic that derived this from configuration
	 * alone would report "hidden" on exactly the broken install it exists to
	 * catch.
	 *
	 * The second input is a COUNT of what the shield actually let through, not a
	 * re-reading of the exposure filter. The filter is handed each ability's own
	 * args and may open one write tool while closing the rest; re-running it here
	 * against nothing reproduces none of those decisions.
	 *
	 * Takes its inputs as arguments rather than reading them, so every answer is
	 * reachable in a test — the class cannot be unloaded inside a running suite,
	 * and an untestable security predicate is one nobody notices inverting.
	 *
	 * @since 1.30.0
	 *
	 * @param bool $guards_loaded  Whether Elementor_MCP_Call_Context is available.
	 * @param int  $exposed_writes How many write abilities the shield let through.
	 * @return bool
	 */
	public static function writes_are_shielded( bool $guards_loaded, int $exposed_writes ): bool {
		return $guards_loaded && 0 === $exposed_writes;
	}

	/**
	 * The exposure notes for a given state — at most ONE cause per unsafe state.
	 *
	 * When the guard component did not load, the shield never ran and the filter
	 * had nothing to do with it. Emitting the filter's explanation as well would
	 * hand the operator two conflicting causes for the same finding, and the
	 * wrong one is the actionable-looking one.
	 *
	 * Pure, and takes the state as arguments, because the missing-component
	 * branch is unreachable from inside a suite that has the component loaded.
	 *
	 * @since 1.30.0
	 *
	 * @param bool     $guards_loaded  Whether Elementor_MCP_Call_Context loaded.
	 * @param string[] $exposed_writes Write tools the shield left visible.
	 * @return string[]
	 */
	public static function exposure_notes( bool $guards_loaded, array $exposed_writes ): array {
		if ( ! $guards_loaded ) {
			return array(
				__( 'The transport-guard component (Elementor_MCP_Call_Context) did not load. Write tools are NOT withheld from other MCP servers on this site and governed writes are not checked against the transport they arrived on. Reinstall the plugin — this usually means a file was quarantined or removed.', 'elementor-mcp' ),
			);
		}
		if ( empty( $exposed_writes ) ) {
			return array();
		}
		return array(
			sprintf(
				/* translators: %s: comma-separated tool names. */
				__( '%s are exposed to OTHER MCP servers on this site, because the elementor_mcp_expose_writes_to_foreign_mcp filter opened them or they declare mcp.type = tool themselves. Those servers authenticate their own callers; approval and audit on the Aura side do not apply to them.', 'elementor-mcp' ),
				implode( ', ', $exposed_writes )
			),
		);
	}

	/**
	 * What to tell an operator about the two write guards, given their state.
	 *
	 * Pure, and takes the state as arguments, because both facts come from
	 * `class_exists()` — the fork-only combination, which is the one this
	 * wording was corrected for, is unreachable from inside a suite that has
	 * SiteAgent's stubs loaded.
	 *
	 * @since 1.31.0
	 *
	 * @param bool $guards_loaded          Whether Elementor_MCP_Call_Context loaded.
	 * @param bool $execution_guard_active Grants verified at execute time (needs SiteAgent).
	 * @param int  $exposed_writes         Write tools the operator opened through the filter.
	 * @return string[]
	 */
	public static function guard_notes( bool $guards_loaded, bool $execution_guard_active, int $exposed_writes = 0 ): array {
		if ( ! $guards_loaded ) {
			return array(
				__( 'The transport gate is NOT running: Elementor_MCP_Call_Context did not load, so a foreign MCP server is not refused at the permission stage. Reinstall the plugin.', 'elementor-mcp' ),
			);
		}
		if ( ! $execution_guard_active ) {
			// Not a warning: a fork-only site IS closed. The transport gate
			// refuses foreign writes at the permission stage. What it cannot do
			// is let an APPROVED one through, because nothing here can verify a
			// grant. The note this replaced said such a server "would not be
			// stopped a second time", which described a closed site as open.
			//
			// "Nowhere else" is only true when nothing was opened through the
			// exposure filter — those writes skip the gate by design, and
			// claiming otherwise contradicts exposed_write_tools.
			if ( $exposed_writes > 0 ) {
				return array(
					sprintf(
						/* translators: %d: how many write tools the operator opened. */
						_n(
							'SiteAgent is not installed, so no approval grant can be verified on this site. Foreign MCP servers are refused at the permission stage — except for the %d write tool opened through the elementor_mcp_expose_writes_to_foreign_mcp filter, which is reachable from them with no approval or audit. See exposed_write_tools.',
							'SiteAgent is not installed, so no approval grant can be verified on this site. Foreign MCP servers are refused at the permission stage — except for the %d write tools opened through the elementor_mcp_expose_writes_to_foreign_mcp filter, which are reachable from them with no approval or audit. See exposed_write_tools.',
							$exposed_writes,
							'elementor-mcp'
						),
						$exposed_writes
					),
				);
			}
			return array(
				__( 'SiteAgent is not installed, so no approval grant can be verified on this site. Foreign MCP servers are refused outright at the permission stage rather than being able to present a grant — writes work from this plugin\'s own MCP server, WP-CLI and the admin, and nowhere else.', 'elementor-mcp' ),
			);
		}
		return array();
	}

	/**
	 * Is the execution-side guard actually running?
	 *
	 * It lives inside the governance wrapper, and `wrap_ability()` returns every
	 * ability untouched when SiteAgent's snapshot engine is absent — so on a
	 * fork-only site the transport check never executes, whatever the context
	 * class reports about itself. Such a site is protected by the registration
	 * metadata alone, and saying otherwise is the false assurance this tool
	 * exists to prevent.
	 *
	 * Inputs are injected for the same reason as `writes_are_shielded()`: both
	 * availability answers come from `class_exists()`, which a running suite
	 * cannot flip.
	 *
	 * @since 1.30.0
	 *
	 * @param bool $guards_loaded      Whether Elementor_MCP_Call_Context loaded.
	 * @param bool $governance_loaded  Whether Elementor_MCP_Governance loaded.
	 * @param bool $governance_active  Whether SiteAgent's snapshot engine is present.
	 * @return bool
	 */
	public static function execution_guard_active( bool $guards_loaded, bool $governance_loaded, bool $governance_active ): bool {
		return $guards_loaded && $governance_loaded && $governance_active;
	}

	/**
	 * MCP servers on this site that are not ours, by server id.
	 *
	 * Read from the adapter's own registry rather than probing for a specific
	 * plugin: Angie's `/mcp/angie` is the one that exists today, but the exposure
	 * belongs to the shared ability registry, not to Angie — the next plugin to
	 * create a server inherits it, and a hardcoded Angie check would report a
	 * clean site.
	 *
	 * @since 1.30.0
	 *
	 * @return string[]
	 */
	private static function foreign_mcp_servers(): array {
		return self::classify_foreign_servers()['ids'];
	}

	/**
	 * Other MCP servers on this site, and which of them publish OUR abilities
	 * in their own tool lists.
	 *
	 * The distinction matters because "another server exists" is not the same
	 * claim as "another server can run these tools". A stock install already has
	 * the bundled adapter's default server, and that one reaches nothing here:
	 * it publishes three proxy tools whose gate requires `meta.mcp.public`, which
	 * none of this plugin's abilities set. Warning about it as a second door
	 * would be crying wolf on every site — and a report that cries wolf is one
	 * operators learn to skip.
	 *
	 * What CAN be established from the registry is whether a server's declared
	 * tool list names our abilities. When it does, that server serves them, full
	 * stop. When it does not, reachability depends on how that server's own
	 * tools select targets (Angie's `execute-ability` proxies by name to anything
	 * its discovery rule admits) — which is exactly why the write shield is a
	 * property of our abilities rather than a list of servers we trust.
	 *
	 * @since 1.30.0
	 *
	 * @return array{ids:string[],publishing:string[]}
	 */
	private static function classify_foreign_servers(): array {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			return array( 'ids' => array(), 'publishing' => array() );
		}
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_servers' ) ) {
			return array( 'ids' => array(), 'publishing' => array() );
		}

		return self::classify_servers(
			(array) $adapter->get_servers(),
			class_exists( 'Elementor_MCP_Plugin' ) ? Elementor_MCP_Plugin::SERVER_ROUTE : '',
			class_exists( 'Elementor_MCP_Ability_Registrar' )
				? Elementor_MCP_Ability_Registrar::get_registered_names()
				: array()
		);
	}

	/**
	 * The pure half of the classification above: given a server map, which ids
	 * are not ours, and which of those publish our abilities themselves.
	 *
	 * Separated so both answers are reachable in a test. The adapter is not
	 * loaded in the unit suite, so driving this through `classify_foreign_servers()`
	 * would exercise nothing but its early return — and the branch that matters
	 * is the one that decides whether an operator sees a security warning.
	 *
	 * @since 1.30.0
	 *
	 * @param array    $servers   Server map, keyed by server id.
	 * @param string   $ours      Our own server id.
	 * @param string[] $our_names Our registered ability names.
	 * @return array{ids:string[],publishing:string[]}
	 */
	public static function classify_servers( array $servers, string $ours, array $our_names ): array {
		$ids        = array();
		$publishing = array();

		foreach ( $servers as $id => $server ) {
			$id = is_string( $id ) ? $id : '';
			if ( '' === $id || $id === $ours ) {
				continue;
			}
			$ids[] = $id;

			if ( empty( $our_names ) || ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) ) {
				continue;
			}
			$tools = $server->get_tools();
			if ( ! is_array( $tools ) ) {
				continue;
			}
			// A live server's list holds McpTool objects keyed by the MCP tool
			// name, which is NOT the ability name: the adapter turns
			// `elementor-mcp/update-element` into `elementor-mcp-update-element`
			// (McpComponentRegistry keys on $tool->get_name()). Comparing raw
			// ability names against those keys never matches, so a server that
			// genuinely publishes these tools would have been reported clean —
			// the one answer this field exists to give. Both sides are normalised
			// through the same transform the grant binding already uses.
			$tool_names = array();
			foreach ( $tools as $key => $tool ) {
				if ( is_string( $tool ) ) {
					$tool_names[] = self::mcp_tool_name( $tool );
				} elseif ( is_object( $tool ) && method_exists( $tool, 'get_name' ) ) {
					$name = $tool->get_name();
					if ( is_string( $name ) ) {
						$tool_names[] = self::mcp_tool_name( $name );
					}
				} elseif ( is_string( $key ) ) {
					$tool_names[] = self::mcp_tool_name( $key );
				}
			}
			$ours_as_tools = array_map( array( self::class, 'mcp_tool_name' ), $our_names );
			if ( array_intersect( $ours_as_tools, $tool_names ) ) {
				$publishing[] = $id;
			}
		}

		sort( $ids );
		sort( $publishing );
		return array( 'ids' => $ids, 'publishing' => $publishing );
	}

	/**
	 * An ability name as the MCP adapter publishes it.
	 *
	 * `RegisterAbilityAsMcpTool` replaces "/" with "-", and the server keys its
	 * tool list on the result — the same transform the governance layer applies
	 * when binding an approval grant. Normalising both sides through one helper
	 * keeps a comparison from silently never matching.
	 *
	 * @since 1.30.0
	 *
	 * @param string $name Ability or tool name.
	 * @return string
	 */
	private static function mcp_tool_name( string $name ): string {
		return str_replace( '/', '-', trim( $name ) );
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

		// Is there a second MCP server on this site, and are our write tools
		// withheld from it? An operator auditing a managed site cannot see this
		// from anywhere else: the other server registers its own routes and
		// enumerates the shared ability registry without telling anyone.
		$foreign          = self::classify_foreign_servers();
		$foreign_servers  = $foreign['ids'];
		$servers_with_us  = $foreign['publishing'];
		// Both guards live in Elementor_MCP_Call_Context, and the loader treats
		// every include as optional — so its absence is not a detail to report
		// around, it is the whole answer. The registrar's class_exists() guard
		// silently skips the shield when the file did not load, which leaves
		// writes exposed; a diagnostic whose entire job is auditing that state
		// must not report "hidden" because a filter happens to be at its default.
		$guards_loaded = class_exists( 'Elementor_MCP_Call_Context' );
		// What the shield ACTUALLY did this request, not what re-running the
		// filter would suggest. The filter receives each ability's own args, so
		// a caller may open one write tool and close the rest; probing it here
		// with an empty array reproduces none of that and would report a
		// selectively-opened site as fully hidden.
		$exposed_writes = $guards_loaded ? Elementor_MCP_Call_Context::writes_left_exposed() : array();
		// The execution guard runs inside the governance wrapper, which only
		// wraps anything when SiteAgent's snapshot engine is present. Without it
		// the transport check never executes, whatever the context class reports
		// — such a site is protected by the registration metadata alone, and
		// saying otherwise is the kind of false assurance this tool exists to
		// prevent.
		$governance_loaded      = class_exists( 'Elementor_MCP_Governance' );
		$execution_guard_active = self::execution_guard_active(
			$guards_loaded,
			$governance_loaded,
			$governance_loaded && Elementor_MCP_Governance::is_active()
		);
		$writes_shielded        = self::writes_are_shielded( $guards_loaded, count( $exposed_writes ) );
		// Two different guards, and conflating them is how this report starts
		// lying. The TRANSPORT gate runs at the permission stage on every site
		// (1.31.0) and stops a foreign caller outright. The GRANT check runs at
		// execute time inside the governance wrapper, so it needs SiteAgent —
		// and it is the one that lets an approved foreign call through.
		// Not simply "the class loaded". The gate deliberately skips any write
		// the operator opened through the exposure filter, so on a site using
		// that hatch some writes ARE reachable from a foreign server — and
		// reporting a blanket true there contradicts `exposed_write_tools` in
		// the same response.
		$transport_gate_active  = $guards_loaded && empty( $exposed_writes );
		$write_exposure         = array(
			'writes_hidden_from_other_mcp_servers' => $writes_shielded,
			'exposed_write_tools'                  => $exposed_writes,
			'foreign_writes_refused_at_permission' => $transport_gate_active,
			'governed_writes_require_grant_off_own_server' => $execution_guard_active,
			'own_server_route'                     => $guards_loaded
				? Elementor_MCP_Call_Context::own_server_route()
				: '',
			'other_mcp_servers'                    => $foreign_servers,
			'other_servers_publishing_our_tools'   => $servers_with_us,
		);

		$notes = array_merge( $notes, self::exposure_notes( $guards_loaded, $exposed_writes ) );

		$notes = array_merge(
			$notes,
			self::guard_notes( $guards_loaded, $execution_guard_active, count( $exposed_writes ) )
		);

		if ( ! empty( $servers_with_us ) ) {
			// Established, not inferred: these servers name our abilities in their
			// own tool lists.
			$notes[] = sprintf(
				/* translators: %s: comma-separated list of MCP server ids. */
				__( 'Another MCP server on this site publishes this plugin\'s tools directly (%s), over a transport the Aura gateway never sees. Check write_exposure to see whether the write tools among them are withheld.', 'elementor-mcp' ),
				implode( ', ', $servers_with_us )
			);
		} elseif ( ! empty( $foreign_servers ) ) {
			// Deliberately weaker wording. A stock install already carries the
			// bundled adapter's default server, which reaches nothing here — its
			// proxy tools require meta.mcp.public, which none of these abilities
			// set. Warning about that as a second door on every site would train
			// operators to skip this report. What is true in general is that a
			// server whose tools resolve targets from the site-wide registry
			// (Angie's execute-ability does) can reach abilities it never listed.
			$notes[] = sprintf(
				/* translators: %s: comma-separated list of other MCP server ids. */
				__( 'Other MCP servers are active on this site (%s). None of them lists this plugin\'s tools directly, but a server whose own tools resolve targets from the site-wide ability registry can still reach them — that is why write tools are withheld by their own metadata rather than by a list of trusted servers.', 'elementor-mcp' ),
				implode( ', ', $foreign_servers )
			);
		}

		// Operator rules (P4.1 plan 3). SiteAgent holds the ruleset and decides;
		// this plugin only declares. A fork-only site enforces nothing, and the
		// report says so rather than leaving the absence to be inferred.
		$rules = class_exists( 'Elementor_MCP_Rules' )
			? Elementor_MCP_Rules::report()
			: array(
				'enforced' => false,
				'source'   => 'none',
				// Distinguish SiteAgent being genuinely absent from this plugin's
				// OWN rules bridge (includes/class-rules.php) simply failing to
				// load — those used to be reported identically, which told an
				// operator to install SiteAgent when it was already there.
				'state'    => class_exists( '\\Aura_Worker_Rules' ) ? 'bridge_missing' : 'absent',
				'ruleset'  => null,
				'points'   => array(),
			);

		// Even a `ready` ruleset enforces nothing unless the governance wrapper
		// that actually calls into Elementor_MCP_Rules at write time is live.
		// Elementor_MCP_Rules::report() only speaks to whether SiteAgent's rules
		// engine is installed and reachable — it deliberately has no dependency
		// on Elementor_MCP_Governance (see class-rules.php's docblock) — so it
		// cannot know that wrap_ability() no-ops entirely when SiteAgent's
		// snapshot engine is absent ($governance_loaded && ::is_active(), the
		// same check execution_guard_active() above already makes). A site in
		// that state must not report `enforced: true` just because the ruleset
		// itself is ready.
		$was_enforced     = ! empty( $rules['enforced'] );
		$governance_live  = $governance_loaded && Elementor_MCP_Governance::is_active();
		if ( $was_enforced && ! $governance_live ) {
			$rules['enforced'] = false;
			$rules['points']   = array();
		}

		// Liveness FIRST, regardless of `state`: report() can say `enforced: true`
		// for a `ready` ruleset OR for `reader_failed` (the one incomplete reason
		// where the gate still decides — current() throwing does not stop it) —
		// in both cases nothing here actually reaches a gate to ask when the
		// wrapper is not live, so that has to win over a MORE SPECIFIC-sounding
		// but misleading note like "SiteAgent's ruleset could not be read;
		// writes are still decided" when in truth NOTHING here is deciding
		// anything. Checked first, not "elseif"-chained after 'incomplete', so
		// it cannot be pre-empted by a state that also happens to be true.
		if ( $was_enforced && ! $governance_live ) {
			$notes[] = __( 'SiteAgent holds an operator ruleset, but this plugin\'s own governance wrapper is not active (its snapshot engine is unavailable), so no rules are enforced on Elementor writes from this plugin.', 'elementor-mcp' );
		} elseif ( 'bridge_missing' === $rules['state'] ) {
			$notes[] = __( 'SiteAgent is installed, but this plugin\'s rules bridge did not load — rules are not enforced on Elementor writes from this plugin.', 'elementor-mcp' );
		} elseif ( 'incomplete' === $rules['state'] ) {
			// Controller ruling: the GATE (missing_method(), requiring BOTH
			// enforce() and current()) refuses every write for BOTH
			// enforce_missing and current_missing — those get the refusal note.
			// Only reader_failed (current() present but throwing, which the gate
			// never calls) still has a working gate — writes are still decided,
			// only THIS report's read of the ruleset failed.
			if ( 'reader_failed' === ( $rules['reason'] ?? '' ) ) {
				$notes[] = __( 'SiteAgent\'s ruleset could not be read for this report; writes are still decided by SiteAgent\'s enforcement — repair SiteAgent to see the ruleset here.', 'elementor-mcp' );
			} else {
				$notes[] = __( 'SiteAgent cannot evaluate rules (no enforce()); writes through this plugin are refused until SiteAgent is repaired.', 'elementor-mcp' );
			}
		} elseif ( empty( $rules['enforced'] ) ) {
			$notes[] = __( 'SiteAgent is not installed, so no operator rules are enforced on Elementor writes from this plugin (a page block or a site freeze set in Aura does not apply here). Install SiteAgent 2.10.0 or later to enforce the same rules SiteAgent enforces.', 'elementor-mcp' );
		}

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
			'write_exposure'    => $write_exposure,
			'rules'             => $rules,
			'notes'             => $notes,
		);
	}
}
