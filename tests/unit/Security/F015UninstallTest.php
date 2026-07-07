<?php
/**
 * Unit tests for F-015: plugin uninstall cleanup.
 *
 * Architecture note (updated for the Freemius-SDK removal)
 * -------------------------------------------------------
 * The vendored Freemius SDK was removed from this fork. Cleanup previously ran
 * via the SDK's `after_uninstall` action (wired to elementor_mcp_after_uninstall()
 * in the main plugin file). With the SDK gone, a native `uninstall.php` at the
 * plugin root is restored — WordPress runs it directly when the plugin is
 * deleted.
 *
 * The cleanup removes plugin-OWNED data (options, transients, dismissal
 * user-meta) and the generated executable PHP for custom widgets + PHP snippets
 * (which must never survive uninstall, via Elementor_MCP_Widget_Store::uninstall_cleanup()).
 * It intentionally does NOT delete user PAGE content (_elementor_data) or
 * brand-kit backups — that is the user's data and is treated as recoverable,
 * not orphaned.
 *
 * @package Elementor_MCP\Tests\Security
 * @since   1.0.0
 */

namespace Elementor_MCP\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class F015UninstallTest extends TestCase {

	/** @var string Absolute path to the native uninstall handler. */
	private string $cleanup_file;

	/** @var string Source of the uninstall handler. */
	private string $cleanup_src;

	protected function setUp(): void {
		parent::setUp();
		$this->cleanup_file = dirname( __DIR__, 3 ) . '/uninstall.php';
		$this->cleanup_src  = file_exists( $this->cleanup_file )
			? file_get_contents( $this->cleanup_file )
			: '';
	}

	/**
	 * @test
	 * The native uninstall.php must exist — it is how cleanup runs now that the
	 * Freemius SDK (and its after_uninstall hook) has been removed.
	 *
	 * @group security
	 * @group f-015
	 */
	public function test_uninstall_php_exists(): void {
		$this->assertFileExists(
			$this->cleanup_file,
			'uninstall.php must exist at the plugin root — it runs the cleanup that the ' .
			'Freemius after_uninstall hook used to run before the SDK was removed.'
		);
	}

	/**
	 * @test
	 * uninstall.php must guard on WP_UNINSTALL_PLUGIN so it can never run outside
	 * a genuine WordPress uninstall.
	 *
	 * @group security
	 * @group f-015
	 */
	public function test_uninstall_guards_on_wp_constant(): void {
		$this->assertStringContainsString(
			"defined( 'WP_UNINSTALL_PLUGIN' )",
			$this->cleanup_src,
			'uninstall.php must bail unless WP_UNINSTALL_PLUGIN is defined.'
		);
	}

	/**
	 * @test
	 * The cleanup must delete plugin-owned options via delete_option().
	 *
	 * @group security
	 * @group f-015
	 */
	public function test_uninstall_deletes_plugin_options(): void {
		$this->assertStringContainsString( 'delete_option', $this->cleanup_src );
		$this->assertStringContainsString( 'elementor_mcp_disabled_tools', $this->cleanup_src );
	}

	/**
	 * @test
	 * Generated executable PHP (custom widgets + PHP snippets) must be removed —
	 * it must never survive an uninstall.
	 *
	 * @group security
	 * @group f-015
	 */
	public function test_uninstall_removes_generated_executable_php(): void {
		$this->assertStringContainsString(
			'uninstall_cleanup',
			$this->cleanup_src,
			'F-015: the uninstall cleanup must call the widget/snippet store uninstall_cleanup() ' .
			'so generated PHP in uploads is deleted.'
		);
	}

	/**
	 * @test
	 * User PAGE content must be PRESERVED on uninstall — deleting all
	 * _elementor_data would destroy the user's pages.
	 *
	 * @group security
	 * @group f-015
	 */
	public function test_uninstall_preserves_user_page_content(): void {
		$this->assertStringNotContainsString(
			"delete_post_meta_by_key( '_elementor_data'",
			$this->cleanup_src,
			'Uninstall must NOT delete _elementor_data — that is user page content, not plugin data.'
		);
	}
}
