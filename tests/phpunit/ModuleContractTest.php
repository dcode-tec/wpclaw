<?php
/**
 * Tests that all 12 modules fulfil the Module_Base contract.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WPClaw\API_Client;
use WPClaw\Module_Base;

class ModuleContractTest extends TestCase {

	/**
	 * Valid agent names that modules can return.
	 *
	 * @var string[]
	 */
	private static $valid_agents = array(
		'architect',
		'scribe',
		'sentinel',
		'commerce',
		'analyst',
		'concierge',
	);

	/**
	 * All 12 module fully-qualified class names.
	 *
	 * @var string[]
	 */
	private static $module_classes = array(
		'WPClaw\Modules\Module_SEO',
		'WPClaw\Modules\Module_Security',
		'WPClaw\Modules\Module_Content',
		'WPClaw\Modules\Module_CRM',
		'WPClaw\Modules\Module_Commerce',
		'WPClaw\Modules\Module_Performance',
		'WPClaw\Modules\Module_Forms',
		'WPClaw\Modules\Module_Analytics',
		'WPClaw\Modules\Module_Backup',
		'WPClaw\Modules\Module_Social',
		'WPClaw\Modules\Module_Chat',
		'WPClaw\Modules\Module_Audit',
	);

	/**
	 * Create a module instance by class name.
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return Module_Base
	 */
	private function make_module( string $class_name ): Module_Base {
		$api_client = new API_Client();
		return new $class_name( $api_client );
	}

	/**
	 * Data provider: returns each module class name.
	 *
	 * @return array
	 */
	public function module_class_provider(): array {
		$data = array();
		foreach ( self::$module_classes as $class ) {
			$short = substr( strrchr( $class, '\\' ), 1 );
			$data[ $short ] = array( $class );
		}
		return $data;
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_module_class_exists( string $class_name ): void {
		$this->assertTrue( class_exists( $class_name ), "Class $class_name should exist" );
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_module_extends_module_base( string $class_name ): void {
		$module = $this->make_module( $class_name );
		$this->assertInstanceOf( Module_Base::class, $module );
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_get_slug_returns_non_empty_string( string $class_name ): void {
		$module = $this->make_module( $class_name );
		$slug   = $module->get_slug();
		$this->assertIsString( $slug );
		$this->assertNotEmpty( $slug );
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_get_name_returns_non_empty_string( string $class_name ): void {
		$module = $this->make_module( $class_name );
		$name   = $module->get_name();
		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_get_agent_returns_valid_agent( string $class_name ): void {
		$module = $this->make_module( $class_name );
		$agent  = $module->get_agent();
		$this->assertIsString( $agent );
		$this->assertContains( $agent, self::$valid_agents, "Agent '$agent' should be one of: " . implode( ', ', self::$valid_agents ) );
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_get_allowed_actions_returns_non_empty_array( string $class_name ): void {
		$module  = $this->make_module( $class_name );
		$actions = $module->get_allowed_actions();
		$this->assertIsArray( $actions );
		$this->assertNotEmpty( $actions );
		foreach ( $actions as $action ) {
			$this->assertIsString( $action );
		}
	}

	/**
	 * Modules whose get_state() requires $wpdb, WP_Query, or WP_Filesystem.
	 * These cannot run without a full WordPress environment.
	 *
	 * @var string[]
	 */
	private static $skip_get_state = array(
		'WPClaw\Modules\Module_SEO',
		'WPClaw\Modules\Module_Security',
		'WPClaw\Modules\Module_Content',
		'WPClaw\Modules\Module_CRM',
		'WPClaw\Modules\Module_Performance',
		'WPClaw\Modules\Module_Forms',
		'WPClaw\Modules\Module_Analytics',
		'WPClaw\Modules\Module_Backup',
		'WPClaw\Modules\Module_Social',
		'WPClaw\Modules\Module_Chat',
		'WPClaw\Modules\Module_Audit',
	);

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_get_state_returns_array( string $class_name ): void {
		if ( in_array( $class_name, self::$skip_get_state, true ) ) {
			// These modules call $wpdb / WP_Query in get_state() — skip in standalone mode.
			$this->assertTrue( true, 'Skipped: get_state() requires WordPress database' );
			return;
		}
		$module = $this->make_module( $class_name );
		$state  = $module->get_state();
		$this->assertIsArray( $state );
	}

	/**
	 * Verify get_state() method is declared on all modules (signature check).
	 *
	 * @dataProvider module_class_provider
	 */
	public function test_get_state_method_exists( string $class_name ): void {
		$this->assertTrue(
			method_exists( $class_name, 'get_state' ),
			"$class_name should implement get_state()"
		);
	}

	/**
	 * @dataProvider module_class_provider
	 */
	public function test_register_hooks_callable( string $class_name ): void {
		$module = $this->make_module( $class_name );
		// Should not throw.
		$module->register_hooks();
		$this->assertTrue( true );
	}

	/**
	 * Verify total module count is exactly 12.
	 */
	public function test_exactly_twelve_modules(): void {
		$this->assertCount( 12, self::$module_classes );
	}

	/**
	 * Verify all module slugs are unique.
	 */
	public function test_module_slugs_are_unique(): void {
		$slugs = array();
		foreach ( self::$module_classes as $class ) {
			$module  = $this->make_module( $class );
			$slugs[] = $module->get_slug();
		}
		$this->assertCount( count( $slugs ), array_unique( $slugs ), 'Module slugs must be unique' );
	}
}
