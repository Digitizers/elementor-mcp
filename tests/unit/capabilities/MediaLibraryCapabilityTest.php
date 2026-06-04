<?php
/**
 * T2 Capability tests — media library abilities (1 tool: list-media).
 * check_read_permission() → edit_posts.
 * @group capabilities
 * @group media-library
 * @package Elementor_MCP\Tests\Capabilities
 */
namespace Elementor_MCP\Tests\Capabilities;

require_once dirname(__DIR__) . '/class-ability-test-case.php';

use Elementor_MCP\Tests\Ability_Test_Case;

class MediaLibraryCapabilityTest extends Ability_Test_Case {
    private \Elementor_MCP_Media_Library_Abilities $ability;

    protected function setUp(): void {
        parent::setUp();
        $data          = $this->createStub(\Elementor_MCP_Data::class);
        $this->ability = new \Elementor_MCP_Media_Library_Abilities($data);
    }

    // check_read_permission() — denied
    /** @test @group t2 */
    public function test_read_permission_denied_with_no_caps(): void {
        $this->deny_all_caps();
        $this->assertFalse($this->ability->check_read_permission());
    }

    // check_read_permission() — accepted
    /** @test @group t2 */
    public function test_read_permission_accepted_with_edit_posts(): void {
        $this->allow_caps('edit_posts');
        $this->assertTrue($this->ability->check_read_permission());
    }

    // T0.3 tool registry
    /** @test @group t0 */
    public function test_ability_names_is_list_media_only(): void {
        $names = $this->ability->get_ability_names();
        $this->assertContains('elementor-mcp/list-media', $names, 'Missing tool: elementor-mcp/list-media');
        $this->assertCount(1, $names, 'Media library class must register exactly 1 tool.');
    }
}
