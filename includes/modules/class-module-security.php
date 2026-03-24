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

			default:
				return new \WP_Error(
					'wp_claw_security_unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown Security action: %s', 'wp-claw' ),
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

		return array(
			'failed_logins_24h' => $recent_fails,
			'blocked_ips_count' => count( $blocked_ips ),
			'last_scan_time'    => get_option( self::OPT_LAST_SCAN, '' ),
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
				__( 'A valid IP address is required.', 'wp-claw' ),
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
				__( 'headers must be a non-empty associative array.', 'wp-claw' ),
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
				__( 'No valid header entries after sanitization.', 'wp-claw' ),
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
				__( 'message parameter is required.', 'wp-claw' ),
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
				__( 'rules parameter is required.', 'wp-claw' ),
				array( 'status' => 422 )
			);
		}

		$rules = sanitize_textarea_field( wp_unslash( $params['rules'] ) );

		// Split into lines for insert_with_markers().
		$lines = array_map( 'trim', explode( "\n", $rules ) );
		$lines = array_filter( $lines );

		$htaccess_file = ABSPATH . '.htaccess';

		// Only attempt to write if the file is writable.
		if ( file_exists( $htaccess_file ) && is_writable( $htaccess_file ) ) {
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
				'htaccess_written' => file_exists( $htaccess_file ) && is_writable( $htaccess_file ),
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
