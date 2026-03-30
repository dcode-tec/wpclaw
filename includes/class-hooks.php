<?php
/**
 * WordPress hook registry — listens for site events and queues agent tasks.
 *
 * Maps WordPress action hooks to the modules that care about them, registers
 * the callbacks, builds structured task payloads from hook arguments, and
 * batches task creation through a shutdown-time queue to avoid blocking the
 * current request with HTTP calls.
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
 * Registers WordPress event listeners and dispatches queued tasks at shutdown.
 *
 * Responsibilities:
 *  - Declare which WordPress hooks each module cares about (hook_map).
 *  - On register_hooks(), inspect enabled modules and register only the
 *    callbacks needed — no wasted listeners for disabled modules.
 *  - Build minimal, typed task payloads from the raw hook arguments.
 *  - Buffer tasks in a transient-backed queue and flush at wp shutdown to
 *    keep HTTP calls off the critical render path.
 *  - Process the queue with a 30-second transient lock so concurrent requests
 *    cannot double-submit tasks.
 *
 * @since 1.0.0
 */
class Hooks {

	/**
	 * Transient key for the pending task queue.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const TRANSIENT_QUEUE = 'wp_claw_task_queue';

	/**
	 * Transient key for the queue-flush mutex lock.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const TRANSIENT_LOCK = 'wp_claw_queue_lock';

	/**
	 * Duration in seconds the queue lock is held.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const LOCK_TTL = 30;

	/**
	 * Whether the shutdown processor has already been registered.
	 *
	 * Static flag prevents multiple add_action('shutdown') registrations
	 * when queue_task() is called more than once per request.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Map of WordPress hook names to the module slugs that handle them.
	 *
	 * Keys are WordPress action/filter names. Values are arrays of module
	 * slugs that should receive a task when that hook fires. Only hooks
	 * for which at least one listed module is enabled will be registered.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string[]>
	 */
	private static array $hook_map = array(
		'save_post'                        => array( 'seo', 'content' ),
		'publish_post'                     => array( 'seo', 'social' ),
		'wp_login_failed'                  => array( 'security' ),
		'wp_login'                         => array( 'security' ),
		'comment_post'                     => array( 'content' ),
		'woocommerce_order_status_changed' => array( 'commerce' ),
		'woocommerce_low_stock'            => array( 'commerce' ),
		'woocommerce_new_order'            => array( 'commerce' ),
		'wpforms_process_complete'         => array( 'crm' ),
		'gform_after_submission'           => array( 'crm' ),
		'wpcf7_mail_sent'                  => array( 'crm' ),
		'woocommerce_add_to_cart'              => array( 'commerce' ),
		'woocommerce_cart_updated'             => array( 'commerce' ),
		'woocommerce_checkout_order_processed' => array( 'commerce' ),
		'woocommerce_after_cart'               => array( 'analytics' ),
		'woocommerce_after_checkout_form'      => array( 'analytics' ),
		'woocommerce_thankyou'                 => array( 'analytics' ),
	);

	/**
	 * API client instance.
	 *
	 * Stored so process_queue() can access it via the singleton —
	 * but also kept here for future direct-dispatch paths.
	 *
	 * @since 1.0.0
	 *
	 * @var API_Client
	 */
	private API_Client $api_client;

	/**
	 * Constructor.
	 *
	 * Stores the API client. Hook registration is deferred to register_hooks()
	 * so it can inspect enabled modules after options are loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param API_Client $api_client The Klawty API client instance.
	 */
	public function __construct( API_Client $api_client ) {
		$this->api_client = $api_client;
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register WordPress action callbacks for all relevant hooks.
	 *
	 * Only hooks whose module list intersects with the enabled modules are
	 * registered. WooCommerce-specific hooks are also gated on WooCommerce
	 * being active to prevent PHP notices on sites without it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$enabled_modules = (array) get_option( 'wp_claw_enabled_modules', array() );

		if ( empty( $enabled_modules ) ) {
			return;
		}

		$woocommerce_hooks = array(
			'woocommerce_order_status_changed',
			'woocommerce_low_stock',
			'woocommerce_new_order',
			'woocommerce_add_to_cart',
			'woocommerce_cart_updated',
			'woocommerce_checkout_order_processed',
			'woocommerce_after_cart',
			'woocommerce_after_checkout_form',
			'woocommerce_thankyou',
		);

		foreach ( self::$hook_map as $hook_name => $module_slugs ) {
			// Determine which of the mapped modules are currently enabled.
			$active_slugs = array_intersect( $module_slugs, $enabled_modules );

			if ( empty( $active_slugs ) ) {
				continue;
			}

			// Gate WooCommerce hooks on WooCommerce being present.
			if ( in_array( $hook_name, $woocommerce_hooks, true ) && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}

			// Capture variables for the closure.
			$captured_hook    = $hook_name;
			$captured_modules = array_values( $active_slugs );

			add_action(
				$hook_name,
				function () use ( $captured_hook, $captured_modules ) {
					$args = func_get_args();
					$this->handle_hook( $captured_hook, $captured_modules, $args );
				},
				10,
				// Accept up to 6 arguments so we can extract context from rich hooks
				// (e.g. woocommerce_add_to_cart passes 6 args).
				6
			);
		}
	}

	// -------------------------------------------------------------------------
	// Hook handler
	// -------------------------------------------------------------------------

	/**
	 * Build a task payload from a fired hook and queue it for each module.
	 *
	 * The payload is tailored per hook type so agents receive the context
	 * they need without redundant data. Unknown hooks receive a minimal
	 * generic payload.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook_name The WordPress action name that fired.
	 * @param string[] $modules   Module slugs that should receive this event.
	 * @param array    $hook_args Raw arguments passed to the action callback.
	 *
	 * @return void
	 */
	private function handle_hook( string $hook_name, array $modules, array $hook_args ): void {
		foreach ( $modules as $module_slug ) {
			$task = $this->build_task( $hook_name, $module_slug, $hook_args );

			if ( null === $task ) {
				continue;
			}

			self::queue_task( $task );
		}
	}

	/**
	 * Build a typed task payload for the given hook and module.
	 *
	 * Returns null when the hook should be silently ignored (e.g. auto-saves,
	 * revisions, or unsupported post types). Each case extracts only the data
	 * the target agent will need, keeping payloads small.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_name   The WordPress action name.
	 * @param string $module_slug The destination module slug.
	 * @param array  $args        Raw action callback arguments.
	 *
	 * @return array|null Task data array, or null to skip queuing.
	 */
	private function build_task( string $hook_name, string $module_slug, array $args ): ?array {
		$base = array(
			'module' => $module_slug,
			'source' => 'hook',
			'hook'   => $hook_name,
		);

		switch ( $hook_name ) {
			case 'save_post':
			case 'publish_post':
				$post_id = isset( $args[0] ) ? (int) $args[0] : 0;

				if ( $post_id <= 0 ) {
					return null;
				}

				// Skip auto-saves and revisions — agents don't need to react to these.
				if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
					return null;
				}

				$post = get_post( $post_id );

				if ( ! $post instanceof \WP_Post ) {
					return null;
				}

				if ( 'revision' === $post->post_type ) {
					return null;
				}

				return array_merge(
					$base,
					array(
						'title'     => sprintf(
							/* translators: 1: Hook name. 2: Post title. */
							__( '%1$s event: %2$s', 'claw-agent' ),
							$hook_name,
							$post->post_title
						),
						'post_id'   => $post_id,
						'post_type' => $post->post_type,
						'post_slug' => $post->post_name,
					)
				);

			case 'wp_login_failed':
				$username = isset( $args[0] ) ? sanitize_user( (string) $args[0] ) : '';

				return array_merge(
					$base,
					array(
						'title'    => __( 'Failed login attempt detected', 'claw-agent' ),
						'username' => $username,
						// Never log the password — it's the second argument.
					)
				);

			case 'wp_login':
				$user_login = isset( $args[0] ) ? sanitize_user( (string) $args[0] ) : '';
				$user       = isset( $args[1] ) && $args[1] instanceof \WP_User ? $args[1] : null;

				return array_merge(
					$base,
					array(
						'title'      => __( 'User login event', 'claw-agent' ),
						'user_login' => $user_login,
						'user_roles' => $user ? (array) $user->roles : array(),
					)
				);

			case 'comment_post':
				$comment_id = isset( $args[0] ) ? (int) $args[0] : 0;

				if ( $comment_id <= 0 ) {
					return null;
				}

				return array_merge(
					$base,
					array(
						'title'      => __( 'New comment submitted', 'claw-agent' ),
						'comment_id' => $comment_id,
					)
				);

			case 'woocommerce_order_status_changed':
				$order_id   = isset( $args[0] ) ? (int) $args[0] : 0;
				$old_status = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';
				$new_status = isset( $args[2] ) ? sanitize_key( (string) $args[2] ) : '';

				if ( $order_id <= 0 ) {
					return null;
				}

				return array_merge(
					$base,
					array(
						'title'      => sprintf(
							/* translators: 1: Order ID. 2: New status. */
							__( 'Order #%1$d status changed to %2$s', 'claw-agent' ),
							$order_id,
							$new_status
						),
						'order_id'   => $order_id,
						'old_status' => $old_status,
						'new_status' => $new_status,
					)
				);

			case 'woocommerce_low_stock':
				$product    = isset( $args[0] ) ? $args[0] : null;
				$product_id = ( $product && method_exists( $product, 'get_id' ) )
					? (int) $product->get_id()
					: 0;

				return array_merge(
					$base,
					array(
						'title'      => __( 'Low stock alert', 'claw-agent' ),
						'product_id' => $product_id,
					)
				);

			case 'woocommerce_new_order':
				$order_id = isset( $args[0] ) ? (int) $args[0] : 0;

				if ( $order_id <= 0 ) {
					return null;
				}

				return array_merge(
					$base,
					array(
						'title'    => sprintf(
							/* translators: %d: Order ID. */
							__( 'New order received: #%d', 'claw-agent' ),
							$order_id
						),
						'order_id' => $order_id,
					)
				);

			case 'wpforms_process_complete':
			case 'gform_after_submission':
			case 'wpcf7_mail_sent':
				// Each form plugin passes form data in different positions and
				// structures. Extract only the hook name for the agent to act on;
				// the agent will fetch the submission data via WP REST if needed.
				return array_merge(
					$base,
					array(
						'title' => sprintf(
							/* translators: %s: Hook name. */
							__( 'Form submission received (%s)', 'claw-agent' ),
							$hook_name
						),
					)
				);

			case 'woocommerce_add_to_cart':
				$product_id = isset( $args[1] ) ? (int) $args[1] : 0;
				$quantity   = isset( $args[2] ) ? (int) $args[2] : 1;
				if ( $product_id <= 0 ) {
					return null;
				}
				$product_name = '';
				$product      = wc_get_product( $product_id );
				if ( $product ) {
					$product_name = $product->get_name();
				}
				return array_merge(
					$base,
					array(
						'title'        => sprintf(
							__( 'Item added to cart: %s', 'claw-agent' ),
							$product_name
						),
						'product_id'   => $product_id,
						'quantity'     => $quantity,
					)
				);

			case 'woocommerce_cart_updated':
				return array_merge(
					$base,
					array(
						'title' => __( 'Cart updated', 'claw-agent' ),
					)
				);

			case 'woocommerce_checkout_order_processed':
				$order_id = isset( $args[0] ) ? (int) $args[0] : 0;
				if ( $order_id <= 0 ) {
					return null;
				}
				return array_merge(
					$base,
					array(
						'title'    => sprintf(
							__( 'Checkout completed: Order #%d', 'claw-agent' ),
							$order_id
						),
						'order_id' => $order_id,
					)
				);

			case 'woocommerce_after_cart':
				return array_merge(
					$base,
					array(
						'title' => __( 'Cart page viewed', 'claw-agent' ),
					)
				);

			case 'woocommerce_after_checkout_form':
				return array_merge(
					$base,
					array(
						'title' => __( 'Checkout page viewed', 'claw-agent' ),
					)
				);

			case 'woocommerce_thankyou':
				$order_id = isset( $args[0] ) ? (int) $args[0] : 0;
				return array_merge(
					$base,
					array(
						'title'    => sprintf(
							__( 'Order confirmed: #%d', 'claw-agent' ),
							$order_id
						),
						'order_id' => $order_id,
					)
				);

			default:
				return array_merge(
					$base,
					array(
						'title' => sprintf(
							/* translators: %s: Hook name. */
							__( 'WordPress event: %s', 'claw-agent' ),
							$hook_name
						),
					)
				);
		}
	}

	// -------------------------------------------------------------------------
	// Queue management
	// -------------------------------------------------------------------------

	/**
	 * Append a task to the shutdown queue.
	 *
	 * Tasks are buffered in a transient and dispatched in a single batch at
	 * request shutdown, keeping HTTP calls off the render critical path.
	 * The shutdown processor is registered once per request via a static flag.
	 *
	 * @since 1.0.0
	 *
	 * @param array $task_data Associative task data array (must include 'module' and 'title').
	 *
	 * @return void
	 */
	public static function queue_task( array $task_data ): void {
		$queue   = (array) get_transient( self::TRANSIENT_QUEUE );
		$queue[] = $task_data;
		set_transient( self::TRANSIENT_QUEUE, $queue );

		if ( ! self::$shutdown_registered ) {
			add_action( 'shutdown', array( __CLASS__, 'process_queue' ), 20 );
			self::$shutdown_registered = true;
		}
	}

	/**
	 * Flush the pending task queue by dispatching each entry to the Klawty API.
	 *
	 * A transient-based mutex prevents concurrent requests from sending the
	 * same tasks twice. On partial failures the queue is updated to retain
	 * only the tasks that could not be dispatched; these will be retried on
	 * the next request that calls process_queue().
	 *
	 * Called on the 'shutdown' action at priority 20.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function process_queue(): void {
		// Acquire mutex lock — if set_transient returns false the lock already exists.
		if ( ! set_transient( self::TRANSIENT_LOCK, 1, self::LOCK_TTL ) ) {
			return;
		}

		$queue = (array) get_transient( self::TRANSIENT_QUEUE );

		if ( empty( $queue ) ) {
			delete_transient( self::TRANSIENT_LOCK );
			return;
		}

		// Get the API client from the main plugin instance.
		$plugin = WP_Claw::get_instance();

		if ( null === $plugin ) {
			delete_transient( self::TRANSIENT_LOCK );
			return;
		}

		$api_client = $plugin->get_api_client();

		if ( null === $api_client ) {
			delete_transient( self::TRANSIENT_LOCK );
			return;
		}

		$remaining = array();

		foreach ( $queue as $task ) {
			if ( ! is_array( $task ) || empty( $task['title'] ) ) {
				// Malformed entry — drop it.
				continue;
			}

			$result = $api_client->create_task( $task );

			if ( is_wp_error( $result ) ) {
				wp_claw_log_warning(
					'Failed to dispatch queued task — will retry next request.',
					array(
						'task'    => $task['title'],
						'module'  => $task['module'] ?? 'unknown',
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					)
				);
				// Keep failed tasks for the next attempt.
				$remaining[] = $task;
			} else {
				wp_claw_log_debug(
					'Queued task dispatched.',
					array(
						'task'    => $task['title'],
						'task_id' => $result['id'] ?? 'unknown',
					)
				);
			}
		}

		if ( empty( $remaining ) ) {
			delete_transient( self::TRANSIENT_QUEUE );
		} else {
			set_transient( self::TRANSIENT_QUEUE, $remaining );
		}

		delete_transient( self::TRANSIENT_LOCK );
	}
}
