<?php
/**
 * SiteAgent governance bridge for destructive Elementor writes.
 *
 * When the SiteAgent worker (digitizer-site-worker) is installed alongside this
 * plugin, every WRITE-capable ability (annotated readonly=false) that targets a
 * specific post is wrapped so the post's Elementor state is snapshotted BEFORE
 * the write and rolled back if the write fails. This gives agent-driven Elementor
 * edits the same capture-before-write safety SiteAgent already gives its own
 * power tools. (The gate is readonly=false rather than destructive=true because
 * many page mutators save _elementor_data while carrying destructive=false.)
 *
 * Soft dependency: when SiteAgent's snapshot engine (\Aura_Worker_Snapshots) is
 * NOT present, nothing is wrapped and behaviour is identical to the standalone
 * plugin. The plugin never hard-requires SiteAgent.
 *
 * Scope (1.17.0): page-targeting writes — those carrying a `post_id` — whose
 * Elementor state lives in the `_elementor_data` / `_elementor_page_settings`
 * post-meta. Kit- and repository-scoped writes (global classes, variables,
 * system kit) do not carry a post_id and pass through ungoverned here; they are
 * a later plank. Server-enforced approval grants and post-write render checks
 * are also later planks.
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
	 * Is SiteAgent's snapshot engine available to govern writes?
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( '\\Aura_Worker_Snapshots' );
	}

	/**
	 * Decorate a destructive, post-targeting ability with snapshot-before-write
	 * and rollback-on-failure.
	 *
	 * Returns $args unchanged when governance is inactive, the ability is not
	 * destructive, or it has no callable execute callback — so it is always safe
	 * to call for every ability at registration time.
	 *
	 * @param string $name Ability name.
	 * @param array  $args Ability args (as passed to wp_register_ability()).
	 * @return array The (possibly wrapped) args.
	 */
	public static function wrap_ability( string $name, array $args ): array {
		if ( ! self::is_active() ) {
			return $args;
		}

		// Govern any WRITE-capable ability (annotated readonly=false), not only
		// those flagged destructive: many page mutators (update-element,
		// batch-update, import-template, the atomic add/update tools) save
		// _elementor_data while carrying destructive=false, so a destructive-only
		// gate would leave the main edit paths blind. Wrapping is harmless for a
		// write tool that carries no post_id — run_governed() passes it through.
		$annotations = ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) )
			? $args['meta']['annotations']
			: null;
		$writes = ( null !== $annotations )
			&& array_key_exists( 'readonly', $annotations )
			&& false === $annotations['readonly'];
		if ( ! $writes ) {
			return $args; // read-only, or annotations we cannot classify → untouched
		}
		if ( empty( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input ) use ( $original, $name ) {
			return self::run_governed( $name, $original, $input );
		};
		return $args;
	}

	/**
	 * Snapshot the target page, run the real write, and roll back on failure.
	 *
	 * @param string   $name     Ability name (for error/audit context).
	 * @param callable $original The wrapped execute callback.
	 * @param mixed    $input    The ability input.
	 * @return mixed The original result, or a \WP_Error when the write could not
	 *               be made safe (no snapshot) or failed (rolled back).
	 */
	public static function run_governed( string $name, $original, $input ) {
		// absint() (not (int)) to match the write handlers, which normalize with
		// absint() before saving — otherwise a negative post_id would fail the
		// `<= 0` skip here yet still mutate abs(post_id) downstream, bypassing the
		// snapshot entirely for destructive tools whose schema allows negatives.
		$post_id = ( is_array( $input ) && isset( $input['post_id'] ) ) ? absint( $input['post_id'] ) : 0;

		// Only page-targeting writes carry a post_id. Kit/repository writes have
		// nothing to snapshot here — pass straight through.
		if ( $post_id <= 0 ) {
			return call_user_func( $original, $input );
		}

		$snapshots = new \Aura_Worker_Snapshots();
		$snap      = $snapshots->snapshot_meta( $post_id, self::PAGE_META_KEYS );
		if ( empty( $snap['success'] ) ) {
			// No rollback point — refuse the write rather than mutate blind.
			return new \WP_Error(
				'governance_snapshot_failed',
				sprintf(
					/* translators: 1: tool name, 2: reason */
					__( 'Refusing %1$s: could not snapshot the page before writing (%2$s).', 'elementor-mcp' ),
					$name,
					isset( $snap['error'] ) ? $snap['error'] : 'unknown error'
				)
			);
		}
		$snapshot_id = $snap['snapshot']['id'];

		// Run the real write. A thrown Throwable is treated like a failed write
		// (a partial write may already be on disk), so roll back and report.
		try {
			$result = call_user_func( $original, $input );
		} catch ( \Throwable $e ) {
			$snapshots->restore( $snapshot_id );
			return new \WP_Error(
				'governance_write_threw',
				sprintf(
					/* translators: 1: tool name, 2: error message */
					__( '%1$s failed and was rolled back: %2$s', 'elementor-mcp' ),
					$name,
					$e->getMessage()
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			// Failed write — return the page to its pre-write state.
			$restore = $snapshots->restore( $snapshot_id );

			/**
			 * Fires after a governed write failed and the page was rolled back.
			 *
			 * @param string   $name        Ability name.
			 * @param int      $post_id     Target post id.
			 * @param string   $snapshot_id Snapshot used for the rollback.
			 * @param \WP_Error $result     The write failure.
			 * @param array    $restore     Result of the restore attempt.
			 */
			do_action( 'elementor_mcp_governance_rolled_back', $name, $post_id, $snapshot_id, $result, $restore );
			return $result;
		}

		/**
		 * Fires after a successful governed write, exposing the rollback point so
		 * the gateway can offer an undo.
		 *
		 * @param string $name        Ability name.
		 * @param int    $post_id     Target post id.
		 * @param string $snapshot_id Snapshot captured before the write.
		 * @param mixed  $result      The write result.
		 */
		do_action( 'elementor_mcp_governance_write', $name, $post_id, $snapshot_id, $result );
		return $result;
	}
}
