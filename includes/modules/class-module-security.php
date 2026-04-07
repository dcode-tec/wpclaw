<?php
/**
 * Security module.
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
 * Security module — managed by the Sentinel agent.
 *
 * Handles IP blocking, security header configuration, login attempt
 * recording, file integrity checks, brute-force protection, .htaccess
 * rules, and security event logging.
 *
 * @since 1.0.0
 */
class Module_Security extends Module_Base {

	/**
	 * Option key for blocked IP addresses.
	 *
	 * Stores a JSON-encoded array of IP strings.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPT_BLOCKED_IPS = 'wp_claw_blocked_ips';

	/**
	 * Option key for failed login attempt log.
	 *
	 * Stores a JSON-encoded array of attempt records (FIFO, max 1000).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPT_LOGIN_ATTEMPTS = 'wp_claw_login_attempts';

	/**
	 * Option key for security header configuration.
	 *
	 * Stores a JSON-encoded associative array of header name => value.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPT_SECURITY_HEADERS = 'wp_claw_security_headers';

	/**
	 * Option key for the last file integrity scan timestamp.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPT_LAST_SCAN = 'wp_claw_security_last_scan';

	/**
	 * Maximum number of login attempt records to keep in the option (FIFO).
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const MAX_LOGIN_ATTEMPTS = 1000;

	// -------------------------------------------------------------------------
	// Contract implementation.
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'security';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Security';
	}

	/**
	 * Return the responsible Klawty agent name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'sentinel';
	}

	/**
	 * Return the allowlisted action strings for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'block_ip',
			'update_security_headers',
			'log_security_event',
			'run_file_integrity_check',
			'enable_brute_force_protection',
			'update_htaccess_rules',
			'get_login_attempts',
			'compute_file_hashes',
			'compare_file_hashes',
			'scan_malware_patterns',
			'quarantine_file',
			'deploy_security_headers',
			'check_ssl_certificate',
			'get_quarantined_files',
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
			case 'block_ip':
				return $this->action_block_ip( $params );

			case 'update_security_headers':
				return $this->action_update_security_headers( $params );

			case 'log_security_event':
				return $this->action_log_security_event( $params );

			case 'run_file_integrity_check':
				return $this->action_run_file_integrity_check();

			case 'enable_brute_force_protection':
				return $this->action_enable_brute_force_protection( $params );

			case 'update_htaccess_rules':
				return $this->action_update_htaccess_rules( $params );

			case 'get_login_attempts':
				return $this->action_get_login_attempts( $params );

			case 'compute_file_hashes':
				return $this->action_compute_file_hashes( $params );

			case 'compare_file_hashes':
				return $this->action_compare_file_hashes( $params );

			case 'scan_malware_patterns':
				return $this->action_scan_malware_patterns( $params );

			case 'quarantine_file':
				return $this->action_quarantine_file( $params );

			case 'deploy_security_headers':
				return $this->action_deploy_security_headers();

			case 'check_ssl_certificate':
				return $this->action_check_ssl_certificate();

			case 'get_quarantined_files':
				return $this->action_get_quarantined_files();

			default:
				return new \WP_Error(
					'wp_claw_security_unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown Security action: %s', 'claw-agent' ),
						esc_html( $action )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return the current security state of the WordPress site.
	 *
	 * Provides the Sentinel agent with a snapshot for decision-making:
	 * recent failed logins, blocked IP count, and last scan timestamp.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		$blocked_ips    = $this->get_blocked_ips();
		$login_attempts = $this->get_raw_login_attempts();

		// Count attempts in last 24 hours.
		$cutoff       = time() - DAY_IN_SECONDS;
		$recent_fails = 0;
		foreach ( $login_attempts as $attempt ) {
			if ( isset( $attempt['time'] ) && (int) $attempt['time'] >= $cutoff ) {
				++$recent_fails;
			}
		}

		// File integrity status from the file_hashes table.
		$file_integrity_status  = 'scan_pending';
		$quarantined_file_count = 0;

		global $wpdb;
		$file_hashes_table = $wpdb->prefix . 'wp_claw_file_hashes';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_hashes = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is hardcoded plugin prefix, not user input.
			"SELECT COUNT(*) FROM {$file_hashes_table}"
		);

		if ( $total_hashes && (int) $total_hashes > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$non_clean = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is hardcoded plugin prefix, not user input.
				"SELECT COUNT(*) FROM {$file_hashes_table} WHERE status != 'clean'"
			);
			$file_integrity_status = ( $non_clean && (int) $non_clean > 0 ) ? 'issues_detected' : 'clean';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$quarantined_file_count = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is hardcoded plugin prefix, not user input.
				"SELECT COUNT(*) FROM {$file_hashes_table} WHERE status = 'quarantined'"
			);
		}

		// SSL info via audit module.
		$ssl_valid          = false;
		$ssl_days_remaining = null;

		$plugin = \WPClaw\WP_Claw::get_instance();
		$audit  = $plugin->get_module( 'audit' );

		if ( $audit ) {
			$ssl_result = $audit->handle_action( 'get_ssl_info', array() );
			if ( ! is_wp_error( $ssl_result ) && isset( $ssl_result['data'] ) ) {
				$ssl_valid          = ! empty( $ssl_result['data']['valid'] );
				$ssl_days_remaining = isset( $ssl_result['data']['days_remaining'] ) ? (int) $ssl_result['data']['days_remaining'] : null;
			}
		}

		// Security score calculation.
		$deployed_headers = get_option( 'wp_claw_security_headers_deployed', array() );
		if ( ! is_array( $deployed_headers ) ) {
			$deployed_headers = array();
		}
		$header_count = count( $deployed_headers );

		$brute_force_enabled = (bool) get_option( 'wp_claw_brute_force_enabled', false );

		$no_malware    = ( 'clean' === $file_integrity_status || 'scan_pending' === $file_integrity_status ) && 0 === $quarantined_file_count;
		$integrity_ok  = 'clean' === $file_integrity_status;

		$ssl_points        = $ssl_valid ? 20 : 0;
		$headers_points    = (int) min( 30, $header_count * 30 / 7 );
		$malware_points    = $no_malware ? 20 : 0;
		$integrity_points  = $integrity_ok ? 15 : 0;
		$brute_force_points = $brute_force_enabled ? 15 : 0;

		$security_score = $ssl_points + $headers_points + $malware_points + $integrity_points + $brute_force_points;

		$score_breakdown = array(
			'ssl'         => array(
				'label'  => 'SSL Valid',
				'points' => $ssl_points,
				'max'    => 20,
				'pass'   => $ssl_valid,
			),
			'headers'     => array(
				'label'  => 'Security Headers Deployed',
				'points' => $headers_points,
				'max'    => 30,
				'pass'   => $header_count >= 7,
			),
			'malware'     => array(
				'label'  => 'No Malware / Quarantine',
				'points' => $malware_points,
				'max'    => 20,
				'pass'   => $no_malware,
			),
			'integrity'   => array(
				'label'  => 'File Integrity Clean',
				'points' => $integrity_points,
				'max'    => 15,
				'pass'   => $integrity_ok,
			),
			'brute_force' => array(
				'label'  => 'Brute Force Protection Enabled',
				'points' => $brute_force_points,
				'max'    => 15,
				'pass'   => $brute_force_enabled,
			),
		);

		// Last 50 login attempts (newest first).
		$all_attempts         = array_reverse( $login_attempts );
		$recent_login_attempts = array_slice( $all_attempts, 0, 50 );

		return array(
			'failed_logins_24h'       => $recent_fails,
			'blocked_ips_count'       => count( $blocked_ips ),
			'last_scan_time'          => get_option( self::OPT_LAST_SCAN, '' ),
			'file_integrity_status'   => $file_integrity_status,
			'quarantined_file_count'  => $quarantined_file_count,
			'ssl_valid'               => $ssl_valid,
			'ssl_days_remaining'      => $ssl_days_remaining,
			'last_malware_scan'       => get_option( 'wp_claw_last_malware_scan', '' ),
			'security_headers_active' => (bool) get_option( 'wp_claw_security_headers_active', false ),
			'security_score'          => $security_score,
			'score_breakdown'         => $score_breakdown,
			'recent_login_attempts'   => $recent_login_attempts,
			'blocked_ip_list'         => $blocked_ips,
		);
	}

	/**
	 * Register WordPress action hooks for login event tracking.
	 *
	 * Records every failed login and successful login into the
	 * attempt log option (max 1000 entries, FIFO eviction).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );
		add_action( 'send_headers', array( $this, 'deploy_stored_headers' ), 5 );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks.
	// -------------------------------------------------------------------------

	/**
	 * Record a failed login attempt.
	 *
	 * Stores the username (sanitized), IP address, and timestamp.
	 * FIFO eviction keeps the list at or below MAX_LOGIN_ATTEMPTS.
	 *
	 * @since 1.0.0
	 *
	 * @param string $username The username used in the failed attempt.
	 *
	 * @return void
	 */
	public function on_login_failed( string $username ): void {
		$attempts = $this->get_raw_login_attempts();

		$attempts[] = array(
			'type'     => 'failed',
			'username' => sanitize_user( $username ),
			'ip'       => $this->get_client_ip(),
			'time'     => time(),
		);

		// FIFO eviction — keep most recent MAX_LOGIN_ATTEMPTS entries.
		if ( count( $attempts ) > self::MAX_LOGIN_ATTEMPTS ) {
			$attempts = array_slice( $attempts, -self::MAX_LOGIN_ATTEMPTS );
		}

		update_option( self::OPT_LOGIN_ATTEMPTS, $attempts );

		wp_claw_log(
			'Failed login attempt recorded.',
			'warning',
			array(
				'username' => sanitize_user( $username ),
				'ip'       => $this->get_client_ip(),
			)
		);
	}

	/**
	 * Log a successful login event.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $user_login The logged-in username.
	 * @param \WP_User $user       The WP_User object.
	 *
	 * @return void
	 */
	public function on_wp_login( string $user_login, \WP_User $user ): void {
		wp_claw_log(
			'Successful login.',
			'info',
			array(
				'user_id'    => $user->ID,
				'user_login' => sanitize_user( $user_login ),
				'ip'         => $this->get_client_ip(),
			)
		);
	}

	/**
	 * Deploy stored security headers on every front-end response.
	 *
	 * Reads the header map from wp_options and emits each as an HTTP
	 * header via header(). Only fires when explicitly activated by the
	 * deploy_security_headers action. Headers are additive only — this
	 * method never removes existing headers.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function deploy_stored_headers(): void {
		if ( ! get_option( 'wp_claw_security_headers_active', false ) ) {
			return;
		}

		$headers = get_option( self::OPT_SECURITY_HEADERS, array() );

		if ( ! is_array( $headers ) || empty( $headers ) ) {
			return;
		}

		foreach ( $headers as $name => $value ) {
			$clean_name  = sanitize_text_field( (string) $name );
			$clean_value = sanitize_text_field( (string) $value );

			if ( '' !== $clean_name && '' !== $clean_value ) {
				header( "{$clean_name}: {$clean_value}" );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers.
	// -------------------------------------------------------------------------

	/**
	 * Add an IP address to the blocked list.
	 *
	 * Validates the IP with FILTER_VALIDATE_IP before storing. The
	 * blocked list is stored in wp_options as a plain PHP array and
	 * is read by the request-interception hook (when enabled).
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { ip: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_block_ip( array $params ) {
		$ip = isset( $params['ip'] ) ? sanitize_text_field( wp_unslash( $params['ip'] ) ) : '';

		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error(
				'wp_claw_security_invalid_ip',
				__( 'A valid IP address is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$blocked = $this->get_blocked_ips();

		if ( in_array( $ip, $blocked, true ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'ip'      => $ip,
					'already' => true,
				),
			);
		}

		$blocked[] = $ip;
		update_option( self::OPT_BLOCKED_IPS, $blocked );

		wp_claw_log( 'IP address blocked.', 'warning', array( 'ip' => $ip ) );

		return array(
			'success' => true,
			'data'    => array(
				'ip'            => $ip,
				'blocked_total' => count( $blocked ),
			),
		);
	}

	/**
	 * Store security header configuration.
	 *
	 * The agent provides an associative array of HTTP header names and
	 * values. These are stored and applied via the 'send_headers' hook.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { headers: array<string,string> }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_security_headers( array $params ) {
		if ( empty( $params['headers'] ) || ! is_array( $params['headers'] ) ) {
			return new \WP_Error(
				'wp_claw_security_missing_headers',
				__( 'headers must be a non-empty associative array.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$sanitized = array();
		foreach ( $params['headers'] as $name => $value ) {
			$clean_name  = sanitize_text_field( wp_unslash( (string) $name ) );
			$clean_value = sanitize_text_field( wp_unslash( (string) $value ) );
			if ( '' !== $clean_name ) {
				$sanitized[ $clean_name ] = $clean_value;
			}
		}

		if ( empty( $sanitized ) ) {
			return new \WP_Error(
				'wp_claw_security_invalid_headers',
				__( 'No valid header entries after sanitization.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		update_option( self::OPT_SECURITY_HEADERS, $sanitized );

		wp_claw_log( 'Security headers configuration updated.', 'info', array( 'count' => count( $sanitized ) ) );

		return array(
			'success' => true,
			'data'    => array(
				'headers_stored' => count( $sanitized ),
			),
		);
	}

	/**
	 * Log a security event via the WP-Claw logger.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { message: string, level?: string, context?: array }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_log_security_event( array $params ) {
		$message = isset( $params['message'] ) ? sanitize_text_field( wp_unslash( $params['message'] ) ) : '';
		if ( '' === $message ) {
			return new \WP_Error(
				'wp_claw_security_missing_message',
				__( 'message parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$level   = isset( $params['level'] ) ? sanitize_text_field( wp_unslash( $params['level'] ) ) : 'warning';
		$context = isset( $params['context'] ) && is_array( $params['context'] ) ? $params['context'] : array();

		// Sanitize context values to prevent log injection.
		$safe_context = array_map( 'sanitize_text_field', array_map( 'wp_unslash', array_map( 'strval', $context ) ) );

		wp_claw_log( $message, $level, $safe_context );

		return array(
			'success' => true,
			'data'    => array(
				'message' => $message,
				'level'   => $level,
			),
		);
	}

	/**
	 * Record the timestamp of a file integrity scan request.
	 *
	 * Actual scanning (WP-CLI or external) is delegated to the Sentinel agent.
	 * This method records the trigger time in wp_options so the state
	 * snapshot stays accurate.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function action_run_file_integrity_check(): array {
		$timestamp = current_time( 'mysql', true );
		update_option( self::OPT_LAST_SCAN, $timestamp );

		wp_claw_log( 'File integrity check triggered.', 'info' );

		return array(
			'success' => true,
			'data'    => array(
				'triggered_at' => $timestamp,
				'note'         => 'Scan delegated to Sentinel agent via Klawty task queue.',
			),
		);
	}

	/**
	 * Enable brute-force protection settings.
	 *
	 * Stores the configuration (max attempts, lockout duration) that
	 * downstream hooks and scheduled tasks use when evaluating login events.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { max_attempts?: int, lockout_minutes?: int }.
	 *
	 * @return array
	 */
	private function action_enable_brute_force_protection( array $params ) {
		$max_attempts    = isset( $params['max_attempts'] ) ? absint( $params['max_attempts'] ) : 5;
		$lockout_minutes = isset( $params['lockout_minutes'] ) ? absint( $params['lockout_minutes'] ) : 30;

		// Sane bounds.
		$max_attempts    = max( 1, min( $max_attempts, 100 ) );
		$lockout_minutes = max( 5, min( $lockout_minutes, 1440 ) );

		update_option( 'wp_claw_brute_force_max_attempts', $max_attempts );
		update_option( 'wp_claw_brute_force_lockout_minutes', $lockout_minutes );
		update_option( 'wp_claw_brute_force_enabled', true );

		wp_claw_log(
			'Brute-force protection enabled.',
			'info',
			array(
				'max_attempts'    => $max_attempts,
				'lockout_minutes' => $lockout_minutes,
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'max_attempts'    => $max_attempts,
				'lockout_minutes' => $lockout_minutes,
				'enabled'         => true,
			),
		);
	}

	/**
	 * Store custom .htaccess security rule directives.
	 *
	 * Rules are stored in wp_options for use by the .htaccess writer
	 * (which wraps them in WP-Claw markers). Actual .htaccess writes
	 * are performed via insert_with_markers() to avoid corrupting
	 * existing WordPress rules.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { rules: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_htaccess_rules( array $params ) {
		if ( ! isset( $params['rules'] ) || '' === $params['rules'] ) {
			return new \WP_Error(
				'wp_claw_security_missing_rules',
				__( 'rules parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$rules = sanitize_textarea_field( wp_unslash( $params['rules'] ) );

		// Split into lines for insert_with_markers().
		$lines = array_map( 'trim', explode( "\n", $rules ) );
		$lines = array_filter( $lines );

		$htaccess_file = ABSPATH . '.htaccess';

		// Only attempt to write if the file is writable.
		if ( file_exists( $htaccess_file ) && wp_is_writable( $htaccess_file ) ) {
			insert_with_markers( $htaccess_file, 'WP-Claw Security', array_values( $lines ) );

			wp_claw_log( '.htaccess security rules written.', 'info', array( 'lines' => count( $lines ) ) );
		} else {
			wp_claw_log( '.htaccess not writable — rules stored in option only.', 'warning' );
		}

		// Always persist to option as the source of truth.
		update_option( 'wp_claw_htaccess_rules', $rules );

		return array(
			'success' => true,
			'data'    => array(
				'lines_stored'     => count( $lines ),
				'htaccess_written' => file_exists( $htaccess_file ) && wp_is_writable( $htaccess_file ),
			),
		);
	}

	/**
	 * Return recent login attempt records from the option store.
	 *
	 * Supports optional filtering by type ('failed'|'success') and
	 * a limit on the number of records returned.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { type?: string, limit?: int }.
	 *
	 * @return array
	 */
	private function action_get_login_attempts( array $params ): array {
		$attempts = $this->get_raw_login_attempts();

		// Optional type filter.
		if ( ! empty( $params['type'] ) ) {
			$type_filter = sanitize_key( $params['type'] );
			$attempts    = array_values(
				array_filter(
					$attempts,
					static function ( $a ) use ( $type_filter ) {
						return isset( $a['type'] ) && $a['type'] === $type_filter;
					}
				)
			);
		}

		// Optional limit — most recent records.
		$limit = isset( $params['limit'] ) ? absint( $params['limit'] ) : 100;
		$limit = max( 1, min( $limit, self::MAX_LOGIN_ATTEMPTS ) );

		if ( count( $attempts ) > $limit ) {
			$attempts = array_slice( $attempts, -$limit );
		}

		return array(
			'success' => true,
			'data'    => array(
				'attempts' => $attempts,
				'total'    => count( $attempts ),
			),
		);
	}

	/**
	 * Compute and store SHA-256 hashes for files in the given scope.
	 *
	 * Delegates to wp_claw_compute_file_hashes() for the actual scanning,
	 * then upserts each result into the wp_claw_file_hashes table.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { scope?: string }.
	 *
	 * @return array
	 */
	private function action_compute_file_hashes( array $params ): array {
		$allowed_scopes = array( 'core', 'plugin', 'theme', 'all' );
		$scope          = isset( $params['scope'] ) ? sanitize_key( $params['scope'] ) : 'all';

		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			$scope = 'all';
		}

		$hashes = wp_claw_compute_file_hashes( $scope );

		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_claw_file_hashes';
		$count      = 0;

		foreach ( $hashes as $file_path => $hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$abs_path  = ABSPATH . $file_path;
				$file_size = file_exists( $abs_path ) ? (int) filesize( $abs_path ) : 0;
				$wpdb->replace(
				$table_name,
				array(
					'file_path'  => sanitize_text_field( $file_path ),
					'file_hash'  => sanitize_text_field( $hash ),
					'file_size'  => $file_size,
					'scope'      => $scope,
					'status'     => 'clean',
					'checked_at' => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			++$count;
		}

		update_option( self::OPT_LAST_SCAN, current_time( 'mysql', true ) );

		wp_claw_log( 'File hashes computed and stored.', 'info', array( 'scope' => $scope, 'files' => $count ) );

		return array(
			'success' => true,
			'data'    => array(
				'scope'       => $scope,
				'files_hashed' => $count,
			),
		);
	}

	/**
	 * Compare current file hashes against the stored baseline.
	 *
	 * Delegates to wp_claw_compare_file_hashes() and returns the diff
	 * arrays (modified, new, deleted).
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { scope?: string }.
	 *
	 * @return array
	 */
	private function action_compare_file_hashes( array $params ): array {
		$allowed_scopes = array( 'core', 'plugin', 'theme', 'all' );
		$scope          = isset( $params['scope'] ) ? sanitize_key( $params['scope'] ) : 'all';

		if ( ! in_array( $scope, $allowed_scopes, true ) ) {
			$scope = 'all';
		}

		$diff = wp_claw_compare_file_hashes( $scope );

		return array(
			'success' => true,
			'data'    => array(
				'scope'    => $scope,
				'modified' => $diff['modified'],
				'new'      => $diff['new'],
				'deleted'  => $diff['deleted'],
			),
		);
	}

	/**
	 * Scan directories for malware patterns.
	 *
	 * Maps the directory parameter to real filesystem paths and delegates
	 * to wp_claw_scan_directory_for_malware(). Records the scan timestamp.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { directory?: string }.
	 *
	 * @return array
	 */
	private function action_scan_malware_patterns( array $params ): array {
		$allowed_dirs = array( 'plugins', 'themes', 'uploads', 'all' );
		$directory    = isset( $params['directory'] ) ? sanitize_key( $params['directory'] ) : 'all';

		if ( ! in_array( $directory, $allowed_dirs, true ) ) {
			$directory = 'all';
		}

		$dir_map = array(
			'plugins' => array( WP_PLUGIN_DIR ),
			'themes'  => array( get_theme_root() ),
			'uploads' => array( wp_upload_dir()['basedir'] ),
		);

		if ( 'all' === $directory ) {
			$paths = array_merge( $dir_map['plugins'], $dir_map['themes'], $dir_map['uploads'] );
		} else {
			$paths = $dir_map[ $directory ];
		}

		$all_matches = array();
		foreach ( $paths as $path ) {
			$matches = wp_claw_scan_directory_for_malware( $path );
			// Merge results, keyed by file path.
			foreach ( $matches as $file => $file_matches ) {
				$all_matches[ $file ] = $file_matches;
			}
		}

		update_option( 'wp_claw_last_malware_scan', current_time( 'mysql', true ) );

		wp_claw_log(
			'Malware pattern scan complete.',
			'info',
			array(
				'directory'      => $directory,
				'infected_files' => count( $all_matches ),
			)
		);

		// Send real-time alert if malware was found.
		if ( ! empty( $all_matches ) && class_exists( '\\WPClaw\\Notifications' ) ) {
			\WPClaw\Notifications::send_alert(
				'malware_found',
				array(
					'agent'   => 'sentinel',
					'details' => array(
						'directory'      => $directory,
						'infected_files' => count( $all_matches ),
						'files'          => array_keys( $all_matches ),
					),
				)
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'directory'      => $directory,
				'infected_files' => count( $all_matches ),
				'matches'        => $all_matches,
			),
		);
	}

	/**
	 * Quarantine a suspicious file.
	 *
	 * Delegates to wp_claw_quarantine_file() which enforces hard-coded
	 * path restrictions (wp-content/plugins/ and wp-content/themes/ only).
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { file_path: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_quarantine_file( array $params ) {
		if ( empty( $params['file_path'] ) ) {
			return new \WP_Error(
				'wp_claw_security_missing_file_path',
				__( 'file_path parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$file_path = sanitize_text_field( wp_unslash( $params['file_path'] ) );

		$result = wp_claw_quarantine_file( $file_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update the file_hashes table to mark the file as quarantined.
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_claw_file_hashes';
		$abspath    = untrailingslashit( ABSPATH );
		$rel_path   = ltrim( str_replace( $abspath, '', $result['original_path'] ), DIRECTORY_SEPARATOR );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array(
				'status'    => 'quarantined',
				'checked_at' => current_time( 'mysql', true ),
			),
			array( 'file_path' => $rel_path ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return array(
			'success' => true,
			'data'    => $result,
		);
	}

	/**
	 * Activate the deployment of stored security headers.
	 *
	 * Sets the wp_claw_security_headers_active option to true. The actual
	 * header emission happens in the send_headers hook callback.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_deploy_security_headers(): array {
		update_option( 'wp_claw_security_headers_active', true );

		$headers = get_option( self::OPT_SECURITY_HEADERS, array() );
		$count   = is_array( $headers ) ? count( $headers ) : 0;

		wp_claw_log( 'Security headers deployment activated.', 'info', array( 'header_count' => $count ) );

		return array(
			'success' => true,
			'data'    => array(
				'active'       => true,
				'header_count' => $count,
				'message'      => __( 'Security headers will be sent on every response.', 'claw-agent' ),
			),
		);
	}

	/**
	 * Check the SSL certificate status via the audit module.
	 *
	 * Delegates to the audit module's get_ssl_info action. Returns a
	 * WP_Error if the audit module is not available.
	 *
	 * @since 1.1.0
	 *
	 * @return array|\WP_Error
	 */
	private function action_check_ssl_certificate() {
		$plugin = \WPClaw\WP_Claw::get_instance();
		$audit  = $plugin->get_module( 'audit' );

		if ( ! $audit ) {
			return new \WP_Error(
				'wp_claw_audit_module_unavailable',
				__( 'The audit module is not available. Enable it to check SSL certificates.', 'claw-agent' ),
				array( 'status' => 503 )
			);
		}

		return $audit->handle_action( 'get_ssl_info', array() );
	}

	/**
	 * Retrieve all quarantined file records from the file_hashes table.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_quarantined_files(): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_claw_file_hashes';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is hardcoded plugin prefix, not user input.
			"SELECT file_path, file_hash, scope, status, checked_at FROM {$table_name} WHERE status = 'quarantined'",
			ARRAY_A
		);

		$files = is_array( $rows ) ? $rows : array();

		return array(
			'success' => true,
			'data'    => array(
				'quarantined_files' => $files,
				'total'             => count( $files ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Return the raw login attempts array from wp_options.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_raw_login_attempts(): array {
		$raw = get_option( self::OPT_LOGIN_ATTEMPTS, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Return the blocked IPs array from wp_options.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private function get_blocked_ips(): array {
		$raw = get_option( self::OPT_BLOCKED_IPS, array() );
		return is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array();
	}

	/**
	 * Return the client IP address.
	 *
	 * Prefers REMOTE_ADDR (authoritative) over forwarded headers.
	 * The value is filtered through FILTER_VALIDATE_IP; returns an
	 * empty string if the address cannot be determined.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return (string) $ip;
		}

		return '';
	}
}
