<?php
/**
 * Registers all MCP Tools for Elementor abilities with the WordPress Abilities API.
 *
 * @package Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registrar that coordinates registration of all ability groups.
 *
 * @since 1.0.0
 */
class Elementor_MCP_Ability_Registrar {

	/**
	 * The data access layer.
	 *
	 * @var Elementor_MCP_Data
	 */
	private $data;

	/**
	 * The element factory.
	 *
	 * @var Elementor_MCP_Element_Factory
	 */
	private $factory;

	/**
	 * The schema generator.
	 *
	 * @var Elementor_MCP_Schema_Generator
	 */
	private $schema_generator;

	/**
	 * The settings validator.
	 *
	 * @var Elementor_MCP_Settings_Validator
	 */
	private $validator;

	/**
	 * All registered ability names.
	 *
	 * Protected to match register_groups(): a subclass that overrides the one
	 * must be able to see the other, or it silently writes a dynamic property
	 * and its registrations vanish.
	 *
	 * @var string[]
	 */
	protected $ability_names = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Elementor_MCP_Data               $data             The data access layer.
	 * @param Elementor_MCP_Element_Factory    $factory          The element factory.
	 * @param Elementor_MCP_Schema_Generator   $schema_generator The schema generator.
	 * @param Elementor_MCP_Settings_Validator $validator        The settings validator.
	 */
	public function __construct(
		Elementor_MCP_Data $data,
		Elementor_MCP_Element_Factory $factory,
		Elementor_MCP_Schema_Generator $schema_generator,
		Elementor_MCP_Settings_Validator $validator
	) {
		$this->data             = $data;
		$this->factory          = $factory;
		$this->schema_generator = $schema_generator;
		$this->validator        = $validator;
	}

	/**
	 * Registers all abilities across all phases.
	 *
	 * Must be called during the `wp_abilities_api_init` action.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Array of registered ability names.
	 */
	/** @var string[] Ability names registered with the Abilities API. */
	private static $registered_names = array();

	/** @var string[] Ability names actually handed to the MCP server. */
	private static $exposed_names = array();

	/**
	 * Everything registered with the Abilities API this request.
	 *
	 * @since 1.28.1
	 *
	 * @return string[]
	 */
	public static function get_registered_names(): array {
		return self::$registered_names;
	}

	/**
	 * What survived the disabled-tools filter and reached the MCP server.
	 *
	 * @since 1.28.1
	 *
	 * @return string[]
	 */
	public static function get_exposed_names(): array {
		return self::$exposed_names;
	}

	/**
	 * Seeds the registered/exposed lists.
	 *
	 * Test-only: the real values are produced by a full registration pass,
	 * which a unit test cannot run. Named with the double underscore so it
	 * reads as a seam, not API.
	 *
	 * @since 1.28.1
	 *
	 * @param string[] $registered Registered names.
	 * @param string[] $exposed    Exposed names.
	 * @return void
	 */
	public static function __set_names_for_test( array $registered, array $exposed ): void {
		self::$registered_names = $registered;
		self::$exposed_names    = $exposed;
	}

	public function register_all(): array {
		try {
			$this->register_groups();
		} catch ( \Throwable $e ) {
			// Ability registration runs on every admin page load and every REST
			// request, so an exception here is a site-wide fatal: wp-admin becomes
			// unreachable and the owner has to recover the site (upstream issue
			// #100, where a host malware scanner had quarantined one class file,
			// leaving require_once satisfied but the class undeclared).
			//
			// No single tool group is worth locking an admin out of their own
			// site. Keep whatever registered before the failure and carry on; the
			// tools from the failed group are simply absent.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'Elementor MCP: ability registration stopped early: ' . $e->getMessage() );
			}
		}

		/**
		 * Filters the registered ability names.
		 *
		 * Allows other plugins to add or modify ability names.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $ability_names The registered ability names.
		 */
		// Keep BOTH lists. `server-info` reports registered-vs-exposed, and a
		// gap that nothing can observe is what made "zero tools" undiagnosable
		// (field report #5, part 2a).
		// One well-defined point to clear the per-pass record the diagnostic
		// reads, so a previous pass can't be blamed for this one's gaps.
		if ( class_exists( 'Elementor_MCP_Plugin' ) ) {
			Elementor_MCP_Plugin::reset_removed_by_settings();
		}

		$before = $this->ability_names;

		$this->ability_names = apply_filters( 'elementor_mcp_ability_names', $this->ability_names );

		// The exemption has to hold against the WHOLE chain, not just our own
		// callback: a later callback returning a curated allowlist would
		// otherwise drop the diagnostic — leaving exactly the silent
		// "zero tools" state it exists to explain.
		if ( class_exists( 'Elementor_MCP_Server_Info_Abilities' ) ) {
			$info = Elementor_MCP_Server_Info_Abilities::ABILITY;
			if ( in_array( $info, $before, true ) && ! in_array( $info, $this->ability_names, true ) ) {
				$this->ability_names[] = $info;
			}
		}

		// A third party may ADD one of its own abilities through the same
		// documented hook. Counting only our pre-filter names would then report
		// more exposed than registered and make the suppressed arithmetic
		// nonsense, so anything that came out counts as registered too.
		// Anything can come back from a public hook. Keep strings only: a
		// callback that appends an object or an array would make array_unique()
		// throw "Object could not be converted to string" and abort
		// registration — a third party crashing the tool surface, which is the
		// failure mode elementor_mcp_require()'s guards exist to prevent.
		$this->ability_names = array_values( array_filter( $this->ability_names, 'is_string' ) );

		// Deduplicate: two integrations can append the same registered tool, and
		// the adapter stores tools by name — so counting duplicates would
		// report more exposed than exist.
		$this->ability_names = array_values( array_unique( $this->ability_names ) );

		// A callback running BEFORE this plugin's priority-10 filter can add an
		// ability that our own settings then remove. Such a name is in neither
		// $before nor the final list, so it would vanish from the accounting
		// entirely — reported as neither registered nor withheld. Our filter
		// records what it removed, which covers exactly that case.
		$removed_by_settings = class_exists( 'Elementor_MCP_Plugin' )
			? Elementor_MCP_Plugin::get_removed_by_settings()
			: array();

		$registered = array_unique( array_filter(
			array_merge( $before, $this->ability_names, $removed_by_settings ),
			'is_string'
		) );

		// NOTE: resolvability is deliberately NOT checked here. Registration
		// runs during wp_abilities_api_init, and another plugin may register
		// its hook-added ability later in that same action — at priority 20, or
		// simply after this callback. wp_get_ability() would return null for a
		// perfectly legitimate name and we would drop it. The report resolves
		// names when it is generated instead, which is long after every
		// registration has run.

		self::$registered_names = array_values( $registered );
		self::$exposed_names    = $this->ability_names;

		return $this->ability_names;
	}

	/**
	 * Registers every ability group in order. Split out of register_all() so a
	 * throwing group aborts only the remainder of registration, never the page.
	 *
	 * @since 1.27.0
	 *
	 * @return void
	 */
	protected function register_groups(): void {
		// Always first, and never suppressible: when everything else is
		// withheld this is the tool that says so.
		//
		// GUARDED, because the loader treats every source file as optional (a
		// host malware scanner quarantining one is upstream #100, the reason
		// elementor_mcp_require() exists). An unguarded `new` here would throw
		// before any other group had registered, and register_all()'s catch
		// would then keep nothing at all — turning a missing diagnostic into
		// total tool loss, which is the opposite of the point.
		if ( class_exists( 'Elementor_MCP_Server_Info_Abilities' ) ) {
			$info = new Elementor_MCP_Server_Info_Abilities();
			$info->register();
			$this->ability_names = array_merge( $this->ability_names, $info->get_ability_names() );
		}

		// Phase 1: Query/discovery abilities (P0 — read-only).
		$query = new Elementor_MCP_Query_Abilities( $this->data, $this->schema_generator );
		$query->register();
		$this->ability_names = array_merge( $this->ability_names, $query->get_ability_names() );

		// Phase 2: Page CRUD abilities (P1).
		$pages = new Elementor_MCP_Page_Abilities( $this->data, $this->factory );
		$pages->register();
		$this->ability_names = array_merge( $this->ability_names, $pages->get_ability_names() );

		// Phase 2: Layout/container abilities (P1).
		$layout = new Elementor_MCP_Layout_Abilities( $this->data, $this->factory );
		$layout->register();
		$this->ability_names = array_merge( $this->ability_names, $layout->get_ability_names() );

		// Phase 3: Widget abilities — universal + convenience (P1/P2).
		$widgets = new Elementor_MCP_Widget_Abilities( $this->data, $this->factory, $this->schema_generator, $this->validator );
		$widgets->register();
		$this->ability_names = array_merge( $this->ability_names, $widgets->get_ability_names() );

		// Phase 4: Template abilities (P2).
		$templates = new Elementor_MCP_Template_Abilities( $this->data, $this->factory );
		$templates->register();
		$this->ability_names = array_merge( $this->ability_names, $templates->get_ability_names() );

		// Phase 4: Global settings abilities (P2).
		$globals = new Elementor_MCP_Global_Abilities( $this->data );
		$globals->register();
		$this->ability_names = array_merge( $this->ability_names, $globals->get_ability_names() );

		// Phase 5: Composite abilities (P2).
		$composite = new Elementor_MCP_Composite_Abilities( $this->data, $this->factory );
		$composite->register();
		$this->ability_names = array_merge( $this->ability_names, $composite->get_ability_names() );

		// Stock image abilities (search, sideload, add).
		$stock_images = new Elementor_MCP_Stock_Image_Abilities( $this->data, $this->factory );
		$stock_images->register();
		$this->ability_names = array_merge( $this->ability_names, $stock_images->get_ability_names() );

		// SVG icon abilities (upload SVG for use as Elementor icons).
		$svg_icons = new Elementor_MCP_Svg_Icon_Abilities( $this->data, $this->factory );
		$svg_icons->register();
		$this->ability_names = array_merge( $this->ability_names, $svg_icons->get_ability_names() );

		// Custom code abilities (CSS, JS, code snippets).
		$custom_code = new Elementor_MCP_Custom_Code_Abilities( $this->data, $this->factory );
		$custom_code->register();
		$this->ability_names = array_merge( $this->ability_names, $custom_code->get_ability_names() );

		// Media Library abilities (list-media — query a site's own uploaded images).
		$media_library = new Elementor_MCP_Media_Library_Abilities( $this->data );
		$media_library->register();
		$this->ability_names = array_merge( $this->ability_names, $media_library->get_ability_names() );

		// Global Classes (Class Manager) abilities (Elementor 4.0+). Self-guards
		// on the Global Classes repository: register()/get_ability_names() are
		// no-ops when it's absent, so list-global-classes never enters the MCP
		// surface on pre-4.0 sites.
		$global_classes = new Elementor_MCP_Global_Classes_Abilities( $this->data );
		$global_classes->register();
		$this->ability_names = array_merge( $this->ability_names, $global_classes->get_ability_names() );

		// Global Classes WRITE abilities (create/update/delete/apply). Same
		// self-guard as the read group: no-ops when the Global Classes repository
		// is absent, so the four write tools never surface on pre-4.0 sites.
		$gc_write = new Elementor_MCP_Global_Classes_Write_Abilities( $this->data );
		$gc_write->register();
		$this->ability_names = array_merge( $this->ability_names, $gc_write->get_ability_names() );

		// Variables (design tokens) CRUD abilities (Elementor 4.0+). Same
		// self-guard as the Global Classes groups: no-ops when the Variables
		// repository is absent, so the six tools never surface on pre-4.0 sites.
		$variables = new Elementor_MCP_Variables_Write_Abilities( $this->data );
		$variables->register();
		$this->ability_names = array_merge( $this->ability_names, $variables->get_ability_names() );

		// Interactions (per-element animations) CRUD abilities (Elementor 4.0+).
		// Same self-guard as the Variables group: no-ops when the Interactions
		// (e_interactions) + Atomic Widgets experiments are inactive, so the four
		// tools never surface unless a write would actually persist and render.
		$interactions = new Elementor_MCP_Interactions_Write_Abilities( $this->data );
		$interactions->register();
		$this->ability_names = array_merge( $this->ability_names, $interactions->get_ability_names() );

		// Performance Analyzer (analyze-performance — read-only page + server + WP
		// audit → scored report). Independent of Elementor version; the ability's
		// manage_options permission callback is the guard.
		$performance = new Elementor_MCP_Performance_Abilities();
		$performance->register();
		$this->ability_names = array_merge( $this->ability_names, $performance->get_ability_names() );

		// Security & Malware Scanner (scan-security — read-only 4-dimension scan:
		// malware heuristics, core-integrity checksum diff, hardening audit,
		// outdated/abandoned software → scored report). Independent of Elementor
		// version; the ability's manage_options permission callback is the guard.
		$security = new Elementor_MCP_Security_Abilities();
		$security->register();
		$this->ability_names = array_merge( $this->ability_names, $security->get_ability_names() );

		// Atomic widget abilities (Elementor 4.0+). Self-guards on version check.
		$atomic_widgets = new Elementor_MCP_Atomic_Widget_Abilities( $this->data, $this->factory );
		$atomic_widgets->register();
		$this->ability_names = array_merge( $this->ability_names, $atomic_widgets->get_ability_names() );

		// Atomic layout abilities (Elementor 4.0+). Includes detect-elementor-version.
		$atomic_layout = new Elementor_MCP_Atomic_Layout_Abilities( $this->data, $this->factory );
		$atomic_layout->register();
		$this->ability_names = array_merge( $this->ability_names, $atomic_layout->get_ability_names() );

		// Brand kit / system-kit abilities (Pro only). Self-guards on Pro access:
		// register() is a no-op and get_ability_names() returns [] for free sites,
		// so the four tools never enter the MCP surface without a license.
		if ( class_exists( 'Elementor_MCP_System_Kit_Abilities' ) ) {
			$brand_kits = new Elementor_MCP_System_Kit_Abilities();
			$brand_kits->register();
			$this->ability_names = array_merge( $this->ability_names, $brand_kits->get_ability_names() );
		}

		// SEO toolkit abilities (Pro only). Self-guards on Pro access exactly
		// like the brand-kit group — register()/get_ability_names() are no-ops
		// without a license, so the tools never enter the MCP surface.
		if ( class_exists( 'Elementor_MCP_Seo_Abilities' ) ) {
			$seo = new Elementor_MCP_Seo_Abilities( $this->data );
			$seo->register();
			$this->ability_names = array_merge( $this->ability_names, $seo->get_ability_names() );
		}

		// Accessibility toolkit abilities (Pro only). Same self-guard.
		if ( class_exists( 'Elementor_MCP_A11y_Abilities' ) ) {
			$a11y = new Elementor_MCP_A11y_Abilities( $this->data );
			$a11y->register();
			$this->ability_names = array_merge( $this->ability_names, $a11y->get_ability_names() );
		}

		// Widget Builder abilities (Pro only). Self-guards on Pro access —
		// register()/get_ability_names() are no-ops without a license.
		if ( class_exists( 'Elementor_MCP_Widget_Builder_Abilities' ) ) {
			$widget_builder = new Elementor_MCP_Widget_Builder_Abilities();
			$widget_builder->register();
			$this->ability_names = array_merge( $this->ability_names, $widget_builder->get_ability_names() );
		}
	}

	/**
	 * Gets the list of registered ability names.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Array of ability names.
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}
}
