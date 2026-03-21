---
name: wp_coding_standards
description: WordPress coding standards and security requirements — mandatory for every file in WP-Claw
type: reference
---

## Mandatory Security Patterns

Every PHP file: `defined('ABSPATH') || exit;`
Every output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
Every input: `sanitize_text_field()`, `absint()`, `sanitize_email()`
Every form: `wp_nonce_field()` + `wp_verify_nonce()`
Every admin action: `current_user_can('manage_options')`
Every SQL: `$wpdb->prepare()`
Every HTTP: `wp_remote_get()` / `wp_remote_post()` (never curl or file_get_contents)
Every file op: `WP_Filesystem` API (never direct PHP file functions)

## PHPCS Command
`./vendor/bin/phpcs --standard=WordPress-Extra wp-claw.php includes/ admin/ public/`

## Plugin Check (PCP)
Must pass WordPress Plugin Check before wordpress.org submission.
