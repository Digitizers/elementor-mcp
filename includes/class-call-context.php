<?php
/**
 * Which transport the current request arrived on.
 *
 * Abilities are registered with WordPress, not with a server. Any MCP server
 * installed on the same site can therefore reach them: `wp_register_ability()`
 * publishes to a site-wide registry, and a co-installed server that enumerates
 * that registry gets this plugin's tools for free. Elementor's own Angie ships
 * exactly such a server (`/mcp/angie`, Angie 1.1.12+) whose `execute-ability`
 * proxy will run any third-party ability by name.
 *
 * That is a second door the Aura gateway never sees, and the tools behind it
 * mutate published pages. This class answers the only question the governance
 * layer needs in order to close it: did this call come in on OUR server route,
 * or somewhere else?
 *
 * @package Elementor_MCP
 * @since   1.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the REST route of the current request and classifies it.
 *
 * @since 1.30.0
 */
class Elementor_MCP_Call_Context {

	/**
	 * The REST route being dispatched, or null outside a REST request.
	 *
	 * @var string|null
	 */
	private static $rest_route = null;

	/**
	 * Names of the write abilities this request offered to the shield, and the
	 * subset it left visible to other MCP servers.
	 *
	 * Recorded rather than recomputed. The diagnostic used to answer "are writes
	 * hidden?" by calling the exposure filter with empty args, which does not
	 * reproduce the registration: the filter receives each ability's own args and
	 * a caller may open exactly one of them. Only the decisions actually taken
	 * can answer the question, and this is where they are taken.
	 *
	 * @var string[]
	 */
	private static $writes_seen = array();

	/** @var string[] */
	private static $writes_left_exposed = array();

	/**
	 * Hook the recorder.
	 *
	 * `rest_pre_dispatch` fires once per REST request, after routing and before
	 * the route callback — so it is set before any ability executes, on every
	 * REST transport including servers we know nothing about.
	 *
	 * @since 1.30.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'record' ), 10, 3 );
	}

	/**
	 * Record the route. A pass-through filter: never alters the response.
	 *
	 * @since 1.30.0
	 *
	 * @param mixed $result  Short-circuit response, passed through untouched.
	 * @param mixed $server  The REST server (unused).
	 * @param mixed $request The request being dispatched.
	 * @return mixed $result, unchanged.
	 */
	public static function record( $result, $server = null, $request = null ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) ) {
			$route = $request->get_route();
			if ( is_string( $route ) ) {
				self::$rest_route = $route;
			}
		}
		return $result;
	}

	/**
	 * The REST route of this request, or null if this is not a REST request.
	 *
	 * @since 1.30.0
	 *
	 * @return string|null
	 */
	public static function rest_route(): ?string {
		return self::$rest_route;
	}

	/**
	 * This plugin's own MCP server route, as WordPress will have matched it.
	 *
	 * Derived from the same two constants `register_mcp_server()` passes to
	 * `create_server()`, so the two cannot drift apart — a hardcoded copy that
	 * fell out of step would silently classify our own server as foreign and
	 * deny every governed write.
	 *
	 * @since 1.30.0
	 *
	 * @return string
	 */
	public static function own_server_route(): string {
		return '/' . Elementor_MCP_Plugin::SERVER_ROUTE_NAMESPACE . '/' . Elementor_MCP_Plugin::SERVER_ROUTE;
	}

	/**
	 * Did this call arrive on this plugin's own MCP server route?
	 *
	 * @since 1.30.0
	 *
	 * @return bool
	 */
	public static function is_own_server(): bool {
		if ( null === self::$rest_route ) {
			return false;
		}
		return self::normalize( self::$rest_route ) === self::normalize( self::own_server_route() );
	}

	/**
	 * Is a governed (mutating) write allowed to run on this transport without
	 * presenting an approval grant?
	 *
	 * True for our own server route, for WP-CLI, and for anything that is not a
	 * REST request at all. That last case is deliberate: the exposure this
	 * guards against is a *co-installed MCP server*, and every one of those —
	 * ours, Angie's, any other — dispatches over REST. Treating cron, admin-ajax
	 * and direct PHP as foreign would deny writes that no remote agent could
	 * ever have reached, for no gain.
	 *
	 * WP-CLI is trusted for the same reason it is trusted everywhere else: a
	 * caller with shell access to the site does not need an ability to edit a
	 * page.
	 *
	 * @since 1.30.0
	 *
	 * @return bool
	 */
	public static function is_trusted_for_writes(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$trusted = true;
		} elseif ( null === self::$rest_route ) {
			$trusted = true;
		} else {
			$trusted = self::is_own_server();
		}

		/**
		 * Filters whether the current transport may run governed writes without
		 * an approval grant.
		 *
		 * Operators with a genuine second integration (a custom REST proxy in
		 * front of these tools, say) can trust it here. Returning true for a
		 * foreign MCP server re-opens the door this exists to close.
		 *
		 * @since 1.30.0
		 *
		 * @param bool        $trusted Whether the transport is trusted.
		 * @param string|null $route   The REST route, or null outside REST.
		 */
		return (bool) apply_filters( 'elementor_mcp_trusted_write_context', $trusted, self::$rest_route );
	}

	/**
	 * A short human-readable name for the current transport, for diagnostics and
	 * denial messages. Never includes query args — a route is enough to say
	 * where a call came from, and request bodies are not ours to echo.
	 *
	 * @since 1.30.0
	 *
	 * @return string
	 */
	public static function describe(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}
		if ( null === self::$rest_route ) {
			return 'non-rest';
		}
		if ( self::is_own_server() ) {
			return 'own-mcp-server';
		}
		return 'rest:' . self::$rest_route;
	}

	/**
	 * Normalize a route for comparison (leading slash, no trailing slash).
	 *
	 * @since 1.30.0
	 *
	 * @param string $route Route to normalize.
	 * @return string
	 */
	private static function normalize( string $route ): string {
		return '/' . trim( $route, '/' );
	}

	/**
	 * Mark a write-capable ability so co-installed MCP servers do not expose it.
	 *
	 * `wp_register_ability()` publishes to a site-wide registry, so a second MCP
	 * server on the same site gets this plugin's tools without asking. Elementor's
	 * Angie ships one (`/mcp/angie`, Angie 1.1.12+): its discovery treats any
	 * third-party ability whose `meta.mcp.type` is absent or `tool` as exposed, and
	 * its `execute-ability` proxy will then run it by name. Both its listing and its
	 * execution gate go through the same check, so declaring a type it does not
	 * serve removes the ability from the menu AND from the door.
	 *
	 * Read-only tools are deliberately left exposed — an assistant answering "what
	 * is on this page" from our data is the point of the read-only bridge. Only
	 * writes are withheld, because approval, snapshotting and audit live on the
	 * gateway side of a transport a second server bypasses entirely.
	 *
	 * This is not redundant with the governance guard in `run_governed()`. That
	 * guard only exists on sites where SiteAgent is installed — `wrap_ability()`
	 * returns the args untouched when its snapshot engine is absent, so on a
	 * fork-only site nothing wraps the write at all. This is that site's only
	 * protection.
	 *
	 * Deliberately narrow: it removes our writes from a foreign server's surface, it
	 * does not try to police that server. `elementor_mcp_expose_writes_to_foreign_mcp`
	 * re-opens it for an operator who wants exactly that.
	 *
	 * @since 1.30.0
	 *
	 * @param array $args Ability args (as passed to wp_register_ability()).
	 * @return array The args, with meta.mcp.type set when the ability writes.
	 */
	public static function shield_write_from_foreign_servers( array $args, string $name = '' ): array {
		if ( ! self::ability_writes( $args ) ) {
			return $args;
		}
		self::$writes_seen[] = $name;

		/**
		 * Filters whether this plugin's write tools stay visible to MCP servers
		 * other than this plugin's own.
		 *
		 * Off by default. Turning it on hands every write tool to any co-installed
		 * MCP server, on that server's authentication rather than the gateway's.
		 *
		 * @since 1.30.0
		 *
		 * @param bool  $expose Whether to leave write tools exposed. Default false.
		 * @param array $args   The ability args.
		 */
		$filter_opens = (bool) apply_filters( 'elementor_mcp_expose_writes_to_foreign_mcp', false, $args );
		// An ability that already declares a type keeps it either way: the
		// declaration is the author's, and 'resource'/'prompt' are equally not
		// 'tool' for this purpose. Which means the filter cannot expose such an
		// ability — a foreign server still reads the type it declared and still
		// refuses to serve it. Exposure is therefore decided by the EFFECTIVE
		// type, never by the filter's answer alone: recording "exposed" for an
		// ability that is in fact hidden would have the diagnostic naming a tool
		// nothing can reach, which is the same false report in the other
		// direction.
		$declared = isset( $args['meta']['mcp']['type'] ) ? $args['meta']['mcp']['type'] : null;
		if ( $filter_opens || null !== $declared ) {
			// Nothing is written in either case; the effective type is whatever
			// the ability already declared, or none (which any consumer reads as
			// 'tool').
			if ( null === $declared || 'tool' === $declared ) {
				self::$writes_left_exposed[] = $name;
			}
			return $args;
		}
		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
			$args['meta'] = array();
		}
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = array();
		}
		// Our own server is unaffected: it is created with an explicit list of tool
		// names (Elementor_MCP_Plugin::register_mcp_server), and the bundled adapter
		// consults meta.mcp.type only when building the DEFAULT server from abilities
		// that also set meta.mcp.public — which none of ours do.
		$args['meta']['mcp']['type'] = 'private';

		return $args;
	}

	/**
	 * Write abilities that passed through the shield this request.
	 *
	 * @since 1.30.0
	 *
	 * @return string[]
	 */
	public static function writes_seen(): array {
		return self::$writes_seen;
	}

	/**
	 * Write abilities the shield left visible to other MCP servers — because the
	 * exposure filter opened them, or because they declared `mcp.type = 'tool'`
	 * themselves. Empty is the safe state.
	 *
	 * @since 1.30.0
	 *
	 * @return string[]
	 */
	public static function writes_left_exposed(): array {
		return self::$writes_left_exposed;
	}

	/**
	 * Decide whether a write-capable ability may even be *attempted* on this
	 * transport, before its own permission check runs.
	 *
	 * The registration-time shield (`shield_write_from_foreign_servers()`) keeps
	 * write tools out of another server's menu, and the governance layer refuses
	 * an ungranted foreign write at execute time. Neither is sufficient alone on
	 * the site this exists for: **a fork-only install has no governance layer**
	 * — `Governance::wrap_ability()` returns every ability untouched without
	 * SiteAgent's snapshot engine — so the metadata was the only thing standing
	 * between a co-installed server and a published page. That made the whole
	 * protection depend on another plugin continuing to honour a convention.
	 *
	 * This gate does not. It runs on every site, for every write-capable
	 * ability, at the permission stage: a caller that cannot present gateway
	 * context is refused before the tool is reached.
	 *
	 * **Presence, not verification.** A grant is checked for existence here and
	 * verified once, later, by the governance layer. Verifying it twice would
	 * burn its single-use nonce and reject the second check — the legitimate
	 * call would fail on its own approval. Presence is the right question at
	 * this stage anyway: it separates "a gateway sent this" from "something else
	 * did", and the signature decides the rest.
	 *
	 * Without SiteAgent's verifier installed there is nothing that *could*
	 * validate a grant, so a foreign write is refused outright rather than
	 * waved through on an unverifiable header.
	 *
	 * @since 1.31.0
	 *
	 * @return true|\WP_Error True when the attempt may proceed.
	 */
	public static function write_permission_gate() {
		$reason = self::write_permission_decision(
			self::is_trusted_for_writes(),
			class_exists( '\\Aura_Worker_Grant' ),
			'' !== ( isset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] ) ? (string) $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] : '' )
		);
		if ( null === $reason ) {
			return true;
		}

		$where = self::describe();

		if ( 'no_verifier' === $reason ) {
			return new \WP_Error(
				'untrusted_transport',
				sprintf(
					/* translators: %s: transport description */
					__( 'This tool changes site content and was called on %s, which is not this plugin\'s own MCP server. Calls from elsewhere need an approval grant, and no grant verifier is installed on this site.', 'elementor-mcp' ),
					$where
				),
				array( 'status' => 403 )
			);
		}

		return new \WP_Error(
			'untrusted_transport',
			sprintf(
				/* translators: %s: transport description */
				__( 'This tool changes site content and was called on %s, which the Aura gateway does not see. Such a call must carry an approval grant (X-Aura-Approval-Grant).', 'elementor-mcp' ),
				$where
			),
			array( 'status' => 403 )
		);
	}

	/**
	 * The gate's decision, given the three facts it turns on.
	 *
	 * Split out because two of those facts come from `class_exists()` and
	 * `$_SERVER`, and a suite cannot unload a class — the fork-only branch,
	 * which is the entire reason this gate exists, would otherwise be the one
	 * combination no test could reach.
	 *
	 * @since 1.31.0
	 *
	 * @param bool $trusted            Whether the transport may write on its own authority.
	 * @param bool $verifier_available Whether SiteAgent's grant verifier is installed.
	 * @param bool $grant_present      Whether a grant header was sent.
	 * @return string|null Refusal reason, or null to allow.
	 */
	public static function write_permission_decision( bool $trusted, bool $verifier_available, bool $grant_present ): ?string {
		if ( $trusted ) {
			return null;
		}
		if ( ! $verifier_available ) {
			// Nothing on this site could validate a grant, so a foreign write is
			// refused outright rather than waved through on an unverifiable
			// header. This is the fork-only case: no gateway, no approval
			// possible, no reason to accept the attempt.
			return 'no_verifier';
		}
		if ( ! $grant_present ) {
			return 'no_grant';
		}
		return null;
	}

	/**
	 * Does this ability declare that it writes?
	 *
	 * One definition, shared by the registration shield, the permission gate and
	 * the governance wrapper: an EXPLICIT `readonly === false`. Three guards
	 * disagreeing about what a write is would leave gaps exactly where they
	 * overlap, and an ability that never classified itself is not known to
	 * write — hiding or gating a read tool costs a real capability.
	 *
	 * @since 1.31.0
	 *
	 * @param array $args Ability args.
	 * @return bool
	 */
	public static function ability_writes( array $args ): bool {
		$annotations = ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) )
			? $args['meta']['annotations']
			: null;
		return null !== $annotations
			&& array_key_exists( 'readonly', $annotations )
			&& false === $annotations['readonly'];
	}

	/**
	 * Wrap a write-capable ability's permission callback with the transport
	 * gate, so an untrusted caller is refused before the ability is reached.
	 *
	 * Wrapping rather than replacing: the ability's own capability check still
	 * runs, and still runs LAST, so this can only ever deny more than before.
	 *
	 * @since 1.31.0
	 *
	 * @param array $args Ability args.
	 * @return array
	 */
	public static function gate_write_permission( array $args ): array {
		if ( ! self::ability_writes( $args ) ) {
			return $args;
		}

		$inner = isset( $args['permission_callback'] ) ? $args['permission_callback'] : null;

		$args['permission_callback'] = static function () use ( $inner ) {
			$gate = self::write_permission_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			if ( null === $inner || ! is_callable( $inner ) ) {
				// An ability with no permission callback of its own is not this
				// gate's business to authorize; preserve whatever the registry
				// would have done with it.
				return true;
			}
			return call_user_func_array( $inner, func_get_args() );
		};

		return $args;
	}

	/**
	 * Clear the recorded route. Tests only — a request is a fresh process.
	 *
	 * @since 1.30.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rest_route          = null;
		self::$writes_seen         = array();
		self::$writes_left_exposed = array();
	}
}
