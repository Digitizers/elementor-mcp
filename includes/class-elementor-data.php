<?php
/**
 * Elementor data access layer.
 *
 * Wraps Elementor internals to provide a clean API for reading and writing
 * Elementor page data, widget registrations, and element trees.
 *
 * @package Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer wrapping Elementor's internal APIs.
 *
 * @since 1.0.0
 */
class Elementor_MCP_Data {

	/**
	 * Gets the Elementor document for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return \Elementor\Core\Base\Document|\WP_Error The document instance or WP_Error.
	 */
	public function get_document( int $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		if ( ! $document ) {
			return new \WP_Error(
				'document_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Elementor document not found for post ID %d.', 'elementor-mcp' ),
					$post_id
				)
			);
		}

		return $document;
	}

	/**
	 * Gets the element tree for an Elementor page.
	 *
	 * Tries the Elementor document API first, falls back to reading raw
	 * post meta if the document returns empty data (common in CLI contexts).
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error The elements data array or WP_Error.
	 */
	public function get_page_data( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$data = $document->get_elements_data();

		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		// Fallback: read from raw post meta (handles CLI/proxy contexts).
		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! empty( $raw ) && is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Gets the page-level settings for an Elementor document.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error The page settings array or WP_Error.
	 */
	public function get_page_settings( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return $document->get_settings();
	}

	/**
	 * Gets the document type for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return string|\WP_Error The document type string or WP_Error.
	 */
	public function get_document_type( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return get_post_meta( $post_id, '_elementor_template_type', true );
	}

	/**
	 * Gets all registered Elementor widget types.
	 *
	 * @since 1.0.0
	 *
	 * @return \Elementor\Widget_Base[] Array of widget instances keyed by widget name.
	 */
	public function get_registered_widgets(): array {
		return \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
	}

	/**
	 * Gets the controls for a specific widget type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name.
	 * @return array|\WP_Error The controls array or WP_Error if widget not found.
	 */
	public function get_widget_controls( string $widget_type ) {
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			return new \WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget type name */
					__( 'Widget type "%s" not found.', 'elementor-mcp' ),
					$widget_type
				)
			);
		}

		return $widget->get_controls();
	}

	/**
	 * Recursively searches for an element by ID within an element tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data The element tree array.
	 * @param string $id   The element ID to find.
	 * @return array|null The element array if found, null otherwise.
	 */
	public function find_element_by_id( array $data, string $id ): ?array {
		foreach ( $data as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $id ) {
				return $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->find_element_by_id( $element['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Saves page data using Elementor's native save mechanism.
	 *
	 * Tries document save() first (triggers CSS regeneration). If that fails
	 * (e.g. non-browser context like WP-CLI or REST API), falls back to direct
	 * meta update and manual CSS cache invalidation.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The elements data array.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_page_data( int $post_id, array $data ) {
		// v4 atomic: Elementor validates the WHOLE element tree on save, so one
		// widget holding raw (unwrapped) prop values blocks every save of the
		// page, including the edit meant to repair it (upstream #101/#102).
		// Sweep the tree on the way in: raw scalars are wrapped into the $$type
		// envelope their prop type accepts, and advertised alias keys are
		// renamed onto the canonical prop before Elementor's parser would
		// silently delete them. This only ever turns invalid values into valid
		// ones, so it is a no-op for healthy pages. It runs FIRST — before the
		// governance snapshot and the pre-save capture — so both observe
		// exactly the tree this save will write, and the projection
		// verification below compares like against like (coercion rewrites
		// settings only, never element ids or structure).
		if ( class_exists( 'Elementor_MCP_Atomic_Props' ) ) {
			$data = Elementor_MCP_Atomic_Props::coerce_tree( $data );
		}

		// SiteAgent governance: snapshot the page before the first write of a
		// governed run. No-op unless the worker is installed and a governed tool
		// is in flight; fails closed if the snapshot cannot be captured.
		if ( class_exists( 'Elementor_MCP_Governance' ) ) {
			$gate = Elementor_MCP_Governance::before_page_write( $post_id );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}

		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		// Capture the PRE-save tree: pre-existence of unavailable elements must
		// be judged against what the page held BEFORE this save — the post-save
		// re-read can't distinguish "never existed" from "just sanitized away",
		// and a widget from a temporarily inactive plugin that lived on the
		// page is data an unrelated edit must not destroy.
		$pre_raw = get_post_meta( $post_id, '_elementor_data', true );
		$pre_tree = ( is_string( $pre_raw ) && '' !== $pre_raw ) ? json_decode( $pre_raw, true ) : null;
		$pre_seq  = is_array( $pre_tree ) ? $this->element_id_sequence( $pre_tree ) : array();

		// Attempt native Elementor save (handles CSS regen, cache busting).
		// Elementor 4.0 atomic widgets THROW on invalid settings instead of
		// returning false, so catch it and return a clean error rather than
		// letting it fatal the whole request. The fallback meta-write below runs
		// ONLY for the no-exception falsy case (false OR null — valid data,
		// non-browser context), so invalid data is never written raw. Document::save()
		// can return null (not just false) in CLI/REST, hence `! $result`. (F-005, #36)
		try {
			$result = $document->save( array( 'elements' => $data ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'save_rejected',
				sprintf(
					/* translators: %s: error message from Elementor */
					__( 'Elementor rejected the element data: %s', 'elementor-mcp' ),
					$e->getMessage()
				)
			);
		}

		// Verify the native save actually persisted our elements. Elementor's
		// Document::save() can return a truthy value in some 4.x / atomic / REST
		// contexts yet drop the elements — leaving `_elementor_data` empty, or
		// (on an already-populated page) leaving the STALE pre-save content in
		// place — a silent write failure the caller never sees (upstream #98).
		// Re-read and compare the RECURSIVE, ORDERED element-id sequence: ids
		// survive Elementor's save-time settings normalization, so any
		// structural difference (nested add/delete/duplicate/reorder, not just
		// top-level) means our tree did not land. Known limitation, stated
		// plainly: a mutation that changes ONLY settings on an unchanged tree
		// shape is indistinguishable from stale content by structure, and a
		// deep settings comparison would false-positive against Elementor's own
		// normalization (and the fallback would then clobber it) — so
		// settings-only silent drops remain undetectable here.
		$needs_fallback = ! $result;
		$silent_drop    = false;
		if ( ! $needs_fallback ) {
			$persisted_raw = get_post_meta( $post_id, '_elementor_data', true );
			$persisted     = ( is_string( $persisted_raw ) && '' !== $persisted_raw )
				? json_decode( $persisted_raw, true )
				: null;

			if ( empty( $data ) ) {
				// Empty requested tree (e.g. delete-page-content): a silent drop
				// leaves the OLD elements in place — verify the page really is
				// empty now, else force the direct meta write of the empty tree.
				if ( is_array( $persisted ) && ! empty( $persisted ) ) {
					$needs_fallback = true;
					$silent_drop    = true;
				}
			} else {
				// Empty/invalid persisted content flows through the SAME
				// projection compare with an empty persisted sequence: a
				// requested tree that consists ENTIRELY of unavailable elements
				// projects to [] == [] and is pure sanitization (upstream
				// documents exactly this for unknown atomic elements) — the
				// old dedicated empty-persisted branch wrongly fell back and
				// wrote the raw unsanitized tree.
				if ( ! is_array( $persisted ) ) {
					$persisted = array();
				}
				// Distinguish a context-related silent drop from Elementor's own
				// DELIBERATE sanitization: a save can succeed while removing
				// elements whose type is unavailable on this site (unknown atomic
				// widgets etc.). Project the requested tree the way a native save
				// would sanitize it — drop unavailable elements EXCEPT ones whose
				// ids already exist on the page (pre-existing data) — and compare
				// that projection's parent-aware id sequence against the re-read.
				// Any difference is a real silent drop (covers nested edits,
				// reorders, and the mixed case of a structural edit arriving
				// together with an unavailable element); a pure-sanitization save
				// matches exactly and is left alone.
				$persisted_seq = $this->element_id_sequence( $persisted );
				// Preserve unavailable elements that existed either AFTER the
				// save (still persisted) or BEFORE it (pre-save capture): a
				// native save that sanitized away a pre-existing inactive-plugin
				// widget is destruction of page data, not acceptable
				// sanitization — the mismatch below restores it via the
				// projection.
				$keep_ids      = array_values( array_unique( array_merge( $persisted_seq, $pre_seq ) ) );
				$expected_tree = $this->strip_unavailable_elements( $data, $keep_ids );
				if ( $this->element_id_sequence( $expected_tree ) !== $persisted_seq ) {
					$needs_fallback = true;
					$silent_drop    = true;
				}
			}
		}

		if ( $needs_fallback ) {
			// Fallback: direct meta write for non-browser contexts (CLI, REST proxy)
			// and for the silent-drop case above. On the SILENT-DROP path only,
			// strip unavailable-type elements first — a native save deliberately
			// sanitizes those, and the raw write must not resurrect them (covers
			// the mixed case too). On the classic falsy-return path (CLI/REST —
			// no native save happened at all) the tree is written as-is: a widget
			// from a temporarily inactive plugin is DATA, not sanitization, and
			// stripping it there would destroy it on an unrelated edit.
			$fallback_tree = ( $silent_drop && isset( $expected_tree ) )
				? $expected_tree
				: $data;
			$json          = wp_json_encode( $fallback_tree );

			if ( false === $json ) {
				return new \WP_Error(
					'json_encode_failed',
					__( 'Failed to encode element data as JSON.', 'elementor-mcp' )
				);
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

			// Ensure Elementor meta flags are set.
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
			}

			// Invalidate Elementor CSS cache so it regenerates on next page view.
			delete_post_meta( $post_id, '_elementor_css' );

			$upload_dir = wp_get_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $css_path ) ) {
				wp_delete_file( $css_path );
			}
		}

		return true;
	}

	/**
	 * Saves page-level settings.
	 *
	 * Tries native Elementor save first, falls back to direct meta for
	 * non-browser contexts (WP-CLI, REST API proxy).
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id  The post ID.
	 * @param array $settings The page settings array.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_page_settings( int $post_id, array $settings ) {
		// SiteAgent governance: snapshot before the first write of a governed run
		// (captures both page-meta keys, so a tool that writes settings then tree,
		// or vice-versa, shares one pre-write snapshot). No-op when ungoverned.
		if ( class_exists( 'Elementor_MCP_Governance' ) ) {
			$gate = Elementor_MCP_Governance::before_page_write( $post_id );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}

		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$result = $document->save( array( 'settings' => $settings ) );

		if ( ! $result ) {
			// Fallback: merge settings into existing page settings meta.
			$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$merged = array_merge( $existing, $settings );
			update_post_meta( $post_id, '_elementor_page_settings', $merged );

			// Invalidate CSS cache.
			delete_post_meta( $post_id, '_elementor_css' );
		}

		return true;
	}

	/**
	 * Inserts an element into the page data tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data      The element tree (passed by reference).
	 * @param string $parent_id The parent element ID. Empty string for top-level.
	 * @param array  $element   The element to insert.
	 * @param int    $position  The insertion position (-1 = append).
	 * @return bool True if inserted, false if parent not found.
	 */
	public function insert_element( array &$data, string $parent_id, array $element, int $position = -1 ): bool {
		// Top-level insertion.
		if ( empty( $parent_id ) ) {
			if ( $position < 0 || $position >= count( $data ) ) {
				$data[] = $element;
			} else {
				array_splice( $data, $position, 0, array( $element ) );
			}
			return true;
		}

		// Find parent and insert.
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $parent_id ) {
				if ( ! isset( $item['elements'] ) ) {
					$item['elements'] = array();
				}

				if ( $position < 0 || $position >= count( $item['elements'] ) ) {
					$item['elements'][] = $element;
				} else {
					array_splice( $item['elements'], $position, 0, array( $element ) );
				}

				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->insert_element( $item['elements'], $parent_id, $element, $position ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Removes an element from the page data tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data       The element tree (passed by reference).
	 * @param string $element_id The element ID to remove.
	 * @return bool True if removed, false if not found.
	 */
	public function remove_element( array &$data, string $element_id ): bool {
		foreach ( $data as $index => &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				array_splice( $data, $index, 1 );
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->remove_element( $item['elements'], $element_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Recursively reassigns fresh IDs to all elements in a tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements The element tree.
	 * @return array The tree with new IDs.
	 */
	/**
	 * Recursively removes elements whose type is unavailable on this site.
	 *
	 * Used by the silent-drop fallback so it writes only what Elementor's own
	 * save would accept — never resurrecting deliberately sanitized elements —
	 * while preserving unavailable elements that already exist on the page
	 * (their ids appear in $keep_ids, the persisted tree's id sequence).
	 *
	 * @since 1.27.0
	 *
	 * @param array $elements The element tree.
	 * @param array $keep_ids Ids present in the persisted tree — always kept.
	 * @return array Filtered tree.
	 */
	private function strip_unavailable_elements( array $elements, array $keep_ids = array() ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			// Strip only what Elementor removed in THIS save: an unavailable
			// element whose id survived in the persisted tree is pre-existing
			// page data (e.g. a widget from a temporarily inactive plugin) and
			// must be preserved, not destroyed by an unrelated edit's fallback.
			$el_id = isset( $el['id'] ) ? (string) $el['id'] : '';
			$kept  = false;
			if ( '' !== $el_id ) {
				foreach ( $keep_ids as $entry ) {
					if ( substr( (string) $entry, strpos( (string) $entry, '>' ) + 1 ) === $el_id ) {
						$kept = true;
						break;
					}
				}
			}
			if ( ! $this->element_type_available( $el ) && ! $kept ) {
				continue;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = $this->strip_unavailable_elements( $el['elements'], $keep_ids );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Whether an element's type is available on this site.
	 *
	 * A widget whose type is not registered (and, for atomic elements, not an
	 * available atomic type) is one Elementor may DELIBERATELY strip on save —
	 * that is sanitization, not a silent write failure, and the fallback must
	 * not resurrect it. Non-widget elements (containers, sections) are always
	 * treated as available.
	 *
	 * @since 1.27.0
	 *
	 * @param array $element The element array.
	 * @return bool
	 */
	private function element_type_available( array $element ): bool {
		$widget_type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
		if ( '' !== $widget_type ) {
			$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
			if ( $manager && method_exists( $manager, 'get_widget_types' ) ) {
				return null !== $manager->get_widget_types( $widget_type );
			}
			return true;
		}

		// Atomic containers (e-flexbox, e-div-block, ...) carry no widgetType —
		// their availability lives in the elements manager. Classic structural
		// types are always available.
		$el_type = isset( $element['elType'] ) ? (string) $element['elType'] : '';
		if ( in_array( $el_type, array( '', 'widget', 'section', 'column', 'container' ), true ) ) {
			return true;
		}
		$em = \Elementor\Plugin::$instance->elements_manager ?? null;
		if ( $em && method_exists( $em, 'get_element_types' ) ) {
			$types = $em->get_element_types();
			if ( is_array( $types ) ) {
				return array_key_exists( $el_type, $types );
			}
		}
		return true;
	}

	/**
	 * Depth-first, ordered sequence of every element id in a tree.
	 *
	 * Used by the silent-save verification: comparing full sequences catches
	 * nested adds/deletes/duplicates and reorders, not just top-level drops.
	 *
	 * @since 1.27.0
	 *
	 * @param array $elements The element tree.
	 * @return string[] Ordered id list.
	 */
	private function element_id_sequence( array $elements, string $parent_id = '' ): array {
		$ids = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$el_id = isset( $el['id'] ) ? (string) $el['id'] : '';
			if ( '' !== $el_id ) {
				// Encode parentage, not just order: moving an element to a
				// different parent can leave the flat depth-first order intact
				// ([A[B]] -> [A],[B] both flatten to A,B), so each entry carries
				// its parent id.
				$ids[] = $parent_id . '>' . $el_id;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $this->element_id_sequence( $el['elements'], $el_id ) as $child_entry ) {
					$ids[] = $child_entry;
				}
			}
		}
		return $ids;
	}

	public function reassign_ids( array $elements ): array {
		foreach ( $elements as &$element ) {
			$element['id'] = Elementor_MCP_Id_Generator::generate();

			// Re-mint v4 local style classes against the new id — here, not only
			// in reassign_element_ids(), so children of a duplicated container
			// AND the template-import paths (which call reassign_ids directly)
			// get fresh classes too (upstream #97).
			if ( class_exists( 'Elementor_MCP_Atomic_Styles' ) ) {
				Elementor_MCP_Atomic_Styles::remap_local_classes( $element );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->reassign_ids( $element['elements'] );
			}
		}

		return $elements;
	}

	/**
	 * Reassigns a fresh ID to a single element and all its children.
	 *
	 * @since 1.0.0
	 *
	 * @param array $element The element array.
	 * @return array The element with new IDs.
	 */
	public function reassign_element_ids( array $element ): array {
		$element['id'] = Elementor_MCP_Id_Generator::generate();

		// v4 atomic elements: local style classes are named `e-<id>-<hash>` and
		// belong to a single element. A fresh element id must get fresh local
		// classes, or the duplicate shares the source's local classes — causing
		// cross-element style bleed and Style Origin doubling (upstream #97).
		if ( class_exists( 'Elementor_MCP_Atomic_Styles' ) ) {
			Elementor_MCP_Atomic_Styles::remap_local_classes( $element );
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = $this->reassign_ids( $element['elements'] );
		}

		return $element;
	}

	/**
	 * Recursively counts all elements in a tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements The element tree.
	 * @return int Total count.
	 */
	public function count_elements( array $elements ): int {
		$count = count( $elements );

		foreach ( $elements as $element ) {
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elements( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Updates settings for a specific element in the tree.
	 *
	 * Modifies `$data` by reference. Returns true if element was found
	 * and updated, false if the element ID was not found.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data       The element tree (passed by reference).
	 * @param string $element_id The element ID to update.
	 * @param array  $settings   The settings to merge.
	 * @return bool True if updated, false if not found.
	 */
	public function update_element_settings( array &$data, string $element_id, array $settings ): bool {
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				if ( ! isset( $item['settings'] ) ) {
					$item['settings'] = array();
				}

				// Sibling-root keys: on v4 atomic elements the local `styles`
				// map and `editor_settings` (Navigator label = editor_settings.
				// title) live at the element ROOT, as siblings of `settings`.
				// An agent naturally nests them under `settings`; hoist them out
				// and deep-merge into the root so they actually persist instead
				// of being written to a dead `settings.styles` key (upstream
				// #72, #73).
				$touched_styles = false;
				foreach ( array( 'styles', 'editor_settings' ) as $root_key ) {
					if ( ! array_key_exists( $root_key, $settings ) ) {
						continue;
					}

					if ( 'styles' === $root_key ) {
						$touched_styles = true;
					}

					$incoming = $settings[ $root_key ];
					unset( $settings[ $root_key ] );

					if ( is_array( $incoming ) ) {
						$existing          = isset( $item[ $root_key ] ) && is_array( $item[ $root_key ] ) ? $item[ $root_key ] : array();
						$item[ $root_key ] = self::deep_merge( $existing, $incoming );
					} else {
						$item[ $root_key ] = $incoming;
					}
				}

				// Containers: rewrite MCP shorthand keys (`justify_content`,
				// `align_items`, `align_content`) to Elementor's prefixed flex
				// keys before merging. Without this, the values are saved
				// but never read by Elementor's CSS generator (issue #32).
				if ( 'container' === ( $item['elType'] ?? '' ) ) {
					$settings = Elementor_MCP_Element_Factory::normalize_container_settings( $settings );
				}

				$item['settings'] = array_merge( $item['settings'], $settings );

				// v4 atomic: a local style class only renders when the element's
				// `classes` prop references it. An agent that writes a `styles`
				// map but forgets to add the class id to settings.classes gets a
				// silent no-op — the styles persist but never apply (upstream
				// #92). Wire every local class id from the styles map into
				// settings.classes. (Prop-value coercion is NOT repeated here:
				// the fork's save_page_data() sweeps the whole tree via
				// coerce_tree() on the way out, which covers these merged
				// settings too.)
				if ( $touched_styles ) {
					self::sync_local_class_refs( $item );
				}

				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->update_element_settings( $item['elements'], $element_id, $settings ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Recursively merges $incoming into $existing. Associative arrays are merged
	 * key-by-key; lists (numeric-keyed arrays, e.g. a `variants` array) and
	 * scalars are replaced wholesale by the incoming value. This lets a partial
	 * `styles`/`editor_settings` update touch one class or key without dropping
	 * the siblings, while still replacing a variants list the caller supplied in
	 * full.
	 *
	 * @since 1.27.0
	 *
	 * @param array $existing The current value.
	 * @param array $incoming The value to merge in (wins on conflicts).
	 * @return array The merged array.
	 */
	private static function deep_merge( array $existing, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if (
				is_string( $key )
				&& isset( $existing[ $key ] )
				&& is_array( $existing[ $key ] )
				&& is_array( $value )
				&& self::is_assoc( $existing[ $key ] )
				&& self::is_assoc( $value )
			) {
				$existing[ $key ] = self::deep_merge( $existing[ $key ], $value );
			} else {
				$existing[ $key ] = $value;
			}
		}

		return $existing;
	}

	/**
	 * Whether an array is associative (has any string key / non-sequential
	 * integer keys). An empty array is treated as a list (not associative).
	 *
	 * @since 1.27.0
	 *
	 * @param array $arr The array to test.
	 * @return bool
	 */
	private static function is_assoc( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Ensures every local style class in an atomic element's `styles` map is
	 * referenced by its `settings.classes` prop, so Elementor actually applies
	 * the styles at render time.
	 *
	 * In Elementor 4.0 (atomic), a per-element local class lives in the element
	 * root `styles` map keyed by an `e-<id>-<hash>` class id, with a sibling
	 * `settings.classes = { $$type:'classes', value:[ ...ids ] }` that lists the
	 * classes actually applied to the element. Writing a `styles` entry alone
	 * persists the definition but renders nothing until the id is also in
	 * `classes.value`. This wires up any missing references (idempotent) so a
	 * `styles` write is self-contained (upstream #92).
	 *
	 * @since 1.27.0
	 *
	 * @param array $item Element structure (by reference).
	 */
	private static function sync_local_class_refs( array &$item ): void {
		if ( empty( $item['styles'] ) || ! is_array( $item['styles'] ) ) {
			return;
		}

		// Collect local class ids from the styles map (type:'class' only).
		$ids = array();
		foreach ( $item['styles'] as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			if ( isset( $def['type'] ) && 'class' !== $def['type'] ) {
				continue;
			}
			$id = ( isset( $def['id'] ) && is_string( $def['id'] ) && '' !== $def['id'] )
				? $def['id']
				: ( is_string( $key ) ? $key : '' );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return;
		}

		if ( ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
			$item['settings'] = array();
		}
		$classes = ( isset( $item['settings']['classes'] ) && is_array( $item['settings']['classes'] ) )
			? $item['settings']['classes']
			: array();

		// A raw LIST (an agent wrote `classes` as a bare id array) is the
		// value, not the wrapper — fold it in before normalizing, or the ids
		// already on the element would be clobbered into a malformed shape.
		// (Divergence from upstream, which loses this edge.)
		if ( ! isset( $classes['$$type'] ) && ! self::is_assoc( $classes ) ) {
			$classes = array( 'value' => $classes );
		}

		// Normalize to the atomic classes wrapper { $$type:'classes', value:[] }.
		if ( ! isset( $classes['$$type'] ) ) {
			$classes['$$type'] = 'classes';
		}
		if ( ! isset( $classes['value'] ) || ! is_array( $classes['value'] ) ) {
			$classes['value'] = array();
		}

		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $classes['value'], true ) ) {
				$classes['value'][] = $id;
			}
		}

		// Canonical key order ($$type first), preserving any extra keys.
		$ordered = array(
			'$$type' => $classes['$$type'],
			'value'  => $classes['value'],
		);
		unset( $classes['$$type'], $classes['value'] );

		$item['settings']['classes'] = $ordered + $classes;
	}
}
