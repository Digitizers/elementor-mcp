<?php
/**
 * Unit tests for the GitHub self-updater's pure policy methods.
 *
 * The checker wiring itself (Elementor_MCP_Updater::init) depends on the
 * bundled Plugin Update Checker library and live WordPress hooks, so these
 * tests cover the policy pieces the wrapper owns: release-only strategy
 * reduction, GitHub-scoped user-agent anonymization, and the package-download
 * passthrough.
 *
 * @package Elementor_MCP\Tests
 */

use PHPUnit\Framework\TestCase;

require_once ELEMENTOR_MCP_DIR . 'includes/class-updater.php';

class UpdaterTest extends TestCase {

	public function test_user_agent_identifies_plugin_and_version_only(): void {
		$ua = Elementor_MCP_Updater::user_agent();
		$this->assertSame( 'elementor-mcp/' . ELEMENTOR_MCP_VERSION, $ua );
		$this->assertStringNotContainsString( 'http', $ua, 'User agent must not carry a site URL.' );
	}

	public function test_anonymous_request_options_sets_user_agent(): void {
		$options = Elementor_MCP_Updater::anonymous_request_options( array( 'timeout' => 10 ) );
		$this->assertSame( Elementor_MCP_Updater::user_agent(), $options['user-agent'] );
		$this->assertSame( 10, $options['timeout'], 'Existing options must be preserved.' );
	}

	public function test_anonymous_request_options_tolerates_non_array(): void {
		$options = Elementor_MCP_Updater::anonymous_request_options( null );
		$this->assertIsArray( $options );
		$this->assertSame( Elementor_MCP_Updater::user_agent(), $options['user-agent'] );
	}

	public function test_release_only_strategies_drops_tag_and_branch_fallbacks(): void {
		$noop       = static function () {};
		$strategies = array(
			'latest_release' => $noop,
			'latest_tag'     => $noop,
			'branch'         => $noop,
		);
		$this->assertSame(
			array( 'latest_release' ),
			array_keys( Elementor_MCP_Updater::release_only_strategies( $strategies ) )
		);
	}

	public function test_release_only_strategies_passes_through_without_release_key(): void {
		$strategies = array( 'branch' => static function () {} );
		$this->assertSame( $strategies, Elementor_MCP_Updater::release_only_strategies( $strategies ) );
		$this->assertSame( 'not-an-array', Elementor_MCP_Updater::release_only_strategies( 'not-an-array' ) );
	}

	/**
	 * @dataProvider github_hosts
	 */
	public function test_anonymize_github_request_rewrites_github_hosts( string $url ): void {
		$args = Elementor_MCP_Updater::anonymize_github_request(
			array( 'user-agent' => 'WordPress/6.9; https://client-site.example' ),
			$url
		);
		$this->assertSame( Elementor_MCP_Updater::user_agent(), $args['user-agent'] );
	}

	public static function github_hosts(): array {
		return array(
			'api'            => array( 'https://api.github.com/repos/Digitizers/elementor-mcp/releases/latest' ),
			'codeload'       => array( 'https://codeload.github.com/Digitizers/elementor-mcp/zip/refs/tags/v1.28.0' ),
			'release assets' => array( 'https://release-assets.githubusercontent.com/github-production/elementor-mcp.zip' ),
			'objects'        => array( 'https://objects.githubusercontent.com/some/asset' ),
			'web'            => array( 'https://github.com/Digitizers/elementor-mcp/releases' ),
		);
	}

	public function test_anonymize_github_request_leaves_other_hosts_alone(): void {
		$original = array( 'user-agent' => 'WordPress/6.9; https://client-site.example' );
		$this->assertSame(
			$original,
			Elementor_MCP_Updater::anonymize_github_request( $original, 'https://downloads.wordpress.org/plugin/foo.zip' )
		);
		// A hostile lookalike host must not match the allowlist.
		$this->assertSame(
			$original,
			Elementor_MCP_Updater::anonymize_github_request( $original, 'https://github.com.evil.example/x.zip' )
		);
	}

	public function test_anonymize_github_request_tolerates_non_array_args(): void {
		$this->assertNull( Elementor_MCP_Updater::anonymize_github_request( null, 'https://api.github.com/x' ) );
	}

	public function test_before_package_download_passes_reply_through(): void {
		$this->assertFalse( Elementor_MCP_Updater::before_package_download( false, 'https://codeload.github.com/Digitizers/elementor-mcp/zip/refs/tags/v1.28.0' ) );
		$this->assertSame( 'reply', Elementor_MCP_Updater::before_package_download( 'reply', 'https://example.com/other.zip' ) );
		$this->assertFalse( Elementor_MCP_Updater::before_package_download( false, array( 'not-a-string' ) ) );
	}
}
