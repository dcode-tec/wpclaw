<?php
/**
 * Tests constitutional safety checks in REST API and Cron classes.
 *
 * Verifies that T3 daily limits, health-fail halt, and related safety
 * mechanisms are present in the source code.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ConstitutionalTest extends TestCase {

	/**
	 * @var string
	 */
	private static $rest_source;

	/**
	 * @var string
	 */
	private static $cron_source;

	public static function set_up_before_class(): void {
		self::$rest_source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'includes/class-rest-api.php' );
		self::$cron_source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'includes/class-cron.php' );
	}

	// -------------------------------------------------------------------------
	// REST API — T3 daily count
	// -------------------------------------------------------------------------

	public function test_rest_api_has_t3_daily_count(): void {
		$this->assertStringContainsString(
			'wp_claw_t3_daily_count',
			self::$rest_source,
			'REST API should reference wp_claw_t3_daily_count transient for T3 limiting'
		);
	}

	// -------------------------------------------------------------------------
	// REST API — operations halted check
	// -------------------------------------------------------------------------

	public function test_rest_api_has_operations_halted(): void {
		$this->assertStringContainsString(
			'wp_claw_operations_halted',
			self::$rest_source,
			'REST API should check wp_claw_operations_halted option'
		);
	}

	// -------------------------------------------------------------------------
	// REST API — 429 status code for T3 limit
	// -------------------------------------------------------------------------

	public function test_rest_api_returns_429(): void {
		$this->assertStringContainsString(
			'429',
			self::$rest_source,
			'REST API should return HTTP 429 when T3 daily limit is reached'
		);
	}

	// -------------------------------------------------------------------------
	// REST API — 503 status code for halt
	// -------------------------------------------------------------------------

	public function test_rest_api_returns_503(): void {
		$this->assertStringContainsString(
			'503',
			self::$rest_source,
			'REST API should return HTTP 503 when operations are halted'
		);
	}

	// -------------------------------------------------------------------------
	// Cron — consecutive health failure counter
	// -------------------------------------------------------------------------

	public function test_cron_has_consecutive_health_fails(): void {
		$this->assertStringContainsString(
			'wp_claw_consecutive_health_fails',
			self::$cron_source,
			'Cron should track consecutive health failures via transient'
		);
	}

	// -------------------------------------------------------------------------
	// Cron — operations halt flag
	// -------------------------------------------------------------------------

	public function test_cron_has_operations_halted(): void {
		$this->assertStringContainsString(
			'wp_claw_operations_halted',
			self::$cron_source,
			'Cron should set wp_claw_operations_halted on repeated health failures'
		);
	}
}
