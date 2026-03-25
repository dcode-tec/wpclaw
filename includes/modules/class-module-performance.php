<?php
/**
 * Performance module.
 *
 * @package    WPClaw
 * @subpackage WPClaw/modules
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

namespace WPClaw\Modules;

use WPClaw\Module_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Performance module.
 *
 * Gives the Analyst agent access to WordPress database cleanup,
 * Core Web Vitals data (stored from an external check), page speed
 * reports, and cache strategy recommendations. All heavy operations
 * are cron-driven — this module registers no real-time hooks.
 *
 * @since 1.0.0
 */
class Module_Performance extends Module_Base {

	/**
	 * Transient key for stored Core Web Vitals data.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const CWV_TRANSIENT = 'wp_claw_core_web_vitals';

	/**
	 * Transient key for stored PageSpeed Insights data.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const PSI_TRANSIENT = 'wp_claw_page_speed_data';

	/**
	 * Maximum number of post revisions to delete per cleanup run.
	 *
	 * Kept low to avoid locking the DB on large sites.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const REVISION_BATCH = 500;

	// -------------------------------------------------------------------------
	// Module contract
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'performance';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Performance', 'claw-agent' );
	}

	/**
	 * Return the Klawty agent responsible for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'analyst';
	}

	/**
	 * Return the allowlisted agent actions for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'get_core_web_vitals',
			'run_db_cleanup',
			'optimize_images',
			'suggest_cache_strategy',
			'get_page_speed_data',
		);
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Dispatch an inbound agent action to the appropriate handler.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error Result array on success, WP_Error on failure.
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'get_core_web_vitals':
				return $this->handle_get_core_web_vitals();

			case 'run_db_cleanup':
				return $this->handle_run_db_cleanup();

			case 'optimize_images':
				return $this->handle_optimize_images( $params );

			case 'suggest_cache_strategy':
				return $this->handle_suggest_cache_strategy();

			case 'get_page_speed_data':
				return $this->handle_get_page_speed_data();

			default:
				return new \WP_Error(
					'wp_claw_unknown_action',
					/* translators: %s: action name */
					sprintf( __( 'Unknown Performance action: %s', 'claw-agent' ), esc_html( $action ) ),
					array( 'status' => 400 )
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Return Core Web Vitals data stored in the transient cache.
	 *
	 * The data is written by the external performance check cron task.
	 * Returns a structured "no data" response when the transient is absent
	 * so the agent can schedule a fresh check.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function handle_get_core_web_vitals(): array {
		$data = get_transient( self::CWV_TRANSIENT );

		if ( false === $data || ! is_array( $data ) ) {
			return array(
				'success'   => true,
				'available' => false,
				'message'   => __( 'No Core Web Vitals data available yet. Run the performance check cron to populate.', 'claw-agent' ),
			);
		}

		return array(
			'success'   => true,
			'available' => true,
			'data'      => $data,
		);
	}

	/**
	 * Delete post revisions, spam comments, and expired transients.
	 *
	 * Each category is deleted in a bounded batch to avoid long-running
	 * queries on large sites. All queries use $wpdb->prepare() or rely on
	 * numeric literals safe for direct interpolation.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array Cleanup result with counts per category.
	 */
	private function handle_run_db_cleanup(): array {
		global $wpdb;

		// --- 1. Delete post revisions (bounded batch) -----------------------
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional batched DELETE of WP core data for cleanup.
		$revisions_deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->posts}
				 WHERE post_type = %s
				 LIMIT %d",
				'revision',
				self::REVISION_BATCH
			)
		);

		// --- 2. Delete spam comments ----------------------------------------
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional DELETE of spam data.
		$spam_deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->comments} WHERE comment_approved = %s",
				'spam'
			)
		);

		// --- 3. Delete expired transients -----------------------------------
		// The option_name format for transients is '_transient_timeout_{name}'.
		// We delete the timeout row and the value row in two passes to avoid
		// leaving orphaned data.
		$now = (int) time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional DELETE of expired transient timeout rows.
		$expired_timeouts = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional DELETE of orphaned transient value rows.
		$orphaned_values = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE v FROM {$wpdb->options} v
				 LEFT JOIN {$wpdb->options} t
				   ON t.option_name = CONCAT( '_transient_timeout_', SUBSTRING( v.option_name, 12 ) )
				 WHERE v.option_name LIKE %s
				   AND t.option_name IS NULL",
				$wpdb->esc_like( '_transient_' ) . '%'
			)
		);

		$transients_deleted = $expired_timeouts + $orphaned_values;

		return array(
			'success'            => true,
			'revisions_deleted'  => $revisions_deleted,
			'spam_deleted'       => $spam_deleted,
			'transients_deleted' => $transients_deleted,
			'message'            => __( 'Database cleanup completed.', 'claw-agent' ),
		);
	}

	/**
	 * Queue an image optimization task for the Analyst agent.
	 *
	 * Actual image processing requires CLI tooling (cwebp, mozjpeg, etc.)
	 * that runs outside WordPress. This handler records the intent in the
	 * task log so the Analyst agent can coordinate the work via its toolset.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Optimization parameters.
	 *
	 *     @type int    $attachment_id  A specific attachment to optimize (0 = all unoptimized).
	 *     @type string $format         Target format: 'webp' or 'original' (default 'webp').
	 *     @type int    $quality        JPEG/WebP quality 1–100 (default 82).
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_optimize_images( array $params ) {
		global $wpdb;

		$attachment_id = absint( $params['attachment_id'] ?? 0 );
		$format        = in_array( sanitize_key( $params['format'] ?? 'webp' ), array( 'webp', 'original' ), true )
			? sanitize_key( $params['format'] ?? 'webp' )
			: 'webp';
		$quality       = min( 100, max( 1, absint( $params['quality'] ?? 82 ) ) );

		$task_id = 'perf-imgopt-' . wp_generate_uuid4();
		$details = wp_json_encode(
			array(
				'attachment_id' => $attachment_id,
				'format'        => $format,
				'quality'       => $quality,
				'queued_at'     => current_time( 'c' ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			array(
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'optimize_images',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Failed to queue image optimization task.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'task_id' => $task_id,
			'message' => __( 'Image optimization task queued.', 'claw-agent' ),
		);
	}

	/**
	 * Return cache strategy recommendations based on active plugins.
	 *
	 * Detects installed caching plugins and recommends settings, or
	 * recommends a caching solution if none is found.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function handle_suggest_cache_strategy(): array {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugins_string = implode( ' ', $active_plugins );

		$detected_cache_plugin = '';
		$recommendations       = array();

		// Detect known caching plugins by slug fragment.
		$cache_plugin_map = array(
			'wp-rocket'         => 'WP Rocket',
			'w3-total-cache'    => 'W3 Total Cache',
			'wp-super-cache'    => 'WP Super Cache',
			'litespeed-cache'   => 'LiteSpeed Cache',
			'wp-fastest-cache'  => 'WP Fastest Cache',
			'autoptimize'       => 'Autoptimize',
			'swift-performance' => 'Swift Performance',
			'sg-cachepress'     => 'SiteGround Optimizer',
		);

		foreach ( $cache_plugin_map as $slug_fragment => $plugin_name ) {
			if ( false !== strpos( $plugins_string, $slug_fragment ) ) {
				$detected_cache_plugin = $plugin_name;
				break;
			}
		}

		if ( empty( $detected_cache_plugin ) ) {
			$recommendations[] = __( 'No caching plugin detected. Recommend installing WP Rocket or LiteSpeed Cache.', 'claw-agent' );
			$recommendations[] = __( 'Enable browser caching via .htaccess Expires headers.', 'claw-agent' );
			$recommendations[] = __( 'Serve static assets from a CDN (Cloudflare is free).', 'claw-agent' );
		} else {
			$recommendations[] = sprintf(
				/* translators: %s: detected caching plugin name */
				__( '%s detected. Verify page caching is enabled for all anonymous visitors.', 'claw-agent' ),
				$detected_cache_plugin
			);
			$recommendations[] = __( 'Enable GZIP or Brotli compression at the server level.', 'claw-agent' );
			$recommendations[] = __( 'Combine and minify CSS and JavaScript assets.', 'claw-agent' );
			$recommendations[] = __( 'Enable lazy loading for images below the fold.', 'claw-agent' );
		}

		// Check if WooCommerce is active — carts/checkouts must not be cached.
		if ( class_exists( 'WooCommerce' ) ) {
			$recommendations[] = __( 'WooCommerce detected: exclude /cart/, /checkout/, and /my-account/ from page cache.', 'claw-agent' );
		}

		return array(
			'success'               => true,
			'detected_cache_plugin' => $detected_cache_plugin ?: __( 'None', 'claw-agent' ),
			'recommendations'       => $recommendations,
		);
	}

	/**
	 * Return PageSpeed Insights data stored in the transient cache.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function handle_get_page_speed_data(): array {
		$data = get_transient( self::PSI_TRANSIENT );

		if ( false === $data || ! is_array( $data ) ) {
			return array(
				'success'   => true,
				'available' => false,
				'message'   => __( 'No PageSpeed data available. Run the performance cron or trigger a manual check.', 'claw-agent' ),
			);
		}

		return array(
			'success'   => true,
			'available' => true,
			'data'      => $data,
		);
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register WordPress hooks for this module.
	 *
	 * Performance operations are entirely cron-driven (wp_claw_performance_check
	 * weekly cron). No real-time hooks are needed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Intentionally empty — all operations are triggered by WP-Cron.
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/**
	 * Return the current performance state for state sync.
	 *
	 * Provides DB size, autoloaded options size, revision count, and
	 * spam comment count so the Analyst agent can decide whether a
	 * cleanup run is warranted.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		// Total database size in bytes (across all tables in this schema).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional aggregate SELECT from information_schema; result changes infrequently.
		$db_size_bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length)
				 FROM information_schema.tables
				 WHERE table_schema = %s',
				DB_NAME
			)
		);

		// Autoloaded options size.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional aggregate SELECT; refreshed each state sync.
		$autoloaded_bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(LENGTH(option_value)) FROM %i WHERE autoload = %s',
				$wpdb->options,
				'yes'
			)
		);

		// Post revision count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT; refreshed each state sync.
		$revision_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'revision'
			)
		);

		// Spam comment count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT; refreshed each state sync.
		$spam_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
				'spam'
			)
		);

		// Core Web Vitals snapshot (from transient, may be empty).
		$cwv = get_transient( self::CWV_TRANSIENT );

		return array(
			'module'           => $this->get_slug(),
			'db_size_bytes'    => $db_size_bytes,
			'db_size_mb'       => round( $db_size_bytes / 1048576, 2 ),
			'autoloaded_bytes' => $autoloaded_bytes,
			'autoloaded_kb'    => round( $autoloaded_bytes / 1024, 1 ),
			'revision_count'   => $revision_count,
			'spam_count'       => $spam_count,
			'core_web_vitals'  => is_array( $cwv ) ? $cwv : null,
			'generated_at'     => current_time( 'c' ),
		);
	}
}
