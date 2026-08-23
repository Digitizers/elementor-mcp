<?php
/**
 * Functional — the operator-rules bridge (P4.1 plan 3). The fork never matches
 * a rule itself: it declares what a write touches and SiteAgent decides. These
 * tests pin the translation layer, with SiteAgent stubbed.
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

class RulesBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_rules'] = array( 'verdict' => array( 'effect' => null ), 'calls' => array(), 'current' => null, 'throw' => false );
		\Elementor_MCP_Rules::reset_state();
	}

	protected function tearDown(): void {
		\Elementor_MCP_Rules::reset_state();
		unset( $GLOBALS['_aura_rules'] );
		parent::tearDown();
	}

	private function block( string $key = 'rule/checkout', string $reason = 'launch day' ): array {
		return array( 'key' => $key, 'effect' => 'block', 'target' => array( 'type' => 'page', 'id' => '7' ), 'reason' => $reason );
	}

	public function test_a_page_write_declares_the_same_id_as_post_and_page(): void {
		// Spec §4: the operator does not know whether "checkout" is a page or a post.
		$this->assertSame(
			array( array( 'type' => 'post', 'id' => '7' ), array( 'type' => 'page', 'id' => '7' ) ),
			\Elementor_MCP_Rules::page_touches( 7 )
		);
		$this->assertSame( array( array( 'type' => 'site', 'id' => '*' ) ), \Elementor_MCP_Rules::site_touches() );
	}

	public function test_enforce_hands_the_declaration_to_siteagent_verbatim_and_returns_its_verdict(): void {
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'block', 'rule' => $this->block() );
		$verdict = \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::page_touches( 7 ), 'elementor-mcp/update-element' );
		$this->assertSame( 'block', $verdict['effect'] );
		$this->assertSame( 'rule/checkout', $verdict['rule']['key'] );
		$this->assertSame(
			array( array( 'touches' => \Elementor_MCP_Rules::page_touches( 7 ), 'name' => 'elementor-mcp/update-element' ) ),
			$GLOBALS['_aura_rules']['calls'],
			'The fork never re-implements match(): the exact declaration reaches SiteAgent.'
		);
	}

	public function test_without_siteagent_there_is_nothing_to_enforce(): void {
		\Elementor_MCP_Rules::reset_state( false ); // fork-only site
		$GLOBALS['_aura_rules']['verdict'] = array( 'effect' => 'block', 'rule' => $this->block() );
		$this->assertFalse( \Elementor_MCP_Rules::available() );
		$this->assertSame( array( 'effect' => null ), \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::page_touches( 7 ), 'x' ) );
		$this->assertSame( array(), $GLOBALS['_aura_rules']['calls'], 'Nothing is asked of a class that is not there.' );
	}

	public function test_a_matcher_that_throws_fails_closed_not_open(): void {
		$GLOBALS['_aura_rules']['throw'] = true;
		$verdict = \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::page_touches( 7 ), 'x' );
		$this->assertSame( 'unavailable', $verdict['effect'] );
		$this->assertStringContainsString( 'stub forced matcher failure', $verdict['error'] );
	}

	public function test_an_installed_engine_missing_enforce_fails_closed(): void {
		// A partial or broken SiteAgent update: the class is there, the method is
		// not. That is not "no SiteAgent" — it is a SiteAgent that cannot decide,
		// and a write under an undecidable verdict is refused.
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Without_Enforce::class );
		$this->assertTrue( \Elementor_MCP_Rules::available(), 'The class exists: this is not a fork-only site.' );
		$verdict = \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::site_touches(), 'x' );
		$this->assertSame( 'unavailable', $verdict['effect'] );
		$this->assertStringContainsString( 'enforce', $verdict['error'] );

		// Codex round 2: report() names WHY — nothing decides a write without
		// enforce(), so this is the one incomplete-reason that is NOT enforced.
		$report = \Elementor_MCP_Rules::report();
		$this->assertSame( 'incomplete', $report['state'] );
		$this->assertSame( 'enforce_missing', $report['reason'] );
		$this->assertFalse( $report['enforced'] );
		$this->assertSame( array(), $report['points'] );
	}

	public function test_an_installed_engine_missing_current_fails_closed_and_reports_itself(): void {
		// Controller ruling (reverting a Codex round 2 pass that briefly let
		// enforce() through here — see enforce()'s docblock): the plan's global
		// constraint requires BOTH enforce() and current() to exist. Missing
		// EITHER is a partial/broken SiteAgent update, refused the same way.
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Without_Current::class );
		$this->assertSame( 'unavailable', \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::site_touches(), 'x' )['effect'] );

		$report = \Elementor_MCP_Rules::report();
		$this->assertSame( 'siteagent', $report['source'] );
		$this->assertSame( 'incomplete', $report['state'] );
		// Codex round 2 (corrected): report() names WHICH method is missing —
		// current_missing is distinct from enforce_missing — but the gate
		// refuses either way, so `enforced` is false for both.
		$this->assertSame( 'current_missing', $report['reason'] );
		$this->assertFalse( $report['enforced'], 'An incomplete engine refuses writes; it does not "enforce" in the sense server-info promises.' );
		$this->assertSame( array(), $report['points'] );
		$this->assertNull( $report['ruleset'] );
	}

	/** @dataProvider garbage_verdicts */
	public function test_an_unrecognisable_verdict_fails_closed( $garbage ): void {
		$GLOBALS['_aura_rules']['verdict'] = $garbage;
		$this->assertSame( 'unavailable', \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::site_touches(), 'x' )['effect'] );
	}

	public static function garbage_verdicts(): array {
		return array(
			'string'            => array( 'block' ),
			'no effect key'     => array( array( 'rule' => array() ) ),
			'unknown effect'    => array( array( 'effect' => 'deny' ) ),
			'block without rule' => array( array( 'effect' => 'block' ) ),
		);
	}

	public function test_blocked_error_names_the_rule_and_says_approval_does_not_help(): void {
		$err = \Elementor_MCP_Rules::blocked_error( 'elementor-mcp/update-element', $this->block() );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'aura_rule_blocked', $err->get_error_code() );
		$this->assertStringContainsString( 'rule/checkout', $err->get_error_message() );
		$this->assertStringContainsString( 'launch day', $err->get_error_message() );
		$this->assertStringContainsString( 'approval does not override a rule', $err->get_error_message() );
		$this->assertSame( array( 'status' => 403, 'rule' => array( 'key' => 'rule/checkout', 'reason' => 'launch day' ) ), $err->get_error_data() );
	}

	public function test_unavailable_error_is_a_503_with_its_own_code(): void {
		$err = \Elementor_MCP_Rules::unavailable_error( 'x', 'boom' );
		$this->assertSame( 'aura_rules_unavailable', $err->get_error_code() );
		$this->assertSame( 503, $err->get_error_data()['status'] );
		$this->assertStringContainsString( 'boom', $err->get_error_message() );
	}

	public function test_warning_entry_is_rule_and_reason_only(): void {
		$this->assertSame( array( 'rule' => 'rule/checkout', 'reason' => 'launch day' ), \Elementor_MCP_Rules::warning_entry( $this->block() ) );
		$this->assertSame( array( 'rule' => 'rule/?', 'reason' => '' ), \Elementor_MCP_Rules::warning_entry( array() ) );
	}

	public function test_a_reader_that_throws_is_reported_as_incomplete_not_as_ready_with_no_ruleset(): void {
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Whose_Reader_Throws::class );
		$report = \Elementor_MCP_Rules::report();
		$this->assertSame( 'incomplete', $report['state'] );
		$this->assertStringContainsString( 'stub forced reader failure', $report['error'] );
		// Codex round 2: current() THROWING is the same reader-side failure as
		// current() being MISSING — enforce() on this fixture answers cleanly
		// ({ effect => null }), so writes are still decided by SiteAgent; only
		// this report's ruleset read failed.
		$this->assertSame( 'reader_failed', $report['reason'] );
		$this->assertTrue( $report['enforced'], 'enforce() still works on this fixture — only current() throws.' );
		$this->assertSame( array( 'governed_write' ), $report['points'] );
		$this->assertNull( $report['ruleset'] );
	}

	public function test_report_describes_an_outdated_siteagent_when_only_snapshots_is_present(): void {
		// Codex round 4: the test bootstrap always defines \Aura_Worker_Snapshots
		// (Elementor_MCP_Governance's soft dependency), so reset_state( false )
		// alone — i.e. \Aura_Worker_Rules absent, everything else default — is
		// exactly the "pre-2.10 SiteAgent" shape: the snapshot engine is there,
		// the rules engine is not. That is installed-but-outdated, not absent.
		\Elementor_MCP_Rules::reset_state( false );
		$this->assertSame(
			array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'outdated', 'ruleset' => null, 'points' => array() ),
			\Elementor_MCP_Rules::report()
		);
	}

	public function test_report_describes_siteagent_with_and_without_a_ruleset(): void {
		$this->assertSame(
			array( 'enforced' => true, 'source' => 'siteagent', 'state' => 'ready', 'ruleset' => null, 'points' => array( 'governed_write' ) ),
			\Elementor_MCP_Rules::report()
		);
		$GLOBALS['_aura_rules']['current'] = array( 'seq' => 4, 'client' => 'c1', 'received_at' => 1800000000, 'envelope' => 'SECRET.SIG', 'rules' => array( $this->block(), $this->block( 'rule/freeze' ) ) );
		$report = \Elementor_MCP_Rules::report();
		$this->assertSame( array( 'seq' => 4, 'rule_count' => 2, 'received_at' => 1800000000 ), $report['ruleset'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $report ), 'The envelope never leaves SiteAgent.' );
	}
}
