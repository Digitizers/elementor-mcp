<?php
/**
 * Connection info tab view for the MCP Tools for Elementor admin settings page.
 *
 * Displays MCP connection configurations for various clients.
 *
 * @package Elementor_MCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var Elementor_MCP_Admin $this */
$elementor_mcp_endpoint      = rest_url( 'mcp/elementor-mcp-server' );
$elementor_mcp_enabled_count = $this->get_enabled_tool_count();
$elementor_mcp_total_count   = $this->get_total_tool_count();
$elementor_mcp_has_adapter   = class_exists( '\WP\MCP\Core\McpAdapter' );

// Adapter provenance: bundled with EMCP, an external plugin, or unavailable.
$elementor_mcp_adapter_source = class_exists( 'Elementor_MCP_Adapter_Bootstrap' )
	? Elementor_MCP_Adapter_Bootstrap::source()
	: ( $elementor_mcp_has_adapter ? 'external' : 'none' );
$elementor_mcp_adapter_label = 'bundled' === $elementor_mcp_adapter_source
	? __( 'Active (bundled)', 'elementor-mcp' )
	: ( 'external' === $elementor_mcp_adapter_source ? __( 'Active (plugin)', 'elementor-mcp' ) : __( 'Not Active', 'elementor-mcp' ) );

// Abilities API is core in WordPress 6.9+/7.0.
$elementor_mcp_has_abilities = function_exists( 'wp_register_ability' );

// The "Activate Abilities API for EMCP" gate (on by default).
$elementor_mcp_server_enabled = class_exists( 'Elementor_MCP_Plugin' )
	? Elementor_MCP_Plugin::is_server_enabled()
	: ( '1' === (string) get_option( 'elementor_mcp_server_enabled', '1' ) );
?>

<div class="elementor-mcp-connection">

	<?php // ===== Step 1: Activate Abilities API for EMCP ===== ?>
	<div class="elementor-mcp-section elementor-mcp-activate-card">
	<div class="elementor-mcp-activate-head">
		<span class="elementor-mcp-step-num" aria-hidden="true">1</span>
		<h2><?php esc_html_e( 'Activate Abilities API for EMCP', 'elementor-mcp' ); ?></h2>
	</div>

	<form method="post" action="options.php" class="elementor-mcp-activate-form">
		<?php settings_fields( Elementor_MCP_Admin::SETTINGS_GROUP_SERVER ); ?>

		<label class="elementor-mcp-activate-toggle">
			<input
				type="checkbox"
				name="<?php echo esc_attr( Elementor_MCP_Plugin::OPTION_SERVER_ENABLED ); ?>"
				value="1"
				<?php checked( $elementor_mcp_server_enabled ); ?>
			/>
			<strong><?php esc_html_e( 'Expose EMCP tools to AI agents on this site', 'elementor-mcp' ); ?></strong>
		</label>

		<p class="elementor-mcp-activate-note elementor-mcp-activate-note--security">
			<strong><?php esc_html_e( 'Security note:', 'elementor-mcp' ); ?></strong>
			<?php esc_html_e( 'When enabled, connected AI agents can create, edit, and delete Elementor pages and content on this site through the MCP server. Use a capable AI model and set your client to ask for confirmation before every action — read what the agent is about to do before approving.', 'elementor-mcp' ); ?>
		</p>
		<p class="elementor-mcp-activate-note">
			<?php
			if ( $elementor_mcp_has_abilities ) {
				printf(
					/* translators: %s: how the MCP Adapter is provided. */
					esc_html__( 'WordPress Abilities API: core (no separate plugin needed). MCP Adapter: %s.', 'elementor-mcp' ),
					'bundled' === $elementor_mcp_adapter_source
						? esc_html__( 'bundled with EMCP', 'elementor-mcp' )
						: ( 'external' === $elementor_mcp_adapter_source ? esc_html__( 'provided by an active MCP Adapter plugin', 'elementor-mcp' ) : esc_html__( 'unavailable', 'elementor-mcp' ) )
				);
			} else {
				esc_html_e( 'WordPress Abilities API is unavailable — WordPress 6.9 or newer is required.', 'elementor-mcp' );
			}
			?>
		</p>

		<?php submit_button( __( 'Save Settings', 'elementor-mcp' ), 'primary', 'submit', false ); ?>
	</form>
	</div>

	<!-- Server Status -->
	<div class="elementor-mcp-section">
		<h2><?php esc_html_e( 'Server Status', 'elementor-mcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Current status of your MCP server and connected components.', 'elementor-mcp' ); ?></p>

		<div class="elementor-mcp-status-grid">
			<div class="elementor-mcp-status-card">
				<span class="elementor-mcp-status-card-icon elementor-mcp-status-card-icon--ok">
					<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
				</span>
				<span class="elementor-mcp-status-card-info">
					<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'MCP Tools for Elementor', 'elementor-mcp' ); ?></span>
					<span class="elementor-mcp-status-card-value"><?php esc_html_e( 'Active', 'elementor-mcp' ); ?></span>
				</span>
			</div>

			<div class="elementor-mcp-status-card">
				<span class="elementor-mcp-status-card-icon <?php echo esc_attr( $elementor_mcp_has_adapter ? 'elementor-mcp-status-card-icon--ok' : 'elementor-mcp-status-card-icon--warn' ); ?>">
					<?php if ( $elementor_mcp_has_adapter ) : ?>
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
					<?php else : ?>
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>
					<?php endif; ?>
				</span>
				<span class="elementor-mcp-status-card-info">
					<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'MCP Adapter', 'elementor-mcp' ); ?></span>
					<span class="elementor-mcp-status-card-value"><?php echo esc_html( $elementor_mcp_adapter_label ); ?></span>
				</span>
			</div>

			<div class="elementor-mcp-status-card">
				<span class="elementor-mcp-status-card-icon <?php echo esc_attr( $elementor_mcp_server_enabled ? 'elementor-mcp-status-card-icon--ok' : 'elementor-mcp-status-card-icon--warn' ); ?>">
					<?php if ( $elementor_mcp_server_enabled ) : ?>
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
					<?php else : ?>
						<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>
					<?php endif; ?>
				</span>
				<span class="elementor-mcp-status-card-info">
					<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'MCP Server', 'elementor-mcp' ); ?></span>
					<span class="elementor-mcp-status-card-value"><?php echo esc_html( $elementor_mcp_server_enabled ? __( 'Enabled', 'elementor-mcp' ) : __( 'Disabled', 'elementor-mcp' ) ); ?></span>
				</span>
			</div>

			<div class="elementor-mcp-status-card">
				<span class="elementor-mcp-status-card-icon elementor-mcp-status-card-icon--ok">
					<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
				</span>
				<span class="elementor-mcp-status-card-info">
					<span class="elementor-mcp-status-card-label"><?php esc_html_e( 'Tools Enabled', 'elementor-mcp' ); ?></span>
					<span class="elementor-mcp-status-card-value">
						<?php
						printf(
							/* translators: %1$d: enabled count, %2$d: total count */
							esc_html__( '%1$d / %2$d', 'elementor-mcp' ),
							(int) $elementor_mcp_enabled_count,
							(int) $elementor_mcp_total_count
						);
						?>
					</span>
				</span>
			</div>
		</div>

		<div class="elementor-mcp-endpoint">
			<code><?php echo esc_html( $elementor_mcp_endpoint ); ?></code>
			<button type="button" class="button elementor-mcp-copy-btn" data-target="elementor-mcp-endpoint-copy"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
			<textarea id="elementor-mcp-endpoint-copy" class="elementor-mcp-copy-source"><?php echo esc_html( $elementor_mcp_endpoint ); ?></textarea>
		</div>
	</div>

	<!-- HTTP Connection -->
	<div class="elementor-mcp-section">
		<h2><?php esc_html_e( 'Connect Your AI Client', 'elementor-mcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Connect to this site from any AI client using HTTP. No proxy or Node.js needed — just an Application Password.', 'elementor-mcp' ); ?>
		</p>

		<h3><?php esc_html_e( 'Step 1: Generate Your Credentials', 'elementor-mcp' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Pick an administrator and click Generate — a new Application Password is created automatically and every client config below is filled in. No need to visit your profile.', 'elementor-mcp' ); ?>
		</p>

		<?php
		$elementor_mcp_current_user = wp_get_current_user();
		$elementor_mcp_admins       = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		// Only offer users the current user is allowed to manage.
		$elementor_mcp_admins = array_values(
			array_filter(
				$elementor_mcp_admins,
				static function ( $elementor_mcp_u ) {
					return current_user_can( 'edit_user', $elementor_mcp_u->ID );
				}
			)
		);
		// Sort the current user to the top, then alphabetically.
		usort(
			$elementor_mcp_admins,
			static function ( $a, $b ) use ( $elementor_mcp_current_user ) {
				if ( (int) $a->ID === (int) $elementor_mcp_current_user->ID ) {
					return -1;
				}
				if ( (int) $b->ID === (int) $elementor_mcp_current_user->ID ) {
					return 1;
				}
				return strcasecmp( (string) $a->display_name, (string) $b->display_name );
			}
		);
		?>

		<div class="elementor-mcp-cred-form">
			<div class="elementor-mcp-cred-field">
				<label for="elementor-mcp-b64-username"><?php esc_html_e( 'Administrator account', 'elementor-mcp' ); ?></label>
				<select id="elementor-mcp-b64-username">
					<?php foreach ( $elementor_mcp_admins as $elementor_mcp_u ) : ?>
						<option
							value="<?php echo esc_attr( (string) $elementor_mcp_u->ID ); ?>"
							data-login="<?php echo esc_attr( $elementor_mcp_u->user_login ); ?>"
							<?php selected( (int) $elementor_mcp_u->ID, (int) $elementor_mcp_current_user->ID ); ?>
						>
							<?php
							echo esc_html(
								(int) $elementor_mcp_u->ID === (int) $elementor_mcp_current_user->ID
									/* translators: %s: username */
									? sprintf( __( '%s (you)', 'elementor-mcp' ), $elementor_mcp_u->user_login )
									: $elementor_mcp_u->user_login
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<button type="button" class="button button-primary elementor-mcp-generate-btn" id="elementor-mcp-generate-b64"><?php esc_html_e( 'Generate Password &amp; Configs', 'elementor-mcp' ); ?></button>

			<p id="elementor-mcp-cred-status" class="description" style="display: none;"></p>

			<div id="elementor-mcp-generated-pw-row" style="display: none;">
				<div class="elementor-mcp-cred-field">
					<label for="elementor-mcp-generated-pw-copy"><?php esc_html_e( 'New Application Password (save it — shown only once)', 'elementor-mcp' ); ?></label>
					<div class="elementor-mcp-auth-result">
						<code id="elementor-mcp-generated-pw"></code>
						<button type="button" class="button elementor-mcp-copy-btn" data-target="elementor-mcp-generated-pw-copy"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
						<textarea id="elementor-mcp-generated-pw-copy" class="elementor-mcp-copy-source"></textarea>
					</div>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to profile */
							esc_html__( 'Manage or revoke application passwords under %s.', 'elementor-mcp' ),
							'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'Users > Profile', 'elementor-mcp' ) . '</a>'
						);
						?>
					</p>
				</div>
			</div>

			<details class="elementor-mcp-cred-advanced">
				<summary><?php esc_html_e( 'Use an existing Application Password instead', 'elementor-mcp' ); ?></summary>
				<div class="elementor-mcp-cred-field" style="margin-top: 8px;">
					<label for="elementor-mcp-b64-app-password"><?php esc_html_e( 'Application Password', 'elementor-mcp' ); ?></label>
					<input type="text" id="elementor-mcp-b64-app-password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'If filled in, this is used as-is and no new password is created.', 'elementor-mcp' ); ?></p>
				</div>
			</details>

			<div id="elementor-mcp-b64-result-row" style="display: none;">
				<div class="elementor-mcp-cred-field">
					<label for="elementor-mcp-b64-result-copy"><?php esc_html_e( 'Authorization header (for direct HTTP clients)', 'elementor-mcp' ); ?></label>
					<div class="elementor-mcp-auth-result">
						<code id="elementor-mcp-b64-result"></code>
						<button type="button" class="button elementor-mcp-copy-btn" data-target="elementor-mcp-b64-result-copy"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
						<textarea id="elementor-mcp-b64-result-copy" class="elementor-mcp-copy-source"></textarea>
					</div>
				</div>
			</div>
		</div>

		<div id="elementor-mcp-proxy-configs" style="display: none;">

			<h3><?php esc_html_e( 'Step 2: Node.js Proxy Configs (Recommended)', 'elementor-mcp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'These configs use a Node.js proxy that handles session management and protocol version compatibility automatically. Requires Node.js 18+ on the machine running your AI client.', 'elementor-mcp' ); ?>
			</p>

			<h4 style="margin-bottom: 4px;"><?php esc_html_e( 'Remote WordPress — npx runner (recommended)', 'elementor-mcp' ); ?></h4>
			<p class="description">
				<?php esc_html_e( 'Best for remote sites and shared hosting. npx fetches the proxy on demand and runs it locally, so there is no file to copy or keep in sync as the plugin updates.', 'elementor-mcp' ); ?>
			</p>

			<!-- Claude Code (npx) -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Code', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .mcp.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="claude-code-npx"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-claude-code-npx-code"></code></pre>
				<textarea id="claude-code-npx" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Claude Desktop (npx) -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Desktop', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; claude_desktop_config.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="claude-desktop-npx"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-claude-desktop-npx-code"></code></pre>
				<textarea id="claude-desktop-npx" class="elementor-mcp-copy-source"></textarea>
			</div>

			<h4 style="margin-bottom: 4px;"><?php esc_html_e( 'Local WordPress — bundled proxy file', 'elementor-mcp' ); ?></h4>
			<p class="description">
				<?php esc_html_e( 'Use this when WordPress runs on the same machine as your AI client. The path below points to bin/mcp-proxy.mjs in this installation.', 'elementor-mcp' ); ?>
			</p>

			<!-- Claude Code (Proxy) -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Code', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .mcp.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="claude-code-proxy"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-claude-code-proxy-code"></code></pre>
				<textarea id="claude-code-proxy" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Claude Desktop (Proxy) -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Desktop', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; claude_desktop_config.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="claude-desktop-proxy"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-claude-desktop-proxy-code"></code></pre>
				<textarea id="claude-desktop-proxy" class="elementor-mcp-copy-source"></textarea>
			</div>

			<p class="description">
				<strong><?php esc_html_e( 'Remote sites:', 'elementor-mcp' ); ?></strong>
				<?php esc_html_e( 'The path in the two configs above points to this server\'s filesystem and will not work from a remote AI client, which launches the proxy locally. Use the npx configs above instead, or copy bin/mcp-proxy.mjs to your local machine and point "args" at that local path.', 'elementor-mcp' ); ?>
			</p>

		</div>

		<div id="elementor-mcp-http-configs" style="display: none;">

			<h3><?php esc_html_e( 'Step 3: Direct HTTP Configs (Advanced)', 'elementor-mcp' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Direct HTTP connections require your AI client to handle session management (Mcp-Session-Id headers). Use only if the Node.js proxy is not an option.', 'elementor-mcp' ); ?>
			</p>

			<!-- Claude Code -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Code', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .mcp.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="claude-code-http"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-claude-code-http-code"></code></pre>
				<textarea id="claude-code-http" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Claude Desktop -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Claude Desktop', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; claude_desktop_config.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="claude-desktop-http"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-claude-desktop-http-code"></code></pre>
				<textarea id="claude-desktop-http" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Cursor -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Cursor', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; .cursor/mcp.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="cursor-config"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-cursor-code"></code></pre>
				<textarea id="cursor-config" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Windsurf -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Windsurf', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; mcp_config.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="windsurf-config"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-windsurf-code"></code></pre>
				<textarea id="windsurf-config" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Antigravity -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Antigravity', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; mcp_config.json</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="antigravity-config"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-antigravity-code"></code></pre>
				<textarea id="antigravity-config" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- Codex -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'Codex', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; config.toml</span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="codex-config"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-codex-code"></code></pre>
				<textarea id="codex-config" class="elementor-mcp-copy-source"></textarea>
			</div>

			<!-- npx mcp-remote -->
			<div class="elementor-mcp-config-card">
				<div class="elementor-mcp-config-card-header">
					<span class="elementor-mcp-config-card-title"><?php esc_html_e( 'npx mcp-remote', 'elementor-mcp' ); ?> <span style="font-weight: 400; color: var(--mcp-gray-400);">&mdash; <?php esc_html_e( 'any stdio client', 'elementor-mcp' ); ?></span></span>
					<button type="button" class="button elementor-mcp-copy-btn" data-target="mcp-remote-config"><?php esc_html_e( 'Copy', 'elementor-mcp' ); ?></button>
				</div>
				<pre><code id="elementor-mcp-mcp-remote-code"></code></pre>
				<textarea id="mcp-remote-config" class="elementor-mcp-copy-source"></textarea>
			</div>

		</div>
	</div>

</div>
