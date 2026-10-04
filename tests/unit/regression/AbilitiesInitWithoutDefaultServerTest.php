<?php
/**
 * The MCP server must come up on a site where the adapter's default server
 * does not.
 *
 * Elementor 4.3.3 registers __return_false on mcp_adapter_create_default_server
 * while its own MCP switch is off — for every plugin on the site. That server
 * was what first touched the lazy Abilities API during mcp_adapter_init, so
 * without it wp_abilities_api_init had not fired when register_mcp_server()
 * ran at priority 20: the ability list was empty and the endpoint was never
 * created. EMCP shipped that bug and fixed it in 3.18.1 (references
 * 2026-10-05 re-verification, P9.1).
 *
 * @package Elementor_MCP
 */

namespace Elementor_MCP\Tests\Regression;

use PHPUnit\Framework\TestCase;

class AbilitiesInitWithoutDefaultServerTest extends TestCase {

	/** @var array<int, array<int, mixed>> */
	private $created = array();

	protected function setUp(): void {
		unset( $GLOBALS['_options'], $GLOBALS['_abilities_init'] );
		$this->created = array();
		$this->set_ability_names( array() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_options'], $GLOBALS['_abilities_init'] );
		$this->set_ability_names( array() );
	}

	private function set_ability_names( array $names ): void {
		$property = new \ReflectionProperty( \Elementor_MCP_Plugin::class, 'ability_names' );
		$property->setAccessible( true );
		$property->setValue( \Elementor_MCP_Plugin::instance(), $names );
	}

	/** An adapter that records what it was asked to create. */
	private function adapter(): object {
		$created = &$this->created;
		return new class( $created ) {
			/** @var array */
			private $created;
			public function __construct( array &$created ) {
				$this->created = &$created;
			}
			public function create_server( ...$args ) {
				$this->created[] = $args;
			}
		};
	}

	/**
	 * Nothing touched the Abilities API before us: the first registry access
	 * fires the init, which is where our abilities get registered.
	 */
	public function test_the_server_is_created_when_nothing_initialised_the_abilities_api(): void {
		$GLOBALS['_abilities_init'] = function (): void {
			$this->set_ability_names( array( 'elementor-mcp/list-pages', 'elementor-mcp/get-page-structure' ) );
		};

		\Elementor_MCP_Plugin::instance()->register_mcp_server( $this->adapter() );

		$this->assertCount( 1, $this->created, 'the endpoint must exist without the adapter default server' );
		$this->assertSame( \Elementor_MCP_Plugin::SERVER_ID, $this->created[0][0] );
		$this->assertSame(
			array( 'elementor-mcp/list-pages', 'elementor-mcp/get-page-structure' ),
			$this->created[0][9],
			'the tools handed to the adapter are the ones the init registered'
		);
		$this->assertNull( $GLOBALS['_abilities_init'], 'the init ran exactly once' );
	}

	/** Already initialised (the default server, SiteAgent, anything): do not ask again. */
	public function test_an_already_registered_list_is_used_as_it_is(): void {
		$this->set_ability_names( array( 'elementor-mcp/list-pages' ) );
		$asked                      = false;
		$GLOBALS['_abilities_init'] = function () use ( &$asked ): void {
			$asked = true;
		};

		\Elementor_MCP_Plugin::instance()->register_mcp_server( $this->adapter() );

		$this->assertFalse( $asked, 'the registry is not touched when the list is already there' );
		$this->assertCount( 1, $this->created );
		$this->assertSame( array( 'elementor-mcp/list-pages' ), $this->created[0][9] );
	}

	/** The init ran and registered nothing (Elementor inactive): still no server, as before. */
	public function test_no_abilities_after_the_init_still_means_no_server(): void {
		$GLOBALS['_abilities_init'] = static function (): void {};

		\Elementor_MCP_Plugin::instance()->register_mcp_server( $this->adapter() );

		$this->assertSame( array(), $this->created );
	}

	/** The admin's off switch wins before anything is loaded. */
	public function test_a_disabled_server_does_not_touch_the_abilities_api(): void {
		$GLOBALS['_options'][ \Elementor_MCP_Plugin::OPTION_SERVER_ENABLED ] = '0';
		$asked                      = false;
		$GLOBALS['_abilities_init'] = function () use ( &$asked ): void {
			$asked = true;
		};

		\Elementor_MCP_Plugin::instance()->register_mcp_server( $this->adapter() );

		$this->assertFalse( $asked );
		$this->assertSame( array(), $this->created );
	}
}
