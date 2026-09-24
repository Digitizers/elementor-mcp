<?php
namespace Elementor_MCP\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What a fork write declares as custom CSS (spec 2026-09-24 §3/§4.1; plan B R3–R6).
 */
class CssTouchesTest extends TestCase {

	private function t( string $name, $input, string $id = '42' ): array {
		return \Elementor_MCP_Rules::css_touches( $id, $name, $input );
	}

	private const EXACT = array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true, 'css_only' => true ) );
	private const MIXED = array( array( 'type' => 'custom_css', 'id' => '42', 'precise' => true ) );
	private const CONS  = array( array( 'type' => 'custom_css', 'id' => '42' ) );

	public function test_add_custom_css_page_and_element_are_exact(): void {
		$this->assertSame( self::EXACT, $this->t( 'elementor-mcp/add-custom-css', array( 'post_id' => 42, 'css' => 'body{}' ) ) );
		$this->assertSame( self::EXACT, $this->t( 'elementor-mcp/add-custom-css', array( 'post_id' => 42, 'element_id' => 'a1', 'css' => 'selector{}', 'replace' => true ) ) );
	}

	public function test_clearing_is_not_css(): void {
		foreach ( array( '', '   ', null ) as $clear ) {
			$this->assertSame( array(), $this->t( 'elementor-mcp/add-custom-css', array( 'post_id' => 42, 'css' => $clear, 'replace' => true ) ) );
			$this->assertSame( array(), $this->t( 'elementor-mcp/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => $clear ) ) ) );
		}
	}

	public function test_page_settings_css_alone_is_exact_and_with_a_title_is_mixed(): void {
		$this->assertSame( self::EXACT, $this->t( 'elementor-mcp/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}' ) ) ) );
		$this->assertSame( self::MIXED, $this->t( 'elementor-mcp/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}', 'hide_title' => 'yes' ) ) ) );
	}

	public function test_no_css_declares_nothing(): void {
		$this->assertSame( array(), $this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'a1', 'settings' => array( 'title' => 'x' ) ) ) );
		$this->assertSame( array(), $this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'a1', 'settings' => array( 'custom_css_class' => 'x' ) ) ), 'a different key' );
	}

	public function test_batch_update_css_only_vs_mixed(): void {
		$css_only = array( 'post_id' => 42, 'operations' => array(
			array( 'element_id' => 'a', 'settings' => array( 'custom_css' => 'x{}' ) ),
			array( 'element_id' => 'b', 'settings' => array( 'custom_css' => 'y{}' ) ),
		) );
		$this->assertSame( self::EXACT, $this->t( 'elementor-mcp/batch-update', $css_only ) );
		$mixed = $css_only;
		$mixed['operations'][] = array( 'element_id' => 'c', 'settings' => array( 'title' => 'Hi' ) );
		$this->assertSame( self::MIXED, $this->t( 'elementor-mcp/batch-update', $mixed ) );
	}

	public function test_nested_and_repeater_custom_css_is_found(): void {
		$tree = array( 'post_id' => 42, 'template_json' => array(
			array( 'id' => 'c', 'elType' => 'container', 'settings' => array(), 'elements' => array(
				array( 'id' => 'w', 'elType' => 'widget', 'settings' => array( 'items' => array( array( 'custom_css' => 'x{}' ) ) ) ),
			) ),
		) );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/import-template', $tree ), 'found — conservative, import-template is not a CSS writer' );
		$other_case = array( 'post_id' => 42, 'settings' => array( 'Custom_CSS' => 'x{}' ) );
		$this->assertSame( array(), $this->t( 'elementor-mcp/update-element', $other_case ) );
	}

	public function test_v4_variant_custom_css_is_found(): void {
		$in = array( 'post_id' => 42, 'element_id' => 'a', 'settings' => array( 'styles' => array( 's1' => array( 'variants' => array( array( 'custom_css' => array( 'raw' => 'x' ) ) ) ) ) ) );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/update-element', $in ), 'a non-string CSS value is CSS of unknown shape' );
	}

	public function test_a_create_is_the_wildcard_and_never_precise(): void {
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '*' ) ),
			$this->t( 'elementor-mcp/build-page', array( 'title' => 'x', 'page_settings' => array( 'custom_css' => 'a{}' ) ), '*' )
		);
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '*' ) ),
			$this->t( 'elementor-mcp/add-heading', array( 'title' => 'x', 'settings' => array( 'custom_css' => 'a{}' ) ), '*' )
		);
	}

	public function test_site_wide_code_surfaces_are_the_wildcard(): void {
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/create-custom-widget', array( 'id' => 'card', 'styles' => '.card{}' ), '*' ) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/update-custom-widget', array( 'id' => 'card', 'html_template' => '<div/>' ), '*' ), 'html_template is conservative' );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/add-code-snippet', array( 'title' => 's', 'code' => '<script/>' ), '*' ) );
		$this->assertSame( array(), $this->t( 'elementor-mcp/add-code-snippet', array( 'title' => 's', 'code' => '  ' ), '*' ) );
	}

	public function test_design_system_css_is_not_custom_css(): void {
		$this->assertSame( array(), $this->t( 'elementor-mcp/create-global-class', array( 'label' => 'x', 'styles' => array( 'color' => 'red' ) ), '*' ) );
	}

	public function test_only_abilities_that_write_css_issue_evidence(): void {
		// A delete that tolerates an extra key must not become "CSS-only".
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/remove-element', array( 'post_id' => 42, 'element_id' => 'a', 'settings' => array( 'custom_css' => 'x{}' ) ) ) );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/import-template', array( 'post_id' => 42, 'template_json' => array( array( 'settings' => array( 'custom_css' => 'x{}' ) ) ) ) ) );
	}

	public function test_a_non_array_input_declares_nothing(): void {
		$this->assertSame( array(), $this->t( 'elementor-mcp/update-element', 'garbage' ) );
	}
}
