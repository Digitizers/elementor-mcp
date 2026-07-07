<?php
/**
 * SiteAgent governance bridge for destructive Elementor writes.
 *
 * When the SiteAgent worker (digitizer-site-worker) is installed alongside this
 * plugin, an explicit allowlist of page-structure writers (GOVERNED_TOOLS) is
 * wrapped so the target page's Elementor state (`_elementor_data` /
 * `_elementor_page_settings`) is snapshotted BEFORE the write and rolled back if
 * the write fails. This gives agent-driven Elementor edits the same
 * capture-before-write safety SiteAgent already gives its own power tools.
 *
 * Why an explicit allowlist rather than an annotation heuristic: `post_id` +
 * `readonly=false` alone also matches tools that mutate OTHER state (template
 * conditions -> `_elementor_conditions`, SEO meta writers) or that are
 * preview-only unless an `apply` flag is set (a11y / SEO generators). Governing
 * those with a page-data snapshot would roll back the wrong keys or snapshot a
 * no-op preview. So governance is keyed to the known set of tools that write the
 * page element tree / page settings, and snapshots exactly those keys.
 *
 * Soft dependency: when SiteAgent's snapshot engine (\Aura_Worker_Snapshots) is
 * NOT present, nothing is wrapped and behaviour is identical to the standalone
 * plugin. The plugin never hard-requires SiteAgent.
 *
 * Scope (1.17.0): page-structure writers only. Template-conditions / SEO / a11y
 * apply-flag writers, kit- and repository-scoped writes (global classes,
 * variables, system kit), server-enforced approval grants, and post-write render
 * checks are later planks.
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
	 * Ability slugs whose write lands in the page element tree / page settings
	 * (`_elementor_data` / `_elementor_page_settings`) and are therefore safe to
	 * govern with a PAGE_META_KEYS snapshot. Read-only siblings (export-page,
	 * detect-elementor-version) and non-page-data writers (template conditions,
	 * SEO, a11y apply-flag tools, kit/repository tools) are deliberately absent.
	 * create-page carries no post_id, so it passes through at run time; it is
	 * listed for completeness.
	 *
	 * @var array<string,true>
	 */
	public const GOVERNED_TOOLS = array(
		'elementor-mcp/create-page'          => true,
		'elementor-mcp/update-page-settings' => true,
		'elementor-mcp/delete-page-content'  => true,
		'elementor-mcp/import-template'      => true,
		'elementor-mcp/add-container'        => true,
		'elementor-mcp/update-container'     => true,
		'elementor-mcp/update-element'       => true,
		'elementor-mcp/move-element'         => true,
		'elementor-mcp/remove-element'       => true,
		'elementor-mcp/reorder-elements'     => true,
		'elementor-mcp/duplicate-element'    => true,
		'elementor-mcp/batch-update'         => true,
		'elementor-mcp/add-atomic-widget'    => true,
		'elementor-mcp/update-atomic-widget' => true,
		'elementor-mcp/add-div-block'        => true,
		'elementor-mcp/add-flexbox'          => true,
		'elementor-mcp/build-page'           => true,
	);

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
		// Only the known page-structure writers are governed (see GOVERNED_TOOLS).
		if ( ! isset( self::GOVERNED_TOOLS[ $name ] ) ) {
			return $args;
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
			$restore = $snapshots->restore( $snapshot_id );
			if ( empty( $restore['success'] ) ) {
				return self::rollback_failed_error( $name, $post_id, $snapshot_id, $e->getMessage(), $restore );
			}
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
			if ( empty( $restore['success'] ) ) {
				// The rollback ITSELF failed: the page may be left partially
				// written. That is more severe than the original write error, so
				// surface it rather than masking it behind the write failure.
				return self::rollback_failed_error( $name, $post_id, $snapshot_id, $result->get_error_message(), $restore );
			}

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

	/**
	 * Build the error returned when a write failed AND its rollback also failed —
	 * the page may be left in a partially written state, so the caller/gateway
	 * must be told the restore did not succeed (not just the original failure).
	 *
	 * @param string $name         Ability name.
	 * @param int    $post_id      Target post id.
	 * @param string $snapshot_id  Snapshot that could not be restored.
	 * @param string $write_error  The original write error message.
	 * @param array  $restore      Result of the failed restore attempt.
	 * @return \WP_Error
	 */
	private static function rollback_failed_error( string $name, int $post_id, string $snapshot_id, string $write_error, array $restore ): \WP_Error {
		/**
		 * Fires when a governed write failed and the rollback ALSO failed.
		 *
		 * @param string $name        Ability name.
		 * @param int    $post_id     Target post id.
		 * @param string $snapshot_id Snapshot that could not be restored.
		 * @param string $write_error Original write error message.
		 * @param array  $restore     Failed restore result.
		 */
		do_action( 'elementor_mcp_governance_rollback_failed', $name, $post_id, $snapshot_id, $write_error, $restore );

		return new \WP_Error(
			'governance_rollback_failed',
			sprintf(
				/* translators: 1: tool name, 2: write error, 3: restore error, 4: post id, 5: snapshot id */
				__( '%1$s failed (%2$s) AND rollback failed (%3$s); page %4$d may be partially written. Snapshot %5$s must be restored manually.', 'elementor-mcp' ),
				$name,
				$write_error,
				isset( $restore['error'] ) ? $restore['error'] : 'unknown error',
				$post_id,
				$snapshot_id
			)
		);
	}
}
