<?php
/**
 * Tests cron event registration, deactivation cleanup, and uninstall cleanup.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WPClaw\Activator;

class CronTest extends TestCase {

	/**
	 * All expected cron event hooks and their recurrences.
	 *
	 * @var array<string, string>
	 */
	private static $expected_events = array(
		'wp_claw_health_check'      => 'hourly',
		'wp_claw_sync_state'        => 'hourly',
		'wp_claw_update_check'      => 'twicedaily',
		'wp_claw_security_scan'     => 'twicedaily',
		'wp_claw_backup'            => 'daily',
		'wp_claw_seo_audit'         => 'daily',
		'wp_claw_analytics_report'  => 'weekly',
		'wp_claw_performance_check' => 'weekly',
		'wp_claw_analytics_cleanup' => 'weekly',
		'wp_claw_file_integrity'    => 'hourly',
		'wp_claw_malware_scan'      => 'daily',
		'wp_claw_ssl_check'         => 'daily',
		'wp_claw_abandoned_cart'    => 'hourly',
		'wp_claw_ab_test_eval'      => 'daily',
		'wp_claw_cwv_cleanup'       => 'weekly',
		'wp_claw_segmentation'      => 'weekly',
	);

	/**
	 * Valid WP-Cron recurrence values.
	 *
	 * @var string[]
	 */
	private static $valid_recurrences = array(
		'hourly',
		'twicedaily',
		'daily',
		'weekly',
	);

	public function test_get_cron_events_returns_exactly_sixteen(): void {
		$events = Activator::get_cron_events();
		$this->assertCount( 16, $events, 'Should return exactly 16 cron events' );
	}

	/**
	 * Data provider: expected cron event names.
	 *
	 * @return array
	 */
	public function cron_event_provider(): array {
		$data = array();
		foreach ( self::$expected_events as $hook => $recurrence ) {
			$data[ $hook ] = array( $hook, $recurrence );
		}
		return $data;
	}

	/**
	 * @dataProvider cron_event_provider
	 */
	public function test_expected_event_present( string $hook, string $expected_recurrence ): void {
		$events = Activator::get_cron_events();
		$this->assertArrayHasKey( $hook, $events, "Cron event '$hook' should be registered" );
	}

	/**
	 * @dataProvider cron_event_provider
	 */
	public function test_event_has_correct_recurrence( string $hook, string $expected_recurrence ): void {
		$events = Activator::get_cron_events();
		$this->assertSame(
			$expected_recurrence,
			$events[ $hook ],
			"Cron event '$hook' should have recurrence '$expected_recurrence'"
		);
	}

	public function test_all_recurrences_are_valid(): void {
		$events = Activator::get_cron_events();
		foreach ( $events as $hook => $recurrence ) {
			$this->assertContains(
				$recurrence,
				self::$valid_recurrences,
				"Recurrence '$recurrence' for hook '$hook' is not a standard WP-Cron schedule"
			);
		}
	}

	/**
	 * New v1.1.0 vision cron events must be present.
	 */
	public function test_new_vision_events_present(): void {
		$events   = Activator::get_cron_events();
		$new_hooks = array(
			'wp_claw_file_integrity',
			'wp_claw_malware_scan',
			'wp_claw_ssl_check',
			'wp_claw_abandoned_cart',
			'wp_claw_ab_test_eval',
			'wp_claw_cwv_cleanup',
			'wp_claw_segmentation',
		);
		foreach ( $new_hooks as $hook ) {
			$this->assertArrayHasKey( $hook, $events, "New vision event '$hook' should be registered" );
		}
	}

	/**
	 * Deactivator should clear all the same cron hooks.
	 */
	public function test_deactivator_has_matching_cron_hooks(): void {
		$source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'includes/class-deactivator.php' );
		$events = Activator::get_cron_events();

		foreach ( array_keys( $events ) as $hook ) {
			$this->assertStringContainsString(
				"'" . $hook . "'",
				$source,
				"Deactivator should reference cron hook '$hook'"
			);
		}
	}

	/**
	 * Uninstall should clear all the same cron hooks.
	 */
	public function test_uninstall_has_matching_cron_hooks(): void {
		$source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'uninstall.php' );
		$events = Activator::get_cron_events();

		foreach ( array_keys( $events ) as $hook ) {
			$this->assertStringContainsString(
				"'" . $hook . "'",
				$source,
				"uninstall.php should reference cron hook '$hook'"
			);
		}
	}
}
