<?php
/**
 * WP-Cron event handlers for scheduled background tasks.
 *
 * Registers action callbacks for all nine WP-Claw cron events and
 * implements each handler. Runs periodic health checks, state syncs,
 * update checks, per-module scheduled runs, and analytics data cleanup.
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
 * Handles all WP-Claw scheduled cron event callbacks.
 *
 * Responsibilities:
 *  - Register PHP action callbacks for all nine cron events via add_action().
 *  - Run hourly health checks against the Klawty API.
 *  - Sync the current WordPress site state to the Klawty instance.
 *  - Poll the Klawty API for available plugin updates.
 *  - Dispatch per-module scheduled tasks (seo_audit, backup, etc.).
 *  - Delete analytics rows older than the configured retention period.
 *
 * @since 1.0.0
 */
class Cron {

	/**
	 * API client instance used to communicate with the Klawty instance.
	 *
	 * @since 1.0.0
	 *
	 * @var API_Client
	 */
	private API_Client $api_client;

	/**
	 * Constructor.
	 *
	 * Registers all cron event action hooks. Module-specific cron events
	 * use closures to capture the module slug without requiring separate
	 * callback methods for each module.
	 *
	 * @since 1.0.0
	 *
	 * @param API_Client $api_client The Klawty API client instance.
	 */
	public function __construct( API_Client $api_client ) {
		$this->api_client = $api_client;

		// Core system events.
		add_action( 'wp_claw_health_check', array( $this, 'run_health_check' ) );
		add_action( 'wp_claw_sync_state', array( $this, 'run_sync_state' ) );
		// Update check disabled for wordpress.org hosted plugins.
		// add_action( 'wp_claw_update_check', array( $this, 'run_update_check' ) );

		// Module-specific scheduled events (closures capture slug cleanly).
		add_action(
			'wp_claw_security_scan',
			function () {
				$this->run_module_cron( 'security' );
			}
		);

		add_action(
			'wp_claw_backup',
			function () {
				$this->run_module_cron( 'backup' );
			}
		);

		add_action(
			'wp_claw_seo_audit',
			function () {
				$this->run_module_cron( 'seo' );
			}
		);

		add_action(
			'wp_claw_analytics_report',
			function () {
				$this->run_module_cron( 'analytics' );
			}
		);

		add_action(
			'wp_claw_performance_check',
			function () {
				$this->run_module_cron( 'performance' );
			}
		);

		// Analytics data retention cleanup.
		add_action( 'wp_claw_analytics_cleanup', array( $this, 'run_analytics_cleanup' ) );

		// Vision capability events.
		add_action( 'wp_claw_file_integrity', array( $this, 'run_file_integrity' ) );
		add_action( 'wp_claw_malware_scan', array( $this, 'run_malware_scan' ) );
		add_action( 'wp_claw_ssl_check', array( $this, 'run_ssl_check' ) );

		add_action(
			'wp_claw_abandoned_cart',
			function () {
				$this->run_abandoned_cart_check();
			}
		);

		add_action( 'wp_claw_ab_test_eval', array( $this, 'run_ab_test_eval' ) );
		add_action( 'wp_claw_cwv_cleanup', array( $this, 'run_cwv_cleanup' ) );
		add_action( 'wp_claw_segmentation', array( $this, 'run_segmentation' ) );
	}

	// -------------------------------------------------------------------------
	// Core system cron handlers
	// -------------------------------------------------------------------------

	/**
	 * Hourly: ping the Klawty instance and update connection status.
	 *
	 * The API client's health_check() method caches the response in the
	 * wp_claw_health_data transient so is_connected() can read it without
	 * making an HTTP request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run_health_check(): void {
		$result = $this->api_client->health_check();

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Scheduled health check failed.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);

			$consecutive = (int) get_transient( 'wp_claw_consecutive_health_fails' );
			set_transient( 'wp_claw_consecutive_health_fails', $consecutive + 1, 2 * HOUR_IN_SECONDS );
			if ( $consecutive + 1 >= 2 ) {
				update_option( 'wp_claw_operations_halted', true, false );
				wp_claw_log_error( 'Two consecutive health check failures — halting T2/T3 operations.' );
			}
			// Bust admin bar cache so disconnected state shows immediately.
			delete_transient( 'wp_claw_admin_bar_status' );
			return;
		}

		wp_claw_log_debug( 'Scheduled health check completed.', array( 'status' => $result['status'] ?? 'unknown' ) );
		delete_transient( 'wp_claw_consecutive_health_fails' );
		delete_option( 'wp_claw_operations_halted' );
		// Bust admin bar cache so next page load reflects new status.
		delete_transient( 'wp_claw_admin_bar_status' );
	}

	/**
	 * Hourly: gather WordPress site state and push it to the Klawty instance.
	 *
	 * Agents use this snapshot to make context-aware decisions (e.g. the
	 * Commerce agent needs to know the current WooCommerce order count).
	 * The sync records a timestamp in wp_options regardless of whether
	 * the API call succeeds — failed syncs log a warning but are not fatal.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run_sync_state(): void {
		global $wpdb;

		// Build a lightweight state snapshot to send to Klawty.
		$state = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'claw_version'      => defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.2.2',
			'site_url'          => get_site_url(),
			'theme'             => get_stylesheet(),
			'active_plugins'    => $this->get_active_plugin_slugs(),
			'post_counts'       => $this->get_post_counts(),
			'woocommerce'       => $this->get_woocommerce_state(),
			'enabled_modules'   => (array) get_option( 'wp_claw_enabled_modules', array() ),
			'pending_proposals' => $this->get_pending_proposal_count(),
			'synced_at'         => gmdate( 'c' ),
		);

		/**
		 * Allow other code to extend the state snapshot sent to Klawty.
		 *
		 * @since 1.0.0
		 *
		 * @param array $state The state array before it is sent.
		 */
		$state = (array) apply_filters( 'wp_claw_sync_state', $state );

		$plugin          = WP_Claw::get_instance();
		$enabled_modules = (array) get_option( 'wp_claw_enabled_modules', array() );
		$state['modules'] = array();
		foreach ( $enabled_modules as $slug ) {
			$module = $plugin->get_module( sanitize_key( $slug ) );
			if ( null !== $module ) {
				$state['modules'][ $slug ] = $module->get_state();
			}
		}

		$state['signals']         = $this->get_site_signals();
		$state['tooling']         = $this->get_site_tooling();
		$state['health']          = $this->get_site_health( (int) ( $state['signals']['autoload_bytes'] ?? 0 ) );
		$state['recommendations'] = $this->build_recommendations( $state['signals'], $state['health'] );

		$result = $this->api_client->sync_state( $state );

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'State sync failed.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		} else {
			wp_claw_log_debug( 'State sync completed successfully.' );
		}

		update_option( 'wp_claw_last_sync', current_time( 'mysql' ), false );
	}

	// -------------------------------------------------------------------------
	// Site triage helpers
	// -------------------------------------------------------------------------

	/**
	 * Collect high-level site signals that describe the environment.
	 *
	 * Returns a flat map of boolean/integer signals agents can use to make
	 * context-aware decisions without querying WordPress themselves.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string,mixed> Signal name => value.
	 */
	private function get_site_signals(): array {
		global $wpdb;

		// Autoload payload size. phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload_bytes = (int) $wpdb->get_var(
			"SELECT SUM( LENGTH( option_value ) ) FROM {$wpdb->options} WHERE autoload = 'yes'"
		);

		return array(
			'has_woocommerce'  => class_exists( 'WooCommerce' ),
			'has_block_theme'  => wp_is_block_theme(),
			'has_multisite'    => is_multisite(),
			'has_object_cache' => wp_using_ext_object_cache(),
			'has_page_cache'   => defined( 'WP_CACHE' ) && WP_CACHE,
			'ssl_active'       => is_ssl(),
			'debug_mode'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'autoload_bytes'   => $autoload_bytes,
		);
	}

	/**
	 * Collect server / PHP environment tooling details.
	 *
	 * The SERVER_SOFTWARE value is sanitized before storage to strip any
	 * potentially unsafe characters originating from the web server header.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string,string|int> Tooling name => value.
	 */
	private function get_site_tooling(): array {
		global $wpdb;

		$server_software = '';
		if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$server_software = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
		}

		return array(
			'php_version'         => PHP_VERSION,
			'mysql_version'       => $wpdb->db_version(),
			'server_software'     => $server_software,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
		);
	}

	/**
	 * Perform a lightweight health audit of the WordPress installation.
	 *
	 * Checks overdue WP-Cron hooks, autoload bloat, broken active plugins,
	 * missing WP-Claw database tables, and available disk space.
	 *
	 * @since 1.4.0
	 *
	 * @param int $autoload_bytes_hint Pre-computed autoload byte total from signals (avoids duplicate query).
	 *
	 * @return array<string,mixed> Health metric name => value.
	 */
	private function get_site_health( int $autoload_bytes_hint = 0 ): array {
		global $wpdb;

		// --- Overdue cron count --------------------------------------------------
		$overdue_count = 0;
		$cron_array    = _get_cron_array();
		if ( is_array( $cron_array ) ) {
			foreach ( $cron_array as $timestamp => $hooks ) {
				if ( (int) $timestamp < time() ) {
					$overdue_count += count( $hooks );
				}
			}
		}

		// --- Autoload bloat (reuse signals value if available) -------------------
		// The autoload_bytes value may already be computed by get_site_signals().
		// We accept it as a parameter to avoid a duplicate DB query.
		$autoload_bytes = $autoload_bytes_hint;

		// --- Broken active plugins (file missing) --------------------------------
		$active_plugins  = (array) get_option( 'active_plugins', array() );
		$failed_plugins  = array();
		foreach ( $active_plugins as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! file_exists( $plugin_path ) ) {
				$failed_plugins[] = sanitize_text_field( $plugin_file );
			}
		}

		// --- Missing WP-Claw DB tables -------------------------------------------
		$expected_tables = array(
			$wpdb->prefix . 'claw_tasks',
			$wpdb->prefix . 'claw_proposals',
			$wpdb->prefix . 'claw_analytics',
			$wpdb->prefix . 'claw_command_log',
			$wpdb->prefix . 'claw_file_hashes',
			$wpdb->prefix . 'claw_ab_tests',
			$wpdb->prefix . 'claw_abandoned_carts',
			$wpdb->prefix . 'claw_email_drafts',
			$wpdb->prefix . 'claw_cwv_history',
			$wpdb->prefix . 'claw_snapshots',
		);

		$db_tables_missing = array();
		foreach ( $expected_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( null === $exists ) {
				$db_tables_missing[] = $table;
			}
		}

		// --- Disk free percentage ------------------------------------------------
		$disk_free_pct = null;
		$abspath_dir   = ABSPATH;
		$disk_total    = @disk_total_space( $abspath_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$disk_free     = @disk_free_space( $abspath_dir );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $disk_total && $disk_total > 0 && false !== $disk_free ) {
			$disk_free_pct = round( ( $disk_free / $disk_total ) * 100, 1 );
		}

		return array(
			'wp_cron_overdue_count' => $overdue_count,
			'autoload_bloat'        => $autoload_bytes > 800000,
			'failed_plugins'        => $failed_plugins,
			'db_tables_missing'     => $db_tables_missing,
			'disk_free_pct'         => $disk_free_pct,
		);
	}

	/**
	 * Build an array of actionable recommendation strings from triage data.
	 *
	 * Each recommendation is a plain-English sentence suitable for display
	 * in the admin dashboard or inclusion in the daily digest email.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string,mixed> $signals Output of get_site_signals().
	 * @param array<string,mixed> $health  Output of get_site_health().
	 *
	 * @return string[] Recommendation strings (empty array = no issues found).
	 */
	private function build_recommendations( array $signals, array $health ): array {
		$recommendations = array();

		if ( ! $signals['has_object_cache'] ) {
			$recommendations[] = __( 'No persistent object cache detected. Install a Redis or Memcached plugin to reduce database load.', 'claw-agent' );
		}

		if ( ! $signals['has_page_cache'] ) {
			$recommendations[] = __( 'Page caching is not enabled (WP_CACHE is false). Enable a full-page cache to improve response times.', 'claw-agent' );
		}

		if ( $signals['debug_mode'] ) {
			$recommendations[] = __( 'WP_DEBUG is enabled on a live site. Disable it in wp-config.php to avoid exposing errors to visitors.', 'claw-agent' );
		}

		if ( ! $signals['ssl_active'] ) {
			$recommendations[] = __( 'SSL is not active. Install an SSL certificate and force HTTPS to protect visitor data.', 'claw-agent' );
		}

		if ( $health['autoload_bloat'] ) {
			$recommendations[] = __( 'Autoloaded options exceed 800 KB. Run a database cleanup to reduce page-load overhead.', 'claw-agent' );
		}

		if ( $health['wp_cron_overdue_count'] > 5 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of overdue cron hooks. */
				__( '%d WP-Cron hooks are overdue. Verify WP-Cron is running correctly or switch to a server-side cron.', 'claw-agent' ),
				(int) $health['wp_cron_overdue_count']
			);
		}

		if ( ! empty( $health['db_tables_missing'] ) ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of missing database tables. */
				__( '%d WP-Claw database tables are missing. Deactivate and reactivate the plugin to recreate them.', 'claw-agent' ),
				count( $health['db_tables_missing'] )
			);
		}

		return $recommendations;
	}

	// -------------------------------------------------------------------------
	// Module cron handler
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a scheduled task to the agent responsible for a given module.
	 *
	 * Skips silently if the module is not enabled, not available (e.g.
	 * WooCommerce is deactivated), or the Klawty connection is down.
	 *
	 * @since 1.0.0
	 *
	 * @param string $module_slug The module slug (e.g. 'seo', 'security', 'backup').
	 *
	 * @return void
	 */
	public function run_module_cron( string $module_slug ): void {
		$module_slug = sanitize_key( $module_slug );

		$enabled_modules = (array) get_option( 'wp_claw_enabled_modules', array() );

		if ( ! in_array( $module_slug, $enabled_modules, true ) ) {
			wp_claw_log_debug(
				'Skipping cron for disabled module.',
				array( 'module' => $module_slug )
			);
			return;
		}

		// Retrieve the live module instance from the main plugin class.
		$plugin = WP_Claw::get_instance();
		$module = $plugin->get_module( $module_slug );

		if ( null === $module ) {
			wp_claw_log_warning(
				'Module not found for cron dispatch — skipping.',
				array( 'module' => $module_slug )
			);
			return;
		}

		$task_data = array(
			'agent'  => $module->get_agent(),
			'title'  => sprintf(
				/* translators: %s: Human-readable module name. */
				__( 'Scheduled %s run', 'claw-agent' ),
				$module->get_name()
			),
			'module' => $module_slug,
			'source' => 'cron',
		);

		$result = $this->api_client->create_task( $task_data );

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Failed to dispatch scheduled module task.',
				array(
					'module'  => $module_slug,
					'agent'   => $module->get_agent(),
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		wp_claw_log_debug(
			'Scheduled module task dispatched.',
			array(
				'module'  => $module_slug,
				'task_id' => $result['id'] ?? 'unknown',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Analytics cleanup handler
	// -------------------------------------------------------------------------

	/**
	 * Daily: delete analytics rows older than the retention period.
	 *
	 * The default retention window is 90 days and can be overridden via
	 * the wp_claw_analytics_retention_days filter. Old rows are hard-deleted
	 * to comply with GDPR data minimisation requirements.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run_analytics_cleanup(): void {
		global $wpdb;

		/**
		 * Number of days to retain analytics rows.
		 *
		 * @since 1.0.0
		 *
		 * @param int $days Default 90.
		 */
		$retention_days = (int) apply_filters( 'wp_claw_analytics_retention_days', 90 );
		$retention_days = max( 1, $retention_days ); // Never allow 0 or negative.

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wp_claw_analytics WHERE created_at < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $deleted ) {
			wp_claw_log_error(
				'Analytics cleanup query failed.',
				array( 'last_error' => $wpdb->last_error )
			);
			return;
		}

		wp_claw_log(
			'Analytics cleanup completed.',
			'info',
			array(
				'rows_deleted'   => $deleted,
				'retention_days' => $retention_days,
				'cutoff'         => $cutoff,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Vision capability cron handlers
	// -------------------------------------------------------------------------

	/**
	 * Hourly: compare WordPress core/plugin/theme file hashes against originals.
	 *
	 * Detects modified, new, or deleted files that may indicate a compromise.
	 * Creates a task for the sentinel agent when changes are found.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_file_integrity(): void {
		$results = wp_claw_compare_file_hashes( 'all' );

		$modified  = isset( $results['modified'] ) ? (array) $results['modified'] : array();
		$new_files = isset( $results['new'] ) ? (array) $results['new'] : array();
		$deleted   = isset( $results['deleted'] ) ? (array) $results['deleted'] : array();

		if ( empty( $modified ) && empty( $new_files ) && empty( $deleted ) ) {
			wp_claw_log_debug( 'File integrity check passed — no changes detected.' );
			return;
		}

		$summary = array(
			'modified' => count( $modified ),
			'new'      => count( $new_files ),
			'deleted'  => count( $deleted ),
			'files'    => array_merge(
				array_slice( $modified, 0, 10 ),
				array_slice( $new_files, 0, 10 ),
				array_slice( $deleted, 0, 10 )
			),
		);

		$result = $this->api_client->create_task(
			array(
				'agent'  => 'sentinel',
				'title'  => __( 'File integrity changes detected', 'claw-agent' ),
				'module' => 'security',
				'source' => 'cron',
				'data'   => $summary,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Failed to create file integrity task.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		wp_claw_log_debug(
			'File integrity task created.',
			array( 'task_id' => $result['id'] ?? 'unknown' )
		);
	}

	/**
	 * Daily: scan plugin and theme directories for known malware patterns.
	 *
	 * Creates a task for the sentinel agent when suspicious files are found.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_malware_scan(): void {
		$plugin_matches = wp_claw_scan_directory_for_malware( WP_PLUGIN_DIR, 5000 );
		$theme_matches  = wp_claw_scan_directory_for_malware( get_theme_root(), 2000 );

		$all_matches = array_merge(
			is_array( $plugin_matches ) ? $plugin_matches : array(),
			is_array( $theme_matches ) ? $theme_matches : array()
		);

		if ( empty( $all_matches ) ) {
			wp_claw_log_debug( 'Malware scan completed — no threats detected.' );
			return;
		}

		$result = $this->api_client->create_task(
			array(
				'agent'  => 'sentinel',
				'title'  => __( 'Malware scan: suspicious files detected', 'claw-agent' ),
				'module' => 'security',
				'source' => 'cron',
				'data'   => array(
					'infected_count' => count( $all_matches ),
					'files'          => array_slice( $all_matches, 0, 20 ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Failed to create malware scan task.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		wp_claw_log_debug(
			'Malware scan task created.',
			array( 'task_id' => $result['id'] ?? 'unknown' )
		);
	}

	/**
	 * Daily: check SSL certificate expiry and alert when nearing renewal.
	 *
	 * Creates a task for the sentinel agent when the certificate expires
	 * within 30 days.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_ssl_check(): void {
		$plugin = WP_Claw::get_instance();
		$module = $plugin->get_module( 'audit' );

		if ( null === $module ) {
			wp_claw_log_debug( 'SSL check skipped — audit module not available.' );
			return;
		}

		$ssl_info = $module->handle_action( 'get_ssl_info', array() );

		if ( is_wp_error( $ssl_info ) ) {
			wp_claw_log_warning(
				'SSL check failed.',
				array(
					'code'    => $ssl_info->get_error_code(),
					'message' => $ssl_info->get_error_message(),
				)
			);
			return;
		}

		$days_remaining = isset( $ssl_info['days_remaining'] ) ? (int) $ssl_info['days_remaining'] : 999;

		// Send real-time email alert if SSL expires within 14 days.
		if ( $days_remaining < 14 && class_exists( '\\WPClaw\\Notifications' ) ) {
			\WPClaw\Notifications::send_alert(
				'ssl_expiring',
				array(
					'agent' => 'sentinel',
					'days'  => $days_remaining,
				)
			);
		}

		if ( $days_remaining >= 30 ) {
			wp_claw_log_debug(
				'SSL certificate OK.',
				array( 'days_remaining' => $days_remaining )
			);
			return;
		}

		$result = $this->api_client->create_task(
			array(
				'agent'  => 'sentinel',
				'title'  => sprintf(
					/* translators: %d: number of days until SSL certificate expires. */
					__( 'SSL certificate expires in %d days', 'claw-agent' ),
					$days_remaining
				),
				'module' => 'security',
				'source' => 'cron',
				'data'   => $ssl_info,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Failed to create SSL expiry task.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		wp_claw_log_debug(
			'SSL expiry task created.',
			array( 'task_id' => $result['id'] ?? 'unknown' )
		);
	}

	/**
	 * Hourly: detect and flag abandoned WooCommerce carts.
	 *
	 * Carts older than 2 hours with status 'active' are marked as 'abandoned'
	 * and a recovery task is created for the commerce agent.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_abandoned_cart_check(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_abandoned_carts';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$abandoned = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)",
				$table,
				'active'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $abandoned ) ) {
			wp_claw_log_debug( 'No abandoned carts found.' );
			return;
		}

		$cart_ids = array();
		foreach ( $abandoned as $cart ) {
			$cart_ids[] = (int) $cart->id;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'status' => 'abandoned' ),
				array( 'id' => (int) $cart->id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$result = $this->api_client->create_task(
			array(
				'agent'  => 'commerce',
				'title'  => sprintf(
					/* translators: %d: number of abandoned carts. */
					__( '%d abandoned carts detected', 'claw-agent' ),
					count( $cart_ids )
				),
				'module' => 'commerce',
				'source' => 'cron',
				'data'   => array( 'cart_ids' => $cart_ids ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Failed to create abandoned cart task.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		wp_claw_log_debug(
			'Abandoned cart task created.',
			array(
				'cart_count' => count( $cart_ids ),
				'task_id'    => $result['id'] ?? 'unknown',
			)
		);
	}

	/**
	 * Daily: check running A/B tests for statistical significance.
	 *
	 * Uses a chi-squared test (p < 0.05, threshold 3.84) to determine
	 * winners. Completed tests are marked with the winning variant.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_ab_test_eval(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_ab_tests';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$tests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = %s",
				$table,
				'running'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $tests ) ) {
			wp_claw_log_debug( 'No running A/B tests to check.' );
			return;
		}

		$completed = array();

		foreach ( $tests as $test ) {
			$ia = (int) $test->impressions_a;
			$ib = (int) $test->impressions_b;
			$ca = (int) $test->clicks_a;
			$cb = (int) $test->clicks_b;

			// Require minimum sample size.
			if ( $ia < 500 || $ib < 500 ) {
				continue;
			}

			// Avoid division by zero.
			$total_clicks      = $ca + $cb;
			$total_impressions = $ia + $ib;
			$non_clicks        = $total_impressions - $total_clicks;

			if ( 0 === $total_clicks || 0 === $non_clicks || 0 === $ia || 0 === $ib ) {
				continue;
			}

			// Chi-squared test for independence.
			$cross        = ( $ca * $ib ) - ( $cb * $ia );
			$numerator    = $cross * $cross * $total_impressions;
			$denominator  = $ia * $ib * $total_clicks * $non_clicks;
			$chi_squared  = $numerator / $denominator;

			if ( $chi_squared <= 3.84 ) {
				continue;
			}

			// Determine winner by higher CTR.
			$ctr_a  = $ca / $ia;
			$ctr_b  = $cb / $ib;
			$winner = $ctr_a >= $ctr_b ? 'a' : 'b';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'winner'   => $winner,
					'status'   => 'completed',
					'ended_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $test->id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			$completed[] = array(
				'test_id'     => (int) $test->id,
				'winner'      => $winner,
				'chi_squared' => round( $chi_squared, 2 ),
				'ctr_a'       => round( $ctr_a, 4 ),
				'ctr_b'       => round( $ctr_b, 4 ),
			);
		}

		if ( empty( $completed ) ) {
			wp_claw_log_debug( 'A/B test check complete — no tests reached significance.' );
			return;
		}

		$result = $this->api_client->create_task(
			array(
				'agent'  => 'scribe',
				'title'  => sprintf(
					/* translators: %d: number of completed A/B tests. */
					__( '%d A/B tests reached statistical significance', 'claw-agent' ),
					count( $completed )
				),
				'module' => 'analytics',
				'source' => 'cron',
				'data'   => array( 'completed_tests' => $completed ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Failed to create A/B test results task.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		wp_claw_log_debug(
			'A/B test results task created.',
			array( 'task_id' => $result['id'] ?? 'unknown' )
		);
	}

	/**
	 * Weekly: delete Core Web Vitals history older than 90 days.
	 *
	 * Keeps the CWV history table lean for GDPR compliance and performance.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_cwv_cleanup(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_cwv_history';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE measured_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
				$table
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $deleted ) {
			wp_claw_log_warning(
				'CWV cleanup query failed.',
				array( 'last_error' => $wpdb->last_error )
			);
			return;
		}

		wp_claw_log_debug(
			'CWV history cleanup completed.',
			array( 'rows_deleted' => $deleted )
		);
	}

	/**
	 * Weekly: compute customer RFM segmentation from WooCommerce orders.
	 *
	 * Stores aggregate segment counts (not per-customer data) in a WordPress
	 * option for dashboard display and commerce agent context.
	 *
	 * Segments:
	 *   - new:     1 order, last order < 30 days ago
	 *   - active:  2-3 orders, last order < 90 days
	 *   - loyal:   4+ orders, last order < 90 days
	 *   - at_risk: any orders, last order 90-180 days ago
	 *   - dormant: last order > 180 days ago
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function run_segmentation(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$customers = $wpdb->get_results(
			"SELECT
				pm.meta_value AS customer_id,
				COUNT(*) AS order_count,
				MAX(p.post_date) AS last_order_date,
				SUM(pm2.meta_value) AS total_spend
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
			WHERE p.post_type = 'shop_order'
			AND p.post_status IN ('wc-completed', 'wc-processing')
			AND pm.meta_value > 0
			GROUP BY pm.meta_value"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$segments = array(
			'new'     => 0,
			'active'  => 0,
			'loyal'   => 0,
			'at_risk' => 0,
			'dormant' => 0,
		);

		$now = time();

		foreach ( $customers as $customer ) {
			$order_count = (int) $customer->order_count;
			$last_order  = strtotime( $customer->last_order_date );
			$days_since  = (int) floor( ( $now - $last_order ) / DAY_IN_SECONDS );

			if ( $days_since > 180 ) {
				++$segments['dormant'];
			} elseif ( $days_since >= 90 ) {
				++$segments['at_risk'];
			} elseif ( $order_count >= 4 ) {
				++$segments['loyal'];
			} elseif ( $order_count >= 2 ) {
				++$segments['active'];
			} elseif ( $days_since < 30 ) {
				++$segments['new'];
			} else {
				++$segments['active'];
			}
		}

		update_option( 'wp_claw_customer_segments', $segments, false );

		wp_claw_log_debug(
			'Customer segmentation completed.',
			array( 'segments' => $segments )
		);
	}

	// -------------------------------------------------------------------------
	// State snapshot helpers (used by run_sync_state)
	// -------------------------------------------------------------------------

	/**
	 * Get a de-versioned list of active plugin slugs.
	 *
	 * Returns only the top-level directory slug (e.g. 'woocommerce' from
	 * 'woocommerce/woocommerce.php') to keep the payload small.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Array of plugin slugs.
	 */
	private function get_active_plugin_slugs(): array {
		$active = (array) get_option( 'active_plugins', array() );
		$slugs  = array();

		foreach ( $active as $plugin_file ) {
			$parts = explode( '/', (string) $plugin_file );
			if ( ! empty( $parts[0] ) ) {
				$slugs[] = sanitize_key( $parts[0] );
			}
		}

		return $slugs;
	}

	/**
	 * Get published post counts per post type.
	 *
	 * Only returns counts for post types that have at least one published post,
	 * to keep the state payload small.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, int> Map of post_type => count.
	 */
	private function get_post_counts(): array {
		$counts     = array();
		$post_types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $post_types as $type ) {
			$count = wp_count_posts( $type );
			if ( isset( $count->publish ) && $count->publish > 0 ) {
				$counts[ $type ] = (int) $count->publish;
			}
		}

		return $counts;
	}

	/**
	 * Get a brief WooCommerce state summary if WooCommerce is active.
	 *
	 * @since 1.0.0
	 *
	 * @return array WooCommerce state data, or empty array if not active.
	 */
	private function get_woocommerce_state(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		return array(
			'active'        => true,
			'version'       => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			'currency'      => get_woocommerce_currency(),
			'product_count' => (int) wp_count_posts( 'product' )->publish,
		);
	}

	/**
	 * Count proposals currently pending human approval.
	 *
	 * Reads from the wp_claw_proposals custom table created on activation.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of pending proposals.
	 */
	private function get_pending_proposal_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$wpdb->prefix . 'wp_claw_proposals',
				'pending'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}
}
