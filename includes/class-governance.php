<?php
/**
 * SiteAgent governance bridge for Elementor page writes.
 *
 * When the SiteAgent worker (digitizer-site-worker) is installed alongside this
 * plugin, a write-capable ability that edits an existing page has the page's
 * Elementor state (`_elementor_data` / `_elementor_page_settings`) snapshotted
 * BEFORE the write and rolled back if the write fails — the same
 * capture-before-write safety SiteAgent gives its own power tools.
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

		$original                 = $args['execute_callback'];
		$args['execute_callback'] = static function ( $input ) use ( $original, $name ) {
			return self::run_governed( $name, $original, $input );
		};
		return $args;
	}

	/**
	 * Run a write-capable ability under governance: arm the run, execute, and (if
	 * the tool actually captured a page snapshot) roll back on failure.
	 *
	 * @param string   $name     Ability name (for error/audit context).
	 * @param callable $original The wrapped execute callback.
	 * @param mixed    $input    The ability input.
	 * @return mixed The original result, or a \WP_Error when a failed write was
	 *               rolled back (or when the rollback itself failed).
	 */
	public static function run_governed( string $name, $original, $input ) {
		// absint() (not (int)) to match the write handlers, which normalize with
		// absint() before saving — otherwise a negative post_id would arm a
		// different post than the one actually mutated downstream.
		$post_id = ( is_array( $input ) && isset( $input['post_id'] ) ) ? absint( $input['post_id'] ) : 0;

		// No specific existing page to protect (e.g. create-page, or a kit/repo
		// tool) → run ungoverned. A snapshot needs a target post that exists.
		if ( $post_id <= 0 ) {
			return call_user_func( $original, $input );
		}

		self::$run = array(
			'post_id'         => $post_id,
			'name'            => $name,
			'snapshot_id'     => null,
			'snapshot_failed' => false,
		);

		try {
			$result = call_user_func( $original, $input );
		} catch ( \Throwable $e ) {
			$out = self::finish_failure( $name, $post_id, $e->getMessage() );
			self::$run = null;
			return $out;
		}

		if ( is_wp_error( $result ) ) {
			$out       = self::finish_failure( $name, $post_id, $result->get_error_message(), $result );
			self::$run = null;
			return $out;
		}

		// Success. If the tool captured a snapshot (i.e. it actually wrote page
		// data), expose the rollback point so the gateway can offer an undo.
		if ( null !== self::$run['snapshot_id'] ) {
			do_action( 'elementor_mcp_governance_write', $name, $post_id, self::$run['snapshot_id'], $result );
		}
		self::$run = null;
		return $result;
	}

	/**
	 * Capture the page snapshot on the first real page-data write of a governed
	 * run. Called by Elementor_MCP_Data::save_page_data() / save_page_settings()
	 * BEFORE they persist anything.
	 *
	 * A cheap no-op unless a governed run for THIS post is in flight and no
	 * snapshot has been taken yet. Returns a \WP_Error when the snapshot cannot be
	 * captured, so the caller can fail closed (refuse the write rather than mutate
	 * without a rollback point); returns null otherwise.
	 *
	 * @param int $post_id The post about to be written.
	 * @return \WP_Error|null
	 */
	public static function before_page_write( $post_id ) {
		if ( null === self::$run || ! self::is_active() ) {
			return null; // no governed run in flight
		}
		if ( self::$run['post_id'] !== absint( $post_id ) ) {
			return null; // a write to some other post (unexpected) — do not touch
		}
		if ( null !== self::$run['snapshot_id'] || self::$run['snapshot_failed'] ) {
			return null; // already snapshotted (or already failed) this run
		}

		$snap = self::snapshots()->snapshot_meta( absint( $post_id ), self::PAGE_META_KEYS );
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
			// The rollback ITSELF failed: the page may be left partially written.
			// That is more severe than the original write error — surface it.
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
