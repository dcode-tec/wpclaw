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
				esc_html__( 'WP-Claw requires PHP 7.4 or higher.', 'claw-agent' ),
				esc_html__( 'Plugin Activation Error', 'claw-agent' ),
				array( 'back_link' => true )
			);
		}

		// --- 2. WordPress version check -------------------------------------
		global $wp_version;
		if ( version_compare( $wp_version, '6.4', '<' ) ) {
			wp_die(
				esc_html__( 'WP-Claw requires WordPress 6.4 or higher.', 'claw-agent' ),
				esc_html__( 'Plugin Activation Error', 'claw-agent' ),
				array( 'back_link' => true )
			);
		}

		// --- 3. Create / upgrade database tables ----------------------------
		self::create_tables();

		// --- 4. Set default options (add_option skips if already set) -------
		add_option( 'wp_claw_db_version', '1.0.0' );
		add_option( 'wp_claw_connection_mode', 'managed' );
		add_option(
			'wp_claw_enabled_modules',
			array( 'seo', 'security', 'content', 'crm', 'commerce', 'performance', 'forms', 'analytics', 'backup', 'social', 'chat', 'audit' )
		);
		add_option( 'wp_claw_chat_enabled', true );
		add_option( 'wp_claw_chat_position', 'bottom-right' );
		add_option( 'wp_claw_chat_welcome', 'Hi! How can I help you today?' );
		add_option( 'wp_claw_chat_agent_name', 'Concierge' );
		add_option( 'wp_claw_analytics_enabled', false );
		add_option( 'wp_claw_chat_consent_text', 'This chat is powered by AI. Your messages are processed to provide assistance. No personal data is stored after your session ends unless you provide contact information.' );
		add_option( 'wp_claw_chat_privacy_url', '' );

		// --- 5. Add capabilities to WordPress roles -------------------------
		wp_claw_add_capabilities();

		// --- 6. Schedule WP-Cron events -------------------------------------
		$cron_events = self::get_cron_events();

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

		$sql = array();

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

		// Table: wp_claw_command_log — Command Center audit trail (encrypted prompts).
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_command_log (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL,
			ip_address varchar(45) NOT NULL,
			prompt text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'sent',
			reason text DEFAULT '',
			task_id varchar(100) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_id (user_id),
			KEY idx_status (status),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		// Table: wp_claw_file_hashes — file integrity baseline for core/plugin/theme files.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_file_hashes (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			file_path VARCHAR(512) NOT NULL DEFAULT '',
			file_hash VARCHAR(64) NOT NULL DEFAULT '',
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			scope VARCHAR(16) NOT NULL DEFAULT 'core',
			status VARCHAR(16) NOT NULL DEFAULT 'clean',
			checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_file_path (file_path(191)),
			KEY idx_scope (scope),
			KEY idx_status (status)
		) {$charset_collate};";

		// Table: wp_claw_ab_tests — SEO A/B test variants and results.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_ab_tests (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			variant_a_title VARCHAR(255) NOT NULL DEFAULT '',
			variant_a_desc VARCHAR(255) NOT NULL DEFAULT '',
			variant_b_title VARCHAR(255) NOT NULL DEFAULT '',
			variant_b_desc VARCHAR(255) NOT NULL DEFAULT '',
			impressions_a INT UNSIGNED NOT NULL DEFAULT 0,
			impressions_b INT UNSIGNED NOT NULL DEFAULT 0,
			clicks_a INT UNSIGNED NOT NULL DEFAULT 0,
			clicks_b INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'running',
			winner VARCHAR(1) DEFAULT NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ended_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id),
			KEY idx_status (status)
		) {$charset_collate};";

		// Table: wp_claw_abandoned_carts — WooCommerce cart recovery tracking.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_abandoned_carts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			email VARCHAR(255) DEFAULT NULL,
			cart_contents LONGTEXT NOT NULL,
			cart_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(3) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			email_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_email_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			recovered_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_session (session_id),
			KEY idx_status (status),
			KEY idx_created (created_at),
			KEY idx_user_id (user_id)
		) {$charset_collate};";

		// Table: wp_claw_email_drafts — agent-drafted emails awaiting approval.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_email_drafts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_task_id VARCHAR(64) DEFAULT NULL,
			recipient_email VARCHAR(255) NOT NULL DEFAULT '',
			recipient_name VARCHAR(255) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			body LONGTEXT NOT NULL,
			language VARCHAR(5) NOT NULL DEFAULT 'en',
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			approved_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_lead (lead_task_id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		// Table: wp_claw_cwv_history — Core Web Vitals time-series data.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_cwv_history (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			page_url VARCHAR(512) NOT NULL DEFAULT '',
			lcp_ms INT UNSIGNED DEFAULT NULL,
			inp_ms INT UNSIGNED DEFAULT NULL,
			cls_score DECIMAL(5,3) DEFAULT NULL,
			ttfb_ms INT UNSIGNED DEFAULT NULL,
			rating VARCHAR(8) NOT NULL DEFAULT 'unknown',
			measured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_page_url (page_url(191)),
			KEY idx_measured (measured_at),
			KEY idx_rating (rating)
		) {$charset_collate};";

		// Table: wp_claw_snapshots — rollback snapshots for agent actions.
		$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_snapshots (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_id VARCHAR(64) NOT NULL,
			agent VARCHAR(32) NOT NULL DEFAULT '',
			action_description VARCHAR(255) NOT NULL DEFAULT '',
			snapshot_type VARCHAR(16) NOT NULL DEFAULT 'database',
			path VARCHAR(512) DEFAULT NULL,
			tables_count INT UNSIGNED NOT NULL DEFAULT 0,
			files_count INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_snapshot_id (snapshot_id),
			KEY idx_status (status),
			KEY idx_expires (expires_at),
			KEY idx_agent (agent)
		) {$charset_collate};";

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Return the full list of WP-Cron events registered by WP-Claw.
	 *
	 * Centralises the event list so that activate() and deactivate() (and
	 * any future callers) share the same source of truth.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string> Hook name => recurrence.
	 */
	public static function get_cron_events(): array {
		return array(
			// Existing events.
			'wp_claw_health_check'      => 'hourly',
			'wp_claw_sync_state'        => 'hourly',
			'wp_claw_update_check'      => 'twicedaily',
			'wp_claw_security_scan'     => 'twicedaily',
			'wp_claw_backup'            => 'daily',
			'wp_claw_seo_audit'         => 'daily',
			'wp_claw_analytics_report'  => 'weekly',
			'wp_claw_performance_check' => 'weekly',
			'wp_claw_analytics_cleanup' => 'weekly',
			// Vision capabilities events.
			'wp_claw_file_integrity'    => 'hourly',
			'wp_claw_malware_scan'      => 'daily',
			'wp_claw_ssl_check'         => 'daily',
			'wp_claw_abandoned_cart'    => 'hourly',
			'wp_claw_ab_test_eval'      => 'daily',
			'wp_claw_cwv_cleanup'       => 'weekly',
			'wp_claw_segmentation'      => 'weekly',
		);
	}
}
