<?php
/**
 * Angie browser-MCP bridge (read-only v1).
 *
 * Exposes a curated, read-only subset of the plugin's registered abilities to
 * Elementor's Angie assistant. A small browser bundle (assets/angie-bridge/)
 * registers an MCP server with Angie via the public @elementor/angie-sdk; that
 * bundle lists and executes tools through the two REST routes defined here.
 *
 * Trust model: this is the operator path — the logged-in admin's cookie
 * session + REST nonce + each ability's own permission callback. It is NOT the
 * gateway/token path, so SiteAgent approval grants never apply here. That is
 * why v1 is read-only: an LLM-chosen mutation would execute without the
 * per-mutation human approval the governed write path requires. Write tools
 * are deliberately absent and requests for them return `bridge_writes_phase_b`.
 *
 * @package Elementor_MCP
 * @since   1.26.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Angie bridge REST surface and enqueues the browser bundle.
 *
 * @since 1.26.0
 */
class Elementor_MCP_Angie_Bridge {

	/**
	 * Option controlling whether the bridge is active. Default OFF.
	 */
	const OPTION_ENABLED = 'elementor_mcp_angie_bridge_enabled';

	/**
	 * REST namespace for the bridge routes.
	 */
	const REST_NAMESPACE = 'emcp/angie/v1';

	/**
	 * Server name the browser bundle registers with Angie.
	 */
	const SERVER_NAME = 'aura-design-engine';

	/**
	 * Whether the browser bundle was already enqueued this request.
	 *
	 * @since 1.27.1
	 *
	 * @var bool
	 */
	private $bridge_enqueued = false;

	/**
	 * Initializes hooks. Call once from the plugin bootstrap.
	 *
	 * @since 1.26.0
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_bridge' ), 20 );

		// The Elementor editor is NOT an ordinary admin screen: it is rendered
		// from `admin_action_elementor`, before admin-header.php, so
		// `admin_enqueue_scripts` never fires there — and Elementor then calls
		// remove_all_actions( 'wp_enqueue_scripts' ) and rebuilds a front-end
		// style document. Its own editor hook is the only one that runs, and
		// it is where Angie itself loads, so the bridge must ride it too.
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'maybe_enqueue_bridge' ), 20 );
	}

	/**
	 * Whether the bridge is enabled.
	 *
	 * @since 1.26.0
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * The curated, read-only tool allowlist.
	 *
	 * Every entry MUST be a registered ability whose meta.annotations.readonly
	 * is true — enforced at runtime by get_tools()/execute_tool() and by the
	 * AngieBridgeAllowlistTest regression guard. Adding a mutating ability here
	 * is not a configuration change; it requires the Phase B approval-queue
	 * mechanism (see the K3 spec in Aura docs/plans/angie-bridge-spec.md).
	 *
	 * @since 1.26.0
	 *
	 * @return string[] Fully-qualified ability names.
	 */
	public static function get_allowed_tools(): array {
		$tools = array(
			'elementor-mcp/list-widgets',
			'elementor-mcp/get-widget-schema',
			'elementor-mcp/get-page-structure',
			'elementor-mcp/list-pages',
			'elementor-mcp/list-global-classes',
			'elementor-mcp/list-variables',
		);

		/**
		 * Filters the Angie bridge tool allowlist.
		 *
		 * Filtered entries that are unregistered or not read-only are dropped
		 * at runtime — the read-only invariant is enforced after this filter,
		 * so a filter cannot smuggle a write tool into the bridge.
		 *
		 * @since 1.26.0
		 *
		 * @param string[] $tools Ability names.
		 */
		$filtered = apply_filters( 'emcp_angie_bridge_tools', $tools );

		return is_array( $filtered ) ? array_values( array_unique( array_map( 'strval', $filtered ) ) ) : $tools;
	}

	/**
	 * Whether the current user may use the bridge at all.
	 *
	 * Matches the weakest capability among the allowlisted read abilities
	 * (edit_posts); each ability still runs its own permission callback on
	 * execute, so this is a gate, not the authority.
	 *
	 * @since 1.26.0
	 *
	 * @return bool
	 */
	private function current_user_can_access_bridge(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the two bridge REST routes.
	 *
	 * @since 1.26.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_tools' ),
				'permission_callback' => array( $this, 'route_permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/execute/(?P<tool>[a-z0-9\-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'execute_tool' ),
				'permission_callback' => array( $this, 'route_permission_check' ),
				'args'                => array(
					'tool' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Shared permission check for both routes.
	 *
	 * Nonce note: cookie-authenticated REST requests are already nonce-gated by
	 * WordPress core (rest_cookie_check_errors) — a missing or invalid
	 * X-WP-Nonce leaves the request unauthenticated, so is_user_logged_in()
	 * fails here with 401. No separate wp_verify_nonce() call is needed.
	 *
	 * @since 1.26.0
	 *
	 * @return true|WP_Error
	 */
	public function route_permission_check() {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'emcp_angie_bridge_disabled',
				__( 'The Angie bridge is disabled.', 'elementor-mcp' ),
				array( 'status' => 404 )
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'emcp_angie_bridge_auth_required',
				__( 'Authentication required.', 'elementor-mcp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->current_user_can_access_bridge() ) {
			return new WP_Error(
				'emcp_angie_bridge_forbidden',
				__( 'You do not have permission to use the Angie bridge.', 'elementor-mcp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Resolves an allowlisted ability, enforcing the read-only invariant.
	 *
	 * @since 1.26.0
	 *
	 * @param string $short_name Short tool name (no namespace prefix).
	 * @return object|null The WP_Ability, or null when unavailable/not read-only.
	 */
	private function resolve_read_ability( string $short_name ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$full_name = 'elementor-mcp/' . $short_name;
		if ( ! in_array( $full_name, self::get_allowed_tools(), true ) ) {
			return null;
		}

		$ability = wp_get_ability( $full_name );
		if ( ! $ability ) {
			return null;
		}

		if ( ! self::ability_is_read_only( $ability ) ) {
			return null;
		}

		return $ability;
	}

	/**
	 * Whether an ability declares itself read-only and non-destructive.
	 *
	 * @since 1.26.0
	 *
	 * @param object|mixed $ability A WP_Ability instance (anything else fails closed).
	 * @return bool
	 */
	public static function ability_is_read_only( $ability ): bool {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}

		$meta        = (array) $ability->get_meta();
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

		$readonly    = isset( $annotations['readonly'] ) && true === $annotations['readonly'];
		$destructive = isset( $annotations['destructive'] ) && true === $annotations['destructive'];

		return $readonly && ! $destructive;
	}

	/**
	 * GET /tools — the MCP tool list for the browser bundle.
	 *
	 * Only registered, read-only allowlisted abilities appear; experiment-gated
	 * abilities (list-global-classes, list-variables) drop out naturally on
	 * sites where they are not registered.
	 *
	 * @since 1.26.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_tools(): WP_REST_Response {
		$tools = array();

		foreach ( self::get_allowed_tools() as $full_name ) {
			$short   = substr( $full_name, strlen( 'elementor-mcp/' ) );
			$ability = $this->resolve_read_ability( $short );
			if ( ! $ability ) {
				continue;
			}

			$input_schema = $ability->get_input_schema();

			$tools[] = array(
				'name'        => $short,
				'description' => trim( (string) $ability->get_description() ),
				// MCP requires an object schema; abilities without inputs may
				// register an empty schema.
				'inputSchema' => ! empty( $input_schema ) ? $input_schema : array( 'type' => 'object' ),
				'annotations' => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
				),
			);
		}

		return new WP_REST_Response( array( 'tools' => $tools ), 200 );
	}

	/**
	 * POST /execute/{tool} — runs one allowlisted read ability.
	 *
	 * Returns an MCP CallToolResult-shaped body so the browser bundle can pass
	 * it through verbatim.
	 *
	 * @since 1.26.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function execute_tool( WP_REST_Request $request ): WP_REST_Response {
		$short = sanitize_key( (string) $request->get_param( 'tool' ) );

		// Anything outside the allowlist is a plain 404 — the bridge does not
		// advertise what else exists. The Phase B error is reserved for the
		// drift case: a mutating ability sitting under an allowlisted name.
		$ability = $this->resolve_read_ability( $short );
		if ( ! $ability ) {
			$full_name    = 'elementor-mcp/' . $short;
			$allowlisted  = in_array( $full_name, self::get_allowed_tools(), true );
			$registered   = ( $allowlisted && function_exists( 'wp_get_ability' ) ) ? wp_get_ability( $full_name ) : null;

			if ( $registered && ! self::ability_is_read_only( $registered ) ) {
				return new WP_REST_Response(
					array(
						'code'    => 'bridge_writes_phase_b',
						'message' => __( 'Write tools are not available through the Angie bridge yet. Mutations require the approval-queue mechanism (Phase B); use the governed MCP connection instead.', 'elementor-mcp' ),
					),
					403
				);
			}

			return new WP_REST_Response(
				array(
					'code'    => 'emcp_angie_tool_not_found',
					'message' => __( 'Tool not found.', 'elementor-mcp' ),
				),
				404
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// Enforce the ability's own permission callback explicitly BEFORE
		// executing. Core WP_Ability::execute() also checks permissions
		// internally, but the bridge must not depend on that implementation
		// detail: a filter-added read ability with a stronger requirement
		// (e.g. manage_options) must refuse an editor here regardless of which
		// Abilities implementation is loaded.
		if ( method_exists( $ability, 'check_permissions' ) ) {
			$permitted = $ability->check_permissions( $params );
			if ( true !== $permitted ) {
				return new WP_REST_Response(
					array(
						'code'    => 'emcp_angie_tool_forbidden',
						'message' => __( 'You do not have permission to run this tool.', 'elementor-mcp' ),
					),
					403
				);
			}
		}

		// WP_Ability::execute() additionally validates the input schema (and
		// re-checks permissions on core implementations).
		$result = $ability->execute( $params );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
					'isError' => true,
					'_meta'   => array( 'source' => 'angie-bridge' ),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $result ),
					),
				),
				'isError' => false,
				'_meta'   => array( 'source' => 'angie-bridge' ),
			),
			200
		);
	}

	/**
	 * Enqueues the browser bundle on eligible wp-admin requests.
	 *
	 * @since 1.26.0
	 */
	public function maybe_enqueue_bridge(): void {
		// Both hooks are registered because neither covers every surface Angie
		// appears on; on a screen where both were to fire, the second pass must
		// not print the localized config a second time.
		if ( $this->bridge_enqueued ) {
			return;
		}

		if ( ! is_admin() || ! self::is_enabled() || ! $this->current_user_can_access_bridge() ) {
			return;
		}

		// Angie's assistant is available across wp-admin, so the bridge must be
		// present wherever Angie is — but there is no point shipping the bundle
		// when the Angie plugin is not installed at all.
		if ( ! defined( 'ANGIE_VERSION' ) && ! class_exists( 'Angie', false ) ) {
			return;
		}

		$script_path = ELEMENTOR_MCP_DIR . 'assets/angie-bridge/dist/angie-bridge.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			'elementor-mcp-angie-bridge',
			plugins_url( 'assets/angie-bridge/dist/angie-bridge.js', ELEMENTOR_MCP_DIR . 'elementor-mcp.php' ),
			array( 'wp-api-fetch' ),
			(string) filemtime( $script_path ),
			true
		);

		$this->bridge_enqueued = true;

		wp_localize_script(
			'elementor-mcp-angie-bridge',
			'emcpAngieBridge',
			array(
				'toolsEndpoint' => rest_url( self::REST_NAMESPACE . '/tools' ),
				'executeBase'   => rest_url( self::REST_NAMESPACE . '/execute/' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'version'       => defined( 'ELEMENTOR_MCP_VERSION' ) ? ELEMENTOR_MCP_VERSION : '0',
				'serverName'    => self::SERVER_NAME,
				'serverLabel'   => 'Aura Design Engine',
			)
		);
	}
}
