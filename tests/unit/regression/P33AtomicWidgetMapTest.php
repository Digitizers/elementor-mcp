<?php
/**
 * Unit tests — P3.3 harvest item #9: shared atomic convenience-param mapper.
 *
 * Elementor_MCP_Atomic_Widget_Map is the single source of the friendly-param →
 * typed-prop mapping, consumed by both the add-atomic-* convenience tools and
 * build-page, so the two produce byte-identical settings for the same input.
 * Also covers the upstream-correct media shapes that ride along: e-image's
 * id-XOR-url image-src (upstream #74), the attachment alt-meta write, and
 * e-self-hosted-video's video-src shape on 4.x (upstream 3.6.2).
 *
 * @group unit
 * @group regression
 * @package Elementor_MCP\Tests
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class P33AtomicWidgetMapTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_meta_calls']              = [];
		$GLOBALS['_post_meta']                  = [];
		$GLOBALS['_attachment_urls']            = [];
		$GLOBALS['_elementor_version_override'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['_elementor_version_override'] = null;
		unset( $GLOBALS['_attachment_urls'] );
		parent::tearDown();
	}

	private function v4(): void {
		$GLOBALS['_elementor_version_override'] = '4.0.0';
	}

	// -------------------------------------------------------------------------
	// Type registry
	// -------------------------------------------------------------------------

	public function test_is_atomic_knows_its_types_and_nothing_else(): void {
		$this->assertTrue( \Elementor_MCP_Atomic_Widget_Map::is_atomic( 'e-heading' ) );
		$this->assertTrue( \Elementor_MCP_Atomic_Widget_Map::is_atomic( 'e-self-hosted-video' ) );
		$this->assertFalse( \Elementor_MCP_Atomic_Widget_Map::is_atomic( 'heading' ), 'Legacy v3 widget names are not atomic.' );
		$this->assertNull(
			\Elementor_MCP_Atomic_Widget_Map::settings( 'heading', [] ),
			'An unmapped type returns null so callers can fall back to the raw path.'
		);
	}

	// -------------------------------------------------------------------------
	// Text widgets
	// -------------------------------------------------------------------------

	public function test_heading_maps_title_tag_link_and_css_id(): void {
		$this->v4();

		$out = \Elementor_MCP_Atomic_Widget_Map::settings(
			'e-heading',
			[
				'title'  => 'Hello',
				'tag'    => 'h1',
				'link'   => 'https://example.com',
				'css_id' => 'hero-title',
			]
		);

		$this->assertSame( \Elementor_MCP_Atomic_Props::html( 'Hello' ), $out['title'], 'On 4.x the content prop is html-v3.' );
		$this->assertSame( [ '$$type' => 'string', 'value' => 'h1' ], $out['tag'] );
		$this->assertSame( \Elementor_MCP_Atomic_Props::link( 'https://example.com' ), $out['link'] );
		$this->assertSame( [ '$$type' => 'string', 'value' => 'hero-title' ], $out['_cssid'] );
		$this->assertSame( \Elementor_MCP_Atomic_Props::classes(), $out['classes'], 'Every builder ends with an empty classes prop.' );
	}

	public function test_heading_content_prop_is_plain_string_on_3x(): void {
		// Default bootstrap ELEMENTOR_VERSION is 3.25.0 — no override needed.
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-heading', [ 'title' => 'Hi' ] );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Hi' ],
			$out['title'],
			'3.x-experimental typed heading content as a plain String — the is_v4 switch must survive the mapper extraction.'
		);
	}

	public function test_heading_defaults_apply(): void {
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-heading', [] );

		$this->assertSame( 'Heading', $out['title']['value'] );
		$this->assertSame( 'h2', $out['tag']['value'] );
		$this->assertArrayNotHasKey( 'link', $out );
		$this->assertArrayNotHasKey( '_cssid', $out );
	}

	public function test_paragraph_content_lands_on_the_paragraph_prop(): void {
		$this->v4();
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-paragraph', [ 'content' => 'Body text' ] );

		$this->assertSame(
			\Elementor_MCP_Atomic_Props::html( 'Body text' ),
			$out['paragraph'],
			'e-paragraph stores content under `paragraph`, not `text` (issue #56).'
		);
		$this->assertArrayNotHasKey( 'content', $out );
		$this->assertArrayNotHasKey( 'text', $out );
	}

	public function test_button_honours_target_blank_only_with_a_link(): void {
		$this->v4();

		$with_link = \Elementor_MCP_Atomic_Widget_Map::settings(
			'e-button',
			[
				'text'         => 'Go',
				'link'         => 'https://example.com',
				'target_blank' => true,
			]
		);
		$this->assertSame(
			\Elementor_MCP_Atomic_Props::link( 'https://example.com', true ),
			$with_link['link'],
			'target_blank must reach the link prop on e-button.'
		);

		$no_link = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-button', [ 'target_blank' => true ] );
		$this->assertArrayNotHasKey( 'link', $no_link );
	}

	// -------------------------------------------------------------------------
	// e-image — id XOR url, alt placement (upstream #74)
	// -------------------------------------------------------------------------

	public function test_image_by_url_builds_image_src_with_null_id_and_inline_alt(): void {
		$out = \Elementor_MCP_Atomic_Widget_Map::settings(
			'e-image',
			[
				'image_url' => 'https://example.com/a.jpg',
				'alt'       => 'A cat',
			]
		);

		$src = $out['image']['value']['src'];
		$this->assertSame( 'image-src', $src['$$type'] );
		$this->assertNull( $src['value']['id'], 'Image_Src_Prop_Type enforces id XOR url — id must be null for a url image (upstream #74).' );
		$this->assertSame( [ '$$type' => 'url', 'value' => 'https://example.com/a.jpg' ], $src['value']['url'] );
		$this->assertSame( [ '$$type' => 'string', 'value' => 'A cat' ], $src['value']['alt'], 'Alt lives INSIDE image-src.' );
		$this->assertArrayNotHasKey( 'alt', $out, 'There is no top-level alt prop on e-image — writing one is silently discarded.' );
	}

	public function test_image_by_id_uses_attachment_id_envelope_and_writes_alt_meta(): void {
		$out = \Elementor_MCP_Atomic_Widget_Map::settings(
			'e-image',
			[
				'image_id' => 42,
				'alt'      => 'Team photo',
			]
		);

		$src = $out['image']['value']['src'];
		$this->assertSame(
			[ '$$type' => 'image-attachment-id', 'value' => 42 ],
			$src['value']['id'],
			'An attachment id must be an image-attachment-id envelope, not a plain number (upstream #74).'
		);
		$this->assertNull( $src['value']['url'], 'id XOR url: url must be null when an id is given.' );

		$alt_writes = array_filter(
			$GLOBALS['_wp_meta_calls'],
			static fn( $c ) => 'update' === $c['action'] && '_wp_attachment_image_alt' === $c['meta_key'] && 42 === $c['post_id']
		);
		$this->assertNotEmpty(
			$alt_writes,
			'For an attachment, Elementor renders only the media library alt — the mapper must write _wp_attachment_image_alt.'
		);
	}

	public function test_image_without_source_params_yields_no_image_prop(): void {
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-image', [] );

		$this->assertArrayNotHasKey( 'image', $out );
		$this->assertEmpty( $GLOBALS['_wp_meta_calls'], 'No id — no alt meta write.' );
	}

	// -------------------------------------------------------------------------
	// e-svg — single url key, is_v4 envelope switch
	// -------------------------------------------------------------------------

	public function test_svg_by_id_resolves_to_a_single_url_key(): void {
		$this->v4();
		$GLOBALS['_attachment_urls'][7] = 'https://example.com/icon.svg';

		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-svg', [ 'svg_id' => 7 ] );

		$this->assertSame(
			[
				'$$type' => 'svg-src',
				'value'  => [ 'url' => [ '$$type' => 'url', 'value' => 'https://example.com/icon.svg' ] ],
			],
			$out['svg'],
			'e-svg validate accepts exactly ONE of id/url — the id is resolved and sent as the single url key (fork live-verified shape).'
		);
	}

	public function test_svg_envelope_is_image_src_on_3x(): void {
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-svg', [ 'svg_url' => 'https://example.com/i.svg' ] );

		$this->assertSame( 'image-src', $out['svg']['$$type'] );
	}

	// -------------------------------------------------------------------------
	// Video widgets
	// -------------------------------------------------------------------------

	public function test_youtube_source_is_a_plain_string_prop(): void {
		$this->v4();
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-youtube', [ 'video_url' => 'https://youtu.be/x' ] );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'https://youtu.be/x' ],
			$out['source'],
			'e-youtube `source` is a plain string, NOT the video-src shape.'
		);
	}

	public function test_self_hosted_video_uses_video_src_shape_on_4x(): void {
		$this->v4();

		$by_url = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-self-hosted-video', [ 'video_url' => 'https://example.com/v.mp4' ] );
		$this->assertSame(
			[
				'$$type' => 'video-src',
				'value'  => [ 'url' => [ '$$type' => 'url', 'value' => 'https://example.com/v.mp4' ] ],
			],
			$by_url['source'],
			'On 4.2+ a bare url envelope is refused outright — source must be the video-src shape (upstream 3.6.2).'
		);

		$by_id = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-self-hosted-video', [ 'video_id' => 9 ] );
		$this->assertSame(
			[
				'$$type' => 'video-src',
				'value'  => [ 'id' => [ '$$type' => 'video-attachment-id', 'value' => 9 ] ],
			],
			$by_id['source'],
			'Video_Src_Prop_Type wants exactly one non-empty key — the unused url is omitted, and the id is a video-attachment-id.'
		);
	}

	public function test_self_hosted_video_keeps_plain_url_shape_on_3x(): void {
		$GLOBALS['_attachment_urls'][5] = 'https://example.com/v.mp4';

		$by_id = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-self-hosted-video', [ 'video_id' => 5 ] );

		$this->assertSame(
			[ '$$type' => 'url', 'value' => 'https://example.com/v.mp4' ],
			$by_id['source'],
			'3.x-experimental keeps the fork\'s original plain-url shape.'
		);
	}

	public function test_divider_maps_to_classes_only(): void {
		$out = \Elementor_MCP_Atomic_Widget_Map::settings( 'e-divider', [] );

		$this->assertSame( [ 'classes' => \Elementor_MCP_Atomic_Props::classes() ], $out );
	}

	// -------------------------------------------------------------------------
	// build-page integration — build_widget routes atomic types through the map
	// -------------------------------------------------------------------------

	private function build_widget_via_composite( string $widget_type, array $settings ): array {
		$composite = new \Elementor_MCP_Composite_Abilities(
			new \Elementor_MCP_Data(),
			new \Elementor_MCP_Element_Factory()
		);

		$method = new \ReflectionMethod( \Elementor_MCP_Composite_Abilities::class, 'build_widget' );

		return $method->invoke( $composite, $widget_type, $settings );
	}

	public function test_build_page_maps_friendly_params_for_atomic_widgets(): void {
		$this->v4();

		$element = $this->build_widget_via_composite( 'e-paragraph', [ 'content' => 'From build-page' ] );

		$this->assertSame( 'widget', $element['elType'] );
		$this->assertSame( 'e-paragraph', $element['widgetType'] );
		$this->assertSame(
			\Elementor_MCP_Atomic_Props::html( 'From build-page' ),
			$element['settings']['paragraph'],
			'build-page must produce the SAME typed settings the add-atomic-paragraph tool produces — previously the friendly params were passed raw and the widget came out empty.'
		);
	}

	public function test_build_page_applies_flat_style_params_as_a_local_class(): void {
		$this->v4();

		$element = $this->build_widget_via_composite(
			'e-heading',
			[
				'title'   => 'Styled',
				'padding' => 20,
			]
		);

		$this->assertNotEmpty( $element['styles'], 'Flat style params on the node must become a local class, as the atomic tools do.' );
		$class_ids = $element['settings']['classes']['value'];
		$this->assertNotEmpty( $class_ids, 'The local class must be referenced from settings.classes (renders nothing otherwise).' );
		$this->assertSame( array_keys( $element['styles'] ), $class_ids );
	}

	public function test_build_page_leaves_legacy_widgets_on_the_raw_path(): void {
		$element = $this->build_widget_via_composite( 'heading', [ 'title' => 'Legacy' ] );

		$this->assertSame( 'heading', $element['widgetType'] );
		$this->assertSame( 'Legacy', $element['settings']['title'], 'Non-atomic widgets keep raw settings — the legacy path is untouched.' );
	}
}
