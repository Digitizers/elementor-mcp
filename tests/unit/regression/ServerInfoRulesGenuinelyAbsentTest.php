<?php
/**
 * Codex round 4 (fork final-review fix wave, PR #63) originally added this
 * fixture to prove server-info's install note ("SiteAgent is not
 * installed... install SiteAgent 2.10.0 or later") for a site with NEITHER
 * SiteAgent class present, reached via Elementor_MCP_Rules::report()'s own
 * `state: 'absent'` (this plugin's bridge class IS loaded here — only
 * SiteAgent's two classes are suppressed — see
 * ServerInfoRulesBridgeAndSiteAgentMissingTest for the sibling scenario where
 * the BRIDGE itself is also missing).
 *
 * Codex round 5's rules_block() refactor briefly regressed this exact
 * scenario: suppressing \Aura_Worker_Snapshots makes
 * Elementor_MCP_Governance::is_active() false too (it keys on the SAME
 * class), so this fixture ALWAYS also has the governance wrapper not live —
 * and an early pass of rules_block() checked governance liveness BEFORE
 * absence, so this scenario briefly surfaced the not-live note ("SiteAgent
 * holds an operator ruleset, but this plugin's own governance wrapper is not
 * active...") instead of the install note, which is actively wrong here:
 * SiteAgent was never installed, so it never "held an operator ruleset" to
 * begin with. Controller ruling corrected rules_block()'s priority order:
 * absence (neither SiteAgent class present, checked from the input booleans
 * directly) now outranks governance liveness — a site with no SiteAgent at
 * all reads as `absent` with the install note, never told its (nonexistent)
 * wrapper "is not active". This test asserts that corrected behavior, which
 * is also the ORIGINAL round-4 behavior this fixture was written to prove.
 *
 * \Aura_Worker_Snapshots AND \Aura_Worker_Rules are both defined
 * unconditionally, at the top level of every process's own run of
 * tests/bootstrap.php, so both env-var seams (ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS,
 * ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES) must be set in the PARENT process,
 * before @runInSeparateProcess forks this class's test — i.e. in
 * setUpBeforeClass(), which runs once, in the parent, before any of this
 * class's tests dispatch (setUp() would already be too late — it runs inside
 * the freshly forked child, after that child's own bootstrap has finished).
 * Earlier revisions of this test used Elementor_MCP_Rules::reset_state( false )
 * (an override of Elementor_MCP_Rules::available() only) as a shortcut for
 * "\Aura_Worker_Rules absent" instead of the env-var seam — adequate while
 * server-info's assembly only ever consulted Elementor_MCP_Rules::report(),
 * but NOT once rules_block() started reading class_exists( '\Aura_Worker_Rules' )
 * directly too (Codex round 5): the override left that class genuinely still
 * defined, so rules_block() saw SiteAgent as present (via \Aura_Worker_Rules)
 * and skipped branch (b) — surfacing the not-live note instead of the
 * install note, exactly the wrong-note bug this fixture was fixed to fix.
 * Both classes are now genuinely undefined, so no override is needed either.
 *
 * A dedicated file (not a second class in ServerInfoTest.php): PHPUnit's
 * directory-based test-suite discovery in this version only picks up the one
 * TestCase subclass per file whose name matches the filename convention
 * (confirmed empirically in the Codex round 2 pass of this same fix wave).
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class ServerInfoRulesGenuinelyAbsentTest extends Ability_Test_Case {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS=1' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES=1' ); // SiteAgent genuinely absent — neither class
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES' );
		parent::tearDownAfterClass();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_server_info_says_so_when_no_rules_can_be_enforced(): void {
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() ), $report['rules'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'no operator rules' ); } ),
			'Spec §6: fork-only means no rules, and server-info reports that.'
		);
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString( 'predates 2.10.0', $note, 'Neither class present — this is not the outdated note.' );
			// Codex round 5 controller correction: absence outranks governance
			// liveness in rules_block(), so this scenario — which necessarily
			// also has the governance wrapper not live, since is_active() keys
			// on the same \Aura_Worker_Snapshots class this fixture suppresses
			// — must NOT surface the not-live note instead of the install one.
			$this->assertStringNotContainsString( 'governance wrapper is not active', $note, 'Absence outranks liveness — SiteAgent never held a ruleset here.' );
		}
	}
}
