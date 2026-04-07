<?php
/**
 * Email notification system for WP-Claw.
 *
 * Delivers real-time alerts, daily digests, and weekly reports to the site
 * administrator when agents complete significant actions, detect threats, or
 * require attention. All emails use wp_mail() so they respect any SMTP plugin
 * already installed on the site.
 *
 * Settings are stored in the `wp_claw_notification_settings` option and can
 * be managed from the WP-Claw Settings admin page.
 *
 * @package    WPClaw
 * @subpackage WPClaw/includes
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.3.0
 */

namespace WPClaw;

defined( 'ABSPATH' ) || exit;

/**
 * Static email notification helper.
 *
 * All methods are static so any part of the plugin can call
 * Notifications::send_alert() without needing to instantiate first.
 *
 * Agent display map (orchestrator ID → human-readable name + emoji):
 *   architect → Karim — The Architect 🏗️
 *   scribe    → Lina — The Scribe ✍️
 *   sentinel  → Bastien — The Sentinel 🛡️
 *   commerce  → Hugo — Commerce Lead 💼
 *   analyst   → Selma — The Analyst 📊
 *   concierge → Marc — The Concierge 🤝
 *
 * @since 1.3.0
 */
class Notifications {

	// -------------------------------------------------------------------------
	// Constants & defaults
	// -------------------------------------------------------------------------

	/**
	 * WordPress option key that stores notification preferences.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_claw_notification_settings';

	/**
	 * Default notification settings merged with whatever is saved in the DB.
	 *
	 * @since 1.3.0
	 *
	 * @var array<string, mixed>
	 */
	private static array $defaults = array(
		'enabled'         => true,
		'email'           => '',      // Falls back to get_option('admin_email') when empty.
		'realtime_alerts' => true,
		'daily_digest'    => true,
		'digest_hour'     => 8,       // 08:00 site time.
		'digest_format'   => 'html',  // 'html' or 'text'.
		'weekly_report'   => true,
		'weekly_day'      => 1,       // 1 = Monday (date('N') format).
		'weekly_hour'     => 9,       // 09:00 site time.
		'muted_agents'    => array(), // Array of agent orchestrator IDs to silence.
	);

	/**
	 * Canonical agent display names keyed by orchestrator ID.
	 *
	 * @since 1.3.0
	 *
	 * @var array<string, string>
	 */
	private static array $agent_names = array(
		'architect' => 'Karim',
		'scribe'    => 'Lina',
		'sentinel'  => 'Bastien',
		'commerce'  => 'Hugo',
		'analyst'   => 'Selma',
		'concierge' => 'Marc',
	);

	/**
	 * Canonical agent role labels keyed by orchestrator ID.
	 *
	 * @since 1.3.0
	 *
	 * @var array<string, string>
	 */
	private static array $agent_roles = array(
		'architect' => 'The Architect',
		'scribe'    => 'The Scribe',
		'sentinel'  => 'The Sentinel',
		'commerce'  => 'Commerce Lead',
		'analyst'   => 'The Analyst',
		'concierge' => 'The Concierge',
	);

	/**
	 * Canonical agent emojis keyed by orchestrator ID.
	 *
	 * @since 1.3.0
	 *
	 * @var array<string, string>
	 */
	private static array $agent_emojis = array(
		'architect' => '🏗️',
		'scribe'    => '✍️',
		'sentinel'  => '🛡️',
		'commerce'  => '💼',
		'analyst'   => '📊',
		'concierge' => '🤝',
	);

	/**
	 * Per-agent accent colors for HTML email avatars.
	 *
	 * @since 1.3.0
	 *
	 * @var array<string, string>
	 */
	private static array $agent_colors = array(
		'architect' => '#6366F1',
		'scribe'    => '#EC4899',
		'sentinel'  => '#EF4444',
		'commerce'  => '#F59E0B',
		'analyst'   => '#10B981',
		'concierge' => '#3B82F6',
	);

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	/**
	 * Retrieve notification settings merged with defaults.
	 *
	 * Always returns a fully-populated array — callers can rely on every key
	 * defined in $defaults being present.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, mixed> Merged settings.
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( (array) $saved, self::$defaults );
	}

	/**
	 * Return the recipient email address, falling back to the admin email.
	 *
	 * @since 1.3.0
	 *
	 * @return string Sanitized email address.
	 */
	public static function get_email(): string {
		$settings = self::get_settings();
		$email    = ! empty( $settings['email'] ) ? $settings['email'] : get_option( 'admin_email' );
		return sanitize_email( (string) $email );
	}

	/**
	 * Check whether a given agent's notifications are muted.
	 *
	 * @since 1.3.0
	 *
	 * @param string $agent Orchestrator agent ID (e.g. 'sentinel').
	 *
	 * @return bool True when the agent is muted.
	 */
	public static function is_agent_muted( string $agent ): bool {
		$settings = self::get_settings();
		return in_array( $agent, (array) $settings['muted_agents'], true );
	}

	// -------------------------------------------------------------------------
	// Real-time alert
	// -------------------------------------------------------------------------

	/**
	 * Send a real-time alert email for a significant agent event.
	 *
	 * Respects the `enabled` and `realtime_alerts` settings and the per-agent
	 * mute list before dispatching. The email format (HTML vs plain-text) follows
	 * the `digest_format` setting so the user receives consistent formatting.
	 *
	 * @since 1.3.0
	 *
	 * @param string             $type Event type key. Supported: security_threat,
	 *                                 malware_found, ssl_expiring, agent_stuck,
	 *                                 circuit_breaker, backup_failed,
	 *                                 health_check_failed.
	 * @param array<string, mixed> $data Contextual data for the alert body.
	 *                                   Recognises 'agent', 'title', 'message',
	 *                                   'detail'.
	 *
	 * @return bool True when wp_mail() accepted the message.
	 */
	public static function send_alert( string $type, array $data ): bool {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] || ! $settings['realtime_alerts'] ) {
			return false;
		}

		$agent = isset( $data['agent'] ) ? sanitize_key( (string) $data['agent'] ) : 'system';

		if ( self::is_agent_muted( $agent ) ) {
			return false;
		}

		$subject = self::get_alert_subject( $type, $data );
		$format  = $settings['digest_format'];
		$body    = self::build_alert_body( $type, $data, $format );
		$headers = 'html' === $format
			? array( 'Content-Type: text/html; charset=UTF-8' )
			: array();

		return (bool) wp_mail( self::get_email(), $subject, $body, $headers );
	}

	/**
	 * Build the email subject line for a real-time alert.
	 *
	 * @since 1.3.0
	 *
	 * @param string             $type Alert type key.
	 * @param array<string, mixed> $data Contextual data (unused currently but kept
	 *                                   for future per-type customisation).
	 *
	 * @return string Escaped subject line including site hostname.
	 */
	private static function get_alert_subject( string $type, array $data ): string {
		$parsed = wp_parse_url( home_url() );
		$site   = ! empty( $parsed['host'] ) ? $parsed['host'] : wp_parse_url( home_url(), PHP_URL_HOST );
		$site   = (string) $site;

		$subjects = array(
			'security_threat'     => '🚨 ' . __( 'Security threat detected', 'claw-agent' ),
			'malware_found'       => '🚨 ' . __( 'Malware detected on your site', 'claw-agent' ),
			'ssl_expiring'        => '⚠️ '  . __( 'SSL certificate expiring soon', 'claw-agent' ),
			'agent_stuck'         => '⚠️ '  . __( 'Agent stuck — needs attention', 'claw-agent' ),
			'circuit_breaker'     => '⚠️ '  . __( 'Module disabled (circuit breaker)', 'claw-agent' ),
			'backup_failed'       => '❌ '  . __( 'Backup failed', 'claw-agent' ),
			'health_check_failed' => '❌ '  . __( 'Health check failed', 'claw-agent' ),
		);

		$label = isset( $subjects[ $type ] ) ? $subjects[ $type ] : '🔔 ' . __( 'WP-Claw Alert', 'claw-agent' );

		/* translators: 1: site hostname, 2: alert label string. */
		return sprintf( __( '[%1$s] %2$s', 'claw-agent' ), $site, $label );
	}

	// -------------------------------------------------------------------------
	// Daily digest
	// -------------------------------------------------------------------------

	/**
	 * Compose and send the daily digest email.
	 *
	 * Aggregates today's task statistics per agent, counts pending proposals,
	 * failed tasks, and retrieves the current security score before rendering
	 * the email body.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when wp_mail() accepted the message.
	 */
	public static function send_daily_digest(): bool {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] || ! $settings['daily_digest'] ) {
			return false;
		}

		global $wpdb;

		$tasks_table     = $wpdb->prefix . 'wp_claw_tasks';
		$proposals_table = $wpdb->prefix . 'wp_claw_proposals';
		$today           = current_time( 'Y-m-d' );

		// Tasks per agent and status for today.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$agent_stats = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, status, COUNT(*) as cnt FROM %i WHERE DATE(created_at) = %s GROUP BY agent, status',
				$tasks_table,
				$today
			),
			ARRAY_A
		);

		// Pending proposals waiting for approval.
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$proposals_table,
				'pending'
			)
		);

		// Failed tasks today.
		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND DATE(created_at) = %s',
				$tasks_table,
				'failed',
				$today
			)
		);

		// Total tasks completed today.
		$completed_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND DATE(created_at) = %s',
				$tasks_table,
				'done',
				$today
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Agent ideas created today.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ideas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agent, details, created_at FROM %i WHERE action = %s AND DATE(created_at) = %s ORDER BY created_at DESC",
				$proposals_table, 'idea', $today
			), ARRAY_A
		);

		// Security score from the security module if available.
		$security_score = 0;
		$plugin         = WP_Claw::get_instance();
		$sec_mod        = $plugin->get_module( 'security' );

		if ( null !== $sec_mod ) {
			$sec_state      = $sec_mod->get_state();
			$security_score = isset( $sec_state['security_score'] ) ? (int) $sec_state['security_score'] : 0;
		}

		$parsed = wp_parse_url( home_url() );
		$site   = ! empty( $parsed['host'] ) ? (string) $parsed['host'] : (string) wp_parse_url( home_url(), PHP_URL_HOST );

		/* translators: 1: site hostname, 2: formatted date (e.g. "Apr 4"). */
		$subject = sprintf(
			__( '[%1$s] Daily AI Team Digest — %2$s', 'claw-agent' ),
			$site,
			wp_date( 'M j' )
		);

		$sections = array(
			'agent_stats'     => is_array( $agent_stats ) ? $agent_stats : array(),
			'pending'         => $pending,
			'failed'          => $failed,
			'completed_today' => $completed_today,
			'security_score'  => $security_score,
			'date'            => $today,
			'ideas'           => is_array( $ideas ) ? $ideas : array(),
		);

		$format  = $settings['digest_format'];
		$body    = 'html' === $format
			? self::build_html_digest( $sections )
			: self::build_text_digest( $sections );

		$headers = 'html' === $format
			? array( 'Content-Type: text/html; charset=UTF-8' )
			: array();

		return (bool) wp_mail( self::get_email(), $subject, $body, $headers );
	}

	// -------------------------------------------------------------------------
	// Weekly report
	// -------------------------------------------------------------------------

	/**
	 * Compose and send the weekly summary report email.
	 *
	 * Aggregates 7-day task statistics, compares with the previous 7 days for
	 * trend indicators, collects cost breakdown, and lists the top 3 agent
	 * recommendations based on failure rate.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True when wp_mail() accepted the message.
	 */
	public static function send_weekly_report(): bool {
		$settings = self::get_settings();

		if ( ! $settings['enabled'] || ! $settings['weekly_report'] ) {
			return false;
		}

		global $wpdb;

		$tasks_table = $wpdb->prefix . 'wp_claw_tasks';
		$week_start  = current_time( 'Y-m-d', false );
		$week_ago    = gmdate( 'Y-m-d', strtotime( '-7 days', strtotime( $week_start ) ) );
		$two_weeks   = gmdate( 'Y-m-d', strtotime( '-14 days', strtotime( $week_start ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// This week tasks by agent + status.
		$this_week_stats = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, status, COUNT(*) as cnt FROM %i WHERE DATE(created_at) >= %s GROUP BY agent, status',
				$tasks_table,
				$week_ago
			),
			ARRAY_A
		);

		// Last week tasks for trend comparison.
		$last_week_stats = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, status, COUNT(*) as cnt FROM %i WHERE DATE(created_at) >= %s AND DATE(created_at) < %s GROUP BY agent, status',
				$tasks_table,
				$two_weeks,
				$week_ago
			),
			ARRAY_A
		);

		// Top 5 most recent completed tasks.
		$recent_tasks = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent, title, status, created_at FROM %i WHERE DATE(created_at) >= %s ORDER BY created_at DESC LIMIT 5',
				$tasks_table,
				$week_ago
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Compute totals.
		$this_total = 0;
		$this_done  = 0;
		$this_fail  = 0;

		foreach ( is_array( $this_week_stats ) ? $this_week_stats : array() as $row ) {
			$cnt         = (int) $row['cnt'];
			$this_total += $cnt;
			if ( 'done' === $row['status'] ) {
				$this_done += $cnt;
			}
			if ( 'failed' === $row['status'] ) {
				$this_fail += $cnt;
			}
		}

		$last_total = 0;
		$last_done  = 0;

		foreach ( is_array( $last_week_stats ) ? $last_week_stats : array() as $row ) {
			$cnt         = (int) $row['cnt'];
			$last_total += $cnt;
			if ( 'done' === $row['status'] ) {
				$last_done += $cnt;
			}
		}

		// Trend strings: ↑ up, ↓ down, → flat.
		$total_trend = self::calc_trend( $this_total, $last_total );
		$done_trend  = self::calc_trend( $this_done, $last_done );

		// Security score.
		$security_score = 0;
		$plugin         = WP_Claw::get_instance();
		$sec_mod        = $plugin->get_module( 'security' );

		if ( null !== $sec_mod ) {
			$sec_state      = $sec_mod->get_state();
			$security_score = isset( $sec_state['security_score'] ) ? (int) $sec_state['security_score'] : 0;
		}

		// Build top recommendations based on failure rate per agent.
		$fail_by_agent  = array();
		$total_by_agent = array();

		foreach ( is_array( $this_week_stats ) ? $this_week_stats : array() as $row ) {
			$ag = sanitize_key( (string) $row['agent'] );
			if ( ! isset( $total_by_agent[ $ag ] ) ) {
				$total_by_agent[ $ag ] = 0;
				$fail_by_agent[ $ag ]  = 0;
			}
			$total_by_agent[ $ag ] += (int) $row['cnt'];
			if ( 'failed' === $row['status'] ) {
				$fail_by_agent[ $ag ] += (int) $row['cnt'];
			}
		}

		$recommendations = array();

		foreach ( $fail_by_agent as $ag => $fail_cnt ) {
			if ( $fail_cnt > 0 && isset( $total_by_agent[ $ag ] ) && $total_by_agent[ $ag ] > 0 ) {
				$fail_rate = $fail_cnt / $total_by_agent[ $ag ];
				if ( $fail_rate > 0.1 ) {
					$name              = self::get_agent_display_name( $ag );
					$recommendations[] = sprintf(
						/* translators: 1: agent display name, 2: failure percentage. */
						__( 'Review %1$s tasks — %2$s%% failure rate this week.', 'claw-agent' ),
						$name,
						(string) round( $fail_rate * 100, 0 )
					);
				}
			}
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'All agents performing within normal parameters. No action required.', 'claw-agent' );
		}

		$parsed = wp_parse_url( home_url() );
		$site   = ! empty( $parsed['host'] ) ? (string) $parsed['host'] : (string) wp_parse_url( home_url(), PHP_URL_HOST );

		/* translators: 1: site hostname, 2: week start date (Y-m-d). */
		$subject = sprintf(
			__( '[%1$s] Weekly AI Team Report — w/c %2$s', 'claw-agent' ),
			$site,
			$week_ago
		);

		$sections = array(
			'this_week_stats'  => is_array( $this_week_stats ) ? $this_week_stats : array(),
			'last_week_stats'  => is_array( $last_week_stats ) ? $last_week_stats : array(),
			'recent_tasks'     => is_array( $recent_tasks ) ? $recent_tasks : array(),
			'this_total'       => $this_total,
			'this_done'        => $this_done,
			'this_fail'        => $this_fail,
			'last_total'       => $last_total,
			'last_done'        => $last_done,
			'total_trend'      => $total_trend,
			'done_trend'       => $done_trend,
			'security_score'   => $security_score,
			'recommendations'  => $recommendations,
			'week_start'       => $week_ago,
			'week_end'         => $week_start,
		);

		$format  = $settings['digest_format'];
		$body    = 'html' === $format
			? self::build_html_weekly( $sections )
			: self::build_text_weekly( $sections );

		$headers = 'html' === $format
			? array( 'Content-Type: text/html; charset=UTF-8' )
			: array();

		return (bool) wp_mail( self::get_email(), $subject, $body, $headers );
	}

	// -------------------------------------------------------------------------
	// HTML email builders
	// -------------------------------------------------------------------------

	/**
	 * Build the HTML body for a real-time alert email.
	 *
	 * Uses inline CSS throughout for maximum email client compatibility.
	 *
	 * @since 1.3.0
	 *
	 * @param string             $type    Alert type key.
	 * @param array<string, mixed> $data  Contextual data. Supports 'agent',
	 *                                    'title', 'message', 'detail'.
	 * @param string             $format  'html' or 'text'. When 'text', the
	 *                                    plain-text builder is called instead.
	 *
	 * @return string Email body.
	 */
	private static function build_alert_body( string $type, array $data, string $format ): string {
		if ( 'html' !== $format ) {
			return self::build_text_alert( $type, $data );
		}

		$agent_id    = isset( $data['agent'] ) ? sanitize_key( (string) $data['agent'] ) : 'system';
		$agent_name  = self::get_agent_display_name( $agent_id );
		$agent_color = self::get_agent_color( $agent_id );
		$agent_emoji = self::get_agent_emoji( $agent_id );

		$title   = isset( $data['title'] ) ? wp_strip_all_tags( (string) $data['title'] ) : ucwords( str_replace( '_', ' ', $type ) );
		$message = isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '';
		$detail  = isset( $data['detail'] ) ? wp_strip_all_tags( (string) $data['detail'] ) : '';

		$icon_map = array(
			'security_threat'     => '🚨',
			'malware_found'       => '🚨',
			'ssl_expiring'        => '⚠️',
			'agent_stuck'         => '⚠️',
			'circuit_breaker'     => '⚠️',
			'backup_failed'       => '❌',
			'health_check_failed' => '❌',
		);

		$icon = isset( $icon_map[ $type ] ) ? $icon_map[ $type ] : '🔔';

		$admin_url = esc_url( admin_url( 'admin.php?page=wp-claw' ) );

		$html  = self::email_header( $icon . ' ' . esc_html( $title ) );
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">';
		$html .= '<tr>';
		$html .= '<td width="48" valign="top" style="padding-right:12px;">';
		$html .= self::agent_avatar( $agent_id );
		$html .= '</td>';
		$html .= '<td valign="top">';
		$html .= '<p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#111827;">';
		$html .= esc_html( $agent_emoji . ' ' . $agent_name );
		$html .= '</p>';
		$html .= '<p style="margin:0;font-size:13px;color:#6B7280;">';
		$html .= esc_html( self::get_agent_role( $agent_id ) );
		$html .= '</p>';
		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';

		if ( ! empty( $message ) ) {
			$html .= '<p style="margin:0 0 16px;font-size:15px;color:#374151;">';
			$html .= esc_html( $message );
			$html .= '</p>';
		}

		if ( ! empty( $detail ) ) {
			$html .= '<div style="background:#F3F4F6;border-left:4px solid ' . esc_attr( $agent_color ) . ';border-radius:4px;padding:12px 16px;margin-bottom:24px;">';
			$html .= '<p style="margin:0;font-size:13px;font-family:monospace;color:#374151;white-space:pre-wrap;">';
			$html .= esc_html( $detail );
			$html .= '</p>';
			$html .= '</div>';
		}

		$html .= '<p style="text-align:center;margin:32px 0 0;">';
		$html .= '<a href="' . $admin_url . '" style="display:inline-block;background:#4F46E5;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:10px 24px;border-radius:8px;">';
		$html .= esc_html__( 'View in Dashboard', 'claw-agent' );
		$html .= '</a>';
		$html .= '</p>';

		$html .= self::email_footer();

		return $html;
	}

	/**
	 * Build the HTML body for the daily digest email.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $sections Digest data sections produced by
	 *                                        send_daily_digest().
	 *
	 * @return string HTML email body.
	 */
	private static function build_html_digest( array $sections ): string {
		$agent_stats     = (array) $sections['agent_stats'];
		$pending         = (int) $sections['pending'];
		$failed          = (int) $sections['failed'];
		$completed_today = (int) $sections['completed_today'];
		$ideas           = isset( $sections['ideas'] ) ? (array) $sections['ideas'] : array();
		$security_score  = (int) $sections['security_score'];
		$date            = sanitize_text_field( (string) $sections['date'] );

		// Reshape stats into per-agent buckets.
		$by_agent = array();

		foreach ( $agent_stats as $row ) {
			$ag = sanitize_key( (string) $row['agent'] );
			if ( ! isset( $by_agent[ $ag ] ) ) {
				$by_agent[ $ag ] = array(
					'done'        => 0,
					'failed'      => 0,
					'in_progress' => 0,
					'backlog'     => 0,
					'total'       => 0,
				);
			}
			$cnt = (int) $row['cnt'];
			$st  = sanitize_key( (string) $row['status'] );

			$by_agent[ $ag ][ $st ]    = $cnt;
			$by_agent[ $ag ]['total'] += $cnt;
		}

		// Score color.
		$score_color = '#EF4444';
		if ( $security_score >= 80 ) {
			$score_color = '#10B981';
		} elseif ( $security_score >= 60 ) {
			$score_color = '#F59E0B';
		}

		/* translators: %s: formatted date such as "Apr 4, 2026". */
		$title = sprintf( __( 'Daily AI Team Digest — %s', 'claw-agent' ), wp_date( 'M j, Y', strtotime( $date ) ) );
		$html  = self::email_header( '📋 ' . esc_html( $title ) );

		// KPI row.
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">';
		$html .= '<tr>';
		$html .= self::kpi_cell( (string) $completed_today, esc_html__( 'Completed today', 'claw-agent' ), '#4F46E5' );
		$html .= self::kpi_cell( (string) $pending, esc_html__( 'Pending approval', 'claw-agent' ), '#F59E0B' );
		$html .= self::kpi_cell( (string) $failed, esc_html__( 'Failed today', 'claw-agent' ), '#EF4444' );
		$html .= self::kpi_cell( (string) $security_score . '%', esc_html__( 'Security score', 'claw-agent' ), $score_color );
		$html .= '</tr>';
		$html .= '</table>';

		// Agent rows.
		if ( ! empty( $by_agent ) ) {
			$html .= '<h3 style="font-size:14px;font-weight:700;color:#111827;margin:0 0 12px;">';
			$html .= esc_html__( 'Agent Performance', 'claw-agent' );
			$html .= '</h3>';

			foreach ( $by_agent as $agent_id => $stats ) {
				$html .= self::agent_stats_row( $agent_id, $stats );
			}
		} else {
			$html .= '<p style="font-size:14px;color:#6B7280;text-align:center;padding:24px 0;">';
			$html .= esc_html__( 'No tasks created yet today.', 'claw-agent' );
			$html .= '</p>';
		}

		// Ideas section.
		if ( ! empty( $ideas ) ) {
			$html .= '<h2 style="color:#854d0e;font-size:18px;margin:24px 0 12px;">💡 ' . esc_html__( 'Ideas from your team', 'claw-agent' ) . '</h2>';
			foreach ( $ideas as $idea ) {
				$details = json_decode( $idea['details'], true );
				$title   = isset( $details['title'] ) ? $details['title'] : __( 'Untitled idea', 'claw-agent' );
				$html   .= '<div style="padding:12px;background:#fefce8;border-radius:8px;margin-bottom:8px;border:1px solid #fde047;">';
				$html   .= '<strong>' . esc_html( ucfirst( $idea['agent'] ) ) . ':</strong> ';
				$html   .= esc_html( $title );
				$html   .= '</div>';
			}
		}

		// Action buttons.
		$proposals_url = esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) );
		$settings_url  = esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) );

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:32px;">';
		$html .= '<tr>';
		$html .= '<td align="center" style="padding:0 8px;">';

		if ( $pending > 0 ) {
			$html .= '<a href="' . $proposals_url . '" style="display:inline-block;background:#4F46E5;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;padding:9px 20px;border-radius:8px;">';
			/* translators: %d: number of pending proposals. */
			$html .= sprintf( esc_html__( 'Review %d Proposal(s)', 'claw-agent' ), $pending );
			$html .= '</a>';
		} else {
			$html .= '<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw' ) ) . '" style="display:inline-block;background:#4F46E5;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;padding:9px 20px;border-radius:8px;">';
			$html .= esc_html__( 'View Dashboard', 'claw-agent' );
			$html .= '</a>';
		}

		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';

		$html .= self::email_footer( $settings_url );

		return $html;
	}

	/**
	 * Build the HTML body for the weekly report email.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $sections Weekly data sections produced by
	 *                                        send_weekly_report().
	 *
	 * @return string HTML email body.
	 */
	private static function build_html_weekly( array $sections ): string {
		$this_total      = (int) $sections['this_total'];
		$this_done       = (int) $sections['this_done'];
		$this_fail       = (int) $sections['this_fail'];
		$last_total      = (int) $sections['last_total'];
		$last_done       = (int) $sections['last_done'];
		$total_trend     = (string) $sections['total_trend'];
		$done_trend      = (string) $sections['done_trend'];
		$security_score  = (int) $sections['security_score'];
		$recommendations = (array) $sections['recommendations'];
		$week_start      = sanitize_text_field( (string) $sections['week_start'] );
		$week_end        = sanitize_text_field( (string) $sections['week_end'] );
		$this_week_stats = (array) $sections['this_week_stats'];
		$recent_tasks    = (array) $sections['recent_tasks'];

		// Trend colors.
		$total_trend_color = '→' === $total_trend ? '#6B7280' : ( '↑' === $total_trend ? '#10B981' : '#EF4444' );
		$done_trend_color  = '→' === $done_trend ? '#6B7280' : ( '↑' === $done_trend ? '#10B981' : '#EF4444' );

		$score_color = '#EF4444';
		if ( $security_score >= 80 ) {
			$score_color = '#10B981';
		} elseif ( $security_score >= 60 ) {
			$score_color = '#F59E0B';
		}

		$completion_rate = $this_total > 0 ? round( ( $this_done / $this_total ) * 100 ) : 0;

		/* translators: 1: week start date, 2: week end date. */
		$title = sprintf(
			__( 'Weekly AI Team Report — %1$s to %2$s', 'claw-agent' ),
			wp_date( 'M j', strtotime( $week_start ) ),
			wp_date( 'M j', strtotime( $week_end ) )
		);

		$html  = self::email_header( '📊 ' . esc_html( $title ) );

		// KPI row.
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">';
		$html .= '<tr>';
		$html .= self::kpi_cell(
			$this_total . ' <span style="font-size:12px;color:' . esc_attr( $total_trend_color ) . ';">' . esc_html( $total_trend ) . '</span>',
			esc_html__( 'Total tasks', 'claw-agent' ),
			'#4F46E5'
		);
		$html .= self::kpi_cell(
			$this_done . ' <span style="font-size:12px;color:' . esc_attr( $done_trend_color ) . ';">' . esc_html( $done_trend ) . '</span>',
			esc_html__( 'Completed', 'claw-agent' ),
			'#10B981'
		);
		$html .= self::kpi_cell( (string) $completion_rate . '%', esc_html__( 'Completion rate', 'claw-agent' ), '#6366F1' );
		$html .= self::kpi_cell( (string) $security_score . '%', esc_html__( 'Security score', 'claw-agent' ), $score_color );
		$html .= '</tr>';
		$html .= '</table>';

		// Agent performance section.
		$by_agent = array();

		foreach ( $this_week_stats as $row ) {
			$ag = sanitize_key( (string) $row['agent'] );
			if ( ! isset( $by_agent[ $ag ] ) ) {
				$by_agent[ $ag ] = array(
					'done'        => 0,
					'failed'      => 0,
					'in_progress' => 0,
					'backlog'     => 0,
					'total'       => 0,
				);
			}
			$cnt = (int) $row['cnt'];
			$st  = sanitize_key( (string) $row['status'] );

			$by_agent[ $ag ][ $st ]    = $cnt;
			$by_agent[ $ag ]['total'] += $cnt;
		}

		if ( ! empty( $by_agent ) ) {
			$html .= '<h3 style="font-size:14px;font-weight:700;color:#111827;margin:0 0 12px;">';
			$html .= esc_html__( 'This Week by Agent', 'claw-agent' );
			$html .= '</h3>';

			foreach ( $by_agent as $agent_id => $stats ) {
				$html .= self::agent_stats_row( $agent_id, $stats );
			}
		}

		// Recent tasks.
		if ( ! empty( $recent_tasks ) ) {
			$html .= '<h3 style="font-size:14px;font-weight:700;color:#111827;margin:24px 0 12px;">';
			$html .= esc_html__( 'Recent Tasks', 'claw-agent' );
			$html .= '</h3>';

			$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
			$html .= '<tr style="border-bottom:1px solid #E5E7EB;">';
			$html .= '<th style="font-size:11px;font-weight:600;color:#6B7280;text-align:left;padding:6px 8px;">' . esc_html__( 'Agent', 'claw-agent' ) . '</th>';
			$html .= '<th style="font-size:11px;font-weight:600;color:#6B7280;text-align:left;padding:6px 8px;">' . esc_html__( 'Task', 'claw-agent' ) . '</th>';
			$html .= '<th style="font-size:11px;font-weight:600;color:#6B7280;text-align:left;padding:6px 8px;">' . esc_html__( 'Status', 'claw-agent' ) . '</th>';
			$html .= '</tr>';

			foreach ( $recent_tasks as $task ) {
				$ag       = sanitize_key( (string) $task['agent'] );
				$status   = sanitize_key( (string) $task['status'] );
				$bg_color = 'done' === $status ? '#F0FDF4' : ( 'failed' === $status ? '#FEF2F2' : '#FFFFFF' );

				$html .= '<tr style="border-bottom:1px solid #F3F4F6;background:' . esc_attr( $bg_color ) . ';">';
				$html .= '<td style="font-size:12px;color:#374151;padding:8px;">' . esc_html( self::get_agent_display_name( $ag ) ) . '</td>';
				$html .= '<td style="font-size:12px;color:#374151;padding:8px;">' . esc_html( wp_trim_words( (string) $task['title'], 8 ) ) . '</td>';
				$html .= '<td style="font-size:12px;color:#374151;padding:8px;">' . esc_html( ucfirst( $status ) ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</table>';
		}

		// Recommendations.
		if ( ! empty( $recommendations ) ) {
			$html .= '<div style="background:#EEF2FF;border-radius:8px;padding:16px 20px;margin-top:24px;">';
			$html .= '<h3 style="font-size:13px;font-weight:700;color:#4F46E5;margin:0 0 10px;">';
			$html .= esc_html__( '💡 Recommendations', 'claw-agent' );
			$html .= '</h3>';
			$html .= '<ul style="margin:0;padding:0 0 0 16px;">';

			foreach ( $recommendations as $rec ) {
				$html .= '<li style="font-size:13px;color:#374151;margin-bottom:6px;">' . esc_html( (string) $rec ) . '</li>';
			}

			$html .= '</ul>';
			$html .= '</div>';
		}

		// CTA.
		$html .= '<p style="text-align:center;margin:32px 0 0;">';
		$html .= '<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw' ) ) . '" style="display:inline-block;background:#4F46E5;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:10px 24px;border-radius:8px;">';
		$html .= esc_html__( 'View Full Dashboard', 'claw-agent' );
		$html .= '</a>';
		$html .= '</p>';

		$settings_url = esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) );
		$html        .= self::email_footer( $settings_url );

		return $html;
	}

	// -------------------------------------------------------------------------
	// Plain-text email builders
	// -------------------------------------------------------------------------

	/**
	 * Build the plain-text body for a real-time alert email.
	 *
	 * @since 1.3.0
	 *
	 * @param string             $type Alert type key.
	 * @param array<string, mixed> $data Contextual data.
	 *
	 * @return string Plain-text email body.
	 */
	private static function build_text_alert( string $type, array $data ): string {
		$agent_id   = isset( $data['agent'] ) ? sanitize_key( (string) $data['agent'] ) : 'system';
		$agent_name = self::get_agent_display_name( $agent_id );
		$title      = isset( $data['title'] ) ? wp_strip_all_tags( (string) $data['title'] ) : ucwords( str_replace( '_', ' ', $type ) );
		$message    = isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '';
		$detail     = isset( $data['detail'] ) ? wp_strip_all_tags( (string) $data['detail'] ) : '';

		$lines   = array();
		$lines[] = '=== WP-Claw Alert ===';
		$lines[] = '';
		$lines[] = $title;
		$lines[] = str_repeat( '-', min( 60, strlen( $title ) ) );
		$lines[] = '';
		/* translators: %s: agent display name. */
		$lines[] = sprintf( __( 'Agent: %s', 'claw-agent' ), $agent_name );
		$lines[] = '';

		if ( ! empty( $message ) ) {
			$lines[] = $message;
			$lines[] = '';
		}

		if ( ! empty( $detail ) ) {
			$lines[] = __( 'Detail:', 'claw-agent' );
			$lines[] = $detail;
			$lines[] = '';
		}

		$lines[] = admin_url( 'admin.php?page=wp-claw' );
		$lines[] = '';
		/* translators: %s: settings page URL. */
		$lines[] = sprintf( __( 'Manage preferences: %s', 'claw-agent' ), admin_url( 'admin.php?page=wp-claw-settings' ) );

		return implode( "\n", $lines );
	}

	/**
	 * Build the plain-text body for the daily digest email.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $sections Digest data sections.
	 *
	 * @return string Plain-text email body.
	 */
	private static function build_text_digest( array $sections ): string {
		$agent_stats     = (array) $sections['agent_stats'];
		$pending         = (int) $sections['pending'];
		$failed          = (int) $sections['failed'];
		$completed_today = (int) $sections['completed_today'];
		$security_score  = (int) $sections['security_score'];
		$date            = sanitize_text_field( (string) $sections['date'] );
		$ideas           = isset( $sections['ideas'] ) ? (array) $sections['ideas'] : array();

		$lines   = array();
		/* translators: %s: formatted date. */
		$lines[] = sprintf( '=== %s ===', sprintf( __( 'WP-Claw Daily Digest — %s', 'claw-agent' ), wp_date( 'M j, Y', strtotime( $date ) ) ) );
		$lines[] = '';
		/* translators: %d: number of completed tasks. */
		$lines[] = sprintf( __( 'Completed today: %d', 'claw-agent' ), $completed_today );
		/* translators: %d: number of pending proposals. */
		$lines[] = sprintf( __( 'Pending approval: %d', 'claw-agent' ), $pending );
		/* translators: %d: number of failed tasks. */
		$lines[] = sprintf( __( 'Failed today: %d', 'claw-agent' ), $failed );
		/* translators: %d: security score percentage. */
		$lines[] = sprintf( __( 'Security score: %d%%', 'claw-agent' ), $security_score );
		$lines[] = '';
		$lines[] = __( 'Agent Summary', 'claw-agent' );
		$lines[] = str_repeat( '-', 40 );

		// Reshape stats.
		$by_agent = array();

		foreach ( $agent_stats as $row ) {
			$ag = sanitize_key( (string) $row['agent'] );
			if ( ! isset( $by_agent[ $ag ] ) ) {
				$by_agent[ $ag ] = array( 'done' => 0, 'failed' => 0, 'total' => 0 );
			}
			$cnt                        = (int) $row['cnt'];
			$by_agent[ $ag ][ $row['status'] ] = $cnt;
			$by_agent[ $ag ]['total']  += $cnt;
		}

		foreach ( $by_agent as $agent_id => $stats ) {
			/* translators: 1: agent name, 2: done count, 3: total count, 4: failed count. */
			$lines[] = sprintf(
				__( '%1$s — %2$d/%3$d done, %4$d failed', 'claw-agent' ),
				self::get_agent_display_name( $agent_id ),
				(int) ( $stats['done'] ?? 0 ),
				(int) $stats['total'],
				(int) ( $stats['failed'] ?? 0 )
			);
		}

		if ( ! empty( $ideas ) ) {
			$lines[] = '';
			$lines[] = __( '--- Ideas from your team ---', 'claw-agent' );
			$lines[] = '';
			foreach ( $ideas as $idea ) {
				$details = json_decode( $idea['details'], true );
				$title   = isset( $details['title'] ) ? $details['title'] : 'Untitled idea';
				/* translators: 1: agent name, 2: idea title. */
				$lines[] = sprintf( '  💡 %1$s: %2$s', ucfirst( $idea['agent'] ), $title );
			}
		}

		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=wp-claw' );
		$lines[] = '';
		/* translators: %s: settings page URL. */
		$lines[] = sprintf( __( 'Manage preferences: %s', 'claw-agent' ), admin_url( 'admin.php?page=wp-claw-settings' ) );

		return implode( "\n", $lines );
	}

	/**
	 * Build the plain-text body for the weekly report email.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $sections Weekly data sections.
	 *
	 * @return string Plain-text email body.
	 */
	private static function build_text_weekly( array $sections ): string {
		$this_total     = (int) $sections['this_total'];
		$this_done      = (int) $sections['this_done'];
		$this_fail      = (int) $sections['this_fail'];
		$last_total     = (int) $sections['last_total'];
		$security_score = (int) $sections['security_score'];
		$recommendations = (array) $sections['recommendations'];
		$week_start     = sanitize_text_field( (string) $sections['week_start'] );
		$week_end       = sanitize_text_field( (string) $sections['week_end'] );
		$total_trend    = (string) $sections['total_trend'];

		$lines   = array();
		/* translators: 1: week start date, 2: week end date. */
		$lines[] = sprintf(
			'=== %s ===',
			sprintf( __( 'WP-Claw Weekly Report — %1$s to %2$s', 'claw-agent' ), $week_start, $week_end )
		);
		$lines[] = '';
		/* translators: 1: total tasks, 2: trend indicator. */
		$lines[] = sprintf( __( 'Total tasks: %1$d %2$s', 'claw-agent' ), $this_total, $total_trend );
		/* translators: %d: completed tasks count. */
		$lines[] = sprintf( __( 'Completed: %d', 'claw-agent' ), $this_done );
		/* translators: %d: failed tasks count. */
		$lines[] = sprintf( __( 'Failed: %d', 'claw-agent' ), $this_fail );
		/* translators: %d: security score percentage. */
		$lines[] = sprintf( __( 'Security score: %d%%', 'claw-agent' ), $security_score );
		/* translators: %d: last week total tasks. */
		$lines[] = sprintf( __( 'vs. last week: %d tasks', 'claw-agent' ), $last_total );
		$lines[] = '';
		$lines[] = __( 'Recommendations', 'claw-agent' );
		$lines[] = str_repeat( '-', 40 );

		foreach ( $recommendations as $rec ) {
			$lines[] = '- ' . (string) $rec;
		}

		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=wp-claw' );
		$lines[] = '';
		/* translators: %s: settings page URL. */
		$lines[] = sprintf( __( 'Manage preferences: %s', 'claw-agent' ), admin_url( 'admin.php?page=wp-claw-settings' ) );

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// HTML email component helpers (reusable across builders)
	// -------------------------------------------------------------------------

	/**
	 * Generate the HTML email header with WP-Claw branding and a title.
	 *
	 * Opens the outer wrapper, logo, and title card. Must be paired with
	 * email_footer() to close the wrapping tables.
	 *
	 * @since 1.3.0
	 *
	 * @param string $title Pre-escaped email title text (may contain emoji).
	 *
	 * @return string Partial HTML.
	 */
	private static function email_header( string $title ): string {
		$admin_url = esc_url( admin_url( 'admin.php?page=wp-claw' ) );

		$html  = '<!DOCTYPE html>';
		$html .= '<html lang="en"><head><meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$html .= '<title>' . esc_html( wp_strip_all_tags( $title ) ) . '</title>';
		$html .= '</head><body style="margin:0;padding:0;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';

		// Outer wrapper.
		$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;padding:24px 16px;">';
		$html .= '<tr><td align="center">';

		// Inner card.
		$html .= '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1),0 1px 2px rgba(0,0,0,.06);">';

		// Logo/header bar.
		$html .= '<tr>';
		$html .= '<td style="background:#4F46E5;padding:20px 32px;">';
		$html .= '<table width="100%" cellpadding="0" cellspacing="0">';
		$html .= '<tr>';
		$html .= '<td>';
		$html .= '<a href="' . $admin_url . '" style="text-decoration:none;">';
		$html .= '<span style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">WP-Claw</span>';
		$html .= '</a>';
		$html .= '</td>';
		$html .= '<td align="right">';
		$html .= '<span style="font-size:11px;color:rgba(255,255,255,0.7);">';
		$html .= esc_html( get_bloginfo( 'name' ) );
		$html .= '</span>';
		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';
		$html .= '</td>';
		$html .= '</tr>';

		// Title row.
		$html .= '<tr>';
		$html .= '<td style="padding:24px 32px 20px;">';
		$html .= '<h1 style="margin:0;font-size:20px;font-weight:700;color:#111827;line-height:1.3;">';
		// Title may contain emoji — output without escaping via wp_kses (allow none so tags are stripped).
		$html .= wp_kses( $title, array() );
		$html .= '</h1>';
		$html .= '</td>';
		$html .= '</tr>';

		// Body padding row open.
		$html .= '<tr><td style="padding:0 32px 32px;">';

		return $html;
	}

	/**
	 * Generate the HTML email footer with preferences link.
	 *
	 * Closes all wrapper tables opened by email_header().
	 *
	 * @since 1.3.0
	 *
	 * @param string $settings_url Optional admin settings URL for the preferences link.
	 *
	 * @return string Partial HTML.
	 */
	private static function email_footer( string $settings_url = '' ): string {
		if ( empty( $settings_url ) ) {
			$settings_url = esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) );
		}

		$html  = '</td></tr>'; // Close body padding row.

		// Footer bar.
		$html .= '<tr>';
		$html .= '<td style="background:#F9FAFB;border-top:1px solid #E5E7EB;padding:16px 32px;text-align:center;">';
		$html .= '<p style="margin:0;font-size:11px;color:#9CA3AF;">';
		$html .= esc_html__( 'You\'re receiving this because notifications are enabled in WP-Claw.', 'claw-agent' );
		$html .= ' <a href="' . esc_url( $settings_url ) . '" style="color:#4F46E5;text-decoration:underline;">';
		$html .= esc_html__( 'Manage preferences', 'claw-agent' );
		$html .= '</a>';
		$html .= '</p>';
		$html .= '<p style="margin:6px 0 0;font-size:11px;color:#9CA3AF;">';
		$html .= esc_html( get_bloginfo( 'name' ) ) . ' &mdash; ';
		$html .= '<a href="' . esc_url( home_url() ) . '" style="color:#9CA3AF;">' . esc_url( home_url() ) . '</a>';
		$html .= '</p>';
		$html .= '</td>';
		$html .= '</tr>';

		// Close inner card, outer wrapper.
		$html .= '</table>'; // Inner card.
		$html .= '</td></tr></table>'; // Outer wrapper.
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Render a KPI statistics cell for a 4-column summary row.
	 *
	 * Renders a white mini-card inside a table cell with a large metric value,
	 * a label, and a colored top accent border.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value HTML value string (may include span for trend arrows — NOT escaped here,
	 *                      caller must ensure it is safe before passing).
	 * @param string $label Already-escaped label text.
	 * @param string $color Hex color for the top accent border.
	 *
	 * @return string HTML table cell.
	 */
	private static function kpi_cell( string $value, string $label, string $color ): string {
		$safe_color = preg_match( '/^#[0-9A-Fa-f]{3,6}$/', $color ) ? $color : '#4F46E5';

		$html  = '<td style="padding:4px;">';
		$html .= '<div style="background:#F9FAFB;border-radius:8px;padding:12px;text-align:center;border-top:3px solid ' . esc_attr( $safe_color ) . ';">';
		$html .= '<div style="font-size:22px;font-weight:700;color:#111827;line-height:1;">' . $value . '</div>';
		$html .= '<div style="font-size:10px;color:#6B7280;margin-top:4px;text-transform:uppercase;letter-spacing:0.5px;">' . $label . '</div>';
		$html .= '</div>';
		$html .= '</td>';

		return $html;
	}

	/**
	 * Render an agent avatar using profile image (falls back to letter circle).
	 *
	 * @since 1.3.1
	 *
	 * @param string $agent_id Orchestrator agent ID.
	 *
	 * @return string HTML img or div element.
	 */
	private static function agent_avatar( string $agent_id ): string {
		$name  = self::get_agent_display_name( $agent_id );
		$color = self::get_agent_color( $agent_id );

		// Map agent ID to avatar filename.
		$avatar_map = array(
			'architect' => 'Karim.png',
			'scribe'    => 'Lina.png',
			'sentinel'  => 'Bastien.png',
			'commerce'  => 'Hugo.png',
			'analyst'   => 'Selma.png',
			'concierge' => 'Marc.png',
		);

		if ( isset( $avatar_map[ $agent_id ] ) && defined( 'WP_CLAW_PLUGIN_URL' ) ) {
			$url = WP_CLAW_PLUGIN_URL . 'public/avatars/' . $avatar_map[ $agent_id ];
			return sprintf(
				'<img src="%s" alt="%s" width="40" height="40" style="width:40px;height:40px;border-radius:50%%;object-fit:cover;display:block;" />',
				esc_url( $url ),
				esc_attr( $name )
			);
		}

		// Fallback to letter circle.
		$letter = strtoupper( mb_substr( $name, 0, 1 ) );
		return sprintf(
			'<div style="width:40px;height:40px;border-radius:50%%;background:%s;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;text-align:center;line-height:40px;">%s</div>',
			esc_attr( $color ),
			esc_html( $letter )
		);
	}

	/**
	 * Render a single agent stats row for use in digest and weekly emails.
	 *
	 * Shows avatar, name/role, done/failed counts, and a progress bar.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $agent_id Orchestrator agent ID.
	 * @param array<string, int>   $stats    Keyed counts: done, failed, total.
	 *
	 * @return string HTML row.
	 */
	private static function agent_stats_row( string $agent_id, array $stats ): string {
		$color     = self::get_agent_color( $agent_id );
		$name      = self::get_agent_display_name( $agent_id );
		$role      = self::get_agent_role( $agent_id );
		$emoji     = self::get_agent_emoji( $agent_id );
		$done      = (int) ( $stats['done'] ?? 0 );
		$failed    = (int) ( $stats['failed'] ?? 0 );
		$total     = (int) ( $stats['total'] ?? 0 );
		$pct       = $total > 0 ? round( ( $done / $total ) * 100 ) : 0;
		$letter    = strtoupper( mb_substr( $name, 0, 1 ) );

		$html  = '<div style="display:flex;align-items:center;padding:10px 0;border-bottom:1px solid #F3F4F6;">';
		$html .= '<div style="width:36px;height:36px;border-radius:50%;background:' . esc_attr( $color ) . ';font-size:14px;font-weight:700;color:#fff;text-align:center;line-height:36px;flex-shrink:0;">' . esc_html( $letter ) . '</div>';
		$html .= '<div style="margin-left:12px;flex:1;">';
		$html .= '<div style="font-size:13px;font-weight:600;color:#111827;">' . esc_html( $emoji . ' ' . $name ) . '</div>';
		$html .= '<div style="font-size:11px;color:#6B7280;">' . esc_html( $role ) . '</div>';
		$html .= '</div>';
		$html .= '<div style="text-align:right;flex-shrink:0;">';
		$html .= '<div style="font-size:13px;font-weight:600;color:#111827;">';
		/* translators: 1: done count, 2: total count. */
		$html .= sprintf( esc_html__( '%1$d/%2$d', 'claw-agent' ), $done, $total );
		$html .= '</div>';
		if ( $failed > 0 ) {
			$html .= '<div style="font-size:11px;color:#EF4444;">';
			/* translators: %d: number of failed tasks. */
			$html .= sprintf( esc_html__( '%d failed', 'claw-agent' ), $failed );
			$html .= '</div>';
		}
		$html .= '</div>';
		$html .= '</div>';

		// Progress bar.
		$html .= '<div style="height:3px;background:#E5E7EB;border-radius:2px;margin-bottom:8px;">';
		$html .= '<div style="height:3px;width:' . esc_attr( (string) $pct ) . '%;background:' . esc_attr( $color ) . ';border-radius:2px;"></div>';
		$html .= '</div>';

		return $html;
	}

	// -------------------------------------------------------------------------
	// Agent display helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the human-readable first name for an orchestrator agent ID.
	 *
	 * @since 1.3.0
	 *
	 * @param string $agent_id Orchestrator agent ID (e.g. 'sentinel').
	 *
	 * @return string First name (e.g. 'Bastien') or title-cased ID as fallback.
	 */
	private static function get_agent_display_name( string $agent_id ): string {
		return isset( self::$agent_names[ $agent_id ] )
			? self::$agent_names[ $agent_id ]
			: ucfirst( $agent_id );
	}

	/**
	 * Return the role label for an orchestrator agent ID.
	 *
	 * @since 1.3.0
	 *
	 * @param string $agent_id Orchestrator agent ID.
	 *
	 * @return string Role label (e.g. 'The Sentinel') or empty string.
	 */
	private static function get_agent_role( string $agent_id ): string {
		return isset( self::$agent_roles[ $agent_id ] ) ? self::$agent_roles[ $agent_id ] : '';
	}

	/**
	 * Return the emoji for an orchestrator agent ID.
	 *
	 * @since 1.3.0
	 *
	 * @param string $agent_id Orchestrator agent ID.
	 *
	 * @return string Emoji character(s) or empty string.
	 */
	private static function get_agent_emoji( string $agent_id ): string {
		return isset( self::$agent_emojis[ $agent_id ] ) ? self::$agent_emojis[ $agent_id ] : '';
	}

	/**
	 * Return the accent color hex for an orchestrator agent ID.
	 *
	 * @since 1.3.0
	 *
	 * @param string $agent_id Orchestrator agent ID.
	 *
	 * @return string Hex color string, defaulting to indigo.
	 */
	private static function get_agent_color( string $agent_id ): string {
		return isset( self::$agent_colors[ $agent_id ] ) ? self::$agent_colors[ $agent_id ] : '#4F46E5';
	}

	// -------------------------------------------------------------------------
	// Utility helpers
	// -------------------------------------------------------------------------

	/**
	 * Calculate a trend symbol by comparing current vs. previous values.
	 *
	 * Returns '↑' when current exceeds previous by more than 5%, '↓' when it
	 * is lower by more than 5%, and '→' when they are roughly equal.
	 *
	 * @since 1.3.0
	 *
	 * @param int $current Current period value.
	 * @param int $previous Previous period value.
	 *
	 * @return string '↑', '↓', or '→'.
	 */
	private static function calc_trend( int $current, int $previous ): string {
		if ( 0 === $previous ) {
			return $current > 0 ? '↑' : '→';
		}

		$change = ( $current - $previous ) / $previous;

		if ( $change > 0.05 ) {
			return '↑';
		}

		if ( $change < -0.05 ) {
			return '↓';
		}

		return '→';
	}
}
