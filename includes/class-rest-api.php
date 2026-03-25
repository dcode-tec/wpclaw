<?php
/**
 * WP REST API endpoints for WP-Claw.
 *
 * Registers all wp-claw/v1 REST routes. Inbound requests from the Klawty
 * agent instance are authenticated via HMAC-SHA256 signature verification.
 * Public routes (chat, analytics) use per-session / per-IP rate limiting
 * backed by WordPress transients. Proposal approval routes require the
 * wp_claw_approve_proposals capability.
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
 * REST API bridge class.
 *
 * Registers the wp-claw/v1 namespace and all endpoints. Handles:
 *  - Inbound agent actions (/execute, /state, /webhook)
 *  - Public visitor chat (/chat/send, /chat/history)
 *  - Privacy-first analytics (/analytics)
 *  - Admin proposal approvals/rejections (/proposals/{id}/approve|reject)
 *
 * @since 1.0.0
 */
class REST_API {

	/**
	 * REST API namespace.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const NAMESPACE = 'wp-claw/v1';

	/**
	 * Maximum age in seconds for a signed request (replay-attack window).
	 *
	 * Requests with a timestamp older than this value are rejected.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const SIGNATURE_TTL = 300;

	/**
	 * Maximum allowed request body size for analytics events (bytes).
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const ANALYTICS_MAX_BODY = 2048;

	/**
	 * Chat rate limit: maximum messages per minute per session.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const CHAT_RATE_LIMIT = 20;

	/**
	 * API client instance.
	 *
	 * @since 1.0.0
	 * @var   API_Client
	 */
	private $api_client;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Constructor.
	 *
	 * Stores the shared API client and hooks route registration onto rest_api_init.
	 *
	 * @since 1.0.0
	 *
	 * @param API_Client $api_client The Klawty API client instance.
	 */
	public function __construct( API_Client $api_client ) {
		$this->api_client = $api_client;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	/**
	 * Register all wp-claw/v1 REST routes.
	 *
	 * Called on the rest_api_init action. Routes are grouped by:
	 *  - Signed agent routes (execute, state, webhook)
	 *  - Public frontend routes (chat/send, chat/history, analytics)
	 *  - Authenticated admin routes (proposals)
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		// --- Agent routes (HMAC-signed) --------------------------------------

		register_rest_route(
			self::NAMESPACE,
			'/execute',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_execute' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/state',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_state' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/webhook',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_signature' ),
			)
		);

		// --- Public frontend routes (rate-limited, no auth) ------------------

		register_rest_route(
			self::NAMESPACE,
			'/chat/send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chat_send' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/chat/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_chat_history' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/analytics',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_analytics' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- Command Center routes (capability-gated) -------------------------

		register_rest_route(
			self::NAMESPACE,
			'/command/setup-pin',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_setup_pin' ),
				'permission_callback' => function () {
					return current_user_can( 'wp_claw_command_center' );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/command',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_command' ),
				'permission_callback' => function () {
					return current_user_can( 'wp_claw_command_center' );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/command/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_command_history' ),
				'permission_callback' => function () {
					return current_user_can( 'wp_claw_command_center' );
				},
			)
		);

		// --- Admin proposal routes (capability-gated) ------------------------

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<id>[\w-]+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_proposal_approve' ),
				'permission_callback' => function () {
					return current_user_can( 'wp_claw_approve_proposals' );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_proposal_id' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<id>[\w-]+)/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_proposal_reject' ),
				'permission_callback' => function () {
					return current_user_can( 'wp_claw_approve_proposals' );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => array( $this, 'validate_proposal_id' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Verify the HMAC-SHA256 request signature from the Klawty instance.
	 *
	 * Checks the X-WPClaw-Signature and X-WPClaw-Timestamp headers.
	 * Rejects requests where the timestamp is more than SIGNATURE_TTL seconds
	 * old (replay protection). Compares signatures using hash_equals() to
	 * prevent timing attacks.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return true|\WP_Error True when the signature is valid, WP_Error with status 403 otherwise.
	 */
	public function verify_signature( \WP_REST_Request $request ) {
		$signature = $request->get_header( 'x-wpclaw-signature' );
		$timestamp = $request->get_header( 'x-wpclaw-timestamp' );

		// Both headers are required.
		if ( empty( $signature ) || empty( $timestamp ) ) {
			wp_claw_log_warning(
				'REST signature verification failed: missing headers.',
				array( 'path' => $request->get_route() )
			);
			return new \WP_Error(
				'wp_claw_missing_signature',
				__( 'Request signature headers are missing.', 'claw-agent' ),
				array( 'status' => 403 )
			);
		}

		// Timestamp must be a numeric string.
		$ts = (int) $timestamp;
		if ( (string) $ts !== (string) $timestamp || $ts <= 0 ) {
			wp_claw_log_warning(
				'REST signature verification failed: invalid timestamp format.',
				array( 'path' => $request->get_route() )
			);
			return new \WP_Error(
				'wp_claw_invalid_timestamp',
				__( 'Request timestamp is invalid.', 'claw-agent' ),
				array( 'status' => 403 )
			);
		}

		// Replay protection: reject if timestamp is too old or too far in the future.
		$age = abs( time() - $ts );
		if ( $age > self::SIGNATURE_TTL ) {
			wp_claw_log_warning(
				'REST signature verification failed: timestamp outside replay window.',
				array(
					'path'        => $request->get_route(),
					'age_seconds' => $age,
					'ttl'         => self::SIGNATURE_TTL,
				)
			);
			return new \WP_Error(
				'wp_claw_signature_expired',
				__( 'Request timestamp is outside the allowed window.', 'claw-agent' ),
				array( 'status' => 403 )
			);
		}

		// Retrieve and decrypt the stored API key.
		$api_key = wp_claw_decrypt( (string) get_option( 'wp_claw_api_key', '' ) );
		if ( empty( $api_key ) ) {
			wp_claw_log_warning(
				'REST signature verification failed: API key not configured.',
				array( 'path' => $request->get_route() )
			);
			return new \WP_Error(
				'wp_claw_not_configured',
				__( 'WP-Claw is not connected to a Klawty instance.', 'claw-agent' ),
				array( 'status' => 403 )
			);
		}

		// Compute the expected signature: HMAC-SHA256( timestamp . "." . body, api_key ).
		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $api_key );

		// Constant-time comparison to prevent timing attacks.
		if ( ! hash_equals( $expected, $signature ) ) {
			wp_claw_log_warning(
				'REST signature verification failed: signature mismatch.',
				array( 'path' => $request->get_route() )
			);
			return new \WP_Error(
				'wp_claw_invalid_signature',
				__( 'Request signature is invalid.', 'claw-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Agent route handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle an inbound agent action execution request.
	 *
	 * Reads 'module' and 'action' from the JSON body, locates the module
	 * instance, validates the action against the module's allowlist, and
	 * dispatches the call. The task is logged to the local wp_claw_tasks table
	 * regardless of outcome.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_execute( \WP_REST_Request $request ) {
		global $wpdb;

		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : array();
		$module = isset( $body['module'] ) ? sanitize_key( (string) $body['module'] ) : '';
		$action = isset( $body['action'] ) ? sanitize_text_field( (string) $body['action'] ) : '';

		if ( empty( $module ) || empty( $action ) ) {
			return new \WP_Error(
				'wp_claw_missing_params',
				__( 'Both "module" and "action" are required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		// Resolve the module instance from the main plugin singleton.
		$plugin        = WP_Claw::get_instance();
		$module_object = $plugin->get_module( $module );

		if ( null === $module_object ) {
			wp_claw_log_warning(
				'handle_execute: module not found.',
				array(
					'module' => $module,
					'action' => $action,
				)
			);
			return new \WP_Error(
				'wp_claw_module_not_found',
				sprintf(
					/* translators: %s: module slug */
					__( 'Module "%s" is not registered or not enabled.', 'claw-agent' ),
					$module
				),
				array( 'status' => 404 )
			);
		}

		// Enforce the per-module action allowlist.
		$allowed_actions = $module_object->get_allowed_actions();
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			wp_claw_log_warning(
				'handle_execute: action not in allowlist.',
				array(
					'module' => $module,
					'action' => $action,
				)
			);
			return new \WP_Error(
				'wp_claw_action_not_allowed',
				sprintf(
					/* translators: 1: action name, 2: module slug */
					__( 'Action "%1$s" is not allowed for module "%2$s".', 'claw-agent' ),
					$action,
					$module
				),
				array( 'status' => 403 )
			);
		}

		// Extract remaining body keys as action parameters.
		$params = $body;
		unset( $params['module'], $params['action'] );

		// Dispatch the action to the module.
		$result = $module_object->handle_action( $action, $params );

		// Determine task status based on result.
		$task_status = is_wp_error( $result ) ? 'failed' : 'done';
		$task_id     = sanitize_text_field( (string) ( isset( $body['task_id'] ) ? $body['task_id'] : wp_generate_uuid4() ) );
		$agent       = sanitize_text_field( (string) ( isset( $body['agent'] ) ? $body['agent'] : $module_object->get_agent() ) );

		// Log the task to the local mirror table.
		$details_data = array(
			'action' => $action,
			'params' => $params,
		);
		if ( is_wp_error( $result ) ) {
			$details_data['error'] = $result->get_error_message();
		}

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional task log insert.
		$wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			array(
				'task_id'    => $task_id,
				'agent'      => $agent,
				'module'     => $module,
				'action'     => $action,
				'status'     => $task_status,
				'details'    => wp_json_encode( $details_data ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( '' !== $wpdb->last_error ) {
			wp_claw_log_warning(
				'handle_execute: failed to insert task log record.',
				array(
					'db_error' => $wpdb->last_error,
					'task_id'  => $task_id,
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'task_id' => $task_id,
				'result'  => $result,
			)
		);
	}

	/**
	 * Return a structured snapshot of the current WordPress site state.
	 *
	 * Agents use this to refresh their context: plugin list, post counts,
	 * WooCommerce status, and enabled module configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_state( \WP_REST_Request $request ) {
		$theme        = wp_get_theme();
		$active_theme = $theme instanceof \WP_Theme ? $theme->get( 'Name' ) : '';
		$post_counts  = wp_count_posts();
		$woocommerce  = null;

		if ( class_exists( 'WooCommerce' ) ) {
			$woocommerce = array(
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			);
		}

		$state = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'site_url'          => esc_url_raw( get_site_url() ),
			'active_theme'      => sanitize_text_field( (string) $active_theme ),
			'active_plugins'    => get_option( 'active_plugins', array() ),
			'post_counts'       => $post_counts,
			'woocommerce'       => $woocommerce,
			'wp_claw_version'   => defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : 'unknown',
			'enabled_modules'   => get_option( 'wp_claw_enabled_modules', array() ),
			'pending_proposals' => $this->count_pending_proposals(),
		);

		return rest_ensure_response( $state );
	}

	/**
	 * Handle an inbound webhook event from the Klawty instance.
	 *
	 * Updates the local task record if a task_id is present in the payload,
	 * then logs the event and acknowledges receipt.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		global $wpdb;

		$body       = $request->get_json_params();
		$body       = is_array( $body ) ? $body : array();
		$event_type = isset( $body['event'] ) ? sanitize_text_field( (string) $body['event'] ) : 'unknown';
		$task_id    = isset( $body['task_id'] ) ? sanitize_text_field( (string) $body['task_id'] ) : '';
		$status     = isset( $body['status'] ) ? sanitize_text_field( (string) $body['status'] ) : '';

		wp_claw_log(
			'Webhook received.',
			'info',
			array(
				'event'   => $event_type,
				'task_id' => $task_id,
			)
		);

		// Update the local task record if a valid task_id and status are provided.
		$allowed_statuses = array( 'pending', 'in_progress', 'review', 'done', 'failed', 'cancelled' );

		if ( ! empty( $task_id ) && ! empty( $status ) && in_array( $status, $allowed_statuses, true ) ) {
			$now = current_time( 'mysql', true );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional task log update.
			$wpdb->update(
				$wpdb->prefix . 'wp_claw_tasks',
				array(
					'status'     => $status,
					'updated_at' => $now,
				),
				array( 'task_id' => $task_id ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return rest_ensure_response( array( 'received' => true ) );
	}

	// -------------------------------------------------------------------------
	// Public frontend route handlers
	// -------------------------------------------------------------------------

	/**
	 * Forward a visitor chat message to the Concierge agent.
	 *
	 * Enforces a per-session rate limit of CHAT_RATE_LIMIT messages per minute
	 * via a WordPress transient. The message is sanitized before forwarding.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_chat_send( \WP_REST_Request $request ) {
		$body       = $request->get_json_params();
		$body       = is_array( $body ) ? $body : array();
		$session_id = isset( $body['session_id'] ) ? sanitize_text_field( (string) $body['session_id'] ) : '';
		$message    = isset( $body['message'] ) ? (string) $body['message'] : '';
		$page_url   = isset( $body['page_url'] ) ? esc_url_raw( (string) $body['page_url'] ) : '';

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'wp_claw_missing_session',
				__( 'A session_id is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $message ) ) {
			return new \WP_Error(
				'wp_claw_empty_message',
				__( 'Message cannot be empty.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		// --- Rate limit: CHAT_RATE_LIMIT messages per minute per session -----
		$rl_key   = 'wp_claw_chat_rl_' . md5( $session_id );
		$rl_count = (int) get_transient( $rl_key );

		if ( $rl_count >= self::CHAT_RATE_LIMIT ) {
			return new \WP_Error(
				'wp_claw_chat_rate_limited',
				__( 'Too many messages. Please wait before sending another.', 'claw-agent' ),
				array(
					'status'      => 429,
					'retry_after' => 60,
				)
			);
		}

		// Increment counter; set TTL to 60 s on first message, refresh otherwise.
		if ( 0 === $rl_count ) {
			set_transient( $rl_key, 1, 60 );
		} else {
			set_transient( $rl_key, $rl_count + 1, 60 );
		}

		// --- Sanitize and forward -------------------------------------------
		$sanitized_message = wp_claw_sanitize_chat_message( $message );

		$response = $this->api_client->send_chat_message(
			array(
				'session_id' => $session_id,
				'message'    => $sanitized_message,
				'page_url'   => $page_url,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_claw_log_error(
				'handle_chat_send: Klawty request failed.',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Retrieve the message history for a chat session.
	 *
	 * Forwards the session_id query parameter to Klawty and returns
	 * the messages array. Public — no authentication required.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_chat_history( \WP_REST_Request $request ) {
		$session_id = sanitize_text_field( (string) $request->get_param( 'session_id' ) );

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'wp_claw_missing_session',
				__( 'A session_id query parameter is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$response = $this->api_client->get_chat_history( $session_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Record a privacy-first analytics event.
	 *
	 * Rate limited to 1 event per second per IP address via a WordPress
	 * transient. No raw IP address is stored — the session hash is computed
	 * server-side from a combination of IP, User-Agent, and UTC date.
	 * No PII is written to the database.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_analytics( \WP_REST_Request $request ) {
		global $wpdb;

		// --- Body size guard (2 KB max) --------------------------------------
		if ( strlen( $request->get_body() ) > self::ANALYTICS_MAX_BODY ) {
			return new \WP_Error(
				'wp_claw_payload_too_large',
				__( 'Analytics payload exceeds the maximum allowed size.', 'claw-agent' ),
				array( 'status' => 413 )
			);
		}

		// --- IP-based rate limit: 1 event per second -------------------------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed immediately, never stored raw.
		$raw_ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
		$ip_hash = md5( $raw_ip );
		$rl_key  = 'wp_claw_analytics_rl_' . $ip_hash;

		if ( false !== get_transient( $rl_key ) ) {
			return new \WP_Error(
				'wp_claw_analytics_rate_limited',
				__( 'Analytics rate limit reached. Please wait before tracking another event.', 'claw-agent' ),
				array(
					'status'      => 429,
					'retry_after' => 1,
				)
			);
		}

		// --- Parse and validate body -----------------------------------------
		$body       = $request->get_json_params();
		$body       = is_array( $body ) ? $body : array();
		$raw_url    = isset( $body['page_url'] ) ? (string) $body['page_url'] : '';
		$raw_ref    = isset( $body['referrer'] ) ? (string) $body['referrer'] : '';
		$raw_event  = isset( $body['event_type'] ) ? (string) $body['event_type'] : 'pageview';
		$raw_device = isset( $body['device_type'] ) ? (string) $body['device_type'] : '';

		// page_url: required, valid URL, max 512 chars.
		if ( empty( $raw_url ) || mb_strlen( $raw_url ) > 512 ) {
			return new \WP_Error(
				'wp_claw_invalid_page_url',
				__( 'page_url is required and must not exceed 512 characters.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$page_url = esc_url_raw( $raw_url );
		if ( empty( $page_url ) ) {
			return new \WP_Error(
				'wp_claw_invalid_page_url',
				__( 'page_url is not a valid URL.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		// referrer: optional, max 512 chars.
		$referrer = '';
		if ( ! empty( $raw_ref ) ) {
			$referrer = mb_substr( esc_url_raw( $raw_ref ), 0, 512 );
		}

		// event_type: enum validation.
		$allowed_events = array( 'pageview', 'click', 'form_submit', 'purchase', 'scroll', 'custom' );
		$event_type     = in_array( $raw_event, $allowed_events, true ) ? $raw_event : 'pageview';

		// device_type: enum validation.
		$allowed_devices = array( 'desktop', 'mobile', 'tablet', '' );
		$device_type     = in_array( $raw_device, $allowed_devices, true ) ? $raw_device : '';

		// --- Compute session hash (server-side — no raw IP stored) -----------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed, never stored or output.
		$user_agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		$session_hash = hash(
			'sha256',
			$raw_ip . $user_agent . gmdate( 'Y-m-d' )
		);

		// --- Insert analytics event ------------------------------------------
		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional analytics insert.
		$wpdb->insert(
			$wpdb->prefix . 'wp_claw_analytics',
			array(
				'page_url'     => mb_substr( $page_url, 0, 512 ),
				'referrer'     => mb_substr( $referrer, 0, 512 ),
				'event_type'   => $event_type,
				'session_hash' => $session_hash,
				'device_type'  => $device_type,
				'created_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( '' !== $wpdb->last_error ) {
			wp_claw_log_error(
				'handle_analytics: failed to insert analytics record.',
				array( 'db_error' => $wpdb->last_error )
			);
		}

		// --- Set 1-second rate-limit transient ------------------------------
		set_transient( $rl_key, 1, 1 );

		return rest_ensure_response( array( 'tracked' => true ) );
	}

	// -------------------------------------------------------------------------
	// Proposal route handlers
	// -------------------------------------------------------------------------

	/**
	 * Approve a pending proposal.
	 *
	 * Forwards the approval to the Klawty instance and updates the local
	 * proposals table with the resolution. The approving user's ID is recorded.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_proposal_approve( \WP_REST_Request $request ) {
		return $this->resolve_proposal( $request, 'approved' );
	}

	/**
	 * Reject a pending proposal.
	 *
	 * Forwards the rejection to the Klawty instance and updates the local
	 * proposals table with the resolution. The rejecting user's ID is recorded.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	public function handle_proposal_reject( \WP_REST_Request $request ) {
		return $this->resolve_proposal( $request, 'rejected' );
	}

	// -------------------------------------------------------------------------
	// Command Center route handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle the PIN setup request.
	 *
	 * Validates and stores a bcrypt-hashed PIN for the Command Center.
	 * Requires the wp_claw_command_center capability.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response REST response (200 on success, 400 on validation failure).
	 */
	public function handle_setup_pin( \WP_REST_Request $request ): \WP_REST_Response {
		$pin = sanitize_text_field( (string) $request->get_param( 'pin' ) );

		$cc     = new Command_Center();
		$result = $cc->setup_pin( $pin );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * Handle a Command Center command submission.
	 *
	 * Runs all 7 security layers via Command_Center::validate_command(),
	 * then forwards the command to the Klawty instance. Logs the result
	 * and increments the rate limit counter on success.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response REST response (200 on success, 403 on validation failure, 500 on send error).
	 */
	public function handle_command( \WP_REST_Request $request ): \WP_REST_Response {
		$prompt = sanitize_textarea_field( (string) $request->get_param( 'prompt' ) );
		$pin    = sanitize_text_field( (string) $request->get_param( 'pin' ) );

		$cc = new Command_Center();

		// Validate all security layers.
		$validation = $cc->validate_command( $prompt, $pin );
		if ( ! $validation['allowed'] ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'error'   => implode( '. ', $validation['reasons'] ),
				),
				403
			);
		}

		// Send to Klawty.
		$result = $cc->send_command( $prompt );

		// Log the outcome.
		$cc->log_command(
			get_current_user_id(),
			$prompt,
			$result['success'] ? 'sent' : 'error',
			$result['success'] ? '' : ( isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'claw-agent' ) ),
			isset( $result['task_id'] ) ? $result['task_id'] : null
		);

		// Increment rate limit counter on success.
		if ( $result['success'] ) {
			$cc->increment_rate_limit( get_current_user_id() );
		}

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
	}

	/**
	 * Handle a Command Center history request.
	 *
	 * Returns the current user's command history (decrypted prompts).
	 * Accepts an optional 'limit' parameter (capped at 100).
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 *
	 * @return \WP_REST_Response REST response with the history array.
	 */
	public function handle_command_history( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = absint( $request->get_param( 'limit' ) );
		$limit = $limit > 0 ? min( $limit, 100 ) : 20;

		$cc      = new Command_Center();
		$history = $cc->get_history( $limit );

		return new \WP_REST_Response( array( 'history' => $history ), 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Shared resolution logic for approve/reject proposal routes.
	 *
	 * Sanitizes the proposal ID, calls the Klawty API, and updates the local
	 * proposals table with the new status, approving user ID, and resolved_at
	 * timestamp.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @param \WP_REST_Request $request    The incoming REST request.
	 * @param string           $resolution Either 'approved' or 'rejected'.
	 *
	 * @return \WP_REST_Response|\WP_Error REST response on success, WP_Error on failure.
	 */
	private function resolve_proposal( \WP_REST_Request $request, string $resolution ) {
		global $wpdb;

		$id = sanitize_text_field( (string) $request->get_param( 'id' ) );

		if ( empty( $id ) ) {
			return new \WP_Error(
				'wp_claw_missing_proposal_id',
				__( 'Proposal ID is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		// Forward to Klawty.
		$api_action = ( 'approved' === $resolution ) ? 'approve' : 'reject';
		$response   = $this->api_client->resolve_proposal( $id, $api_action );

		if ( is_wp_error( $response ) ) {
			wp_claw_log_error(
				'resolve_proposal: Klawty API call failed.',
				array(
					'proposal_id' => $id,
					'resolution'  => $resolution,
					'error'       => $response->get_error_message(),
				)
			);
			return $response;
		}

		// Update the local proposals table.
		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional proposal status update.
		$wpdb->update(
			$wpdb->prefix . 'wp_claw_proposals',
			array(
				'status'      => $resolution,
				'approved_by' => get_current_user_id(),
				'resolved_at' => $now,
			),
			array( 'proposal_id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' !== $wpdb->last_error ) {
			wp_claw_log_warning(
				'resolve_proposal: failed to update local proposals table.',
				array(
					'db_error'    => $wpdb->last_error,
					'proposal_id' => $id,
				)
			);
		}

		wp_claw_log(
			'Proposal resolved.',
			'info',
			array(
				'proposal_id' => $id,
				'resolution'  => $resolution,
				'user_id'     => get_current_user_id(),
			)
		);

		return rest_ensure_response(
			array(
				'success'     => true,
				'proposal_id' => $id,
				'resolution'  => $resolution,
			)
		);
	}

	/**
	 * Count the number of pending proposals in the local proposals table.
	 *
	 * @since  1.0.0
	 * @access private
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return int Number of pending proposals.
	 */
	private function count_pending_proposals(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lightweight scalar query.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wp_claw_proposals WHERE status = %s",
				'pending'
			)
		);

		return (int) $count;
	}

	/**
	 * Validate a proposal ID route parameter.
	 *
	 * Must be a non-empty string containing only word characters and hyphens,
	 * which matches the [\w-]+ route pattern.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw parameter value.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public function validate_proposal_id( $value ): bool {
		if ( ! is_string( $value ) || empty( $value ) ) {
			return false;
		}
		return (bool) preg_match( '/^[\w-]+$/', $value );
	}
}
