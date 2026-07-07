<?php
/**
 * SiteAgent governance bridge for Elementor page writes.
 *
 * When the SiteAgent worker (digitizer-site-worker) is installed alongside this
 * plugin, a write-capable ability that writes page data has the page's Elementor
 * state (`_elementor_data` / `_elementor_page_settings`) snapshotted BEFORE the
 * write and rolled back if the write fails — the same capture-before-write safety
 * SiteAgent gives its own power tools. This covers create-style writes (whose
 * target post id is not in the input) as well as edits, since the snapshot/grant
 * fire from the write site, which learns the post id there.
 *
 * How it decides WHAT to snapshot — robustly, without a hand-maintained list:
 * the ability wrapper "arms" a governed run for the target post, then the actual
 * page-data write site (`Elementor_MCP_Data::save_page_data()` /
 * `save_page_settings()`) calls back into before_page_write(), which captures the
 * snapshot lazily on the first real write. Consequences, all correct by
 * construction:
 *   - every save_page_data() caller is covered (no allowlist to keep in sync);
 *   - tools that write OTHER state (template conditions -> _elementor_conditions,
 *     SEO meta) never reach save_page_data(), so they are never snapshotted;
 *   - a preview call (an a11y/SEO generator with apply=false) writes nothing,
 *     so it is never snapshotted — no false rollback point, no spurious deny.
 * The tool boundary is preserved (the wrapper knows the tool name + input), which
 * is where server-enforced approval grants will hook in a later plank.
 *
 * Server-enforced approval (1.18.0): when SiteAgent's Ed25519 grant regime is
 * active AND grant enforcement is opted in for this plugin
 * (emcp_governance_require_grants()), a governed write must present a valid grant
 * bound to its exact tool + params. The grant is checked BEFORE the callback runs
 * (so a create-style tool cannot wp_insert_post an unauthorized draft before
 * approval), and skipped only for a dry-run preview — a preview-capable tool (its
 * schema declares an `apply` flag) invoked with apply falsy writes nothing. Opt-in
 * so it cannot deny Elementor edits before the gateway is minting grants for this
 * plugin's tool names.
 *
 * Post-write render check (1.19.0, opt-in — emcp_governance_render_check()): after
 * a successful governed write, the edited page's front end is fetched and, if it
 * comes back DEFINITELY broken (HTTP 5xx, empty body / WSOD, or WordPress's fatal
 * page), the write is reverted to its pre-write snapshot. Fail-safe: a transient
 * or ambiguous response never reverts a good write.
 *
 * Soft dependency: when SiteAgent's snapshot engine (\Aura_Worker_Snapshots) is
 * NOT present, nothing is wrapped and behaviour is identical to the standalone
 * plugin. The plugin never hard-requires SiteAgent.
 *
 * @package Elementor_MCP
 * @since 1.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor_MCP_Governance {

	/**
	 * Post-meta keys that hold a page's Elementor state. Snapshotting both means
	 * a rollback restores the element tree AND the page-level settings together.
	 *
	 * @var string[]
	 */
	public const PAGE_META_KEYS = array( '_elementor_data', '_elementor_page_settings' );

	/**
	 * The governed run currently in flight, or null. Shape:
	 *   array{ post_id:int, name:string, snapshot_id:?string, snapshot_failed:bool }
	 * Request-scoped: only one MCP tool executes at a time, and page writes happen
	 * synchronously inside run_governed(), so a single static frame is sufficient.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $run = null;

	/**
	 * Reusable snapshot-engine instance (lazily created).
	 *
	 * @var \Aura_Worker_Snapshots|null
	 */
	private static $snapshots = null;

	/**
	 * Is SiteAgent's snapshot engine available to govern writes?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( '\\Aura_Worker_Snapshots' );
	}

	/**
	 * Decorate a write-capable ability (annotated readonly=false) with governed
	 * execution. Read-only tools, tools without classifiable annotations, and any
	 * ability without a callable execute callback are returned unchanged, so this
	 * is always safe to call for every ability at registration time.
	 *
	 * Wrapping a write tool that turns out NOT to touch page data is harmless: its
	 * governed run simply never captures a snapshot (see before_page_write()).
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability args (as passed to wp_register_ability()).
	 * @return array The (possibly wrapped) args.
	 */
	public static function wrap_ability( string $name, array $args ): array {
		if ( ! self::is_active() ) {
			return $args;
		}
		$annotations = ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) )
			? $args['meta']['annotations']
			: null;
		$writes = null !== $annotations
			&& array_key_exists( 'readonly', $annotations )
			&& false === $annotations['readonly'];
		if ( ! $writes ) {
			return $args; // read-only, or annotations we cannot classify → untouched
		}
		if ( empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		// A tool is preview-capable iff its input schema declares an `apply` flag
		// (the a11y/SEO generators: apply falsy = dry-run preview, apply truthy =
		// write). We thread this to run_governed so a preview can skip the grant
		// without a hardcoded tool list.
		$preview_capable          = isset( $args['input_schema']['properties']['apply'] );
		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input ) use ( $original, $name, $preview_capable ) {
			return self::run_governed( $name, $original, $input, $preview_capable );
		};
		return $args;
	}

	/**
	 * Run a write-capable ability under governance: arm the run, execute, and (if
	 * the tool actually captured a page snapshot) roll back on failure.
	 *
	 * @param string   $name            Ability name (for error/audit context).
	 * @param callable $original        The wrapped execute callback.
	 * @param mixed    $input           The ability input.
	 * @param bool     $preview_capable Whether the tool declares an `apply` flag.
	 * @return mixed The original result, or a \WP_Error when the grant was rejected
	 *               or a failed write was rolled back.
	 */
	public static function run_governed( string $name, $original, $input, bool $preview_capable = false ) {
		// Server-enforced approval, checked BEFORE the callback runs — so a
		// create-style tool cannot wp_insert_post() an unauthorized draft before we
		// verify the grant. Skipped only for a dry-run preview (a preview-capable
		// tool invoked with apply falsy writes nothing), which never needs approval.
		if ( self::grants_required() && ! self::is_preview_call( $preview_capable, $input ) ) {
			$grant = self::verify_grant( $name, $input );
			if ( is_wp_error( $grant ) ) {
				return $grant;
			}
		}

		// Arm the run. The snapshot fires from the page-write site
		// (before_page_write), which learns the actual post id there — so
		// create-style writes with no input post_id are still snapshotted.
		self::$run = array(
			'name'            => $name,
			'input'           => $input,
			'post_id'         => 0, // set lazily on the first real page write
			'snapshot_id'     => null,
			'snapshot_failed' => false,
			'baseline'        => 'inconclusive', // pre-write render state
		);

		try {
			$result = call_user_func( $original, $input );
		} catch ( \Throwable $e ) {
			$out       = self::finish_failure( $name, self::$run['post_id'], $e->getMessage() );
			self::$run = null;
			return $out;
		}

		if ( is_wp_error( $result ) ) {
			$out       = self::finish_failure( $name, self::$run['post_id'], $result->get_error_message(), $result );
			self::$run = null;
			return $out;
		}

		// Success — the tool captured a snapshot, i.e. it actually wrote page data.
		if ( null !== self::$run['snapshot_id'] ) {
			$post_id     = self::$run['post_id'];
			$snapshot_id = self::$run['snapshot_id'];

			// Optional post-write render check: revert only when a CONFIRMED-healthy
			// pre-write baseline turned 'broken' — i.e. the write actually broke the
			// page. If the baseline was merely inconclusive (flaky loopback, WAF,
			// already-broken), we never proved the write was the cause, so keep it.
			$baseline = self::$run['baseline'] ?? 'inconclusive';
			if ( self::render_check_enabled() && 'healthy' === $baseline && 'broken' === self::probe_render( $post_id ) ) {
				$restore   = self::snapshots()->restore( $snapshot_id );
				self::$run = null;
				if ( empty( $restore['success'] ) ) {
					return self::rollback_failed_error( $name, $post_id, $snapshot_id, 'page did not render after the write', $restore );
				}

				/**
				 * Fires after a governed write was reverted because the page failed
				 * its post-write render check.
				 *
				 * @param string $name        Ability name.
				 * @param int    $post_id     Reverted post id.
				 * @param string $snapshot_id Snapshot restored.
				 * @param array  $restore     Restore result.
				 */
				do_action( 'elementor_mcp_governance_render_reverted', $name, $post_id, $snapshot_id, $restore );
				return new \WP_Error(
					'governance_render_failed',
					sprintf(
						/* translators: 1: tool name, 2: post id */
						__( '%1$s left page %2$d not rendering; the change was reverted.', 'elementor-mcp' ),
						$name,
						$post_id
					)
				);
			}

			// Expose the rollback point so the gateway can offer an undo.
			do_action( 'elementor_mcp_governance_write', $name, $post_id, $snapshot_id, $result );
		}
		self::$run = null;
		return $result;
	}

	/**
	 * Capture the page snapshot on the first real page-data write of a governed
	 * run. Called by Elementor_MCP_Data::save_page_data() / save_page_settings()
	 * BEFORE they persist anything.
	 *
	 * A cheap no-op unless a governed run is in flight and no snapshot has been
	 * taken yet. The first real write of the run defines the post to protect.
	 * Returns a \WP_Error when the snapshot cannot be captured, so the caller can
	 * fail closed (refuse the write rather than mutate without a rollback point);
	 * returns null otherwise. (Grant enforcement happens earlier, in run_governed.)
	 *
	 * @param int $post_id The post about to be written.
	 * @return \WP_Error|null
	 */
	public static function before_page_write( $post_id ) {
		if ( null === self::$run || ! self::is_active() ) {
			return null; // no governed run in flight
		}
		if ( null !== self::$run['snapshot_id'] || self::$run['snapshot_failed'] ) {
			return null; // already snapshotted (or already failed) this run
		}
		$post_id = absint( $post_id );

		// The first real write of the run defines the post to protect + roll back.
		self::$run['post_id'] = $post_id;
		$snap                 = self::snapshots()->snapshot_meta( $post_id, self::PAGE_META_KEYS );
		if ( empty( $snap['success'] ) ) {
			self::$run['snapshot_failed'] = true;
			return new \WP_Error(
				'governance_snapshot_failed',
				sprintf(
					/* translators: 1: tool name, 2: reason */
					__( 'Refusing %1$s: could not snapshot the page before writing (%2$s).', 'elementor-mcp' ),
					self::$run['name'],
					isset( $snap['error'] ) ? $snap['error'] : 'unknown error'
				)
			);
		}
		self::$run['snapshot_id'] = $snap['snapshot']['id'];

		// Baseline the page's render BEFORE the write (the old data is still in
		// place at this point) so the post-write check can tell a breakage the edit
		// caused from one that was already there. Only a CONFIRMED-healthy baseline
		// permits a later revert (see the tri-state in probe_render()).
		if ( self::render_check_enabled() ) {
			self::$run['baseline'] = self::probe_render( $post_id );
		}
		return null;
	}

	/**
	 * Resolve a failed governed write: roll back if a snapshot was captured, and
	 * build the error to return.
	 *
	 * @param string        $name        Ability name.
	 * @param int           $post_id     Target post id.
	 * @param string        $write_error Original write error message.
	 * @param \WP_Error|null $original   The original WP_Error result, if any (returned
	 *                                   verbatim after a clean rollback).
	 * @return mixed
	 */
	private static function finish_failure( string $name, int $post_id, string $write_error, $original = null ) {
		$snapshot_id = self::$run['snapshot_id'] ?? null;

		// No snapshot was captured → the tool wrote no page data (or the snapshot
		// itself failed and the write was refused). Nothing to roll back.
		if ( null === $snapshot_id ) {
			return null !== $original
				? $original
				: new \WP_Error(
					'governance_write_threw',
					sprintf(
						/* translators: 1: tool name, 2: error message */
						__( '%1$s failed: %2$s', 'elementor-mcp' ),
						$name,
						$write_error
					)
				);
		}

		$restore = self::snapshots()->restore( $snapshot_id );
		if ( empty( $restore['success'] ) ) {
			return self::rollback_failed_error( $name, $post_id, $snapshot_id, $write_error, $restore );
		}

		/**
		 * Fires after a governed write failed and the page was rolled back.
		 *
		 * @param string    $name        Ability name.
		 * @param int       $post_id     Target post id.
		 * @param string    $snapshot_id Snapshot used for the rollback.
		 * @param string    $write_error Original write error message.
		 * @param array     $restore     Result of the restore attempt.
		 */
		do_action( 'elementor_mcp_governance_rolled_back', $name, $post_id, $snapshot_id, $write_error, $restore );

		return null !== $original
			? $original
			: new \WP_Error(
				'governance_write_threw',
				sprintf(
					/* translators: 1: tool name, 2: error message */
					__( '%1$s failed and was rolled back: %2$s', 'elementor-mcp' ),
					$name,
					$write_error
				)
			);
	}

	/**
	 * The error returned when a rollback ITSELF failed — the page may be left in a
	 * partially written state, so the caller/gateway must be told the restore did
	 * not succeed. Shared by the write-failure and render-check revert paths.
	 *
	 * @param string $name        Ability name.
	 * @param int    $post_id     Target post id.
	 * @param string $snapshot_id Snapshot that could not be restored.
	 * @param string $write_error What the write did (error message or context).
	 * @param array  $restore     The failed restore result.
	 * @return \WP_Error
	 */
	private static function rollback_failed_error( string $name, int $post_id, string $snapshot_id, string $write_error, array $restore ): \WP_Error {
		do_action( 'elementor_mcp_governance_rollback_failed', $name, $post_id, $snapshot_id, $write_error, $restore );
		return new \WP_Error(
			'governance_rollback_failed',
			sprintf(
				/* translators: 1: tool name, 2: write error/context, 3: restore error, 4: post id, 5: snapshot id */
				__( '%1$s failed (%2$s) AND rollback failed (%3$s); page %4$d may be partially written. Snapshot %5$s must be restored manually.', 'elementor-mcp' ),
				$name,
				$write_error,
				isset( $restore['error'] ) ? $restore['error'] : 'unknown error',
				$post_id,
				$snapshot_id
			)
		);
	}

	/**
	 * Whether governed writes must present a valid SiteAgent approval grant right
	 * now: SiteAgent's grant regime must be active (a gateway key provisioned) AND
	 * grant enforcement explicitly enabled for this plugin (opt-in — see
	 * emcp_governance_require_grants()). Both conditions fail closed to "false" so
	 * enabling SiteAgent's grants for its own tools never silently denies every
	 * Elementor edit before the gateway mints grants for this plugin's tools.
	 *
	 * @return bool
	 */
	public static function grants_required(): bool {
		if ( ! class_exists( '\\Aura_Worker_Grant' ) || ! \Aura_Worker_Grant::is_enforced() ) {
			return false;
		}
		return function_exists( 'emcp_governance_require_grants' ) && emcp_governance_require_grants();
	}

	/**
	 * Whether this call is a dry-run preview that needs no approval: the tool is
	 * preview-capable (its input schema declares an `apply` flag) AND `apply` is
	 * falsy/absent, so it writes nothing. A tool with no `apply` flag is never a
	 * preview — it always needs a grant when enforcement is on.
	 *
	 * @param bool  $preview_capable Whether the tool declares an `apply` flag.
	 * @param mixed $input           The ability input.
	 * @return bool
	 */
	private static function is_preview_call( bool $preview_capable, $input ): bool {
		return $preview_capable && ( ! is_array( $input ) || empty( $input['apply'] ) );
	}

	/**
	 * Whether governed page writes run a post-write render check (opt-in — see
	 * emcp_governance_require_grants()'s sibling emcp_governance_render_check()).
	 *
	 * @return bool
	 */
	public static function render_check_enabled(): bool {
		return self::is_active()
			&& function_exists( 'emcp_governance_render_check' )
			&& emcp_governance_render_check();
	}

	/**
	 * Fetch the page's front end and classify its render as one of three states —
	 * 'healthy', 'broken', or 'inconclusive'. The tri-state matters: a write is
	 * only reverted when a CONFIRMED-healthy baseline turns 'broken', so a baseline
	 * that was merely inconclusive (never proved healthy) can never trigger a revert.
	 *
	 *   - 'broken'       = HTTP 5xx (a real PHP fatal is served by WordPress's fatal
	 *                      handler with a 500 status), or an empty 2xx body (white
	 *                      screen). We do NOT scan a 200 body for a fatal string —
	 *                      that would false-positive on legitimate page copy.
	 *   - 'inconclusive' = a transient loopback failure (timeout/DNS — WP_Error), a
	 *                      3xx/4xx (auth redirect, WAF 401/403, protected 404), or a
	 *                      page that is not a published, publicly-viewable page
	 *                      (drafts, elementor_library templates/popups, non-viewable
	 *                      post types). Never causes a revert.
	 *   - 'healthy'      = a non-empty 2xx with no fatal marker.
	 *
	 * @param int $post_id The page to probe.
	 * @return string 'healthy' | 'broken' | 'inconclusive'
	 */
	private static function probe_render( int $post_id ): string {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return 'inconclusive'; // drafts/private pages aren't served anonymously
		}
		// Elementor library items (templates / popups / theme parts) and any
		// non-viewable post type have no meaningful public page render.
		$post_type = get_post_type( $post_id );
		if ( 'elementor_library' === $post_type || ! is_post_type_viewable( $post_type ) ) {
			return 'inconclusive';
		}
		$url = get_permalink( $post_id );
		// A get_permalink filter (e.g. a permalink-rewriting plugin) can point a
		// post at an EXTERNAL url. redirection=0 only stops later hops, not the
		// initial request, so validate the permalink is on this site's own origin
		// before fetching — otherwise the probe becomes an SSRF to an arbitrary host.
		if ( ! $url || ! self::is_same_origin( $url ) ) {
			return 'inconclusive';
		}

		// Cache-bust the probe: a warm full-page cache / CDN could otherwise serve
		// a cached page and hide a fatal. A unique query arg makes common WP page
		// caches (Super Cache, W3TC) skip the cache, and the no-cache headers ask
		// intermediaries not to serve a hit. Best-effort — a cache that ignores
		// query strings for anonymous hits can still mask breakage.
		$probe_url = add_query_arg( array( 'emcp_render_check' => uniqid() ), $url );
		$response  = wp_remote_get(
			$probe_url,
			array(
				'timeout'     => 15,
				// Leave TLS verification ON (WP default). Forcing sslverify off would
				// let a spoofed cert / on-path attacker control the probe response and
				// defeat the check; a real cert error just fails safe (WP_Error →
				// inconclusive → keep the write).
				// Do NOT follow redirects: an edited page that issues an off-origin
				// (open) redirect would otherwise make wp_remote_get fetch an
				// attacker-controlled URL server-side — an SSRF. The initial URL is
				// always this site's own permalink; a redirect response is a 3xx,
				// which probe_render() already treats as inconclusive.
				'redirection' => 0,
				// The check only needs to tell empty from non-empty and scan for the
				// fatal marker (which a WordPress fatal page emits near the top), so
				// bound the buffered body — a huge healthy page must not make the
				// write request download megabytes twice or exhaust PHP memory.
				'limit_response_size' => 256 * 1024,
				'headers'             => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
					'Range'         => 'bytes=0-262143',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 'inconclusive'; // transient failure — proves nothing
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return 'broken';
		}
		// Only a SUCCESSFUL (2xx) response is evidence of what Elementor rendered. A
		// 3xx/4xx (auth redirect, WAF 401/403, protected 404) is ambiguous — an
		// empty 403 body is NOT a WSOD.
		if ( $code < 200 || $code >= 300 ) {
			return 'inconclusive';
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return 'broken'; // white screen of death on a 200
		}
		// NB: we deliberately do NOT scan a 200 body for WordPress's "critical
		// error" sentence. A real PHP fatal is served by the fatal handler with a
		// 500 status (caught above); matching the sentence in a 200 body would
		// false-positive on legitimate page copy (e.g. docs about WP errors).
		return 'healthy';
	}

	/**
	 * Whether a URL is on this site's own front-end origin — scheme, host AND port
	 * must match (default ports normalized). Host-only would let a same-host but
	 * different-scheme/port permalink (e.g. http://host:8080 vs https://host) slip
	 * past the SSRF guard. Used to refuse probing an off-origin permalink.
	 *
	 * @param string $url The URL to check.
	 * @return bool
	 */
	private static function is_same_origin( string $url ): bool {
		$site   = wp_parse_url( home_url( '/' ) );
		$target = wp_parse_url( $url );
		if ( ! is_array( $site ) || ! is_array( $target ) ) {
			return false;
		}
		return self::origin_tuple( $site ) === self::origin_tuple( $target );
	}

	/**
	 * Normalize parsed-URL parts into a "scheme://host:port" origin string, filling
	 * the default port for http/https so an explicit :80 / :443 compares equal.
	 *
	 * @param array $parts Output of wp_parse_url().
	 * @return string
	 */
	private static function origin_tuple( array $parts ): string {
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return ''; // no host → cannot be same-origin
		}
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
		} elseif ( 'https' === $scheme ) {
			$port = 443;
		} elseif ( 'http' === $scheme ) {
			$port = 80;
		} else {
			$port = 0;
		}
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Verify the Ed25519 approval grant for a governed write against this exact
	 * tool + params. The grant is presented as the `X-Aura-Approval-Grant` request
	 * header (WP maps it to $_SERVER['HTTP_X_AURA_APPROVAL_GRANT']); SiteAgent's
	 * Aura_Worker_Grant::verify() checks the signature, tool/params/site binding,
	 * validity window and single-use nonce.
	 *
	 * @param string $name  Ability name (bound into the grant).
	 * @param mixed  $input The ability input (bound into the grant as params).
	 * @return true|\WP_Error
	 */
	private static function verify_grant( string $name, $input ) {
		// $_SERVER header values are not slash-escaped by WP (unlike GET/POST), and
		// a grant is base64url.base64url, so a plain string cast is sufficient.
		$header = isset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] ) ? (string) $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] : '';
		if ( '' === $header ) {
			return new \WP_Error(
				'governance_grant_required',
				sprintf(
					/* translators: %s: tool name */
					__( '%s requires an approval grant (X-Aura-Approval-Grant) but none was provided.', 'elementor-mcp' ),
					$name
				)
			);
		}

		// The gateway mints grants against the EXPOSED MCP tool name, which the
		// bundled adapter derives from the ability name by replacing "/" with "-"
		// (RegisterAbilityAsMcpTool::get_data(): str_replace('/', '-', ...)). Bind
		// verification to that same name, or every correctly minted grant is
		// rejected. e.g. "elementor-mcp/update-element" -> "elementor-mcp-update-element".
		$mcp_tool = str_replace( '/', '-', trim( $name ) );
		$params   = is_array( $input ) ? $input : array();
		$result   = \Aura_Worker_Grant::verify( $header, $mcp_tool, $params );
		if ( true !== $result ) {
			return new \WP_Error(
				'governance_grant_invalid',
				sprintf(
					/* translators: 1: tool name, 2: rejection reason */
					__( '%1$s approval grant rejected: %2$s', 'elementor-mcp' ),
					$name,
					is_string( $result ) ? $result : 'invalid grant'
				)
			);
		}
		return true;
	}

	/**
	 * Lazily create / return the shared snapshot-engine instance.
	 *
	 * @return \Aura_Worker_Snapshots
	 */
	private static function snapshots() {
		if ( null === self::$snapshots ) {
			self::$snapshots = new \Aura_Worker_Snapshots();
		}
		return self::$snapshots;
	}

	/**
	 * Clear all governed-run state. For test isolation.
	 *
	 * @return void
	 */
	public static function reset_state(): void {
		self::$run       = null;
		self::$snapshots = null;
	}
}
