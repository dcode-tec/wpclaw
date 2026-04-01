<?php
/**
 * API Client for communication with Klawty instance.
 *
 * Provides a typed, circuit-breaker-protected HTTP client for all
 * communication between WP-Claw and the managed or self-hosted
 * Klawty AI agent instance. All HTTP calls use WordPress-native
 * wp_remote_*() functions. Credentials are never stored in plaintext.
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
 * HTTP client for the Klawty AI instance.
 *
 * Responsibilities:
 *  - Builds and signs every outbound request (HMAC-SHA256 timestamp signature).
 *  - Routes to the correct Klawty URL depending on connection mode.
 *  - Retries 5xx errors up to 2 times with exponential back-off.
 *  - Implements an exponential-cooldown circuit breaker backed by
 *    WordPress transients (no extra database tables required).
 *  - Sanitizes every decoded response via wp_claw_sanitize_api_response().
 *
 * All public methods return an associative array on success or a
 * WP_Error object on failure. Callers should always check is_wp_error().
 *
 * @since 1.0.0
 */
class API_Client {

	/**
	 * Transient key: consecutive failure counter.
	 *
	 * Stores an integer. Incremented by record_failure(), reset by
	 * record_success(). When it reaches CIRCUIT_THRESHOLD the circuit
	 * is opened and wp_claw_circuit_open_until is set.
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	const TRANSIENT_FAILURES = 'wp_claw_circuit_failures';

	/**
	 * Transient key: Unix timestamp when the circuit can close again.
	 *
	 * When this value is greater than time() every request short-circuits
	 * immediately without making an HTTP call.
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	const TRANSIENT_OPEN_UNTIL = 'wp_claw_circuit_open_until';

	/**
	 * Transient key: last successful health-check payload.
	 *
	 * Stored by health_check() on a 200 response. Read by is_connected()
	 * to verify the remote endpoint reported status "ok".
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	const TRANSIENT_LAST_HEALTH = 'wp_claw_last_health';

	/**
	 * Number of consecutive failures that trips the circuit breaker.
	 *
	 * @since  1.0.0
	 * @var    int
	 */
	const CIRCUIT_THRESHOLD = 3;

	/**
	 * Base URL of the Klawty instance (no trailing slash).
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $base_url;

	/**
	 * Decrypted API key used for request signing.
	 *
	 * Zeroed from memory as soon as possible during the request cycle
	 * by keeping usage scoped to request().
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $api_key;

	/**
	 * Connection mode: 'managed' or 'self-hosted'.
	 *
	 * @since  1.0.0
	 * @var    string
	 */
	private $connection_mode;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Instantiate the API client.
	 *
	 * Reads connection mode and API key from wp_options, decrypts the key,
	 * and resolves the correct base URL for the active connection mode.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->connection_mode = (string) get_option( 'wp_claw_connection_mode', 'managed' );
		$this->api_key         = wp_claw_decrypt( (string) get_option( 'wp_claw_api_key', '' ) );

		if ( 'self-hosted' === $this->connection_mode ) {
			$this->base_url = 'http://localhost:2508';
		} else {
			$this->base_url = rtrim( (string) get_option( 'wp_claw_instance_url', '' ), '/' );
		}
	}

	// -------------------------------------------------------------------------
	// Public API — task management
	// -------------------------------------------------------------------------

	/**
	 * Create a new task on the Klawty instance.
	 *
	 * Posts the supplied data payload to /api/tasks with a generous 30-second
	 * timeout to accommodate instances under high load.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Task payload. Expected keys: agent, module, action,
	 *                    priority, details. Unknown keys are passed through
	 *                    and sanitized server-side.
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function create_task( array $data ) {
		return $this->request( 'POST', '/api/tasks', $data, 30 );
	}

	/**
	 * Retrieve the current status of a task by its ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $task_id The task identifier returned by create_task().
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function get_task( string $task_id ) {
		$task_id = sanitize_text_field( $task_id );
		return $this->request( 'GET', '/api/tasks/' . rawurlencode( $task_id ) );
	}

	// -------------------------------------------------------------------------
	// Public API — agent + proposal management
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the full agent team status from the Klawty instance.
	 *
	 * Returns one status object per agent (current task, health, uptime,
	 * LLM cost today, task count).
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function get_agents() {
		return $this->request( 'GET', '/api/agents' );
	}

	/**
	 * Retrieve all pending proposals from the Klawty instance.
	 *
	 * Pending proposals are actions that require admin approval before the
	 * agent executes them (PROPOSE or CONFIRM tier).
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function get_proposals() {
		return $this->request( 'GET', '/api/proposals' );
	}

	/**
	 * Approve or reject a pending proposal.
	 *
	 * The $action parameter must be either 'approve' or 'reject'. The
	 * Klawty instance validates the value server-side; invalid actions
	 * will receive a 4xx error response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     The proposal identifier.
	 * @param string $action Either 'approve' or 'reject'.
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function resolve_proposal( string $id, string $action ) {
		$id     = sanitize_text_field( $id );
		$action = sanitize_text_field( $action );
		return $this->request(
			'POST',
			'/api/proposals/' . rawurlencode( $id ),
			array( 'action' => $action )
		);
	}

	// -------------------------------------------------------------------------
	// Public API — health + infrastructure
	// -------------------------------------------------------------------------

	/**
	 * Perform a health check against the Klawty instance.
	 *
	 * On a successful 200 response with status "ok" the response payload is
	 * cached in the wp_claw_last_health transient (TTL 70 seconds — slightly
	 * longer than the hourly cron so there is always a recent value).
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function health_check() {
		$response = $this->request( 'GET', '/api/health' );

		if ( ! is_wp_error( $response ) ) {
			// Cache the health payload for is_connected() to read.
			// TTL must outlast the hourly cron interval — 2h gives safe margin.
			set_transient( self::TRANSIENT_LAST_HEALTH, $response, 2 * HOUR_IN_SECONDS );
		}

		return $response;
	}

	/**
	 * Push a WordPress site state snapshot to the Klawty instance.
	 *
	 * Called by the state sync cron to give agents fresh context about the
	 * WordPress installation (plugin list, post counts, WooCommerce state, etc.).
	 *
	 * @since 1.0.0
	 *
	 * @param array $state Associative array of site state data.
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function sync_state( array $state ) {
		return $this->request( 'POST', '/api/state', $state );
	}

	/**
	 * Register WordPress event hooks with the Klawty instance.
	 *
	 * Informs the agent brain which WP hooks are active so it can react to
	 * save_post, woocommerce_order_status_changed, etc. without polling.
	 *
	 * @since 1.0.0
	 *
	 * @param array $hooks Associative array of hook name => webhook callback URL.
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function register_hooks( array $hooks ) {
		return $this->request( 'POST', '/api/hooks', $hooks );
	}

	// -------------------------------------------------------------------------
	// Public API — chat
	// -------------------------------------------------------------------------

	/**
	 * Forward a visitor chat message to the Concierge agent.
	 *
	 * The caller is responsible for sanitizing the message content before
	 * passing it here. Use wp_claw_sanitize_chat_message() for visitor input.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Chat payload. Expected keys: session_id, message,
	 *                    visitor_context (optional), product_context (optional).
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function send_chat_message( array $data ) {
		// Chat endpoints need a slightly longer timeout for LLM inference.
		return $this->request( 'POST', '/api/chat', $data, 25 );
	}

	// -------------------------------------------------------------------------
	// Public API — updates
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the message history for a visitor chat session.
	 *
	 * Forwards the session identifier to the Klawty instance and returns
	 * the ordered message list for that session. Intended for the chat widget
	 * to restore history on page reload.
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id The chat session identifier.
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function get_chat_history( string $session_id ) {
		$session_id = sanitize_text_field( $session_id );
		return $this->request( 'GET', '/api/chat/history?session_id=' . rawurlencode( $session_id ) );
	}

	/**
	 * Query the Klawty update endpoint for the latest plugin version.
	 *
	 * Appends the current plugin version, WordPress version, and PHP version
	 * as query parameters so the server can make version-specific decisions.
	 *
	 * @since 1.0.0
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	public function check_for_updates() {
		global $wp_version;

		$wp_ver     = isset( $wp_version ) ? $wp_version : 'unknown';
		$php_ver    = PHP_VERSION;
		$plugin_ver = defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : 'unknown';

		$endpoint = add_query_arg(
			array(
				'version' => rawurlencode( $plugin_ver ),
				'wp'      => rawurlencode( $wp_ver ),
				'php'     => rawurlencode( $php_ver ),
			),
			'/api/updates/check'
		);

		return $this->request( 'GET', $endpoint );
	}

	// -------------------------------------------------------------------------
	// Public API — connection status
	// -------------------------------------------------------------------------

	/**
	 * Check whether WP-Claw is actively connected to a healthy Klawty instance.
	 *
	 * Returns true only when:
	 *  1. The circuit breaker is closed (no recent failure streak).
	 *  2. The last health check returned status "ok".
	 *
	 * This method is intentionally read-only — it does not make a live HTTP
	 * request. Call health_check() to refresh the connection state.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if connected and healthy, false otherwise.
	 */
	public function is_connected(): bool {
		// --- Circuit breaker open? ------------------------------------------
		$open_until = (int) get_transient( self::TRANSIENT_OPEN_UNTIL );
		if ( $open_until > time() ) {
			return false;
		}

		// --- Last health check reported OK? ----------------------------------
		$last_health = get_transient( self::TRANSIENT_LAST_HEALTH );
		if ( ! is_array( $last_health ) ) {
			return false;
		}

		$status = isset( $last_health['status'] ) ? $last_health['status'] : '';
		return 'ok' === $status;
	}

	// -------------------------------------------------------------------------
	// Core HTTP handler
	// -------------------------------------------------------------------------

	/**
	 * Make a signed HTTP request to the Klawty instance.
	 *
	 * Implements the full request lifecycle:
	 *  1. Circuit breaker guard — rejects immediately when open.
	 *  2. URL assembly.
	 *  3. Body JSON-encoding.
	 *  4. HMAC-SHA256 request signing (timestamp . "." . body).
	 *  5. wp_remote_request() dispatch.
	 *  6. WP_Error propagation from the transport layer.
	 *  7. HTTP status handling (2xx / 429 / 5xx / other).
	 *  8. Automatic retry for 5xx with 1 s + 3 s back-off (max 2 retries).
	 *  9. JSON decode and null check.
	 * 10. Response sanitization via wp_claw_sanitize_api_response().
	 * 11. Circuit breaker feedback (success clears, failure increments).
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param string $method   HTTP verb: 'GET', 'POST'.
	 * @param string $endpoint API endpoint starting with '/'.
	 * @param array  $data     Request body data for POST requests.
	 * @param int    $timeout  Request timeout in seconds. Default 15.
	 *
	 * @return array|\WP_Error Sanitized response array on success, WP_Error on failure.
	 */
	private function request( string $method, string $endpoint, array $data = array(), int $timeout = 15 ) {

		// --- Step 1: Circuit breaker guard ----------------------------------
		$failures   = (int) get_transient( self::TRANSIENT_FAILURES );
		$open_until = (int) get_transient( self::TRANSIENT_OPEN_UNTIL );

		if ( $open_until > time() ) {
			$remaining = $open_until - time();
			wp_claw_log_warning(
				'Circuit breaker is open — skipping Klawty request.',
				array(
					'endpoint' => $endpoint,
					'open_for' => $remaining . 's',
					'failures' => $failures,
				)
			);
			return new \WP_Error(
				'wp_claw_circuit_open',
				__( 'Circuit breaker is open — Klawty instance temporarily unreachable.', 'claw-agent' )
			);
		}

		// --- Step 2: Build URL ----------------------------------------------
		if ( empty( $this->base_url ) ) {
			return new \WP_Error(
				'wp_claw_no_base_url',
				__( 'Klawty instance URL is not configured.', 'claw-agent' )
			);
		}

		$url = $this->base_url . $endpoint;

		// --- Step 3: Encode body --------------------------------------------
		$body = '';
		if ( 'POST' === strtoupper( $method ) || 'PUT' === strtoupper( $method ) ) {
			$encoded = wp_json_encode( $data );
			$body    = ( false !== $encoded ) ? $encoded : '{}';
		}

		// --- Step 4: Generate timestamp -------------------------------------
		$timestamp = (string) time();

		// --- Step 5: Generate HMAC signature --------------------------------
		// Signature = HMAC-SHA256( timestamp . "." . body, api_key )
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $this->api_key );

		// --- Step 6: Build headers ------------------------------------------
		$headers = array(
			'Content-Type'       => 'application/json',
			'Authorization'      => 'Bearer ' . $this->api_key,
			'X-WPClaw-Signature' => $signature,
			'X-WPClaw-Timestamp' => $timestamp,
		);

		// --- Step 7: Dispatch request (with 5xx retry loop) -----------------
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $timeout,
			'headers' => $headers,
			'body'    => $body,
		);

		$max_attempts = 3; // 1 original + 2 retries for 5xx
		$retry_delays = array( 0, 1, 3 ); // seconds before each attempt

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {

			// Wait between retries (never before the first attempt).
			if ( $attempt > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- not a security use case.
				sleep( $retry_delays[ $attempt ] );
				wp_claw_log(
					'Retrying Klawty request.',
					'info',
					array(
						'endpoint' => $endpoint,
						'attempt'  => $attempt + 1,
					)
				);
			}

			$response = wp_remote_request( $url, $args );

			// --- Step 8: Transport-level WP_Error ---------------------------
			if ( is_wp_error( $response ) ) {
				wp_claw_log_error(
					'wp_remote_request transport error.',
					array(
						'endpoint' => $endpoint,
						'error'    => $response->get_error_message(),
						'attempt'  => $attempt + 1,
					)
				);

				// Transport errors are not retried (the host may be down).
				$this->record_failure();
				return $response;
			}

			// --- Step 9: HTTP status handling -------------------------------
			$code = (int) wp_remote_retrieve_response_code( $response );

			// 2xx — success.
			if ( $code >= 200 && $code < 300 ) {
				$this->record_success();
				return $this->decode_and_sanitize( $response, $endpoint );
			}

			// 429 — rate limited.
			if ( 429 === $code ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$retry_after = $retry_after > 0 ? $retry_after : 60;

				wp_claw_log_warning(
					'Klawty returned 429 Rate Limited.',
					array(
						'endpoint'    => $endpoint,
						'retry_after' => $retry_after . 's',
					)
				);

				return new \WP_Error(
					'wp_claw_rate_limited',
					sprintf(
						/* translators: %d: retry delay in seconds */
						__( 'Klawty rate limit reached. Retry after %d seconds.', 'claw-agent' ),
						$retry_after
					),
					array( 'retry_after' => $retry_after )
				);
			}

			// 5xx — server error, eligible for retry.
			if ( $code >= 500 && $code < 600 ) {
				wp_claw_log_warning(
					'Klawty returned 5xx — will retry if attempts remain.',
					array(
						'endpoint' => $endpoint,
						'code'     => $code,
						'attempt'  => $attempt + 1,
					)
				);

				// If this was the last attempt, fall through to failure handling.
				if ( $attempt + 1 >= $max_attempts ) {
					$this->record_failure();
					return new \WP_Error(
						'wp_claw_server_error',
						sprintf(
							/* translators: %d: HTTP status code */
							__( 'Klawty returned server error %1$d after %2$d attempts.', 'claw-agent' ),
							$code,
							$max_attempts
						),
						array( 'http_code' => $code )
					);
				}

				// Continue the retry loop.
				continue;
			}

			// 4xx or any other unexpected code.
			wp_claw_log_error(
				'Klawty returned unexpected HTTP status.',
				array(
					'endpoint' => $endpoint,
					'code'     => $code,
				)
			);

			return new \WP_Error(
				'wp_claw_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Unexpected status: %d', 'claw-agent' ),
					$code
				),
				array( 'http_code' => $code )
			);

		} // end retry loop

		// Should never be reached — the loop always returns.
		$this->record_failure();
		return new \WP_Error( 'wp_claw_unknown', __( 'Unknown error during Klawty request.', 'claw-agent' ) );
	}

	// -------------------------------------------------------------------------
	// Response processing
	// -------------------------------------------------------------------------

	/**
	 * Decode and sanitize a successful HTTP response body.
	 *
	 * Decodes the JSON body, validates it produced a non-null array,
	 * and passes it through wp_claw_sanitize_api_response(). Raw
	 * non-sanitized data is never returned to callers.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param array|\WP_Http_Response $response The raw WP HTTP response.
	 * @param string                  $endpoint Endpoint name used for log context.
	 *
	 * @return array|\WP_Error Sanitized array on success, WP_Error if JSON is invalid.
	 */
	private function decode_and_sanitize( $response, string $endpoint ) {
		$raw_body = wp_remote_retrieve_body( $response );

		// --- Step 10: JSON decode -------------------------------------------
		$decoded = json_decode( $raw_body, true );

		if ( null === $decoded || JSON_ERROR_NONE !== json_last_error() ) {
			wp_claw_log_error(
				'Klawty response is not valid JSON.',
				array(
					'endpoint'     => $endpoint,
					'json_error'   => json_last_error_msg(),
					'body_preview' => substr( $raw_body, 0, 200 ),
				)
			);

			return new \WP_Error(
				'wp_claw_invalid_json',
				__( 'Klawty returned an invalid JSON response.', 'claw-agent' )
			);
		}

		if ( ! is_array( $decoded ) ) {
			wp_claw_log_error(
				'Klawty JSON response is not an object or array.',
				array( 'endpoint' => $endpoint )
			);

			return new \WP_Error(
				'wp_claw_unexpected_response',
				__( 'Klawty returned an unexpected response format.', 'claw-agent' )
			);
		}

		// --- Step 11: Sanitize and return ------------------------------------
		return wp_claw_sanitize_api_response( $decoded );
	}

	// -------------------------------------------------------------------------
	// Circuit breaker
	// -------------------------------------------------------------------------

	/**
	 * Record a request failure and open the circuit if the threshold is reached.
	 *
	 * Failure count is stored in a transient so it persists across requests
	 * without requiring a database write on every call. When the failure count
	 * reaches CIRCUIT_THRESHOLD the circuit opens with an exponential cooldown:
	 *
	 *   cooldown = min( 300 * 2^(failures - threshold), 3600 ) seconds
	 *
	 * This gives a minimum cooldown of 5 minutes on the first trip and a
	 * maximum of 60 minutes regardless of how long the instance stays down.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private function record_failure(): void {
		$failures = (int) get_transient( self::TRANSIENT_FAILURES );
		++$failures;

		// Store the new failure count; use a long TTL so it outlives the cooldown.
		set_transient( self::TRANSIENT_FAILURES, $failures, HOUR_IN_SECONDS * 2 );

		if ( $failures >= self::CIRCUIT_THRESHOLD ) {
			// Exponential cooldown: 300s, 600s, 1200s … capped at 3600s.
			$exponent = $failures - self::CIRCUIT_THRESHOLD;
			$cooldown = (int) min( 300 * pow( 2, $exponent ), 3600 );

			set_transient( self::TRANSIENT_OPEN_UNTIL, time() + $cooldown, $cooldown + 60 );

			wp_claw_log_error(
				'Circuit breaker opened — Klawty unreachable.',
				array(
					'failures'         => $failures,
					'cooldown_seconds' => $cooldown,
					'open_until'       => gmdate( 'Y-m-d H:i:s', time() + $cooldown ) . ' UTC',
				)
			);
		} else {
			wp_claw_log_warning(
				'Klawty request failed — circuit breaker failure count incremented.',
				array(
					'failures'  => $failures,
					'threshold' => self::CIRCUIT_THRESHOLD,
				)
			);
		}
	}

	/**
	 * Record a successful request and reset the circuit breaker.
	 *
	 * Deletes both failure-tracking transients so the next failure streak
	 * starts fresh. This ensures a single successful request after a
	 * degraded period fully restores normal operation.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @return void
	 */
	private function record_success(): void {
		$had_failures = (bool) get_transient( self::TRANSIENT_FAILURES );

		delete_transient( self::TRANSIENT_FAILURES );
		delete_transient( self::TRANSIENT_OPEN_UNTIL );

		if ( $had_failures ) {
			wp_claw_log(
				'Circuit breaker reset — Klawty connection restored.',
				'info'
			);
		}
	}
}
