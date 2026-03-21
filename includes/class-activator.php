<?php
/**
 * Plugin activation handler.
 *
 * Runs once when the plugin is activated. Creates database tables,
 * sets default options, adds capabilities, schedules cron events,
 * and triggers the activation redirect.
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
 * Plugin activation class.
 *
 * All methods are static so the class can be called from the
 * register_activation_hook() callback without instantiation.
 * The activate() method is also safe to call again on update
 * because it uses add_option() (not update_option()) for defaults,
 * and create_tables() uses dbDelta() which is idempotent.
 *
 * @since 1.0.0
 */
class Activator {

	/**
	 * Run all activation routines.
	 *
	 * Validates server requirements, creates database tables,
	 * sets default options, registers capabilities, schedules
	 * WP-Cron events, and sets the activation redirect transient.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		// --- 1. PHP version check -------------------------------------------
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die(
				esc_html__( 'WP-Claw requires PHP 7.4 or higher.', 'wp-claw' ),
				esc_html__( 'Plugin Activation Error', 'wp-claw' ),
				[ 'back_link' => true ]
			);
		}

		// --- 2. WordPress version check -------------------------------------
		global $wp_version;
		if ( version_compare( $wp_version, '6.4', '<' ) ) {
			wp_die(
				esc_html__( 'WP-Claw requires WordPress 6.4 or higher.', 'wp-claw' ),
				esc_html__( 'Plugin Activation Error', 'wp-claw' ),
				[ 'back_link' => true ]
			);
		}

		// --- 3. Create / upgrade database tables ----------------------------
		self::create_tables();

		// --- 4. Set default options (add_option skips if already set) -------
		add_option( 'wp_claw_db_version', '1.0.0' );
		add_option( 'wp_claw_connection_mode', 'managed' );
		add_option(
			'wp_claw_enabled_modules',
			[ 'seo', 'security', 'content', 'crm', 'commerce', 'performance', 'forms', 'analytics', 'backup', 'social', 'chat' ]
		);
		add_option( 'wp_claw_chat_enabled', true );
		add_option( 'wp_claw_chat_position', 'bottom-right' );
		add_option( 'wp_claw_chat_welcome', 'Hi! How can I help you today?' );
		add_option( 'wp_claw_chat_agent_name', 'Concierge' );
		add_option( 'wp_claw_analytics_enabled', false );

		// --- 5. Add capabilities to WordPress roles -------------------------
		wp_claw_add_capabilities();

		// --- 6. Schedule WP-Cron events -------------------------------------
		$cron_events = [
			'wp_claw_health_check'      => 'hourly',
			'wp_claw_sync_state'        => 'hourly',
			'wp_claw_update_check'      => 'twicedaily',
			'wp_claw_security_scan'     => 'twicedaily',
			'wp_claw_backup'            => 'daily',
			'wp_claw_seo_audit'         => 'daily',
			'wp_claw_analytics_report'  => 'weekly',
			'wp_claw_performance_check' => 'weekly',
			'wp_claw_analytics_cleanup' => 'weekly',
		];

		$now = time();
		foreach ( $cron_events as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( $now, $recurrence, $hook );
			}
		}

		// --- 7. Set activation redirect transient (30-second TTL) ----------
		set_transient( 'wp_claw_activation_redirect', true, 30 );

		// --- 8. Flush rewrite rules -----------------------------------------
		flush_rewrite_rules();
	}

	/**
	 * Create or upgrade the WP-Claw database tables.
	 *
	 * Uses dbDelta() so it is safe to call on subsequent activations and
	 * during plugin updates — existing tables are only modified, never
	 * dropped. Must be public so it can be called from the update routine.
	 *
	 * Tables created:
	 *   {prefix}wp_claw_tasks       — Local mirror of agent task log
	 *   {prefix}wp_claw_proposals   — Proposals awaiting admin approval
	 *   {prefix}wp_claw_analytics   — Privacy-first analytics events
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional schema creation on activation.

		$sql = [];

		// Table: wp_claw_tasks — agent task log (local mirror of Klawty tasks).
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_tasks (
			task_id VARCHAR(64) NOT NULL,
			agent VARCHAR(32) NOT NULL DEFAULT '',
			module VARCHAR(32) NOT NULL DEFAULT '',
			action VARCHAR(128) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			details LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (task_id),
			KEY idx_agent_status (agent, status),
			KEY idx_module (module),
			KEY idx_created (created_at)
		) {$charset_collate};";

		// Table: wp_claw_proposals — proposals requiring admin approval.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_proposals (
			proposal_id VARCHAR(64) NOT NULL,
			agent VARCHAR(32) NOT NULL DEFAULT '',
			action VARCHAR(128) NOT NULL DEFAULT '',
			tier VARCHAR(16) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			details LONGTEXT NULL,
			approved_by BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at DATETIME NULL,
			PRIMARY KEY  (proposal_id),
			KEY idx_status (status),
			KEY idx_agent (agent),
			KEY idx_created (created_at)
		) {$charset_collate};";

		// Table: wp_claw_analytics — privacy-first analytics events (no PII stored).
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_analytics (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			page_url VARCHAR(512) NOT NULL DEFAULT '',
			referrer VARCHAR(512) NOT NULL DEFAULT '',
			event_type VARCHAR(32) NOT NULL DEFAULT 'pageview',
			session_hash VARCHAR(64) NOT NULL DEFAULT '',
			device_type VARCHAR(16) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_event_type (event_type),
			KEY idx_created (created_at),
			KEY idx_page_url (page_url(191))
		) {$charset_collate};";

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
