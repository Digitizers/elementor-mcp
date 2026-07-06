<?php
/**
 * Brand Kit / system-kit MCP abilities for Elementor.
 *
 * Registers 4 Pro-gated tools that replace the active Elementor kit's four
 * SYSTEM color/typography slots (unlike the additive update-global-* tools,
 * which write to the custom_* arrays):
 *
 *   - list-brand-kits          (read-only enumeration of the cached bundle)
 *   - apply-brand-kit          (composite: look up + apply colors + typography)
 *   - replace-system-colors    (atomic 4-slot color replace)
 *   - replace-system-typography(atomic 4-slot typography replace)
 *
 * All four register ONLY when the site has Pro access — the first layer of the
 * § 6.1 defense-in-depth gate. Each execute callback re-checks the gate, and
 * the underlying Elementor_MCP_System_Kit_Writer re-checks it a third time.
 *
 * See docs/BRAND_KITS_PLAN.md §§ 4.3, 6.
 *
 * @package Elementor_MCP
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the brand-kit / system-kit abilities.
 *
 * @since 1.8.0
 */
class Elementor_MCP_System_Kit_Abilities {

	/**
	 * Whether brand-kit tools should register/run on this site.
	 *
	 * @since 1.8.0
	 *
	 * @return bool
	 */
	private function has_access(): bool {
		// Fork: the Freemius paid gate is gone — the local system-kit writers
		// (replace-system-colors/typography) operate on THIS site's own kit and
		// need no hosted content, so they ship enabled (filterable via
		// emcp_fork_premium_tools_enabled).
		return function_exists( 'emcp_fork_premium_tools_enabled' ) && emcp_fork_premium_tools_enabled();
	}

	/**
	 * Whether the HOSTED brand-kit library is reachable. list-brand-kits and
	 * apply-brand-kit fetch kits from upstream's licensed content service
	 * (emcp.msrbuilds.com) — the fork does not unlock that content, so these
	 * two tools only surface on a site that actually carries a valid license.
	 * Otherwise they'd register but every call would return `no_license`.
	 *
	 * @return bool
	 */
	private function has_hosted_kit_access(): bool {
		// Also gated by the fork kill switch: the check_*_permission callbacks
		// require has_access(), so if the pack is disabled the hosted tools must
		// not register either — otherwise they'd be advertised but denied on
		// every call.
		return $this->has_access()
			&& class_exists( 'Elementor_MCP_Pro_Brand_Kits' )
			&& Elementor_MCP_Pro_Brand_Kits::user_has_access();
	}

	/**
	 * Returns the ability names registered by this class. The two local writers
	 * ship on any fork install; the two hosted-library tools only when a license
	 * makes the content reachable — so nothing surfaces that always fails.
	 *
	 * @since 1.8.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		$names = array();
		if ( $this->has_access() ) {
			$names[] = 'elementor-mcp/replace-system-colors';
			$names[] = 'elementor-mcp/replace-system-typography';
		}
		if ( $this->has_hosted_kit_access() ) {
			$names[] = 'elementor-mcp/list-brand-kits';
			$names[] = 'elementor-mcp/apply-brand-kit';
		}
		return $names;
	}

	/**
	 * Registers system-kit abilities. Local writers on any fork install; the
	 * hosted-library tools only when their content is reachable.
	 *
	 * @since 1.8.0
	 */
	public function register(): void {
		if ( $this->has_access() ) {
			$this->register_replace_system_colors();
			$this->register_replace_system_typography();
		}
		if ( $this->has_hosted_kit_access() ) {
			$this->register_list_brand_kits();
			$this->register_apply_brand_kit();
		}
	}

	/**
	 * Read permission (enumeration).
	 *
	 * @since 1.8.0
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return $this->has_access() && current_user_can( 'edit_posts' );
	}

	/**
	 * Write permission (global styling changes).
	 *
	 * @since 1.8.0
	 *
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return $this->has_access() && current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Schema fragments
	// -------------------------------------------------------------------------

	/**
	 * The shape of a single system color slot.
	 *
	 * @return array
	 */
	private function color_slot_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title' => array(
					'type'        => 'string',
					'description' => __( 'Human-readable slot title.', 'elementor-mcp' ),
				),
				'color' => array(
					'type'        => 'string',
					'description' => __( 'Hex color (e.g. "#6366F1").', 'elementor-mcp' ),
				),
			),
			'required'   => array( 'color' ),
		);
	}

	/**
	 * The shape of a single system typography slot (master-file shape).
	 *
	 * @return array
	 */
	private function typography_slot_schema(): array {
		$size_obj = array(
			'type'        => 'object',
			'description' => __( 'Slider value: { size: number, unit: string }. Omit or leave blank to clear.', 'elementor-mcp' ),
		);
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'           => array( 'type' => 'string' ),
				'font_family'     => array( 'type' => 'string' ),
				'font_weight'     => array( 'type' => 'string' ),
				'font_size'       => $size_obj,
				'line_height'     => $size_obj,
				'letter_spacing'  => $size_obj,
				'word_spacing'    => $size_obj,
				'text_transform'  => array( 'type' => 'string' ),
				'font_style'      => array( 'type' => 'string' ),
				'text_decoration' => array( 'type' => 'string' ),
				'direction'       => array( 'type' => 'string' ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// list-brand-kits
	// -------------------------------------------------------------------------

	private function register_list_brand_kits(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/list-brand-kits',
			array(
				'label'               => __( 'List Brand Kits', 'elementor-mcp' ),
				'description'         => __( 'Lists available premium brand kits (title, slug, description, category) from the cached library. Use apply-brand-kit to apply one.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_list_brand_kits' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category_slug' => array(
							'type'        => 'string',
							'description' => __( 'Optional: only list kits in this category.', 'elementor-mcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'kits' => array( 'type' => 'array' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_list_brand_kits( $input ) {
		if ( ! $this->has_hosted_kit_access() ) {
			return new \WP_Error( 'no_license', __( 'The hosted brand-kit library requires a valid EMCP Tools Pro license.', 'elementor-mcp' ) );
		}

		$category_filter = isset( $input['category_slug'] ) ? sanitize_key( (string) $input['category_slug'] ) : '';

		$bundle = Elementor_MCP_Pro_Brand_Kits::get_bundle();
		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$kits = array();
		foreach ( $bundle['categories'] ?? array() as $category ) {
			$cat_slug = isset( $category['slug'] ) ? (string) $category['slug'] : '';
			if ( '' !== $category_filter && $cat_slug !== $category_filter ) {
				continue;
			}
			foreach ( $category['kits'] ?? array() as $kit ) {
				$kits[] = array(
					'slug'        => isset( $kit['slug'] ) ? (string) $kit['slug'] : '',
					'title'       => isset( $kit['title'] ) ? (string) $kit['title'] : '',
					'description' => isset( $kit['description'] ) ? (string) $kit['description'] : '',
					'category'    => $cat_slug,
					'tags'        => isset( $kit['tags'] ) && is_array( $kit['tags'] ) ? array_map( 'strval', $kit['tags'] ) : array(),
				);
			}
		}

		return array( 'kits' => $kits );
	}

	// -------------------------------------------------------------------------
	// apply-brand-kit
	// -------------------------------------------------------------------------

	private function register_apply_brand_kit(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/apply-brand-kit',
			array(
				'label'               => __( 'Apply Brand Kit', 'elementor-mcp' ),
				'description'         => __( 'Applies a premium brand kit by slug: replaces the active Elementor kit\'s system colors and typography site-wide. Destructive — back up first (backup defaults to true).', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_apply_brand_kit' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'kit_slug'      => array(
							'type'        => 'string',
							'description' => __( 'The brand kit slug (e.g. "modern-saas").', 'elementor-mcp' ),
						),
						'category_slug' => array(
							'type'        => 'string',
							'description' => __( 'Optional category slug to disambiguate the kit.', 'elementor-mcp' ),
						),
						'backup'        => array(
							'type'        => 'boolean',
							'description' => __( 'Back up current global settings before applying. Defaults to true.', 'elementor-mcp' ),
						),
					),
					'required'   => array( 'kit_slug' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'             => array( 'type' => 'boolean' ),
						'kit_slug'            => array( 'type' => 'string' ),
						'kit_title'           => array( 'type' => 'string' ),
						'colors_applied'      => array( 'type' => 'integer' ),
						'typography_applied'  => array( 'type' => 'integer' ),
						'custom_colors_added' => array( 'type' => 'integer' ),
						'backup_id'           => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_apply_brand_kit( $input ) {
		if ( ! $this->has_hosted_kit_access() ) {
			return new \WP_Error( 'no_license', __( 'The hosted brand-kit library requires a valid EMCP Tools Pro license.', 'elementor-mcp' ) );
		}

		$kit_slug = isset( $input['kit_slug'] ) ? sanitize_key( (string) $input['kit_slug'] ) : '';
		if ( '' === $kit_slug ) {
			return new \WP_Error( 'missing_slug', __( 'A kit_slug is required.', 'elementor-mcp' ) );
		}
		$category_slug = isset( $input['category_slug'] ) ? sanitize_key( (string) $input['category_slug'] ) : '';
		$do_backup     = ! isset( $input['backup'] ) || (bool) $input['backup'];

		$kit = Elementor_MCP_Pro_Brand_Kits::find_kit( $kit_slug, $category_slug );
		if ( null === $kit ) {
			return new \WP_Error( 'kit_not_found', __( 'Brand kit not found in the cached library. Try syncing the library first.', 'elementor-mcp' ) );
		}

		// Backup-before-apply, so AI-driven applies are as recoverable as UI ones.
		$backup_id = null;
		if ( $do_backup && class_exists( 'Elementor_MCP_Kit_Backup_Store' ) ) {
			$backup = Elementor_MCP_Kit_Backup_Store::create( isset( $kit['title'] ) ? (string) $kit['title'] : $kit_slug );
			if ( ! is_wp_error( $backup ) ) {
				$backup_id = (int) $backup;
			}
		}

		$result = Elementor_MCP_Pro_Brand_Kits::apply_kit( $kit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['backup_id'] = $backup_id;
		return $result;
	}

	// -------------------------------------------------------------------------
	// replace-system-colors
	// -------------------------------------------------------------------------

	private function register_replace_system_colors(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/replace-system-colors',
			array(
				'label'               => __( 'Replace System Colors', 'elementor-mcp' ),
				'description'         => __( 'Replaces all four Elementor system color slots (primary, secondary, text, accent) atomically. All four must be provided. Propagates site-wide via global color tokens.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_replace_system_colors' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'colors' => array(
							'type'        => 'object',
							'description' => __( 'The four system color slots.', 'elementor-mcp' ),
							'properties'  => array(
								'primary'   => $this->color_slot_schema(),
								'secondary' => $this->color_slot_schema(),
								'text'      => $this->color_slot_schema(),
								'accent'    => $this->color_slot_schema(),
							),
							'required'    => array( 'primary', 'secondary', 'text', 'accent' ),
						),
					),
					'required'   => array( 'colors' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'colors_applied' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_replace_system_colors( $input ) {
		if ( ! $this->has_access() ) {
			return new \WP_Error( 'no_license', __( 'A valid EMCP Tools Pro license is required.', 'elementor-mcp' ) );
		}
		$colors = isset( $input['colors'] ) && is_array( $input['colors'] ) ? $input['colors'] : array();
		return Elementor_MCP_System_Kit_Writer::replace_system_colors( $colors );
	}

	// -------------------------------------------------------------------------
	// replace-system-typography
	// -------------------------------------------------------------------------

	private function register_replace_system_typography(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/replace-system-typography',
			array(
				'label'               => __( 'Replace System Typography', 'elementor-mcp' ),
				'description'         => __( 'Replaces all four Elementor system typography slots atomically with a full per-field reset. All four must be provided. Use master-file typography shape (font_family, font_weight, font_size {size,unit}, etc.).', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_replace_system_typography' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'typography' => array(
							'type'        => 'object',
							'description' => __( 'The four system typography slots.', 'elementor-mcp' ),
							'properties'  => array(
								'primary'   => $this->typography_slot_schema(),
								'secondary' => $this->typography_slot_schema(),
								'text'      => $this->typography_slot_schema(),
								'accent'    => $this->typography_slot_schema(),
							),
							'required'    => array( 'primary', 'secondary', 'text', 'accent' ),
						),
					),
					'required'   => array( 'typography' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'typography_applied' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_replace_system_typography( $input ) {
		if ( ! $this->has_access() ) {
			return new \WP_Error( 'no_license', __( 'A valid EMCP Tools Pro license is required.', 'elementor-mcp' ) );
		}
		$typography = isset( $input['typography'] ) && is_array( $input['typography'] ) ? $input['typography'] : array();
		return Elementor_MCP_System_Kit_Writer::replace_system_typography( $typography );
	}
}
