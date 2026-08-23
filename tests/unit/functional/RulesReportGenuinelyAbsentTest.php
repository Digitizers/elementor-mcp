<?php
/**
 * Codex round 4 (fork final-review fix wave, PR #63): Elementor_MCP_Rules::report()
 * distinguishes a genuinely fork-only site (NEITHER \Aura_Worker_Snapshots nor
 * \Aura_Worker_Rules present — SiteAgent never installed) from an OUTDATED
 * SiteAgent (\Aura_Worker_Snapshots present, \Aura_Worker_Rules absent — a
 * pre-2.10 install). See RulesBridgeTest::test_report_describes_an_outdated_siteagent_when_only_snapshots_is_present
 * for the outdated case, which needs no seam (the test bootstrap already
 * defines \Aura_Worker_Snapshots by default).
 *
 * \Aura_Worker_Snapshots is defined unconditionally, at the top level of every
 * process's own run of tests/bootstrap.php — not behind a lazy autoloader —
 * so ELEMENTOR_MCP_TEST_NO_AURA_WORKER_SNAPSHOTS (a new test-only seam in
 * tests/bootstrap.php, mirroring the existing ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES)
 * MUST be set in the PARENT process, before @runInSeparateProcess forks this
 * class's test — i.e. in setUpBeforeClass(), which runs once, in the parent,
 * before any of this class's tests dispatch. Setting it in setUp() would be
 * one step too late: for an isolated test, setUp() runs INSIDE the freshly
 * forked child, after that child's own bootstrap.php has already executed
 * (which is where this env var is read).
 *
 * A dedicated file (not a second class alongside RulesBridgeTest): PHPUnit's
 * directory-based test-suite discovery in this version only picks up the one
 * TestCase subclass per file whose name matches the filename convention
 * (confirmed empirically in the Codex round 2 pass of this same fix wave —
 * see ServerInfoRulesBridgeMissingTest.php's docblock).
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

class RulesReportGenuinelyAbsentTest extends TestCase {

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
	public function test_report_describes_a_genuinely_fork_only_site_with_neither_class(): void {
		\Elementor_MCP_Rules::reset_state( false ); // \Aura_Worker_Rules absent too
		$report = \Elementor_MCP_Rules::report();
		\Elementor_MCP_Rules::reset_state();

		$this->assertSame(
			array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() ),
			$report
		);
	}
}
