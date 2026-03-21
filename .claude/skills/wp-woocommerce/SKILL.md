---
name: wp-woocommerce
description: WooCommerce integration patterns for WP-Claw Commerce module — product management, orders, stock, pricing, customer data, webhooks
keywords: [woocommerce, wordpress, ecommerce, products, orders, stock, pricing, webhooks, customers]
---

# WooCommerce Integration

## Conditional Loading

```php
// ALWAYS check before using any WooCommerce class
if ( ! class_exists( 'WooCommerce' ) ) {
    return; // Commerce module skipped entirely
}
```

## Hook Into WooCommerce Events

```php
// Order status changes (agent handles follow-up, invoicing)
add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_change' ], 10, 4 );

// Low stock alert (agent reorders or alerts)
add_action( 'woocommerce_low_stock', [ $this, 'on_low_stock' ], 10, 1 );

// New order (agent processes)
add_action( 'woocommerce_new_order', [ $this, 'on_new_order' ], 10, 2 );

// Abandoned cart (via scheduled check)
// WooCommerce doesn't have native abandoned cart hooks — use cron to check
```

## Product Data Access

```php
// Get product data for Klawty
$product = wc_get_product( $product_id );
$data = [
    'id'            => $product->get_id(),
    'name'          => $product->get_name(),
    'sku'           => $product->get_sku(),
    'price'         => $product->get_price(),
    'regular_price' => $product->get_regular_price(),
    'sale_price'    => $product->get_sale_price(),
    'stock_quantity'=> $product->get_stock_quantity(),
    'stock_status'  => $product->get_stock_status(),
    'categories'    => wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
];
```

## Agent Actions on WooCommerce

```php
// Update stock (Commerce agent)
$product = wc_get_product( absint( $params['product_id'] ) );
$product->set_stock_quantity( absint( $params['stock_quantity'] ) );
$product->save();

// Update price (Commerce agent — PROPOSE tier, needs approval)
$product = wc_get_product( absint( $params['product_id'] ) );
$product->set_regular_price( wc_format_decimal( $params['regular_price'] ) );
if ( ! empty( $params['sale_price'] ) ) {
    $product->set_sale_price( wc_format_decimal( $params['sale_price'] ) );
}
$product->save();
```
