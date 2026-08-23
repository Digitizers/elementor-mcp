<?php
/**
 * Operator-rules bridge to SiteAgent (P4.1 plan 3, fork 1.32.0).
 *
 * SiteAgent holds the client's signed ruleset and owns the matcher
 * (\Aura_Worker_Rules). This class is the fork's one seam onto it: a governed
 * Elementor write declares what it touches, SiteAgent decides, and this class
 * translates the verdict into what the governance wrapper returns. It never
 * re-implements matching, never reads the ruleset option itself, and never
 * hard-requires SiteAgent: without it there are no rules and no policy, and
 * `server-info` says so.
 *
 * Two failure modes, deliberately different:
 *  - SiteAgent ABSENT  → nothing to enforce (spec §6: "fork-only means no rules").
 *  - SiteAgent BROKEN  → fail closed. A matcher that throws, or answers with
 *    something that is not a verdict, may be hiding a block; a write under an
 *    unknown verdict is refused with its own code so the cause is visible.
 *
 * @since 1.32.0
 * @package Elementor_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor_MCP_Rules {

	/** The one enforcement point this plugin adds, as reported by server-info. */
	const POINT = 'governed_write';

	/** @var bool|null Test-only override of available(). */
	private static $available_override = null;

	/** @var string The engine class. Tests swap it for a class missing a method. */
	private static $engine = '\\Aura_Worker_Rules';

	/**
	 * Is SiteAgent's rules engine installed? The CLASS existing is the whole
	 * question: a class that is there with a method that is not is an
	 * installed-but-broken engine, and complete() decides what that means.
	 *
	 * @since 1.32.0
	 * @return bool
	 */
	public static function available(): bool {
		if ( null !== self::$available_override ) {
			return self::$available_override;
		}
		return class_exists( self::$engine );
	}

	/**
	 * Does the installed engine expose the API this bridge needs? False on a
	 * partial or broken update — and that is never "no policy": enforce()
	 * refuses, report() says `incomplete`.
	 *
	 * Requires BOTH `enforce()` and `current()` (controller ruling, reaffirmed
	 * after Codex round 2 briefly narrowed enforce()'s own gate to just
	 * `enforce()` in commit fb6701d — reverted): the plan's global constraint is
	 * explicit that a class missing EITHER method is refused with
	 * `aura_rules_unavailable`, not partially trusted. report() still uses this
	 * to distinguish `enforce_missing` from `current_missing` in its `reason`.
	 *
	 * @since 1.32.0
	 * @return string '' when complete, else the missing method's name.
	 */
	private static function missing_method(): string {
		foreach ( array( 'enforce', 'current' ) as $m ) {
			if ( ! method_exists( self::$engine, $m ) ) {
				return $m;
			}
		}
		return '';
	}

	/**
	 * What a page write touches: the same id as a post AND as a page, because an
	 * operator writing "do not touch checkout" does not know which one it is.
	 *
	 * @since 1.32.0
	 * @param int $post_id Post id.
	 * @return array<int,array{type:string,id:string}>
	 */
	public static function page_touches( int $post_id ): array {
		$id = (string) absint( $post_id );
		return array(
			array( 'type' => 'post', 'id' => $id ),
			array( 'type' => 'page', 'id' => $id ),
		);
	}

	/**
	 * What a design-system write touches: the whole site. Kit variables, global
	 * colours and global classes change every page and have no narrower resource
	 * an operator would write a rule about.
	 *
	 * @since 1.32.0
	 * @return array<int,array{type:string,id:string}>
	 */
	public static function site_touches(): array {
		return array( array( 'type' => 'site', 'id' => '*' ) );
	}

	/**
	 * Ask SiteAgent whether a rule decides this write.
	 *
	 * Requires BOTH `enforce()` and `current()` to exist (controller ruling —
	 * the plan's global constraint: a class missing either method is a partial
	 * or broken SiteAgent update, refused with `aura_rules_unavailable`, not
	 * partially trusted just because the piece THIS call happens to need is
	 * present). A Codex round 2 pass briefly narrowed this to just `enforce()`
	 * — reverted; report() carries the finer-grained `current_missing` /
	 * `reader_failed` distinction on its OWN side instead (see report()).
	 *
	 * @since 1.32.0
	 * @param array  $touches Declaration, from page_touches() / site_touches().
	 * @param string $name    Ability name, for SiteAgent's forensic hooks.
	 * @return array {effect: null|'warn'|'block'|'unavailable', rule?: array, error?: string}
	 */
	public static function enforce( array $touches, string $name ): array {
		if ( ! self::available() ) {
			return array( 'effect' => null );
		}
		$missing = self::missing_method();
		if ( '' !== $missing ) {
			return array( 'effect' => 'unavailable', 'error' => sprintf( 'SiteAgent is installed but Aura_Worker_Rules::%s() is missing', $missing ) );
		}
		try {
			$verdict = call_user_func( array( self::$engine, 'enforce' ), $touches, $name );
		} catch ( \Throwable $e ) {
			return array( 'effect' => 'unavailable', 'error' => $e->getMessage() );
		}
		if ( ! is_array( $verdict ) || ! array_key_exists( 'effect', $verdict ) ) {
			return array( 'effect' => 'unavailable', 'error' => 'SiteAgent returned no verdict' );
		}
		$effect = $verdict['effect'];
		if ( null === $effect ) {
			return array( 'effect' => null );
		}
		if ( ( 'block' === $effect || 'warn' === $effect ) && isset( $verdict['rule'] ) && is_array( $verdict['rule'] ) ) {
			return array( 'effect' => $effect, 'rule' => $verdict['rule'] );
		}
		return array( 'effect' => 'unavailable', 'error' => 'SiteAgent returned an unrecognisable verdict' );
	}

	/**
	 * The refusal for a block. Says plainly that approval does not help, so
	 * nobody goes looking for a grant bug.
	 *
	 * @since 1.32.0
	 * @param string $name Ability name.
	 * @param array  $rule Deciding rule.
	 * @return \WP_Error
	 */
	public static function blocked_error( string $name, array $rule ): \WP_Error {
		// Error data names the rule by `key` (the contract callers and the status
		// route read); warning_entry()'s `{rule, reason}` is the body shape for
		// warnings and is NOT reused here.
		$key    = isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?';
		$reason = isset( $rule['reason'] ) ? (string) $rule['reason'] : '';
		return new \WP_Error(
			'aura_rule_blocked',
			sprintf(
				/* translators: 1: tool name, 2: rule key, 3: " (reason)" or empty */
				__( '%1$s is blocked by %2$s%3$s — approval does not override a rule; release the rule first.', 'elementor-mcp' ),
				$name,
				$key,
				'' === $reason ? '' : ' (' . $reason . ')'
			),
			array( 'status' => 403, 'rule' => array( 'key' => $key, 'reason' => $reason ) )
		);
	}

	/**
	 * The refusal when SiteAgent is installed but could not decide.
	 *
	 * @since 1.32.0
	 * @param string $name Ability name.
	 * @param string $why  What went wrong.
	 * @return \WP_Error
	 */
	public static function unavailable_error( string $name, string $why ): \WP_Error {
		return new \WP_Error(
			'aura_rules_unavailable',
			sprintf(
				/* translators: 1: tool name, 2: error */
				__( 'Refusing %1$s: SiteAgent could not evaluate operator rules (%2$s). A write under an unknown verdict is not taken.', 'elementor-mcp' ),
				$name,
				$why
			),
			array( 'status' => 503 )
		);
	}

	/**
	 * @since 1.32.0
	 * @param array $rule Matched rule.
	 * @return array{rule:string, reason:string}
	 */
	public static function warning_entry( array $rule ): array {
		return array(
			'rule'   => isset( $rule['key'] ) ? (string) $rule['key'] : 'rule/?',
			'reason' => isset( $rule['reason'] ) ? (string) $rule['reason'] : '',
		);
	}

	/**
	 * What server-info says about rules on this site. Never includes the
	 * envelope or its signature — seq, count and age are the facts an operator
	 * needs, and the fleet reads the rest from SiteAgent's audit_rules.
	 *
	 * `incomplete` covers THREE distinguishable failures, given by `reason`
	 * (Codex round 2 of the fork final-review's fix wave; controller-corrected
	 * after that round briefly changed the GATE itself — reverted, see
	 * enforce()). The gate (missing_method(), requiring BOTH enforce() and
	 * current()) is the plan's global constraint and does not change: a class
	 * missing either method refuses every write with `aura_rules_unavailable`.
	 * report() mirrors that for `enforce_missing` and `current_missing` — both
	 * `enforced: false` — but distinguishes `reader_failed`: current() EXISTS
	 * (missing_method() found nothing missing) but THROWS when called; the gate
	 * never calls current() at all, so enforce() still decides every write —
	 * only THIS report's read of the ruleset failed, hence `enforced: true`.
	 * This method is deliberately wrapper-agnostic: `enforced` here means "the
	 * gate would let this through", not "something is live to ask the gate" —
	 * the caller (server-info's assembly) is the one that knows whether
	 * Elementor_MCP_Governance is active, and downgrades `enforced`/`points`
	 * for its own report when it is not; this class never depends on
	 * Elementor_MCP_Governance.
	 *
	 * @since 1.32.0
	 * @since 1.32.0 `reason` on `state: 'incomplete'` (Codex round 2, corrected).
	 * @return array{enforced:bool, source:string, state:string, reason?:string, ruleset:?array, points:string[]}
	 */
	public static function report(): array {
		if ( ! self::available() ) {
			return array( 'enforced' => false, 'source' => 'none', 'state' => 'absent', 'ruleset' => null, 'points' => array() );
		}
		$missing = self::missing_method();
		if ( 'enforce' === $missing ) {
			// enforce() itself is gone: nothing decides ANY write. Not "enforced"
			// in any sense server-info promises, and not "absent" either — the
			// operator needs to see this is a broken install, not no install.
			return array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'incomplete', 'reason' => 'enforce_missing', 'ruleset' => null, 'points' => array() );
		}
		if ( 'current' === $missing ) {
			// current() missing means the GATE (missing_method(), requiring both
			// methods) refuses too — real writes get `aura_rules_unavailable`
			// just like enforce_missing. Not "ready", and not conflated with
			// reader_failed below (current() present but throwing), which does
			// NOT trip the gate.
			return array( 'enforced' => false, 'source' => 'siteagent', 'state' => 'incomplete', 'reason' => 'current_missing', 'ruleset' => null, 'points' => array() );
		}
		$rec = null;
		try {
			$rec = call_user_func( array( self::$engine, 'current' ) );
		} catch ( \Throwable $e ) {
			// current() EXISTS (missing_method() found nothing missing) but
			// throws when called. The gate (enforce()) never calls current(), so
			// a real write is still decided normally — only THIS report's read
			// of the ruleset failed.
			return array( 'enforced' => true, 'source' => 'siteagent', 'state' => 'incomplete', 'reason' => 'reader_failed', 'ruleset' => null, 'points' => array( self::POINT ), 'error' => $e->getMessage() );
		}
		$ruleset = null;
		if ( is_array( $rec ) && isset( $rec['seq'], $rec['rules'] ) && is_array( $rec['rules'] ) ) {
			$ruleset = array(
				'seq'         => (int) $rec['seq'],
				'rule_count'  => count( $rec['rules'] ),
				'received_at' => isset( $rec['received_at'] ) ? (int) $rec['received_at'] : 0,
			);
		}
		return array(
			'enforced' => true,
			'source'   => 'siteagent',
			'state'    => 'ready',
			'ruleset'  => $ruleset,
			'points'   => array( self::POINT ),
		);
	}

	/**
	 * Test hook: force availability (false = a fork-only site), swap the engine
	 * class (a class missing a method = a broken install), or clear both (null).
	 *
	 * @since 1.32.0
	 * @param bool|null   $available_override Override, or null to read the real state.
	 * @param string|null $engine             Engine class name, or null for SiteAgent's.
	 */
	public static function reset_state( ?bool $available_override = null, ?string $engine = null ): void {
		self::$available_override = $available_override;
		self::$engine             = null === $engine ? '\\Aura_Worker_Rules' : $engine;
	}
}
