<?php
/**
 * WP-Claw uninstall handler.
 *
 * Removes all plugin data on uninstall: custom tables, options, transients,
 * cron events, capabilities, and the backup upload directory.
 *
 * @package    WPClaw
 * @subpackage WPClaw/uninstall
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- uninstall script, not loaded in global scope.

// The main plugin file is NOT loaded during uninstall — require helpers manually.
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers/capabilities.php';

global $wpdb;

// 1. Drop custom database tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_tasks' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_proposals' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_analytics' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_command_log' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_file_hashes' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_ab_tests' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_abandoned_carts' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_email_drafts' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_cwv_history' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_snapshots' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_task_chains' ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching

// 2. Delete all plugin options.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'wp_claw_' ) . '%'
	)
);

// 3. Delete all plugin transients.
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_wp_claw_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_timeout_wp_claw_' ) . '%'
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// 4. Remove custom capabilities from all roles.
wp_claw_remove_capabilities();

// 5. Clear all scheduled cron hooks.
$cron_hooks = array(
	'wp_claw_health_check',
	'wp_claw_sync_state',
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
	'wp_claw_daily_digest',
	'wp_claw_weekly_report',
);

foreach ( $cron_hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
	wp_clear_scheduled_hook( $hook );
}

// 6. Remove backup directory via WP_Filesystem.
$upload_dir = wp_upload_dir();
$backup_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-claw-backups';

if ( is_dir( $backup_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

	$filesystem = new WP_Filesystem_Direct( null );
	$filesystem->rmdir( $backup_dir, true );
}
