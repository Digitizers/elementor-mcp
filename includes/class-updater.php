<?php
/**
 * GitHub-based self-updater.
 *
 * Wires the bundled Plugin Update Checker library to this fork's public GitHub
 * repository so updates appear on the normal WordPress Plugins / Dashboard >
 * Updates screens. Updates are pulled from tagged GitHub Releases: publish a
 * Release whose tag matches the new version (e.g. v1.28.0) and sites will be
 * offered the update on the next check. When a Release carries a built
 * `elementor-mcp*.zip` asset that asset is served; otherwise the tag's source
 * archive is used (the repository root is the plugin root, and the checker
 * normalizes GitHub's `owner-repo-sha` archive directory back to the plugin
 * slug on install).
 *
 * Ported from the Digitizer Pro Tools updater (`DPT_Updater`), including its
 * two hardening choices: release-ONLY update detection (a bare version tag
 * never offers an update before its Release is published), and an anonymized
 * user agent on every request to GitHub (WordPress's default user agent
 * carries the site URL; nothing about the site should leave the site).
 *
 * @since 1.28.0
 *
 * @package Elementor_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Elementor_MCP_Updater {

	/** Public repository the updates are pulled from. */
	const REPO = 'https://github.com/Digitizers/elementor-mcp/';

	/** Owner/name pair, used to recognise this plugin's own package URLs. */
	const REPO_PATH = 'Digitizers/elementor-mcp';

	/** @var object|null The built update-checker instance. */
	private static $checker = null;

	/**
	 * Build the update checker for the given main plugin file.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return object|null The update checker, or null when unavailable.
	 */
	public static function init( $plugin_file ) {
		if ( null !== self::$checker ) {
			return self::$checker;
		}

		$loader = ELEMENTOR_MCP_DIR . 'includes/vendors/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $loader ) ) {
			return null;
		}
		require_once $loader;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return null;
		}

		$checker = call_user_func(
			array( $factory, 'buildUpdateChecker' ),
			self::REPO,
			$plugin_file,
			'elementor-mcp'
		);

		// Public repo: no authentication needed. We deliberately do NOT call
		// setBranch(): that would track a branch head instead of releases.
		$api = ( is_object( $checker ) && method_exists( $checker, 'getVcsApi' ) ) ? $checker->getVcsApi() : null;
		if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
			// If a Release attaches a built plugin .zip named like the plugin,
			// prefer it; otherwise the checker falls back to the tag's source
			// archive. The pattern matches "elementor-mcp*.zip".
			$api->enableReleaseAssets( '/elementor-mcp.*\.zip/i' );
		}

		if ( is_object( $checker ) && method_exists( $checker, 'getUniqueName' ) ) {
			// Enforce release-ONLY detection. By default the GitHub API strategy
			// falls back latest-release -> latest-tag -> branch, so merely pushing
			// a version tag (before its Release is published) would already offer
			// the update. Keep only the latest-release strategy so an update is
			// offered strictly when a GitHub Release exists. 'latest_release' is
			// the stable strategy key (Vcs\Api::STRATEGY_LATEST_RELEASE) across
			// PUC 5.x.
			add_filter(
				$checker->getUniqueName( 'vcs_update_detection_strategies' ),
				array( __CLASS__, 'release_only_strategies' )
			);
			// Send a neutral user agent on the update check. WordPress's default
			// is "WordPress/6.x; https://example.com", which would hand the site
			// URL to GitHub.
			add_filter(
				$checker->getUniqueName( 'request_info_options' ),
				array( __CLASS__, 'anonymous_request_options' )
			);
		}

		// Installing an offered update is a separate path: WordPress downloads
		// the package itself, outside the checker's API calls, so the filter
		// above never sees it and the default user agent would go out with the
		// download. Hook the request arguments for that download too.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'before_package_download' ), 10, 2 );

		self::$checker = $checker;
		return $checker;
	}

	/**
	 * The user agent sent to GitHub: identifies the plugin, nothing about the
	 * site. GitHub's API rejects requests without one.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'elementor-mcp/' . ( defined( 'ELEMENTOR_MCP_VERSION' ) ? ELEMENTOR_MCP_VERSION : '0' );
	}

	/**
	 * Replace the outgoing user agent for the update check.
	 *
	 * @param array $options wp_remote_get() options.
	 * @return array
	 */
	public static function anonymous_request_options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$options['user-agent'] = self::user_agent();
		return $options;
	}

	/**
	 * Runs just before WordPress downloads a package. Passes the reply through
	 * untouched; it only serves to hook the request arguments when the package
	 * being fetched is this plugin's own.
	 *
	 * @param mixed  $reply   Short-circuit reply (false to continue normally).
	 * @param string $package Package URL about to be downloaded.
	 * @return mixed
	 */
	public static function before_package_download( $reply, $package = '' ) {
		if ( is_string( $package ) && false !== strpos( $package, self::REPO_PATH ) ) {
			add_filter( 'http_request_args', array( __CLASS__, 'anonymize_github_request' ), 10, 2 );
		}
		return $reply;
	}

	/**
	 * Drop the site URL from the user agent on GitHub requests.
	 *
	 * Scoped to GitHub hosts, so an unrelated request in the same page load is
	 * left alone. A release asset redirects from github.com to a separate
	 * download host, which is why several hosts are listed - the arguments are
	 * reused across the redirect.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function anonymize_github_request( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$host  = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$hosts = array(
			'github.com',
			'api.github.com',
			'codeload.github.com',
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
		);
		if ( in_array( $host, $hosts, true ) ) {
			$args['user-agent'] = self::user_agent();
		}
		return $args;
	}

	/**
	 * Reduce the VCS update-detection strategies to release-only, so a bare
	 * version tag without a published GitHub Release never triggers an update.
	 *
	 * @param array $strategies Strategy key => callable.
	 * @return array
	 */
	public static function release_only_strategies( $strategies ) {
		if ( is_array( $strategies ) && isset( $strategies['latest_release'] ) ) {
			return array( 'latest_release' => $strategies['latest_release'] );
		}
		return $strategies;
	}
}
