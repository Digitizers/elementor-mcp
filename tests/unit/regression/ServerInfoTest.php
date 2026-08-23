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

	protected function setUp(): void {
		parent::setUp();
		// The record is additive within a request and cleared at the start of a
		// registration pass; a test that calls the filter directly gets the
		// same clean slate.
		\Elementor_MCP_Plugin::reset_removed_by_settings();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_options'], $GLOBALS['_abilities'] );
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

		$this->seed_names( $registered, $exposed );

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
		$this->seed_names( $registered, array( 'elementor-mcp/a' ) );

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
		$this->seed_names( array( 'elementor-mcp/a', 'elementor-mcp/b'), array( 'elementor-mcp/a', 'elementor-mcp/b' ) );
		$GLOBALS['_options'][ \Elementor_MCP_Plugin::OPTION_SERVER_ENABLED ] = '0';

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 0, $report['abilities']['exposed'], 'No server means no tools, whatever survived the filter.' );
		$this->assertSame( 2, $report['abilities']['would_expose'], 'The operator still needs to know what comes back when they re-enable it.' );
		$this->assertFalse( $report['server_enabled'] );
		$this->assertStringContainsString( 'switched OFF', implode( ' ', $report['notes'] ) );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	public function test_exposed_matches_the_filtered_list_when_the_server_is_on(): void {
		$this->seed_names( array( 'elementor-mcp/a', 'elementor-mcp/b'), array( 'elementor-mcp/a' ) );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 1, $report['abilities']['exposed'] );
		$this->assertSame( 1, $report['abilities']['would_expose'] );
		$this->assertSame( 1, $report['abilities']['suppressed'] );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * Builds a registrar whose groups register exactly $names, so the real
	 * filter-chain handling in register_all() can be exercised.
	 *
	 * @param string[] $names Names the "groups" register.
	 * @return \Elementor_MCP_Ability_Registrar
	 */
	/**
	 * Seeds the registrar's lists AND the ability lookup, so the seeded names
	 * resolve the way real registrations do — the report drops names that
	 * resolve to nothing, because the MCP adapter skips those too.
	 *
	 * @param string[] $registered Registered names.
	 * @param string[] $exposed    Exposed names.
	 */
	private function seed_names( array $registered, array $exposed ): void {
		foreach ( array_merge( $registered, $exposed ) as $name ) {
			$GLOBALS['_abilities'][ $name ] = (object) array( 'name' => $name );
		}

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( $registered, $exposed );
	}

	private function registrar_registering( array $names ) {
		// The names must actually resolve through wp_get_ability(): the
		// registrar now drops ones that don't, because the adapter skips them
		// too and counting them would overstate the report.
		foreach ( $names as $name ) {
			$GLOBALS['_abilities'][ $name ] = (object) array( 'name' => $name );
		}

		$schema = new \Elementor_MCP_Schema_Generator();

		return new class( $this->make_data_stub(), $this->make_factory(), $schema, new \Elementor_MCP_Settings_Validator( $schema ), $names ) extends \Elementor_MCP_Ability_Registrar {
			/** @var string[] */
			private $seed;

			public function __construct( $data, $factory, $schema, $validator, array $seed ) {
				parent::__construct( $data, $factory, $schema, $validator );
				$this->seed = $seed;
			}

			protected function register_groups(): void {
				$this->ability_names = $this->seed;
			}
		};
	}

	/**
	 * The exemption must hold against the WHOLE filter chain, not just this
	 * plugin's callback: another callback returning a curated allowlist would
	 * otherwise drop the diagnostic, leaving exactly the silent "zero tools"
	 * state it exists to explain.
	 */
	public function test_server_info_survives_a_third_party_allowlist(): void {
		$info = \Elementor_MCP_Server_Info_Abilities::ABILITY;

		$curate = static function () {
			return array( 'elementor-mcp/list-pages' );
		};
		add_filter( 'elementor_mcp_ability_names', $curate, 99 );

		$exposed = $this->registrar_registering( array( $info, 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $curate, 99 );

		$this->assertContains( $info, $exposed, 'A curated allowlist from another plugin must not silence the diagnostic.' );
	}

	/**
	 * The same hook can ADD abilities. Counting only our own pre-filter names
	 * would report more exposed than registered and make the suppressed
	 * arithmetic nonsense.
	 */
	public function test_an_ability_added_by_another_plugin_counts_as_registered(): void {
		$add = static function ( array $names ) {
			$names[] = 'other-plugin/its-own-tool';
			return $names;
		};
		add_filter( 'elementor_mcp_ability_names', $add, 99 );

		$this->registrar_registering( array( 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $add, 99 );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertGreaterThanOrEqual(
			$report['abilities']['exposed'],
			$report['abilities']['registered'],
			'Exposed can never exceed registered.'
		);
		$this->assertSame( 0, $report['abilities']['suppressed'], 'Nothing was withheld — one thing was added.' );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * The loader treats every source file as optional — a host malware scanner
	 * quarantining one is upstream #100, the reason the guarded require exists.
	 * Registering the diagnostic FIRST made that dangerous: an unguarded `new`
	 * would throw before any other group ran, and register_all()'s catch would
	 * keep nothing, turning a missing diagnostic into total tool loss.
	 */
	public function test_a_missing_diagnostic_class_does_not_cost_every_other_tool(): void {
		$registrar = $this->registrar_registering( array( 'elementor-mcp/list-pages', 'elementor-mcp/add-widget' ) );

		// The seeded subclass stands in for "groups registered, diagnostic
		// absent" — the shape a quarantined file produces.
		$exposed = $registrar->register_all();

		$this->assertContains( 'elementor-mcp/list-pages', $exposed );
		$this->assertContains( 'elementor-mcp/add-widget', $exposed );
	}

	/**
	 * Two integrations can append the same registered tool. The adapter stores
	 * tools by name, so counting duplicates would report more exposed than
	 * exist — from the tool whose job is reporting that number accurately.
	 */
	public function test_duplicate_names_from_filters_are_counted_once(): void {
		$dupe = static function ( array $names ) {
			$names[] = 'elementor-mcp/list-pages';
			return $names;
		};
		add_filter( 'elementor_mcp_ability_names', $dupe, 99 );

		$exposed = $this->registrar_registering( array( 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $dupe, 99 );

		$this->assertSame( array( 'elementor-mcp/list-pages' ), $exposed, 'A name appended twice is still one tool.' );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		$this->assertSame( 1, $report['abilities']['exposed'] );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * A callback running before this plugin's priority-10 filter can add an
	 * ability that our own settings then remove. Such a name is in neither the
	 * pre-filter snapshot nor the final list, so it would vanish from the
	 * accounting entirely — counted as neither registered nor withheld.
	 */
	public function test_an_addition_our_settings_then_remove_is_still_accounted_for(): void {
		$GLOBALS['_abilities']['other-plugin/added-then-disabled'] = (object) array( 'name' => 'other-plugin/added-then-disabled' );
		$GLOBALS['_options']['elementor_mcp_disabled_tools']       = array( 'other-plugin/added-then-disabled' );

		$add = static function ( array $names ) {
			$names[] = 'other-plugin/added-then-disabled';
			return $names;
		};
		add_filter( 'elementor_mcp_ability_names', $add, 5 );
		add_filter( 'elementor_mcp_ability_names', array( $this->plugin(), 'filter_disabled_tools' ), 10 );

		$this->registrar_registering( array( 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $add, 5 );
		remove_filter( 'elementor_mcp_ability_names', array( $this->plugin(), 'filter_disabled_tools' ), 10 );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertContains(
			'other-plugin/added-then-disabled',
			$report['abilities']['withheld_by']['plugin_settings'],
			'An ability our settings removed must be reported as withheld, whoever added it.'
		);
		$this->assertSame( 2, $report['abilities']['registered'] );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * A callback can append a typo, or the name of an optional ability whose
	 * registration failed. The adapter resolves tools with wp_get_ability() and
	 * skips what it cannot find, so counting them would overstate both numbers
	 * — from the tool whose job is stating them accurately.
	 */
	public function test_a_name_that_resolves_to_nothing_is_not_counted(): void {
		$typo = static function ( array $names ) {
			$names[] = 'elementor-mcp/lst-pages';
			return $names;
		};
		add_filter( 'elementor_mcp_ability_names', $typo, 99 );

		$exposed = $this->registrar_registering( array( 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $typo, 99 );

		// The registrar deliberately does NOT drop it: registration runs during
		// wp_abilities_api_init and a legitimate name may not resolve yet. The
		// adapter skips unresolvable names on its own; only the REPORT has to
		// exclude them, and by then every registration has run.
		$this->assertContains( 'elementor-mcp/lst-pages', $exposed, 'Registration must not second-guess names that may resolve later.' );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		$this->assertSame( 1, $report['abilities']['exposed'] );
		$this->assertSame( 1, $report['abilities']['registered'] );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * Registration runs during wp_abilities_api_init, and another plugin may
	 * register its hook-added ability later in that same action. Resolving at
	 * registration time would discard a perfectly legitimate name; the report
	 * resolves instead, long after every registration has run.
	 */
	public function test_an_ability_registered_after_us_is_not_discarded(): void {
		$late = static function ( array $names ) {
			$names[] = 'other-plugin/registers-later';
			return $names;
		};
		add_filter( 'elementor_mcp_ability_names', $late, 99 );

		$exposed = $this->registrar_registering( array( 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $late, 99 );

		$this->assertContains( 'other-plugin/registers-later', $exposed, 'A name that has not registered YET must not be dropped at registration time.' );

		// It registers after us, as a priority-20 callback would.
		$GLOBALS['_abilities']['other-plugin/registers-later'] = (object) array( 'name' => 'other-plugin/registers-later' );

		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();

		$this->assertSame( 2, $report['abilities']['exposed'], 'By report time it resolves, so it counts.' );

		\Elementor_MCP_Ability_Registrar::__set_names_for_test( array(), array() );
	}

	/**
	 * Anything can come back from a public hook. A callback appending an object
	 * or array would make array_unique() throw "Object could not be converted
	 * to string" and abort registration — a third party taking down the whole
	 * tool surface, which is the failure mode the guarded loader exists to
	 * prevent.
	 */
	public function test_a_callback_returning_junk_does_not_abort_registration(): void {
		$junk = static function ( array $names ) {
			$names[] = (object) array( 'not' => 'a name' );
			$names[] = array( 'nor', 'this' );
			$names[] = 'elementor-mcp/still-fine';
			return $names;
		};
		add_filter( 'elementor_mcp_ability_names', $junk, 99 );

		$exposed = $this->registrar_registering( array( 'elementor-mcp/list-pages' ) )->register_all();

		remove_filter( 'elementor_mcp_ability_names', $junk, 99 );

		$this->assertContains( 'elementor-mcp/list-pages', $exposed, 'Our own tools must survive a badly-behaved callback.' );
		$this->assertContains( 'elementor-mcp/still-fine', $exposed, 'The valid part of that callback still counts.' );

		foreach ( $exposed as $name ) {
			$this->assertIsString( $name, 'Only names reach the adapter.' );
		}

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

	public function test_server_info_reports_rules_from_siteagent(): void {
		$GLOBALS['_aura_rules'] = array(
			'current' => array( 'seq' => 9, 'client' => 'c1', 'received_at' => 1800000000, 'envelope' => 'SECRET.SIG', 'rules' => array( array( 'key' => 'rule/freeze' ) ) ),
		);
		\Elementor_MCP_Rules::reset_state();
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		unset( $GLOBALS['_aura_rules'] );

		$this->assertSame(
			array( 'enforced' => true, 'source' => 'siteagent', 'state' => 'ready', 'ruleset' => array( 'seq' => 9, 'rule_count' => 1, 'received_at' => 1800000000 ), 'points' => array( 'governed_write' ) ),
			$report['rules']
		);
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $report ) );
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString( 'no operator rules', $note, 'A site that enforces is not told it does not.' );
		}
	}

	public function test_server_info_names_an_incomplete_siteagent_as_refusing_not_as_absent(): void {
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Without_Enforce::class );
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Rules::reset_state();
		$this->assertSame( 'incomplete', $report['rules']['state'] );
		$this->assertNotEmpty( array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'refused' ); } ) );
	}

	public function test_server_info_says_so_when_no_rules_can_be_enforced(): void {
		\Elementor_MCP_Rules::reset_state( false ); // fork-only
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Rules::reset_state();

		$this->assertSame( array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() ), $report['rules'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'no operator rules' ); } ),
			'Spec §6: fork-only means no rules, and server-info reports that.'
		);
	}

	public function test_server_info_reports_no_enforcement_when_the_governance_wrapper_is_not_live(): void {
		// Important #1 (fork final-review): Elementor_MCP_Rules::report() only
		// speaks to whether SiteAgent's rules engine is installed and reachable —
		// it never asks whether THIS plugin's own governance wrapper actually
		// calls into it (wrap_ability() no-ops entirely when SiteAgent's snapshot
		// engine is absent). A ready ruleset must not be reported as `enforced:
		// true` on a site where nothing ever wraps a write to ask it.
		$GLOBALS['_aura_rules'] = array(
			'current' => array( 'seq' => 9, 'client' => 'c1', 'received_at' => 1800000000, 'rules' => array( array( 'key' => 'rule/freeze' ) ) ),
		);
		\Elementor_MCP_Rules::reset_state();
		\Elementor_MCP_Governance::reset_state( null, false ); // wrapper not live
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Governance::reset_state();
		unset( $GLOBALS['_aura_rules'] );

		$this->assertFalse( $report['rules']['enforced'] );
		$this->assertSame( array(), $report['rules']['points'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'governance wrapper is not active' ); } ),
			'A ready ruleset with no live wrapper to enforce it must say so.'
		);
	}

	public function test_server_info_reports_enforced_true_for_a_reader_that_throws_when_the_wrapper_is_live(): void {
		// Codex round 2: current() throwing is a READER failure, not an enforcer
		// one — Elementor_MCP_Rules::enforce() calls the engine's enforce()
		// independently of current(), so a real write is still decided by
		// SiteAgent. The report must say `enforced: true` and give the
		// read-failure note, never the refusal note that belongs to
		// enforce_missing / bridge_missing / absent / wrapper-not-live.
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Whose_Reader_Throws::class );
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Rules::reset_state();

		$this->assertTrue( $report['rules']['enforced'] );
		$this->assertSame( 'incomplete', $report['rules']['state'] );
		$this->assertSame( 'reader_failed', $report['rules']['reason'] );
		$this->assertSame( array( 'governed_write' ), $report['rules']['points'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'could not be read' ); } ),
			'The read-failure note, not the refusal note.'
		);
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString(
				'writes through this plugin are refused',
				$note,
				'Writes are still decided — this is not the enforce_missing refusal note.'
			);
		}
	}

	public function test_server_info_reports_enforced_false_for_a_reader_that_throws_when_the_wrapper_is_not_live(): void {
		// Same broken engine as above, but the governance wrapper is not live
		// (Important #1's seam) — nothing reaches ANY gate at all, so `enforced`
		// must be false and the note must be the wrapper-not-live one, not the
		// read-failure note (which would wrongly imply writes are still
		// decided). Liveness has to be checked FIRST, before `state`.
		\Elementor_MCP_Rules::reset_state( null, \Elementor_MCP_Test_Engine_Whose_Reader_Throws::class );
		\Elementor_MCP_Governance::reset_state( null, false );
		$report = ( new \Elementor_MCP_Server_Info_Abilities() )->execute_server_info();
		\Elementor_MCP_Governance::reset_state();
		\Elementor_MCP_Rules::reset_state();

		$this->assertFalse( $report['rules']['enforced'] );
		$this->assertSame( array(), $report['rules']['points'] );
		$this->assertNotEmpty(
			array_filter( $report['notes'], static function ( $n ) { return false !== strpos( $n, 'governance wrapper is not active' ); } )
		);
		foreach ( $report['notes'] as $note ) {
			$this->assertStringNotContainsString( 'could not be read', $note, 'The not-live note wins over the read-failure note.' );
		}
	}
}
