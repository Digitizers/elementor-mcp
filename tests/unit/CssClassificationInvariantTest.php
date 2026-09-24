<?php
namespace Elementor_MCP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every raw-CSS-NAMED input property of every registered write ability is
 * classified — CSS, conservative, or not-CSS with a reason — so an ability
 * that starts taking CSS under a new field fails the build (spec §4.1
 * "Guard against drift", plan B R3).
 *
 * Registration runs with every gate open — Elementor Pro present and the
 * atomic / variables / interactions experiments active — so the Pro-only and
 * V4-only write tools are reviewed too. ELEMENTOR_PRO_VERSION is a process-
 * wide constant other suites assert is absent, so every test here runs in its
 * own process.
 *
 * @since 1.37.0
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CssClassificationInvariantTest extends TestCase {

	private function writes(): array {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '3.99.0' );
		}
		$GLOBALS['_active_experiments']   = array( 'e_atomic_elements', 'e_variables', 'e_interactions' );
		$GLOBALS['_registered_abilities'] = array();
		$schema    = new \Elementor_MCP_Schema_Generator();
		$registrar = new \Elementor_MCP_Ability_Registrar(
			new \Elementor_MCP_Data(),
			new \Elementor_MCP_Element_Factory(),
			$schema,
			new \Elementor_MCP_Settings_Validator( $schema )
		);
		$registrar->register_all();
		$out = array();
		foreach ( $GLOBALS['_registered_abilities'] as $name => $args ) {
			// A governed write by EITHER signal (Codex r5): the readonly
			// annotation wrap_ability() reads, or a governance declaration
			// (`writes` / `scope`) — an ability that forgot the annotation must
			// still be reviewed, not silently skipped.
			$annotated = false === ( $args['meta']['annotations']['readonly'] ?? null );
			$declared  = isset( $args['meta']['governance']['writes'] ) || isset( $args['meta']['governance']['scope'] );
			if ( $annotated || $declared ) {
				$out[ $name ] = $args['input_schema'] ?? array();
			}
		}
		return $out;
	}

	/** Raw-CSS-named property paths in a schema. */
	private function raw_css_paths( array $schema, string $prefix = '' ): array {
		$out = array();
		foreach ( ( $schema['properties'] ?? array() ) as $prop => $sub ) {
			$path = '' === $prefix ? (string) $prop : $prefix . '.' . $prop;
			if ( in_array( (string) $prop, \Elementor_MCP_Rules::RAW_CSS_NAMES, true ) || preg_match( \Elementor_MCP_Rules::RAW_CSS_NAME_PATTERN, (string) $prop ) ) {
				$out[] = $path;
			}
			if ( is_array( $sub ) && isset( $sub['items'] ) && is_array( $sub['items'] ) ) {
				$out = array_merge( $out, $this->raw_css_paths( $sub['items'], $path . '[]' ) );
			} elseif ( is_array( $sub ) && isset( $sub['properties'] ) ) {
				$out = array_merge( $out, $this->raw_css_paths( $sub, $path ) );
			}
		}
		return $out;
	}

	public function test_the_registry_really_registered_the_write_tools(): void {
		// A silent registration failure must not make this suite vacuous.
		$this->assertGreaterThanOrEqual( 40, count( $this->writes() ) );
		$this->assertArrayHasKey( 'elementor-mcp/add-custom-css', $this->writes() );
	}

	public function test_every_write_ability_is_reviewed_exactly_once(): void {
		$writes   = array_keys( $this->writes() );
		$reviewed = array_keys( \Elementor_MCP_Rules::CSS_REVIEWED );
		$this->assertSame( array(), array_values( array_diff( $writes, $reviewed ) ), 'unreviewed write abilities — add each to CSS_REVIEWED after reading its execute method' );
		$this->assertSame( array(), array_values( array_diff( $reviewed, $writes ) ), 'CSS_REVIEWED names an ability that is not a registered write' );
		foreach ( \Elementor_MCP_Rules::CSS_REVIEWED as $name => $how ) {
			$this->assertMatchesRegularExpression( '/^(walk|walk\+fields|always|none: .+)$/', $how, $name );
			$this->assertSame( in_array( $name, \Elementor_MCP_Rules::ALWAYS_CONSERVATIVE_CSS, true ), 'always' === $how, "{$name}: always iff listed in ALWAYS_CONSERVATIVE_CSS" );
			$has_fields = isset( \Elementor_MCP_Rules::RAW_CSS_FIELDS[ $name ] ) || isset( \Elementor_MCP_Rules::CONSERVATIVE_CSS_FIELDS[ $name ] );
			$this->assertSame( $has_fields, 'walk+fields' === $how, "{$name}: walk+fields iff it has field-map entries" );
		}
	}

	/**
	 * A `none` entry is CHECKED, not trusted (Codex r2): its schema may not
	 * carry CSS by any route the walker would not see — no raw-CSS-named
	 * property, no object open to any key, no free-form string that is
	 * forwarded as markup (`content`, `html`, `markup`, `template*`), and no
	 * `settings`/`page_settings`/`elements`/`structure` (those are walked, so
	 * an ability carrying them is at least 'walk').
	 */
	public function test_a_none_classification_is_checked_against_the_schema(): void {
		$writes = $this->writes();
		foreach ( \Elementor_MCP_Rules::CSS_REVIEWED as $name => $how ) {
			if ( 0 !== strpos( $how, 'none:' ) ) {
				continue;
			}
			$bad = array_merge( $this->raw_css_paths( $writes[ $name ] ), $this->open_or_forwarding_paths( $writes[ $name ] ) );
			$this->assertSame( array(), $bad, "{$name} is marked none but its schema can carry CSS — reclassify" );
		}
	}

	/** Open objects and content-forwarding properties anywhere in a schema. */
	private function open_or_forwarding_paths( array $schema, string $prefix = '' ): array {
		$out = array();
		if ( isset( $schema['additionalProperties'] ) && ( true === $schema['additionalProperties'] || is_array( $schema['additionalProperties'] ) ) ) {
			$out[] = '' === $prefix ? '(root)' : $prefix;
		}
		foreach ( ( $schema['properties'] ?? array() ) as $prop => $sub ) {
			$path = '' === $prefix ? (string) $prop : $prefix . '.' . $prop;
			if ( preg_match( '/^(settings|page_settings|elements|structure|content|html|markup)$|template/i', (string) $prop ) ) {
				$out[] = $path;
				continue;
			}
			if ( is_array( $sub ) && isset( $sub['items'] ) && is_array( $sub['items'] ) ) {
				$out = array_merge( $out, $this->open_or_forwarding_paths( $sub['items'], $path . '[]' ) );
			} elseif ( is_array( $sub ) && ( isset( $sub['properties'] ) || isset( $sub['additionalProperties'] ) ) ) {
				$out = array_merge( $out, $this->open_or_forwarding_paths( $sub, $path ) );
			}
		}
		return $out;
	}

	/**
	 * The field maps hold TOP-LEVEL input keys only — css_touches() reads
	 * `$input[ $field ]` directly (Codex r2). A nested raw-CSS path found by
	 * the drift guard cannot be satisfied by a map entry; it needs a change
	 * to css_touches() (and is reported by the classification test until then).
	 */
	/**
	 * A `walk` producer proves it forwards no OPAQUE content (Codex r3): a
	 * string-typed property that carries markup/templates cannot be walked for
	 * a `custom_css` key, so it must be in CONSERVATIVE_CSS_FIELDS. Open
	 * objects and element arrays are walked, so they are fine.
	 */
	public function test_a_walk_producer_forwards_no_unclassified_opaque_string(): void {
		$writes = $this->writes();
		foreach ( \Elementor_MCP_Rules::CSS_REVIEWED as $name => $how ) {
			if ( 0 === strpos( $how, 'none:' ) ) {
				continue;
			}
			foreach ( $this->opaque_string_paths( $writes[ $name ] ) as $path ) {
				// Top-level paths can be satisfied by a field-map entry; a nested
				// one cannot (maps hold top-level keys) — it needs css_touches()
				// to read it, so it always fails here until the handler does
				// and the path is listed below (Codex r4).
				// A descriptive string (`html_tag`, `template_type`) may be
				// classified not-CSS with a reason (Codex r5) — the point is
				// that someone looked, not that every match is conservative.
				$this->assertTrue(
					in_array( $path, \Elementor_MCP_Rules::CONSERVATIVE_CSS_FIELDS[ $name ] ?? array(), true )
						|| in_array( $path, \Elementor_MCP_Rules::RAW_CSS_FIELDS[ $name ] ?? array(), true )
						|| array_key_exists( $path, \Elementor_MCP_Rules::NOT_CSS_FIELDS[ $name ] ?? array() ),
					"{$name} :: {$path} matches the opaque-content net — classify it (conservative, raw, or not-CSS with a reason)"
				);
			}
		}
	}

	/** String-typed markup/code/template properties at ANY depth (objects and array items). */
	private function opaque_string_paths( array $schema, string $prefix = '' ): array {
		$out = array();
		foreach ( ( $schema['properties'] ?? array() ) as $prop => $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$path      = '' === $prefix ? (string) $prop : $prefix . '.' . $prop;
			$type      = $sub['type'] ?? null;
			$is_string = 'string' === $type || ( is_array( $type ) && in_array( 'string', $type, true ) );
			if ( $is_string && preg_match( '/content|html|markup|template|code/i', (string) $prop ) ) {
				$out[] = $path;
			}
			if ( isset( $sub['items'] ) && is_array( $sub['items'] ) ) {
				$out = array_merge( $out, $this->opaque_string_paths( $sub['items'], $path . '[]' ) );
			} elseif ( isset( $sub['properties'] ) ) {
				$out = array_merge( $out, $this->opaque_string_paths( $sub, $path ) );
			}
		}
		return $out;
	}

	/**
	 * Field maps hold object-key paths (`css`, `spec.styles`) — no `[]` list
	 * segments, which css_touches() cannot resolve — and css_touches() really
	 * reads every entry: a non-empty string at the path declares custom_css,
	 * so a map entry can never satisfy the guard without being read (Task 3
	 * review, C1).
	 */
	public function test_field_maps_hold_object_paths_that_css_touches_reads(): void {
		foreach ( array( \Elementor_MCP_Rules::RAW_CSS_FIELDS, \Elementor_MCP_Rules::CONSERVATIVE_CSS_FIELDS ) as $map ) {
			foreach ( $map as $name => $fields ) {
				foreach ( $fields as $f ) {
					$this->assertMatchesRegularExpression( '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $f, "{$name} :: {$f} is not an object-key path" );
					$input = array();
					$node  = &$input;
					foreach ( explode( '.', $f ) as $segment ) {
						$node[ $segment ] = array();
						$node             = &$node[ $segment ];
					}
					$node = 'a{color:red}';
					unset( $node );
					$touches = \Elementor_MCP_Rules::css_touches( '*', $name, $input );
					$this->assertCount( 1, $touches, "{$name} :: {$f} is mapped but css_touches() does not read it" );
					$this->assertSame( 'custom_css', $touches[0]['type'] );
				}
			}
		}
	}

	public function test_every_raw_css_named_field_is_classified_exactly_once(): void {
		$unclassified = array();
		foreach ( $this->writes() as $name => $schema ) {
			foreach ( $this->raw_css_paths( $schema ) as $path ) {
				$in = (int) in_array( $path, \Elementor_MCP_Rules::RAW_CSS_FIELDS[ $name ] ?? array(), true )
					+ (int) in_array( $path, \Elementor_MCP_Rules::CONSERVATIVE_CSS_FIELDS[ $name ] ?? array(), true )
					+ (int) array_key_exists( $path, \Elementor_MCP_Rules::NOT_CSS_FIELDS[ $name ] ?? array() );
				if ( 1 !== $in ) {
					$unclassified[] = "{$name} :: {$path} (in {$in} maps)";
				}
			}
		}
		$this->assertSame( array(), $unclassified, "Classify each raw-CSS-named field in Elementor_MCP_Rules (RAW_CSS_FIELDS / CONSERVATIVE_CSS_FIELDS / NOT_CSS_FIELDS with a reason)" );
	}

	public function test_every_map_entry_names_a_registered_write_and_a_real_field(): void {
		$writes = $this->writes();
		foreach ( array( \Elementor_MCP_Rules::RAW_CSS_FIELDS, \Elementor_MCP_Rules::CONSERVATIVE_CSS_FIELDS ) as $map ) {
			foreach ( $map as $name => $fields ) {
				$this->assertArrayHasKey( $name, $writes, "{$name} is not a registered write" );
				foreach ( $fields as $f ) {
					$this->assertContains( $f, $this->raw_css_paths( $writes[ $name ] ), "{$name} has no field {$f}" );
				}
			}
		}
		foreach ( \Elementor_MCP_Rules::NOT_CSS_FIELDS as $name => $fields ) {
			$this->assertArrayHasKey( $name, $writes );
			foreach ( $fields as $f => $reason ) {
				$this->assertNotSame( '', trim( (string) $reason ), "{$name} :: {$f} needs a reason" );
			}
		}
	}
}
