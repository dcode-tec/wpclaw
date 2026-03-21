---
name: wp-chat-widget
description: Frontend chat widget for WP-Claw — floating widget, real-time AI conversation with Concierge agent, product recommendations, order tracking, lead capture
keywords: [wordpress, chat, widget, concierge, ai, customer, support, product, recommendations, live chat]
---

# Chat Widget — Concierge Agent Interface

## Widget Implementation

The chat widget is a lightweight, self-contained JavaScript component injected into the frontend via `wp_footer`. It communicates with the Concierge agent through the WP-Claw REST API.

### Frontend Injection

```php
// Only load on frontend, not admin
add_action( 'wp_footer', [ $this, 'render_chat_widget' ] );

public function render_chat_widget(): void {
    if ( is_admin() || ! $this->is_enabled() ) {
        return;
    }

    $config = [
        'endpoint'  => rest_url( 'wp-claw/v1/chat' ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
        'position'  => get_option( 'wp_claw_chat_position', 'bottom-right' ),
        'accent'    => get_option( 'wp_claw_chat_accent', '#EA580C' ),
        'welcome'   => get_option( 'wp_claw_chat_welcome', __( 'Hi! How can I help you today?', 'wp-claw' ) ),
        'agentName' => get_option( 'wp_claw_chat_agent_name', __( 'AI Assistant', 'wp-claw' ) ),
        'avatar'    => get_option( 'wp_claw_chat_avatar', WP_CLAW_URL . 'public/img/agent-avatar.svg' ),
    ];

    wp_enqueue_script( 'wp-claw-chat', WP_CLAW_URL . 'public/js/wp-claw-chat.js', [], WP_CLAW_VERSION, true );
    wp_enqueue_style( 'wp-claw-chat', WP_CLAW_URL . 'public/css/wp-claw-chat.css', [], WP_CLAW_VERSION );
    wp_add_inline_script( 'wp-claw-chat', 'window.wpClawChat=' . wp_json_encode( $config ) . ';', 'before' );
}
```

### REST Endpoints for Chat

```php
// Visitor sends a message
register_rest_route( 'wp-claw/v1', '/chat', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'handle_chat_message' ],
    'permission_callback' => '__return_true',  // Public endpoint (visitors)
    'args'                => [
        'message'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
        'session_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
        'context'    => [ 'type' => 'object' ],  // current page URL, product ID if on product page
    ],
] );

// Get chat history for session
register_rest_route( 'wp-claw/v1', '/chat/history', [
    'methods'             => 'GET',
    'callback'            => [ $this, 'get_chat_history' ],
    'permission_callback' => '__return_true',
    'args'                => [
        'session_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
    ],
] );
```

### Rate Limiting for Chat

```php
// Prevent abuse on public endpoint
// 30 messages per session per hour
// 5 messages per IP per minute (burst protection)
public function handle_chat_message( WP_REST_Request $request ): WP_REST_Response {
    $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    $session = sanitize_text_field( $request->get_param( 'session_id' ) );

    $rate_key = 'wp_claw_chat_rate_' . md5( $ip );
    $count = (int) get_transient( $rate_key );
    if ( $count >= 5 ) {
        return new WP_REST_Response( [ 'error' => 'Too many messages. Please wait a moment.' ], 429 );
    }
    set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

    // Forward to Klawty Concierge agent
    $response = $this->api_client->post( '/api/chat', [
        'message'    => sanitize_text_field( $request->get_param( 'message' ) ),
        'session_id' => $session,
        'context'    => $this->build_context( $request ),
    ] );

    return new WP_REST_Response( $response, 200 );
}
```

### Context Building

The Concierge agent receives rich context about where the visitor is on the site:

```php
private function build_context( WP_REST_Request $request ): array {
    $context = [
        'site_name' => get_bloginfo( 'name' ),
        'page_url'  => sanitize_url( $request->get_param( 'page_url' ) ?? '' ),
    ];

    // If on a product page, include product data
    $product_id = absint( $request->get_param( 'product_id' ) ?? 0 );
    if ( $product_id && class_exists( 'WooCommerce' ) ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $context['product'] = [
                'name'           => $product->get_name(),
                'price'          => $product->get_price(),
                'description'    => wp_strip_all_tags( $product->get_short_description() ),
                'stock_status'   => $product->get_stock_status(),
                'categories'     => wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] ),
            ];
        }
    }

    // If logged-in customer, include order history summary
    if ( is_user_logged_in() && class_exists( 'WooCommerce' ) ) {
        $customer_id = get_current_user_id();
        $orders = wc_get_orders( [
            'customer_id' => $customer_id,
            'limit'       => 5,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ] );
        $context['customer'] = [
            'name'         => wp_get_current_user()->display_name,
            'recent_orders' => array_map( function( $order ) {
                return [
                    'id'     => $order->get_id(),
                    'status' => $order->get_status(),
                    'total'  => $order->get_total(),
                    'date'   => $order->get_date_created()->format( 'Y-m-d' ),
                ];
            }, $orders ),
        ];
    }

    return $context;
}
```

### Widget Features

1. **Floating button** — bottom-right (configurable), with unread badge
2. **Chat window** — expandable, mobile-responsive, dark/light mode
3. **Product cards** — when Concierge recommends products, renders clickable cards
4. **Quick replies** — suggested responses as tappable buttons
5. **Typing indicator** — shows when agent is thinking
6. **Session persistence** — localStorage session ID, history persists across pages
7. **Offline mode** — "Leave a message" form when Klawty is unavailable
8. **Lead capture** — asks for email before starting conversation (configurable)
9. **Knowledge base** — admin can add FAQ entries that Concierge references
10. **Escalation** — Concierge can create a support ticket and notify the site owner