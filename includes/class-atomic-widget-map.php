<?php
/**
 * Shared convenience-param mapping for atomic (Elementor 4.0+) widgets.
 *
 * The atomic convenience tools (add-atomic-heading, add-atomic-image, …)
 * accept friendly params — `title`, `content`, `image_url`, `alt`,
 * `video_url` — and turn them into the typed `$$type` prop shapes Elementor
 * stores. build-page, however, passed widget settings through raw, so an
 * atomic widget given the same friendly params came out empty (its complex
 * props, like `e-image`'s `image` or `e-self-hosted-video`'s `source`, have
 * no matching raw key).
 *
 * This class is the single source of that mapping so the individual tools and
 * build-page produce byte-identical settings for the same input. Every builder
 * is PURE; the one media side effect `e-image` implies (the attachment's alt
 * meta, the only alt Elementor renders for a media-library image) is exposed
 * as pending_alt_write() / apply_alt_write() so callers can authorize it
 * against the attachment and defer it until the page save succeeded.
 *
 * Ported from upstream 3.6.2 (class-atomic-widget-map.php), adapted to the
 * fork's is_v4()-aware prop builders (3.x-experimental sites keep the shapes
 * the fork verified live).
 *
 * @package Elementor_MCP
 * @since   1.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps friendly params to atomic widget settings, keyed by widget type.
 *
 * @since 1.27.0
 */
class Elementor_MCP_Atomic_Widget_Map {

	/**
	 * Widget types this class knows how to build from convenience params.
	 *
	 * @return string[]
	 */
	public static function atomic_types(): array {
		return array(
			'e-heading',
			'e-paragraph',
			'e-button',
			'e-image',
			'e-svg',
			'e-youtube',
			'e-self-hosted-video',
			'e-divider',
		);
	}

	/**
	 * Whether this class can map convenience params for a widget type.
	 *
	 * @param string $widget_type Widget type, e.g. 'e-image'.
	 * @return bool
	 */
	public static function is_atomic( string $widget_type ): bool {
		return in_array( $widget_type, self::atomic_types(), true );
	}

	/**
	 * Maps convenience params to atomic settings for a widget type.
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $params      Convenience params (title, content, image_url, …).
	 * @return array|null Atomic settings, or null when the type is not handled here.
	 */
	public static function settings( string $widget_type, array $params ): ?array {
		switch ( $widget_type ) {
			case 'e-heading':
				return self::heading( $params );
			case 'e-paragraph':
				return self::paragraph( $params );
			case 'e-button':
				return self::button( $params );
			case 'e-image':
				return self::image( $params );
			case 'e-svg':
				return self::svg( $params );
			case 'e-youtube':
				return self::youtube( $params );
			case 'e-self-hosted-video':
				return self::video( $params );
			case 'e-divider':
				return self::divider( $params );
			default:
				return null;
		}
	}

	/**
	 * Content prop for e-heading/e-paragraph/e-button text. Elementor 4.x GA
	 * types these as Html_V3 (html-v3); 3.x-experimental used a plain String.
	 *
	 * @param string $text Plain text content.
	 * @return array Typed prop ($$type html-v3 on 4.x, string on 3.x).
	 */
	private static function content_prop( string $text ): array {
		return Elementor_MCP_Atomic_Props::is_v4()
			? Elementor_MCP_Atomic_Props::html( $text )
			: Elementor_MCP_Atomic_Props::string( $text );
	}

	/**
	 * Adds the shared link + css_id + classes tail every builder ends with.
	 *
	 * @param array $settings          Settings being built (by value).
	 * @param array $params            Convenience params.
	 * @param bool  $link_target_blank Whether to honour a target_blank flag on the link.
	 * @return array
	 */
	private static function finish( array $settings, array $params, bool $link_target_blank = false ): array {
		if ( ! empty( $params['link'] ) ) {
			$target           = $link_target_blank && ! empty( $params['target_blank'] );
			$settings['link'] = Elementor_MCP_Atomic_Props::link( esc_url_raw( $params['link'] ), $target );
		}
		if ( ! empty( $params['css_id'] ) ) {
			$settings['_cssid'] = Elementor_MCP_Atomic_Props::string( sanitize_text_field( $params['css_id'] ) );
		}

		$settings['classes'] = Elementor_MCP_Atomic_Props::classes();
		return $settings;
	}

	/**
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function heading( array $params ): array {
		$settings = array(
			'title' => self::content_prop( sanitize_text_field( $params['title'] ?? 'Heading' ) ),
			'tag'   => Elementor_MCP_Atomic_Props::string( sanitize_text_field( $params['tag'] ?? 'h2' ) ),
		);
		return self::finish( $settings, $params );
	}

	/**
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function paragraph( array $params ): array {
		// The e-paragraph content prop is named `paragraph` (Html_V3), not
		// `text`. Writing `text` silently dropped the content (issue #56).
		$settings = array(
			'paragraph' => self::content_prop( sanitize_text_field( $params['content'] ?? 'Paragraph text' ) ),
		);
		return self::finish( $settings, $params );
	}

	/**
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function button( array $params ): array {
		$settings = array(
			'text' => self::content_prop( sanitize_text_field( $params['text'] ?? 'Click Here' ) ),
		);
		return self::finish( $settings, $params, true );
	}

	/**
	 * Builds e-image settings. PURE — like every other builder.
	 *
	 * For a media-library image Elementor renders the attachment's own alt
	 * text (`_wp_attachment_image_alt`), so setting alt has an effect only
	 * there; there is no top-level `alt` prop on `e-image` (upstream #102
	 * class). That write is a mutation of a DIFFERENT post than the page
	 * being edited, so it is NOT performed here — see pending_alt_write() /
	 * apply_alt_write(), which the callers run after the page save succeeds
	 * and only for a user who may edit that attachment.
	 *
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function image( array $params ): array {
		$settings = array();

		$image_id  = absint( $params['image_id'] ?? 0 );
		$image_url = esc_url_raw( $params['image_url'] ?? '' );
		$alt       = isset( $params['alt'] ) ? sanitize_text_field( $params['alt'] ) : '';

		if ( $image_id ) {
			$settings['image'] = Elementor_MCP_Atomic_Props::image( $image_id, '', $alt );
		} elseif ( $image_url ) {
			$settings['image'] = Elementor_MCP_Atomic_Props::image( 0, $image_url, $alt );
		}

		return self::finish( $settings, $params );
	}

	/**
	 * The attachment alt-meta write a set of convenience params implies, or
	 * null when there is none.
	 *
	 * Deliberately separate from settings(): it mutates an attachment post,
	 * not the page, so it must be (a) authorized against that attachment and
	 * (b) deferred until the page write actually succeeded — an invalid
	 * parent id or a failed save must not leave a stray alt change behind.
	 *
	 * @since 1.27.0
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $params      Convenience params.
	 * @return array{attachment_id:int,alt:string}|null
	 */
	public static function pending_alt_write( string $widget_type, array $params ): ?array {
		if ( 'e-image' !== $widget_type ) {
			return null;
		}

		$image_id = absint( $params['image_id'] ?? 0 );
		$alt      = isset( $params['alt'] ) ? sanitize_text_field( $params['alt'] ) : '';

		if ( $image_id < 1 || '' === $alt ) {
			return null;
		}

		return array(
			'attachment_id' => $image_id,
			'alt'           => $alt,
		);
	}

	/**
	 * Applies a pending alt write — ONLY for a user who may edit that
	 * attachment. Editing the page a media item is placed on does not grant
	 * edit rights over the media item itself, so the page-level permission
	 * check the tools already ran is not sufficient here.
	 *
	 * Silently skips an unauthorized write: the element itself was placed
	 * successfully, and the alt text is an optional side effect.
	 *
	 * @since 1.27.0
	 *
	 * @param array|null $pending Output of pending_alt_write().
	 * @return bool True when the meta was written.
	 */
	public static function apply_alt_write( ?array $pending ): bool {
		if ( ! is_array( $pending ) || empty( $pending['attachment_id'] ) ) {
			return false;
		}

		$attachment_id = absint( $pending['attachment_id'] );
		if ( $attachment_id < 1 ) {
			return false;
		}

		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $attachment_id ) ) {
			return false;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', (string) $pending['alt'] );
		return true;
	}

	/**
	 * Builds e-svg settings. The fork's live-verified shape: `e-svg`'s prop is
	 * svg-src on 4.x (image-src on 3.x-experimental), whose validate accepts
	 * exactly ONE of id/url — an attachment id is resolved to its URL and sent
	 * as the single `url` key.
	 *
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function svg( array $params ): array {
		$settings = array();

		$svg_id  = absint( $params['svg_id'] ?? 0 );
		$svg_url = esc_url_raw( $params['svg_url'] ?? '' );

		$src_url = $svg_id ? ( wp_get_attachment_url( $svg_id ) ?: '' ) : $svg_url;
		if ( $src_url ) {
			$settings['svg'] = array(
				'$$type' => Elementor_MCP_Atomic_Props::is_v4() ? 'svg-src' : 'image-src',
				'value'  => array( 'url' => Elementor_MCP_Atomic_Props::url( $src_url ) ),
			);
		}

		return self::finish( $settings, $params );
	}

	/**
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function youtube( array $params ): array {
		// e-youtube's video prop is `source`, a plain string (union), NOT the
		// video-src shape the self-hosted video widget uses.
		$settings = array(
			'source' => Elementor_MCP_Atomic_Props::string( esc_url_raw( $params['video_url'] ?? '' ) ),
		);
		return self::finish( $settings, $params );
	}

	/**
	 * Builds e-self-hosted-video settings. On 4.x its `source` prop is a
	 * `video-src` SHAPE (id XOR url) — a bare url envelope makes Elementor
	 * refuse the element outright on 4.2+ (upstream 3.6.2 finding). On
	 * 3.x-experimental the fork's original plain-url shape is kept.
	 *
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function video( array $params ): array {
		$settings = array();

		$video_id  = absint( $params['video_id'] ?? 0 );
		$video_url = esc_url_raw( $params['video_url'] ?? '' );

		if ( Elementor_MCP_Atomic_Props::is_v4() ) {
			if ( $video_id ) {
				$settings['source'] = Elementor_MCP_Atomic_Props::video_src( $video_id );
			} elseif ( $video_url ) {
				$settings['source'] = Elementor_MCP_Atomic_Props::video_src( 0, $video_url );
			}
		} else {
			$src_url = $video_id ? ( wp_get_attachment_url( $video_id ) ?: '' ) : $video_url;
			if ( $src_url ) {
				$settings['source'] = Elementor_MCP_Atomic_Props::url( $src_url );
			}
		}

		return self::finish( $settings, $params );
	}

	/**
	 * @param array $params Convenience params.
	 * @return array
	 */
	private static function divider( array $params ): array {
		return self::finish( array(), $params );
	}
}
