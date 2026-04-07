<?php
/**
 * Command Center security class.
 *
 * Implements a 7-layer security model for the Command Center feature,
 * which allows authorised users to send natural-language instructions to
 * the AI agent team via the Klawty instance.
 *
 * Security layers:
 *  1. Capability check        — wp_claw_command_center
 *  2. PIN verification        — bcrypt-hashed, 4-8 alphanumeric chars
 *  3. IP allowlist (optional) — comma-separated in wp_options
 *  4. Rate limiting           — transient-based, per user (10/hour, 30/day)
 *  5. Input validation        — length + sanitisation
 *  6. Audit trail             — every command logged (encrypted prompt)
 *  7. Sentinel review         — high-risk commands tagged on Klawty side
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
 * Command Center — 7-layer security gate + audit log.
 *
 * Usage:
 *   $cc   = new Command_Center();
 *   $gate = $cc->validate_command( $prompt, $pin );
 *   if ( $gate['allowed'] ) { $result = $cc->send_command( $prompt ); }
 *
 * @since 1.0.0
 */
class Command_Center {

	/**
	 * WordPress database abstraction instance.
	 *
	 * @since 1.0.0
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Maximum commands per hour per user.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const HOURLY_LIMIT = 10;

	/**
	 * Maximum commands per day per user.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const DAILY_LIMIT = 30;

	/**
	 * Maximum allowed command length in characters.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const MAX_PROMPT_LENGTH = 2000;

	/**
	 * Minimum allowed command length in characters.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const MIN_PROMPT_LENGTH = 3;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Constructor.
	 *
	 * Stores a reference to the global $wpdb instance.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 */
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	// -------------------------------------------------------------------------
	// PIN Management
	// -------------------------------------------------------------------------

	/**
	 * Check whether a Command Center PIN has been configured.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if a hashed PIN is stored in options.
	 */
	public static function is_pin_set(): bool {
		return (bool) get_option( 'wp_claw_command_pin', '' );
	}

	/**
	 * Set (or overwrite) the Command Center PIN.
	 *
	 * Validates the PIN against length and character rules, then stores a
	 * bcrypt hash via wp_hash_password(). Logs the setup event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $pin The plaintext PIN to hash and store.
	 *
	 * @return array{success: bool, error?: string} Result array.
	 */
	public function setup_pin( string $pin ): array {
		// Validate: 4-8 characters.
		if ( strlen( $pin ) < 4 || strlen( $pin ) > 8 ) {
			return array(
				'success' => false,
				'error'   => __( 'PIN must be 4-8 characters.', 'claw-agent' ),
			);
		}

		// Validate: alphanumeric only.
		if ( ! ctype_alnum( $pin ) ) {
			return array(
				'success' => false,
				'error'   => __( 'PIN must be alphanumeric only.', 'claw-agent' ),
			);
		}

		$hash = wp_hash_password( $pin );
		update_option( 'wp_claw_command_pin', $hash );

		$this->log_command( get_current_user_id(), 'PIN configured', 'setup', '', null );

		return array( 'success' => true );
	}

	/**
	 * Verify a plaintext PIN against the stored bcrypt hash.
	 *
	 * @since 1.0.0
	 *
	 * @param string $pin The plaintext PIN to verify.
	 *
	 * @return bool True if the PIN matches.
	 */
	public function verify_pin( string $pin ): bool {
		$hash = get_option( 'wp_claw_command_pin', '' );

		if ( ! $hash ) {
			return false;
		}

		return wp_check_password( $pin, $hash );
	}

	// -------------------------------------------------------------------------
	// Security Validation (all 7 layers)
	// -------------------------------------------------------------------------

	/**
	 * Run all security layers against a command.
	 *
	 * Layers checked:
	 *  1. wp_claw_command_center capability
	 *  2. PIN verification (bcrypt)
	 *  3. IP allowlist (optional — skipped when empty)
	 *  4. Rate limiting (10/hour, 30/day per user)
	 *  5. Input validation (length)
	 *  — Layers 6 (audit) and 7 (Sentinel) are handled externally.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prompt The raw command text.
	 * @param string $pin    The plaintext PIN for verification.
	 *
	 * @return array{allowed: bool, reasons: string[]} Validation result.
	 */
	public function validate_command( string $prompt, string $pin ): array {
		$user_id = get_current_user_id();
		$ip      = $this->get_client_ip();
		$reasons = array();

		// Layer 1: Capability.
		if ( ! current_user_can( 'wp_claw_command_center' ) ) {
			$reasons[] = __( 'Missing wp_claw_command_center capability', 'claw-agent' );
		}

		// Layer 2: PIN.
		if ( ! $this->verify_pin( $pin ) ) {
			$reasons[] = __( 'Invalid PIN', 'claw-agent' );
			$this->log_command( $user_id, $prompt, 'blocked', 'Invalid PIN', null );
		}

		// Layer 3: IP allowlist (optional).
		$allowlist = get_option( 'wp_claw_command_ip_allowlist', '' );
		if ( ! empty( $allowlist ) ) {
			$allowed_ips = array_map( 'trim', explode( ',', $allowlist ) );
			if ( ! in_array( $ip, $allowed_ips, true ) ) {
				/* translators: %s: client IP address */
				$reasons[] = sprintf( __( 'IP not in allowlist: %s', 'claw-agent' ), $ip );
			}
		}

		// Layer 4: Rate limit.
		$rate = $this->check_rate_limit( $user_id );
		if ( $rate['limited'] ) {
			$reasons[] = sprintf(
				/* translators: 1: hourly count, 2: hourly limit, 3: daily count, 4: daily limit */
				__( 'Rate limit exceeded (%1$d/%2$d hourly, %3$d/%4$d daily)', 'claw-agent' ),
				$rate['hourly'],
				self::HOURLY_LIMIT,
				$rate['daily'],
				self::DAILY_LIMIT
			);
		}

		// Layer 5: Input validation.
		$prompt = trim( $prompt );
		if ( strlen( $prompt ) < self::MIN_PROMPT_LENGTH ) {
			$reasons[] = __( 'Command too short', 'claw-agent' );
		}
		if ( strlen( $prompt ) > self::MAX_PROMPT_LENGTH ) {
			/* translators: %d: maximum character count */
			$reasons[] = sprintf( __( 'Command too long (max %d chars)', 'claw-agent' ), self::MAX_PROMPT_LENGTH );
		}

		// If any layer failed, log a combined failure (unless PIN failure already logged).
		if ( ! empty( $reasons ) ) {
			$combined = implode( ', ', $reasons );

			if ( false === strpos( $combined, 'Invalid PIN' ) ) {
				// Log non-PIN failures (PIN failures were already logged above).
				$this->log_command( $user_id, $prompt, 'blocked', implode( '; ', $reasons ), null );
			}

			return array(
				'allowed' => false,
				'reasons' => $reasons,
			);
		}

		return array(
			'allowed' => true,
			'reasons' => array(),
		);
	}

	// -------------------------------------------------------------------------
	// Rate Limiting
	// -------------------------------------------------------------------------

	/**
	 * Check whether a user has exceeded the hourly or daily rate limit.
	 *
	 * Uses WordPress transients keyed by user ID. Does NOT increment
	 * counters — call increment_rate_limit() after a successful command.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param int $user_id The WordPress user ID.
	 *
	 * @return array{limited: bool, hourly: int, daily: int} Rate limit status.
	 */
	private function check_rate_limit( int $user_id ): array {
		$hourly_key = 'wp_claw_cc_hourly_' . $user_id;
		$daily_key  = 'wp_claw_cc_daily_' . $user_id;

		$hourly = (int) get_transient( $hourly_key );
		$daily  = (int) get_transient( $daily_key );

		return array(
			'limited' => $hourly >= self::HOURLY_LIMIT || $daily >= self::DAILY_LIMIT,
			'hourly'  => $hourly,
			'daily'   => $daily,
		);
	}

	/**
	 * Increment the rate limit counters for a user.
	 *
	 * Should be called after a command is successfully sent.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id The WordPress user ID.
	 *
	 * @return void
	 */
	public function increment_rate_limit( int $user_id ): void {
		$hourly_key = 'wp_claw_cc_hourly_' . $user_id;
		$daily_key  = 'wp_claw_cc_daily_' . $user_id;

		$hourly = (int) get_transient( $hourly_key );
		$daily  = (int) get_transient( $daily_key );

		set_transient( $hourly_key, $hourly + 1, HOUR_IN_SECONDS );
		set_transient( $daily_key, $daily + 1, DAY_IN_SECONDS );
	}

	// -------------------------------------------------------------------------
	// Send Command to Klawty
	// -------------------------------------------------------------------------

	/**
	 * Send a validated command to the Klawty instance.
	 *
	 * Creates a task for the orchestrator agent (Atlas/Architect) with the
	 * user's natural-language command. Callers MUST validate the command
	 * via validate_command() before calling this method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prompt The validated command text.
	 *
	 * @return array{success: bool, response?: string, error?: string, task_id?: string|null}
	 */
	public function send_command( string $prompt ): array {
		$user = wp_get_current_user();

		$api_client = new API_Client();

		// Build a task payload for the orchestrator agent.
		// The Klawty /api/tasks endpoint requires 'agent' + 'title' (not module/action).
		$task_data = array(
			'agent'       => 'atlas',
			'title'       => sprintf(
				/* translators: %s: The user's command text (truncated). */
				__( 'Command: %s', 'claw-agent' ),
				mb_strimwidth( $prompt, 0, 80, '...' )
			),
			'description' => sprintf(
				"User command from %s (%s) via Command Center:\n\n%s",
				$user->display_name,
				implode( ', ', $user->roles ),
				$prompt
			),
			'priority'    => 'high',
		);

		$response = $api_client->create_task( $task_data );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'task_id' => null,
			);
		}

		$task_id        = isset( $response['task_id'] ) ? $response['task_id'] : null;
		$agent_response = isset( $response['response'] )
			? $response['response']
			: __( 'Command received. Atlas will delegate to the appropriate agent.', 'claw-agent' );

		return array(
			'success'  => true,
			'response' => $agent_response,
			'task_id'  => $task_id,
		);
	}

	// -------------------------------------------------------------------------
	// Audit Log
	// -------------------------------------------------------------------------

	/**
	 * Log a command event to the wp_claw_command_log table.
	 *
	 * The prompt is encrypted before storage using the same sodium/AES
	 * pattern as the API key (see helpers/encryption.php).
	 *
	 * @since 1.0.0
	 *
	 * @param int         $user_id The WordPress user ID.
	 * @param string      $prompt  The command text (will be encrypted).
	 * @param string      $status  Event status: sent, blocked, error, setup.
	 * @param string      $reason  Failure/block reason (empty on success).
	 * @param string|null $task_id The Klawty task ID, if available.
	 *
	 * @return void
	 */
	public function log_command( int $user_id, string $prompt, string $status, string $reason, ?string $task_id ): void {
		$table = $this->db->prefix . 'wp_claw_command_log';
		$ip    = $this->get_client_ip();

		// Encrypt the prompt before storing.
		$encrypted_prompt = function_exists( 'wp_claw_encrypt' )
			? wp_claw_encrypt( $prompt )
			: $prompt;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional audit log insert.
		$this->db->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'ip_address' => sanitize_text_field( $ip ),
				'prompt'     => $encrypted_prompt,
				'status'     => sanitize_key( $status ),
				'reason'     => sanitize_text_field( $reason ),
				'task_id'    => $task_id ? sanitize_text_field( $task_id ) : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( '' !== $this->db->last_error ) {
			wp_claw_log_warning(
				'Command_Center::log_command: DB insert failed.',
				array(
					'db_error' => $this->db->last_error,
					'status'   => $status,
				)
			);
		}
	}

	/**
	 * Retrieve the command history for the current user.
	 *
	 * Decrypts prompts before returning them for display.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum number of records to return (capped at 100).
	 *
	 * @return array List of command log entries.
	 */
	public function get_history( int $limit = 20 ): array {
		$table   = $this->db->prefix . 'wp_claw_command_log';
		$user_id = get_current_user_id();
		$limit   = min( max( 1, $limit ), 100 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional audit log read.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT id, status, reason, task_id, created_at, prompt FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name uses wpdb prefix.
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $rows ) {
			return array();
		}

		// Decrypt prompts for display.
		return array_map(
			function ( $row ) {
				if ( function_exists( 'wp_claw_decrypt' ) ) {
					$decrypted     = wp_claw_decrypt( $row['prompt'] );
					$row['prompt'] = $decrypted ? $decrypted : '[encrypted]';
				}
				return $row;
			},
			$rows
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine the client's IP address.
	 *
	 * Checks the X-Forwarded-For header first (for clients behind a
	 * reverse proxy or load balancer), taking only the first (leftmost)
	 * IP. Falls back to REMOTE_ADDR.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip(): string {
		// Check forwarded header (behind proxy / load balancer).
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $ips[0] );  // First IP is the client.
		}

		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}
}
