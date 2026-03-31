<?php
/**
 * Tests the Hooks class $hook_map static property.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class HookMapTest extends TestCase {

	/**
	 * Raw source of class-hooks.php.
	 *
	 * @var string
	 */
	private static $hooks_source;

	/**
	 * Parsed hook map entries from source.
	 *
	 * @var array
	 */
	private static $parsed_hooks;

	public static function set_up_before_class(): void {
		self::$hooks_source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'includes/class-hooks.php' );

		// Parse the hook_map entries using regex.
		// Matches lines like: 'hook_name' => array( 'module1', 'module2' ),
		preg_match_all(
			"/['\"]([a-z0-9_]+)['\"]\s*=>\s*array\(\s*([^)]+)\)/",
			self::$hooks_source,
			$matches,
			PREG_SET_ORDER
		);

		self::$parsed_hooks = array();
		foreach ( $matches as $m ) {
			$hook    = $m[1];
			// Extract module slugs from the array values.
			preg_match_all( "/['\"]([a-z_]+)['\"]/", $m[2], $slugs );
			self::$parsed_hooks[ $hook ] = $slugs[1];
		}
	}

	public function test_hook_map_contains_exactly_seventeen_entries(): void {
		$this->assertCount(
			17,
			self::$parsed_hooks,
			'Hook map should contain exactly 17 entries. Found: ' . implode( ', ', array_keys( self::$parsed_hooks ) )
		);
	}

	/**
	 * Data provider: the 6 new WooCommerce hooks added in v1.1.0.
	 *
	 * @return array
	 */
	public function new_woocommerce_hooks_provider(): array {
		return array(
			'woocommerce_add_to_cart'              => array( 'woocommerce_add_to_cart', array( 'commerce' ) ),
			'woocommerce_cart_updated'             => array( 'woocommerce_cart_updated', array( 'commerce' ) ),
			'woocommerce_checkout_order_processed' => array( 'woocommerce_checkout_order_processed', array( 'commerce' ) ),
			'woocommerce_after_cart'               => array( 'woocommerce_after_cart', array( 'analytics' ) ),
			'woocommerce_after_checkout_form'      => array( 'woocommerce_after_checkout_form', array( 'analytics' ) ),
			'woocommerce_thankyou'                 => array( 'woocommerce_thankyou', array( 'analytics' ) ),
		);
	}

	/**
	 * @dataProvider new_woocommerce_hooks_provider
	 */
	public function test_new_woocommerce_hook_present( string $hook_name, array $expected_modules ): void {
		$this->assertArrayHasKey(
			$hook_name,
			self::$parsed_hooks,
			"Hook '$hook_name' should be in hook_map"
		);
	}

	/**
	 * @dataProvider new_woocommerce_hooks_provider
	 */
	public function test_new_woocommerce_hook_maps_to_correct_modules( string $hook_name, array $expected_modules ): void {
		$actual = self::$parsed_hooks[ $hook_name ] ?? array();
		sort( $expected_modules );
		sort( $actual );
		$this->assertSame(
			$expected_modules,
			$actual,
			"Hook '$hook_name' should map to modules: " . implode( ', ', $expected_modules )
		);
	}

	/**
	 * Each hook should map to at least one valid module slug.
	 */
	public function test_all_hooks_map_to_valid_modules(): void {
		$valid_slugs = array(
			'seo', 'security', 'content', 'crm', 'commerce',
			'performance', 'forms', 'analytics', 'backup', 'social', 'chat', 'audit',
		);

		foreach ( self::$parsed_hooks as $hook => $modules ) {
			$this->assertNotEmpty( $modules, "Hook '$hook' should map to at least one module" );
			foreach ( $modules as $slug ) {
				$this->assertContains(
					$slug,
					$valid_slugs,
					"Module '$slug' in hook '$hook' is not a valid module slug"
				);
			}
		}
	}

	/**
	 * Original hooks should still be present.
	 */
	public function test_original_hooks_present(): void {
		$original = array(
			'save_post',
			'publish_post',
			'wp_login_failed',
			'wp_login',
			'comment_post',
			'woocommerce_order_status_changed',
			'woocommerce_low_stock',
			'woocommerce_new_order',
			'wpforms_process_complete',
			'gform_after_submission',
			'wpcf7_mail_sent',
		);

		foreach ( $original as $hook ) {
			$this->assertArrayHasKey(
				$hook,
				self::$parsed_hooks,
				"Original hook '$hook' should still be in hook_map"
			);
		}
	}
}
