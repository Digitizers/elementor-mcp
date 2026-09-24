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
		// The custom-widget tools nest their code under `spec` (the real
		// schema, class-widget-builder-abilities.php spec_schema()).
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/create-custom-widget', array( 'spec' => array( 'html_template' => '', 'styles' => '.card{}' ) ), '*' ) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/update-custom-widget', array( 'widget_id' => 7, 'spec' => array( 'html_template' => '<div/>' ) ), '*' ), 'html_template is conservative' );
		$this->assertSame( array(), $this->t( 'elementor-mcp/create-custom-widget', array( 'spec' => array( 'html_template' => '', 'styles' => '' ) ), '*' ), 'empty code declares nothing' );
		$this->assertSame( array(), $this->t( 'elementor-mcp/create-custom-widget', array( 'styles' => '.card{}' ), '*' ), 'a top-level styles key is not the tool\'s input' );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/add-code-snippet', array( 'title' => 's', 'code' => '<script/>' ), '*' ) );
		$this->assertSame( array(), $this->t( 'elementor-mcp/add-code-snippet', array( 'title' => 's', 'code' => '  ' ), '*' ) );
	}

	public function test_abilities_that_copy_existing_css_always_declare_it_conservatively(): void {
		// apply-template carries only an id; the template's CSS is copied in.
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/apply-template', array( 'post_id' => 42, 'template_id' => 7 ) ) );
		// Never precise, even when the input carries a CSS-only shape.
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/duplicate-element', array( 'post_id' => 42, 'element_id' => 'a', 'settings' => array( 'custom_css' => 'x{}' ) ) ) );
		// A create-style call is declared on the wildcard governance passes.
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/set-widget-status', array( 'widget_id' => 7, 'status' => 'active' ), '*' ) );
		$this->assertSame( array( array( 'type' => 'custom_css', 'id' => '*' ) ), $this->t( 'elementor-mcp/set-widget-status', 'garbage', '*' ), 'whatever the input' );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/add-loop-grid', array( 'post_id' => 42, 'parent_id' => 'c', 'template_id' => '7' ) ), 'a loop renders inside the one page' );
	}

	public function test_a_copy_that_reaches_other_pages_is_the_wildcard_even_on_a_digit_id(): void {
		$wild = array( array( 'type' => 'custom_css', 'id' => '*' ) );
		// Governance passes the template's own post id; its CSS lands on every
		// page the conditions name, so only '*' lets a page-specific block match.
		$this->assertSame( $wild, $this->t( 'elementor-mcp/set-template-conditions', array( 'post_id' => 42, 'conditions' => array( 'include/general' ) ) ) );
		$this->assertSame( $wild, $this->t( 'elementor-mcp/set-popup-settings', array( 'post_id' => 42, 'triggers' => array( 'page_load' => 'yes' ) ) ) );
		$this->assertSame( $wild, $this->t( 'elementor-mcp/save-as-template', array( 'post_id' => 42, 'element_id' => 'a', 'title' => 't' ) ) );
	}

	public function test_embedding_a_saved_template_is_css_of_unknown_shape(): void {
		// Task 3 review I-1: the generic writes reach the same effect as
		// add-loop-grid / apply-template, so an embed key is conservative.
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/add-widget', array( 'post_id' => 42, 'parent_id' => 'c', 'widget_type' => 'loop-grid', 'settings' => array( 'template_id' => '7' ) ) ) );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/update-widget', array( 'post_id' => 42, 'element_id' => 'w', 'settings' => array( 'template_id' => 8 ) ) ), 'swapping the template' );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/batch-update', array( 'post_id' => 42, 'operations' => array( array( 'element_id' => 'w', 'settings' => array( 'template_id' => '9' ) ) ) ) ) );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'w', 'settings' => array( 'custom_css' => 'a{}', 'template_id' => '9' ) ) ), 'CSS beside an embed is never precise' );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/import-template', array( 'post_id' => 42, 'template_json' => array( array( 'elType' => 'widget', 'widgetType' => 'global', 'templateID' => 12 ) ) ) ), 'a global widget' );
		$this->assertSame(
			array( array( 'type' => 'custom_css', 'id' => '*' ) ),
			$this->t( 'elementor-mcp/build-page', array( 'title' => 'p', 'structure' => array( array( 'type' => 'container', 'children' => array( array( 'type' => 'widget', 'widget_type' => 'template', 'settings' => array( 'template_id' => '5' ) ) ) ) ) ), '*' )
		);
	}

	public function test_a_v4_component_instance_embeds_its_components_css(): void {
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'w', 'settings' => array( 'component_id' => 31 ) ) ) );
		$this->assertSame( self::CONS, $this->t( 'elementor-mcp/batch-update', array( 'post_id' => 42, 'operations' => array( array( 'element_id' => 'w', 'settings' => array( 'component_id' => '31' ) ) ) ) ) );
		$this->assertSame( array(), $this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'w', 'settings' => array( 'component_id' => '' ) ) ), 'empty component_id is no embed' );
	}

	public function test_an_empty_template_ref_is_no_embed(): void {
		foreach ( array( 0, '0', '', '  ', null, array() ) as $none ) {
			$this->assertSame( array(), $this->t( 'elementor-mcp/add-widget', array( 'post_id' => 42, 'parent_id' => 'c', 'widget_type' => 'loop-grid', 'settings' => array( 'template_id' => $none ) ) ), var_export( $none, true ) );
		}
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

	/**
	 * Review r1 I-1: css_only must be an explicit input shape per writer, not
	 * a generic "only custom_css / element_id keys at any depth" walk — that
	 * walk let a nested non-CSS setting (a real `title`, `link`, `html_tag`,
	 * or a V4 `styles` change) qualify as css_only, which an id-less
	 * `allow custom_css` rule would then auto-run.
	 */
	public function test_a_nested_non_css_setting_is_precise_but_never_css_only(): void {
		$this->assertSame(
			self::MIXED,
			$this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'a1', 'settings' => array( 'title' => array( 'custom_css' => 'a{}' ) ) ) ),
			'settings.title is a real setting being overwritten, not CSS'
		);
		$this->assertSame(
			self::MIXED,
			$this->t( 'elementor-mcp/update-widget', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}', 'link' => array( 'element_id' => 'x' ) ) ) ),
			'link is overwritten alongside custom_css'
		);
		$this->assertSame(
			self::MIXED,
			$this->t( 'elementor-mcp/update-page-settings', array( 'post_id' => 42, 'settings' => array( 'custom_css' => 'a{}', 'background_image' => array( 'custom_css' => '' ) ) ) ),
			'background_image is a page setting, not CSS, even though it nests a custom_css key'
		);
		$mixed_batch = array( 'post_id' => 42, 'operations' => array(
			array( 'element_id' => 'a', 'settings' => array( 'custom_css' => 'x', 'html_tag' => array( 'element_id' => 'h1' ) ) ),
		) );
		$this->assertSame( self::MIXED, $this->t( 'elementor-mcp/batch-update', $mixed_batch ), 'html_tag is overwritten alongside custom_css' );
		$atomic = array( 'post_id' => 42, 'element_id' => 'a', 'settings' => array( 'styles' => array( 's1' => array( 'variants' => array( array( 'custom_css' => 'x' ) ) ) ) ) );
		$this->assertSame( self::MIXED, $this->t( 'elementor-mcp/update-element', $atomic ), 'a V4 styles change is a structural change, not only CSS' );
	}

	public function test_settings_that_is_only_custom_css_is_still_exact(): void {
		$this->assertSame(
			self::EXACT,
			$this->t( 'elementor-mcp/update-element', array( 'post_id' => 42, 'element_id' => 'a1', 'settings' => array( 'custom_css' => 'a{}' ) ) )
		);
	}

	public function test_batch_update_with_no_operations_declares_nothing(): void {
		$this->assertSame( array(), $this->t( 'elementor-mcp/batch-update', array( 'post_id' => 42, 'operations' => array() ) ) );
	}
}
