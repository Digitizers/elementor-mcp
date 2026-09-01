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
 * matched by id and compared for equality. Three trees take part:
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
 * Pure: no WordPress, no I/O, no state. The verdict (warn / refuse / off) is
 * Elementor_MCP_Governance's, which is also where the report is recorded.
 *
 * @since 1.34.0
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
	 * @return array{
	 *   comparable: bool,
	 *   targets: list<string>,
	 *   checked: int,
	 *   collateral: list<array{id:string,kind:string,type:string,path:string}>,
	 *   gained: list<array{id:string,type:string,path:string}>,
	 *   not_landed: list<array{id:string,missing:list<string>}>
	 * }
	 */
	public static function report( $before, $requested, $persisted, $coerced = null ): array {
		$report = array(
			'comparable' => false,
			'targets'    => array(),
			'checked'    => 0,
			'collateral' => array(),
			'gained'     => array(),
			'not_landed' => array(),
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
		$report['targets'] = array_keys( $targets );

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
	 * @param array $report Output of report().
	 * @return bool
	 */
	public static function has_findings( array $report ): bool {
		return ! empty( $report['collateral'] ) || ! empty( $report['not_landed'] );
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
