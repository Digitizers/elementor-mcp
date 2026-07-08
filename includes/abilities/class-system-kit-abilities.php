<?php
/**
 * System-kit MCP abilities for Elementor.
 *
 * Registers 2 tools that replace the active Elementor kit's four SYSTEM
 * color/typography slots (unlike the additive update-global-* tools, which
 * write to the custom_* arrays):
 *
 *   - replace-system-colors    (atomic 4-slot color replace)
 *   - replace-system-typography(atomic 4-slot typography replace)
 *
 * Both operate on THIS site's own kit and need no hosted content, so they
 * register whenever the fork tool pack is enabled — the first layer of the
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
	 * Returns the ability names registered by this class — the two local
	 * system-kit writers, which ship on any fork install with the pack enabled.
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
		return $names;
	}

	/**
	 * Registers system-kit abilities — the two local writers on any fork
	 * install with the pack enabled.
	 *
	 * @since 1.8.0
	 */
	public function register(): void {
		if ( $this->has_access() ) {
			$this->register_replace_system_colors();
			$this->register_replace_system_typography();
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
					'governance'   => array( 'scope' => 'kit' ),
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
					'governance'   => array( 'scope' => 'kit' ),
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
