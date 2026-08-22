<?php
/**
 * Important #2 (fork final-review): distinguish this plugin's OWN rules bridge
 * (includes/class-rules.php) failing to load from SiteAgent being genuinely
 * absent — the old fallback in server-info's assembly reported both
 * identically as "SiteAgent is not installed", which is simply false when
 * SiteAgent's own engine class is right there and only this plugin's bridge
 * is missing.
 *
 * Elementor_MCP_Rules is autoloaded once per PHP process, so "class does not
 * exist" is not reachable from ordinary test code once anything else has
 * referenced it (which the wider suite always has by the time this file's
 * tests run). ELEMENTOR_MCP_TEST_NO_RULES_BRIDGE is a test-only seam in
 * tests/bootstrap.php's autoload closure, gated by getenv() at the exact
 * point that class would otherwise be required. That gate is read once, the
 * first time something calls class_exists( 'Elementor_MCP_Rules' ) —
 * server-info's assembly does this itself, inside execute_server_info() — so,
 * unlike the sibling test in ServerInfoRulesBridgeAndSiteAgentMissingTest.php,
 * setting the env var from inside the test method (rather than
 * setUpBeforeClass()) would work here too; it is kept in setUpBeforeClass for
 * symmetry with that file, and because @runInSeparateProcess makes the
 * distinction moot (this class runs its one test in its own file so a
 * dedicated env var it does not otherwise need can't leak into anything else).
 *
 * A dedicated file (not just a second class in ServerInfoTest.php): PHPUnit's
 * directory-based test-suite discovery in this version only picks up the one
 * TestCase subclass per file whose name matches the filename convention —
 * confirmed empirically (`--list-tests` silently drops a second TestCase
 * class declared in the same file, even though PHP itself loads it fine).
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class ServerInfoRulesBridgeMissingTest extends Ability_Test_Case {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Set in the PARENT process, before any of this class's tests dispatch —
		// a @runInSeparateProcess test's own setUp() runs inside the freshly
		// forked child, AFTER that child's bootstrap.php has already executed
		// (which is where this env var is read), so setting it there would be
		// one step too late for the class-map autoload closure this gates.
		putenv( 'ELEMENTOR_MCP_TEST_NO_RULES_BRIDGE=1' ); // SiteAgent IS present; only our bridge is missing
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ELEMENTOR_MCP_TEST_NO_RULES_BRIDGE' );
		parent::tearDownAfterClass();
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_server_info_names_its_own_bridge_as_the_problem_when_siteagent_is_present(): void {
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 'bridge_missing', $report['rules']['state'] );
		$this->assertFalse( $report['rules']['enforced'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'rules bridge did not load' ); } )
		);
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString(
				'SiteAgent is not installed',
				$note,
				'SiteAgent IS installed here — only this plugin\'s own bridge failed to load.'
			);
		}
	}
}
