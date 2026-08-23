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
 * Codex round 5's rules_block() refactor surfaced something this fixture's
 * own setup makes true but the round-4 test never noticed: suppressing
 * \Aura_Worker_Snapshots makes Elementor_MCP_Governance::is_active() false
 * too (it keys on the SAME class) — so this exact combination ALWAYS also
 * has the governance wrapper not live. rules_block()'s branch (b)
 * (`! $governance_live`) is checked before branch (f) (`state === 'absent'`),
 * per the round-5 priority table, so this scenario now surfaces the NOT-LIVE
 * note ("...this plugin's own governance wrapper is not active...") instead
 * of the install note — even though that note's own wording ("SiteAgent
 * holds an operator ruleset, but...") is arguably imprecise for a site where
 * SiteAgent isn't installed at all. Flagged to the controller as a known,
 * cosmetic wording gap rather than silently reworded — this test asserts the
 * CURRENT, round-5-correct behavior.
 *
 * \Aura_Worker_Snapshots is defined unconditionally, at the top level of every
 * process's own run of tests/bootstrap.php, so ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS
 * (a test-only seam in tests/bootstrap.php, mirroring the existing
 * ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES) must be set in the PARENT process,
 * before @runInSeparateProcess forks this class's test — i.e. in
 * setUpBeforeClass(), which runs once, in the parent, before any of this
 * class's tests dispatch (setUp() would already be too late — it runs inside
 * the freshly forked child, after that child's own bootstrap has finished).
 * \Aura_Worker_Rules absence is simulated with the existing
 * Elementor_MCP_Rules::reset_state( false ) override instead of the sibling
 * env-var toggle — that override works in any process, isolated or not, so
 * only the Snapshots side needs the heavier seam here.
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
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS=1' ); // SiteAgent genuinely absent
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS' );
		parent::tearDownAfterClass();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_server_info_reports_absent_state_with_the_not_live_note_when_no_rules_can_be_enforced(): void {
		\Elementor_MCP_Rules::reset_state( false ); // \Aura_Worker_Rules absent too
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Rules::reset_state();

		// `state`/`source`/`ruleset`/`points` are untouched by rules_block()'s
		// branch (b) — only `enforced` (already false) and the note change.
		$this->assertSame( array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() ), $report['rules'] );
		// Codex round 5: this fixture's own suppression of \Aura_Worker_Snapshots
		// necessarily also makes the governance wrapper not live (see class
		// docblock) — branch (b) wins over branch (f)'s install note.
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'governance wrapper is not active' ); } )
		);
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString( 'predates 2.10.0', $note, 'Neither class present — this is not the outdated note.' );
			$this->assertStringNotContainsString( 'install SiteAgent', $note, 'Branch (b) pre-empts branch (f)\'s install note here — see class docblock.' );
		}
	}
}
