<?php
/**
 * Regression — a foreign refusal on the adapter's global pre-call filter is
 * named, not passed on as if it were ours.
 *
 * Angie 1.1.17 hooks `mcp_adapter_pre_tool_call` with no server check and
 * returns `angie_consent_required` until its external-scripts consent is
 * granted. Every MCP Adapter server on the site applies that filter, so the
 * refusal reaches this plugin's server too, and tools/list keeps working: the
 * server looks healthy and every call fails with another plugin's message.
 *
 * The plugin reports it; it never lifts it. These tests pin both halves.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class ForeignCallBlockersTest extends Ability_Test_Case {

	const ANGIE = 'Angie\\Modules\\Mcp\\Module::require_consent_before_tool_call';

	protected function tearDown(): void {
		unset( $GLOBALS['_options'], $GLOBALS['wp_filter'] );
		parent::tearDown();
	}

	private function server( string $id ): object {
		return new class( $id ) {
			private $id;
			public function __construct( string $id ) {
				$this->id = $id;
			}
			public function get_server_id(): string {
				return $this->id;
			}
		};
	}

	private function angie_refusal(): \WP_Error {
		return new \WP_Error( 'angie_consent_required', 'Angie is installed but external-scripts consent has not been granted.' );
	}

	public function test_angie_refusal_on_our_server_is_named_and_stays_a_refusal(): void {
		$result = \Elementor_MCP_Foreign_Blockers::explain(
			$this->angie_refusal(),
			'elementor-mcp-add-heading',
			null,
			$this->server( \Elementor_MCP_Plugin::SERVER_ID )
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'The call must stay refused: another vendor\'s consent gate is not ours to lift.' );
		$this->assertSame( 'elementor_mcp_blocked_by_foreign_plugin', $result->get_error_code() );
		$this->assertStringContainsString( 'Angie', $result->get_error_message() );
		$this->assertStringContainsString( 'not by MCP Tools for Elementor', $result->get_error_message() );
		$this->assertStringContainsString( 'admin.php?page=angie-app', $result->get_error_message() );
		$this->assertStringContainsString( 'external-scripts consent', $result->get_error_message(), 'The other plugin\'s own message is kept.' );
		$this->assertSame(
			array( 'blocked_by' => 'angie', 'original_code' => 'angie_consent_required', 'tool' => 'elementor-mcp-add-heading' ),
			$result->get_error_data()
		);
	}

	public function test_other_servers_are_left_untouched(): void {
		$refusal = $this->angie_refusal();

		$this->assertSame( $refusal, \Elementor_MCP_Foreign_Blockers::explain( $refusal, 'x', null, $this->server( 'elementor-mcp' ) ) );
		$this->assertSame( $refusal, \Elementor_MCP_Foreign_Blockers::explain( $refusal, 'x', null, $this->server( 'mcp-adapter-default-server' ) ) );
		$this->assertSame( $refusal, \Elementor_MCP_Foreign_Blockers::explain( $refusal, 'x', null, null ), 'No server object: nothing proves the call is ours.' );
	}

	public function test_arguments_and_unknown_errors_pass_through_unchanged(): void {
		$ours  = $this->server( \Elementor_MCP_Plugin::SERVER_ID );
		$args  = array( 'post_id' => 12, 'title' => 'Hi' );
		$other = new \WP_Error( 'some_other_plugin_refusal', 'nope' );

		$this->assertSame( $args, \Elementor_MCP_Foreign_Blockers::explain( $args, 'x', null, $ours ), 'Arguments are never rewritten.' );
		$this->assertSame( $other, \Elementor_MCP_Foreign_Blockers::explain( $other, 'x', null, $ours ), 'Only a refusal read in its vendor\'s source is re-worded.' );
	}

	public function test_explainer_runs_last_on_the_adapter_filter(): void {
		$GLOBALS['_filters'] = array();
		\Elementor_MCP_Foreign_Blockers::register();

		$this->assertArrayHasKey( PHP_INT_MAX, $GLOBALS['_filters']['mcp_adapter_pre_tool_call'] );
		$this->assertSame( 4, $GLOBALS['_filters']['mcp_adapter_pre_tool_call'][ PHP_INT_MAX ][0]['accepted_args'], 'It needs the server argument to tell our calls from others.' );
	}

	public function test_report_flags_angie_as_blocking_until_consent_is_yes(): void {
		$callbacks = array( self::ANGIE, 'Elementor_MCP_Foreign_Blockers::explain', 'Some_Plugin::audit' );

		$report = \Elementor_MCP_Foreign_Blockers::report( $callbacks );
		$this->assertCount( 1, $report['known'] );
		$this->assertSame( 'angie', $report['known'][0]['plugin'] );
		$this->assertTrue( $report['known'][0]['blocking'], 'Absent consent option means Angie refuses every call.' );
		$this->assertSame( array( 'Some_Plugin::audit' ), $report['other_callbacks'], 'Our own explainer is not reported; unknown callbacks are.' );

		$GLOBALS['_options']['angie_external_scripts_consent'] = 'no';
		$this->assertTrue( \Elementor_MCP_Foreign_Blockers::report( $callbacks )['known'][0]['blocking'] );

		$GLOBALS['_options']['angie_external_scripts_consent'] = 'yes';
		$this->assertFalse( \Elementor_MCP_Foreign_Blockers::report( $callbacks )['known'][0]['blocking'] );
	}

	public function test_live_filter_is_read_from_wp_hook_callbacks(): void {
		$GLOBALS['wp_filter']['mcp_adapter_pre_tool_call'] = (object) array(
			'callbacks' => array(
				10          => array(
					'a' => array( 'function' => array( 'Angie\\Modules\\Mcp\\Module', 'require_consent_before_tool_call' ), 'accepted_args' => 1 ),
					'b' => array( 'function' => 'some_function', 'accepted_args' => 1 ),
					'c' => array( 'function' => static function ( $a ) { return $a; }, 'accepted_args' => 1 ),
				),
				PHP_INT_MAX => array(
					'd' => array( 'function' => array( 'Elementor_MCP_Foreign_Blockers', 'explain' ), 'accepted_args' => 4 ),
				),
			),
		);

		$this->assertSame(
			array( self::ANGIE, 'some_function', 'closure', 'Elementor_MCP_Foreign_Blockers::explain' ),
			\Elementor_MCP_Foreign_Blockers::describe_callbacks()
		);
		$this->assertSame( array(), ( function () { unset( $GLOBALS['wp_filter'] ); return \Elementor_MCP_Foreign_Blockers::describe_callbacks(); } )() );
	}

	public function test_server_info_carries_the_block_and_a_note_when_blocking(): void {
		$GLOBALS['wp_filter']['mcp_adapter_pre_tool_call'] = (object) array(
			'callbacks' => array(
				10 => array( 'a' => array( 'function' => array( 'Angie\\Modules\\Mcp\\Module', 'require_consent_before_tool_call' ), 'accepted_args' => 1 ) ),
			),
		);

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertArrayHasKey( 'foreign_call_blockers', $report );
		$this->assertTrue( $report['foreign_call_blockers']['known'][0]['blocking'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'refused by another plugin (angie)' ); } ),
			'An operator reading notes must learn why every call fails.'
		);

		$GLOBALS['_options']['angie_external_scripts_consent'] = 'yes';
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		$this->assertFalse( $report['foreign_call_blockers']['known'][0]['blocking'] );
		$this->assertEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'refused by another plugin' ); } )
		);
	}
}
