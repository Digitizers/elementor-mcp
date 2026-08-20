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
		$annotations = ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) )
			? $args['meta']['annotations']
			: null;
		// Same classification as the governance wrapper: an explicit readonly=false.
		// Annotations we cannot classify are left alone — an unclassifiable ability
		// is not known to write, and hiding a read tool costs a real capability.
		$writes = null !== $annotations
			&& array_key_exists( 'readonly', $annotations )
			&& false === $annotations['readonly'];
		if ( ! $writes ) {
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
