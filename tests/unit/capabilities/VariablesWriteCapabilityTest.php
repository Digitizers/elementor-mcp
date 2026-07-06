<?php
/**
 * T2 Capability tests — Variables WRITE abilities (6 tools).
 * check_write_permission() -> manage_options; check_read_permission() -> edit_posts.
 * @group capabilities
 * @group variables
 * @package Elementor_MCP\Tests\Capabilities
 */
namespace Elementor_MCP\Tests\Capabilities;

require_once dirname(__DIR__) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

// The Variables_Repository stub lives in tests/bootstrap.php (declared before
// this suite), so is_available() is true here.

class VariablesWriteCapabilityTest extends Ability_Test_Case {
    private \Elementor_MCP_Variables_Write_Abilities $ability;

    protected function setUp(): void {
        parent::setUp();
        $data          = $this->createStub(\Elementor_MCP_Data::class);
        $this->ability = new \Elementor_MCP_Variables_Write_Abilities($data);
    }

    /** @test @group t2 */
    public function test_write_permission_denied_with_no_caps(): void {
        $this->deny_all_caps();
        $this->assertFalse($this->ability->check_write_permission());
    }

    /** @test @group t2 */
    public function test_write_permission_denied_with_only_edit_posts(): void {
        $this->allow_caps('edit_posts');
        $this->assertFalse($this->ability->check_write_permission());
    }

    /** @test @group t2 */
    public function test_write_permission_accepted_with_manage_options(): void {
        $this->allow_caps('manage_options');
        $this->assertTrue($this->ability->check_write_permission());
    }

    /** @test @group t2 */
    public function test_read_permission_accepted_with_edit_posts(): void {
        $this->allow_caps('edit_posts');
        $this->assertTrue($this->ability->check_read_permission());
    }

    /** @test @group t0 */
    public function test_ability_names_are_the_six_tools(): void {
        $names = $this->ability->get_ability_names();
        $this->assertContains('elementor-mcp/list-variables', $names);
        $this->assertContains('elementor-mcp/get-variable', $names);
        $this->assertContains('elementor-mcp/create-variable', $names);
        $this->assertContains('elementor-mcp/edit-variable', $names);
        $this->assertContains('elementor-mcp/delete-variable', $names);
        $this->assertContains('elementor-mcp/restore-variable', $names);
        $this->assertCount(6, $names, 'Variables write class must register exactly 6 tools.');
    }
}
