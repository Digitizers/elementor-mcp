<?php
/**
 * Regression — the two colour/typography tool families must point at each other.
 *
 * Field report #5, part 2b, with the correction in part 3.
 *
 * `replace-system-colors` was available and unused for an entire build that
 * spent rounds fighting the global palette. It reads as a destructive bulk
 * operation rather than "set the brand palette", and it lives in
 * `class-system-kit-abilities.php` while the obvious-looking
 * `update-global-colors` lives elsewhere and does something subtly different:
 * it appends to `custom_colors` and never touches the four system slots that
 * every widget's Global Color picker actually references. So a caller sets the
 * brand palette, the call succeeds, and Elementor's stock `#6EC1E4` stays bound
 * to the roles that matter.
 *
 * Report #4 proposed either routing the global tools through the kit writer or
 * exposing a separate system-role tool; report #3's correction is that the
 * separate tool already existed, so the remaining work is discovery — two
 * tools, adjacent domains, opposite discoverability.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class GlobalVsSystemKitDiscoveryTest extends TestCase {

	/**
	 * @param string $file Relative source path.
	 * @return string
	 */
	private function source( string $file ): string {
		$source = file_get_contents( ELEMENTOR_MCP_DIR . $file );
		$this->assertIsString( $source, "Could not read $file" );

		return $source;
	}

	/**
	 * The description of a tool is the entire model an agent has of it, so the
	 * additive-only behaviour has to be stated where it is read — not left to
	 * be discovered from the compiled CSS several rounds later.
	 */
	public function test_the_global_tools_disclose_that_they_are_additive(): void {
		$source = $this->source( 'includes/abilities/class-global-abilities.php' );

		$this->assertStringContainsString( 'custom_colors', $source, 'update-global-colors must name the array it actually writes.' );
		$this->assertStringContainsString( 'custom_typography', $source, 'update-global-typography must name the array it actually writes.' );
	}

	/**
	 * @dataProvider cross_references
	 */
	public function test_each_family_points_at_the_other( string $file, string $needle, string $why ): void {
		$this->assertStringContainsString( $needle, $this->source( $file ), $why );
	}

	public static function cross_references(): array {
		return array(
			'global colours point at the system tool'    => array(
				'includes/abilities/class-global-abilities.php',
				'replace-system-colors',
				'An agent reading update-global-colors must learn that the system slots need a different tool.',
			),
			'global typography points at the system tool' => array(
				'includes/abilities/class-global-abilities.php',
				'replace-system-typography',
				'Same for typography.',
			),
			'system colours point back'                  => array(
				'includes/abilities/class-system-kit-abilities.php',
				'update-global-colors',
				'replace-system-colors reads destructive; its description must say it is the kit-setup tool and name the other one.',
			),
			'system typography points back'              => array(
				'includes/abilities/class-system-kit-abilities.php',
				'update-global-typography',
				'Same for typography.',
			),
		);
	}

	/**
	 * The behaviour the descriptions claim: the global tools write the custom
	 * arrays only. If that ever changes, the cross-references above become the
	 * lie instead of the fix.
	 */
	public function test_update_global_colors_writes_only_the_custom_palette(): void {
		$source = $this->source( 'includes/abilities/class-global-abilities.php' );

		$start = strpos( $source, 'function execute_update_global_colors' );
		$this->assertNotFalse( $start );

		$body = substr( $source, $start, 3000 );

		$this->assertStringContainsString( "'custom_colors'", $body );
		$this->assertStringNotContainsString(
			"'system_colors'",
			$body,
			'If this tool starts writing the system slots, its description must stop saying it does not.'
		);
	}
}
