<?php
/**
 * Atomic widget MCP abilities for Elementor 4.0+.
 *
 * Registers universal add/update tools plus convenience shortcut tools
 * for atomic widgets (e-heading, e-paragraph, e-button, e-image, etc.).
 * Only registers when Elementor >= 4.0 is active.
 *
 * @package Elementor_MCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements atomic widget abilities.
 *
 * @since 1.5.0
 */
class Elementor_MCP_Atomic_Widget_Abilities {

	/** @var Elementor_MCP_Data */
	private $data;

	/** @var Elementor_MCP_Element_Factory */
	private $factory;

	/** @var string[] */
	private $ability_names = array();

	/**
	 * @param Elementor_MCP_Data            $data    The data access layer.
	 * @param Elementor_MCP_Element_Factory $factory The element factory.
	 */
	public function __construct( Elementor_MCP_Data $data, Elementor_MCP_Element_Factory $factory ) {
		$this->data    = $data;
		$this->factory = $factory;
	}

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * JSON-Schema fragment for the flat atomic styling props the factory reads
	 * (typography + common). Shared by the convenience tools and add-atomic-widget
	 * so agents discover the capability. The factory accepts more keys than are
	 * documented here (per-side padding, units); these are the common ones.
	 *
	 * @return array<string,array> Schema properties to merge into a tool's input.
	 */
	private static function style_schema_props(): array {
		return array(
			'font_size'        => array( 'type' => 'number', 'description' => __( 'Font size value (default unit px).', 'elementor-mcp' ) ),
			'font_size_unit'   => array( 'type' => 'string', 'description' => __( 'Font size unit: px, em, rem.', 'elementor-mcp' ) ),
			'font_family'      => array( 'type' => 'string', 'description' => __( 'Font family name.', 'elementor-mcp' ) ),
			'font_weight'      => array( 'type' => 'string', 'description' => __( 'Font weight (e.g. 400, 700).', 'elementor-mcp' ) ),
			'line_height'      => array( 'type' => 'number', 'description' => __( 'Line height value (default unit em).', 'elementor-mcp' ) ),
			'letter_spacing'   => array( 'type' => 'number', 'description' => __( 'Letter spacing value (default unit px).', 'elementor-mcp' ) ),
			'text_align'       => array( 'type' => 'string', 'description' => __( 'Text alignment: left, center, right, justify.', 'elementor-mcp' ) ),
			'color'            => array( 'type' => 'string', 'description' => __( 'Text color (hex/rgb).', 'elementor-mcp' ) ),
			'background_color' => array( 'type' => 'string', 'description' => __( 'Background color (hex/rgb).', 'elementor-mcp' ) ),
			'padding'          => array( 'type' => 'number', 'description' => __( 'Uniform padding value.', 'elementor-mcp' ) ),
			'border_radius'    => array( 'type' => 'number', 'description' => __( 'Border radius value.', 'elementor-mcp' ) ),
			'max_width'        => array( 'type' => 'number', 'description' => __( 'Max width value (e.g. 1360 to box a section).', 'elementor-mcp' ) ),
			'border_width'     => array( 'type' => 'number', 'description' => __( 'Border width value.', 'elementor-mcp' ) ),
			'border_color'     => array( 'type' => 'string', 'description' => __( 'Border color (hex/rgb).', 'elementor-mcp' ) ),
			'border_style'     => array( 'type' => 'string', 'description' => __( 'Border style: solid, dashed, dotted, none.', 'elementor-mcp' ) ),
			'gradient_from'    => array( 'type' => 'string', 'description' => __( 'Gradient start color (with gradient_to enables a gradient background).', 'elementor-mcp' ) ),
			'gradient_to'      => array( 'type' => 'string', 'description' => __( 'Gradient end color.', 'elementor-mcp' ) ),
			'gradient_type'    => array( 'type' => 'string', 'description' => __( 'Gradient type: linear or radial.', 'elementor-mcp' ) ),
			'gradient_angle'   => array( 'type' => 'number', 'description' => __( 'Linear gradient angle in degrees (default 135).', 'elementor-mcp' ) ),
		);
	}

	/**
	 * Registers all atomic widget abilities.
	 *
	 * Skips registration entirely if Elementor < 4.0.
	 */
	public function register(): void {
		if ( ! Elementor_MCP_Atomic_Props::is_atomic_supported() ) {
			return;
		}

		// Fatal-proof: the convenience tools delegate their settings mapping
		// to the shared widget map; without it they would register broken.
		if ( ! class_exists( 'Elementor_MCP_Atomic_Widget_Map' ) ) {
			return;
		}

		$this->register_add_atomic_widget();
		$this->register_update_atomic_widget();
		$this->register_add_atomic_heading();
		$this->register_add_atomic_paragraph();
		$this->register_add_atomic_button();
		$this->register_add_atomic_image();
		$this->register_add_atomic_svg();
		$this->register_add_atomic_youtube();
		$this->register_add_atomic_video();
		$this->register_add_atomic_divider();
	}

	// =========================================================================
	// Permission check
	// =========================================================================

	/**
	 * @param array $input Input parameters.
	 * @return true|\WP_Error
	 */
	public function check_edit_permission( $input ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit posts.', 'elementor-mcp' ) );
		}

		$post_id = $input['post_id'] ?? 0;
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'elementor-mcp' ) );
		}

		return true;
	}

	// =========================================================================
	// Universal tools
	// =========================================================================

	private function register_add_atomic_widget(): void {
		$name                  = 'elementor-mcp/add-atomic-widget';
		$this->ability_names[] = $name;

		elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Add Atomic Widget', 'elementor-mcp' ),
				'description'         => __( 'Adds any Elementor 4.0+ atomic widget to a container. Settings must use the $$type prop format. For simpler usage, prefer the convenience tools (add-atomic-heading, etc.).', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_add_atomic_widget' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'post_id'     => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'elementor-mcp' ) ),
							'parent_id'   => array( 'type' => 'string', 'description' => __( 'Parent container element ID.', 'elementor-mcp' ) ),
							'position'    => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'elementor-mcp' ) ),
							'widget_type' => array( 'type' => 'string', 'description' => __( 'Atomic widget type name (e.g. e-heading, e-button).', 'elementor-mcp' ) ),
							'settings'    => array( 'type' => 'object', 'description' => __( 'Widget settings with $$type-wrapped values.', 'elementor-mcp' ) ),
						),
						self::style_schema_props()
					),
					'required'   => array( 'post_id', 'parent_id', 'widget_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'element_id' => array( 'type' => 'string' ) ),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_add_atomic_widget( $input ) {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$parent_id   = sanitize_text_field( $input['parent_id'] ?? '' );
		$position    = (int) ( $input['position'] ?? -1 );
		$widget_type = sanitize_text_field( $input['widget_type'] ?? '' );
		$settings    = $input['settings'] ?? array();

		if ( empty( $widget_type ) ) {
			return new \WP_Error( 'missing_widget_type', __( 'widget_type is required.', 'elementor-mcp' ) );
		}

		// Flat style props (color, font_size, padding, ...) are read from $input
		// by the factory's build_common/typography props; unknown keys ignored.
		$element = $this->factory->create_atomic_widget( $widget_type, $settings, $input );

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$inserted = $this->data->insert_element( $page_data, $parent_id, $element, $position );
		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		// insert_element() returns a bool and mutates $page_data by reference.
		// save_page_data() expects the page-data array — passing $inserted (bool)
		// here raised a TypeError and broke add-atomic-widget entirely.
		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			// Schema-in-error: if Elementor rejected the atomic settings, return the
			// widget's compact prop schema so the agent can fix it in one round trip.
			return Elementor_MCP_Atomic_Props::enrich_save_rejection( $save, $widget_type );
		}

		return array( 'element_id' => $element['id'] );
	}

	private function register_update_atomic_widget(): void {
		$name                  = 'elementor-mcp/update-atomic-widget';
		$this->ability_names[] = $name;

		elementor_mcp_register_ability(
			$name,
			array(
				'label'               => __( 'Update Atomic Widget', 'elementor-mcp' ),
				'description'         => __( 'Updates settings on an existing Elementor 4.0+ atomic widget. Performs a partial merge — only provided keys are changed.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_update_atomic_widget' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'elementor-mcp' ) ),
						'element_id' => array( 'type' => 'string', 'description' => __( 'The element ID to update.', 'elementor-mcp' ) ),
						'settings'   => array( 'type' => 'object', 'description' => __( 'Partial settings to merge ($$type-wrapped values).', 'elementor-mcp' ) ),
					),
					'required'   => array( 'post_id', 'element_id', 'settings' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input Input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_atomic_widget( $input ) {
		$post_id    = absint( $input['post_id'] ?? 0 );
		$element_id = sanitize_text_field( $input['element_id'] ?? '' );
		$settings   = $input['settings'] ?? array();

		$page_data = $this->data->get_page_data( $post_id );
		if ( is_wp_error( $page_data ) ) {
			return $page_data;
		}

		$updated = $this->data->update_element_settings( $page_data, $element_id, $settings );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// update_element_settings() returns a bool and mutates $page_data by
		// reference — pass the array, not the bool (same TypeError class as
		// add-atomic-widget above).
		$save = $this->data->save_page_data( $post_id, $page_data );
		if ( is_wp_error( $save ) ) {
			// Schema-in-error: resolve the element's atomic type and attach its
			// compact prop schema to a rejection so the agent can self-correct.
			$element     = $this->data->find_element_by_id( $page_data, $element_id );
			$widget_type = is_array( $element ) ? (string) ( $element['widgetType'] ?? $element['elType'] ?? '' ) : '';
			return Elementor_MCP_Atomic_Props::enrich_save_rejection( $save, $widget_type );
		}

		return array( 'success' => true );
	}

	// =========================================================================
	// Convenience tools
	// =========================================================================

	/**
	 * Shared registration for atomic convenience tools.
	 *
	 * @param string   $name         Tool name without prefix.
	 * @param string   $label        Human-readable label.
	 * @param string   $description  Tool description.
	 * @param array    $extra_props  Additional JSON Schema properties.
	 * @param array    $required     Additional required fields.
	 * @param string   $widget_type  The atomic widget type (e.g. 'e-heading').
	 * @param callable $settings_fn  Builds $$type settings from flat input.
	 */
	private function register_atomic_convenience(
		string $name,
		string $label,
		string $description,
		array $extra_props,
		array $required,
		string $widget_type,
		callable $settings_fn
	): void {
		$full_name             = 'elementor-mcp/' . $name;
		$this->ability_names[] = $full_name;

		$base_props = array_merge(
			array(
				'post_id'   => array( 'type' => 'integer', 'description' => __( 'The post/page ID.', 'elementor-mcp' ) ),
				'parent_id' => array( 'type' => 'string', 'description' => __( 'Parent container element ID (e-flexbox or e-div-block).', 'elementor-mcp' ) ),
				'position'  => array( 'type' => 'integer', 'description' => __( 'Insert position. -1 = append.', 'elementor-mcp' ) ),
			),
			self::style_schema_props()
		);

		$all_required = array_unique( array_merge( array( 'post_id', 'parent_id' ), $required ) );

		elementor_mcp_register_ability(
			$full_name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'elementor-mcp',
				'execute_callback'    => function ( $input ) use ( $widget_type, $settings_fn ) {
					$settings = $settings_fn( $input );
					// Flat style props (color/spacing + typography) are read from $input
					// by the factory — routes the convenience tools through the same
					// styled-widget path as add-atomic-widget.
					$element  = $this->factory->create_atomic_widget( $widget_type, $settings, $input );

					$post_id   = absint( $input['post_id'] ?? 0 );
					$parent_id = sanitize_text_field( $input['parent_id'] ?? '' );
					$position  = (int) ( $input['position'] ?? -1 );

					$page_data = $this->data->get_page_data( $post_id );
					if ( is_wp_error( $page_data ) ) {
						return $page_data;
					}

					$ok = $this->data->insert_element( $page_data, $parent_id, $element, $position );
					if ( ! $ok ) {
						return new \WP_Error( 'not_found', "Parent element '{$parent_id}' not found in page {$post_id}." );
					}

					// The attachment alt write an e-image implies is applied ONLY
					// after the page write succeeded, and only for a user who may
					// edit that attachment — page-edit rights do not extend to the
					// media item (Codex retro-round P1).
					$pending_alt = Elementor_MCP_Atomic_Widget_Map::pending_alt_write( $widget_type, $input );

					$save = $this->data->save_page_data( $post_id, $page_data );
					if ( is_wp_error( $save ) ) {
						return Elementor_MCP_Atomic_Props::enrich_save_rejection( $save, $widget_type );
					}

					Elementor_MCP_Atomic_Widget_Map::apply_alt_write( $pending_alt );

					return array( 'element_id' => $element['id'] );
				},
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge( $base_props, $extra_props ),
					'required'   => $all_required,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array( 'element_id' => array( 'type' => 'string' ) ),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	// -------------------------------------------------------------------------

	private function register_add_atomic_heading(): void {
		$this->register_atomic_convenience(
			'add-atomic-heading',
			__( 'Add Atomic Heading', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic heading element. Accepts plain text and tag; $$type wrapping is handled automatically.', 'elementor-mcp' ),
			array(
				'title'  => array( 'type' => 'string', 'description' => __( 'Heading text content.', 'elementor-mcp' ) ),
				'tag'    => array( 'type' => 'string', 'enum' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), 'description' => __( 'HTML tag. Default: h2.', 'elementor-mcp' ) ),
				'link'   => array( 'type' => 'string', 'description' => __( 'Optional URL to link the heading.', 'elementor-mcp' ) ),
				'css_id' => array( 'type' => 'string', 'description' => __( 'Optional CSS ID for the element.', 'elementor-mcp' ) ),
			),
			array(),
			'e-heading',
			function ( $input ) {
				// Single source of the mapping — build-page consumes the same
				// class, so both produce byte-identical settings (P3.3 #9).
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-heading', $input );
			}
		);
	}

	private function register_add_atomic_paragraph(): void {
		$this->register_atomic_convenience(
			'add-atomic-paragraph',
			__( 'Add Atomic Paragraph', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic paragraph element.', 'elementor-mcp' ),
			array(
				'content' => array( 'type' => 'string', 'description' => __( 'Paragraph text content.', 'elementor-mcp' ) ),
				'link'    => array( 'type' => 'string', 'description' => __( 'Optional URL to link the paragraph.', 'elementor-mcp' ) ),
				'css_id'  => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array(),
			'e-paragraph',
			function ( $input ) {
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-paragraph', $input );
			}
		);
	}

	private function register_add_atomic_button(): void {
		$this->register_atomic_convenience(
			'add-atomic-button',
			__( 'Add Atomic Button', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic button element.', 'elementor-mcp' ),
			array(
				'text'         => array( 'type' => 'string', 'description' => __( 'Button label text.', 'elementor-mcp' ) ),
				'link'         => array( 'type' => 'string', 'description' => __( 'Button URL.', 'elementor-mcp' ) ),
				'target_blank' => array( 'type' => 'boolean', 'description' => __( 'Open in new tab.', 'elementor-mcp' ) ),
				'css_id'       => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array(),
			'e-button',
			function ( $input ) {
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-button', $input );
			}
		);
	}

	private function register_add_atomic_image(): void {
		$this->register_atomic_convenience(
			'add-atomic-image',
			__( 'Add Atomic Image', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic image element. Provide either image_id (from media library) or image_url.', 'elementor-mcp' ),
			array(
				'image_id'  => array( 'type' => 'integer', 'description' => __( 'WordPress media library attachment ID.', 'elementor-mcp' ) ),
				'image_url' => array( 'type' => 'string', 'description' => __( 'Image URL (if not using media library).', 'elementor-mcp' ) ),
				'alt'       => array( 'type' => 'string', 'description' => __( 'Alt text for the image.', 'elementor-mcp' ) ),
				'link'      => array( 'type' => 'string', 'description' => __( 'Optional link URL.', 'elementor-mcp' ) ),
				'css_id'    => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array(),
			'e-image',
			function ( $input ) {
				// Delegates the whole mapping, including the id-XOR-url
				// image-src shape (upstream #74 — the old both-keys shape is
				// rejected on 4.x) and the attachment alt-meta write.
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-image', $input );
			}
		);
	}

	private function register_add_atomic_svg(): void {
		$this->register_atomic_convenience(
			'add-atomic-svg',
			__( 'Add Atomic SVG', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic SVG element.', 'elementor-mcp' ),
			array(
				'svg_id'  => array( 'type' => 'integer', 'description' => __( 'WordPress media library SVG attachment ID.', 'elementor-mcp' ) ),
				'svg_url' => array( 'type' => 'string', 'description' => __( 'SVG URL (if not using media library).', 'elementor-mcp' ) ),
				'css_id'  => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array(),
			'e-svg',
			function ( $input ) {
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-svg', $input );
			}
		);
	}

	private function register_add_atomic_youtube(): void {
		$this->register_atomic_convenience(
			'add-atomic-youtube',
			__( 'Add Atomic YouTube', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic YouTube video element.', 'elementor-mcp' ),
			array(
				'video_url' => array( 'type' => 'string', 'description' => __( 'YouTube video URL.', 'elementor-mcp' ) ),
				'css_id'    => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array( 'video_url' ),
			'e-youtube',
			function ( $input ) {
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-youtube', $input );
			}
		);
	}

	private function register_add_atomic_video(): void {
		$this->register_atomic_convenience(
			'add-atomic-video',
			__( 'Add Atomic Video', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic self-hosted video element.', 'elementor-mcp' ),
			array(
				'video_url' => array( 'type' => 'string', 'description' => __( 'Self-hosted video URL.', 'elementor-mcp' ) ),
				'video_id'  => array( 'type' => 'integer', 'description' => __( 'Media library video attachment ID.', 'elementor-mcp' ) ),
				'css_id'    => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array(),
			'e-self-hosted-video',
			function ( $input ) {
				// On 4.x `source` is a video-src SHAPE (id XOR url) — a bare
				// url envelope is refused outright on 4.2+ (upstream 3.6.2);
				// the map keeps the fork's plain-url shape for 3.x.
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-self-hosted-video', $input );
			}
		);
	}

	private function register_add_atomic_divider(): void {
		$this->register_atomic_convenience(
			'add-atomic-divider',
			__( 'Add Atomic Divider', 'elementor-mcp' ),
			__( 'Adds an Elementor 4.0 atomic divider element.', 'elementor-mcp' ),
			array(
				'css_id' => array( 'type' => 'string', 'description' => __( 'Optional CSS ID.', 'elementor-mcp' ) ),
			),
			array(),
			'e-divider',
			function ( $input ) {
				return Elementor_MCP_Atomic_Widget_Map::settings( 'e-divider', $input );
			}
		);
	}
}
