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
	 * Fields whose value IS raw CSS under a name other than Elementor's
	 * `custom_css` key (plan B R3/R4). ability => field paths: a top-level
	 * key, or a dotted path through OBJECT keys (`spec.styles` — the custom
	 * widget tools nest their code under `spec`). No `[]` segments: a field
	 * inside a list needs handler code, not a map entry.
	 *
	 * @since 1.37.0
	 */
	const RAW_CSS_FIELDS = array(
		'elementor-mcp/add-custom-css'       => array( 'css' ),
		'elementor-mcp/create-custom-widget' => array( 'spec.styles' ),
		'elementor-mcp/update-custom-widget' => array( 'spec.styles' ),
	);

	/**
	 * Fields that may carry CSS inside content this class does not parse —
	 * a non-empty value declares a conservative touch (plan B R4). Same path
	 * syntax as RAW_CSS_FIELDS.
	 *
	 * @since 1.37.0
	 */
	const CONSERVATIVE_CSS_FIELDS = array(
		// The custom widget's `spec.sections[].controls[].selectors` are raw
		// CSS too (widget-generator) and no map lists them: they are covered
		// only because `spec.html_template` is REQUIRED non-empty on every
		// valid create/update, so every such call already declares
		// custom_css:* here. If html_template ever becomes optional, the
		// selectors need their own handler code (a `[]` path).
		'elementor-mcp/create-custom-widget' => array( 'spec.html_template' ),
		'elementor-mcp/update-custom-widget' => array( 'spec.html_template' ),
		'elementor-mcp/add-code-snippet'     => array( 'code' ),
	);

	/** NOT_CSS_FIELDS reason: V4 `border_style` shortcut → one `border-style` prop value; the schema does not enforce a keyword (class-atomic-styles.php:306,586). @since 1.37.0 */
	const NOT_CSS_BORDER_STYLE = 'descriptive: free-text value of the one V4 `border-style` style prop (stored as-is, class-atomic-styles.php:306) — a prop value like `color`, not custom CSS text';

	/** NOT_CSS_FIELDS reason: V4 `css_position` enum → one `position` prop. @since 1.37.0 */
	const NOT_CSS_POSITION = 'descriptive: V4 position enum static|relative|absolute|fixed|sticky (class-atomic-styles.php:617,714)';

	/** NOT_CSS_FIELDS reason: V4 `css_id` → the element's `_cssid` attribute. @since 1.37.0 */
	const NOT_CSS_ID = 'descriptive: element HTML id → _cssid, sanitize_text_field (class-atomic-widget-map.php:123, class-atomic-layout-abilities.php:152)';

	/** NOT_CSS_FIELDS reason: kit typography `font_style`. @since 1.37.0 */
	const NOT_CSS_FONT_STYLE = 'descriptive: free-text value of the kit typography font_style prop (plain string, class-system-kit-abilities.php:137) — a prop value, not custom CSS text';

	/**
	 * Raw-CSS-NAMED input fields that are not custom CSS, each with its reason
	 * (plan B R5/R6). The invariant test requires every raw-CSS-named property
	 * of every write ability to be in exactly one of the three maps, and every
	 * string property of a walked ability that matches the opaque-content net
	 * (content/html/markup/template/code) to be classified too. Keys are
	 * schema paths (`slides[].content`), since nothing here is read by
	 * css_touches(). Reasons: `descriptive:` (an enum / id / slug / plain
	 * text the net matches by name only), `design_system (R6)`, `content
	 * (R5)`, or `walked (R3)` (an array the walker already reads).
	 *
	 * @since 1.37.0
	 */
	const NOT_CSS_FIELDS = array(
		// Descriptive strings the name net matches by name only.
		'elementor-mcp/create-page'               => array(
			'template' => 'descriptive: WordPress page-template slug → _wp_page_template (class-page-abilities.php:234)',
		),
		// Array-typed element tree: walked by walk_custom_css() (R3) — the field
		// itself is not raw CSS, and a conservative entry would over-declare every import.
		'elementor-mcp/import-template'           => array(
			'template_json' => 'walked (R3): array element tree — its custom_css keys are read by walk_custom_css(); no json_decode of a string (class-page-abilities.php:443-468)',
		),
		'elementor-mcp/add-divider'               => array(
			'style' => 'descriptive: border-style enum solid|dashed|dotted|double (class-widget-abilities.php:874)',
		),
		'elementor-mcp/add-star-rating'           => array(
			'star_style' => 'descriptive: icon-set enum star_fontawesome|star_unicode (class-widget-abilities.php:1168)',
		),
		'elementor-mcp/add-animated-headline'     => array(
			'headline_style' => 'descriptive: animation enum highlight|rotate (class-widget-abilities.php:1545)',
		),
		'elementor-mcp/add-slides'                => array(
			'slides[].custom_css_class'  => 'descriptive: a CSS class NAME on the slide, not CSS text (class-widget-abilities.php:1605)',
			'slides[].content_animation' => 'descriptive: free-text entrance-animation name stored as a setting, e.g. fadeInUp (class-widget-abilities.php:1604) — a name, not CSS text',
		),
		'elementor-mcp/add-loop-grid'             => array(
			'template_id' => 'descriptive: loop-template post id (class-widget-abilities.php:2183)',
		),
		'elementor-mcp/add-loop-carousel'         => array(
			'template_id' => 'descriptive: loop-template post id (class-widget-abilities.php:2210)',
		),
		'elementor-mcp/apply-template'            => array(
			'template_id' => 'descriptive: saved-template post id, absint (class-template-abilities.php:300)',
		),
		'elementor-mcp/save-as-template'          => array(
			'template_type' => 'descriptive: enum page|section|container, sanitize_key (class-template-abilities.php:140,178)',
		),
		'elementor-mcp/create-theme-template'     => array(
			'template_type' => 'descriptive: theme-template type enum, sanitize_key (class-template-abilities.php:389,414)',
		),
		'elementor-mcp/add-accordion'             => array(
			'title_html_tag'     => 'descriptive: HTML tag enum h1-h6|div (class-widget-abilities.php:954)',
			'tabs[].tab_content' => 'content (R5): accordion item body text — page content the builder shows, not walked for CSS (class-widget-abilities.php:948)',
		),
		'elementor-mcp/add-toggle'                => array(
			'title_html_tag'     => 'descriptive: HTML tag enum h1-h6|div (class-widget-abilities.php:1239)',
			'tabs[].tab_content' => 'content (R5): toggle item body text — page content the builder shows, not walked for CSS (class-widget-abilities.php:1233)',
		),
		'elementor-mcp/add-atomic-paragraph'      => array(
			'content'      => 'descriptive: plain text — sanitize_text_field strips tags before it is stored (class-atomic-widget-map.php:151)',
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		// Text/HTML content settings (plan B R5: CSS embedded in content is out of scope).
		'elementor-mcp/add-tabs'                  => array(
			'tabs[].tab_content' => 'content (R5): tab body text — page content the builder shows, not walked for CSS (class-widget-abilities.php:1190)',
		),
		'elementor-mcp/add-testimonial'           => array(
			'testimonial_content' => 'content (R5): testimonial text — page content the builder shows, not walked for CSS (class-widget-abilities.php:1208)',
		),
		'elementor-mcp/add-html'                  => array(
			'html' => 'content (R5): HTML widget markup — R5 names this widget — page content the builder shows, not walked for CSS (class-widget-abilities.php:1279)',
		),
		'elementor-mcp/add-shortcode'             => array(
			'shortcode' => 'content (R5): shortcode tag rendered in the page — page content the builder shows, not walked for CSS (class-widget-abilities.php:2033)',
		),
		'elementor-mcp/add-form'                  => array(
			'form_fields[].field_html' => 'content (R5): HTML-type form field markup — page content the builder shows, not walked for CSS (class-widget-abilities.php:1310)',
		),
		'elementor-mcp/add-testimonial-carousel'  => array(
			'slides[].content' => 'content (R5): testimonial slide text — page content the builder shows, not walked for CSS (class-widget-abilities.php:1675)',
		),
		'elementor-mcp/add-blockquote'            => array(
			'blockquote_content' => 'content (R5): quote text — page content the builder shows, not walked for CSS (class-widget-abilities.php:1859)',
		),
		'elementor-mcp/add-hotspot'               => array(
			'hotspot[].hotspot_tooltip_content' => 'content (R5): tooltip text — page content the builder shows, not walked for CSS (class-widget-abilities.php:1965)',
		),
		'elementor-mcp/add-code-highlight'        => array(
			'code' => 'content (R5): source shown by the Code Highlight widget — page content the builder shows, not walked for CSS (class-widget-abilities.php:2425)',
		),
		// Design system (plan B R6): global-class styles are CSS-prop→value maps
		// stored as the class's variant props (wrap_and_validate), design_system.
		'elementor-mcp/create-global-class'       => array(
			'styles'            => 'design_system (R6): global-class base styles, CSS-prop map (class-global-classes-write-abilities.php:201,375)',
			'variants[].styles' => 'design_system (R6): global-class variant styles, CSS-prop map (class-global-classes-write-abilities.php:182,1013)',
		),
		'elementor-mcp/update-global-class'       => array(
			'styles'            => 'design_system (R6): global-class base styles, CSS-prop map (class-global-classes-write-abilities.php:239,459)',
			'variants[].styles' => 'design_system (R6): global-class variant styles, CSS-prop map (class-global-classes-write-abilities.php:182,471)',
		),
		'elementor-mcp/replace-system-typography' => array(
			'typography.primary.font_style'   => self::NOT_CSS_FONT_STYLE,
			'typography.secondary.font_style' => self::NOT_CSS_FONT_STYLE,
			'typography.text.font_style'      => self::NOT_CSS_FONT_STYLE,
			'typography.accent.font_style'    => self::NOT_CSS_FONT_STYLE,
		),
		// V4 (atomic) style shortcuts: one typed prop each, never CSS text.
		'elementor-mcp/add-atomic-widget'         => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
		),
		'elementor-mcp/update-atomic-widget'      => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
		),
		'elementor-mcp/add-atomic-heading'        => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-atomic-button'         => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-atomic-image'          => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-atomic-svg'            => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-atomic-youtube'        => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-atomic-video'          => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-atomic-divider'        => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-flexbox'               => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
		'elementor-mcp/add-div-block'             => array(
			'border_style' => self::NOT_CSS_BORDER_STYLE,
			'css_position' => self::NOT_CSS_POSITION,
			'css_id'       => self::NOT_CSS_ID,
		),
	);

	/** Input property names that can hold raw CSS text (the drift guard's net). @since 1.37.0 */
	const RAW_CSS_NAMES = array( 'css', 'styles', 'style', 'stylesheet', 'code', 'html_template' );

	/** Any property name matching this is also in the net — a new `extra_css`, `inline_style`, `template_code`. @since 1.37.0 */
	const RAW_CSS_NAME_PATTERN = '/css|style|code|template/i';

	/**
	 * Every registered write ability, reviewed for CSS (spec §4.1: "every
	 * registered ability whose writes are not read-only must appear in exactly
	 * one list"). The value says how its CSS is judged: 'walk' (custom_css
	 * keys only), 'walk+fields' (also RAW_CSS_FIELDS / CONSERVATIVE_CSS_FIELDS),
	 * 'always' (in ALWAYS_CONSERVATIVE_CSS: every call declares a conservative
	 * touch), or 'none: <reason>' (cannot carry CSS). A write ability missing here
	 * fails the build — a human looks at every new one. Reviewed by reading
	 * each ability's execute path with every registration gate open (Pro,
	 * atomic, variables, interactions): anything that saves page, element,
	 * kit or template data is at least 'walk'.
	 *
	 * @since 1.37.0
	 */
	const CSS_REVIEWED = array(
		'elementor-mcp/create-page'               => 'walk',
		'elementor-mcp/update-page-settings'      => 'walk',
		'elementor-mcp/delete-page-content'       => 'walk',
		'elementor-mcp/import-template'           => 'walk',
		'elementor-mcp/add-container'             => 'walk',
		'elementor-mcp/update-container'          => 'walk',
		'elementor-mcp/update-element'            => 'walk',
		'elementor-mcp/batch-update'              => 'walk',
		'elementor-mcp/reorder-elements'          => 'walk',
		'elementor-mcp/move-element'              => 'walk',
		'elementor-mcp/remove-element'            => 'walk',
		'elementor-mcp/duplicate-element'         => 'always',
		'elementor-mcp/add-widget'                => 'walk',
		'elementor-mcp/update-widget'             => 'walk',
		'elementor-mcp/add-heading'               => 'walk',
		'elementor-mcp/add-text-editor'           => 'walk',
		'elementor-mcp/add-image'                 => 'walk',
		'elementor-mcp/add-button'                => 'walk',
		'elementor-mcp/add-video'                 => 'walk',
		'elementor-mcp/add-icon'                  => 'walk',
		'elementor-mcp/add-spacer'                => 'walk',
		'elementor-mcp/add-divider'               => 'walk',
		'elementor-mcp/add-icon-box'              => 'walk',
		'elementor-mcp/add-accordion'             => 'walk',
		'elementor-mcp/add-alert'                 => 'walk',
		'elementor-mcp/add-counter'               => 'walk',
		'elementor-mcp/add-google-maps'           => 'walk',
		'elementor-mcp/add-icon-list'             => 'walk',
		'elementor-mcp/add-image-box'             => 'walk',
		'elementor-mcp/add-image-carousel'        => 'walk',
		'elementor-mcp/add-progress'              => 'walk',
		'elementor-mcp/add-social-icons'          => 'walk',
		'elementor-mcp/add-star-rating'           => 'walk',
		'elementor-mcp/add-tabs'                  => 'walk',
		'elementor-mcp/add-testimonial'           => 'walk',
		'elementor-mcp/add-toggle'                => 'walk',
		'elementor-mcp/add-html'                  => 'walk',
		'elementor-mcp/add-menu-anchor'           => 'walk',
		'elementor-mcp/add-shortcode'             => 'walk',
		'elementor-mcp/add-rating'                => 'walk',
		'elementor-mcp/add-text-path'             => 'walk',
		'elementor-mcp/add-form'                  => 'walk',
		'elementor-mcp/add-posts-grid'            => 'walk',
		'elementor-mcp/add-countdown'             => 'walk',
		'elementor-mcp/add-price-table'           => 'walk',
		'elementor-mcp/add-flip-box'              => 'walk',
		'elementor-mcp/add-animated-headline'     => 'walk',
		'elementor-mcp/add-call-to-action'        => 'walk',
		'elementor-mcp/add-slides'                => 'walk',
		'elementor-mcp/add-testimonial-carousel'  => 'walk',
		'elementor-mcp/add-price-list'            => 'walk',
		'elementor-mcp/add-gallery'               => 'walk',
		'elementor-mcp/add-share-buttons'         => 'walk',
		'elementor-mcp/add-table-of-contents'     => 'walk',
		'elementor-mcp/add-blockquote'            => 'walk',
		'elementor-mcp/add-lottie'                => 'walk',
		'elementor-mcp/add-hotspot'               => 'walk',
		'elementor-mcp/add-nav-menu'              => 'walk',
		'elementor-mcp/add-loop-grid'             => 'always',
		'elementor-mcp/add-loop-carousel'         => 'always',
		'elementor-mcp/add-media-carousel'        => 'walk',
		'elementor-mcp/add-nested-tabs'           => 'walk',
		'elementor-mcp/add-nested-accordion'      => 'walk',
		'elementor-mcp/add-portfolio'             => 'walk',
		'elementor-mcp/add-author-box'            => 'walk',
		'elementor-mcp/add-login'                 => 'walk',
		'elementor-mcp/add-code-highlight'        => 'walk',
		'elementor-mcp/add-reviews'               => 'walk',
		'elementor-mcp/add-off-canvas'            => 'walk',
		'elementor-mcp/add-progress-tracker'      => 'walk',
		'elementor-mcp/add-search'                => 'walk',
		'elementor-mcp/save-as-template'          => 'always',
		'elementor-mcp/apply-template'            => 'always',
		'elementor-mcp/create-theme-template'     => 'walk',
		'elementor-mcp/set-template-conditions'   => 'always',
		'elementor-mcp/set-dynamic-tag'           => 'walk',
		'elementor-mcp/create-popup'              => 'walk',
		'elementor-mcp/set-popup-settings'        => 'always',
		'elementor-mcp/update-global-colors'      => 'walk',
		'elementor-mcp/update-global-typography'  => 'walk',
		'elementor-mcp/build-page'                => 'walk',
		'elementor-mcp/sideload-image'            => 'none: media-library attachment only (media_handle_sideload, class-stock-image-abilities.php:393) — no Elementor page/element/kit/template data',
		'elementor-mcp/add-stock-image'           => 'walk',
		'elementor-mcp/upload-svg-icon'           => 'none: media-library SVG attachment only (class-svg-icon-abilities.php:163) — no Elementor page/element/kit/template data; svg_content is SVG markup where a <style> survives sanitize_svg_content, but the upload places nothing — it reaches a page only when add-icon places the icon, which is content (R5)',
		'elementor-mcp/add-custom-js'             => 'walk',
		'elementor-mcp/add-custom-css'            => 'walk+fields',
		'elementor-mcp/add-code-snippet'          => 'walk+fields',
		// Global-classes scope (governance resolves it to the active kit id,
		// Task 2). Their `styles` are CSS-prop maps, design_system (R6) — see
		// NOT_CSS_FIELDS. A literal `custom_css` key anywhere in the input is
		// still walked and declared custom_css:<kit> conservatively (these are
		// not CSS_PRECISE_ABILITIES): the variant builder writes its own
		// custom_css null (class-global-classes-write-abilities.php:994), so a
		// caller-supplied key is unexplained input, never evidence.
		'elementor-mcp/create-global-class'       => 'walk',
		'elementor-mcp/update-global-class'       => 'walk',
		'elementor-mcp/delete-global-class'       => 'walk',
		'elementor-mcp/apply-global-class'        => 'walk',
		'elementor-mcp/create-variable'           => 'walk',
		'elementor-mcp/edit-variable'             => 'walk',
		'elementor-mcp/delete-variable'           => 'walk',
		'elementor-mcp/restore-variable'          => 'walk',
		'elementor-mcp/add-interaction'           => 'walk',
		'elementor-mcp/edit-interaction'          => 'walk',
		'elementor-mcp/delete-interaction'        => 'walk',
		'elementor-mcp/add-atomic-widget'         => 'walk',
		'elementor-mcp/update-atomic-widget'      => 'walk',
		'elementor-mcp/add-atomic-heading'        => 'walk',
		'elementor-mcp/add-atomic-paragraph'      => 'walk',
		'elementor-mcp/add-atomic-button'         => 'walk',
		'elementor-mcp/add-atomic-image'          => 'walk',
		'elementor-mcp/add-atomic-svg'            => 'walk',
		'elementor-mcp/add-atomic-youtube'        => 'walk',
		'elementor-mcp/add-atomic-video'          => 'walk',
		'elementor-mcp/add-atomic-divider'        => 'walk',
		'elementor-mcp/add-flexbox'               => 'walk',
		'elementor-mcp/add-div-block'             => 'walk',
		'elementor-mcp/replace-system-colors'     => 'walk',
		'elementor-mcp/replace-system-typography' => 'walk',
		'elementor-mcp/generate-meta-tags'        => 'none: writes only the SEO plugin\'s title/description meta (Elementor_MCP_Seo_Meta::write, class-seo-abilities.php:328) — no Elementor data',
		'elementor-mcp/generate-schema-markup'    => 'walk',
		'elementor-mcp/fix-color-contrast'        => 'walk',
		'elementor-mcp/add-alt-text-from-context' => 'walk',
		'elementor-mcp/create-custom-widget'      => 'walk+fields',
		'elementor-mcp/update-custom-widget'      => 'walk+fields',
		'elementor-mcp/set-widget-status'         => 'always',
		'elementor-mcp/delete-custom-widget'      => 'none: deletes a custom widget record + file (Widget_Store::delete, class-widget-builder-abilities.php:693) — input is an id only',
	);

	/**
	 * Element-data keys that embed a SAVED template (and so its custom CSS)
	 * by reference (Task 3 review, I-1). A non-empty one at any depth of the
	 * input makes the walk 'unknown': the call declares a conservative
	 * custom_css touch, never precise — the input shows an id, not the CSS.
	 * This covers the generic writes (add-widget / update-widget /
	 * update-element / batch-update) and tree writes (create-page,
	 * build-page, import-template) that reach the same effect as the
	 * ALWAYS_CONSERVATIVE_CSS tools.
	 *
	 *  - `template_id`: the Loop Grid / Loop Carousel loop-item template
	 *    (this plugin's add-loop-grid / add-loop-carousel settings,
	 *    class-widget-abilities.php:2183,2210) and Elementor Pro's Template
	 *    widget (Pro 4.1.0 library/widgets/template.php:63,87; V4 template
	 *    styles, atomic-widgets/template-styles.php:67-80).
	 *  - `templateID`: Elementor Pro Global Widgets, at the element root
	 *    (Pro 4.1.0 global-widget.php:35,95).
	 *  - `component_id`: a V4 component instance renders its component's
	 *    styles into the page (core 4.1.3
	 *    components/widgets/component-instance.php:125-132).
	 *  - `additional_template_select`: WooCommerce Cart widget, empty-cart
	 *    template, rendered via `[elementor-template]` (Pro 4.2.2
	 *    modules/woocommerce/widgets/cart.php:2651; control at :526-532).
	 *  - `customize_dashboard_select`: WooCommerce My Account widget, custom
	 *    dashboard template, rendered the same way (Pro 4.2.2
	 *    modules/woocommerce/widgets/my-account.php:2141,2158; control at
	 *    :303-309).
	 *
	 * Not included: `_skin` — a skin enum (post|post_taxonomy), not a
	 * template reference. Popup references are OUT OF SCOPE by ruling: they
	 * link another document, governed under its own id — do not add them.
	 *
	 * @since 1.37.0
	 */
	const EMBED_KEYS = array( 'template_id', 'templateID', 'component_id', 'additional_template_select', 'customize_dashboard_select' );

	/**
	 * A string value (any key, any depth) containing this shortcode embeds a
	 * saved template — and its custom CSS, rendered with `$include_css`
	 * (Pro modules/library/classes/shortcode.php:64) — so the walk is
	 * 'unknown' (final review M-1). Matched case-insensitively.
	 *
	 * OUT OF SCOPE by ruling: custom JS that injects CSS at runtime
	 * (add-custom-js `js`, custom-widget `spec.scripts`) is not detected.
	 *
	 * @since 1.37.0
	 */
	const TEMPLATE_SHORTCODE_NEEDLE = '[elementor-template';

	/**
	 * Abilities whose effect copies or activates EXISTING custom CSS into a
	 * target while nothing in their input shows it (Task 3 review, C4): the
	 * CSS rides on a template, element or widget named by id, so the walker
	 * sees only the id. Each call declares a conservative custom_css touch —
	 * never precise, never css_only, whatever the input — so an operator's
	 * `block custom_css` still applies and an `allow custom_css` can never
	 * admit one. Cost: every call is declared, CSS or not (an over-block, by
	 * design).
	 *
	 * The value is where that CSS lands: 'target' = only the page governance
	 * resolved (the touch carries its id); '*' = beyond it, so the touch is
	 * the wildcard even when governance passes a digit id — SiteAgent matches
	 * a page-specific `block custom_css:<id>` against `custom_css:*`, and the
	 * template's own id would slip past it (Task 3 review, C4 follow-up).
	 *
	 *  - apply-template / duplicate-element: copy a template's or element's
	 *    tree (with any custom_css) into the page.
	 *  - save-as-template: copies an element's tree into a new template that
	 *    can then be applied or displayed anywhere ('*').
	 *  - add-loop-grid / add-loop-carousel: render a loop template (and its
	 *    custom CSS) inside the one page being edited, by template_id.
	 *  - set-template-conditions / set-popup-settings: make a theme template
	 *    or popup — and its CSS — live on the pages its conditions name.
	 *  - set-widget-status: activating a custom widget serves its stored
	 *    `styles` site-wide (R4).
	 *
	 * @since 1.37.0
	 */
	const ALWAYS_CONSERVATIVE_CSS = array(
		'elementor-mcp/apply-template'          => 'target',
		'elementor-mcp/duplicate-element'       => 'target',
		'elementor-mcp/add-loop-grid'           => 'target',
		'elementor-mcp/add-loop-carousel'       => 'target',
		'elementor-mcp/save-as-template'        => '*',
		'elementor-mcp/set-template-conditions' => '*',
		'elementor-mcp/set-popup-settings'      => '*',
		'elementor-mcp/set-widget-status'       => '*',
	);

	/**
	 * Abilities whose execution path WRITES the CSS it is given (Codex r3 on
	 * Plan B). Only these may issue `precise` / `css_only`: an ability that
	 * merely tolerates an extra key (an open schema) would otherwise let a
	 * caller add `custom_css` to, say, a delete — declared "CSS-only", and
	 * auto-run by an `allow custom_css`. CSS found anywhere else is declared
	 * conservatively (no evidence fields).
	 *
	 * @since 1.37.0
	 */
	const CSS_PRECISE_ABILITIES = array(
		'elementor-mcp/add-custom-css',
		'elementor-mcp/update-page-settings',
		'elementor-mcp/update-element',
		'elementor-mcp/update-widget',
		'elementor-mcp/update-container',
		'elementor-mcp/batch-update',
	);

	/**
	 * Per-ability input shape that qualifies as `css_only` (spec §3: "the
	 * call's only effect is that CSS"; review r1 I-1). A generic recursive
	 * walk cannot tell a nested `settings` key — a setting being replaced —
	 * from padding: an input like `settings:{title:{custom_css:'a{}'}}`
	 * walked as "only custom_css / element_id keys at any depth" and wrongly
	 * qualified, even though it overwrites `title` with an array. Each
	 * writer's exact allowed top-level keys are listed here instead, with
	 * `settings` (or each batch op's `settings`) required to be precisely
	 * `{custom_css: <string>}` — nothing left to interpret.
	 *
	 * `add-custom-css` writes its `css` field directly (no settings merge),
	 * so it only needs its own allowed keys — this also covers its atomic
	 * (V4) path: the fork stores an element's custom CSS in a generated
	 * style class there too, so the ability's only effect is still CSS.
	 *
	 * @since 1.37.0
	 */
	const CSS_ONLY_SHAPES = array(
		'elementor-mcp/update-element'       => array( 'post_id', 'element_id', 'settings' ),
		'elementor-mcp/update-widget'        => array( 'post_id', 'element_id', 'settings' ),
		'elementor-mcp/update-container'     => array( 'post_id', 'element_id', 'settings' ),
		'elementor-mcp/update-page-settings' => array( 'post_id', 'settings' ),
		'elementor-mcp/batch-update'         => array( 'post_id', 'operations' ),
		'elementor-mcp/add-custom-css'       => array( 'post_id', 'element_id', 'css', 'replace' ),
	);

	/**
	 * The custom_css touch a write declares, in addition to its page/site
	 * touches (spec 2026-09-24 §3/§4.1). Pure.
	 *
	 * @since 1.37.0
	 * @param string $id    Target post id (digits) or '*' (create / no id).
	 * @param string $name  Ability name.
	 * @param mixed  $input Ability input.
	 * @return array
	 */
	public static function css_touches( string $id, string $name, $input ): array {
		if ( isset( self::ALWAYS_CONSERVATIVE_CSS[ $name ] ) ) {
			$lands = self::ALWAYS_CONSERVATIVE_CSS[ $name ];
			return array(
				array(
					'type' => 'custom_css',
					'id'   => '*' === $lands ? '*' : $id,
				),
			);
		}
		if ( ! is_array( $input ) ) {
			return array();
		}
		$found = 'none'; // One of: none, css, unknown.
		foreach ( self::RAW_CSS_FIELDS[ $name ] ?? array() as $field ) {
			$at = self::field_at( $input, $field );
			if ( $at[0] ) {
				$found = self::worse( $found, self::css_value( $at[1] ) );
			}
		}
		foreach ( self::CONSERVATIVE_CSS_FIELDS[ $name ] ?? array() as $field ) {
			$at = self::field_at( $input, $field );
			if ( $at[0] && 'none' !== self::css_value( $at[1] ) ) {
				$found = 'unknown';
			}
		}
		$found = self::worse( $found, self::walk_custom_css( $input ) );
		if ( 'none' === $found ) {
			return array();
		}
		$touch = array(
			'type' => 'custom_css',
			'id'   => $id,
		);
		if ( 'css' === $found && ctype_digit( $id ) && in_array( $name, self::CSS_PRECISE_ABILITIES, true ) ) {
			$touch['precise'] = true;
			if ( self::only_css( $input, $name ) ) {
				$touch['css_only'] = true;
			}
		}
		return array( $touch );
	}

	/**
	 * The value at a field-map path: a top-level key or a dotted path through
	 * object keys (`spec.styles`). A missing segment, or a non-array on the
	 * way, is "absent".
	 *
	 * @since 1.37.0
	 * @param array  $input Ability input.
	 * @param string $path  Field-map path.
	 * @return array{0:bool,1:mixed} [present, value]
	 */
	private static function field_at( array $input, string $path ): array {
		$node = $input;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return array( false, null );
			}
			$node = $node[ $segment ];
		}
		return array( true, $node );
	}

	/**
	 * How much CSS a field value is: none (null, blank, empty array), css (a
	 * non-blank string) or unknown (anything else).
	 *
	 * @since 1.37.0
	 * @param mixed $v Field value.
	 * @return string none|css|unknown
	 */
	private static function css_value( $v ): string {
		if ( null === $v ) {
			return 'none';
		}
		if ( is_string( $v ) ) {
			return '' === trim( $v ) ? 'none' : 'css';
		}
		return ( is_array( $v ) && array() === $v ) ? 'none' : 'unknown';
	}

	/**
	 * The worse of two findings: unknown beats css beats none.
	 *
	 * @since 1.37.0
	 * @param string $a A finding.
	 * @param string $b A finding.
	 * @return string none|css|unknown
	 */
	private static function worse( string $a, string $b ): string {
		if ( 'unknown' === $a || 'unknown' === $b ) {
			return 'unknown';
		}
		return ( 'css' === $a || 'css' === $b ) ? 'css' : 'none';
	}

	/**
	 * Does an EMBED_KEYS value name a template? Empty, null, false, 0 and '0'
	 * (and an empty array) mean "no embed"; anything else counts, scalar or
	 * array — a malformed ref is not proof of no CSS.
	 *
	 * @since 1.37.0
	 * @param mixed $v The EMBED_KEYS value.
	 * @return bool
	 */
	private static function is_embed( $v ): bool {
		if ( null === $v || false === $v || 0 === $v || array() === $v ) {
			return false;
		}
		if ( is_string( $v ) ) {
			$t = trim( $v );
			return '' !== $t && '0' !== $t;
		}
		return true;
	}

	/**
	 * Every `custom_css` key at any depth — plus any EMBED_KEYS reference,
	 * which makes the walk 'unknown' (conservative, never precise). Arrays only — MCP input is decoded
	 * to associative arrays, and the write handlers this class defers to
	 * (`update_element_settings( array $settings )` etc.) fatal on anything
	 * else, so a `stdClass` here is not a value this class needs to handle.
	 *
	 * @since 1.37.0
	 * @param mixed $node Input node.
	 * @return string none|css|unknown
	 */
	private static function walk_custom_css( $node ): string {
		if ( ! is_array( $node ) ) {
			return 'none';
		}
		$found = 'none';
		foreach ( $node as $k => $v ) {
			if ( 'custom_css' === $k ) {
				$found = self::worse( $found, self::css_value( $v ) );
			} elseif ( in_array( $k, self::EMBED_KEYS, true ) && self::is_embed( $v ) ) {
				// A saved template embedded by id brings whatever CSS it
				// carries; the input cannot show it (Task 3 review, I-1).
				$found = 'unknown';
			} elseif ( is_string( $v ) && self::has_template_shortcode( $v ) ) {
				// The same embed, as a shortcode in a string (final review M-1).
				$found = 'unknown';
			} elseif ( is_array( $v ) ) {
				$found = self::worse( $found, self::walk_custom_css( $v ) );
			}
		}
		return $found;
	}

	/**
	 * Whether a string embeds a saved template by shortcode — also when it is
	 * URL-encoded, as a `__dynamic__` shortcode tag stores it (final re-review
	 * N-1). Decodes at most three times, stopping once a pass changes nothing.
	 *
	 * @since 1.37.0
	 * @param string $v String value.
	 * @return bool
	 */
	private static function has_template_shortcode( string $v ): bool {
		for ( $i = 0; $i < 4; $i++ ) {
			if ( false !== stripos( $v, self::TEMPLATE_SHORTCODE_NEEDLE ) ) {
				return true;
			}
			$decoded = rawurldecode( $v );
			if ( $decoded === $v ) {
				return false;
			}
			$v = $decoded;
		}
		return false;
	}

	/**
	 * Is $name's write of $input shaped so its only effect is CSS (review r1
	 * I-1)? Looked up in CSS_ONLY_SHAPES: the input's top-level keys must be a
	 * subset of that ability's allowed set, and — for every shape but
	 * `add-custom-css` — the `settings` it carries (each batch op's, for
	 * `batch-update`) must be exactly `{custom_css: <string>}`. An ability not
	 * listed there is never css_only.
	 *
	 * @since 1.37.0
	 * @param array  $input Ability input.
	 * @param string $name  Ability name.
	 * @return bool
	 */
	private static function only_css( array $input, string $name ): bool {
		$allowed = self::CSS_ONLY_SHAPES[ $name ] ?? null;
		if ( null === $allowed || ! self::keys_subset( $input, $allowed ) ) {
			return false;
		}
		if ( 'elementor-mcp/add-custom-css' === $name ) {
			return true;
		}
		if ( 'elementor-mcp/batch-update' === $name ) {
			return self::batch_ops_only_custom_css( $input['operations'] ?? null );
		}
		return self::settings_is_only_custom_css( $input['settings'] ?? null );
	}

	/**
	 * Are $input's keys all in $allowed?
	 *
	 * @since 1.37.0
	 * @param array $input   Input to check.
	 * @param array $allowed Allowed keys.
	 * @return bool
	 */
	private static function keys_subset( array $input, array $allowed ): bool {
		foreach ( array_keys( $input ) as $k ) {
			if ( ! in_array( (string) $k, $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is $settings exactly one key, `custom_css`, holding a string?
	 *
	 * @since 1.37.0
	 * @param mixed $settings Settings value.
	 * @return bool
	 */
	private static function settings_is_only_custom_css( $settings ): bool {
		return is_array( $settings )
			&& 1 === count( $settings )
			&& array_key_exists( 'custom_css', $settings )
			&& is_string( $settings['custom_css'] );
	}

	/**
	 * Is $operations a non-empty list of ops, each
	 * `{element_id?, settings: {custom_css: <string>}}`?
	 *
	 * @since 1.37.0
	 * @param mixed $operations batch-update operations.
	 * @return bool
	 */
	private static function batch_ops_only_custom_css( $operations ): bool {
		if ( ! is_array( $operations ) || array() === $operations ) {
			return false;
		}
		foreach ( $operations as $op ) {
			if ( ! is_array( $op )
				|| ! self::keys_subset( $op, array( 'element_id', 'settings' ) )
				|| ! self::settings_is_only_custom_css( $op['settings'] ?? null )
			) {
				return false;
			}
		}
		return true;
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
	 *
	 * `state: 'outdated'` (Codex round 4) is the other absent-but-not-really
	 * case: SiteAgent's snapshot engine (\Aura_Worker_Snapshots, what
	 * Elementor_MCP_Governance::is_active() keys on) and its rules engine
	 * (\Aura_Worker_Rules, added in SiteAgent 2.10.0) are independently
	 * versioned classes from the SAME plugin. A pre-2.10 SiteAgent has the
	 * former installed but not the latter — that site has SiteAgent, just an
	 * outdated one, and must not be told "SiteAgent is not installed" (`state:
	 * 'absent'`, reserved for a site with NEITHER class). Checking
	 * class_exists() on the snapshot class here is not a new dependency on
	 * Elementor_MCP_Governance — it is the exact same soft-dependency check
	 * that class already keys its own is_active() on; this class still never
	 * calls into Elementor_MCP_Governance.
	 *
	 * This method is deliberately wrapper-agnostic: `enforced` here means "the
	 * gate would let this through", not "something is live to ask the gate" —
	 * the caller (server-info's assembly) is the one that knows whether
	 * Elementor_MCP_Governance is active, and downgrades `enforced`/`points`
	 * for its own report when it is not; this class never depends on
	 * Elementor_MCP_Governance.
	 *
	 * @since 1.32.0
	 * @since 1.32.0 `reason` on `state: 'incomplete'` (Codex round 2, corrected).
	 * @since 1.32.0 `state: 'outdated'` (Codex round 4).
	 * @return array{enforced:bool, source:string, state:string, reason?:string, ruleset:?array, points:string[]}
	 */
	public static function report(): array {
		if ( ! self::available() ) {
			$outdated = class_exists( '\Aura_Worker_Snapshots' );
			return array(
				'enforced' => false,
				'source'   => $outdated ? 'siteagent' : 'none',
				'state'    => $outdated ? 'outdated' : 'absent',
				'ruleset'  => null,
				'points'   => array(),
			);
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
