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
	 * Transient key for update check data (12-hour TTL).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const TRANSIENT_UPDATE_DATA = 'wp_claw_update_data';

	/**
	 * Update data transient TTL in seconds (12 hours).
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const UPDATE_TTL = 43200;

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
		add_action( 'wp_claw_update_check', array( $this, 'run_update_check' ) );

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
			return;
		}

		wp_claw_log_debug( 'Scheduled health check completed.', array( 'status' => $result['status'] ?? 'unknown' ) );
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

	/**
	 * Twice-daily: check the Klawty API for available plugin updates.
	 *
	 * The update data is cached in a transient and consumed by the
	 * update checker integration (class-wp-claw.php hooks into
	 * plugins_api and pre_set_site_transient_update_plugins).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run_update_check(): void {
		$result = $this->api_client->check_for_updates();

		if ( is_wp_error( $result ) ) {
			wp_claw_log_warning(
				'Update check failed.',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
			return;
		}

		set_transient( self::TRANSIENT_UPDATE_DATA, $result, self::UPDATE_TTL );

		wp_claw_log_debug(
			'Update check completed.',
			array(
				'latest_version'   => $result['latest_version'] ?? 'unknown',
				'update_available' => ! empty( $result['update_available'] ),
			)
		);
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
				__( 'Scheduled %s run', 'wp-claw' ),
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
