<?php
/**
 * Regression — the fallback write path must invalidate the rendered-element cache.
 *
 * Field report #5, part 1. Elementor caches each element's rendered HTML in the
 * `_elementor_element_cache` post meta and clears it on every native save
 * (`Document::save()` → `delete_cache()`, `core/base/document.php`). The fork's
 * fallback meta-write deleted `_elementor_css` and the generated CSS file but
 * left the HTML cache in place — and that fallback is precisely the path taken
 * in non-browser contexts (CLI, REST proxy), i.e. how the MCP writes.
 *
 * The result was the worst failure class in either field report: the tool
 * returns success, `_elementor_data` is correct on disk, the compiled CSS is
 * correct, and the front end serves the pre-change HTML until the cache TTL
 * expires. Indistinguishable from a failed write, so agents rebuilt elements
 * that were never broken — and intermittent, because the TTL eventually hides
 * it.
 *
 * @group regression
 * @group cache
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Elementor_MCP_Data::save_page_data
 * @covers \Elementor_MCP_Data::save_page_settings
 */
class ElementCacheInvalidationTest extends TestCase {

	/** Elementor's own key for the rendered-HTML cache (Document::CACHE_META_KEY). */
	private const CACHE_META_KEY = '_elementor_element_cache';

	/** @var \Elementor_MCP_Data */
	private $data;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_meta_calls'] = [];
		$this->data                = new \Elementor_MCP_Data();
	}

	/**
	 * Forces the fallback path by making Document::save() report failure.
	 *
	 * @param mixed $return_value What the stubbed save() returns.
	 */
	private function inject_document_returning( $return_value ): void {
		$mock_doc = new class( $return_value ) {
			private $ret;
			public function __construct( $ret ) {
				$this->ret = $ret;
			}
			public function save( array $data ) {
				return $this->ret;
			}
			public function get_settings(): array {
				return [];
			}
		};

		\Elementor\Plugin::$instance->documents = new class( $mock_doc ) {
			private $doc;
			public function __construct( $doc ) {
				$this->doc = $doc;
			}
			public function get( int $post_id ) {
				return $this->doc;
			}
		};
	}

	/**
	 * Meta keys deleted during the test.
	 *
	 * @return string[]
	 */
	private function meta_keys_deleted(): array {
		return array_column(
			array_filter(
				$GLOBALS['_wp_meta_calls'],
				static function ( $call ) {
					return 'delete' === ( $call['action'] ?? '' );
				}
			),
			'meta_key'
		);
	}

	public function test_page_data_fallback_deletes_the_element_cache(): void {
		$this->inject_document_returning( false );

		$this->data->save_page_data( 7, [ [ 'id' => 'abc123', 'elType' => 'container', 'elements' => [] ] ] );

		$this->assertContains(
			self::CACHE_META_KEY,
			$this->meta_keys_deleted(),
			'A fallback page-data write must drop the rendered-HTML cache, or the front end '
			. 'serves stale markup and the write looks like it failed (field report #5).'
		);
	}

	public function test_page_data_fallback_still_deletes_the_css_cache(): void {
		$this->inject_document_returning( false );

		$this->data->save_page_data( 7, [ [ 'id' => 'abc123', 'elType' => 'container', 'elements' => [] ] ] );

		$this->assertContains(
			'_elementor_css',
			$this->meta_keys_deleted(),
			'The CSS invalidation that was already there must not regress.'
		);
	}

	public function test_page_settings_fallback_deletes_the_element_cache(): void {
		$this->inject_document_returning( false );

		$this->data->save_page_settings( 7, [ 'padding' => [ 'size' => 10 ] ] );

		$deleted = $this->meta_keys_deleted();
		$this->assertContains( self::CACHE_META_KEY, $deleted, 'Page-settings fallback must invalidate the HTML cache too.' );
		$this->assertContains( '_elementor_css', $deleted, 'Page-settings fallback must keep invalidating the CSS.' );
	}

	public function test_native_save_that_really_persisted_does_not_hand_invalidate(): void {
		// A native save that actually persisted handles CSS *and* cache itself
		// (Document::save() → delete_cache()), so the fallback must not run and
		// nothing is deleted by hand. The persisted meta has to match what was
		// requested, otherwise the 1.27.0 silent-drop verification correctly
		// routes to the fallback regardless of the truthy return.
		$tree = [ [ 'id' => 'abc123', 'elType' => 'container', 'elements' => [] ] ];

		$this->inject_document_returning( true );
		$GLOBALS['_post_meta'][7]['_elementor_data'] = wp_json_encode( $tree );

		$this->data->save_page_data( 7, $tree );

		$this->assertSame(
			[],
			$this->meta_keys_deleted(),
			'A successful native save already cleared its own caches; the fallback must stay out of it.'
		);

		unset( $GLOBALS['_post_meta'][7] );
	}
}
