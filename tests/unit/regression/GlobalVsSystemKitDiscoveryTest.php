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
 * These assertions read the REGISTERED descriptions rather than the source
 * text. Scanning the file would pass on the execute implementations' own
 * mentions of `custom_colors`, so it would have passed before this change and
 * would keep passing if the disclosure were deleted — a test advertising a
 * protection it does not provide.
 *
 * @group regression
 * @package Elementor_MCP\Tests\Regression
 */

namespace Elementor_MCP\Tests\Regression;

require_once dirname( __DIR__ ) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class GlobalVsSystemKitDiscoveryTest extends Ability_Test_Case {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_registered_abilities'] = array();

		( new \Elementor_MCP_Global_Abilities( new \Elementor_MCP_Data() ) )->register();
		( new \Elementor_MCP_System_Kit_Abilities() )->register();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_registered_abilities'] );
		parent::tearDown();
	}

	/**
	 * The description an agent actually reads.
	 *
	 * @param string $ability Full ability name.
	 * @return string
	 */
	private function description( string $ability ): string {
		$this->assertArrayHasKey(
			$ability,
			$GLOBALS['_registered_abilities'],
			"$ability did not register; the cross-reference cannot be read."
		);

		return (string) ( $GLOBALS['_registered_abilities'][ $ability ]['description'] ?? '' );
	}

	/**
	 * @dataProvider disclosures
	 */
	public function test_each_tool_discloses_what_it_writes( string $ability, string $needle, string $why ): void {
		$this->assertStringContainsString( $needle, $this->description( $ability ), $why );
	}

	public static function disclosures(): array {
		return array(
			'global colours name the array they write'    => array(
				'elementor-mcp/update-global-colors',
				'custom_colors',
				'The description is the whole model an agent has of the tool; the additive-only behaviour has to be stated there.',
			),
			'global typography names its array'           => array(
				'elementor-mcp/update-global-typography',
				'custom_typography',
				'Same for typography.',
			),
			'global colours point at the system tool'     => array(
				'elementor-mcp/update-global-colors',
				'replace-system-colors',
				'An agent reading this must learn the system slots need a different tool.',
			),
			'global typography points at the system tool' => array(
				'elementor-mcp/update-global-typography',
				'replace-system-typography',
				'Same for typography.',
			),
			'system colours point back'                   => array(
				'elementor-mcp/replace-system-colors',
				'update-global-colors',
				'This one reads destructive; it must say it is the kit-setup tool and name the other.',
			),
			'system typography points back'               => array(
				'elementor-mcp/replace-system-typography',
				'update-global-typography',
				'Same for typography.',
			),
		);
	}

	/**
	 * The behaviour those descriptions claim. If `update-global-colors` ever
	 * starts writing the system slots, this fails — rather than the description
	 * quietly becoming the lie instead of the fix.
	 */
	public function test_update_global_colors_writes_only_the_custom_palette(): void {
		$source = file_get_contents( ELEMENTOR_MCP_DIR . 'includes/abilities/class-global-abilities.php' );
		$this->assertIsString( $source );

		$start = strpos( $source, 'function execute_update_global_colors' );
		$this->assertNotFalse( $start );

		$body = substr( $source, $start, 3000 );

		$this->assertStringContainsString( "'custom_colors'", $body );
		$this->assertStringNotContainsString( "'system_colors'", $body );
	}
}
