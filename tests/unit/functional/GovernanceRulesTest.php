<?php
/**
 * Functional — operator rules inside a governed run (P4.1 plan 3). Order is
 * grant → RULES → snapshot → write: a block executes nothing and snapshots
 * nothing; a warn proceeds and is reported in the body; a preview is exempt;
 * a fork-only site enforces nothing; a broken matcher refuses.
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

class GovernanceRulesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_snap']  = array( 'fail_snapshot' => false, 'fail_restore' => false, 'snapshot_calls' => array(), 'restore_calls' => array(), 'seq' => 0 );
		$GLOBALS['_aura_grant'] = array( 'enforced' => false, 'verify_result' => true, 'verify_calls' => array() );
		$GLOBALS['_aura_rules'] = array( 'verdict' => array( 'effect' => null ), 'calls' => array(), 'current' => null, 'throw' => false );
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']   = false;
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'],
			$GLOBALS['_active_kit'],
			$GLOBALS['_aura_rules'],
			// Only the render-revert-carries-warnings test below sets these; unset
			// here (as GovernanceFunctionalTest does) so a future test added to
			// this file never inherits a stale post/HTTP fixture.
			$GLOBALS['_posts'],
			$GLOBALS['_http_response_queue'],
			$GLOBALS['_http_response'],
			$GLOBALS['_permalink']
		);
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
		parent::tearDown();
	}

	private function block( string $key = 'rule/checkout', string $reason = 'launch day' ): array {
		return array( 'key' => $key, 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => $reason );
	}

	private function warn( string $key = 'rule/careful', string $reason = 'client reviewing' ): array {
		return array( 'key' => $key, 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => $reason );
	}

	/** A verdict that blocks only when the declaration contains the given touch. */
	private function block_on( string $type, string $id, array $rule ): void {
		$GLOBALS['_aura_rules']['verdict'] = static function ( array $touches ) use ( $type, $id, $rule ) {
			foreach ( $touches as $t ) {
				if ( $t['type'] === $type && $t['id'] === $id ) {
					return array( 'effect' => 'block', 'rule' => $rule );
				}
			}
			return array( 'effect' => null );
		};
	}

	private function write_args( $callback, array $meta = array() ): array {
		return array(
			'label'            => 'Update element',
			'execute_callback' => $callback,
			'meta'             => array_merge( array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) ), $meta ),
		);
	}

	/** Like Elementor_MCP_Data::save_page_data(): gate, then write. Records whether it ran. */
	private function page_writer( $return = array( 'ok' => true ) ): callable {
		return static function ( $input ) use ( $return ) {
			$GLOBALS['_writer_ran'] = true;
			$gate = \Elementor_MCP_Governance::before_page_write( $input['post_id'] ?? 4242 );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$GLOBALS['_writer_wrote'] = true;
			return $return;
		};
	}

	private function invoke( array $args, array $input ) {
		$GLOBALS['_writer_ran']   = false;
		$GLOBALS['_writer_wrote'] = false;
		$wrapped = \Elementor_MCP_Governance::wrap_ability( 'elementor-mcp/update-element', $args );
		return call_user_func( $wrapped['execute_callback'], $input );
	}

	private function declared(): array {
		return array_map( static function ( $c ) { return $c['touches']; }, $GLOBALS['_aura_rules']['calls'] );
	}

	// --- order: rules before the callback, before the snapshot ---------------

	public function test_an_edit_blocked_by_a_page_rule_never_runs_the_callback_and_never_snapshots(): void {
		$this->block_on( 'page', '7', $this->block() );
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertFalse( $GLOBALS['_writer_ran'], 'Nothing executed.' );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'], 'No snapshot created.' );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['restore_calls'], 'Nothing to roll back.' );
		$this->assertSame( array( 'status' => 403, 'rule' => array( 'key' => 'rule/checkout', 'reason' => 'launch day' ) ), $res->get_error_data() );
	}

	public function test_an_edit_declares_the_input_post_as_post_and_page_before_the_callback(): void {
		$this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );
		$this->assertSame( \Elementor_MCP_Rules::page_touches( 7 ), $this->declared()[0] );
	}

	public function test_a_create_declares_the_whole_site_before_the_callback_and_the_new_id_at_the_write(): void {
		// No post_id in the input: the post does not exist yet, so a freeze is the
		// only rule that can apply before the insert (spec §6: "for a create,
		// site:*"). Once the writer learns the id, the page rule is asked too.
		$this->invoke( $this->write_args( $this->page_writer() ), array( 'title' => 'New' ) );
		$declared = $this->declared();
		$this->assertSame( \Elementor_MCP_Rules::site_touches(), $declared[0] );
		$this->assertSame( \Elementor_MCP_Rules::page_touches( 4242 ), $declared[1], 'The write site knows the real id.' );
	}

	public function test_a_create_blocked_by_a_site_freeze_inserts_nothing(): void {
		$this->block_on( 'site', '*', $this->block( 'rule/freeze', 'deploy night' ) );
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'title' => 'New' ) );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertFalse( $GLOBALS['_writer_ran'], 'A create must be refused BEFORE it can insert a draft.' );
	}

	public function test_a_page_rule_for_an_id_the_input_did_not_name_is_caught_at_the_write_site_before_the_snapshot(): void {
		// The tool's input named no post (it resolves the page internally); the
		// write site declares the real id and the block lands there — before the
		// snapshot, so the page is untouched and nothing is rolled back.
		$this->block_on( 'page', '4242', $this->block( 'rule/landing' ) );
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'slug' => 'landing' ) );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertTrue( $GLOBALS['_writer_ran'] );
		$this->assertFalse( $GLOBALS['_writer_wrote'], 'The gate refused before the write.' );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'], 'Rules decide BEFORE the snapshot.' );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['restore_calls'] );
	}

	// --- design-system writes --------------------------------------------------

	public function test_a_kit_write_declares_the_whole_site_and_a_freeze_blocks_it_before_the_snapshot(): void {
		$GLOBALS['_active_kit'] = new class() { public function get_id() { return 99; } };
		$this->block_on( 'site', '*', $this->block( 'rule/freeze' ) );
		$writer = static function ( $input ) {
			$gate = \Elementor_MCP_Governance::before_kit_write();
			return is_wp_error( $gate ) ? $gate : array( 'ok' => true );
		};
		$res = $this->invoke( $this->write_args( $writer, array( 'governance' => array( 'scope' => 'kit' ) ) ), array( 'colors' => array() ) );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'] );
		foreach ( $this->declared() as $touches ) {
			$this->assertSame( \Elementor_MCP_Rules::site_touches(), $touches, 'A design-token write has no narrower resource than the site.' );
		}
	}

	public function test_a_global_classes_write_declares_the_whole_site(): void {
		$this->block_on( 'site', '*', $this->block( 'rule/freeze' ) );
		$writer = static function ( $input ) {
			$gate = \Elementor_MCP_Governance::before_global_classes_write( 99, array( 5 ), array( 7 ) );
			return is_wp_error( $gate ) ? $gate : array( 'ok' => true );
		};
		$res = $this->invoke( $this->write_args( $writer, array( 'governance' => array( 'scope' => 'global-classes' ) ) ), array( 'label' => 'hero' ) );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'] );
		$this->assertSame( \Elementor_MCP_Rules::site_touches(), $this->declared()[0] );
	}

	// --- warn ---------------------------------------------------------------------

	public function test_a_warn_proceeds_and_the_result_carries_the_warning_once(): void {
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'warn', 'rule' => $this->warn() );
		$res = $this->invoke( $this->write_args( $this->page_writer( array( 'ok' => true ) ) ), array( 'post_id' => 7 ) );
		$this->assertTrue( $GLOBALS['_writer_wrote'] );
		$this->assertTrue( $res['ok'] );
		// Asked twice (pre-callback and at the write site) for the same rule; told once.
		$this->assertSame( array( array( 'rule' => 'rule/careful', 'reason' => 'client reviewing' ) ), $res['warnings'] );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'], 'A warn is still a governed write: snapshotted as usual.' );
	}

	public function test_a_warn_survives_a_write_that_then_fails(): void {
		// The warn was decided before the write; the write failed and was rolled
		// back. The caller still learns the rule — on this fork-owned path there
		// is no header channel to fall back on, so the error carries it.
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'warn', 'rule' => $this->warn() );
		$res = $this->invoke( $this->write_args( $this->page_writer( new \WP_Error( 'elementor_save_failed', 'disk full' ) ) ), array( 'post_id' => 7 ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'elementor_save_failed', $res->get_error_code(), 'The original error is returned verbatim …' );
		$this->assertSame( array( array( 'rule' => 'rule/careful', 'reason' => 'client reviewing' ) ), $res->get_error_data()['warnings'], '… carrying the warning.' );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['restore_calls'], 'Governance still rolled the failed write back.' );
	}

	public function test_a_warn_survives_a_write_that_then_fails_and_the_message_names_it_too(): void {
		// Codex round 1 (controller ruling, PR review of the fix wave above): the
		// warn used to land ONLY in the error's data — the bundled MCP adapter's
		// ToolsHandler converts a WP_Error using just get_error_message() +
		// get_error_code(), silently dropping data-only warnings on that
		// transport. The message must carry it too, alongside the unchanged
		// structured data entry every existing caller already reads.
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'warn', 'rule' => $this->warn() );
		$res = $this->invoke( $this->write_args( $this->page_writer( new \WP_Error( 'elementor_save_failed', 'disk full' ) ) ), array( 'post_id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'elementor_save_failed', $res->get_error_code() );
		$this->assertStringContainsString( 'disk full', $res->get_error_message(), 'The original message is kept …' );
		$this->assertStringContainsString( 'rule/careful (client reviewing)', $res->get_error_message(), '… with the rule key and reason appended.' );
		$this->assertSame(
			array( array( 'rule' => 'rule/careful', 'reason' => 'client reviewing' ) ),
			$res->get_error_data()['warnings'],
			'The structured data entry is unchanged.'
		);
	}

	public function test_a_warn_survives_a_render_revert_that_also_fails_to_restore(): void {
		// Controller ruling (Task 2): the render-check branch's rollback_failed_error
		// exit is still an OUTCOME of a governed run — the global constraint ("warn
		// proceeds and is reported once, on every outcome") outranks the brief's
		// four-point enumeration of exits, so this one must carry warnings too.
		// The warn was decided before the write; the write then broke the page's
		// render, and even the revert-to-snapshot failed — the worst outcome a
		// governed run can produce. The caller must still learn the rule.
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'warn', 'rule' => $this->warn() );
		$GLOBALS['_posts'][7]              = (object) array( 'ID' => 7, 'post_status' => 'publish' );
		$GLOBALS['_emcp_render_check']      = true;
		$GLOBALS['_http_response_queue']    = array(
			array( 'response' => array( 'code' => 200 ), 'body' => 'ok' ), // baseline: healthy
			array( 'response' => array( 'code' => 500 ), 'body' => '' ),   // post-write: broken
		);
		$GLOBALS['_aura_snap']['fail_restore'] = true;

		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'governance_rollback_failed', $res->get_error_code() );
		$this->assertSame( array( array( 'rule' => 'rule/careful', 'reason' => 'client reviewing' ) ), $res->get_error_data()['warnings'] );
	}

	public function test_a_warn_on_a_scalar_result_wraps_it_rather_than_losing_the_warning(): void {
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'warn', 'rule' => $this->warn() );
		$res = $this->invoke( $this->write_args( $this->page_writer( 'done' ) ), array( 'post_id' => 7 ) );
		$this->assertSame( array( 'value' => 'done', 'warnings' => array( array( 'rule' => 'rule/careful', 'reason' => 'client reviewing' ) ) ), $res );
	}

	public function test_without_a_warn_results_are_returned_untouched(): void {
		$this->assertSame( 'done', $this->invoke( $this->write_args( $this->page_writer( 'done' ) ), array( 'post_id' => 7 ) ) );
		$err = $this->invoke( $this->write_args( $this->page_writer( new \WP_Error( 'x', 'y' ) ) ), array( 'post_id' => 7 ) );
		$this->assertSame( array(), (array) $err->get_error_data() );
		// Codex round 1 regression guard: the message-suffix fix above only
		// triggers when there IS a warning — no warning means the message must
		// stay byte-for-byte what the write site produced.
		$this->assertSame( 'y', $err->get_error_message(), 'No warnings — the message is untouched.' );
	}

	// --- mixed outcomes across the two gate calls (Recommendation 3) ----------

	public function test_unavailable_raised_only_at_the_write_gate_still_refuses_and_snapshots_nothing(): void {
		// The pre-callback gate (call #1: site/page for the input's post_id)
		// answers cleanly; the write-site gate (call #2, before_page_write) gets
		// back garbage — a matcher that is broken only sometimes, not a matcher
		// that always throws (already covered above). Still fails closed.
		$GLOBALS['_aura_rules']['verdict'] = static function () {
			return 2 === count( $GLOBALS['_aura_rules']['calls'] ) ? 'garbage' : array( 'effect' => null );
		};
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'aura_rules_unavailable', $res->get_error_code() );
		$this->assertTrue( $GLOBALS['_writer_ran'] );
		$this->assertFalse( $GLOBALS['_writer_wrote'], 'Nothing was written.' );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'], 'No snapshot — the write-gate refused before it.' );
	}

	public function test_a_warn_at_the_pre_callback_then_a_block_at_the_write_gate_carries_both(): void {
		// The pre-callback gate (call #1) warns; the write-site gate (call #2,
		// same post) then blocks. The refusal must not lose the earlier warn —
		// the error data carries the deciding `rule` AND the run's `warnings`.
		$warn_rule  = $this->warn();
		$block_rule = $this->block();
		$GLOBALS['_aura_rules']['verdict'] = static function () use ( $warn_rule, $block_rule ) {
			return 1 === count( $GLOBALS['_aura_rules']['calls'] )
				? array( 'effect' => 'warn', 'rule' => $warn_rule )
				: array( 'effect' => 'block', 'rule' => $block_rule );
		};
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$data = $res->get_error_data();
		$this->assertSame( array( 'key' => 'rule/checkout', 'reason' => 'launch day' ), $data['rule'] );
		$this->assertSame( array( array( 'rule' => 'rule/careful', 'reason' => 'client reviewing' ) ), $data['warnings'] );
		$this->assertFalse( $GLOBALS['_writer_wrote'], 'Nothing was written.' );
	}

	// --- exemptions -----------------------------------------------------------

	public function test_a_preview_is_never_rules_checked(): void {
		$this->block_on( 'page', '7', $this->block() );
		$args = $this->write_args( static function ( $input ) { return array( 'preview' => true ); } );
		$args['input_schema'] = array( 'properties' => array( 'apply' => array( 'type' => 'boolean' ) ) );
		$res = $this->invoke( $args, array( 'post_id' => 7, 'apply' => false ) );
		$this->assertSame( array( 'preview' => true ), $res );
		$this->assertSame( array(), $GLOBALS['_aura_rules']['calls'], 'A dry run writes nothing; the rule blocks execution, not sight.' );
	}

	public function test_the_same_tool_with_apply_true_is_checked(): void {
		$this->block_on( 'page', '7', $this->block() );
		$args = $this->write_args( $this->page_writer() );
		$args['input_schema'] = array( 'properties' => array( 'apply' => array( 'type' => 'boolean' ) ) );
		$res = $this->invoke( $args, array( 'post_id' => 7, 'apply' => true ) );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
	}

	public function test_a_fork_only_site_enforces_nothing(): void {
		\Elementor_MCP_Rules::reset_state( false );
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'block', 'rule' => $this->block() );
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );
		$this->assertSame( array( 'ok' => true ), $res );
		$this->assertSame( array(), $GLOBALS['_aura_rules']['calls'] );
	}

	// --- a rule outranks a grant ------------------------------------------------

	public function test_a_valid_grant_does_not_override_a_block(): void {
		$GLOBALS['_aura_grant']['enforced'] = true;
		$GLOBALS['_emcp_require_grants']    = true;
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = 'valid-grant';
		$this->block_on( 'page', '7', $this->block() );
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertCount( 1, $GLOBALS['_aura_grant']['verify_calls'], 'The grant was checked first (it is cheaper to explain) …' );
		$this->assertStringContainsString( 'approval does not override a rule', $res->get_error_message(), '… and the rule still refused.' );
		$this->assertFalse( $GLOBALS['_writer_ran'] );
	}

	public function test_a_rejected_grant_is_reported_before_any_rule_is_asked(): void {
		$GLOBALS['_aura_grant']['enforced']      = true;
		$GLOBALS['_aura_grant']['verify_result'] = 'grant expired';
		$GLOBALS['_emcp_require_grants']         = true;
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT']   = 'stale';
		$this->block_on( 'page', '7', $this->block() );
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );
		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertNotSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_aura_rules']['calls'], 'Spec §6: rules last — no point saying "blocked by rule" to a call that fails its grant.' );
	}

	// --- every distinct post the run writes is gated, not just the first ---------

	public function test_a_run_that_writes_two_posts_still_gates_the_second_after_the_first_snapshots(): void {
		// Bug (Important #3): the "already snapshotted" early return sat ABOVE the
		// rules gate, so a run that already snapshotted post 7 sailed straight past
		// a block on post 99 written later in the same run.
		$this->block_on( 'page', '99', $this->block( 'rule/checkout' ) );
		$writer = static function ( $input ) {
			$first = \Elementor_MCP_Governance::before_page_write( 7 );
			if ( is_wp_error( $first ) ) {
				return $first;
			}
			$GLOBALS['_writer_wrote_7'] = true;
			$second = \Elementor_MCP_Governance::before_page_write( 99 );
			if ( is_wp_error( $second ) ) {
				return $second;
			}
			$GLOBALS['_writer_wrote_99'] = true;
			return array( 'ok' => true );
		};
		$res = $this->invoke( $this->write_args( $writer ), array( 'title' => 'Bulk' ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'aura_rule_blocked', $res->get_error_code() );
		$this->assertTrue( $GLOBALS['_writer_wrote_7'], 'The first post write succeeded — it was never blocked.' );
		$this->assertArrayNotHasKey( '_writer_wrote_99', $GLOBALS, 'The second write never happened.' );
		$this->assertCount( 1, $GLOBALS['_aura_snap']['snapshot_calls'], 'Only post 7 was ever snapshotted.' );
	}

	public function test_a_run_that_writes_the_same_post_twice_asks_enforce_once_for_it(): void {
		$writer = static function ( $input ) {
			$first = \Elementor_MCP_Governance::before_page_write( 7 );
			if ( is_wp_error( $first ) ) {
				return $first;
			}
			$second = \Elementor_MCP_Governance::before_page_write( 7 );
			if ( is_wp_error( $second ) ) {
				return $second;
			}
			return array( 'ok' => true );
		};
		$res = $this->invoke( $this->write_args( $writer ), array( 'post_id' => 7 ) );

		$this->assertSame( array( 'ok' => true ), $res );
		// One enforce() call from the pre-callback gate (run_governed) and one from
		// the write site's FIRST before_page_write(7) — the second before_page_write
		// call for the same post 7 must add no further call.
		$this->assertCount( 2, $this->declared(), 'The write site asked enforce() once for post 7, not twice.' );
	}

	// --- broken matcher -----------------------------------------------------------

	public function test_a_matcher_that_throws_refuses_the_write_with_its_own_code(): void {
		$GLOBALS['_aura_rules']['throw'] = true;
		$res = $this->invoke( $this->write_args( $this->page_writer() ), array( 'post_id' => 7 ) );
		$this->assertSame( 'aura_rules_unavailable', $res->get_error_code() );
		$this->assertFalse( $GLOBALS['_writer_ran'] );
		$this->assertSame( array(), $GLOBALS['_aura_snap']['snapshot_calls'] );
	}
}
