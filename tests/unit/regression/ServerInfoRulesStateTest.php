<?php
/**
 * Codex round 5 (fork final-review fix wave, PR #63) — the fourth round on
 * server-info's rules-state block. After three rounds of patching one more
 * branch of an inline if/elseif chain (each fix covering one combination and
 * missing another — round 5's own finding: the bridge-missing fallback
 * decided "SiteAgent present" from \Aura_Worker_Rules alone, so a pre-2.10
 * SiteAgent — only \Aura_Worker_Snapshots — with the bridge ALSO missing
 * still reported "not installed"), the controller ruled a mechanism change:
 * the whole decision is now ONE pure function,
 * Elementor_MCP_Server_Info_Abilities::rules_block() (see its docblock in
 * includes/abilities/class-server-info-abilities.php for the full priority
 * table this test enumerates) — including a controller correction to that
 * function's OWN priority order, mid-round: absence (neither SiteAgent class
 * present) outranks governance liveness, not the other way around, since
 * Elementor_MCP_Governance::is_active() keys on the same
 * \Aura_Worker_Snapshots class the absence check does — checking liveness
 * first meant a genuinely-absent site got told its (nonexistent) wrapper
 * "is not active" rather than that SiteAgent is not installed.
 *
 * This test calls that pure function DIRECTLY — no @runInSeparateProcess, no
 * env-var seams, no bootstrap gymnastics — because it takes its four inputs
 * (bridge_loaded, rules_class, snapshots_class, governance_live) and a
 * pre-built `report` array as plain parameters, the same way
 * ServerInfoRulesGenuinelyAbsentTest and its neighbours already establish the
 * server-info assembly's OTHER pure decision helpers
 * (execution_guard_active(), writes_are_shielded()) are unit-tested (see
 * tests/unit/Security/ForeignMcpTransportTest.php).
 *
 * Coverage is exhaustive over combinations that CAN occur:
 *   - branch (a) (`! $bridge_loaded`): all 4 combinations of
 *     ($rules_class, $snapshots_class), each with governance_live true AND
 *     false (governance liveness must be irrelevant to this branch — it's
 *     checked before governance is even consulted) — 8 rows.
 *   - branch (b) (`$bridge_loaded` true, neither SiteAgent class present):
 *     governance_live true AND false, each crossed with every possible
 *     $report shape (even ones inconsistent with "neither class", to prove
 *     branch (b) decides from the booleans alone and never even looks at
 *     $report) — proves absence wins unconditionally.
 *   - branches (c)–(f) (`$bridge_loaded` true, SiteAgent present — i.e. at
 *     least one class exists, matching each report() shape's own real
 *     precondition): every report() shape (ready, outdated, incomplete × 3
 *     reasons) × governance_live true and false — 12 rows.
 *   - one self-check row: an unrecognised `state` must produce an EMPTY note
 *     rather than asserting anything that might not hold — see rules_block()'s
 *     own "self-check" paragraph.
 * `bridge_loaded = true` with `report = null` is NOT tested — that combination
 * cannot occur through the real call site (execute_server_info() only omits
 * `report` when the bridge itself failed to load), so it is a caller-contract
 * violation, not a state this table needs to answer for. Likewise, `report`
 * claiming `state: 'absent'` together with a SiteAgent class actually present
 * cannot occur through the real call site either (report()'s own absent
 * branch requires the same "neither class" condition branch (b) checks) — it
 * is exercised anyway, deliberately, in the "branch (b) ignores $report"
 * coverage above, as a defensive-robustness property of the pure function.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class ServerInfoRulesStateTest extends TestCase {

	// --- report() fixtures, one per shape rules_block() has to handle -------

	private function ready_report(): array {
		return array( 'enforced' => true, 'source' => 'siteagent', 'state' => 'ready', 'ruleset' => array( 'seq' => 1, 'rule_count' => 1, 'received_at' => 1 ), 'points' => array( 'governed_write' ) );
	}

	private function outdated_report(): array {
		return array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'outdated', 'ruleset' => null, 'points' => array() );
	}

	private function absent_report(): array {
		return array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() );
	}

	private function enforce_missing_report(): array {
		return array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'incomplete', 'reason' => 'enforce_missing', 'ruleset' => null, 'points' => array() );
	}

	private function current_missing_report(): array {
		return array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'incomplete', 'reason' => 'current_missing', 'ruleset' => null, 'points' => array() );
	}

	private function reader_failed_report(): array {
		// Carries an extra 'error' key, like the real report() does — the
		// function must not choke on or silently drop keys it does not
		// otherwise touch.
		return array( 'enforced' => true, 'source' => 'siteagent', 'state' => 'incomplete', 'reason' => 'reader_failed', 'ruleset' => null, 'points' => array( 'governed_write' ), 'error' => 'stub forced reader failure' );
	}

	private function all_report_fixtures(): array {
		return array( 'ready_report', 'outdated_report', 'absent_report', 'enforce_missing_report', 'current_missing_report', 'reader_failed_report' );
	}

	// --- (a) bridge not loaded: all 4 (rules_class, snapshots_class) combos,
	// each with governance_live true AND false ------------------------------

	public static function bridge_missing_combinations(): array {
		$rows = array();
		foreach ( array( false, true ) as $governance_live ) {
			$rows[ 'neither class, governance_live=' . var_export( $governance_live, true ) ] = array( false, false, $governance_live, 'absent', 'none', 'install' );
			$rows[ 'rules class only, governance_live=' . var_export( $governance_live, true ) ] = array( true, false, $governance_live, 'bridge_missing', 'siteagent', 'bridge_missing' );
			// This exact row is round 5's own finding: a pre-2.10 SiteAgent
			// (only \Aura_Worker_Snapshots) with the bridge ALSO missing used
			// to report `absent` — must now report `bridge_missing`.
			$rows[ 'snapshots class only, governance_live=' . var_export( $governance_live, true ) ] = array( false, true, $governance_live, 'bridge_missing', 'siteagent', 'bridge_missing' );
			$rows[ 'both classes, governance_live=' . var_export( $governance_live, true ) ] = array( true, true, $governance_live, 'bridge_missing', 'siteagent', 'bridge_missing' );
		}
		return $rows;
	}

	/**
	 * @dataProvider bridge_missing_combinations
	 */
	public function test_bridge_not_loaded( bool $rules_class, bool $snapshots_class, bool $governance_live, string $expected_state, string $expected_source, string $expected_note_kind ): void {
		$result = \Elementor_MCP_Server_Info_Abilities::rules_block( false, $rules_class, $snapshots_class, $governance_live, null );

		$this->assertSame( $expected_state, $result['state'] );
		$this->assertSame( $expected_source, $result['source'] );
		$this->assertFalse( $result['enforced'] );
		$this->assertSame( array(), $result['points'] );
		$this->assertNull( $result['ruleset'] );
		$this->assertArrayNotHasKey( 'reason', $result );
		if ( 'install' === $expected_note_kind ) {
			$this->assertStringContainsString( 'SiteAgent is not installed', $result['note'] );
		} else {
			$this->assertStringContainsString( 'rules bridge did not load', $result['note'] );
		}
	}

	// --- (b) bridge loaded, neither SiteAgent class present: absence wins,
	// unconditionally — over governance liveness AND over whatever $report
	// happens to say (branch (b) never even reads it) --------------------

	public function test_neither_class_present_reports_absent_regardless_of_governance_liveness_or_report(): void {
		foreach ( array( false, true ) as $governance_live ) {
			foreach ( array_merge( $this->all_report_fixtures(), array( null ) ) as $fixture ) {
				$report = null === $fixture ? null : $this->{$fixture}();
				$result = \Elementor_MCP_Server_Info_Abilities::rules_block( true, false, false, $governance_live, $report );
				$label  = 'governance_live=' . var_export( $governance_live, true ) . ', report=' . ( $fixture ?? 'null' );

				$this->assertSame( 'absent', $result['state'], $label );
				$this->assertSame( 'none', $result['source'], $label );
				$this->assertFalse( $result['enforced'], $label );
				$this->assertSame( array(), $result['points'], $label );
				$this->assertNull( $result['ruleset'], $label );
				$this->assertArrayNotHasKey( 'reason', $result, $label );
				$this->assertStringContainsString( 'SiteAgent is not installed', $result['note'], $label );
				$this->assertStringNotContainsString( 'governance wrapper is not active', $result['note'], $label );
			}
		}
	}

	// --- (c)-(f): bridge loaded, SiteAgent present (at least one class),
	// every report() shape × governance liveness -----------------------

	public static function bridge_loaded_with_siteagent_present_combinations(): array {
		return array(
			// [ report_fixture, rules_class, snapshots_class, governance_live, expect[] ]
			'ready, governance_live'              => array( 'ready_report', true, true, true, array( 'state' => 'ready', 'enforced' => true, 'points' => array( 'governed_write' ), 'note_exact_empty' => true ) ),
			'ready, NOT governance_live'           => array( 'ready_report', true, true, false, array( 'state' => 'ready', 'enforced' => false, 'points' => array(), 'note_contains' => 'governance wrapper is not active' ) ),
			'outdated, governance_live'            => array( 'outdated_report', false, true, true, array( 'state' => 'outdated', 'enforced' => false, 'points' => array(), 'note_contains' => 'predates 2.10.0' ) ),
			'outdated, NOT governance_live'        => array( 'outdated_report', false, true, false, array( 'state' => 'outdated', 'enforced' => false, 'points' => array(), 'note_contains' => 'governance wrapper is not active' ) ),
			'enforce_missing, governance_live'     => array( 'enforce_missing_report', true, true, true, array( 'state' => 'incomplete', 'reason' => 'enforce_missing', 'enforced' => false, 'points' => array(), 'note_contains' => 'no enforce()' ) ),
			'enforce_missing, NOT governance_live' => array( 'enforce_missing_report', true, true, false, array( 'state' => 'incomplete', 'reason' => 'enforce_missing', 'enforced' => false, 'points' => array(), 'note_contains' => 'governance wrapper is not active' ) ),
			'current_missing, governance_live'     => array( 'current_missing_report', true, true, true, array( 'state' => 'incomplete', 'reason' => 'current_missing', 'enforced' => false, 'points' => array(), 'note_contains' => 'no current()' ) ),
			'current_missing, NOT governance_live' => array( 'current_missing_report', true, true, false, array( 'state' => 'incomplete', 'reason' => 'current_missing', 'enforced' => false, 'points' => array(), 'note_contains' => 'governance wrapper is not active' ) ),
			'reader_failed, governance_live'       => array( 'reader_failed_report', true, true, true, array( 'state' => 'incomplete', 'reason' => 'reader_failed', 'enforced' => true, 'points' => array( 'governed_write' ), 'note_contains' => 'could not be read' ) ),
			'reader_failed, NOT governance_live'   => array( 'reader_failed_report', true, true, false, array( 'state' => 'incomplete', 'reason' => 'reader_failed', 'enforced' => false, 'points' => array(), 'note_contains' => 'governance wrapper is not active' ) ),
		);
	}

	/**
	 * @dataProvider bridge_loaded_with_siteagent_present_combinations
	 */
	public function test_bridge_loaded_with_siteagent_present( string $report_fixture, bool $rules_class, bool $snapshots_class, bool $governance_live, array $expect ): void {
		$report = $this->{$report_fixture}();
		$result = \Elementor_MCP_Server_Info_Abilities::rules_block( true, $rules_class, $snapshots_class, $governance_live, $report );

		$this->assertSame( $expect['state'], $result['state'] );
		$this->assertSame( $expect['enforced'], $result['enforced'] );
		$this->assertSame( $expect['points'], $result['points'] );
		if ( isset( $expect['reason'] ) ) {
			$this->assertSame( $expect['reason'], $result['reason'] );
		} else {
			$this->assertArrayNotHasKey( 'reason', $result );
		}
		if ( ! empty( $expect['note_exact_empty'] ) ) {
			$this->assertSame( '', $result['note'], 'A fully enforced site gets no note.' );
		} else {
			$this->assertStringContainsString( $expect['note_contains'], $result['note'] );
		}
		// A key rules_block() never touches (reader_failed's 'error') must
		// survive untouched — it is not this function's to drop.
		if ( isset( $report['error'] ) ) {
			$this->assertSame( $report['error'], $result['error'] ?? null );
		}
	}

	// --- Not-live pre-empts every report()-derived note, once SiteAgent is
	// established to be present (branch (b) has already ruled out absence) --

	public function test_not_live_note_wins_over_every_report_derived_note_when_siteagent_is_present(): void {
		foreach ( array( 'ready_report', 'outdated_report', 'enforce_missing_report', 'current_missing_report', 'reader_failed_report' ) as $fixture ) {
			$result = \Elementor_MCP_Server_Info_Abilities::rules_block( true, true, true, false, $this->{$fixture}() );
			$this->assertStringContainsString( 'governance wrapper is not active', $result['note'], "Fixture: {$fixture}" );
			$this->assertFalse( $result['enforced'], "Fixture: {$fixture}" );
			$this->assertSame( array(), $result['points'], "Fixture: {$fixture}" );
		}
	}

	// --- Self-check: exhaustive, no default that lies -----------------------

	public function test_an_unrecognised_state_gets_silence_not_a_false_claim(): void {
		// Not a state Elementor_MCP_Rules::report() can actually return — this
		// proves the function's own defensive fallback (its "self-check"
		// paragraph) does not fabricate a note for a state it was never
		// updated to handle.
		$result = \Elementor_MCP_Server_Info_Abilities::rules_block(
			true,
			true,
			true,
			true,
			array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'some_future_state', 'ruleset' => null, 'points' => array() )
		);
		$this->assertSame( '', $result['note'] );
	}
}
