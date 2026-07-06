<?php
/**
 * Elementor v4 Variables (design tokens) CRUD MCP abilities — Elementor 4.0+.
 *
 * Six write/read tools that let an agent author Elementor 4's Variables (global
 * design tokens): list / get / create / edit / delete / restore. Companion to
 * the Global Classes write tools — where Global Classes are reusable style
 * bundles, Variables are the atomic tokens (colors, fonts, sizes) those styles
 * reference.
 *
 * Registers only when Elementor's Variables_Repository is present (Elementor
 * 4.0+ with the e_variables + AtomicWidgets experiments). Writes are gated on
 * `manage_options` — mutating the shared token set is a site-wide operation, not
 * per-post; the two read tools (list/get) are gated on `edit_posts`.
 *
 * Storage: variables live in the active kit's json-meta `_elementor_global_variables`,
 * accessed through Variables_Repository( Kit )->load()/->save( Variables_Collection ).
 *
 * @package Elementor_MCP
 * @since   1.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes Elementor v4 Variables (design tokens) CRUD over MCP.
 *
 * @since 1.15.0
 */
class Elementor_MCP_Variables_Write_Abilities {

	/**
	 * Elementor's Variables repository class (the availability gate).
	 */
	// Elementor's canonical Variables CRUD repository (the REST backend):
	// create/update/delete/restore on the kit's `_elementor_global_variables`
	// json-meta, operating on raw records. This is the path that maintains the
	// `deleted` tombstone flag every consumer (variables-service, the value
	// transformer, the template-library snapshot builder) filters on — the
	// entity/adapter `Variables_Repository` only sets `deleted_at` and, because
	// its save() rebuilds the record from entities, cannot preserve `deleted`.
	const REPOSITORY = '\\Elementor\\Modules\\Variables\\Storage\\Repository';

	/**
	 * Internal (stored) type key for a public `color` variable.
	 */
	const TYPE_COLOR = 'global-color-variable';

	/**
	 * Internal (stored) type key for a public `font` variable.
	 */
	const TYPE_FONT = 'global-font-variable';

	/**
	 * Internal (stored) type key for a public `size` variable (plain dimension).
	 */
	const TYPE_SIZE = 'global-size-variable';

	/**
	 * Internal (stored) type key for a public `size` variable whose value is a
	 * CSS-function expression (clamp/calc/min/max/var/env).
	 */
	const TYPE_CUSTOM_SIZE = 'global-custom-size-variable';

	/**
	 * The data access layer (kept for parity with the Global Classes write class;
	 * Variables live on the kit, not a page, so it is unused here).
	 *
	 * @var Elementor_MCP_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param Elementor_MCP_Data $data The data access layer.
	 */
	public function __construct( Elementor_MCP_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Whether Elementor exposes the Variables repository we can read/write through.
	 *
	 * Gate: the repository class must exist AND the runtime feature must be
	 * active. class_exists() alone is insufficient — Elementor's autoloader loads
	 * the Variables storage classes even when the `e_variables` / Atomic Widgets
	 * experiments are OFF and the Variables module has returned early; the
	 * repository's load()/save() only touch kit meta, so nothing would stop us
	 * writing `_elementor_global_variables` that the inactive runtime ignores (the
	 * same silent-no-op trap `Elementor_MCP_Atomic_Props::is_atomic_supported()`
	 * guards against). So we also require the `e_variables` experiment and atomic
	 * support before the six tools register.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( ! class_exists( self::REPOSITORY ) ) {
			return false;
		}
		if ( ! self::e_variables_active() ) {
			return false;
		}
		return class_exists( 'Elementor_MCP_Atomic_Props' )
			? \Elementor_MCP_Atomic_Props::is_atomic_supported()
			: true;
	}

	/**
	 * Whether Elementor's `e_variables` experiment is active. Permissive only when
	 * the experiments API can't be reached (class_exists already gated); strict
	 * (false) when the API is present and reports the feature off — the case
	 * Codex flagged.
	 *
	 * @return bool
	 */
	private static function e_variables_active(): bool {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return false;
		}
		$elementor = \Elementor\Plugin::$instance ?? null;
		if ( ! is_object( $elementor ) || ! isset( $elementor->experiments ) || ! is_object( $elementor->experiments )
			|| ! method_exists( $elementor->experiments, 'is_feature_active' ) ) {
			return true;
		}
		try {
			return (bool) $elementor->experiments->is_feature_active( 'e_variables' );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		if ( ! self::is_available() ) {
			return array();
		}
		return array(
			'elementor-mcp/list-variables',
			'elementor-mcp/get-variable',
			'elementor-mcp/create-variable',
			'elementor-mcp/edit-variable',
			'elementor-mcp/delete-variable',
			'elementor-mcp/restore-variable',
		);
	}

	/**
	 * Permission check for Variables WRITES (create/edit/delete/restore).
	 *
	 * Mutating the shared design-token set is a site-wide operation, gated on
	 * `manage_options`.
	 *
	 * @return bool
	 */
	public function check_write_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission check for Variables READS (list/get). Requires `edit_posts`.
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Registers the Variables abilities.
	 */
	public function register(): void {
		if ( ! self::is_available() ) {
			return;
		}

		$this->register_list();
		$this->register_get();
		$this->register_create();
		$this->register_edit();
		$this->register_delete();
		$this->register_restore();
	}

	// =========================================================================
	// Registration
	// =========================================================================

	private function register_list(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/list-variables',
			array(
				'label'               => __( 'List Variables', 'elementor-mcp' ),
				'description'         => __( 'Lists all Elementor 4.0+ Variables (global design tokens) — colors, fonts and sizes — excluding soft-deleted ones. Returns each variable\'s id, type (color|font|size), label, value and order. Requires edit_posts.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'     => array( 'type' => 'integer' ),
						'variables' => array( 'type' => 'array' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	private function register_get(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/get-variable',
			array(
				'label'               => __( 'Get Variable', 'elementor-mcp' ),
				'description'         => __( 'Returns a single Elementor 4.0+ Variable (design token) by its id, in public shape { id, type (color|font|size), label, value, order }. Requires edit_posts.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'variable_id' => array( 'type' => 'string', 'description' => __( 'The Variable id (e-gv-<hash>).', 'elementor-mcp' ) ),
					),
					'required'   => array( 'variable_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'string' ),
						'type'  => array( 'type' => 'string' ),
						'label' => array( 'type' => 'string' ),
						'value' => array( 'type' => 'string' ),
						'order' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	private function register_create(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/create-variable',
			array(
				'label'               => __( 'Create Variable', 'elementor-mcp' ),
				'description'         => __( 'Creates a new Elementor 4.0+ Variable (global design token). Pass label, type (color|font|size) and value. color = strict hex (e.g. #111 / #112233 / #11223344, named colors rejected); size = <number><unit> (px/em/rem/%/vw/vh/vmin/vmax/ch/pt/pc/ex/fr) OR a CSS-function expression (clamp/calc/min/max/var/env); font = a font-family name. Returns the minted e-gv- id. Requires manage_options.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_create' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'label' => array( 'type' => 'string', 'description' => __( 'Human-readable token name (no spaces, max 50 chars), e.g. "brand-primary".', 'elementor-mcp' ) ),
						'type'  => array( 'type' => 'string', 'enum' => array( 'color', 'font', 'size' ), 'description' => __( 'Token type: color | font | size.', 'elementor-mcp' ) ),
						'value' => array( 'type' => 'string', 'description' => __( 'Token value (hex color, font-family name, or dimension / CSS-function expression).', 'elementor-mcp' ) ),
					),
					'required'   => array( 'label', 'type', 'value' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'string' ),
						'label'   => array( 'type' => 'string' ),
						'type'    => array( 'type' => 'string' ),
						'created' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	private function register_edit(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/edit-variable',
			array(
				'label'               => __( 'Edit Variable', 'elementor-mcp' ),
				'description'         => __( 'Edits an existing Elementor 4.0+ Variable in place, preserving its id so bindings survive. Pass variable_id plus at least one of: label (rename), value (change token value). The public type is fixed and cannot be changed here (a size variable\'s internal dimension↔expression form is recomputed automatically when its value changes). Requires manage_options.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_edit' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'variable_id' => array( 'type' => 'string', 'description' => __( 'The Variable id to edit.', 'elementor-mcp' ) ),
						'label'       => array( 'type' => 'string', 'description' => __( 'Optional new label.', 'elementor-mcp' ) ),
						'value'       => array( 'type' => 'string', 'description' => __( 'Optional new value (validated against the variable\'s fixed type).', 'elementor-mcp' ) ),
					),
					'required'   => array( 'variable_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'string' ),
						'updated' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	private function register_delete(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/delete-variable',
			array(
				'label'               => __( 'Delete Variable', 'elementor-mcp' ),
				'description'         => __( 'Soft-deletes an Elementor 4.0+ Variable by id (tombstoned, not purged — it can be brought back with restore-variable). Elements referencing the token keep the dangling reference. Idempotent. Requires manage_options.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_delete' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'variable_id' => array( 'type' => 'string', 'description' => __( 'The Variable id to soft-delete.', 'elementor-mcp' ) ),
					),
					'required'   => array( 'variable_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'string' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	private function register_restore(): void {
		elementor_mcp_register_ability(
			'elementor-mcp/restore-variable',
			array(
				'label'               => __( 'Restore Variable', 'elementor-mcp' ),
				'description'         => __( 'Restores a previously soft-deleted Elementor 4.0+ Variable by id, bringing it back into the active token set. Requires manage_options.', 'elementor-mcp' ),
				'category'            => 'elementor-mcp',
				'execute_callback'    => array( $this, 'execute_restore' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'variable_id' => array( 'type' => 'string', 'description' => __( 'The Variable id to restore.', 'elementor-mcp' ) ),
					),
					'required'   => array( 'variable_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'string' ),
						'restored' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	// =========================================================================
	// Execute — read
	// =========================================================================

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_list( $input ) {
		if ( ! self::is_available() ) {
			return $this->unavailable();
		}

		$repo = $this->repo();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		try {
			$records = (array) $repo->variables();
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}

		$out = array();
		foreach ( $records as $id => $record ) {
			$record = (array) $record;
			if ( $this->is_tombstoned( $record ) ) {
				continue; // Tombstoned — hidden from the active list.
			}
			$out[] = $this->public_shape_from_raw( (string) $id, $record );
		}

		return array(
			'count'     => count( $out ),
			'variables' => $out,
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_get( $input ) {
		if ( ! self::is_available() ) {
			return $this->unavailable();
		}

		$variable_id = isset( $input['variable_id'] ) ? sanitize_text_field( $input['variable_id'] ) : '';
		if ( '' === $variable_id ) {
			return new \WP_Error( 'missing_variable_id', __( 'variable_id is required.', 'elementor-mcp' ) );
		}

		$repo = $this->repo();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		try {
			$records = (array) $repo->variables();
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}

		if ( ! isset( $records[ $variable_id ] ) ) {
			return $this->not_found( $variable_id );
		}
		$record = (array) $records[ $variable_id ];

		// Report tombstoned (soft-deleted) tokens as not_found so get stays
		// consistent with list — an agent re-reading a token it just deleted must
		// not see it as active and re-bind it.
		if ( $this->is_tombstoned( $record ) ) {
			return $this->not_found( $variable_id );
		}

		return $this->public_shape_from_raw( $variable_id, $record );
	}

	// =========================================================================
	// Execute — create
	// =========================================================================

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_create( $input ) {
		if ( ! self::is_available() ) {
			return $this->unavailable();
		}

		$label = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : '';
		$type  = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '';
		$value = isset( $input['value'] ) ? $this->sanitize_value( $input['value'] ) : '';

		if ( '' === $label ) {
			return new \WP_Error( 'missing_label', __( 'label is required.', 'elementor-mcp' ) );
		}
		if ( '' === $type ) {
			return new \WP_Error( 'missing_type', __( 'type is required (color | font | size).', 'elementor-mcp' ) );
		}
		if ( ! in_array( $type, array( 'color', 'font', 'size' ), true ) ) {
			return new \WP_Error(
				'invalid_type',
				sprintf( /* translators: %s: the given type */ __( 'type "%s" is not valid. Use one of: color, font, size.', 'elementor-mcp' ), $type )
			);
		}
		// Size Variables are Pro-only: Elementor's variables service filters
		// `global-size-variable` out on non-Pro sites, so it would save but never
		// render. Refuse up front instead of creating an unusable token.
		if ( 'size' === $type && ! $this->pro_active() ) {
			return new \WP_Error( 'requires_pro', __( 'Size Variables require Elementor Pro. Create color or font variables, or activate Elementor Pro.', 'elementor-mcp' ) );
		}
		if ( '' === $value ) {
			return new \WP_Error( 'missing_value', __( 'value is required.', 'elementor-mcp' ) );
		}

		$value_check = $this->validate_value( $type, $value );
		if ( is_wp_error( $value_check ) ) {
			return $value_check;
		}

		// Elementor's Variable::validate() rules (no spaces, ≤50) — Repository's
		// create() doesn't enforce these, so pre-validate the label ourselves.
		$label_check = $this->validate_label( $label );
		if ( is_wp_error( $label_check ) ) {
			return $label_check;
		}

		$internal_type = $this->resolve_internal_type( $type );

		$repo = $this->repo();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		// Repository::create enforces label uniqueness + the count cap itself
		// (skipping tombstoned rows) and mints the id + order.
		try {
			$result = $repo->create(
				array(
					'type'  => $internal_type,
					'label' => $label,
					'value' => $value,
				)
			);
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}

		$id = isset( $result['variable']['id'] ) ? (string) $result['variable']['id'] : '';

		$this->clear_cache();

		return array(
			'id'      => $id,
			'label'   => $label,
			'type'    => $type,
			'created' => true,
		);
	}

	// =========================================================================
	// Execute — edit
	// =========================================================================

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_edit( $input ) {
		if ( ! self::is_available() ) {
			return $this->unavailable();
		}

		$variable_id = isset( $input['variable_id'] ) ? sanitize_text_field( $input['variable_id'] ) : '';
		if ( '' === $variable_id ) {
			return new \WP_Error( 'missing_variable_id', __( 'variable_id is required.', 'elementor-mcp' ) );
		}

		$has_label = array_key_exists( 'label', $input );
		$has_value = array_key_exists( 'value', $input );
		if ( ! $has_label && ! $has_value ) {
			return new \WP_Error( 'nothing_to_update', __( 'Provide at least one of: label, value.', 'elementor-mcp' ) );
		}

		$repo = $this->repo();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		try {
			$records = (array) $repo->variables();
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}
		if ( ! isset( $records[ $variable_id ] ) || $this->is_tombstoned( (array) $records[ $variable_id ] ) ) {
			return $this->not_found( $variable_id );
		}
		$current = (array) $records[ $variable_id ];

		$changes = array();

		if ( $has_label ) {
			$label = sanitize_text_field( $input['label'] );
			if ( '' === $label ) {
				return new \WP_Error( 'missing_label', __( 'label cannot be empty.', 'elementor-mcp' ) );
			}
			// Repository::update does not validate the label format (no spaces,
			// ≤50 chars) — enforce it here, matching create.
			$label_check = $this->validate_label( $label );
			if ( is_wp_error( $label_check ) ) {
				return $label_check;
			}
			$changes['label'] = $label;
		}

		if ( $has_value ) {
			$value = $this->sanitize_value( $input['value'] );
			if ( '' === $value ) {
				return new \WP_Error( 'missing_value', __( 'value cannot be empty.', 'elementor-mcp' ) );
			}
			// Validate the new value against the token's fixed public kind. (The
			// public type cannot change on edit — Repository::update only merges
			// label/value/order, mirroring Elementor's own editor.)
			$public_type = $this->public_type( isset( $current['type'] ) ? (string) $current['type'] : '' );
			$value_check = $this->validate_value( $public_type, $value );
			if ( is_wp_error( $value_check ) ) {
				return $value_check;
			}
			$changes['value'] = $value;
		}

		// Repository::update enforces label uniqueness (skipping tombstones) and
		// RecordNotFound.
		try {
			$repo->update( $variable_id, $changes );
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}

		$this->clear_cache();

		return array(
			'id'      => $variable_id,
			'updated' => true,
		);
	}

	// =========================================================================
	// Execute — delete / restore
	// =========================================================================

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_delete( $input ) {
		if ( ! self::is_available() ) {
			return $this->unavailable();
		}

		$variable_id = isset( $input['variable_id'] ) ? sanitize_text_field( $input['variable_id'] ) : '';
		if ( '' === $variable_id ) {
			return new \WP_Error( 'missing_variable_id', __( 'variable_id is required.', 'elementor-mcp' ) );
		}

		$repo = $this->repo();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		// Repository::delete tombstones the row — setting BOTH `deleted` and
		// `deleted_at` — which is what every consumer (variables-service, the value
		// transformer, the template-library snapshot builder) filters on. Throws
		// RecordNotFound when the id is unknown.
		try {
			$repo->delete( $variable_id );
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}

		$this->clear_cache();

		return array(
			'id'      => $variable_id,
			'deleted' => true,
		);
	}

	/**
	 * @param array $input Input.
	 * @return array|\WP_Error
	 */
	public function execute_restore( $input ) {
		if ( ! self::is_available() ) {
			return $this->unavailable();
		}

		$variable_id = isset( $input['variable_id'] ) ? sanitize_text_field( $input['variable_id'] ) : '';
		if ( '' === $variable_id ) {
			return new \WP_Error( 'missing_variable_id', __( 'variable_id is required.', 'elementor-mcp' ) );
		}

		$repo = $this->repo();
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		try {
			$records = (array) $repo->variables();
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}
		if ( ! isset( $records[ $variable_id ] ) ) {
			return $this->not_found( $variable_id );
		}

		// Idempotent: restoring an already-active token is a no-op. Return BEFORE
		// Repository::restore's cap/uniqueness re-checks — otherwise a retry while
		// the active set is at the 1000-cap would count the target itself and
		// wrongly report limit_reached.
		if ( ! $this->is_tombstoned( (array) $records[ $variable_id ] ) ) {
			return array(
				'id'             => $variable_id,
				'restored'       => true,
				'already_active' => true,
			);
		}

		// Repository::restore clears the tombstone (drops `deleted`/`deleted_at`)
		// and re-asserts label uniqueness + the count cap against the active set.
		try {
			$repo->restore( $variable_id );
		} catch ( \Throwable $e ) {
			return $this->map_exception( $e );
		}

		$this->clear_cache();

		return array(
			'id'       => $variable_id,
			'restored' => true,
		);
	}

	// =========================================================================
	// Repository helpers
	// =========================================================================

	/**
	 * Resolves the active Elementor kit and constructs the canonical Variables
	 * Repository against it.
	 *
	 * @return object|\WP_Error The Repository, or WP_Error.
	 */
	private function repo() {
		$kit = $this->active_kit();
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		$repo_class = self::REPOSITORY;
		try {
			return new $repo_class( $kit );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'read_failed', $e->getMessage() );
		}
	}

	/**
	 * Resolves the active Elementor kit (the repository's constructor argument).
	 * Guards class_exists / isset / method_exists like the Global Classes code's
	 * active-kit resolution.
	 *
	 * @return object|\WP_Error The Kit document, or WP_Error.
	 */
	private function active_kit() {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return new \WP_Error( 'unavailable', __( 'Elementor is not active.', 'elementor-mcp' ) );
		}
		$elementor = \Elementor\Plugin::$instance ?? null;
		if ( ! is_object( $elementor ) || ! isset( $elementor->kits_manager ) || ! is_object( $elementor->kits_manager )
			|| ! method_exists( $elementor->kits_manager, 'get_active_kit' ) ) {
			return new \WP_Error( 'unavailable', __( 'Elementor kits manager is not available.', 'elementor-mcp' ) );
		}

		try {
			$kit = $elementor->kits_manager->get_active_kit();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'unavailable', $e->getMessage() );
		}

		if ( ! is_object( $kit ) ) {
			return new \WP_Error( 'no_active_kit', __( 'No active Elementor kit was found.', 'elementor-mcp' ) );
		}

		return $kit;
	}

	/**
	 * Builds the public { id, type, label, value, order } shape from a raw stored
	 * variable record (as returned by Repository::variables()).
	 *
	 * @param string $id     The variable id.
	 * @param array  $record The raw record ({ type, label, value, order, ... }).
	 * @return array
	 */
	private function public_shape_from_raw( string $id, array $record ): array {
		return array(
			'id'    => $id,
			'type'  => $this->public_type( isset( $record['type'] ) ? (string) $record['type'] : '' ),
			'label' => isset( $record['label'] ) ? (string) $record['label'] : '',
			'value' => $this->stringify_value( $record['value'] ?? '' ),
			'order' => isset( $record['order'] ) ? (int) $record['order'] : 0,
		);
	}

	// =========================================================================
	// Type resolution + value validation
	// =========================================================================

	/**
	 * Resolves a public type into the internal (stored) type key.
	 *
	 * All size tokens — plain dimensions AND CSS-function expressions — store as
	 * `global-size-variable`: that is the only size type Elementor actually
	 * REGISTERS (`Size_Variable_Prop_Type::get_key()`). `global-custom-size-variable`
	 * is an internal Prop_Type_Adapter alias, not a registered/stored type — a
	 * token stored under it would not be recognized as a size variable by the
	 * editor or bindings. The custom-ness of an expression rides on the value.
	 *
	 * @param string $public_type One of color|font|size.
	 * @return string Internal type key.
	 */
	private function resolve_internal_type( string $public_type ): string {
		switch ( $public_type ) {
			case 'color':
				return self::TYPE_COLOR;
			case 'font':
				return self::TYPE_FONT;
			case 'size':
			default:
				return self::TYPE_SIZE;
		}
	}

	/**
	 * Collapses an internal type key back to its public form (color|font|size).
	 * Both size keys fold to `size`.
	 *
	 * @param string $internal_type Internal type key.
	 * @return string
	 */
	private function public_type( string $internal_type ): string {
		switch ( $internal_type ) {
			case self::TYPE_COLOR:
				return 'color';
			case self::TYPE_FONT:
				return 'font';
			case self::TYPE_SIZE:
			case self::TYPE_CUSTOM_SIZE:
				return 'size';
			default:
				// Best-effort for unknown/future keys: infer from substring.
				if ( false !== strpos( $internal_type, 'color' ) ) {
					return 'color';
				}
				if ( false !== strpos( $internal_type, 'font' ) ) {
					return 'font';
				}
				if ( false !== strpos( $internal_type, 'size' ) ) {
					return 'size';
				}
				return $internal_type;
		}
	}

	/**
	 * Whether a size value is a CSS-function expression (→ custom-size).
	 *
	 * @param string $value Size value.
	 * @return bool
	 */
	private function is_size_expression( string $value ): bool {
		return (bool) preg_match( '/(?:clamp|calc|min|max|var|env)\s*\(/i', $value );
	}

	/**
	 * Whether a raw variable record is tombstoned (soft-deleted).
	 *
	 * Treats EITHER marker as a tombstone: the canonical Repository::delete sets
	 * both `deleted` + `deleted_at`, but the entity path / other Elementor flows
	 * can leave a record carrying only `deleted_at`. Checking just one would let
	 * such tokens leak back into list/get/edit and fool restore into a no-op.
	 *
	 * @param array $record Raw variable record.
	 * @return bool
	 */
	private function is_tombstoned( array $record ): bool {
		return ! empty( $record['deleted'] ) || ! empty( $record['deleted_at'] );
	}

	/**
	 * Validates a label against Elementor's Variable rules (no spaces, ≤50 chars).
	 * Mirrors Variable::validate(), applied to a *new* label on create/edit so an
	 * invalid rename can't slip past apply_changes() (which validates the old
	 * label before applying the change).
	 *
	 * @param string $label The label.
	 * @return true|\WP_Error
	 */
	private function validate_label( string $label ) {
		if ( strpos( $label, ' ' ) !== false ) {
			return new \WP_Error( 'invalid_variable', __( 'Variable label cannot contain spaces.', 'elementor-mcp' ) );
		}
		if ( strlen( $label ) > 50 ) {
			return new \WP_Error( 'invalid_variable', __( 'Variable label cannot be longer than 50 characters.', 'elementor-mcp' ) );
		}
		// The label becomes a CSS custom-property name emitted RAW into the kit's
		// generated `:root { --<label>:<value>; }` stylesheet (Elementor's
		// css-renderer only runs sanitize_text_field, which leaves `;{}:` intact),
		// so a label like `brand;color:red` would inject a global declaration.
		// Constrain to a CSS-ident-safe slug.
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $label ) ) {
			return new \WP_Error( 'invalid_variable', __( 'Variable label may only contain letters, digits, hyphens and underscores.', 'elementor-mcp' ) );
		}
		return true;
	}

	/**
	 * Validates a value against its public type.
	 *
	 * @param string $public_type One of color|font|size.
	 * @param string $value       The value.
	 * @return true|\WP_Error
	 */
	private function validate_value( string $public_type, string $value ) {
		// The value is emitted RAW into `--<label>:<value>;` inside the kit's
		// generated `:root {}` (Elementor's css-renderer only sanitize_text_field's
		// it), so CSS-structural characters would let a value like `Arial;color:red`
		// or `calc(1px)}html{...` inject/break global styles. Reject them up front
		// (the color + plain-dimension patterns below already exclude these; this
		// covers font values and size CSS-function expressions).
		if ( preg_match( '/[;{}<]/', $value ) ) {
			return new \WP_Error( 'invalid_value', __( 'Variable value cannot contain the characters ; { } or <.', 'elementor-mcp' ) );
		}

		switch ( $public_type ) {
			case 'color':
				if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
					return new \WP_Error(
						'invalid_value',
						sprintf(
							/* translators: %s: the rejected color value */
							__( 'Color value "%s" is not a valid hex color. Use #RGB, #RRGGBB or #RRGGBBAA (named colors are not accepted).', 'elementor-mcp' ),
							$value
						)
					);
				}
				return true;

			case 'size':
				if ( $this->is_size_expression( $value ) ) {
					return true;
				}
				if ( ! preg_match( '/^-?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(px|rem|em|%|vw|vh|vmin|vmax|ch|pt|pc|ex|fr)$/i', $value ) ) {
					return new \WP_Error(
						'invalid_value',
						sprintf(
							/* translators: %s: the rejected size value */
							__( 'Size value "%s" is not a valid dimension. Use <number><unit> (px/em/rem/%%/vw/vh/vmin/vmax/ch/pt/pc/ex/fr) or a CSS-function expression (clamp/calc/min/max/var/env).', 'elementor-mcp' ),
							$value
						)
					);
				}
				return true;

			case 'font':
			default:
				if ( '' === trim( $value ) ) {
					return new \WP_Error( 'invalid_value', __( 'Font value must be a non-empty font-family name.', 'elementor-mcp' ) );
				}
				return true;
		}
	}

	/**
	 * Sanitizes a raw value input, preserving CSS-function expressions (which may
	 * contain commas/parentheses) while stripping tags.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_value( $value ): string {
		if ( is_array( $value ) ) {
			return '';
		}
		return trim( sanitize_text_field( (string) $value ) );
	}

	/**
	 * Coerces a stored value (usually a string, but tolerant of arrays) to a
	 * string for the public shape.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private function stringify_value( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			// Tokens written through Elementor's own manager go via
			// Variables_Repository -> Prop_Type_Adapter::to_storage(), which stores
			// prop-typed ARRAY values (e.g. { $$type:'size', value:{ size, unit } }).
			// Unwrap them to a public scalar rather than returning a JSON blob.
			if ( class_exists( 'Elementor_MCP_Atomic_Props' ) ) {
				try {
					$value = \Elementor_MCP_Atomic_Props::unwrap( $value );
				} catch ( \Throwable $e ) {
					// fall through
				}
			}
			if ( is_string( $value ) ) {
				return $value;
			}
			if ( is_scalar( $value ) ) {
				return (string) $value;
			}
			if ( is_array( $value ) ) {
				// Size shape { size, unit } → "<size><unit>" (unit 'custom' rides on
				// the size, which is the raw CSS expression).
				if ( isset( $value['size'] ) && is_scalar( $value['size'] ) ) {
					$unit = ( isset( $value['unit'] ) && is_scalar( $value['unit'] ) && 'custom' !== $value['unit'] ) ? (string) $value['unit'] : '';
					return (string) $value['size'] . $unit;
				}
				// Generic single-value wrapper.
				if ( isset( $value['value'] ) && is_scalar( $value['value'] ) ) {
					return (string) $value['value'];
				}
			}
		}
		// Unknown shape — empty rather than leaking an opaque blob.
		return '';
	}

	/**
	 * Whether Elementor Pro is active. Size Variables are Pro-only — Elementor's
	 * own variables service filters `global-size-variable` out when
	 * `Elementor\Utils::has_pro()` is false, so a size token created on a Free
	 * site saves but never reaches the editor/runtime. Permissive only when
	 * has_pro() can't be resolved.
	 *
	 * @return bool
	 */
	private function pro_active(): bool {
		if ( class_exists( '\\Elementor\\Utils' ) && method_exists( '\\Elementor\\Utils', 'has_pro' ) ) {
			try {
				return (bool) \Elementor\Utils::has_pro();
			} catch ( \Throwable $e ) {
				return true;
			}
		}
		return true;
	}

	// =========================================================================
	// Errors
	// =========================================================================

	/**
	 * The standard unavailable WP_Error.
	 *
	 * @return \WP_Error
	 */
	private function unavailable(): \WP_Error {
		return new \WP_Error( 'unavailable', __( 'Elementor Variables are not available — Elementor 4.0+ with the Variables experiment is required.', 'elementor-mcp' ) );
	}

	/**
	 * The standard not-found WP_Error for a variable id.
	 *
	 * @param string $variable_id The id.
	 * @return \WP_Error
	 */
	private function not_found( string $variable_id ): \WP_Error {
		return new \WP_Error(
			'not_found',
			sprintf( /* translators: %s: variable id */ __( 'Variable "%s" was not found.', 'elementor-mcp' ), $variable_id )
		);
	}

	/**
	 * Maps an Elementor Variables exception (or any Throwable) to a clear WP_Error.
	 *
	 * Matches on the exception class-name suffix so it is robust whether or not a
	 * given build defines every exception class (referencing an undefined class in
	 * a catch is avoided; we string-match instead).
	 *
	 * @param \Throwable $e The caught exception.
	 * @return \WP_Error
	 */
	private function map_exception( \Throwable $e ): \WP_Error {
		$class   = get_class( $e );
		$message = $e->getMessage();

		if ( false !== strpos( $class, 'DuplicatedLabel' ) ) {
			return new \WP_Error( 'label_not_unique', '' !== $message ? $message : __( 'A Variable with this label already exists (labels are case-insensitive).', 'elementor-mcp' ) );
		}
		if ( false !== strpos( $class, 'VariablesLimitReached' ) ) {
			return new \WP_Error( 'limit_reached', '' !== $message ? $message : __( 'Elementor\'s Variables limit has been reached. Delete an unused variable first.', 'elementor-mcp' ) );
		}
		if ( false !== strpos( $class, 'RecordNotFound' ) ) {
			return new \WP_Error( 'not_found', '' !== $message ? $message : __( 'Variable was not found.', 'elementor-mcp' ) );
		}
		if ( false !== strpos( $class, 'Type_Mismatch' ) ) {
			return new \WP_Error( 'type_mismatch', '' !== $message ? $message : __( 'The variable type cannot be changed this way.', 'elementor-mcp' ) );
		}
		if ( false !== strpos( $class, 'InvalidVariable' ) || $e instanceof \InvalidArgumentException ) {
			return new \WP_Error( 'invalid_variable', '' !== $message ? $message : __( 'The variable is invalid (label must have no spaces and be at most 50 characters).', 'elementor-mcp' ) );
		}

		return new \WP_Error( 'write_failed', '' !== $message ? $message : __( 'The Variables operation failed.', 'elementor-mcp' ) );
	}

	// =========================================================================
	// Cache
	// =========================================================================

	/**
	 * Clears Elementor's file cache so regenerated CSS picks up the change.
	 * Guarded for unit stubs (the stub Plugin has no files_manager).
	 */
	private function clear_cache(): void {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return;
		}
		$elementor = \Elementor\Plugin::$instance ?? null;
		if ( ! is_object( $elementor ) || ! isset( $elementor->files_manager ) || ! is_object( $elementor->files_manager ) ) {
			return;
		}
		if ( method_exists( $elementor->files_manager, 'clear_cache' ) ) {
			try {
				$elementor->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
				// Non-fatal — the write already succeeded.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[Elementor MCP] variables: clear_cache failed: ' . $e->getMessage() );
				}
			}
		}
	}
}
