<?php
/**
 * Companion to ServerInfoRulesBridgeMissingTest: the same server-info
 * rules_block() branch (a) — `! $bridge_loaded` — but with BOTH SiteAgent
 * classes genuinely absent too (Codex round 5 added the \Aura_Worker_Snapshots
 * seam this test needed; without it, this fixture was — despite its name —
 * only ever simulating "our bridge missing, \Aura_Worker_Rules missing,
 * \Aura_Worker_Snapshots STILL PRESENT", which is round 5's own finding: a
 * pre-2.10 SiteAgent with the bridge also missing must report
 * `bridge_missing`, not `absent`. See ServerInfoRulesStateTest for that
 * corrected combination, tested directly against the pure function). With
 * all three classes suppressed, this fixture is now truly "SiteAgent never
 * installed" — the ORIGINAL "SiteAgent is not installed" install note
 * applies, via rules_block()'s branch (a), which does not consult governance
 * liveness at all (unlike branches (b) onward) — so this scenario is NOT
 * subject to the branch-(b)-vs-(f) precedence question ServerInfoRulesGenuinelyAbsentTest
 * documents for the bridge-loaded-but-report()-says-absent case.
 *
 * \Aura_Worker_Rules and \Aura_Worker_Snapshots are both stubbed
 * unconditionally, at the top level of every process's run of
 * tests/bootstrap.php — not behind the lazy autoloader — so unlike
 * Elementor_MCP_Rules, their env-var seams MUST be set before that child
 * process's own bootstrap.php runs, i.e. in the PARENT process, before
 * @runInSeparateProcess forks the child for this class's test.
 * setUpBeforeClass() runs once, in the parent, before any of this class's
 * tests are dispatched — setUp() would already be too late (it runs inside
 * the child, after that child's bootstrap has finished).
 *
 * A dedicated file, not a second class alongside ServerInfoRulesBridgeMissingTest:
 * PHPUnit's directory-based test-suite discovery in this version only picks up
 * the one TestCase subclass per file whose name matches the filename
 * convention (confirmed empirically).
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class ServerInfoRulesBridgeAndSiteAgentMissingTest extends Ability_Test_Case {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		putenv( 'ELEMENTOR_MCP_TEST_NO_RULES_BRIDGE=1' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES=1' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS=1' ); // SiteAgent genuinely absent — neither class
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ELEMENTOR_MCP_TEST_NO_RULES_BRIDGE' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS' );
		parent::tearDownAfterClass();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_server_info_keeps_the_absent_wording_when_siteagent_is_genuinely_absent_too(): void {
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 'absent', $report['rules']['state'] );
		$this->assertFalse( $report['rules']['enforced'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'SiteAgent is not installed' ); } )
		);
	}
}
