<?php
/**
 * Tests that uninstall.php cleans up all plugin data.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class UninstallTest extends TestCase {

	/**
	 * @var string
	 */
	private static $uninstall_source;

	public static function set_up_before_class(): void {
		self::$uninstall_source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'uninstall.php' );
	}

	/**
	 * Data provider: all 10 table names that should be dropped.
	 *
	 * @return array
	 */
	public function table_name_provider(): array {
		return array(
			'tasks'           => array( 'wp_claw_tasks' ),
			'proposals'       => array( 'wp_claw_proposals' ),
			'analytics'       => array( 'wp_claw_analytics' ),
			'command_log'     => array( 'wp_claw_command_log' ),
			'file_hashes'     => array( 'wp_claw_file_hashes' ),
			'ab_tests'        => array( 'wp_claw_ab_tests' ),
			'abandoned_carts' => array( 'wp_claw_abandoned_carts' ),
			'email_drafts'    => array( 'wp_claw_email_drafts' ),
			'cwv_history'     => array( 'wp_claw_cwv_history' ),
			'snapshots'       => array( 'wp_claw_snapshots' ),
		);
	}

	/**
	 * @dataProvider table_name_provider
	 */
	public function test_table_dropped_in_uninstall( string $table_name ): void {
		$this->assertStringContainsString(
			$table_name,
			self::$uninstall_source,
			"uninstall.php should DROP TABLE '$table_name'"
		);
	}

	/**
	 * Data provider: all 16 cron hooks.
	 *
	 * @return array
	 */
	public function cron_hook_provider(): array {
		$hooks = array(
			'wp_claw_health_check',
			'wp_claw_sync_state',
			'wp_claw_update_check',
			'wp_claw_security_scan',
			'wp_claw_backup',
			'wp_claw_seo_audit',
			'wp_claw_analytics_report',
			'wp_claw_performance_check',
			'wp_claw_analytics_cleanup',
			'wp_claw_file_integrity',
			'wp_claw_malware_scan',
			'wp_claw_ssl_check',
			'wp_claw_abandoned_cart',
			'wp_claw_ab_test_eval',
			'wp_claw_cwv_cleanup',
			'wp_claw_segmentation',
		);
		$data = array();
		foreach ( $hooks as $hook ) {
			$data[ $hook ] = array( $hook );
		}
		return $data;
	}

	/**
	 * @dataProvider cron_hook_provider
	 */
	public function test_cron_hook_cleared_in_uninstall( string $hook ): void {
		$this->assertStringContainsString(
			"'" . $hook . "'",
			self::$uninstall_source,
			"uninstall.php should clear cron hook '$hook'"
		);
	}

	public function test_capabilities_removed(): void {
		$this->assertStringContainsString(
			'wp_claw_remove_capabilities',
			self::$uninstall_source,
			'uninstall.php should call wp_claw_remove_capabilities()'
		);
	}

	public function test_options_deleted(): void {
		$this->assertStringContainsString(
			"wp_claw_",
			self::$uninstall_source,
			'uninstall.php should delete wp_claw_ prefixed options'
		);
	}

	public function test_transients_deleted(): void {
		$this->assertStringContainsString(
			'_transient_wp_claw_',
			self::$uninstall_source,
			'uninstall.php should delete wp_claw_ transients'
		);
	}
}
