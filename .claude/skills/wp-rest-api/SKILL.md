---
name: wp-rest-api
description: WordPress REST API patterns for WP-Claw — registering custom endpoints, authentication, schema validation, HMAC webhook verification
keywords: [wordpress, rest, api, endpoints, authentication, webhooks, hmac, schema, json]
---

# WordPress REST API for WP-Claw

## Endpoint Registration

```php
add_action( 'rest_api_init', [ $this, 'register_routes' ] );

public function register_routes(): void {
    $namespace = 'wp-claw/v1';

    // Klawty calls this to execute actions on WordPress
    register_rest_route( $namespace, '/execute', [
        'methods'             => 'POST',
        'callback'            => [ $this, 'handle_execute' ],
        'permission_callback' => [ $this, 'verify_webhook_signature' ],
        'args'                => $this->get_execute_schema(),
    ] );

    // Klawty calls this to get WP site state
    register_rest_route( $namespace, '/state', [
        'methods'             => 'GET',
        'callback'            => [ $this, 'handle_state' ],
        'permission_callback' => [ $this, 'verify_webhook_signature' ],
    ] );

    // Klawty sends completion notifications
    register_rest_route( $namespace, '/webhook', [
        'methods'             => 'POST',
        'callback'            => [ $this, 'handle_webhook' ],
        'permission_callback' => [ $this, 'verify_webhook_signature' ],
    ] );
}
```

## HMAC Signature Verification

```php
public function verify_webhook_signature( WP_REST_Request $request ): bool {
    $signature = $request->get_header( 'X-Klawty-Signature' );
    if ( empty( $signature ) ) {
        return false;
    }

    $body    = $request->get_body();
    $api_key = wp_claw_decrypt( get_option( 'wp_claw_api_key' ) );

    if ( empty( $api_key ) ) {
        return false;
    }

    $expected = hash_hmac( 'sha256', $body, $api_key );
    return hash_equals( $expected, $signature );
}
```

## Action Execution Pattern

```php
public function handle_execute( WP_REST_Request $request ): WP_REST_Response {
    $action = sanitize_text_field( $request->get_param( 'action' ) );
    $params = $request->get_json_params();

    // Verify action is in allowlist
    if ( ! array_key_exists( $action, self::ALLOWED_ACTIONS ) ) {
        return new WP_REST_Response(
            [ 'error' => 'Action not allowed: ' . $action ],
            403
        );
    }

    // Sanitize params against schema
    $clean_params = $this->sanitize_action_params( $action, $params );

    // Execute via module
    $result = $this->dispatch_action( $action, $clean_params );

    return new WP_REST_Response( $result, 200 );
}
```

## State Endpoint — What WP Tells Klawty

```php
public function handle_state( WP_REST_Request $request ): WP_REST_Response {
    return new WP_REST_Response( [
        'site'    => [
            'url'     => home_url(),
            'name'    => get_bloginfo( 'name' ),
            'wp'      => get_bloginfo( 'version' ),
            'php'     => PHP_VERSION,
            'theme'   => get_stylesheet(),
            'locale'  => get_locale(),
        ],
        'counts'  => [
            'posts'      => (int) wp_count_posts()->publish,
            'pages'      => (int) wp_count_posts( 'page' )->publish,
            'comments'   => (int) wp_count_comments()->approved,
            'users'      => (int) count_users()['total_users'],
            'plugins'    => count( get_option( 'active_plugins', [] ) ),
        ],
        'modules' => array_keys( wp_claw()->get_active_modules() ),
        'health'  => $this->get_health_status(),
    ], 200 );
}
```
