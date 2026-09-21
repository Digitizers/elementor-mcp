<?php
/**
 * Collateral diff for a governed page write (P5.1, elementor-mcp#67).
 *
 * Every other check in the write path asks "did my change land?". This one
 * asks "did anything ELSE change?" — the question Respira's builder-type
 * guard exists for (8.6.33 / 8.8.12), where every known incident came from
 * the writer's own serialiser retyping or emptying nodes the caller never
 * addressed, with ids, order and stored text intact so nothing complained.
 *
 * Elementor lets us ask it more precisely than a text-containment check can:
 * every node carries a stable `id` and structured JSON settings, so nodes are
 * matched by id and compared for equality. Four trees take part — two of them
 * are the same requested tree either side of the coercion, for the reason given
 * under "not landed" below:
 *
 *   - BEFORE     — `_elementor_data` as stored before the save. Elementor wrote
 *                  it, so it is already in Elementor's normalised form.
 *   - REQUESTED  — the tree the tool handed to save_page_data(), BEFORE
 *                  Atomic_Props::coerce_tree(). It is the before tree plus the
 *                  tool's own mutations, so the nodes whose payload differs
 *                  between the two are, by construction, the TARGETS of the
 *                  write. Nothing has to declare them.
 *   - COERCED    — the same tree AFTER coerce_tree(): what was actually handed
 *                  to Elementor to persist. Used only to judge whether a
 *                  target's settings landed, never to derive targets.
 *   - PERSISTED  — `_elementor_data` re-read after the save.
 *
 * Collateral = an untargeted node (payload identical in BEFORE and REQUESTED)
 * whose PERSISTED payload is not identical to its BEFORE payload, or which is
 * gone. Comparing before-vs-persisted rather than requested-vs-persisted is
 * what keeps Elementor's save-time normalisation out of the verdict: the
 * before tree already went through it. What can still differ on an untouched
 * node is exactly what should be surfaced — a save-time migration after an
 * Elementor upgrade, coerce_tree() repairing a prop the tool never touched, or
 * one of this plugin's own normalisers reaching a sibling.
 *
 * A node's payload is everything EXCEPT its `elements` children. Otherwise a
 * targeted child would make every ancestor "targeted" (their own settings
 * would go unchecked) and a deleted target would make its parent look damaged.
 *
 * Not landed = a targeted node present in COERCED and PERSISTED whose coerced
 * `settings` carry a key that is absent after the save. Only ABSENCE is
 * reported: a value Elementor rewrote into its canonical shape is normal, and
 * value-equality here would be the false positive save_page_data()'s
 * projection check already declines to make. A key that was dropped is field
 * report #4's class — the write persisted, the tool said success, and the
 * setting was never there.
 *
 * COERCED rather than REQUESTED for this one question, and the split is the
 * whole point of carrying both trees: `apply_prop_aliases()` deliberately
 * renames an advertised alias onto its canonical prop and REMOVES the alias
 * key, so a tool that legitimately writes `content` on a widget whose schema
 * aliases it to `text` has no `content` key after the save — reading the
 * pre-coercion keys would call every aliased write a dropped setting, and in
 * `refuse` mode revert it (Codex round-1 P2). Targets stay on REQUESTED for
 * the mirror-image reason: a prop coerce_tree() repaired on a node the tool
 * never touched must read as collateral, not be absorbed into the targets.
 *
 * DECLARED intent (P5.4). Derived targets answer "what did the tool change?".
 * They cannot answer "was the tool SUPPOSED to change that?" — anything the
 * tool itself touched becomes a target by construction, so an ability that
 * quietly rewrites an unrelated node is invisible to every check above: the
 * save was faithful, so there is no collateral, and the node is a target, so
 * it is not checked. That is tool-side over-reach, and the only way to see it
 * is for the write to say up front what it meant to touch. So save_page_data()
 * may carry a DECLARATION, and the report names the derived targets that fall
 * outside it (`undeclared`). Two scopes:
 *
 *   - `array( 'scope' => 'targeted', 'ids' => array( '<id>', … ) )` — see the
 *     coverage rule below.
 *   - `array( 'scope' => 'document' )` — the whole page was the subject
 *     (build-page, import-template, delete-page-content). Nothing can be
 *     outside it, so `undeclared` is always empty.
 *
 * COVERAGE, for `targeted`. A node is covered when its own id was declared, or
 * when a declared id is one of its ancestors — and WHICH tree's ancestors count
 * depends on where the node lives:
 *
 *   - present in BOTH trees — both ancestries must place it inside the
 *     declaration. Either one alone lets a relocation launder the coverage: a
 *     write that declares X and then STEALS an unrelated node into X, or EVICTS
 *     one out of X, is covered on exactly one side of the move, and "either
 *     side" would report nothing. That is the shape a hijack takes.
 *   - present only in BEFORE (removed) — the before ancestry, the only tree
 *     that still places it. So removing a declared container covers the
 *     children that went with it.
 *   - present only in REQUESTED (added) — the requested ancestry, likewise. So
 *     a node added under a declared parent is covered by that parent.
 *
 * WHAT IS JUDGED, and what is not. The derived targets are judged, plus nodes
 * that merely MOVED — present in both trees under a different parent id. A move
 * changes no payload, so it is never a derived target and no amount of diffing
 * will make it one; but taking a node out of a container the write never named
 * is precisely the over-reach this check exists for. So `undeclared` sees
 * payload edits, additions, removals and re-parenting. It does NOT see a
 * REORDER among siblings under one parent: that changes no payload and no
 * ancestry, so nothing here can distinguish it from the page standing still.
 * Said plainly rather than left for someone to discover.
 *
 * DUPLICATE IDS. Coverage is decided from a node's own path as the tree is
 * walked, never by climbing an id => parent map afterwards, so a node under the
 * first of two containers sharing an id is judged by the container it is really
 * in. A duplicate therefore never launders an unrelated subtree into the
 * declaration, and never shuts a legitimate one out of it either.
 *
 * What the duplicate costs depends on WHO made it, and the two cases are not
 * alike:
 *
 *   - INHERITED — already ambiguous in the BEFORE tree. The page was corrupt
 *     before this write touched it; which node is which was never knowable, so
 *     the duplicated node ITSELF is never reported. That is the one thing here
 *     that stays invisible, and it is invisible on purpose: index() refuses to
 *     compare its payload for the same reason, and accusing an ordinary
 *     remove-element of over-reach because the page it was handed already had
 *     a repeated id would be a guard punishing the wrong party. Everything
 *     under it is judged normally, by path.
 *
 *     KNOWN RESIDUAL, stated rather than left to be found: when the DECLARED
 *     id is the one already duplicated in BEFORE, every occurrence of it still
 *     confers coverage — including an occurrence this write ADDS. So a write
 *     declaring X, on a page that already carries two nodes with id X, can
 *     append a third X and park new content under it unreported. It is not
 *     closed because the only way to close it is to stop an inherited
 *     duplicate from conferring coverage at all, which re-opens the false
 *     positive this whole rule exists to prevent: an ordinary remove-element
 *     of an inherited-duplicated container would accuse itself of over-reach.
 *     The precondition is narrow — the page must ALREADY carry two nodes
 *     bearing the exact id being edited — and it is specific to the declared
 *     id: a non-declared inherited-ambiguous id confers nothing, so content
 *     added under a further copy of one IS reported.
 *   - INTRODUCED — unique or absent in BEFORE, ambiguous in REQUESTED. THIS
 *     write made it, so it buys nothing. A declared id that was forged this way
 *     confers no coverage on anything beneath any of its occurrences, and earns
 *     no exemption for itself: it is reported, once, along with whatever was
 *     parked under it that no genuinely-declared ancestor covers. Otherwise a
 *     write could mint a second node carrying the declared id and smuggle a
 *     whole subtree in under it, or plant a decoy sharing an unrelated node's
 *     id purely to buy that node an exemption while rewriting it — and nothing
 *     else in the write path would notice either, because the save is faithful.
 *     A declaration anyone can mint mid-write declares nothing.
 *
 * Under `document` intent none of this applies: there is nothing to be outside
 * of, so `undeclared` stays empty however the ids fall.
 *
 * Declaring is optional and additive: a caller that passes nothing gets exactly
 * the report it got in 1.34.0, with `intent: 'undeclared'` and an empty list.
 * A declaration this class cannot read — an unknown scope, `targeted` without a
 * non-empty list of string ids — is treated as undeclared. It never throws and
 * never blocks a write: a malformed declaration is a bug in the caller, and
 * refusing the caller's write over it would be the guard failing the thing it
 * guards. Over-reach is reported, never refused (see Elementor_MCP_Governance).
 *
 * `compared_by` rides every report for the reason `comparable` does: nodes are
 * matched by id and ONLY by id — there is no path fallback — and no reader
 * should be able to infer a stronger method from silence.
 *
 * Pure: no WordPress, no I/O, no state. The verdict (warn / refuse / off) is
 * Elementor_MCP_Governance's, which is also where the report is recorded.
 *
 * @since 1.34.0
 * @since 1.36.0 Declared intent: `compared_by`, `intent` and `undeclared`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor_MCP_Collateral {

	/**
	 * Compare the three trees. Any of them may be null / not a list when the
	 * caller could not read it; the report then says what it could not check
	 * (`comparable` false) rather than passing the write off as verified.
	 *
	 * @param mixed $before    Tree stored before the save.
	 * @param mixed $requested Tree the tool asked to save (pre-coercion).
	 * @param mixed $persisted Tree stored after the save.
	 * @param mixed $coerced   The requested tree after coerce_tree() — what was
	 *                         handed to Elementor. Null (or not a list) falls
	 *                         back to $requested, which is correct wherever no
	 *                         coercion ran.
	 * @param mixed $intent    What the write DECLARED it would touch:
	 *                         `array( 'scope' => 'targeted', 'ids' => [ … ] )`,
	 *                         `array( 'scope' => 'document' )`, or null /
	 *                         malformed for undeclared. See the class docblock.
	 * @return array{
	 *   comparable: bool,
	 *   compared_by: string,
	 *   intent: string,
	 *   targets: list<string>,
	 *   undeclared: list<string>,
	 *   checked: int,
	 *   collateral: list<array{id:string,kind:string,type:string,path:string}>,
	 *   gained: list<array{id:string,type:string,path:string}>,
	 *   not_landed: list<array{id:string,missing:list<string>}>
	 * }
	 */
	public static function report( $before, $requested, $persisted, $coerced = null, $intent = null ): array {
		$declared = self::declaration( $intent );
		$report   = array(
			'comparable'  => false,
			'compared_by' => 'id',
			'intent'      => $declared['scope'],
			'targets'     => array(),
			'undeclared'  => array(),
			'checked'     => 0,
			'collateral'  => array(),
			'gained'      => array(),
			'not_landed'  => array(),
		);
		if ( ! is_array( $before ) || ! is_array( $requested ) || ! is_array( $persisted ) ) {
			return $report;
		}
		$report['comparable'] = true;

		$b = self::index( $before );
		$r = self::index( $requested );
		$p = self::index( $persisted );
		$c = is_array( $coerced ) ? self::index( $coerced ) : $r;

		// Targets: what the tool itself changed — present on one side only, or
		// with a different payload. Derived, never declared.
		$targets = array();
		foreach ( $b as $id => $node ) {
			if ( ! isset( $r[ $id ] ) || $r[ $id ]['payload'] !== $node['payload'] ) {
				$targets[ $id ] = true;
			}
		}
		foreach ( $r as $id => $node ) {
			if ( ! isset( $b[ $id ] ) ) {
				$targets[ $id ] = true;
			}
		}
		// (string) on every id: PHP turns an all-digit array key into an int, so
		// a list built from keys would silently change type on a page whose ids
		// happen to be numeric — and a consumer comparing with === would be
		// wrong on exactly those pages. Every id this report names is a string.
		foreach ( array_keys( $targets ) as $id ) {
			$report['targets'][] = (string) $id;
		}

		// Undeclared: what the write touched but never said it would touch —
		// tool-side over-reach, which nothing below can see because the save
		// itself was faithful. Only `targeted` can produce any: `document`
		// declared the whole page, and an undeclared write declared nothing for
		// anything to be outside of.
		if ( 'targeted' === $declared['scope'] ) {
			$before_tree    = self::scan( $before, $declared['ids'], array() );
			$requested_tree = self::scan( $requested, $declared['ids'], array() );

			// Ambiguity the page ALREADY had is tolerated; ambiguity THIS WRITE
			// created is not. See "DUPLICATE IDS" in the class docblock: without
			// the distinction, minting a second node with the declared id buys
			// both an exemption for the forgery and cover for everything parked
			// under it, and a declaration that can be minted at will declares
			// nothing. Costs a second scan of the requested tree, and only when
			// a write actually introduced a duplicate — rare, and never on the
			// path the declaring abilities take.
			$forged = array();
			foreach ( array_keys( $requested_tree['ambiguous'] ) as $id ) {
				$id = (string) $id;
				if ( ! isset( $before_tree['ambiguous'][ $id ] ) ) {
					$forged[ $id ] = true;
				}
			}
			if ( ! empty( $forged ) ) {
				$requested_tree = self::scan( $requested, $declared['ids'], $forged );
			}

			// The suspects are the derived targets, PLUS the nodes that moved —
			// see "WHAT IS JUDGED" in the class docblock for why a move has to
			// be added here rather than folded into how targets are derived —
			// PLUS every id this write made ambiguous, which is a finding in its
			// own right whether or not anything else noticed it.
			$suspects = array();
			foreach ( array_keys( $targets ) as $id ) {
				$suspects[ (string) $id ] = true;
			}
			foreach ( $before_tree['parents'] as $id => $was ) {
				$id = (string) $id;
				if ( isset( $suspects[ $id ] ) || ! isset( $requested_tree['parents'][ $id ] ) ) {
					continue; // already judged, or not present either side — not a move
				}
				if ( isset( $before_tree['ambiguous'][ $id ] ) || isset( $requested_tree['ambiguous'][ $id ] ) ) {
					continue; // an ambiguous id cannot prove it moved any more than it can prove it stayed
				}
				if ( $requested_tree['parents'][ $id ] !== $was ) {
					$suspects[ $id ] = true;
				}
			}
			foreach ( array_keys( $forged ) as $id ) {
				$suspects[ (string) $id ] = true;
			}

			foreach ( array_keys( $suspects ) as $id ) {
				$id = (string) $id;
				if ( ! self::within_declaration( $id, $before_tree, $requested_tree, $forged ) ) {
					$report['undeclared'][] = $id;
				}
			}
		}

		// Collateral: untargeted nodes that the pipeline changed or dropped.
		foreach ( $b as $id => $node ) {
			if ( isset( $targets[ $id ] ) ) {
				continue;
			}
			++$report['checked'];
			if ( ! isset( $p[ $id ] ) ) {
				$report['collateral'][] = array( 'id' => (string) $id, 'kind' => 'vanished', 'type' => $node['type'], 'path' => $node['path'] );
				continue;
			}
			if ( $p[ $id ]['type'] !== $node['type'] ) {
				$report['collateral'][] = array( 'id' => (string) $id, 'kind' => 'retyped', 'type' => $node['type'] . '→' . $p[ $id ]['type'], 'path' => $node['path'] );
				continue;
			}
			if ( $p[ $id ]['payload'] !== $node['payload'] ) {
				$report['collateral'][] = array( 'id' => (string) $id, 'kind' => 'changed', 'type' => $node['type'], 'path' => $node['path'] );
			}
		}

		// Gained: persisted nodes nobody asked for. Logged, not refused — an
		// adapter that legitimately wraps or splits a node produces one.
		foreach ( $p as $id => $node ) {
			if ( ! isset( $r[ $id ] ) && ! isset( $b[ $id ] ) ) {
				$report['gained'][] = array( 'id' => (string) $id, 'type' => $node['type'], 'path' => $node['path'] );
			}
		}

		// Not landed: a setting key that is absent after the save. Read from the
		// COERCED tree — see the class docblock: an alias the coercion renamed
		// onto its canonical prop is not a dropped setting.
		foreach ( array_keys( $targets ) as $id ) {
			if ( ! isset( $c[ $id ] ) || ! isset( $p[ $id ] ) ) {
				continue; // an intended delete, or a node Elementor dropped (the projection check owns that)
			}
			$wanted  = isset( $c[ $id ]['payload']['settings'] ) && is_array( $c[ $id ]['payload']['settings'] ) ? $c[ $id ]['payload']['settings'] : array();
			$got     = isset( $p[ $id ]['payload']['settings'] ) && is_array( $p[ $id ]['payload']['settings'] ) ? $p[ $id ]['payload']['settings'] : array();
			$missing = array();
			foreach ( array_keys( $wanted ) as $key ) {
				if ( ! array_key_exists( $key, $got ) ) {
					$missing[] = (string) $key;
				}
			}
			if ( ! empty( $missing ) ) {
				$report['not_landed'][] = array( 'id' => (string) $id, 'missing' => $missing );
			}
		}

		return $report;
	}

	/**
	 * Whether the report carries anything a warn or refuse should act on.
	 *
	 * `undeclared` is deliberately NOT one of them: tool-side over-reach is
	 * warn-only in this release, so it must never be what reverts a write.
	 * Governance asks for it separately (see run_governed()).
	 *
	 * @param array $report Output of report().
	 * @return bool
	 */
	public static function has_findings( array $report ): bool {
		return ! empty( $report['collateral'] ) || ! empty( $report['not_landed'] );
	}

	/**
	 * One line for the warning a non-empty `undeclared` raises: names the ids,
	 * capped as summarize() caps its examples, and says what makes them worth
	 * reading — the TOOL changed them, not the save.
	 *
	 * @since 1.36.0
	 * @param array $report Output of report().
	 * @return string Empty when there is nothing to say.
	 */
	public static function summarize_undeclared( array $report ): string {
		if ( empty( $report['undeclared'] ) ) {
			return '';
		}
		$count = count( $report['undeclared'] );
		return sprintf(
			/* translators: 1: count of nodes changed outside the declaration, 2: element ids */
			_n(
				'%1$d element this write never declared it would touch was changed before the save: %2$s',
				'%1$d elements this write never declared it would touch were changed before the save: %2$s',
				$count,
				'elementor-mcp'
			),
			$count,
			implode( ', ', array_slice( $report['undeclared'], 0, 5 ) )
		);
	}

	/**
	 * One line for a warning entry or an error message: names the nodes, not
	 * the rule — the agent reading it has to decide what to do next.
	 *
	 * @param array $report Output of report().
	 * @return string
	 */
	public static function summarize( array $report ): string {
		$parts = array();
		if ( ! empty( $report['collateral'] ) ) {
			$examples = array();
			foreach ( array_slice( $report['collateral'], 0, 5 ) as $c ) {
				$examples[] = sprintf( '%s %s (%s)', $c['type'], $c['id'], $c['kind'] );
			}
			$parts[] = sprintf(
				/* translators: 1: count of untargeted nodes changed, 2: examples */
				_n( '%1$d element this write never targeted changed: %2$s', '%1$d elements this write never targeted changed: %2$s', count( $report['collateral'] ), 'elementor-mcp' ),
				count( $report['collateral'] ),
				implode( '; ', $examples )
			);
		}
		if ( ! empty( $report['not_landed'] ) ) {
			$examples = array();
			foreach ( array_slice( $report['not_landed'], 0, 5 ) as $n ) {
				$examples[] = sprintf( '%s: %s', $n['id'], implode( ', ', $n['missing'] ) );
			}
			$parts[] = sprintf(
				/* translators: 1: count of targets with missing settings, 2: examples */
				_n( '%1$d targeted element is missing a requested setting after the save: %2$s', '%1$d targeted elements are missing a requested setting after the save: %2$s', count( $report['not_landed'] ), 'elementor-mcp' ),
				count( $report['not_landed'] ),
				implode( '; ', $examples )
			);
		}
		return implode( ' — ', $parts );
	}

	/**
	 * Read a declaration, or decide there isn't one. Anything this cannot read
	 * whole — an unknown scope, `targeted` without a non-empty LIST of non-empty
	 * string ids — is undeclared rather than partially honoured: a declaration
	 * half-read would silently narrow what counts as over-reach, which is the
	 * one way this check could lie. Never throws (see the class docblock).
	 *
	 * @since 1.36.0
	 * @param mixed $intent Declaration as given to report().
	 * @return array{scope:string,ids:array<string,true>} scope is targeted|document|undeclared.
	 */
	private static function declaration( $intent ): array {
		$none = array( 'scope' => 'undeclared', 'ids' => array() );
		if ( ! is_array( $intent ) || ! isset( $intent['scope'] ) || ! is_string( $intent['scope'] ) ) {
			return $none;
		}
		if ( 'document' === $intent['scope'] ) {
			return array( 'scope' => 'document', 'ids' => array() );
		}
		if ( 'targeted' !== $intent['scope'] ) {
			return $none;
		}
		$ids = isset( $intent['ids'] ) ? $intent['ids'] : null;
		if ( ! is_array( $ids ) || empty( $ids ) || array_keys( $ids ) !== range( 0, count( $ids ) - 1 ) ) {
			return $none;
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || '' === $id ) {
				return $none;
			}
			$out[ $id ] = true;
		}
		return array( 'scope' => 'targeted', 'ids' => $out );
	}

	/**
	 * Scan one tree once, against the declaration, into:
	 *
	 *   - `covered`   — id => whether that node sits inside the declaration.
	 *                   Decided AS THE TREE IS WALKED, from the node's own path:
	 *                   a node is covered when its own id is declared or when a
	 *                   declared id is somewhere above it on the way down. Never
	 *                   by climbing an id => parent map afterwards, for two
	 *                   reasons. It is honest: a node under the FIRST of two
	 *                   containers sharing an id is judged by the container it
	 *                   is really in, not by whichever occurrence a lookup
	 *                   happened to resolve to — which is what made an ordinary
	 *                   remove-element on a page that already carried a
	 *                   duplicate id accuse itself of over-reach. And it is
	 *                   linear: coverage is computed once per node rather than
	 *                   once per suspect per level of depth.
	 *   - `parents`   — id => nearest ancestor id ('' at the top), first
	 *                   occurrence. Used only to tell a MOVE from a stay, and
	 *                   only for ids that are not ambiguous. Presence here is
	 *                   also what "this tree contains that id" means.
	 *   - `ambiguous` — ids seen more than once. Where that ambiguity came from
	 *                   decides what it costs: see "DUPLICATE IDS" in the class
	 *                   docblock. $forged carries the ids this write made
	 *                   ambiguous, and a declared id among them confers NOTHING
	 *                   — the declaration names a node, not a name anyone may
	 *                   mint a second copy of mid-write.
	 *
	 * A node without an id is transparent: its children keep the nearest
	 * ancestor that HAS one, because an id is the only thing this class matches
	 * on, and they inherit its coverage.
	 *
	 * @since 1.36.0
	 * @param array              $elements Tree.
	 * @param array<string,true> $declared Declared ids.
	 * @param array<string,true> $forged   Ids this write made ambiguous. Empty
	 *                                     on the first scan, which is what
	 *                                     discovers them.
	 * @return array{covered:array<string,bool>,parents:array<string,string>,ambiguous:array<string,true>}
	 */
	private static function scan( array $elements, array $declared, array $forged ): array {
		$out = array( 'covered' => array(), 'parents' => array(), 'ambiguous' => array() );
		self::walk_scan( $elements, '', false, $declared, $forged, $out );
		return $out;
	}

	/**
	 * @param array              $elements Tree.
	 * @param string             $parent   Nearest ancestor id, '' at the top.
	 * @param bool               $under    Whether an ancestor was declared.
	 * @param array<string,true> $declared Declared ids.
	 * @param array<string,true> $forged   Ids this write made ambiguous.
	 * @param array              $out      Accumulator, as described by scan().
	 */
	private static function walk_scan( array $elements, string $parent, bool $under, array $declared, array $forged, array &$out ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id   = isset( $el['id'] ) ? (string) $el['id'] : '';
			$here = $under;
			if ( '' !== $id ) {
				// A declared id this write duplicated proves nothing about this
				// node; an ancestor that was genuinely declared still does, so
				// $under keeps flowing down through it.
				$here = $under || ( isset( $declared[ $id ] ) && ! isset( $forged[ $id ] ) );
				if ( isset( $out['parents'][ $id ] ) ) {
					// A repeat: first occurrence wins, as it does in index(). For
					// an inherited duplicate the `covered` entry is never read —
					// it is exempt. For a forged one the entry can differ between
					// occurrences (one inside a genuinely declared ancestor, one
					// outside), so whether the forged WRAPPER id is itself named
					// follows document order. What it wraps does not: every node
					// under it is judged by its own path either way, so the
					// smuggled content is reported regardless of the order.
					$out['ambiguous'][ $id ] = true;
				} else {
					$out['parents'][ $id ] = $parent;
					$out['covered'][ $id ] = $here;
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_scan( $el['elements'], '' !== $id ? $id : $parent, $here, $declared, $forged, $out );
			}
		}
	}

	/**
	 * Whether the declaration reaches $id, given where it lives in each tree.
	 * The whole point of taking both trees is that a node present in both must
	 * satisfy BOTH — see "COVERAGE" in the class docblock: either side alone
	 * lets a relocation launder the coverage.
	 *
	 * @since 1.36.0
	 * @param string $id             Suspect node id.
	 * @param array  $before_tree    scan() of the before tree.
	 * @param array  $requested_tree scan() of the requested tree.
	 * @param array<string,true> $forged Ids this write made ambiguous.
	 * @return bool
	 */
	private static function within_declaration( string $id, array $before_tree, array $requested_tree, array $forged ): bool {
		if ( ! isset( $forged[ $id ] ) && ( isset( $before_tree['ambiguous'][ $id ] ) || isset( $requested_tree['ambiguous'][ $id ] ) ) ) {
			// Ambiguity the page already carried: which node is which was never
			// knowable, so this accuses nothing — index()'s rule, applied here.
			// An id THIS write made ambiguous earns no such exemption; it falls
			// through and is judged like anything else, and its own coverage is
			// false wherever the forgery is not itself inside the declaration.
			return true;
		}
		$in_before    = isset( $before_tree['parents'][ $id ] );
		$in_requested = isset( $requested_tree['parents'][ $id ] );
		if ( $in_before && $in_requested ) {
			return ! empty( $before_tree['covered'][ $id ] ) && ! empty( $requested_tree['covered'][ $id ] );
		}
		if ( $in_before ) {
			return ! empty( $before_tree['covered'][ $id ] ); // removed: only this tree still places it
		}
		if ( $in_requested ) {
			return ! empty( $requested_tree['covered'][ $id ] ); // added: only this tree places it yet
		}
		return false; // in neither tree — nothing places it anywhere
	}

	/**
	 * Flatten a tree into id => { type, payload, path }. An id that appears
	 * more than once cannot prove which node is which and is dropped from the
	 * comparison entirely (Respira's rule). Nodes without an id are skipped —
	 * Elementor always assigns one, and a node without one cannot be told from
	 * one that merely moved.
	 *
	 * @param array  $elements Tree.
	 * @param string $prefix   Path prefix.
	 * @return array<string,array{type:string,payload:array,path:string}>
	 */
	private static function index( array $elements, string $prefix = '' ): array {
		$out   = array();
		$dupes = array();
		self::walk( $elements, $prefix, $out, $dupes );
		foreach ( array_keys( $dupes ) as $id ) {
			unset( $out[ $id ] );
		}
		return $out;
	}

	/**
	 * @param array                $elements Tree.
	 * @param string               $prefix   Path prefix.
	 * @param array<string,array>  $out      Accumulator.
	 * @param array<string,true>   $dupes    Ids seen more than once.
	 */
	private static function walk( array $elements, string $prefix, array &$out, array &$dupes ): void {
		$i = 0;
		foreach ( $elements as $el ) {
			$path = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			++$i;
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id = isset( $el['id'] ) ? (string) $el['id'] : '';
			if ( '' !== $id ) {
				if ( isset( $out[ $id ] ) ) {
					$dupes[ $id ] = true;
				} else {
					$payload = $el;
					unset( $payload['elements'] );
					$out[ $id ] = array(
						'type'    => self::node_type( $el ),
						'payload' => self::canonical( $payload ),
						'path'    => $path,
					);
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $path, $out, $dupes );
			}
		}
	}

	/**
	 * `widget` nodes are typed by their widgetType; everything else by elType.
	 *
	 * @param array $el Node.
	 * @return string
	 */
	private static function node_type( array $el ): string {
		$el_type = isset( $el['elType'] ) ? (string) $el['elType'] : '';
		if ( 'widget' === $el_type && ! empty( $el['widgetType'] ) ) {
			return (string) $el['widgetType'];
		}
		return $el_type;
	}

	/**
	 * Key order must not count as a change: json_decode and an in-memory
	 * mutation can order the same keys differently.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out     = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = self::canonical( $v );
		}
		if ( ! $is_list ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}
}
