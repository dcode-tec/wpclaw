<?php
/**
 * Plugin deactivation handler.
 *
 * Runs when the plugin is deactivated (not uninstalled). Clears
 * scheduled cron events and plugin transients. Does NOT delete
 * options or database tables — those are removed only on uninstall
 * via uninstall.php to preserve data during temporary deactivation.
 *
 * @package    WPClaw
 * @subpackage WPClaw/includes
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

namespace WPClaw;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation class.
 *
 * All methods are static so the class can be called from the
 * register_deactivation_hook() callback without instantiation.
 *
 * Intentional deactivation-time behaviour:
 *   - Clear all WP-Cron events   (would pile up if plugin is reactivated)
 *   - Delete runtime transients   (stale cache after reactivation)
 *   - Log the deactivation event  (audit trail)
 *
 * NOT done on deactivation (done only on uninstall):
 *   - Deleting database tables
 *   - Deleting plugin options
 *   - Removing capabilities
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Cron event hooks registered by WP-Claw.
	 *
	 * Kept as a constant so the same list is used in the activator,
	 * the deactivator, and any future update/repair routines.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private static array $cron_hooks = [
		'wp_claw_health_check',
		'wp_claw_sync_state',
		'wp_claw_update_check',
		'wp_claw_security_scan',
		'wp_claw_backup',
		'wp_claw_seo_audit',
		'wp_claw_analytics_report',
		'wp_claw_performance_check',
		'wp_claw_analytics_cleanup',
	];

	/**
	 * Transient keys that hold runtime-only data.
	 *
	 * These transients become stale after deactivation and should
	 * be cleared so they are rebuilt fresh on reactivation.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private static array $transient_keys = [
		'wp_claw_task_queue',
		'wp_claw_last_health',
		'wp_claw_circuit_failures',
		'wp_claw_update_data',
		'wp_claw_queue_lock',
	];

	/**
	 * Run all deactivation routines.
	 *
	 * Clears scheduled cron events, deletes stale transients, and
	 * writes a log entry. Intentionally does NOT remove options or
	 * database tables so that data is preserved during temporary
	 * deactivation (e.g. during a WP core update).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// --- 1. Clear all scheduled WP-Cron events --------------------------
		foreach ( self::$cron_hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// --- 2. Delete stale runtime transients -----------------------------
		foreach ( self::$transient_keys as $key ) {
			delete_transient( $key );
		}

		// --- 3. Log deactivation (helpers are loaded before this fires) -----
		wp_claw_log( 'Plugin deactivated.', 'info' );
	}
}
