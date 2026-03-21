---
name: wp-security
description: WordPress security patterns for WP-Claw — nonces, capabilities, sanitization, escaping, encryption, CSRF protection, XSS prevention
keywords: [wordpress, security, nonces, capabilities, sanitization, escaping, encryption, csrf, xss]
---

# WordPress Security Patterns

## Input Sanitization — EVERY Input

```php
// Text fields
$title = sanitize_text_field( wp_unslash( $_POST['title'] ) );

// Integers
$post_id = absint( $_GET['post_id'] );

// Email
$email = sanitize_email( $_POST['email'] );

// URL
$url = esc_url_raw( $_POST['url'] );

// HTML content (allows safe tags)
$content = wp_kses_post( wp_unslash( $_POST['content'] ) );

// Textarea
$text = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );

// Filename
$file = sanitize_file_name( $_FILES['upload']['name'] );
```

## Output Escaping — EVERY Output

```php
// In HTML context
echo esc_html( $title );

// In HTML attributes
echo '<input value="' . esc_attr( $value ) . '">';

// URLs
echo '<a href="' . esc_url( $link ) . '">';

// JavaScript
echo '<script>var data = ' . wp_json_encode( $data ) . ';</script>';

// Rich HTML content
echo wp_kses_post( $content );
```

## Nonce Verification — EVERY Form

```php
// In form
wp_nonce_field( 'wp_claw_save_settings', 'wp_claw_nonce' );

// In handler
if ( ! isset( $_POST['wp_claw_nonce'] ) ||
     ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_claw_nonce'] ) ), 'wp_claw_save_settings' )
) {
    wp_die( esc_html__( 'Security check failed.', 'wp-claw' ) );
}
```

## Capability Checks — EVERY Admin Action

```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-claw' ) );
}
```

## API Key Encryption

```php
function wp_claw_encrypt( string $plaintext ): string {
    $key   = sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
    $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
    return base64_encode( $nonce . $cipher );
}

function wp_claw_decrypt( string $encoded ): string {
    $decoded = base64_decode( $encoded );
    $key     = sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    $nonce   = mb_substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit' );
    $cipher  = mb_substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit' );
    return sodium_crypto_secretbox_open( $cipher, $nonce, $key );
}
```

## Database Query Safety

```php
// ALWAYS use prepare() for any query with variables
global $wpdb;
$table = $wpdb->prefix . 'wp_claw_tasks';

$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} WHERE agent = %s AND status = %s ORDER BY created_at DESC LIMIT %d",
        $agent,
        $status,
        $limit
    )
);
```
