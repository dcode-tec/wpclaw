<?php
/**
 * Live Chat module.
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
 * Live Chat module — powers the frontend chat widget via the Concierge agent.
 *
 * Responsibilities:
 *  - Output the chat widget container div in the site footer.
 *  - Enqueue the chat widget CSS and JS with localised configuration.
 *  - Handle inbound agent actions: product catalog lookup, order status,
 *    knowledge base search, lead capture, and human escalation.
 *  - Store chat session tasks and CRM leads locally in wp_claw_tasks.
 *  - Report current state (today's sessions, FAQ count) for the sync cron.
 *
 * The Concierge is the only WP-Claw agent that interacts directly with
 * website visitors. All other agents work behind the scenes.
 *
 * @since 1.0.0
 */
class Module_Chat extends Module_Base {

	// -------------------------------------------------------------------------
	// Module_Base contract
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'chat';
	}

	/**
	 * Return the human-readable module name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Live Chat', 'wp-claw' );
	}

	/**
	 * Return the Klawty agent responsible for chat operations.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'concierge';
	}

	/**
	 * Return the actions this module exposes through the REST bridge.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'get_product_catalog',
			'get_order_status',
			'search_knowledge_base',
			'capture_chat_lead',
			'escalate_to_human',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * Routes to the appropriate internal handler. Returns a structured
	 * result array on success, or a WP_Error on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action name (must be in get_allowed_actions()).
	 * @param array  $params Parameters supplied by the agent.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'get_product_catalog':
				return $this->handle_get_product_catalog( $params );

			case 'get_order_status':
				return $this->handle_get_order_status( $params );

			case 'search_knowledge_base':
				return $this->handle_search_knowledge_base( $params );

			case 'capture_chat_lead':
				return $this->handle_capture_chat_lead( $params );

			case 'escalate_to_human':
				return $this->handle_escalate_to_human( $params );

			default:
				return new \WP_Error(
					'wp_claw_unknown_action',
					sprintf(
						/* translators: %s: Action name. */
						__( 'Unknown chat action: %s', 'wp-claw' ),
						esc_html( $action )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Register WordPress hooks for the chat module.
	 *
	 * Outputs the widget container in the frontend footer and enqueues
	 * the chat CSS/JS with configuration data. Hooks are registered
	 * unconditionally; rendering is gated at callback time.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_footer', array( $this, 'output_chat_widget' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_chat_assets' ) );
	}

	/**
	 * Return the current state of the chat module for the sync cron.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_tasks';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh state snapshot needed for sync; caching would give stale counts.

		// Chat sessions created today (all statuses).
		$sessions_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE module = %s AND DATE(created_at) = CURDATE()",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix is safe.
				'chat'
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$faq = (array) get_option( 'wp_claw_chat_faq', array() );

		return array(
			'chat_sessions_today' => $sessions_today,
			'faq_entry_count'     => count( $faq ),
		);
	}

	// -------------------------------------------------------------------------
	// WordPress hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Output the chat widget root container in the frontend footer.
	 *
	 * The React/vanilla JS widget attaches to this element. All config
	 * is embedded as a JSON-encoded data attribute to avoid a separate
	 * REST request on widget boot.
	 *
	 * Only renders when the module is active (guard via is_admin() inverse
	 * is handled by the module loader — this callback fires on the frontend).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function output_chat_widget(): void {
		$config = $this->get_widget_config();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode return value is safe; esc_attr applied directly.
		echo '<div id="wp-claw-chat-root" data-config="' . esc_attr( wp_json_encode( $config ) ) . '"></div>' . "\n";
	}

	/**
	 * Enqueue the chat widget CSS and JS.
	 *
	 * Assets are versioned with WP_CLAW_VERSION so browsers bust their
	 * cache automatically after plugin updates. Config is also passed via
	 * wp_localize_script as a fallback for frameworks that read window vars.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_chat_assets(): void {
		$version    = defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.0.0';
		$suffix     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$plugin_url = defined( 'WP_CLAW_PLUGIN_URL' ) ? WP_CLAW_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) );

		wp_enqueue_style(
			'wp-claw-chat',
			$plugin_url . 'public/css/wp-claw-chat' . $suffix . '.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'wp-claw-chat',
			$plugin_url . 'public/js/wp-claw-chat' . $suffix . '.js',
			array(),
			$version,
			true // load in footer
		);

		wp_localize_script(
			'wp-claw-chat',
			'wpClawChatConfig',
			$this->get_widget_config()
		);
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle the get_product_catalog action.
	 *
	 * When WooCommerce is active, returns up to 50 published products with
	 * id, name, price, permalink, and featured image URL. Falls back to
	 * the 20 most recent published posts when WooCommerce is not installed.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Currently unused; reserved for future filters.
	 *
	 * @return array|\WP_Error
	 */
	private function handle_get_product_catalog( array $params ) {
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' ) ) {
			return $this->get_woocommerce_catalog();
		}

		// WooCommerce not active — fall back to recent posts.
		return $this->get_recent_posts_catalog();
	}

	/**
	 * Build a product catalog from WooCommerce.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_woocommerce_catalog(): array {
		$products = wc_get_products(
			array(
				'limit'  => 50,
				'status' => 'publish',
				'return' => 'objects',
			)
		);

		$items = array();

		foreach ( $products as $product ) {
			if ( ! ( $product instanceof \WC_Product ) ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '';

			$items[] = array(
				'id'        => $product->get_id(),
				'name'      => $product->get_name(),
				'price'     => $product->get_price(),
				'permalink' => get_permalink( $product->get_id() ),
				'image'     => $image_url ? esc_url_raw( $image_url ) : '',
			);
		}

		return array(
			'success'  => true,
			'source'   => 'woocommerce',
			'count'    => count( $items ),
			'products' => $items,
		);
	}

	/**
	 * Build a product catalog from recent WordPress posts (WooCommerce fallback).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_recent_posts_catalog(): array {
		$posts = get_posts(
			array(
				'numberposts'      => 20,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}

			$thumbnail = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );

			$items[] = array(
				'id'        => $post->ID,
				'name'      => $post->post_title,
				'price'     => '',
				'permalink' => get_permalink( $post->ID ),
				'image'     => $thumbnail ? esc_url_raw( $thumbnail ) : '',
			);
		}

		return array(
			'success'  => true,
			'source'   => 'posts',
			'count'    => count( $items ),
			'products' => $items,
		);
	}

	/**
	 * Handle the get_order_status action.
	 *
	 * Requires WooCommerce. Returns the order's status, dates, and line items
	 * for the given order_id. Returns WP_Error if WooCommerce is not active
	 * or the order cannot be found.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type int $order_id Required. WooCommerce order ID.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_get_order_status( array $params ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error(
				'wp_claw_woocommerce_inactive',
				__( 'WooCommerce is not active. Order status is unavailable.', 'wp-claw' ),
				array( 'status' => 422 )
			);
		}

		$order_id = isset( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;

		if ( $order_id <= 0 ) {
			return new \WP_Error(
				'wp_claw_missing_order_id',
				__( 'order_id is required and must be a positive integer.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! ( $order instanceof \WC_Order ) ) {
			return new \WP_Error(
				'wp_claw_order_not_found',
				sprintf(
					/* translators: %d: Order ID. */
					__( 'Order #%d was not found.', 'wp-claw' ),
					$order_id
				),
				array( 'status' => 404 )
			);
		}

		// Build line items — do not expose customer PII.
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}

			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => $item->get_total(),
			);
		}

		return array(
			'success'       => true,
			'order_id'      => $order->get_id(),
			'status'        => $order->get_status(),
			'date_created'  => $order->get_date_created()
				? $order->get_date_created()->date( 'c' )
				: '',
			'date_modified' => $order->get_date_modified()
				? $order->get_date_modified()->date( 'c' )
				: '',
			'items'         => $items,
			'item_count'    => count( $items ),
		);
	}

	/**
	 * Handle the search_knowledge_base action.
	 *
	 * Performs a case-insensitive keyword search across FAQ question strings
	 * stored in the wp_claw_chat_faq option. Returns matching entries.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type string $query Required. Search keyword or phrase.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_search_knowledge_base( array $params ) {
		$query = isset( $params['query'] ) ? sanitize_text_field( (string) $params['query'] ) : '';

		if ( '' === $query ) {
			return new \WP_Error(
				'wp_claw_missing_query',
				__( 'A search query is required.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		$faq     = (array) get_option( 'wp_claw_chat_faq', array() );
		$matches = array();

		$query_lower = strtolower( $query );

		foreach ( $faq as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question = isset( $entry['question'] ) ? (string) $entry['question'] : '';
			$answer   = isset( $entry['answer'] ) ? (string) $entry['answer'] : '';

			if ( '' === $question ) {
				continue;
			}

			// Match if the query appears in the question or in the answer.
			if (
				false !== strpos( strtolower( $question ), $query_lower ) ||
				false !== strpos( strtolower( $answer ), $query_lower )
			) {
				$matches[] = array(
					'question' => sanitize_text_field( $question ),
					'answer'   => sanitize_textarea_field( $answer ),
				);
			}
		}

		return array(
			'success' => true,
			'query'   => $query,
			'count'   => count( $matches ),
			'results' => $matches,
		);
	}

	/**
	 * Handle the capture_chat_lead action.
	 *
	 * Sanitizes the visitor's name, email, and message, then stores the
	 * lead as a task record in the wp_claw_tasks table with module='crm'
	 * so the Commerce agent can follow up.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type string $name       Required. Visitor's name.
	 *   @type string $email      Required. Visitor's email address.
	 *   @type string $message    Optional. Inquiry message.
	 *   @type string $session_id Optional. Chat session identifier (anonymized).
	 * }
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function handle_capture_chat_lead( array $params ) {
		global $wpdb;

		// --- Sanitize inputs -------------------------------------------------
		$name  = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';

		if ( '' === $name ) {
			return new \WP_Error(
				'wp_claw_missing_name',
				__( 'Visitor name is required to capture a lead.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return new \WP_Error(
				'wp_claw_invalid_email',
				__( 'A valid email address is required to capture a lead.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		$message    = isset( $params['message'] ) ? sanitize_textarea_field( (string) $params['message'] ) : '';
		$session_id = isset( $params['session_id'] ) ? sanitize_text_field( (string) $params['session_id'] ) : '';

		// --- Persist as a CRM task ------------------------------------------
		$task_id = 'chat-lead-' . uniqid( '', true );
		$table   = $wpdb->prefix . 'wp_claw_tasks';
		$now     = current_time( 'mysql', true );

		$details = wp_json_encode(
			array(
				'name'       => $name,
				'email'      => $email,
				'message'    => $message,
				'session_id' => $session_id,
				'source'     => 'chat_widget',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- local task log insert; no caching required.
		$inserted = $wpdb->insert(
			$table,
			array(
				'task_id'    => $task_id,
				'agent'      => 'commerce',
				'module'     => 'crm',
				'action'     => 'capture_chat_lead',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_claw_log(
				'Chat: failed to insert CRM lead task.',
				'error',
				array(
					'email'    => $email,
					'db_error' => $wpdb->last_error,
				)
			);

			return new \WP_Error(
				'wp_claw_db_insert_failed',
				__( 'Failed to save chat lead to the database.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		wp_claw_log(
			'Chat: captured lead from chat widget.',
			'info',
			array(
				'task_id' => $task_id,
				'name'    => $name,
			)
		);

		return array(
			'success' => true,
			'task_id' => $task_id,
			'message' => __( 'Lead captured successfully.', 'wp-claw' ),
		);
	}

	/**
	 * Handle the escalate_to_human action.
	 *
	 * Marks the conversation for human follow-up by creating a high-priority
	 * task in the wp_claw_tasks table. The Commerce agent (or a human admin)
	 * will action it from the proposals queue.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type string $session_id Optional. Chat session identifier.
	 *   @type string $reason     Optional. Reason for escalation.
	 *   @type string $name       Optional. Visitor name.
	 *   @type string $email      Optional. Visitor email.
	 *   @type string $message    Optional. Last visitor message.
	 * }
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function handle_escalate_to_human( array $params ) {
		global $wpdb;

		$session_id = isset( $params['session_id'] ) ? sanitize_text_field( (string) $params['session_id'] ) : '';
		$reason     = isset( $params['reason'] ) ? sanitize_text_field( (string) $params['reason'] ) : '';
		$name       = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$email      = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$message    = isset( $params['message'] ) ? sanitize_textarea_field( (string) $params['message'] ) : '';

		$task_id = 'chat-escalation-' . uniqid( '', true );
		$table   = $wpdb->prefix . 'wp_claw_tasks';
		$now     = current_time( 'mysql', true );

		$details = wp_json_encode(
			array(
				'session_id' => $session_id,
				'reason'     => $reason,
				'name'       => $name,
				'email'      => $email,
				'message'    => $message,
				'source'     => 'chat_escalation',
				'priority'   => 'high',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- local task log insert; no caching required.
		$inserted = $wpdb->insert(
			$table,
			array(
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'escalate_to_human',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_claw_log(
				'Chat: failed to insert escalation task.',
				'error',
				array(
					'session_id' => $session_id,
					'db_error'   => $wpdb->last_error,
				)
			);

			return new \WP_Error(
				'wp_claw_db_insert_failed',
				__( 'Failed to record escalation request.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		wp_claw_log(
			'Chat: escalation to human recorded.',
			'info',
			array(
				'task_id'    => $task_id,
				'session_id' => $session_id,
			)
		);

		return array(
			'success' => true,
			'task_id' => $task_id,
			'message' => __( 'Your conversation has been flagged for human follow-up. The team will contact you shortly.', 'wp-claw' ),
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the widget configuration array passed to the frontend JS.
	 *
	 * All values are read from wp_options with safe defaults. The nonce
	 * is generated fresh on each page load so it is always valid.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *   @type string $position       Widget position: 'bottom-right' or 'bottom-left'.
	 *   @type string $accentColor    CSS hex color string.
	 *   @type string $welcomeMessage Welcome text shown in the widget header.
	 *   @type string $agentName      Display name shown in the widget header.
	 *   @type string $agentAvatar    URL of the agent avatar image (may be empty).
	 *   @type string $restUrl        Base REST API URL for the chat endpoint.
	 *   @type array  $businessHours  Business hours config array (may be empty).
	 *   @type string $nonce          Fresh wp_rest nonce.
	 * }
	 */
	private function get_widget_config(): array {
		$position = sanitize_text_field(
			(string) get_option( 'wp_claw_chat_position', 'bottom-right' )
		);

		// Constrain to known positions.
		if ( ! in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ) {
			$position = 'bottom-right';
		}

		$welcome = sanitize_text_field(
			(string) get_option( 'wp_claw_chat_welcome', __( 'Hi! How can I help you today?', 'wp-claw' ) )
		);

		$agent_name = sanitize_text_field(
			(string) get_option( 'wp_claw_chat_agent_name', 'Concierge' )
		);

		$agent_avatar = esc_url_raw(
			(string) get_option( 'wp_claw_chat_agent_avatar', '' )
		);

		$accent_color = sanitize_hex_color(
			(string) get_option( 'wp_claw_chat_accent_color', '#2563EB' )
		);

		if ( ! $accent_color ) {
			$accent_color = '#2563EB';
		}

		$business_hours = (array) get_option( 'wp_claw_chat_business_hours', array() );

		return array(
			'position'       => $position,
			'accentColor'    => $accent_color,
			'welcomeMessage' => $welcome,
			'agentName'      => $agent_name,
			'agentAvatar'    => $agent_avatar,
			'restUrl'        => esc_url_raw( rest_url( 'wp-claw/v1/' ) ),
			'businessHours'  => $business_hours,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
		);
	}
}
