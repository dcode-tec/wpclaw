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
		return __( 'Analytics', 'wp-claw' );
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

			default:
				return new \WP_Error(
					'wp_claw_analytics_unknown_action',
					/* translators: %s: action name */
					sprintf( esc_html__( 'Unknown analytics action: %s', 'wp-claw' ), esc_html( $action ) ),
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

		$today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE DATE(created_at) = %s",
				gmdate( 'Y-m-d' )
			)
		);

		$week = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE created_at >= %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);

		$month = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE created_at >= %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);

		return array(
			'pageviews_today' => $today,
			'pageviews_week'  => $week,
			'pageviews_month' => $month,
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
				esc_html__( 'date_from and date_to must be valid Y-m-d dates.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE DATE(created_at) BETWEEN %s AND %s",
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

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_url, COUNT(*) as views
				 FROM `{$table}`
				 WHERE DATE(created_at) BETWEEN %s AND %s
				 GROUP BY page_url
				 ORDER BY views DESC
				 LIMIT 10",
				$date_from,
				$date_to
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to retrieve top pages from database.', 'wp-claw' ),
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referrer, COUNT(*) as visits
				 FROM `{$table}`
				 WHERE DATE(created_at) BETWEEN %s AND %s
				   AND referrer IS NOT NULL AND referrer <> ''
				 GROUP BY referrer
				 ORDER BY visits DESC
				 LIMIT 10",
				$date_from,
				$date_to
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to retrieve referrers from database.', 'wp-claw' ),
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

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT device_type, COUNT(*) as views
				 FROM `{$table}`
				 WHERE DATE(created_at) BETWEEN %s AND %s
				 GROUP BY device_type
				 ORDER BY views DESC",
				$date_from,
				$date_to
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_analytics_db_error',
				esc_html__( 'Failed to retrieve device breakdown from database.', 'wp-claw' ),
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
