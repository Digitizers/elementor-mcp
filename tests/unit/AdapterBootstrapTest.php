<?php
/**
 * The bundled MCP Adapter: what we ship, and what we say we ship.
 *
 * @package Elementor_MCP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-mcp-adapter-bootstrap.php';

class AdapterBootstrapTest extends TestCase {

	private function vendor_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes/vendors/mcp-adapter/';
	}

	/**
	 * BUNDLED_VERSION is a hand-written constant; the tree beside it comes from
	 * an upstream ZIP. Nothing but this test stops the two from drifting, and a
	 * drifted constant is worse than no constant — it reports a version the
	 * site is not running.
	 */
	public function test_bundled_version_matches_the_vendored_source(): void {
		$header = $this->vendor_dir() . 'mcp-adapter.php';
		$this->assertFileExists( $header, 'upstream plugin header must be vendored as the version anchor' );

		$matched = preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', (string) file_get_contents( $header ), $m );
		$this->assertSame( 1, $matched, 'the vendored header must declare a Version' );
		$this->assertSame(
			$m[1],
			Elementor_MCP_Adapter_Bootstrap::BUNDLED_VERSION,
			'BUNDLED_VERSION drifted from the vendored adapter source'
		);
	}

	/**
	 * Since adapter 0.5.0 every MCP response is a typed DTO from
	 * `wordpress/php-mcp-schema`. Shipping `includes/` without it loads fine and
	 * then fatals on the first tool call — the worst shape of missing
	 * dependency, because nothing fails until a user is mid-request.
	 */
	public function test_the_schema_runtime_dependency_is_vendored(): void {
		$src = $this->vendor_dir() . 'vendor/wordpress/php-mcp-schema/src/';
		$this->assertDirectoryExists( $src, 'php-mcp-schema is a RUNTIME dependency of adapter >= 0.5.0' );

		// One DTO the adapter's own source imports by name.
		$this->assertFileExists(
			$src . 'Common/Content/DTO/TextContent.php',
			'a DTO the adapter imports must resolve under the vendored PSR-4 root'
		);
	}

	/** Every `WP\McpSchema\*` class the adapter imports must exist in the tree we ship. */
	public function test_every_schema_class_the_adapter_imports_is_present(): void {
		$root = $this->vendor_dir() . 'includes/';
		$src  = $this->vendor_dir() . 'vendor/wordpress/php-mcp-schema/src/';

		$imports = array();
		$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( preg_match_all( '/^\s*use\s+(WP\\\\McpSchema\\\\[A-Za-z0-9_\\\\]+)/mi', (string) file_get_contents( $file->getPathname() ), $m ) ) {
				foreach ( $m[1] as $class ) {
					$imports[ $class ] = true;
				}
			}
		}
		$this->assertNotEmpty( $imports, 'the adapter is expected to import schema DTOs' );

		$missing = array();
		foreach ( array_keys( $imports ) as $class ) {
			$relative = str_replace( '\\', '/', substr( $class, strlen( 'WP\\McpSchema\\' ) ) ) . '.php';
			if ( ! is_readable( $src . $relative ) ) {
				$missing[] = $class;
			}
		}
		$this->assertSame( array(), $missing, 'schema classes imported by the adapter but not vendored' );
	}

	/**
	 * `WP\MCP\` is a prefix of nothing here, but `WP\McpSchema\` is NOT a
	 * prefix-safe sibling under a case-insensitive read — the registration
	 * order in the bootstrap is what keeps them apart, so assert the order.
	 */
	public function test_the_schema_prefix_is_registered_before_the_adapter_prefix(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-mcp-adapter-bootstrap.php' );
		$schema  = strpos( $src, "'WP\\\\McpSchema\\\\'" );
		$adapter = strpos( $src, "'WP\\\\MCP\\\\'" );
		$this->assertNotFalse( $schema, 'the schema prefix must be registered' );
		$this->assertNotFalse( $adapter, 'the adapter prefix must be registered' );
		$this->assertLessThan( $adapter, $schema, 'the longer prefix must be tried first' );
	}

	/**
	 * Adapter 0.6.0 exposes `meta.public` abilities on its default MCP server.
	 * Ours must never land there: the plugin's whole write-guard doctrine
	 * assumes our tools are reachable only through our own server.
	 */
	public function test_registration_declares_the_default_server_opt_out(): void {
		if ( ! function_exists( 'elementor_mcp_register_ability' ) ) {
			$this->markTestSkipped( 'registration wrapper not loaded in this suite' );
		}

		$GLOBALS['_registered_abilities'] = $GLOBALS['_registered_abilities'] ?? array();
		$name = 'elementor-mcp/opt-out-probe';

		elementor_mcp_register_ability( $name, array(
			'label'            => 'probe',
			'description'      => 'probe',
			'execute_callback' => static fn() => array( 'ok' => true ),
		) );

		$args = $GLOBALS['_registered_abilities'][ $name ] ?? null;
		$this->assertIsArray( $args, 'the registrar must record the ability' );
		$this->assertFalse(
			$args['meta']['mcp']['public'] ?? null,
			'an ability must declare meta.mcp.public = false, not merely omit meta.public'
		);
	}

	/**
	 * The live case that motivated the diagnostic, reproduced.
	 *
	 * Rank Math's bundled 0.4.1 won the load-order race on a production site: it
	 * defined no `WP_MCP_VERSION` and exposed `McpAdapter::VERSION = '0.4.1'`.
	 * Reading only the global would report "unknown version" for exactly the
	 * site the feature exists for — so the class constant is read first, and it
	 * is authoritative besides: it belongs to the class that actually loaded,
	 * while the global belongs to whichever copy ran its bootstrap.
	 */
	public function test_the_external_version_comes_from_the_loaded_class(): void {
		if ( ! class_exists( '\\WP\\MCP\\Core\\McpAdapter', false ) ) {
			eval( 'namespace WP\\MCP\\Core; class McpAdapter { public const VERSION = "0.4.1"; }' );
		}

		$ref    = new ReflectionMethod( 'Elementor_MCP_Adapter_Bootstrap', 'detect_external_version' );
		$actual = $ref->invoke( null );

		$this->assertSame(
			'0.4.1',
			$actual,
			'a bundled copy defines no WP_MCP_VERSION but does expose McpAdapter::VERSION'
		);
		$this->assertNotSame( '', $actual, 'the very case the diagnostic targets must not read as unknown' );
	}

	/**
	 * The helpers only matter if something renders them. Round-1 of #65 caught
	 * exactly this: `active_version()` and `is_outdated()` were added, the
	 * changelog announced the connection screen would report the version, and no
	 * production code called either — the promise shipped without the feature.
	 */
	public function test_the_connection_screen_and_server_report_consume_the_helpers(): void {
		$consumers = array(
			'includes/admin/views/page-connection.php',
			'includes/abilities/class-server-info-abilities.php',
		);
		foreach ( $consumers as $relative ) {
			$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
			$this->assertStringContainsString( 'active_version()', $src, "$relative must report which adapter is live" );
			$this->assertStringContainsString( 'is_outdated()', $src, "$relative must surface an older adapter" );
		}
	}

	/**
	 * An external adapter that declares no version is a copy bundled inside
	 * another plugin — every one observed in the wild predates what we ship, so
	 * unknown must read as outdated rather than as fine.
	 */
	public function test_an_unversioned_external_adapter_counts_as_outdated(): void {
		$ref = new ReflectionClass( 'Elementor_MCP_Adapter_Bootstrap' );

		// No setAccessible(): private properties are readable through Reflection
		// without it since PHP 8.1, and calling it is deprecated in 8.5.
		$source  = $ref->getProperty( 'source' );
		$version = $ref->getProperty( 'external_version' );

		$source->setValue( null, 'external' );
		$version->setValue( null, '' );
		$this->assertTrue( Elementor_MCP_Adapter_Bootstrap::is_outdated() );
		$this->assertSame( '', Elementor_MCP_Adapter_Bootstrap::active_version() );

		$version->setValue( null, '0.5.0' );
		$this->assertTrue( Elementor_MCP_Adapter_Bootstrap::is_outdated(), 'an older external adapter is outdated' );
		$this->assertSame( '0.5.0', Elementor_MCP_Adapter_Bootstrap::active_version() );

		$version->setValue( null, Elementor_MCP_Adapter_Bootstrap::BUNDLED_VERSION );
		$this->assertFalse( Elementor_MCP_Adapter_Bootstrap::is_outdated() );

		$source->setValue( null, 'bundled' );
		$this->assertFalse( Elementor_MCP_Adapter_Bootstrap::is_outdated(), 'our own copy is never outdated' );
		$this->assertSame( Elementor_MCP_Adapter_Bootstrap::BUNDLED_VERSION, Elementor_MCP_Adapter_Bootstrap::active_version() );

		$source->setValue( null, 'none' );
		$this->assertFalse( Elementor_MCP_Adapter_Bootstrap::is_outdated() );
		$this->assertSame( '', Elementor_MCP_Adapter_Bootstrap::active_version() );
	}
}
