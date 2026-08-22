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
	}

	public function test_an_installed_engine_missing_current_fails_closed_and_reports_itself(): void {
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Without_Current::class );
		$this->assertSame( 'unavailable', \Elementor_MCP_Rules::enforce( \Elementor_MCP_Rules::site_touches(), 'x' )['effect'] );
		$report = \Elementor_MCP_Rules::report();
		$this->assertSame( 'siteagent', $report['source'] );
		$this->assertFalse( $report['enforced'], 'An incomplete engine refuses writes; it does not "enforce" in the sense server-info promises.' );
		$this->assertSame( 'incomplete', $report['state'] );
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
		$this->assertFalse( $report['enforced'] );
		$this->assertSame( 'incomplete', $report['state'] );
		$this->assertStringContainsString( 'stub forced reader failure', $report['error'] );
	}

	public function test_report_describes_a_fork_only_site(): void {
		\Elementor_MCP_Rules::reset_state( false );
		$this->assertSame(
			array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() ),
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
