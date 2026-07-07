<?php
/**
 * Brand Kits tab view.
 *
 * Renders the 10 bundled, no-license-required brand kits (coordinated colors +
 * typography) shipped in `assets/brand-kits/free-brand-kits.json`. Users can
 * apply a kit in one click — replacing the site's global palette and fonts —
 * with backup-before-apply and restore from any saved point.
 *
 * Applying + backup/restore route through the free, capability-gated engine
 * (Elementor_MCP_System_Kit_Writer + Elementor_MCP_Kit_Backup_Store); there is
 * no hosted library, license check, or phone-home here.
 *
 * Previews use pre-rendered, font-outlined SVGs (thumbnail_url) bundled in the
 * plugin. When absent we fall back to a CSS swatch strip; no Google Fonts are
 * ever loaded in wp-admin.
 *
 * @package Elementor_MCP
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$elementor_mcp_render = class_exists( 'Elementor_MCP_Free_Brand_Kits' )
	? Elementor_MCP_Free_Brand_Kits::get_bundle()
	: array( 'categories' => array() );

$elementor_mcp_bk_backups = class_exists( 'Elementor_MCP_Kit_Backup_Store' )
	? Elementor_MCP_Kit_Backup_Store::list_backups()
	: array();

$elementor_mcp_bk_total = 0;
foreach ( $elementor_mcp_render['categories'] as $elementor_mcp_bk_cat ) {
	$elementor_mcp_bk_total += is_array( $elementor_mcp_bk_cat['kits'] ?? null ) ? count( $elementor_mcp_bk_cat['kits'] ) : 0;
}
?>

<div class="elementor-mcp-brand-kits">

	<?php if ( $elementor_mcp_bk_total > 0 ) : ?>

		<div class="elementor-mcp-pro-prompts">
			<div class="elementor-mcp-pro-prompts-header">
				<div class="elementor-mcp-pro-prompts-heading">
					<h2>
						<?php esc_html_e( 'Brand Kits Library', 'elementor-mcp' ); ?>
						<span class="elementor-mcp-badge elementor-mcp-badge--free"><?php esc_html_e( 'FREE', 'elementor-mcp' ); ?></span>
					</h2>
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of free brand kits */
							esc_html__( '%d coordinated color + typography kits, free to apply. One click replaces your site\'s global palette and fonts — back up first and restore any time.', 'elementor-mcp' ),
							(int) $elementor_mcp_bk_total
						);
						?>
					</p>
				</div>
			</div>

			<?php if ( count( $elementor_mcp_render['categories'] ) > 1 ) : ?>
				<div class="elementor-mcp-pro-filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter by category', 'elementor-mcp' ); ?>">
					<button type="button" class="elementor-mcp-pro-filter is-active" data-category="all">
						<?php esc_html_e( 'All', 'elementor-mcp' ); ?>
						<span class="elementor-mcp-pro-filter-count"><?php echo (int) $elementor_mcp_bk_total; ?></span>
					</button>
					<?php foreach ( $elementor_mcp_render['categories'] as $elementor_mcp_bk_cat ) :
						$elementor_mcp_cat_slug  = isset( $elementor_mcp_bk_cat['slug'] ) ? sanitize_key( $elementor_mcp_bk_cat['slug'] ) : '';
						$elementor_mcp_cat_label = isset( $elementor_mcp_bk_cat['label'] ) ? (string) $elementor_mcp_bk_cat['label'] : '';
						$elementor_mcp_cat_count = is_array( $elementor_mcp_bk_cat['kits'] ?? null ) ? count( $elementor_mcp_bk_cat['kits'] ) : 0;
						if ( '' === $elementor_mcp_cat_slug || '' === $elementor_mcp_cat_label ) {
							continue;
						}
					?>
						<button type="button" class="elementor-mcp-pro-filter" data-category="<?php echo esc_attr( $elementor_mcp_cat_slug ); ?>">
							<?php echo esc_html( $elementor_mcp_cat_label ); ?>
							<span class="elementor-mcp-pro-filter-count"><?php echo (int) $elementor_mcp_cat_count; ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div
				class="elementor-mcp-brand-kit-grid"
				data-apply-nonce="<?php echo esc_attr( wp_create_nonce( 'elementor_mcp_apply_brand_kit' ) ); ?>"
			>
				<?php foreach ( $elementor_mcp_render['categories'] as $elementor_mcp_bk_cat ) :
					$elementor_mcp_cat_slug  = isset( $elementor_mcp_bk_cat['slug'] ) ? sanitize_key( $elementor_mcp_bk_cat['slug'] ) : '';
					$elementor_mcp_cat_label = isset( $elementor_mcp_bk_cat['label'] ) ? (string) $elementor_mcp_bk_cat['label'] : '';
					if ( '' === $elementor_mcp_cat_slug || empty( $elementor_mcp_bk_cat['kits'] ) ) {
						continue;
					}
					foreach ( $elementor_mcp_bk_cat['kits'] as $elementor_mcp_kit ) :
						$elementor_mcp_k_slug  = isset( $elementor_mcp_kit['slug'] ) ? sanitize_key( $elementor_mcp_kit['slug'] ) : '';
						$elementor_mcp_k_title = isset( $elementor_mcp_kit['title'] ) ? (string) $elementor_mcp_kit['title'] : '';
						$elementor_mcp_k_desc  = isset( $elementor_mcp_kit['description'] ) ? (string) $elementor_mcp_kit['description'] : '';
						$elementor_mcp_k_thumb = isset( $elementor_mcp_kit['thumbnail_url'] ) ? (string) $elementor_mcp_kit['thumbnail_url'] : '';
						if ( '' === $elementor_mcp_k_slug ) {
							continue;
						}

						// Swatch fallback when no pre-rendered preview ships.
						$elementor_mcp_swatches = array();
						if ( isset( $elementor_mcp_kit['preview']['swatches'] ) && is_array( $elementor_mcp_kit['preview']['swatches'] ) ) {
							$elementor_mcp_swatches = $elementor_mcp_kit['preview']['swatches'];
						} elseif ( isset( $elementor_mcp_kit['colors'] ) && is_array( $elementor_mcp_kit['colors'] ) ) {
							foreach ( array( 'primary', 'secondary', 'text', 'accent' ) as $elementor_mcp_slot ) {
								if ( isset( $elementor_mcp_kit['colors'][ $elementor_mcp_slot ]['color'] ) ) {
									$elementor_mcp_swatches[] = $elementor_mcp_kit['colors'][ $elementor_mcp_slot ]['color'];
								}
							}
						}
				?>
						<div class="elementor-mcp-brand-kit-card" data-category="<?php echo esc_attr( $elementor_mcp_cat_slug ); ?>">
							<?php if ( '' !== $elementor_mcp_k_thumb ) : ?>
								<div class="elementor-mcp-brand-kit-preview">
									<img src="<?php echo esc_url( $elementor_mcp_k_thumb ); ?>" alt="<?php echo esc_attr( $elementor_mcp_k_title ); ?>" loading="lazy" />
								</div>
							<?php elseif ( ! empty( $elementor_mcp_swatches ) ) : ?>
								<div class="elementor-mcp-brand-kit-swatches" aria-hidden="true">
									<?php
									$elementor_mcp_widths = array( '50%', '25%', '15%', '10%' );
									foreach ( array_slice( $elementor_mcp_swatches, 0, 4 ) as $elementor_mcp_i => $elementor_mcp_hex ) :
										$elementor_mcp_hex_safe = sanitize_hex_color( (string) $elementor_mcp_hex );
										if ( empty( $elementor_mcp_hex_safe ) ) {
											continue;
										}
									?>
										<span
											class="elementor-mcp-brand-kit-swatch"
											style="width:<?php echo esc_attr( $elementor_mcp_widths[ $elementor_mcp_i ] ?? '25%' ); ?>;background-color:<?php echo esc_attr( $elementor_mcp_hex_safe ); ?>;"
										></span>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<div class="elementor-mcp-brand-kit-body">
								<div class="elementor-mcp-brand-kit-header">
									<h3 class="elementor-mcp-brand-kit-title"><?php echo esc_html( $elementor_mcp_k_title ); ?></h3>
									<span class="elementor-mcp-prompt-tag"><?php echo esc_html( $elementor_mcp_cat_label ); ?></span>
								</div>
								<?php if ( '' !== $elementor_mcp_k_desc ) : ?>
									<p class="elementor-mcp-brand-kit-desc"><?php echo esc_html( $elementor_mcp_k_desc ); ?></p>
								<?php endif; ?>
								<div class="elementor-mcp-brand-kit-actions">
									<button
										type="button"
										class="button button-primary elementor-mcp-brand-kit-apply"
										data-category-slug="<?php echo esc_attr( $elementor_mcp_cat_slug ); ?>"
										data-kit-slug="<?php echo esc_attr( $elementor_mcp_k_slug ); ?>"
										data-kit-title="<?php echo esc_attr( $elementor_mcp_k_title ); ?>"
									>
										<?php esc_html_e( 'Apply Kit', 'elementor-mcp' ); ?>
									</button>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>

			<!-- Restore from backup -->
			<div class="elementor-mcp-brand-kit-restore" data-restore-nonce="<?php echo esc_attr( wp_create_nonce( 'elementor_mcp_restore_brand_kit' ) ); ?>">
				<h3><?php esc_html_e( 'Restore from backup', 'elementor-mcp' ); ?></h3>
				<?php if ( ! empty( $elementor_mcp_bk_backups ) ) : ?>
					<p class="description"><?php esc_html_e( 'Roll your global colors and typography back to a saved point. By default only kit-applied tokens are restored; tick the box to clobber your custom colors/typography exactly as they were.', 'elementor-mcp' ); ?></p>
					<div class="elementor-mcp-brand-kit-restore-row">
						<select class="elementor-mcp-brand-kit-backup-select">
							<?php foreach ( $elementor_mcp_bk_backups as $elementor_mcp_backup ) : ?>
								<option value="<?php echo esc_attr( (int) $elementor_mcp_backup['id'] ); ?>">
									<?php echo esc_html( $elementor_mcp_backup['title'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button elementor-mcp-brand-kit-restore-btn">
							<?php esc_html_e( 'Restore', 'elementor-mcp' ); ?>
						</button>
					</div>
					<label class="elementor-mcp-brand-kit-clobber">
						<input type="checkbox" class="elementor-mcp-brand-kit-clobber-input" value="1" />
						<?php esc_html_e( 'Also restore my custom colors and typography exactly as they were', 'elementor-mcp' ); ?>
					</label>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No backups yet. The first time you apply a kit (with the backup option checked), a restore point will appear here.', 'elementor-mcp' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<!-- Apply confirmation modal -->
		<div class="elementor-mcp-brand-kit-modal" hidden>
			<div class="elementor-mcp-brand-kit-modal__backdrop" data-modal-dismiss></div>
			<div class="elementor-mcp-brand-kit-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="elementor-mcp-bk-modal-title">
				<h3 id="elementor-mcp-bk-modal-title" class="elementor-mcp-brand-kit-modal__title"></h3>
				<p class="elementor-mcp-brand-kit-modal__body">
					<?php esc_html_e( 'This will replace your site\'s global colors and typography. Every widget using global color/type tokens will switch to the new palette.', 'elementor-mcp' ); ?>
				</p>
				<label class="elementor-mcp-brand-kit-modal__backup">
					<input type="checkbox" class="elementor-mcp-brand-kit-modal__backup-input" value="1" checked />
					<?php esc_html_e( 'Back up current global settings (recommended)', 'elementor-mcp' ); ?>
				</label>
				<div class="elementor-mcp-brand-kit-modal__actions">
					<button type="button" class="button" data-modal-dismiss><?php esc_html_e( 'Cancel', 'elementor-mcp' ); ?></button>
					<button type="button" class="button button-primary elementor-mcp-brand-kit-modal__confirm"><?php esc_html_e( 'Apply Brand Kit', 'elementor-mcp' ); ?></button>
				</div>
			</div>
		</div>

	<?php else : ?>

		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'No brand kits are available right now.', 'elementor-mcp' ); ?></p>
		</div>

	<?php endif; ?>

</div>
