<?php
/**
 * @group performance
 * @package Elementor_MCP\Tests\Performance
 */
namespace Elementor_MCP\Tests\Performance;

use PHPUnit\Framework\TestCase;

class PerformanceAbilitiesTest extends TestCase {

	/** @test */
	public function register_collects_the_ability_name(): void {
		$abilities = new \Elementor_MCP_Performance_Abilities();
		$abilities->register();
		$this->assertSame( array( 'elementor-mcp/analyze-performance' ), $abilities->get_ability_names() );
	}

	/** @test */
	public function permission_requires_manage_options(): void {
		$abilities = new \Elementor_MCP_Performance_Abilities();
		// The stub current_user_can() in tests/bootstrap returns true; assert it is wired to manage_options.
		$this->assertTrue( $abilities->check_permission() );
	}
}
