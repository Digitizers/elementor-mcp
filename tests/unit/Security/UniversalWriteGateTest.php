<?php
/**
 * Security — the transport gate that does not depend on SiteAgent.
 *
 * Two guards shipped in 1.30.0. Neither covers a **fork-only** site on its own:
 *
 * - The registration shield keeps write tools out of another server's menu, but
 *   only for as long as that server keeps honouring `meta.mcp.type`. Angie 1.1.12
 *   compares the raw value; the bundled adapter's own helper coerces anything
 *   outside `tool|resource|prompt` back to `tool`. If Angie ever adopts that
 *   helper, `private` silently becomes `tool` and the shield reopens.
 * - The execution guard refuses an ungranted foreign write — but it lives in
 *   `Governance::run_governed()`, and `wrap_ability()` returns every ability
 *   untouched when SiteAgent's snapshot engine is absent. On a fork-only site it
 *   never runs at all.
 *
 * So on the sites this fork is meant to run on without SiteAgent, the whole
 * protection rested on another plugin continuing to honour a convention. This
 * gate removes that dependency: it runs at the permission stage, on every site,
 * for every write-capable ability.
 *
 * @group security
 * @group governance
 * @package Elementor_MCP\Tests\Security
 */

namespace Elementor_MCP\Tests\Security;

use PHPUnit\Framework\TestCase;

class UniversalWriteGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Elementor_MCP_Call_Context::reset();
		$GLOBALS['_filters'] = array();
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
	}

	protected function tearDown(): void {
		\Elementor_MCP_Call_Context::reset();
		$GLOBALS['_filters'] = array();
		unset( $_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] );
		parent::tearDown();
	}

	private function arrive_on( string $route ): void {
		\Elementor_MCP_Call_Context::init();
		apply_filters( 'rest_pre_dispatch', null, null, new \WP_REST_Request( array(), null, $route ) );
	}

	/** Args for a write ability whose own permission callback records that it ran. */
	private function write_args( ?bool &$inner_ran = null, bool $inner_allows = true ): array {
		$inner_ran = false;
		return array(
			'label'               => 'Update element',
			'execute_callback'    => static fn() => array( 'ok' => true ),
			'permission_callback' => function () use ( &$inner_ran, $inner_allows ) {
				$inner_ran = true;
				return $inner_allows;
			},
			'meta'                => array(
				'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
			),
		);
	}

	private function permission_of( array $args ) {
		$gated = \Elementor_MCP_Call_Context::gate_write_permission( $args );
		return call_user_func( $gated['permission_callback'] );
	}

	// --- what the gate refuses ----------------------------------------------

	public function test_a_foreign_transport_is_refused_before_the_ability_is_reached(): void {
		$this->arrive_on( '/mcp/angie' );
		$args   = $this->write_args( $inner_ran );

		$result = $this->permission_of( $args );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'untrusted_transport', $result->get_error_code() );
		$this->assertFalse( $inner_ran, 'Refused at the transport, before the capability check even runs.' );
		$this->assertStringContainsString( '/mcp/angie', $result->get_error_message() );
	}

	public function test_the_refusal_carries_a_403_so_a_client_sees_a_denial_not_a_crash(): void {
		$this->arrive_on( '/mcp/angie' );

		$result = $this->permission_of( $this->write_args() );
		$data   = $result->get_error_data();

		$this->assertSame( 403, $data['status'] ?? null );
	}

	public function test_a_grant_header_lets_a_foreign_transport_past_the_gate(): void {
		// Presence only — the signature, binding and single-use nonce are
		// checked once, later, by the governance layer.
		$this->arrive_on( '/mcp/angie' );
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = 'header.signature';
		$args = $this->write_args( $inner_ran );

		$this->assertTrue( $this->permission_of( $args ) );
		$this->assertTrue( $inner_ran, 'And the ability\'s own capability check still runs.' );
	}

	public function test_the_grant_is_not_verified_here(): void {
		// Verifying twice would burn the grant's single-use nonce and reject the
		// second check — the legitimate call would fail on its own approval.
		$GLOBALS['_aura_grant'] = array( 'enforced' => true, 'verify_result' => true, 'verify_calls' => array() );
		$this->arrive_on( '/mcp/angie' );
		$_SERVER['HTTP_X_AURA_APPROVAL_GRANT'] = 'header.signature';

		$this->permission_of( $this->write_args() );

		$this->assertSame( array(), $GLOBALS['_aura_grant']['verify_calls'] );
	}

	// --- what the gate lets through -----------------------------------------

	public function test_our_own_server_route_is_untouched(): void {
		$this->arrive_on( \Elementor_MCP_Call_Context::own_server_route() );
		$args = $this->write_args( $inner_ran );

		$this->assertTrue( $this->permission_of( $args ) );
		$this->assertTrue( $inner_ran );
	}

	public function test_a_non_rest_context_is_untouched(): void {
		$args = $this->write_args( $inner_ran );

		$this->assertTrue( $this->permission_of( $args ) );
		$this->assertTrue( $inner_ran );
	}

	public function test_the_abilitys_own_denial_still_wins(): void {
		// The gate wraps rather than replaces, and the capability check runs
		// LAST — so this can only ever deny more than before, never less.
		$this->arrive_on( \Elementor_MCP_Call_Context::own_server_route() );
		$args = $this->write_args( $inner_ran, false );

		$this->assertFalse( $this->permission_of( $args ) );
		$this->assertTrue( $inner_ran );
	}

	public function test_read_only_abilities_are_not_gated_at_all(): void {
		// The entire point of the read-only bridge is that an assistant on
		// another server can answer questions from this plugin's data.
		$this->arrive_on( '/mcp/angie' );
		$args                                = $this->write_args( $inner_ran );
		$args['meta']['annotations']['readonly'] = true;

		$gated = \Elementor_MCP_Call_Context::gate_write_permission( $args );

		$this->assertSame( $args, $gated, 'Untouched — not even wrapped.' );
	}

	public function test_an_unclassified_ability_is_not_gated(): void {
		// One definition of "writes" across the shield, this gate and the
		// governance wrapper: an explicit readonly=false. An ability that never
		// classified itself is not known to write, and gating a read tool costs
		// a real capability.
		$this->arrive_on( '/mcp/angie' );
		$args = $this->write_args();
		unset( $args['meta'] );

		$this->assertSame( $args, \Elementor_MCP_Call_Context::gate_write_permission( $args ) );
	}

	public function test_the_three_guards_agree_on_what_a_write_is(): void {
		// Guards that disagree leave gaps exactly where they overlap.
		$write = $this->write_args();
		$read  = $this->write_args();
		$read['meta']['annotations']['readonly'] = true;

		$this->assertTrue( \Elementor_MCP_Call_Context::ability_writes( $write ) );
		$this->assertFalse( \Elementor_MCP_Call_Context::ability_writes( $read ) );

		// The shield and the governance wrapper classify the same way.
		\Elementor_MCP_Call_Context::reset();
		\Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $write, 'x/write' );
		$this->assertSame( array( 'x/write' ), \Elementor_MCP_Call_Context::writes_seen() );

		\Elementor_MCP_Call_Context::reset();
		\Elementor_MCP_Call_Context::shield_write_from_foreign_servers( $read, 'x/read' );
		$this->assertSame( array(), \Elementor_MCP_Call_Context::writes_seen() );

		$wrapped = \Elementor_MCP_Governance::wrap_ability( 'x/read', $read );
		$this->assertSame( $read['execute_callback'], $wrapped['execute_callback'], 'Governance leaves reads alone too.' );
	}

	// --- the fork-only case, which is the whole reason for this gate ---------

	public function test_a_site_with_no_grant_verifier_refuses_a_foreign_write_outright(): void {
		// The fork-only install: no SiteAgent, so nothing on the site could
		// validate a grant even if one arrived. Accepting the attempt on an
		// unverifiable header would be worse than refusing it, and this is the
		// exact combination a running suite cannot reach through the class
		// itself — hence the injected facts.
		$this->assertSame(
			'no_verifier',
			\Elementor_MCP_Call_Context::write_permission_decision( false, false, false )
		);
		$this->assertSame(
			'no_verifier',
			\Elementor_MCP_Call_Context::write_permission_decision( false, false, true ),
			'A header nothing can check is not a credential.'
		);
	}

	public function test_the_decision_covers_every_combination(): void {
		$decide = array( \Elementor_MCP_Call_Context::class, 'write_permission_decision' );

		// Trusted transport: nothing else matters.
		$this->assertNull( $decide( true, false, false ) );
		$this->assertNull( $decide( true, true, false ) );

		// Foreign, verifier present: the grant decides.
		$this->assertSame( 'no_grant', $decide( false, true, false ) );
		$this->assertNull( $decide( false, true, true ) );
	}

	// --- the site this exists for -------------------------------------------

	public function test_it_runs_at_the_real_registration_seam(): void {
		// The gate is worthless if the seam forgets to apply it — which is how
		// the fork-only site was left exposed in the first place.
		$this->arrive_on( '/mcp/angie' );
		$GLOBALS['_registered_abilities'] = array();

		elementor_mcp_register_ability( 'elementor-mcp/update-element', $this->write_args( $inner_ran ) );

		$registered = $GLOBALS['_registered_abilities']['elementor-mcp/update-element'];
		$result     = call_user_func( $registered['permission_callback'] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'untrusted_transport', $result->get_error_code() );
		$this->assertFalse( $inner_ran );
	}
}
