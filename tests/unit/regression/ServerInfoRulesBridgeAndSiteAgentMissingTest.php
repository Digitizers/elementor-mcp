<?php
/**
 * Companion to ServerInfoRulesBridgeMissingTest: the same server-info assembly
 * fallback (`! class_exists( 'Elementor_MCP_Rules' )`), but with SiteAgent's
 * own engine (\Aura_Worker_Rules) genuinely absent too — the ORIGINAL "SiteAgent
 * is not installed" wording must survive here, unlike the sibling test where
 * only this plugin's own bridge is missing.
 *
 * \Aura_Worker_Rules is stubbed unconditionally, at the top level of every
 * process's run of tests/bootstrap.php — not behind the lazy autoloader — so
 * unlike Elementor_MCP_Rules, ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES MUST be
 * set before that child process's own bootstrap.php runs, i.e. in the PARENT
 * process, before @runInSeparateProcess forks the child for this class's
 * test. setUpBeforeClass() runs once, in the parent, before any of this
 * class's tests are dispatched — setUp() would already be too late (it runs
 * inside the child, after that child's bootstrap has finished).
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
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES=1' ); // SiteAgent genuinely absent
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ELEMENTOR_MCP_TEST_NO_RULES_BRIDGE' );
		putenv( 'ELEMENTOR_MCP_TEST_NO_AURA_WORKER_RULES' );
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
