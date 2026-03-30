<?php
/**
 * Analytics module.
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
 * Analytics module — Analyst agent reads privacy-first analytics data.
 *
 * Pageview events are collected via a lightweight JS pixel (public.js)
 * that posts to the /wp-json/wp-claw/v1/analytics REST endpoint.
 * Session hashes are SHA-256 of IP + UA — no personal data stored.
 * All read operations use $wpdb->prepare() to prevent injection.
 *
 * @since 1.0.0
 */
class Module_Analytics extends Module_Base {

	/**
	 * Database table name (without global $wpdb->prefix — appended at runtime).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const TABLE = 'wp_claw_analytics';

	// -------------------------------------------------------------------------
	// Module contract implementation
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'analytics';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Analytics', 'claw-agent' );
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
	 * Return the allowlisted actions for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'get_pageviews',
			'get_top_pages',
			'get_referrers',
			'get_device_breakdown',
			'generate_report',
			'detect_anomalies',
			'get_funnel_data',
			'get_top_content',
			'get_content_trends',
			'store_cwv_data',
			'get_cwv_trends',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'get_pageviews':
				return $this->action_get_pageviews( $params );

			case 'get_top_pages':
				return $this->action_get_top_pages( $params );

			case 'get_referrers':
				return $this->action_get_referrers( $params );

			case 'get_device_breakdown':
				return $this->action_get_device_breakdown( $params );

			case 'generate_report':
				return $this->action_generate_report( $params );

			case 'detect_anomalies':
				return $this->action_detect_anomalies( $params );

			case 'get_funnel_data':
				return $this->action_get_funnel_data( $params );

			case 'get_top_content':
				return $this->action_get_top_content( $params );

			case 'get_content_trends':
				return $this->action_get_content_trends( $params );

			case 'store_cwv_data':
				return $this->action_store_cwv_data( $params );

			case 'get_cwv_trends':
				return $this->action_get_cwv_trends( $params );

			default:
				return new \WP_Error(
					'wp_claw_analytics_unknown_action',
					/* translators: %s: action name */
					sprintf( esc_html__( 'Unknown analytics action: %s', 'claw-agent' ), esc_html( $action ) ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return today/week/month pageview counts from the analytics table.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$today = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE DATE(created_at) = %s',
				$table,
				gmdate( 'Y-m-d' )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$week = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$month = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
				$table,
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);

		// Anomaly detection: compare last 24h vs 30-day daily average.
		$anomaly            = $this->compute_anomaly_summary();
		$anomaly_detected   = $anomaly['anomaly_detected'];
		$anomaly_type       = $anomaly['anomaly_type'];
		$funnel_drop_off    = $this->compute_funnel_drop_off_step();
		$cwv_counts         = $this->compute_cwv_rating_counts();

		return array(
			'pageviews_today'     => $today,
			'pageviews_week'      => $week,
			'pageviews_month'     => $month,
			'anomaly_detected'    => $anomaly_detected,
			'anomaly_type'        => $anomaly_type,
			'funnel_drop_off_step' => $funnel_drop_off,
			'cwv_pages_good'      => $cwv_counts['good'],
			'cwv_pages_poor'      => $cwv_counts['poor'],
		);
	}

	/**
	 * Register WordPress hooks for this module.
	 *
	 * Hooks into wp_footer to inject the analytics pixel HTML and into
	 * wp_enqueue_scripts to conditionally load the public JS tracker.
	 * Both are guarded so they only fire when the analytics module is
	 * enabled in plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_footer', array( $this, 'render_analytics_pixel' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_scripts' ) );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks (public — required for add_action callbacks)
	// -------------------------------------------------------------------------

	/**
	 * Render the analytics pixel markup in the page footer.
	 *
	 * Injects a small hidden div that the public JS uses to discover
	 * the REST endpoint URL and nonce. Only rendered when the analytics
	 * module is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_analytics_pixel(): void {
		if ( ! $this->is_analytics_enabled() ) {
			return;
		}

		$endpoint = esc_url( rest_url( 'wp-claw/v1/analytics' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );

		echo '<div id="wp-claw-analytics-pixel" style="display:none" ';
		echo 'data-endpoint="' . esc_attr( $endpoint ) . '" ';
		echo 'data-nonce="' . esc_attr( $nonce ) . '"></div>' . "\n";
	}

	/**
	 * Enqueue the public JS analytics tracker.
	 *
	 * Only loaded when the analytics module is enabled and we are on
	 * a singular front-end page (not admin, not REST, not feeds).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_public_scripts(): void {
		if ( ! $this->is_analytics_enabled() ) {
			return;
		}

		wp_enqueue_script(
			'wp-claw-public',
			plugins_url( 'public/js/wp-claw-public.js', WP_CLAW_PLUGIN_FILE ),
			array(),
			WP_CLAW_VERSION,
			true
		);
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Return total pageview count within an optional date range.
	 *
	 * Accepts optional $params['date_from'] and $params['date_to'] as
	 * 'Y-m-d' strings. Defaults to the last 30 days when omitted.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_pageviews( array $params ) {
		global $wpdb;

		$table     = $wpdb->prefix . self::TABLE;
		$date_from = $this->sanitize_date( $params['date_from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$date_to   = $this->sanitize_date( $params['date_to'] ?? gmdate( 'Y-m-d' ) );

		if ( ! $date_from || ! $date_to ) {
			return new \WP_Error(
				'wp_claw_analytics_invalid_date',
				esc_html__( 'date_from and date_to must be valid Y-m-d dates.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE DATE(created_at) BETWEEN %s AND %s',
				$table,
				$date_from,
				$date_to
			)
		);

		return array(
			'success'   => true,
			'pageviews' => $count,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Return the top 10 pages by pageview count.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_top_pages( array $params ) {
		global $wpdb;

		$table     = $wpdb->prefix . self::TABLE;
		$date_from = $this->sanitize_date( $params['date_from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$date_to   = $this->sanitize_date( $params['date_to'] ?? gmdate( 'Y-m-d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT page_url, COUNT(*) as views FROM %i WHERE DATE(created_at) BETWEEN %s AND %s GROUP BY page_url ORDER BY views DESC LIMIT 10',
				$table,
				$date_from,
				$date_to
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to retrieve top pages from database.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'   => true,
			'top_pages' => $rows,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Return the top 10 referrers by visit count.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_referrers( array $params ) {
		global $wpdb;

		$table     = $wpdb->prefix . self::TABLE;
		$date_from = $this->sanitize_date( $params['date_from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$date_to   = $this->sanitize_date( $params['date_to'] ?? gmdate( 'Y-m-d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT referrer, COUNT(*) as visits FROM %i WHERE DATE(created_at) BETWEEN %s AND %s AND referrer IS NOT NULL AND referrer <> %s GROUP BY referrer ORDER BY visits DESC LIMIT 10',
				$table,
				$date_from,
				$date_to,
				''
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to retrieve referrers from database.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'   => true,
			'referrers' => $rows,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Return pageview counts grouped by device type.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_device_breakdown( array $params ) {
		global $wpdb;

		$table     = $wpdb->prefix . self::TABLE;
		$date_from = $this->sanitize_date( $params['date_from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$date_to   = $this->sanitize_date( $params['date_to'] ?? gmdate( 'Y-m-d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT device_type, COUNT(*) as views FROM %i WHERE DATE(created_at) BETWEEN %s AND %s GROUP BY device_type ORDER BY views DESC',
				$table,
				$date_from,
				$date_to
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to retrieve device breakdown from database.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'          => true,
			'device_breakdown' => $rows,
			'date_from'        => $date_from,
			'date_to'          => $date_to,
		);
	}

	/**
	 * Generate a summary analytics report aggregating all metrics.
	 *
	 * Runs get_pageviews, get_top_pages, get_referrers, and
	 * get_device_breakdown in sequence and compiles them into a single
	 * report array. Used by the Analyst agent for weekly reports.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_generate_report( array $params ) {
		$pageviews = $this->action_get_pageviews( $params );
		if ( is_wp_error( $pageviews ) ) {
			return $pageviews;
		}

		$top_pages = $this->action_get_top_pages( $params );
		if ( is_wp_error( $top_pages ) ) {
			return $top_pages;
		}

		$referrers = $this->action_get_referrers( $params );
		if ( is_wp_error( $referrers ) ) {
			return $referrers;
		}

		$device_breakdown = $this->action_get_device_breakdown( $params );
		if ( is_wp_error( $device_breakdown ) ) {
			return $device_breakdown;
		}

		return array(
			'success'          => true,
			'report_generated' => gmdate( 'Y-m-d H:i:s' ),
			'date_from'        => $pageviews['date_from'],
			'date_to'          => $pageviews['date_to'],
			'total_pageviews'  => $pageviews['pageviews'],
			'top_pages'        => $top_pages['top_pages'],
			'referrers'        => $referrers['referrers'],
			'device_breakdown' => $device_breakdown['device_breakdown'],
		);
	}

	/**
	 * Detect traffic anomalies by comparing last 24h against 30-day average.
	 *
	 * Flags a spike when the last 24h exceeds 200% of the daily average,
	 * or a drop when it falls below 50% of the daily average.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters (unused).
	 *
	 * @return array
	 */
	private function action_detect_anomalies( array $params ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// Last 24h count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$current_24h = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND event_type = %s',
				$table,
				gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) ),
				'pageview'
			)
		);

		// 30-day daily average.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$total_30d = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND event_type = %s',
				$table,
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
				'pageview'
			)
		);

		$average_daily = ( $total_30d > 0 ) ? round( $total_30d / 30, 2 ) : 0;

		$anomaly_detected = false;
		$anomaly_type     = null;
		$magnitude        = 0;

		if ( $average_daily > 0 ) {
			$ratio     = $current_24h / $average_daily;
			$magnitude = round( abs( $ratio - 1 ) * 100, 1 );

			if ( $ratio > 2.0 ) {
				$anomaly_detected = true;
				$anomaly_type     = 'spike';
			} elseif ( $ratio < 0.5 ) {
				$anomaly_detected = true;
				$anomaly_type     = 'drop';
			}
		}

		// Top 5 pages with biggest traffic change.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$top_affected = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT page_url, COUNT(*) as views FROM %i WHERE created_at >= %s AND event_type = %s GROUP BY page_url ORDER BY views DESC LIMIT 5',
				$table,
				gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) ),
				'pageview'
			),
			ARRAY_A
		);

		return array(
			'success'            => true,
			'anomaly_detected'   => $anomaly_detected,
			'anomaly_type'       => $anomaly_type,
			'magnitude_percent'  => $magnitude,
			'current_24h'        => $current_24h,
			'average_daily'      => $average_daily,
			'top_affected_pages' => is_array( $top_affected ) ? $top_affected : array(),
		);
	}

	/**
	 * Return e-commerce funnel data with drop-off rates.
	 *
	 * Requires WooCommerce. Groups analytics events by event_type
	 * for the standard purchase funnel and computes step-to-step
	 * drop-off percentages.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters (unused).
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_funnel_data( array $params ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error(
				'wp_claw_woocommerce_required',
				esc_html__( 'WooCommerce is required for funnel analytics.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		$table       = $wpdb->prefix . self::TABLE;
		$funnel_keys = array( 'pageview', 'cart_view', 'checkout_view', 'purchase' );
		$steps       = array();

		foreach ( $funnel_keys as $event_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE event_type = %s AND created_at >= %s',
					$table,
					$event_type,
					gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
				)
			);

			$steps[] = array(
				'name'  => $event_type,
				'count' => $count,
			);
		}

		// Compute drop-off between each step.
		for ( $i = 1, $len = count( $steps ); $i < $len; $i++ ) {
			$prev  = $steps[ $i - 1 ]['count'];
			$steps[ $i ]['drop_off_percent'] = ( $prev > 0 )
				? round( ( 1 - $steps[ $i ]['count'] / $prev ) * 100, 1 )
				: 0;
		}
		$steps[0]['drop_off_percent'] = 0;

		return array(
			'success' => true,
			'steps'   => $steps,
		);
	}

	/**
	 * Return top content pages by pageview count with trend comparison.
	 *
	 * Compares the current period against the previous period of the
	 * same length to determine whether each page is trending up, down,
	 * or stable.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *     Action parameters.
	 *
	 *     @type int $period Number of days (7, 30, or 90). Default 30.
	 *     @type int $limit  Max results (1–50). Default 10.
	 * }
	 *
	 * @return array
	 */
	private function action_get_top_content( array $params ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$period = absint( $params['period'] ?? 30 );

		if ( ! in_array( $period, array( 7, 30, 90 ), true ) ) {
			$period = 30;
		}

		$limit = min( 50, max( 1, absint( $params['limit'] ?? 10 ) ) );

		$current_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );
		$previous_from = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $period * 2 ) . ' days' ) );
		$previous_to   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period} days" ) );

		// Current period top pages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$current_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT page_url, COUNT(*) as views FROM %i WHERE event_type = %s AND created_at >= %s GROUP BY page_url ORDER BY views DESC LIMIT %d',
				$table,
				'pageview',
				$current_from,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $current_rows ) ) {
			$current_rows = array();
		}

		// Previous period counts for the same pages.
		$pages = array();
		foreach ( $current_rows as $row ) {
			$url = $row['page_url'];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$prev_views = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE event_type = %s AND page_url = %s AND created_at >= %s AND created_at < %s',
					$table,
					'pageview',
					$url,
					$previous_from,
					$previous_to
				)
			);

			$current_views = (int) $row['views'];
			if ( $prev_views > 0 ) {
				$change = ( $current_views - $prev_views ) / $prev_views;
				if ( $change > 0.1 ) {
					$trend = 'up';
				} elseif ( $change < -0.1 ) {
					$trend = 'down';
				} else {
					$trend = 'stable';
				}
			} else {
				$trend = ( $current_views > 0 ) ? 'up' : 'stable';
			}

			$pages[] = array(
				'url'   => $url,
				'views' => $current_views,
				'trend' => $trend,
			);
		}

		return array(
			'success' => true,
			'period'  => $period,
			'pages'   => $pages,
		);
	}

	/**
	 * Compare 7-day vs 30-day averages to find content trends.
	 *
	 * Returns pages with >20% change grouped into growing and declining
	 * arrays for easy agent consumption.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters (unused).
	 *
	 * @return array
	 */
	private function action_get_content_trends( array $params ) {
		global $wpdb;

		$table    = $wpdb->prefix . self::TABLE;
		$since_7  = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		$since_30 = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

		// 30-day per-page totals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$rows_30 = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT page_url, COUNT(*) as views FROM %i WHERE event_type = %s AND created_at >= %s GROUP BY page_url HAVING views >= 5',
				$table,
				'pageview',
				$since_30
			),
			ARRAY_A
		);

		if ( ! is_array( $rows_30 ) ) {
			$rows_30 = array();
		}

		$growing   = array();
		$declining = array();

		foreach ( $rows_30 as $row ) {
			$url       = $row['page_url'];
			$avg_30    = (int) $row['views'] / 30;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$views_7 = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE event_type = %s AND page_url = %s AND created_at >= %s',
					$table,
					'pageview',
					$url,
					$since_7
				)
			);

			$avg_7 = $views_7 / 7;

			if ( $avg_30 <= 0 ) {
				continue;
			}

			$change_pct = round( ( ( $avg_7 - $avg_30 ) / $avg_30 ) * 100, 1 );

			if ( $change_pct > 20 ) {
				$growing[] = array(
					'url'        => $url,
					'avg_7d'     => round( $avg_7, 2 ),
					'avg_30d'    => round( $avg_30, 2 ),
					'change_pct' => $change_pct,
				);
			} elseif ( $change_pct < -20 ) {
				$declining[] = array(
					'url'        => $url,
					'avg_7d'     => round( $avg_7, 2 ),
					'avg_30d'    => round( $avg_30, 2 ),
					'change_pct' => $change_pct,
				);
			}
		}

		return array(
			'success'   => true,
			'growing'   => $growing,
			'declining' => $declining,
		);
	}

	/**
	 * Store Core Web Vitals measurement data.
	 *
	 * Validates the page URL and computes a quality rating based on
	 * Google's thresholds: good (LCP<2500, CLS<0.1, INP<200),
	 * poor (LCP>4000, CLS>0.25, INP>500), otherwise needs-improvement.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *     CWV parameters.
	 *
	 *     @type string $page_url Required. The measured page URL.
	 *     @type int    $lcp_ms   Largest Contentful Paint in milliseconds.
	 *     @type int    $inp_ms   Interaction to Next Paint in milliseconds.
	 *     @type float  $cls_score Cumulative Layout Shift score.
	 *     @type int    $ttfb_ms  Time to First Byte in milliseconds.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function action_store_cwv_data( array $params ) {
		global $wpdb;

		$page_url = esc_url_raw( $params['page_url'] ?? '' );

		if ( empty( $page_url ) ) {
			return new \WP_Error(
				'wp_claw_analytics_missing_url',
				esc_html__( 'page_url is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$lcp_ms    = isset( $params['lcp_ms'] ) ? absint( $params['lcp_ms'] ) : null;
		$inp_ms    = isset( $params['inp_ms'] ) ? absint( $params['inp_ms'] ) : null;
		$cls_score = isset( $params['cls_score'] ) ? (float) $params['cls_score'] : null;
		$ttfb_ms   = isset( $params['ttfb_ms'] ) ? absint( $params['ttfb_ms'] ) : null;

		// Compute rating per Google thresholds.
		$is_poor = ( null !== $lcp_ms && $lcp_ms > 4000 )
			|| ( null !== $cls_score && $cls_score > 0.25 )
			|| ( null !== $inp_ms && $inp_ms > 500 );

		$is_good = ( null === $lcp_ms || $lcp_ms < 2500 )
			&& ( null === $cls_score || $cls_score < 0.1 )
			&& ( null === $inp_ms || $inp_ms < 200 );

		if ( $is_poor ) {
			$rating = 'poor';
		} elseif ( $is_good ) {
			$rating = 'good';
		} else {
			$rating = 'improve';
		}

		$table = $wpdb->prefix . 'wp_claw_cwv_history';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$inserted = $wpdb->insert(
			$table,
			array(
				'page_url'    => $page_url,
				'lcp_ms'      => $lcp_ms,
				'inp_ms'      => $inp_ms,
				'cls_score'   => $cls_score,
				'ttfb_ms'     => $ttfb_ms,
				'rating'      => $rating,
				'measured_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%f', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to store CWV data.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'id'      => (int) $wpdb->insert_id,
			'rating'  => $rating,
		);
	}

	/**
	 * Return Core Web Vitals time-series data with summary.
	 *
	 * Optionally filters by page_url. Returns individual measurements
	 * plus a summary with latest values, rating, and trend direction.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *     Query parameters.
	 *
	 *     @type string $page_url Optional. Filter to a specific page.
	 *     @type int    $days     Number of days to look back. Default 30.
	 * }
	 *
	 * @return array
	 */
	private function action_get_cwv_trends( array $params ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'wp_claw_cwv_history';
		$page_url = isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : '';
		$days     = min( 365, max( 1, absint( $params['days'] ?? 30 ) ) );
		$since    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		if ( ! empty( $page_url ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT page_url, lcp_ms, inp_ms, cls_score, ttfb_ms, rating, measured_at FROM %i WHERE page_url = %s AND measured_at >= %s ORDER BY measured_at ASC',
					$table,
					$page_url,
					$since
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT page_url, lcp_ms, inp_ms, cls_score, ttfb_ms, rating, measured_at FROM %i WHERE measured_at >= %s ORDER BY measured_at ASC',
					$table,
					$since
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Build summary from latest measurement.
		$summary = array(
			'latest_lcp_ms'  => null,
			'latest_inp_ms'  => null,
			'latest_cls'     => null,
			'latest_ttfb_ms' => null,
			'latest_rating'  => 'unknown',
			'trend'          => 'stable',
		);

		$count = count( $rows );
		if ( $count > 0 ) {
			$latest              = $rows[ $count - 1 ];
			$summary['latest_lcp_ms']  = isset( $latest['lcp_ms'] ) ? (int) $latest['lcp_ms'] : null;
			$summary['latest_inp_ms']  = isset( $latest['inp_ms'] ) ? (int) $latest['inp_ms'] : null;
			$summary['latest_cls']     = isset( $latest['cls_score'] ) ? (float) $latest['cls_score'] : null;
			$summary['latest_ttfb_ms'] = isset( $latest['ttfb_ms'] ) ? (int) $latest['ttfb_ms'] : null;
			$summary['latest_rating']  = sanitize_key( $latest['rating'] );

			// Determine trend by comparing first-half vs second-half LCP average.
			if ( $count >= 4 ) {
				$mid       = intdiv( $count, 2 );
				$first_avg = 0;
				$second_avg = 0;
				$first_cnt  = 0;
				$second_cnt = 0;

				for ( $i = 0; $i < $mid; $i++ ) {
					if ( isset( $rows[ $i ]['lcp_ms'] ) ) {
						$first_avg += (int) $rows[ $i ]['lcp_ms'];
						++$first_cnt;
					}
				}
				for ( $i = $mid; $i < $count; $i++ ) {
					if ( isset( $rows[ $i ]['lcp_ms'] ) ) {
						$second_avg += (int) $rows[ $i ]['lcp_ms'];
						++$second_cnt;
					}
				}

				if ( $first_cnt > 0 && $second_cnt > 0 ) {
					$first_avg  /= $first_cnt;
					$second_avg /= $second_cnt;

					if ( $first_avg > 0 ) {
						$change = ( $second_avg - $first_avg ) / $first_avg;
						if ( $change < -0.1 ) {
							$summary['trend'] = 'improving';
						} elseif ( $change > 0.1 ) {
							$summary['trend'] = 'degrading';
						}
					}
				}
			}
		}

		return array(
			'success'     => true,
			'days'        => $days,
			'data'        => $rows,
			'data_points' => $count,
			'summary'     => $summary,
		);
	}

	// -------------------------------------------------------------------------
	// State helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute anomaly summary for get_state().
	 *
	 * @since 1.1.0
	 *
	 * @return array{anomaly_detected: bool, anomaly_type: string|null}
	 */
	private function compute_anomaly_summary(): array {
		$result = $this->action_detect_anomalies( array() );

		return array(
			'anomaly_detected' => $result['anomaly_detected'] ?? false,
			'anomaly_type'     => $result['anomaly_type'] ?? null,
		);
	}

	/**
	 * Find the funnel step with the highest drop-off for get_state().
	 *
	 * Returns null if WooCommerce is not active.
	 *
	 * @since 1.1.0
	 *
	 * @return string|null Step name with highest drop-off, or null.
	 */
	private function compute_funnel_drop_off_step(): ?string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}

		$result = $this->action_get_funnel_data( array() );

		if ( is_wp_error( $result ) || empty( $result['steps'] ) ) {
			return null;
		}

		$max_drop = 0;
		$max_step = null;

		foreach ( $result['steps'] as $step ) {
			if ( $step['drop_off_percent'] > $max_drop ) {
				$max_drop = $step['drop_off_percent'];
				$max_step = $step['name'];
			}
		}

		return $max_step;
	}

	/**
	 * Count CWV pages by rating for get_state().
	 *
	 * Uses the latest measurement per page_url.
	 *
	 * @since 1.1.0
	 *
	 * @return array{good: int, poor: int}
	 */
	private function compute_cwv_rating_counts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_cwv_history';

		// Latest rating per page_url.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT rating, COUNT(*) as cnt FROM ( SELECT page_url, rating FROM %i WHERE id IN ( SELECT MAX(id) FROM %i GROUP BY page_url ) ) AS latest GROUP BY rating',
				$table,
				$table
			),
			ARRAY_A
		);

		$counts = array(
			'good' => 0,
			'poor' => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key = sanitize_key( $row['rating'] );
				if ( isset( $counts[ $key ] ) ) {
					$counts[ $key ] = (int) $row['cnt'];
				}
			}
		}

		return $counts;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether the analytics module is currently enabled in plugin settings.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_analytics_enabled(): bool {
		$settings = get_option( 'wp_claw_module_settings', array() );
		return ! empty( $settings['analytics']['enabled'] );
	}

	/**
	 * Sanitize and validate a Y-m-d date string.
	 *
	 * Returns the sanitized date string on success, or false if invalid.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date Raw date string.
	 *
	 * @return string|false Sanitized 'Y-m-d' date or false on failure.
	 */
	private function sanitize_date( string $date ) {
		$clean = sanitize_text_field( $date );
		$parts = explode( '-', $clean );

		if ( count( $parts ) !== 3 ) {
			return false;
		}

		$year  = absint( $parts[0] );
		$month = absint( $parts[1] );
		$day   = absint( $parts[2] );

		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}
}
