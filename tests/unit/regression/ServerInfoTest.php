<?php
/**
 * Regression — the "zero tools, no explanation" experience must be diagnosable.
 *
 * Field report #5, part 2a. A fresh install presented to a client as "the MCP
 * server exposes zero tools", and nothing anywhere explained it: no server-info
 * response, no log line, nothing saying "N abilities registered, M suppressed
 * by option". The only recourse was reading the plugin source to discover the
 * option existed.
 *
 * The same invisibility bites after upgrades: the defaults seeder re-disables
 * the Pro-badged set when its counter falls behind, so 36 tools vanished from a
 * working install and it took an ability-vs-tool diff to notice.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class ServerInfoTest extends Ability_Test_Case {

	protected function tearDown(): void {
		unset( $GLOBALS['_options'] );
		parent::tearDown();
	}

	private function plugin(): \Elementor_MCP_Plugin {
		return \Elementor_MCP_Plugin::instance();
	}

	public function test_server_info_survives_a_disabled_list_that_names_it(): void {
		$GLOBALS['_options']['elementor_mcp_disabled_tools'] = array(
			\Elementor_MCP_Server_Info_Abilities::ABILITY,
			'elementor-mcp/list-pages',
		);

		$kept = $this->plugin()->filter_disabled_tools( array(
			\Elementor_MCP_Server_Info_Abilities::ABILITY,
			'elementor-mcp/list-pages',
			'elementor-mcp/add-widget',
		) );

		$this->assertContains(
			\Elementor_MCP_Server_Info_Abilities::ABILITY,
			$kept,
			'A diagnostic the thing it diagnoses can switch off is no diagnostic at all.'
		);
		$this->assertNotContains( 'elementor-mcp/list-pages', $kept, 'Other disabled tools must still be withheld.' );
	}

	public function test_server_info_survives_low_tools_mode(): void {
		$GLOBALS['_options']['elementor_mcp_low_tool_mode'] = '1';

		$kept = $this->plugin()->filter_disabled_tools( array(
			\Elementor_MCP_Server_Info_Abilities::ABILITY,
			'elementor-mcp/some-non-essential-tool',
		) );

		$this->assertContains(
			\Elementor_MCP_Server_Info_Abilities::ABILITY,
			$kept,
			'Low-tools mode trims to essentials; the tool that explains the trimming is one.'
		);
	}

	public function test_it_is_not_re_added_when_it_was_never_registered(): void {
		$kept = $this->plugin()->filter_disabled_tools( array( 'elementor-mcp/list-pages' ) );

		$this->assertNotContains(
			\Elementor_MCP_Server_Info_Abilities::ABILITY,
			$kept,
			'The filter must not invent a tool that no group registered.'
		);
	}

	public function test_the_report_names_the_option_that_is_withholding_tools(): void {
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame(
			'elementor_mcp_disabled_tools',
			$report['abilities']['controlled_by']['option'],
			'Naming the option is the whole point: without it the agent has to read the source.'
		);
		$this->assertArrayHasKey( 'registered', $report['abilities'] );
		$this->assertArrayHasKey( 'exposed', $report['abilities'] );
		$this->assertArrayHasKey( 'suppressed', $report['abilities'] );
	}

	public function test_a_gap_caused_by_our_settings_names_the_option(): void {
		$registered = array( 'elementor-mcp/a', 'elementor-mcp/b', 'elementor-mcp/c' );

		// Drive the real filter so the attribution is genuine, not seeded.
		$GLOBALS['_options']['elementor_mcp_disabled_tools'] = array( 'elementor-mcp/b', 'elementor-mcp/c' );
		$exposed = $this->plugin()->filter_disabled_tools( $registered );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( $registered, $exposed );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 3, $report['abilities']['registered'] );
		$this->assertSame( 1, $report['abilities']['exposed'] );
		$this->assertSame( 2, $report['abilities']['suppressed'] );
		$this->assertSame( array( 'elementor-mcp/b', 'elementor-mcp/c' ), $report['abilities']['withheld_by']['plugin_settings'] );
		$this->assertSame( array(), $report['abilities']['withheld_by']['other_filters'] );

		$this->assertNotEmpty( $report['notes'], 'A count with no explanation is what the field report complained about.' );
		$this->assertStringContainsString( 'elementor_mcp_disabled_tools', implode( ' ', $report['notes'] ) );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * `elementor_mcp_ability_names` is a documented public hook, so another
	 * plugin can remove abilities. Attributing that to the local option would
	 * send an operator hunting slugs that are not in it.
	 */
	public function test_a_gap_caused_elsewhere_is_not_blamed_on_our_option(): void {
		$registered = array( 'elementor-mcp/a', 'elementor-mcp/b' );

		// Our filter removed nothing; something else did.
		$this->plugin()->filter_disabled_tools( $registered );
		\Elementor_MCP_Ability_Registrar::__set_names_for_test( $registered, array( 'elementor-mcp/a' ) );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		$notes  = implode( ' ', $report['notes'] );

		$this->assertSame( array(), $report['abilities']['withheld_by']['plugin_settings'] );
		$this->assertSame( array( 'elementor-mcp/b' ), $report['abilities']['withheld_by']['other_filters'] );
		$this->assertStringContainsString( 'elementor_mcp_ability_names', $notes );
		$this->assertStringNotContainsString(
			'withheld by this plugin',
			$notes,
			'Blaming our own settings for another plugin\'s removal sends the operator to the wrong place.'
		);

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	public function test_exposed_is_zero_when_the_server_endpoint_is_off(): void {
		\Elementor_MCP_Ability_Registrar::__set_names_for_test(
			array( 'elementor-mcp/a', 'elementor-mcp/b' ),
			array( 'elementor-mcp/a', 'elementor-mcp/b' )
		);
		$GLOBALS['_options'][ \Elementor_MCP_Plugin::OPTION_SERVER_ENABLED ] = '0';

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 0, $report['abilities']['exposed'], 'No server means no tools, whatever survived the filter.' );
		$this->assertSame( 2, $report['abilities']['would_expose'], 'The operator still needs to know what comes back when they re-enable it.' );
		$this->assertFalse( $report['server_enabled'] );
		$this->assertStringContainsString( 'switched OFF', implode( ' ', $report['notes'] ) );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	public function test_exposed_matches_the_filtered_list_when_the_server_is_on(): void {
		\Elementor_MCP_Ability_Registrar::__set_names_for_test(
			array( 'elementor-mcp/a', 'elementor-mcp/b' ),
			array( 'elementor-mcp/a' )
		);

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 1, $report['abilities']['exposed'] );
		$this->assertSame( 1, $report['abilities']['would_expose'] );
		$this->assertSame( 1, $report['abilities']['suppressed'] );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	public function test_the_report_carries_the_adapter_source_and_versions(): void {
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		// The bundled adapter can lag the standalone plugin, and the two answer
		// the same client differently — so which one is in use is diagnostic.
		$this->assertArrayHasKey( 'source', $report['mcp_adapter'] );
		$this->assertArrayHasKey( 'version', $report['mcp_adapter'] );
		$this->assertSame( ELEMENTOR_MCP_VERSION, $report['plugin_version'] );
		$this->assertArrayHasKey( 'elementor_version', $report );
	}

	public function test_the_ability_is_read_only(): void {
		$GLOBALS['_registered_abilities'] = array();

		( new \Elementor_MCP_Server_Info_Abilities() )->register();

		$args = $GLOBALS['_registered_abilities'][ \Elementor_MCP_Server_Info_Abilities::ABILITY ] ?? array();

		$this->assertTrue( $args['meta']['annotations']['readonly'] ?? false );
		$this->assertFalse( $args['meta']['annotations']['destructive'] ?? true );

		unset( $GLOBALS['_registered_abilities'] );
	}
}
