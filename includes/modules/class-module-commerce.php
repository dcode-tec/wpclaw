<?php
/**
 * Commerce module.
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
 * Commerce module.
 *
 * Wraps WooCommerce data and operations so the Commerce agent can
 * query the product catalogue, manage stock alerts, adjust pricing,
 * create coupons, retrieve orders, and respond to store events.
 *
 * This module is only available when WooCommerce is active. All
 * WooCommerce function calls are guarded by is_available() checks.
 *
 * @since 1.0.0
 */
class Module_Commerce extends Module_Base {

	// -------------------------------------------------------------------------
	// Module contract
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'commerce';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Commerce', 'wp-claw' );
	}

	/**
	 * Return the Klawty agent responsible for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'commerce';
	}

	/**
	 * Return the allowlisted agent actions for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return [
			'update_stock_alert',
			'update_product_price',
			'create_coupon',
			'get_orders',
			'get_products',
			'send_abandoned_cart_reminder',
			'update_product_description',
		];
	}

	/**
	 * Whether WooCommerce is installed and active.
	 *
	 * The module loader checks this before registering hooks or allowing
	 * any agent actions. All handle_action() paths also guard against
	 * WooCommerce being absent.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True only when the WooCommerce class is available.
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Dispatch an inbound agent action to the appropriate handler.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error Result array on success, WP_Error on failure.
	 */
	public function handle_action( string $action, array $params ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'wp_claw_woocommerce_unavailable',
				__( 'WooCommerce is not active. The Commerce module requires WooCommerce.', 'wp-claw' ),
				[ 'status' => 503 ]
			);
		}

		switch ( $action ) {
			case 'update_stock_alert':
				return $this->handle_update_stock_alert( $params );

			case 'update_product_price':
				return $this->handle_update_product_price( $params );

			case 'create_coupon':
				return $this->handle_create_coupon( $params );

			case 'get_orders':
				return $this->handle_get_orders( $params );

			case 'get_products':
				return $this->handle_get_products( $params );

			case 'send_abandoned_cart_reminder':
				return $this->handle_send_abandoned_cart_reminder( $params );

			case 'update_product_description':
				return $this->handle_update_product_description( $params );

			default:
				return new \WP_Error(
					'wp_claw_unknown_action',
					/* translators: %s: action name */
					sprintf( __( 'Unknown Commerce action: %s', 'wp-claw' ), esc_html( $action ) ),
					[ 'status' => 400 ]
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Update the stock alert threshold for a product.
	 *
	 * Sets the low-stock threshold and enables stock management on the
	 * product so WooCommerce triggers the low-stock hook when stock dips
	 * below the threshold.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Parameters.
	 *
	 *     @type int $product_id       WooCommerce product ID.
	 *     @type int $low_stock_amount Threshold below which alerts fire.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_update_stock_alert( array $params ) {
		$product_id       = absint( $params['product_id'] ?? 0 );
		$low_stock_amount = absint( $params['low_stock_amount'] ?? 5 );

		if ( ! $product_id ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'product_id is required.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error(
				'wp_claw_product_not_found',
				/* translators: %d: product ID */
				sprintf( __( 'Product #%d not found.', 'wp-claw' ), $product_id ),
				[ 'status' => 404 ]
			);
		}

		$product->set_manage_stock( true );
		$product->set_low_stock_amount( $low_stock_amount );
		$product->save();

		return [
			'success'          => true,
			'product_id'       => $product_id,
			'low_stock_amount' => $low_stock_amount,
		];
	}

	/**
	 * Update the regular price of a product.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Parameters.
	 *
	 *     @type int    $product_id WooCommerce product ID.
	 *     @type string $price      New regular price (e.g. '29.99').
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_update_product_price( array $params ) {
		$product_id = absint( $params['product_id'] ?? 0 );
		$price      = sanitize_text_field( $params['price'] ?? '' );

		if ( ! $product_id ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'product_id is required.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		// Validate that the price is a non-negative number.
		if ( '' === $price || ! is_numeric( $price ) || (float) $price < 0 ) {
			return new \WP_Error(
				'wp_claw_invalid_price',
				__( 'price must be a non-negative numeric value.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error(
				'wp_claw_product_not_found',
				/* translators: %d: product ID */
				sprintf( __( 'Product #%d not found.', 'wp-claw' ), $product_id ),
				[ 'status' => 404 ]
			);
		}

		$product->set_regular_price( $price );
		$product->save();

		return [
			'success'    => true,
			'product_id' => $product_id,
			'price'      => $price,
		];
	}

	/**
	 * Create a WooCommerce coupon.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Coupon parameters.
	 *
	 *     @type string $code            Coupon code (required).
	 *     @type string $discount_type   'percent', 'fixed_cart', or 'fixed_product' (default 'percent').
	 *     @type string $amount          Discount amount (default '10').
	 *     @type string $expiry_date     Expiry date in Y-m-d format (optional).
	 *     @type int    $usage_limit     Maximum total redemptions (optional).
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_create_coupon( array $params ) {
		$code          = sanitize_text_field( strtoupper( $params['code'] ?? '' ) );
		$discount_type = sanitize_key( $params['discount_type'] ?? 'percent' );
		$amount        = sanitize_text_field( $params['amount'] ?? '10' );
		$expiry_date   = sanitize_text_field( $params['expiry_date'] ?? '' );
		$usage_limit   = absint( $params['usage_limit'] ?? 0 );

		if ( empty( $code ) ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'Coupon code is required.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		$allowed_types = [ 'percent', 'fixed_cart', 'fixed_product' ];
		if ( ! in_array( $discount_type, $allowed_types, true ) ) {
			$discount_type = 'percent';
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );

		if ( ! empty( $expiry_date ) ) {
			$coupon->set_date_expires( $expiry_date );
		}

		if ( $usage_limit > 0 ) {
			$coupon->set_usage_limit( $usage_limit );
		}

		$coupon_id = $coupon->save();

		if ( ! $coupon_id ) {
			return new \WP_Error(
				'wp_claw_coupon_save_failed',
				__( 'Failed to save coupon.', 'wp-claw' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'success'       => true,
			'coupon_id'     => $coupon_id,
			'code'          => $code,
			'discount_type' => $discount_type,
			'amount'        => $amount,
		];
	}

	/**
	 * Retrieve WooCommerce orders.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Query parameters.
	 *
	 *     @type string $status Order status to filter by (default 'any').
	 *     @type int    $limit  Maximum results (default 10, max 50).
	 * }
	 *
	 * @return array
	 */
	private function handle_get_orders( array $params ) {
		$status = sanitize_text_field( $params['status'] ?? 'any' );
		$limit  = min( 50, absint( $params['limit'] ?? 10 ) );
		$limit  = max( 1, $limit );

		$orders = wc_get_orders(
			[
				'status' => $status,
				'limit'  => $limit,
				'return' => 'objects',
			]
		);

		$result = [];
		foreach ( $orders as $order ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				continue;
			}
			$result[] = [
				'order_id'   => $order->get_id(),
				'status'     => $order->get_status(),
				'total'      => $order->get_total(),
				'currency'   => $order->get_currency(),
				'customer'   => $order->get_billing_email(),
				'created_at' => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
			];
		}

		return [
			'success' => true,
			'orders'  => $result,
			'count'   => count( $result ),
		];
	}

	/**
	 * Retrieve WooCommerce products.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Query parameters.
	 *
	 *     @type int    $limit    Maximum results (default 10, max 50).
	 *     @type string $category Category slug to filter by (optional).
	 * }
	 *
	 * @return array
	 */
	private function handle_get_products( array $params ) {
		$limit    = min( 50, absint( $params['limit'] ?? 10 ) );
		$limit    = max( 1, $limit );
		$category = sanitize_text_field( $params['category'] ?? '' );

		$query_args = [ 'limit' => $limit ];

		if ( ! empty( $category ) ) {
			$query_args['category'] = [ $category ];
		}

		$products = wc_get_products( $query_args );

		$result = [];
		foreach ( $products as $product ) {
			if ( ! ( $product instanceof \WC_Product ) ) {
				continue;
			}
			$result[] = [
				'product_id'   => $product->get_id(),
				'name'         => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price'        => $product->get_price(),
				'stock_status' => $product->get_stock_status(),
				'stock_qty'    => $product->get_stock_quantity(),
				'status'       => $product->get_status(),
			];
		}

		return [
			'success'  => true,
			'products' => $result,
			'count'    => count( $result ),
		];
	}

	/**
	 * Queue an abandoned cart reminder proposal for the Commerce agent.
	 *
	 * Sending the actual reminder email is a PROPOSE-tier action that
	 * requires admin approval per the tiered autonomy model. This handler
	 * records the intent in the task log — the agent handles the proposal.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Parameters.
	 *
	 *     @type string $customer_email Customer email address.
	 *     @type string $cart_contents  JSON-encoded summary of cart items.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_send_abandoned_cart_reminder( array $params ) {
		global $wpdb;

		$customer_email = sanitize_email( $params['customer_email'] ?? '' );
		$cart_contents  = sanitize_text_field( $params['cart_contents'] ?? '' );

		if ( empty( $customer_email ) || ! is_email( $customer_email ) ) {
			return new \WP_Error(
				'wp_claw_invalid_email',
				__( 'A valid customer_email is required.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		$task_id = 'commerce-cart-' . wp_generate_uuid4();
		$details = wp_json_encode(
			[
				'customer_email' => $customer_email,
				'cart_contents'  => $cart_contents,
				'queued_at'      => current_time( 'c' ),
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			[
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'send_abandoned_cart_reminder',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Failed to queue abandoned cart reminder.', 'wp-claw' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'success' => true,
			'task_id' => $task_id,
			'message' => __( 'Abandoned cart reminder queued for agent review.', 'wp-claw' ),
		];
	}

	/**
	 * Update the long description of a WooCommerce product.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *     Parameters.
	 *
	 *     @type int    $product_id   WooCommerce product ID.
	 *     @type string $description  New product description (HTML allowed, filtered by wp_kses_post).
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_update_product_description( array $params ) {
		$product_id  = absint( $params['product_id'] ?? 0 );
		$description = wp_kses_post( $params['description'] ?? '' );

		if ( ! $product_id ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'product_id is required.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $description ) ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'description is required.', 'wp-claw' ),
				[ 'status' => 400 ]
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error(
				'wp_claw_product_not_found',
				/* translators: %d: product ID */
				sprintf( __( 'Product #%d not found.', 'wp-claw' ), $product_id ),
				[ 'status' => 404 ]
			);
		}

		$product->set_description( $description );
		$product->save();

		return [
			'success'    => true,
			'product_id' => $product_id,
			'message'    => __( 'Product description updated.', 'wp-claw' ),
		];
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register WooCommerce event hooks.
	 *
	 * Only wires up hooks when WooCommerce is active. Hooks queue agent
	 * tasks in response to store events so the Commerce agent can react
	 * autonomously within its allowed tier.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! $this->is_available() ) {
			return;
		}

		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 10, 4 );
		add_action( 'woocommerce_low_stock', [ $this, 'handle_low_stock' ], 10, 1 );
		add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// WooCommerce event callbacks
	// -------------------------------------------------------------------------

	/**
	 * Queue a task when an order status changes.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int       $order_id   WooCommerce order ID.
	 * @param string    $from       Previous order status.
	 * @param string    $to         New order status.
	 * @param \WC_Order $order      The WooCommerce order object.
	 *
	 * @return void
	 */
	public function handle_order_status_changed( $order_id, $from, $to, $order ): void {
		global $wpdb;

		$order_id = absint( $order_id );
		$from     = sanitize_key( $from );
		$to       = sanitize_key( $to );

		$task_id = 'commerce-order-status-' . $order_id . '-' . $to . '-' . time();
		$details = wp_json_encode(
			[
				'order_id' => $order_id,
				'from'     => $from,
				'to'       => $to,
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			[
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'order_status_changed',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Queue a low-stock alert task when WooCommerce detects low inventory.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param \WC_Product $product The low-stock product.
	 *
	 * @return void
	 */
	public function handle_low_stock( $product ): void {
		global $wpdb;

		if ( ! ( $product instanceof \WC_Product ) ) {
			return;
		}

		$product_id = $product->get_id();
		$task_id    = 'commerce-lowstock-' . $product_id . '-' . time();
		$details    = wp_json_encode(
			[
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
				'stock_qty'    => $product->get_stock_quantity(),
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			[
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'low_stock_alert',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Queue a task when a new WooCommerce order is placed.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int       $order_id WooCommerce order ID.
	 * @param \WC_Order $order    The new order object.
	 *
	 * @return void
	 */
	public function handle_new_order( $order_id, $order ): void {
		global $wpdb;

		$order_id = absint( $order_id );

		$customer_email = '';
		$total          = '';
		if ( $order instanceof \WC_Order ) {
			$customer_email = sanitize_email( $order->get_billing_email() );
			$total          = $order->get_total();
		}

		$task_id = 'commerce-neworder-' . $order_id . '-' . time();
		$details = wp_json_encode(
			[
				'order_id'       => $order_id,
				'customer_email' => $customer_email,
				'total'          => $total,
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			[
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'new_order',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/**
	 * Return the current WooCommerce store state for state sync.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		if ( ! $this->is_available() ) {
			return [
				'module'       => $this->get_slug(),
				'available'    => false,
				'message'      => __( 'WooCommerce is not active.', 'wp-claw' ),
				'generated_at' => current_time( 'c' ),
			];
		}

		// Order counts by status.
		$order_counts = [];
		$wc_statuses  = wc_get_order_statuses();
		foreach ( array_keys( $wc_statuses ) as $raw_status ) {
			$status = str_replace( 'wc-', '', sanitize_key( $raw_status ) );
			$count  = wc_orders_count( $status );
			if ( $count > 0 ) {
				$order_counts[ $status ] = $count;
			}
		}

		// Product count.
		$product_count = wp_count_posts( 'product' );
		$total_products = isset( $product_count->publish ) ? absint( $product_count->publish ) : 0;

		// Low stock count via WooCommerce data store.
		$low_stock_count = $this->get_low_stock_count();

		return [
			'module'          => $this->get_slug(),
			'available'       => true,
			'order_counts'    => $order_counts,
			'total_orders'    => array_sum( $order_counts ),
			'total_products'  => $total_products,
			'low_stock_count' => $low_stock_count,
			'generated_at'    => current_time( 'c' ),
		];
	}

	/**
	 * Return the number of low-stock products.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private function get_low_stock_count(): int {
		$low_stock = wc_get_products(
			[
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => -1,
				'return'       => 'ids',
			]
		);

		$count = 0;
		foreach ( $low_stock as $product_id ) {
			$product = wc_get_product( absint( $product_id ) );
			if ( ! ( $product instanceof \WC_Product ) ) {
				continue;
			}
			if ( $product->get_manage_stock() && $product->is_on_sale( 'edit' ) ) {
				continue;
			}
			if ( $product->get_manage_stock() ) {
				$threshold   = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
				$product_low = $product->get_low_stock_amount();
				if ( false !== $product_low && '' !== $product_low ) {
					$threshold = absint( $product_low );
				}
				$stock_qty = (int) $product->get_stock_quantity();
				if ( $stock_qty <= $threshold ) {
					$count++;
				}
			}
		}

		return $count;
	}
}
