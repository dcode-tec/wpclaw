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
			'optimize_tables',
			'get_autoload_analysis',
			'store_pagespeed_data',
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

			case 'optimize_tables':
				return $this->handle_optimize_tables( $params );

			case 'get_autoload_analysis':
				return $this->handle_get_autoload_analysis( $params );

			case 'store_pagespeed_data':
				return $this->handle_store_pagespeed_data( $params );

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

	/**
	 * Optimize fragmented database tables.
	 *
	 * Queries SHOW TABLE STATUS to find tables with significant fragmentation
	 * (Data_free / Data_length > 20%). Runs OPTIMIZE TABLE on qualifying
	 * tables. InnoDB tables are logged but skipped since OPTIMIZE is a no-op
	 * for most InnoDB configurations.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters. Currently unused.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array
	 */
	private function handle_optimize_tables( array $params ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional SHOW TABLE STATUS for maintenance analysis.
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $tables ) ) {
			return array(
				'success' => true,
				'message' => __( 'No tables found to optimize.', 'claw-agent' ),
				'results' => array(),
			);
		}

		$results = array();

		foreach ( $tables as $table ) {
			$name        = sanitize_text_field( $table['Name'] );
			$engine      = isset( $table['Engine'] ) ? sanitize_text_field( $table['Engine'] ) : '';
			$data_length = isset( $table['Data_length'] ) ? (int) $table['Data_length'] : 0;
			$data_free   = isset( $table['Data_free'] ) ? (int) $table['Data_free'] : 0;

			// Calculate fragmentation ratio.
			$denominator      = $data_length + 1;
			$fragmentation    = $data_free / $denominator;

			if ( $fragmentation <= 0.2 ) {
				continue;
			}

			// Skip InnoDB — OPTIMIZE is largely a no-op.
			if ( 'InnoDB' === $engine ) {
				$results[] = array(
					'table'         => $name,
					'engine'        => $engine,
					'fragmentation' => round( $fragmentation * 100, 1 ),
					'skipped'       => true,
					'reason'        => __( 'InnoDB: OPTIMIZE TABLE is a no-op for this engine.', 'claw-agent' ),
				);
				continue;
			}

			$before_free = $data_free;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- intentional OPTIMIZE TABLE for maintenance.
			$wpdb->query(
				$wpdb->prepare( 'OPTIMIZE TABLE %i', $name )
			);

			// Re-check after optimize.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- re-read after optimize.
			$after = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT Data_free FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
					DB_NAME,
					$name
				),
				ARRAY_A
			);

			$after_free = $after ? (int) $after['Data_free'] : 0;

			$results[] = array(
				'table'         => $name,
				'engine'        => $engine,
				'fragmentation' => round( $fragmentation * 100, 1 ),
				'before_free'   => $before_free,
				'after_free'    => $after_free,
				'reclaimed'     => max( 0, $before_free - $after_free ),
				'skipped'       => false,
			);
		}

		return array(
			'success'         => true,
			'tables_analyzed' => count( $tables ),
			'tables_optimized' => count( array_filter( $results, static function ( $r ) { return ! $r['skipped']; } ) ),
			'results'         => $results,
			'message'         => __( 'Table optimization completed.', 'claw-agent' ),
		);
	}

	/**
	 * Analyze autoloaded options for bloat.
	 *
	 * Returns the total size of autoloaded options and the top 10 largest
	 * entries by byte size. Helps agents identify plugins or themes that
	 * store excessive data in autoloaded options.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters. Currently unused.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array
	 */
	private function handle_get_autoload_analysis( array $params ): array {
		global $wpdb;

		// Total autoloaded size.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional aggregate for performance analysis.
		$total_bytes = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(LENGTH(option_value)) FROM %i WHERE autoload = %s',
				$wpdb->options,
				'yes'
			)
		);

		// Top 10 largest autoloaded options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional analysis query; not cacheable.
		$top_10 = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, LENGTH(option_value) as size FROM %i WHERE autoload = %s ORDER BY size DESC LIMIT 10',
				$wpdb->options,
				'yes'
			),
			ARRAY_A
		);

		$top_entries = array();
		if ( is_array( $top_10 ) ) {
			foreach ( $top_10 as $row ) {
				$top_entries[] = array(
					'option_name' => sanitize_text_field( $row['option_name'] ),
					'size_bytes'  => (int) $row['size'],
				);
			}
		}

		return array(
			'success'     => true,
			'total_bytes' => $total_bytes,
			'total_kb'    => round( $total_bytes / 1024, 1 ),
			'top_10'      => $top_entries,
		);
	}

	/**
	 * Store PageSpeed data for a specific page URL.
	 *
	 * Stores scores (performance, accessibility, best_practices, seo) as a
	 * transient with a 7-day TTL. The transient key is derived from an MD5
	 * hash of the URL for uniqueness.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *   @type string $page_url Required. The URL that was measured.
	 *   @type array  $scores   Required. Object with performance, accessibility, best_practices, seo (each 0-100).
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_store_pagespeed_data( array $params ) {
		$page_url = isset( $params['page_url'] ) ? esc_url_raw( wp_unslash( (string) $params['page_url'] ) ) : '';
		if ( '' === $page_url ) {
			return new \WP_Error(
				'wp_claw_missing_page_url',
				__( 'page_url parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		if ( ! isset( $params['scores'] ) || ! is_array( $params['scores'] ) ) {
			return new \WP_Error(
				'wp_claw_missing_scores',
				__( 'scores parameter is required and must be an object.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$scores = array(
			'performance'    => min( 100, max( 0, absint( $params['scores']['performance'] ?? 0 ) ) ),
			'accessibility'  => min( 100, max( 0, absint( $params['scores']['accessibility'] ?? 0 ) ) ),
			'best_practices' => min( 100, max( 0, absint( $params['scores']['best_practices'] ?? 0 ) ) ),
			'seo'            => min( 100, max( 0, absint( $params['scores']['seo'] ?? 0 ) ) ),
		);

		$transient_key = 'wp_claw_pagespeed_' . md5( $page_url );
		$data          = array(
			'page_url'   => $page_url,
			'scores'     => $scores,
			'checked_at' => current_time( 'c' ),
		);

		set_transient( $transient_key, $data, 7 * DAY_IN_SECONDS );

		return array(
			'success'  => true,
			'page_url' => $page_url,
			'scores'   => $scores,
			'message'  => __( 'PageSpeed data stored successfully.', 'claw-agent' ),
		);
	}

	// -------------------------------------------------------------------------
	// Diagnostic checks (used by run_diagnostics)
	// -------------------------------------------------------------------------

	/**
	 * Check autoloaded options for size bloat.
	 *
	 * Queries the wp_options table for total autoloaded size and the top 10
	 * offenders. Fires a warning when total autoloaded bytes exceeds 800 KB.
	 *
	 * @since 1.4.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array {
	 *     @type string $id           Check identifier 'autoload_bloat'.
	 *     @type string $status       'pass' or 'warning'.
	 *     @type string $value        Human-readable total autoloaded size.
	 *     @type int    $value_bytes  Total autoloaded bytes.
	 *     @type string $threshold    Human-readable threshold (800 KB).
	 *     @type array  $top_offenders Top 10 options by size.
	 * }
	 */
	private function check_autoload_bloat(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional aggregate for diagnostic check.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(LENGTH(option_value)) FROM %i WHERE autoload = %s',
				$wpdb->options,
				'yes'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional TOP-10 query for diagnostic check.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, LENGTH(option_value) AS size FROM %i WHERE autoload = %s ORDER BY size DESC LIMIT 10',
				$wpdb->options,
				'yes'
			),
			ARRAY_A
		);

		$top_offenders = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$top_offenders[] = array(
					'option_name' => sanitize_text_field( $row['option_name'] ),
					'size_bytes'  => (int) $row['size'],
				);
			}
		}

		return array(
			'id'            => 'autoload_bloat',
			'status'        => $total > 800000 ? 'warning' : 'pass',
			'value'         => size_format( $total ),
			'value_bytes'   => $total,
			'threshold'     => size_format( 800000 ),
			'top_offenders' => $top_offenders,
		);
	}

	/**
	 * Check whether a persistent object cache is installed and connected.
	 *
	 * Detects Redis or Memcached by reading the object-cache.php drop-in and
	 * verifying connection via wp_using_ext_object_cache().
	 *
	 * @since 1.4.0
	 *
	 * @return array {
	 *     @type string $id        Check identifier 'object_cache'.
	 *     @type string $status    'pass' or 'fail'.
	 *     @type string $provider  'redis', 'memcached', 'none', or 'unknown'.
	 *     @type bool   $connected Whether the cache is actively connected.
	 * }
	 */
	private function check_object_cache(): array {
		$drop_in = WP_CONTENT_DIR . '/object-cache.php';
		$exists  = file_exists( $drop_in );

		$provider  = 'none';
		$connected = false;

		if ( $exists ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local drop-in file, not a remote URL.
			$contents = (string) file_get_contents( $drop_in );
			if ( false !== stripos( $contents, 'redis' ) ) {
				$provider = 'redis';
			} elseif ( false !== stripos( $contents, 'memcache' ) ) {
				$provider = 'memcached';
			} else {
				$provider = 'unknown';
			}
			$connected = (bool) wp_using_ext_object_cache();
		}

		return array(
			'id'        => 'object_cache',
			'status'    => ( $exists && $connected ) ? 'pass' : 'fail',
			'provider'  => $provider,
			'connected' => $connected,
		);
	}

	/**
	 * Check whether a page caching solution is active.
	 *
	 * Detects the advanced-cache.php drop-in and known page cache plugins.
	 *
	 * @since 1.4.0
	 *
	 * @return array {
	 *     @type string $id      Check identifier 'page_cache'.
	 *     @type string $status  'pass' or 'fail'.
	 *     @type string $plugin  (optional) Detected cache plugin directory name.
	 *     @type string $detail  (optional) Human-readable status detail.
	 * }
	 */
	private function check_page_cache(): array {
		$drop_in = WP_CONTENT_DIR . '/advanced-cache.php';

		if ( file_exists( $drop_in ) ) {
			return array(
				'id'     => 'page_cache',
				'status' => 'pass',
				'detail' => __( 'advanced-cache.php drop-in is present.', 'claw-agent' ),
			);
		}

		// Fall back to checking active plugins for known cache plugin slugs.
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$known_slugs    = array(
			'wp-super-cache',
			'w3-total-cache',
			'wp-fastest-cache',
			'litespeed-cache',
			'wp-rocket',
		);

		foreach ( $active_plugins as $plugin_path ) {
			$dir = dirname( $plugin_path );
			if ( in_array( $dir, $known_slugs, true ) ) {
				return array(
					'id'     => 'page_cache',
					'status' => 'pass',
					'plugin' => $dir,
				);
			}
		}

		return array(
			'id'     => 'page_cache',
			'status' => 'fail',
			'detail' => __( 'No page caching detected.', 'claw-agent' ),
		);
	}

	/**
	 * Count overdue WP-Cron events.
	 *
	 * An event is considered overdue when its scheduled timestamp is in
	 * the past. More than 5 overdue events triggers a warning.
	 *
	 * @since 1.4.0
	 *
	 * @return array {
	 *     @type string $id      Check identifier 'cron_health'.
	 *     @type string $status  'pass' or 'warning'.
	 *     @type int    $overdue Number of overdue events.
	 *     @type int    $total   Total registered cron events.
	 * }
	 */
	private function check_cron_health(): array {
		$cron_array = _get_cron_array();

		if ( ! is_array( $cron_array ) ) {
			return array(
				'id'      => 'cron_health',
				'status'  => 'pass',
				'overdue' => 0,
				'total'   => 0,
			);
		}

		$now     = time();
		$overdue = 0;
		$total   = 0;

		foreach ( $cron_array as $timestamp => $hooks ) {
			$total += count( $hooks );
			if ( (int) $timestamp < $now ) {
				$overdue += count( $hooks );
			}
		}

		return array(
			'id'      => 'cron_health',
			'status'  => $overdue > 5 ? 'warning' : 'pass',
			'overdue' => $overdue,
			'total'   => $total,
		);
	}

	/**
	 * Count database bloat from orphaned and junk rows.
	 *
	 * Checks for: orphaned post meta, expired transients, trashed posts,
	 * spam comments, and post revisions. A warning fires when the combined
	 * waste count exceeds 1,000 rows.
	 *
	 * @since 1.4.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array {
	 *     @type string $id                  Check identifier 'database_bloat'.
	 *     @type string $status              'pass' or 'warning'.
	 *     @type int    $orphaned_meta       Postmeta rows without a parent post.
	 *     @type int    $expired_transients  Expired transient timeout rows.
	 *     @type int    $trashed_posts       Posts in the trash.
	 *     @type int    $spam_comments       Spam comments.
	 *     @type int    $revisions           Post revisions.
	 * }
	 */
	private function check_database_bloat(): array {
		global $wpdb;

		// Orphaned post meta (postmeta rows whose post no longer exists).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT JOIN for diagnostic check.
		$orphaned_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.ID IS NULL"
		);

		// Expired transient timeout rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT for diagnostic check.
		$expired_transients = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		// Trashed posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT for diagnostic check.
		$trashed_posts = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
				'trash'
			)
		);

		// Spam comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT for diagnostic check.
		$spam_comments = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
				'spam'
			)
		);

		// Post revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional COUNT for diagnostic check.
		$revisions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'revision'
			)
		);

		$total_waste = $orphaned_meta + $expired_transients + $trashed_posts + $spam_comments + $revisions;

		return array(
			'id'                 => 'database_bloat',
			'status'             => $total_waste > 1000 ? 'warning' : 'pass',
			'orphaned_meta'      => $orphaned_meta,
			'expired_transients' => $expired_transients,
			'trashed_posts'      => $trashed_posts,
			'spam_comments'      => $spam_comments,
			'revisions'          => $revisions,
		);
	}

	/**
	 * Check whether WP-Claw itself is causing autoload bloat.
	 *
	 * Looks for wp_claw_* options that are both autoloaded and exceed 10 KB.
	 *
	 * @since 1.4.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array {
	 *     @type string $id        Check identifier 'self_audit'.
	 *     @type string $status    'pass' or 'warning'.
	 *     @type string $detail    Human-readable summary.
	 *     @type array  $offenders List of offending options with name and size.
	 * }
	 */
	private function check_autoload_self(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional self-audit query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, LENGTH(option_value) AS size FROM %i WHERE autoload = %s AND option_name LIKE %s AND LENGTH(option_value) > %d ORDER BY size DESC',
				$wpdb->options,
				'yes',
				$wpdb->esc_like( 'wp_claw_' ) . '%',
				10240
			),
			ARRAY_A
		);

		$offenders = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$offenders[] = array(
					'option_name' => sanitize_text_field( $row['option_name'] ),
					'size_bytes'  => (int) $row['size'],
				);
			}
		}

		if ( empty( $offenders ) ) {
			return array(
				'id'        => 'self_audit',
				'status'    => 'pass',
				'detail'    => __( 'No oversized WP-Claw autoloaded options found.', 'claw-agent' ),
				'offenders' => array(),
			);
		}

		return array(
			'id'        => 'self_audit',
			'status'    => 'warning',
			/* translators: %d: number of offending options. */
			'detail'    => sprintf( _n( '%d WP-Claw option is autoloaded and exceeds 10 KB.', '%d WP-Claw options are autoloaded and exceed 10 KB.', count( $offenders ), 'claw-agent' ), count( $offenders ) ),
			'offenders' => $offenders,
		);
	}

	/**
	 * Run all six diagnostic checks and compile a performance report.
	 *
	 * Calculates a score starting at 100, subtracting 15 per 'fail' and
	 * 8 per 'warning'. The resulting report is cached in the
	 * wp_claw_perf_report transient for one day.
	 *
	 * @since 1.4.0
	 *
	 * @return array {
	 *     @type int    $score            Performance score (0–100).
	 *     @type array  $checks           Indexed array of individual check results.
	 *     @type array  $recommendations  Human-readable action items.
	 *     @type string $generated_at     ISO 8601 timestamp.
	 * }
	 */
	public function run_diagnostics(): array {
		$checks = array(
			$this->check_autoload_bloat(),
			$this->check_object_cache(),
			$this->check_page_cache(),
			$this->check_cron_health(),
			$this->check_database_bloat(),
			$this->check_autoload_self(),
		);

		$score = 100;
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				$score -= 15;
			} elseif ( 'warning' === $check['status'] ) {
				$score -= 8;
			}
		}
		$score = max( 0, $score );

		$recommendations = array();

		foreach ( $checks as $check ) {
			if ( 'pass' === $check['status'] ) {
				continue;
			}
			switch ( $check['id'] ) {
				case 'autoload_bloat':
					$recommendations[] = sprintf(
						/* translators: %s: total autoloaded size. */
						__( 'Autoloaded options total %s — investigate the top offenders and disable autoloading where not needed.', 'claw-agent' ),
						$check['value']
					);
					break;
				case 'object_cache':
					$recommendations[] = __( 'No persistent object cache is active. Install and connect Redis or Memcached to reduce database queries.', 'claw-agent' );
					break;
				case 'page_cache':
					$recommendations[] = __( 'No page caching detected. Install WP Rocket, LiteSpeed Cache, or WP Super Cache to serve static HTML.', 'claw-agent' );
					break;
				case 'cron_health':
					$recommendations[] = sprintf(
						/* translators: %d: number of overdue cron events. */
						_n( '%d overdue cron event detected. Check that WP-Cron is running reliably.', '%d overdue cron events detected. Check that WP-Cron is running reliably.', $check['overdue'], 'claw-agent' ),
						$check['overdue']
					);
					break;
				case 'database_bloat':
					$recommendations[] = __( 'Database bloat detected. Run the DB cleanup action to remove orphaned meta, expired transients, trash, and spam.', 'claw-agent' );
					break;
				case 'self_audit':
					$recommendations[] = $check['detail'];
					break;
			}
		}

		$report = array(
			'score'           => $score,
			'checks'          => $checks,
			'recommendations' => $recommendations,
			'generated_at'    => current_time( 'c' ),
		);

		set_transient( 'wp_claw_perf_report', $report, DAY_IN_SECONDS );

		return $report;
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

		// Table fragmentation percentage (average across all tables).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional aggregate for state sync.
		$frag_data = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT AVG(Data_free / (Data_length + 1)) as avg_frag FROM information_schema.tables WHERE table_schema = %s AND Data_length > 0',
				DB_NAME
			),
			ARRAY_A
		);

		$table_fragmentation_pct = $frag_data ? round( (float) $frag_data['avg_frag'] * 100, 1 ) : 0.0;

		// Determine if autoload is top-heavy (largest option > 50% of total).
		$autoload_top_heavy = false;
		if ( $autoloaded_bytes > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional single-value query for state.
			$largest_autoload = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT MAX(LENGTH(option_value)) FROM %i WHERE autoload = %s',
					$wpdb->options,
					'yes'
				)
			);
			$autoload_top_heavy = ( $largest_autoload > ( $autoloaded_bytes * 0.5 ) );
		}

		return array(
			'module'                  => $this->get_slug(),
			'db_size_bytes'           => $db_size_bytes,
			'db_size_mb'              => round( $db_size_bytes / 1048576, 2 ),
			'autoloaded_bytes'        => $autoloaded_bytes,
			'autoloaded_kb'           => round( $autoloaded_bytes / 1024, 1 ),
			'revision_count'          => $revision_count,
			'spam_count'              => $spam_count,
			'core_web_vitals'         => is_array( $cwv ) ? $cwv : null,
			'table_fragmentation_pct' => $table_fragmentation_pct,
			'autoload_top_heavy'      => $autoload_top_heavy,
			'generated_at'            => current_time( 'c' ),
		);
	}
}
