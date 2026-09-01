<?php
/**
 * Functional — the collateral verdict on a governed page write (P5.1,
 * elementor-mcp#67). save_page_data() records what the save did to the page;
 * when the run ends, governance decides: warn (default — the write stands and
 * the finding rides the run's warnings channel), refuse (the pre-write snapshot
 * is restored and an error names the nodes), or off.
 *
 * @group functional
 * @group governance
 * @package Elementor_MCP\Tests\Functional
 */

namespace Elementor_MCP\Tests\Functional;

use PHPUnit\Framework\TestCase;

class GovernanceCollateralTest extends TestCase {

	/** @var callable|null */
	private $mode_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_aura_snap'] = array(
			'fail_snapshot'  => false,
			'fail_restore'   => false,
			'snapshot_calls' => array(),
			'restore_calls'  => array(),
			'seq'            => 0,
		);
		$GLOBALS['_aura_grant'] = array( 'enforced' => false, 'verify_result' => true, 'verify_calls' => array() );
		$GLOBALS['_emcp_require_grants'] = false;
		$GLOBALS['_emcp_render_check']   = false;
		$GLOBALS['_actions_fired']       = array();
		$GLOBALS['_aura_rules']          = array( 'verdict' => array( 'effect' => null ), 'calls' => array(), 'current' => null, 'throw' => false );
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
	}

	protected function tearDown(): void {
		if ( null !== $this->mode_filter ) {
			remove_filter( 'emcp_collateral_guard_mode', $this->mode_filter );
			$this->mode_filter = null;
		}
		unset( $GLOBALS['_aura_rules'] );
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();
		parent::tearDown();
	}

	private function set_mode( string $mode ): void {
		$this->mode_filter = static function () use ( $mode ) {
			return $mode;
		};
		add_filter( 'emcp_collateral_guard_mode', $this->mode_filter, 10, 4 );
	}

	private function page(): array {
		return array(
			array( 'id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'One' ), 'elements' => array() ),
			array( 'id' => 'h2', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Two' ), 'elements' => array() ),
		);
	}

	/**
	 * A page write as save_page_data() performs it: gate + snapshot first, then
	 * the write, then the record of what it did — with the trees given here.
	 */
	private function writer( array $before, array $requested, array $persisted, int $record_post = 0 ): callable {
		return static function ( $input ) use ( $before, $requested, $persisted, $record_post ) {
			$post_id = (int) ( $input['post_id'] ?? 0 );
			$gate    = \Elementor_MCP_Governance::before_page_write( $post_id );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			\Elementor_MCP_Governance::record_page_write( $record_post ?: $post_id, $before, $requested, $persisted );
			return array( 'ok' => true );
		};
	}

	/** before → requested changes h1; persisted ALSO emptied h2. */
	private function damaging_writer( int $record_post = 0 ): callable {
		$before            = $this->page();
		$requested         = $before;
		$requested[0]['settings']['title'] = 'Uno';
		$persisted         = $requested;
		$persisted[1]['settings']['title'] = '';
		return $this->writer( $before, $requested, $persisted, $record_post );
	}

	public function test_default_mode_warns_and_lets_the_write_stand(): void {
		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( 'collateral', $result['warnings'][0]['rule'] );
		$this->assertStringContainsString( 'heading h2 (changed)', $result['warnings'][0]['reason'] );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'], 'warn never reverts.' );
	}

	/** The collateral actions this run fired, as [ tag, name, post_id, first collateral id, mode ]. */
	private function collateral_actions(): array {
		$out = array();
		foreach ( $GLOBALS['_actions_fired'] as $fired ) {
			if ( 0 === strpos( $fired['tag'], 'elementor_mcp_governance_collateral' ) ) {
				$args  = $fired['args'];
				$out[] = array( $fired['tag'], $args[0], $args[1], is_array( $args[2] ) ? ( $args[2]['collateral'][0]['id'] ?? null ) : $args[2], $args[3] ?? null );
			}
		}
		return $out;
	}

	public function test_warn_fires_the_collateral_action_with_the_report(): void {
		\Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertSame(
			array( array( 'elementor_mcp_governance_collateral', 'elementor-mcp/update-element', 55, 'h2', 'warn' ) ),
			$this->collateral_actions()
		);
	}

	public function test_refuse_restores_the_snapshot_and_names_the_nodes(): void {
		$this->set_mode( 'refuse' );

		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_collateral_refused', $result->get_error_code() );
		$this->assertStringContainsString( 'heading h2 (changed)', $result->get_error_message() );
		$this->assertStringContainsString( 'Do not retry', $result->get_error_message() );
		$data = $result->get_error_data();
		$this->assertTrue( $data['rolled_back'] );
		$this->assertTrue( $data['agent_must_not_retry'] );
		$this->assertSame( array( 'h1' ), $data['targets'] );
		$this->assertSame( array( 'snap_stub_1' ), $GLOBALS['_aura_snap']['restore_calls'] );
		$fired = $GLOBALS['_actions_fired'];
		$this->assertCount( 1, $fired );
		$this->assertSame( 'elementor_mcp_governance_collateral_reverted', $fired[0]['tag'] );
		$this->assertSame( array( 'elementor-mcp/update-element', 55, 'snap_stub_1' ), array_slice( $fired[0]['args'], 0, 3 ) );
		$this->assertSame( 'h2', $fired[0]['args'][3]['collateral'][0]['id'], 'The reverted action carries the report.' );
	}

	public function test_refuse_with_a_failed_restore_reports_the_partial_write(): void {
		$this->set_mode( 'refuse' );
		$GLOBALS['_aura_snap']['fail_restore'] = true;

		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'governance_rollback_failed', $result->get_error_code() );
	}

	public function test_off_skips_the_verdict_entirely(): void {
		$this->set_mode( 'off' );
		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertSame( array( 'ok' => true ), $result, 'No warnings key at all.' );
		$this->assertSame( array(), $this->collateral_actions(), 'off announces nothing.' );
	}

	public function test_an_unknown_mode_falls_back_to_warn(): void {
		$this->set_mode( 'yolo' );

		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertSame( 'collateral', $result['warnings'][0]['rule'] );
		$this->assertCount( 0, $GLOBALS['_aura_snap']['restore_calls'] );
	}

	public function test_a_clean_write_carries_no_warning(): void {
		$before    = $this->page();
		$requested = $before;
		$requested[0]['settings']['title'] = 'Uno';

		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->writer( $before, $requested, $requested ), array( 'post_id' => 55 ) );

		$this->assertSame( array( 'ok' => true ), $result );
	}

	public function test_a_record_for_a_post_the_run_did_not_snapshot_is_ignored(): void {
		// The run protects post 55; a record for post 99 has no rollback point
		// here and is not judged (stated limitation).
		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer( 99 ), array( 'post_id' => 55 ) );

		$this->assertSame( array( 'ok' => true ), $result );
	}

	public function test_a_record_outside_a_governed_run_is_a_no_op(): void {
		\Elementor_MCP_Governance::record_page_write( 55, $this->page(), $this->page(), array() );
		$this->assertTrue( true, 'No run in flight: nothing to record, nothing thrown.' );
	}

	public function test_an_unreadable_tree_is_not_a_finding(): void {
		$writer = static function ( $input ) {
			\Elementor_MCP_Governance::before_page_write( 55 );
			\Elementor_MCP_Governance::record_page_write( 55, null, array(), array() );
			return array( 'ok' => true );
		};

		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $writer, array( 'post_id' => 55 ) );

		$this->assertSame( array( 'ok' => true ), $result, 'Could not compare → say nothing, never accuse.' );
	}

	public function test_collateral_warning_and_a_rule_warning_travel_together(): void {
		// A warn-rule already on the run must not be displaced by the collateral
		// entry, and vice versa — one channel, every warning, once.
		$GLOBALS['_aura_rules']['verdict'] = array(
			'effect' => 'warn',
			'rule'   => array( 'key' => 'rule/careful', 'effect' => 'warn', 'target' => array( 'type' => 'page', 'id' => '55' ), 'reason' => 'client reviewing' ),
		);

		$result = \Elementor_MCP_Governance::run_governed( 'elementor-mcp/update-element', $this->damaging_writer(), array( 'post_id' => 55 ) );

		$this->assertSame( array( 'rule/careful', 'collateral' ), array_column( $result['warnings'], 'rule' ) );
		$this->assertSame( 'client reviewing', $result['warnings'][0]['reason'] );
	}
}
