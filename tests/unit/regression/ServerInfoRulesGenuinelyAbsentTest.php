<?php
/**
 * Codex round 4 (fork final-review fix wave, PR #63): server-info's install
 * note ("SiteAgent is not installed... install SiteAgent 2.10.0 or later") is
 * reserved for a site with NEITHER SiteAgent class present. See
 * ServerInfoTest::test_server_info_reports_an_outdated_siteagent_when_only_snapshots_is_present
 * for the outdated case (\Aura_Worker_Snapshots present, \Aura_Worker_Rules
 * absent), which needs no seam — the test bootstrap already defines
 * \Aura_Worker_Snapshots by default.
 *
 * \Aura_Worker_Snapshots is defined unconditionally, at the top level of every
 * process's own run of tests/bootstrap.php, so ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS
 * (a new test-only seam in tests/bootstrap.php, mirroring the existing
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
	public function test_server_info_says_so_when_no_rules_can_be_enforced(): void {
		\Elementor_MCP_Rules::reset_state( false ); // \Aura_Worker_Rules absent too
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Rules::reset_state();

		$this->assertSame( array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() ), $report['rules'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'no operator rules' ); } ),
			'Spec §6: fork-only means no rules, and server-info reports that.'
		);
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString( 'predates 2.10.0', $note, 'Neither class present — this is not the outdated note.' );
		}
	}
}
