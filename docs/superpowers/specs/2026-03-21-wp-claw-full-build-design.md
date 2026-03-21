# WP-Claw Full Build — Design Specification

**Date:** 2026-03-21
**Author:** ARCHITECT
**Status:** Approved
**Approach:** Bottom-up layered build (helpers -> core -> modules -> admin -> public -> root)

---

## 1. Overview

WP-Claw is a WordPress plugin that connects any WordPress site to a Klawty AI agent instance via REST/HTTP. The plugin is the bridge — lightweight PHP on the WordPress side, all AI processing on the Klawty backend.

**Scope:** Full implementation of the plugin — 35+ files across 6 build layers.

**Non-scope:** Klawty runtime, agent logic, portal dashboard, storefront, vertical JSON generation. These are handled by sibling projects.

---

## 2. Build Layers

### Layer 1: Helpers (4 files, zero dependencies)

All helper files are pure utility functions with no dependencies on WP-Claw classes.

#### 2.1.1 `includes/helpers/encryption.php`

Two public functions for API key storage:

```php
function wp_claw_encrypt( string $plaintext ): string
function wp_claw_decrypt( string $ciphertext ): string
```

**Implementation:**
- Derive 32-byte key from `wp_salt('auth')` via `hash('sha256', ...)`
- Generate 24-byte random nonce via `random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)`
- Encrypt with `sodium_crypto_secretbox($plaintext, $nonce, $key)`
- Store as `base64_encode($nonce . $ciphertext)`
- Decrypt reverses: base64 decode -> split nonce (first 24 bytes) -> `sodium_crypto_secretbox_open()`
- Return empty string on failure (never throw from decrypt — corrupted data should degrade gracefully)
- `sodium_memzero()` on key and plaintext after use

**Fallback:** If sodium extension unavailable (PHP < 7.2 edge case), use `openssl_encrypt('aes-256-cbc', ...)` with same key derivation. Log a warning via `wp_claw_log()`.

#### 2.1.2 `includes/helpers/sanitization.php`

Specialized sanitization wrappers for WP-Claw data structures:

```php
function wp_claw_sanitize_api_response( array $response ): array
function wp_claw_sanitize_task_data( array $task ): array
function wp_claw_sanitize_proposal_data( array $proposal ): array
function wp_claw_sanitize_chat_message( string $message ): string
function wp_claw_sanitize_module_settings( string $module, array $settings ): array
```

**`wp_claw_sanitize_api_response()`:**
- Whitelist expected keys per endpoint
- `sanitize_text_field()` on all string values
- `absint()` on all numeric values
- `wp_kses_post()` on any HTML content fields
- JSON-decode and re-validate any nested JSON strings
- Strip unexpected keys entirely

**`wp_claw_sanitize_chat_message()`:**
- `sanitize_textarea_field()` for multiline
- Strip HTML tags entirely (`wp_strip_all_tags()`)
- Enforce max length: 2000 characters
- Remove null bytes and control characters

**`wp_claw_sanitize_module_settings()`:**
- Per-module schema validation (switch on `$module`)
- SEO: `sanitize_text_field()` on keywords, `absint()` on limits
- Security: `sanitize_text_field()` on IP rules, validate CIDR format
- Commerce: `absint()` on thresholds, `sanitize_email()` on notification addresses

#### 2.1.3 `includes/helpers/capabilities.php`

Custom capabilities and role management:

```php
function wp_claw_add_capabilities(): void
function wp_claw_remove_capabilities(): void
function wp_claw_current_user_can( string $capability ): bool
```

**Capabilities:**

| Capability | Default Role | Purpose |
|-----------|-------------|---------|
| `wp_claw_manage_agents` | Administrator | View/configure agent team |
| `wp_claw_approve_proposals` | Administrator | Approve/reject agent proposals |
| `wp_claw_view_dashboard` | Editor | View WP-Claw dashboard |
| `wp_claw_manage_settings` | Administrator | Change plugin settings |
| `wp_claw_manage_modules` | Administrator | Enable/disable modules |
| `wp_claw_view_analytics` | Editor | View analytics data |
| `wp_claw_manage_chat` | Administrator | Configure chat widget |

**`wp_claw_add_capabilities()`** — Called on activation. Gets `administrator` and `editor` roles via `get_role()`, adds capabilities per the table above.

**`wp_claw_remove_capabilities()`** — Called on uninstall. Removes all `wp_claw_*` capabilities from all roles.

**`wp_claw_current_user_can()`** — Wrapper that checks `current_user_can()` and logs denied access attempts via `wp_claw_log()`.

#### 2.1.4 `includes/helpers/logger.php`

Structured logging with severity levels:

```php
function wp_claw_log( string $message, string $level = 'info', array $context = [] ): void
function wp_claw_log_error( string $message, array $context = [] ): void
function wp_claw_log_warning( string $message, array $context = [] ): void
function wp_claw_log_debug( string $message, array $context = [] ): void
```

**Format:**
```
[2026-03-21T10:30:00+01:00] [WP-Claw] [INFO] Message here {"module":"seo","task_id":"task_abc123"}
```

**Behavior:**
- Only logs if `WP_DEBUG_LOG` is true (respects WordPress debug config)
- Debug level only logs if `WP_CLAW_DEBUG` constant is defined and true
- Uses `error_log()` to write to `wp-content/debug.log`
- Context array is JSON-encoded and appended
- Truncates messages over 1000 characters
- Never logs sensitive data (API keys, passwords, personal data)

---

### Layer 2: Core Classes (10 files)

These form the plugin skeleton. API client must be built first (other classes depend on it).

#### 2.2.1 `includes/class-api-client.php`

**Namespace:** `WPClaw\API_Client`
**Pattern:** Singleton (accessed via `WP_Claw::get_api_client()`)

**Properties:**
```php
private string $base_url;           // Klawty instance URL
private string $api_key;            // Decrypted API key
private string $connection_mode;    // 'managed' | 'self-hosted'
private int    $consecutive_failures;
private int    $circuit_open_until; // Unix timestamp, 0 = closed
```

**Public methods:**

| Method | HTTP | Klawty Endpoint | Returns |
|--------|------|----------------|---------|
| `create_task( $data )` | POST | `/api/tasks` | `array\|WP_Error` |
| `get_task( $task_id )` | GET | `/api/tasks/{id}` | `array\|WP_Error` |
| `get_agents()` | GET | `/api/agents` | `array\|WP_Error` |
| `get_proposals()` | GET | `/api/proposals` | `array\|WP_Error` |
| `resolve_proposal( $id, $action )` | POST | `/api/proposals/{id}` | `array\|WP_Error` |
| `health_check()` | GET | `/api/health` | `array\|WP_Error` |
| `register_hooks( $hooks )` | POST | `/api/hooks` | `array\|WP_Error` |
| `send_chat_message( $data )` | POST | `/api/chat` | `array\|WP_Error` |
| `check_for_updates()` | GET | `/api/updates/check` | `array\|WP_Error` |
| `is_connected()` | -- | -- | `bool` |

**Private methods:**

`request( string $method, string $endpoint, array $data = [] ): array|WP_Error`

Core HTTP method. Flow:
1. Check circuit breaker — if open and not expired, return `WP_Error('circuit_open')`
2. Build full URL from `$base_url . $endpoint`
3. JSON-encode body for POST requests
4. Generate timestamp: `$timestamp = (string) time()`
5. Generate HMAC signature: `hash_hmac('sha256', $timestamp . '.' . $body, $this->api_key)` — includes timestamp to prevent replay attacks (matches inbound verification scheme)
6. Set headers: `Content-Type: application/json`, `X-WPClaw-Signature: {hmac}`, `X-WPClaw-Timestamp: {timestamp}`
7. Call `wp_remote_request()` with 15s timeout (30s for task creation)
8. Check `is_wp_error()` — if yes, increment failures, check circuit breaker threshold
9. Check HTTP status — 2xx = success (reset failures), 429 = rate limited (respect `Retry-After`), 5xx = retry up to 2 times with 1s/3s backoff
10. JSON-decode response body, pass through `wp_claw_sanitize_api_response()`
11. Return sanitized array

**Circuit breaker logic:**
- `$consecutive_failures` tracked in transient `wp_claw_circuit_failures`
- Threshold: 3 consecutive failures -> open circuit for 5 minutes
- Each subsequent failure doubles the cooldown (5min -> 10min -> 20min, max 60min)
- Any successful request resets to 0
- `is_connected()` checks: circuit closed AND last health check succeeded (transient `wp_claw_last_health`)

#### 2.2.2 `includes/class-rest-api.php`

**Namespace:** `WPClaw\REST_API`

Registers 3 inbound endpoints under `wp-claw/v1` namespace plus chat and analytics endpoints.

**Endpoints:**

| Route | Method | Callback | Permission |
|-------|--------|----------|------------|
| `/execute` | POST | `handle_execute` | `verify_signature` |
| `/state` | GET | `handle_state` | `verify_signature` |
| `/webhook` | POST | `handle_webhook` | `verify_signature` |
| `/chat/send` | POST | `handle_chat_send` | Rate limit (public) |
| `/chat/history` | GET | `handle_chat_history` | Session token |
| `/analytics` | POST | `handle_analytics` | Public (no auth) |
| `/proposals/{id}/approve` | POST | `handle_proposal_approve` | `permission_callback` below |
| `/proposals/{id}/reject` | POST | `handle_proposal_reject` | `permission_callback` below |

**Admin REST endpoint permission pattern:**
```php
// permission_callback for admin-authenticated endpoints
'permission_callback' => function() {
    return current_user_can( 'wp_claw_approve_proposals' );
}
// Nonce verification via X-WP-Nonce header (WordPress REST API built-in).
// Client sends: fetch(url, { headers: { 'X-WP-Nonce': wpClaw.nonce } })
// WP REST infrastructure verifies automatically when nonce is present.
```

**Signature verification (`verify_signature`):**
```php
private function verify_signature( WP_REST_Request $request ): bool|WP_Error {
    $signature = $request->get_header( 'X-WPClaw-Signature' );
    $timestamp = $request->get_header( 'X-WPClaw-Timestamp' );
    $body      = $request->get_body();

    // Reject if timestamp older than 5 minutes (replay protection)
    if ( abs( time() - (int) $timestamp ) > 300 ) {
        return new WP_Error( 'expired_signature', 'Request timestamp expired', [ 'status' => 403 ] );
    }

    $api_key  = wp_claw_decrypt( get_option( 'wp_claw_api_key' ) );
    $expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $api_key );

    if ( ! hash_equals( $expected, $signature ) ) {
        return new WP_Error( 'invalid_signature', 'Signature mismatch', [ 'status' => 403 ] );
    }
    return true;
}
```

**`/execute` handler:**
1. Extract `module` and `action` from request body
2. Look up module instance from `WP_Claw::get_module( $module )`
3. Check action is in module's `get_allowed_actions()` array — if not, 403
4. Call `$module->handle_action( $action, $params )`
5. Log to `wp_claw_tasks` table
6. Return result

**`/state` handler:**
Returns aggregated site state:
```php
[
    'wordpress_version' => get_bloginfo('version'),
    'php_version'       => PHP_VERSION,
    'site_url'          => get_site_url(),
    'active_theme'      => wp_get_theme()->get('Name'),
    'active_plugins'    => get_option('active_plugins'),
    'post_counts'       => wp_count_posts(),
    'woocommerce'       => class_exists('WooCommerce') ? [ 'version' => WC()->version, ... ] : null,
    'wp_claw_version'   => WP_CLAW_VERSION,
    'enabled_modules'   => get_option('wp_claw_enabled_modules', []),
    'pending_proposals'  => $this->count_pending_proposals(),
]
```

**Chat rate limiting:**
- Track requests per session hash in a transient
- Max 20 messages per minute per session
- Return 429 with `Retry-After` header if exceeded

#### 2.2.3 `includes/class-admin.php`

**Namespace:** `WPClaw\Admin`

Registers the admin menu, enqueues assets, and renders views.

**Menu structure:**
```
WP-Claw (top-level, dashicons-superhero)
 |- Dashboard     — wp_claw_view_dashboard
 |- Agents        — wp_claw_manage_agents
 |- Proposals     — wp_claw_approve_proposals
 |- Settings      — wp_claw_manage_settings
 |- Modules       — wp_claw_manage_modules
```

**Asset loading:**
- `admin_enqueue_scripts` callback checks `$hook_suffix` against WP-Claw page slugs
- Only loads `admin/css/wp-claw-admin.css` and `admin/js/wp-claw-admin.js` on WP-Claw pages
- Passes data to JS via `wp_localize_script('wp-claw-admin', 'wpClaw', [...])`
- Localized data includes: `ajaxUrl`, `restUrl`, `nonce`, `agentData`, `moduleStates`

**Admin bar integration:**
- Adds a status badge to WP admin bar showing connection health (green/yellow/red dot)
- Updates via a lightweight AJAX call every 60s on admin pages

**Settings registration:**
Uses WordPress Settings API (`register_setting`, `add_settings_section`, `add_settings_field`):

| Option | Type | Default | Sanitization |
|--------|------|---------|-------------|
| `wp_claw_api_key` | string | '' | `wp_claw_encrypt()` on save |
| `wp_claw_connection_mode` | string | 'managed' | `sanitize_text_field()`, validate enum |
| `wp_claw_instance_url` | string | '' | `esc_url_raw()` |
| `wp_claw_enabled_modules` | array | all enabled | `array_map('sanitize_text_field', ...)` |
| `wp_claw_chat_enabled` | bool | true | `(bool)` cast |
| `wp_claw_chat_position` | string | 'bottom-right' | validate enum |
| `wp_claw_chat_welcome` | string | 'Hi! How can I help?' | `sanitize_textarea_field()` |
| `wp_claw_chat_agent_name` | string | 'Concierge' | `sanitize_text_field()` |
| `wp_claw_analytics_enabled` | bool | false | `(bool)` cast |

#### 2.2.4 `includes/class-cron.php`

**Namespace:** `WPClaw\Cron`

Manages all WP-Cron scheduled events.

**Note:** Uses WordPress built-in schedules (`hourly`, `twicedaily`, `daily`, `weekly`). No custom intervals needed — `twicedaily` is a core WP schedule.

**Events registered on activation:**

| Hook | Schedule | Handler | Module |
|------|----------|---------|--------|
| `wp_claw_health_check` | hourly | `run_health_check()` | Core |
| `wp_claw_sync_state` | hourly | `run_sync_state()` | Core |
| `wp_claw_update_check` | twicedaily | `run_update_check()` | Core |
| `wp_claw_security_scan` | twicedaily | `run_module_cron('security')` | Security |
| `wp_claw_backup` | daily | `run_module_cron('backup')` | Backup |
| `wp_claw_seo_audit` | daily | `run_module_cron('seo')` | SEO |
| `wp_claw_analytics_report` | weekly | `run_module_cron('analytics')` | Analytics |
| `wp_claw_performance_check` | weekly | `run_module_cron('performance')` | Performance |

**`run_health_check()`:**
1. Call `$api_client->health_check()`
2. Store result in transient `wp_claw_last_health` (1 hour TTL)
3. If failed, log warning. If 3 consecutive failures, log error.

**`run_sync_state()`:**
1. Gather site state (same as REST `/state` response)
2. POST to Klawty `/api/state/sync`
3. Store sync timestamp in option `wp_claw_last_sync`

**`run_module_cron( $module )`:**
1. Check if module is enabled
2. Create a task via API client: `create_task([ 'agent' => $module->get_agent(), 'title' => "Scheduled {$module} run", ... ])`
3. Log result

All events cleared on deactivation via `wp_clear_scheduled_hook()`.

#### 2.2.5 `includes/class-hooks.php`

**Namespace:** `WPClaw\Hooks`

Central registry that maps WordPress actions/filters to module handlers. Decouples WordPress core events from module logic.

**Registry structure:**
```php
private array $hook_map = [
    'save_post'                          => [ 'seo', 'content' ],
    'publish_post'                       => [ 'seo', 'social' ],
    'wp_login_failed'                    => [ 'security' ],
    'wp_login'                           => [ 'security' ],
    'comment_post'                       => [ 'content' ],
    'woocommerce_order_status_changed'   => [ 'commerce' ],
    'woocommerce_low_stock'              => [ 'commerce' ],
    'woocommerce_new_order'              => [ 'commerce' ],
    'wpforms_process_complete'           => [ 'crm' ],
    'gform_after_submission'             => [ 'crm' ],
    'wpcf7_mail_sent'                    => [ 'crm' ],
];
```

**`register_hooks()`:**
- Iterates `$hook_map`
- For each hook, checks if the mapped module is enabled
- If WooCommerce hooks, wraps in `class_exists('WooCommerce')` guard
- Registers callback that builds a task payload and queues it via `wp_claw_queue_task()`

**Task queuing:**
- Tasks are not sent immediately (avoid blocking page loads)
- Instead, queued in a transient array `wp_claw_task_queue`
- A `shutdown` action hook processes the queue: iterates and calls `$api_client->create_task()` for each
- **Race condition protection:** Before processing, acquire a transient-based lock (`wp_claw_queue_lock`, 30s TTL). If lock exists, skip processing (another request is handling it). Lock is deleted after processing completes. Uses `set_transient()` with expiry as a simple mutex.
- **Partial failure handling:** Successfully sent tasks are removed from the queue individually. If the shutdown handler fails mid-queue, remaining tasks persist in the transient and are retried on next `shutdown` or next cron health check.
- Failed sends are logged and retried on next cron cycle

#### 2.2.6 `includes/class-activator.php`

**Namespace:** `WPClaw\Activator`

Static class called on `register_activation_hook()`.

**`activate()` sequence:**
1. Check PHP version >= 7.4, WP version >= 6.4 — `wp_die()` with message if unmet
2. Create database tables via `dbDelta()` (3 tables, see Section 3 below)
3. Set default options: `wp_claw_enabled_modules` (all enabled), `wp_claw_db_version`, connection defaults
4. Add custom capabilities to roles via `wp_claw_add_capabilities()`
5. Schedule all cron events
6. Set transient `wp_claw_activation_redirect` for first-run redirect to settings page
7. Flush rewrite rules for REST endpoint registration

#### 2.2.7 `includes/class-deactivator.php`

**Namespace:** `WPClaw\Deactivator`

Static class called on `register_deactivation_hook()`.

**`deactivate()` sequence:**
1. Clear all `wp_claw_*` cron events
2. Delete transients: `wp_claw_task_queue`, `wp_claw_last_health`, `wp_claw_circuit_failures`, `wp_claw_update_data`, `wp_claw_queue_lock`
3. Do NOT delete options or tables (user might reactivate)
4. Log deactivation event

#### 2.2.8 `includes/class-i18n.php`

**Namespace:** `WPClaw\I18n`

Loads the plugin text domain on `init` hook:

```php
public function load_textdomain(): void {
    load_plugin_textdomain(
        'wp-claw',
        false,
        dirname( plugin_basename( WP_CLAW_PLUGIN_FILE ) ) . '/languages'
    );
}
```

#### 2.2.9 `includes/class-wp-claw.php`

**Namespace:** `WPClaw\WP_Claw`
**Pattern:** Singleton

The main orchestrator class. Holds references to all subsystems.

**Properties:**
```php
private static ?self $instance = null;
private API_Client $api_client;
private REST_API   $rest_api;
private Admin      $admin;
private Cron       $cron;
private Hooks      $hooks;
private I18n       $i18n;
private array      $modules = [];
```

**`init()` sequence:**
1. Load helpers (require_once the 4 helper files)
2. **DB upgrade check** — compare `WP_CLAW_DB_VERSION` against `get_option('wp_claw_db_version')`. If stale, run `Activator::create_tables()` (which uses `dbDelta()` — safe to re-run) and update the stored version.
3. Instantiate `I18n`, call `load_textdomain()`
4. Instantiate `API_Client` with stored credentials
5. Instantiate `REST_API`, pass API client reference
6. Instantiate `Cron`
7. Instantiate `Hooks`
8. Load modules — iterate module registry, check `wp_claw_enabled_modules` option, instantiate enabled ones
9. If `is_admin()`, instantiate `Admin`
10. If front-end and chat enabled, enqueue public assets
11. Check activation redirect transient -> redirect to settings if first run

**Module registry (static array):**
```php
private static array $module_registry = [
    'seo'         => 'WPClaw\\Modules\\Module_SEO',
    'security'    => 'WPClaw\\Modules\\Module_Security',
    'content'     => 'WPClaw\\Modules\\Module_Content',
    'crm'         => 'WPClaw\\Modules\\Module_CRM',
    'commerce'    => 'WPClaw\\Modules\\Module_Commerce',
    'performance' => 'WPClaw\\Modules\\Module_Performance',
    'forms'       => 'WPClaw\\Modules\\Module_Forms',
    'analytics'   => 'WPClaw\\Modules\\Module_Analytics',
    'backup'      => 'WPClaw\\Modules\\Module_Backup',
    'social'      => 'WPClaw\\Modules\\Module_Social',
    'chat'        => 'WPClaw\\Modules\\Module_Chat',
];
```

**Public API:**
```php
public static function get_instance(): self
public function get_api_client(): API_Client
public function get_module( string $slug ): ?Module_Base
public function get_enabled_modules(): array
public function is_module_enabled( string $slug ): bool
```

---

### Layer 3: Modules (11 files)

All modules extend an abstract base class and implement a common interface.

#### 2.3.0 `includes/class-module-base.php` — Abstract Module Base Class

```php
abstract class Module_Base {
    protected API_Client $api_client;
    protected string $slug;
    protected string $agent;

    abstract public function get_slug(): string;
    abstract public function get_name(): string;
    abstract public function get_agent(): string;
    abstract public function get_allowed_actions(): array;
    abstract public function handle_action( string $action, array $params ): array|WP_Error;
    abstract public function get_state(): array;
    abstract public function register_hooks(): void;

    public function is_available(): bool { return true; }
    public function get_settings_fields(): array { return []; }
}
```

#### 2.3.1 `class-module-seo.php` — Agent: Scribe

**Allowed actions:**
- `update_post_meta_title` — Update SEO title for a post
- `update_post_meta_description` — Update SEO description
- `update_schema_markup` — Set JSON-LD schema for a post
- `generate_sitemap` — Trigger sitemap regeneration
- `analyze_content` — Return SEO score for a post
- `suggest_internal_links` — Return linking suggestions
- `update_robots_txt` — Modify robots.txt directives

**WP hooks registered:**
- `save_post` -> queue SEO audit task for the saved post
- `publish_post` -> queue sitemap update task

**State data:**
- Total posts, posts with meta titles, posts without meta descriptions
- Sitemap last generated timestamp
- Average content score (if tracked)

#### 2.3.2 `class-module-security.php` — Agent: Sentinel

**Allowed actions:**
- `block_ip` — Add IP to deny list (via `.htaccess` predefined rules)
- `update_security_headers` — Set security response headers
- `log_security_event` — Record event in WP-Claw log
- `run_file_integrity_check` — Compare file hashes against known-good
- `enable_brute_force_protection` — Configure login throttling
- `update_htaccess_rules` — Apply predefined security rule set
- `get_login_attempts` — Return recent login failure data

**WP hooks registered:**
- `wp_login_failed` -> log failed attempt, check brute-force threshold
- `wp_login` -> log successful login, check for suspicious patterns
- Cron (twicedaily) -> file integrity scan, malware check

**State data:**
- Failed login attempts (last 24h), blocked IPs count
- File integrity status (clean/issues), last scan time
- Security headers status

#### 2.3.3 `class-module-content.php` — Agent: Scribe

**Allowed actions:**
- `create_draft_post` — Create a new post as draft
- `update_post_content` — Update existing post body
- `create_page` — Create a new page as draft
- `translate_post` — Create translated copy of a post
- `generate_excerpt` — Auto-generate post excerpt

**WP hooks registered:**
- None by default (content creation is manual/cron triggered)

**State data:**
- Post counts by status (draft/publish/pending)
- Recently modified posts (last 7 days)

#### 2.3.4 `class-module-crm.php` — Agent: Commerce

**Allowed actions:**
- `capture_lead` — Store lead data (name, email, source, notes)
- `update_lead_status` — Move lead through pipeline stages
- `score_lead` — Set lead score (1-100)
- `create_followup_task` — Schedule a follow-up reminder
- `get_leads` — Query leads with filters

**WP hooks registered:**
- `wpforms_process_complete` -> capture form submission as lead
- `gform_after_submission` -> Gravity Forms integration
- `wpcf7_mail_sent` -> Contact Form 7 integration

Leads stored in `wp_claw_tasks` with `module='crm'` and structured details JSON.

**State data:**
- Lead counts by status (new/contacted/qualified/converted)
- Recent form submissions count

#### 2.3.5 `class-module-commerce.php` — Agent: Commerce

**Prerequisite:** `class_exists('WooCommerce')` — `is_available()` returns false if WooCommerce not active.

**Allowed actions:**
- `update_stock_alert` — Set low-stock notification threshold
- `update_product_price` — Modify product price (regular or sale)
- `create_coupon` — Generate a WooCommerce coupon
- `get_orders` — Query orders with filters
- `get_products` — Query products with filters
- `send_abandoned_cart_reminder` — Queue reminder (PROPOSE tier — handled via proposal)
- `update_product_description` — Update product short/long description

**WP hooks registered:**
- `woocommerce_order_status_changed` -> log order event, trigger follow-up
- `woocommerce_low_stock` -> create stock alert task
- `woocommerce_new_order` -> log new order, trigger processing task
- Cron (daily) -> abandoned cart check, stock level audit

**State data:**
- Order counts by status (processing/completed/refunded)
- Products count, low-stock products count
- Revenue summary (today/week/month — aggregated, not individual amounts)

#### 2.3.6 `class-module-performance.php` — Agent: Analyst

**Allowed actions:**
- `get_core_web_vitals` — Return CWV metrics (if available via transient/API)
- `run_db_cleanup` — Delete post revisions, spam comments, transients (with limits)
- `optimize_images` — Queue image optimization (metadata-level, not actual compression)
- `suggest_cache_strategy` — Return caching recommendations
- `get_page_speed_data` — Return page load metrics

**WP hooks registered:**
- Cron (weekly) -> performance audit, DB cleanup suggestions

**State data:**
- Database size, autoloaded options size
- Post revision count, spam comment count
- Transient count

#### 2.3.7 `class-module-forms.php` — Agent: Architect

**Allowed actions:**
- `create_form` — Register a form definition (stored as custom post type or option)
- `get_submissions` — Query form submissions
- `update_form` — Modify form fields
- `delete_submission` — Remove a submission (PROPOSE tier)

**WP hooks registered:**
- None (forms are created via admin, submissions handled by CRM module)

**State data:**
- Active form count, total submission count

#### 2.3.8 `class-module-analytics.php` — Agent: Analyst

**Allowed actions:**
- `get_pageviews` — Query pageview data (date range, grouping)
- `get_top_pages` — Return most viewed pages
- `get_referrers` — Return top referrer sources
- `get_device_breakdown` — Return device type distribution
- `generate_report` — Create weekly/monthly summary

**WP hooks registered:**
- Public-facing: enqueue analytics pixel script on `wp_footer`
- Cron (weekly) -> generate and store report

**Analytics pixel (in public JS):**
- On page load, POST to `/wp-json/wp-claw/v1/analytics`
- Payload: `page_url`, `referrer`, `event_type` ('pageview'), `device_type`
- **Session hash is computed server-side** in the `/analytics` endpoint handler: `hash('sha256', $ip . $user_agent . gmdate('Y-m-d'))`. Client JS never sees or computes it. The raw IP is hashed immediately and never stored.
- No cookies. No PII. Session hash rotates daily.

**GDPR consent gate:**
- Analytics pixel checks for a consent signal before firing: `window.wpClawAnalyticsConsent === true` or the presence of a cookie `wp_claw_analytics_consent=1` (set by the site's cookie consent banner)
- Respects `navigator.doNotTrack === '1'` — if set, pixel does not fire
- If no consent mechanism is detected AND `doNotTrack` is not set, pixel does NOT fire by default (opt-in only, GDPR-compliant)
- Site owners integrate with their existing consent management platform (CMP) by setting the `window.wpClawAnalyticsConsent` flag

**Analytics endpoint rate limiting:**
- Rate limited: 1 request per second per IP, tracked via transient `wp_claw_analytics_rl_{ip_hash}` with 1s TTL
- Input validation: `page_url` must be a valid URL via `wp_http_validate_url()`, max 512 chars. `referrer` and `device_type` validated similarly.
- Maximum payload: 2KB. Requests exceeding this are rejected with 413.
- **Data retention:** Cron job `wp_claw_analytics_cleanup` runs weekly, deletes records older than configurable retention period (default: 90 days). Keeps table size bounded.

**State data:**
- Total pageviews (today/week/month)
- Top 10 pages, top 5 referrers

#### 2.3.9 `class-module-backup.php` — Agent: Sentinel

**Allowed actions:**
- `create_backup` — Trigger database + uploads backup
- `list_backups` — Return available backup files
- `restore_backup` — Restore from backup (CONFIRM tier — requires human approval)
- `delete_old_backups` — Remove backups older than retention period
- `verify_backup` — Check backup integrity

**WP hooks registered:**
- Cron (daily) -> create backup
- Uses `WP_Filesystem` API for all file operations

**Backup storage:**
- Default: `wp-content/uploads/wp-claw-backups/` directory
- Each backup: timestamped directory with `database.sql.gz` and `uploads.tar.gz`
- Retention: configurable, default 7 days
- Optional: offsite push to S3/Backblaze (configured in settings, uses `wp_remote_post()`)

**State data:**
- Last backup timestamp, backup count, total backup size
- Last verification result

#### 2.3.10 `class-module-social.php` — Agent: Scribe

**Allowed actions:**
- `create_social_post` — Generate social media post text from blog content
- `schedule_post` — Queue post for future publication (via proposal)
- `get_scheduled_posts` — Return upcoming scheduled social posts

**WP hooks registered:**
- `publish_post` -> auto-generate social post suggestions

Social posts are stored as tasks with `module='social'` and details containing platform, text, scheduled time. Actual posting happens on the Klawty side (agents have social media API access).

**State data:**
- Scheduled posts count, recent posts count

#### 2.3.11 `class-module-chat.php` — Agent: Concierge

**Allowed actions:**
- `get_product_catalog` — Return product data for recommendations
- `get_order_status` — Look up order by ID (customer must provide)
- `search_knowledge_base` — Search FAQ entries
- `capture_chat_lead` — Store visitor info from chat as CRM lead
- `escalate_to_human` — Mark conversation for human follow-up

**WP hooks registered:**
- `wp_footer` -> render chat widget HTML container
- `wp_enqueue_scripts` -> enqueue `public/js/wp-claw-chat.js` and `public/css/wp-claw-chat.css`

**Chat REST endpoints (registered in `class-rest-api.php`):**

`POST /wp-json/wp-claw/v1/chat/send`
- Public endpoint (no WP auth — visitors are not logged in)
- Rate limited: 20 messages/minute per session hash
- Body: `{ session_id, message, page_url }`
- Forwards to Klawty Concierge agent via API client
- Returns: `{ response, suggestions: [], products: [] }`

`GET /wp-json/wp-claw/v1/chat/history?session_id={id}`
- Public endpoint. Session ID is the `crypto.randomUUID()` generated client-side.
- Chat history is NOT stored on the WP side — this endpoint forwards to Klawty's `/api/chat/history?session_id={id}` which manages conversation state.
- The session ID is an opaque UUID. No server-side signing needed because chat data is ephemeral and non-sensitive (visitor-initiated public conversations). An attacker guessing a UUID (2^122 entropy) is impractical.
- Returns last 50 messages for the session

**Chat widget configuration (stored in wp_options):**
- Position: bottom-right or bottom-left
- Accent color: hex value (default: theme primary)
- Welcome message: configurable text
- Agent name: display name in chat header
- Agent avatar: URL or default icon
- Business hours: JSON object `{ mon: [9,17], tue: [9,17], ... }`
- Outside-hours message: "Leave a message" text
- Knowledge base: array of FAQ `{ question, answer }` pairs (editable in admin)

---

### Layer 4: Admin Views and Assets (7 files)

#### 2.4.1 `admin/css/wp-claw-admin.css`

Approximately 400 lines. All selectors prefixed `.wp-claw-admin-*`.

**Key components:**
- Dashboard grid layout (CSS Grid, responsive)
- Agent status cards (colored border by health status)
- KPI metric cards
- Proposal table with action buttons
- Settings form layout
- Module toggle switches
- Connection status indicator (pulsing dot)
- Consistent with WP admin color scheme (`--wp-admin-theme-color`)

#### 2.4.2 `admin/js/wp-claw-admin.js`

Approximately 300 lines. Vanilla JS, no jQuery dependency.

**Features:**
- AJAX proposal approve/reject (POST to REST API with nonce)
- Settings form validation (API key format, URL format)
- Module toggle persistence (AJAX save without full page reload)
- Dashboard auto-refresh (agent status every 60s via REST)
- Connection test button (calls health check, shows result)
- Dismiss admin notices
- Tab navigation for module settings

**Data from PHP (via `wp_localize_script`):**
```javascript
const wpClaw = {
    restUrl: '/wp-json/wp-claw/v1/',
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: '...wp_rest nonce...',
    adminNonce: '...wp_claw_admin nonce...',
    agents: [ /* agent data */ ],
    modules: { /* module states */ },
    i18n: { approve: '...', reject: '...', /* ... */ }
};
```

#### 2.4.3 `admin/views/dashboard.php`

Main dashboard view. Loaded by `class-admin.php` via `include`.

**Layout:**
```
+------------------------------------------------------+
|  WP-Claw Dashboard                    [Status: O]    |
+----------+----------+----------+---------------------+
| Tasks    | Proposals| Agents   | Health              |
|  42      |  3       |  5/5     | All systems OK      |
+----------+----------+----------+---------------------+
|  Recent Tasks                                         |
|  +---------------------------------------------+     |
|  | [Scribe] SEO audit for "New Blog Post" done  |     |
|  | [Sentinel] Security scan — in_progress       |     |
|  | [Commerce] Stock check — done                |     |
|  +---------------------------------------------+     |
+------------------------------------------------------+
|  Agent Team                                           |
|  [Scribe] [Sentinel] [Commerce] [Analyst] [Concierge]|
+------------------------------------------------------+
```

All output escaped. All data fetched via `WP_Claw::get_instance()` methods.

#### 2.4.4 `admin/views/settings.php`

Settings page rendered via WordPress Settings API (`do_settings_sections`, `settings_fields`).

**Sections:**
1. **Connection** — API key (password input, masked), connection mode radio (managed/self-hosted), instance URL (shown only for self-hosted), test connection button
2. **Modules** — Checklist of 11 modules with toggle switches, description text per module
3. **Chat Widget** — Position, colors, welcome message, agent name, business hours
4. **Analytics** — Enable/disable toggle, data retention period
5. **Updates** — Current version, last check time, check now button

#### 2.4.5 `admin/views/agents.php`

Agent team overview. One card per agent from Klawty API response.

Each card shows: agent name, emoji, role description, current task (or "Idle"), health status (green/yellow/red), tasks completed today, uptime.

Data fetched from `$api_client->get_agents()` with transient cache (5 minutes).

#### 2.4.6 `admin/views/proposals.php`

Table of pending proposals from `wp_claw_proposals` table.

Columns: Agent, Action, Details (truncated), Created, Status, Actions (Approve/Reject buttons).

Approve/Reject buttons are forms with nonce fields, handled via AJAX in `wp-claw-admin.js`. On approval, calls `$api_client->resolve_proposal($id, 'approved')` and updates local table.

#### 2.4.7 `admin/views/modules.php` (template)

Per-module settings view. Loaded dynamically based on active tab.

Each module provides its own settings fields via `get_settings_fields()` method. This view iterates them and renders standard WP settings fields.

---

### Layer 5: Public Assets (4 files)

#### 2.5.1 `public/css/wp-claw-public.css`

Approximately 100 lines. All selectors under `.wp-claw-*`. Minimal styles for non-chat public elements (analytics pixel hidden element, any future non-chat frontend components). Chat widget styles live in the separate `wp-claw-chat.css` file.

#### 2.5.2 `public/js/wp-claw-public.js`

Approximately 500 lines. Vanilla JS. Two independent components:

**Chat Widget (approximately 400 lines):**

```javascript
class WPClawChat {
    constructor(config) { /* position, colors, welcome, agentName, restUrl */ }
    init()              { /* render DOM, bind events, show welcome */ }
    toggle()            { /* open/close chat window */ }
    sendMessage(text)   { /* POST to /wp-json/wp-claw/v1/chat/send */ }
    renderMessage(msg)  { /* append to message list */ }
    renderProducts(arr) { /* render product cards */ }
    renderSuggestions()  { /* render quick reply buttons */ }
    showTyping()        { /* show typing indicator */ }
    hideTyping()        { /* hide typing indicator */ }
    checkBusinessHours(){ /* show/hide based on configured hours */ }
}
```

- Session ID generated client-side (`crypto.randomUUID()` or fallback), stored in `sessionStorage`
- Messages fetched via polling (every 3s while window open) or long-poll
- Product cards link to product pages
- Quick replies are clickable buttons that send predefined messages
- Accessibility: ARIA labels, keyboard navigation, focus trapping when open

**Analytics Pixel (approximately 100 lines):**

```javascript
class WPClawAnalytics {
    constructor(config) { /* restUrl */ }
    init()              { /* check consent, fire pageview on DOMContentLoaded */ }
    hasConsent()        { /* check window.wpClawAnalyticsConsent or consent cookie */ }
    trackPageview()     { /* POST to /wp-json/wp-claw/v1/analytics */ }
    getDeviceType()     { /* mobile/tablet/desktop from screen width */ }
}
```

- No cookies set by WP-Claw. No localStorage persistence.
- Session hash computed server-side (not in JS) — client sends only `page_url`, `referrer`, `device_type`
- Pageview fires once per page load, debounced
- Respects `navigator.doNotTrack` and GDPR consent gate (see Section 2.3.8)

#### 2.5.3 `public/css/wp-claw-chat.css`

Dedicated chat widget styles if separated from main public CSS. May be merged into `wp-claw-public.css` during build — decision made at implementation time based on file size.

#### 2.5.4 `public/js/wp-claw-chat.js`

Dedicated chat widget JS if separated. Same merge consideration as CSS.

**Decision:** Keep chat as a separate file pair. It is only loaded when chat module is enabled, reducing payload for non-chat users. Enqueued conditionally in `class-module-chat.php`.

---

### Layer 6: Root Files (5 files)

#### 2.6.1 `wp-claw.php`

Plugin entry point. Approximately 80 lines.

```php
/**
 * Plugin Name:       WP-Claw
 * Plugin URI:        https://wp-claw.ai
 * Description:       The AI Operating Layer for WordPress.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            dcode technologies
 * Author URI:        https://d-code.lu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-claw
 * Domain Path:       /languages
 */
```

Defines constants (`WP_CLAW_VERSION`, `WP_CLAW_DB_VERSION`, `WP_CLAW_PLUGIN_FILE`, `WP_CLAW_PLUGIN_DIR`, `WP_CLAW_PLUGIN_URL`, `WP_CLAW_PLUGIN_BASENAME`), requires all files manually (no Composer autoloader), registers activation/deactivation hooks, and boots the singleton on `plugins_loaded`.

#### 2.6.2 `uninstall.php`

Approximately 50 lines. Guards with `defined('WP_UNINSTALL_PLUGIN')`. Drops all 3 custom tables, deletes all `wp_claw_*` options and transients, removes custom capabilities, clears all cron events, and deletes the backup directory via `WP_Filesystem`.

#### 2.6.3 `readme.txt`

Approximately 200 lines. WordPress.org standard format with `=== WP-Claw ===` header, description, installation, FAQ, screenshots, and changelog sections.

---

## 3. Database Schema (Full DDL)

```sql
CREATE TABLE {prefix}wp_claw_tasks (
    task_id      VARCHAR(64)  NOT NULL,
    agent        VARCHAR(32)  NOT NULL DEFAULT '',
    module       VARCHAR(32)  NOT NULL DEFAULT '',
    action       VARCHAR(128) NOT NULL DEFAULT '',
    status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
    details      LONGTEXT     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (task_id),
    KEY idx_agent_status (agent, status),
    KEY idx_module (module),
    KEY idx_created (created_at)
) {charset_collate};

CREATE TABLE {prefix}wp_claw_proposals (
    proposal_id  VARCHAR(64)  NOT NULL,
    agent        VARCHAR(32)  NOT NULL DEFAULT '',
    action       VARCHAR(128) NOT NULL DEFAULT '',
    tier         VARCHAR(16)  NOT NULL DEFAULT '',
    status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
    details      LONGTEXT     NULL,
    approved_by  BIGINT(20)   UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at  DATETIME     NULL,
    PRIMARY KEY  (proposal_id),
    KEY idx_status (status),
    KEY idx_agent (agent),
    KEY idx_created (created_at)
) {charset_collate};

CREATE TABLE {prefix}wp_claw_analytics (
    id           BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    page_url     VARCHAR(512) NOT NULL DEFAULT '',
    referrer     VARCHAR(512) NOT NULL DEFAULT '',
    event_type   VARCHAR(32)  NOT NULL DEFAULT 'pageview',
    session_hash VARCHAR(64)  NOT NULL DEFAULT '',
    device_type  VARCHAR(16)  NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    KEY idx_event_type (event_type),
    KEY idx_created (created_at),
    KEY idx_page_url (page_url(191))
) {charset_collate};
```

---

## 4. Security Enforcement Matrix

| Layer | Mechanism | Scope |
|-------|-----------|-------|
| Transport | HTTPS (managed), HMAC-SHA256 on all requests | All communication |
| Replay protection | Timestamp within 5-minute window | Inbound requests |
| Action control | Static allowlist per module | `/execute` endpoint |
| Credential storage | `sodium_crypto_secretbox` | API key in wp_options |
| Admin auth | WordPress nonces + capability checks | All admin actions |
| Public rate limit | 20 msg/min per session (chat), 1 req/s (analytics) | Public endpoints |
| SQL injection | `$wpdb->prepare()` on every query | All database access |
| XSS | `esc_html()`, `esc_attr()`, `esc_url()` on every output | All rendered HTML |
| Input validation | `sanitize_*()` functions on every input | All user/API input |
| File access | `WP_Filesystem` API only | Backup module |
| Code safety | No dynamic code paths, no string-to-code conversion | Entire plugin |

---

## 5. Update System Architecture

**Server endpoints (on ai-agent-builder.ai):**

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/api/updates/check?version=X&wp=Y&php=Z` | Version check |
| `GET` | `/api/updates/info/{version}` | Release details |
| `GET` | `/api/updates/download/{version}` | Package download |

**WordPress integration hooks:**
- `pre_set_site_transient_update_plugins` -> inject update data into WP transient
- `plugins_api` -> provide plugin info for the update modal
- `upgrader_post_install` -> post-install cleanup (rename directory, verify files)

**Security:**
- Ed25519 signature on every package
- Public key pinned in plugin source code (`WP_CLAW_UPDATE_PUBLIC_KEY` constant)
- Signature verified before extraction via `sodium_crypto_sign_verify_detached()`
- Pre-update backup of current version to `wp-content/wp-claw-backups/`
- Post-install hash verification against manifest

**Caching:**
- Update response cached in transient `wp_claw_update_data` for 12 hours
- Invalidated on: manual check, reactivation, WP core update check

---

## 6. File Manifest

| # | File | Layer | Lines (est.) |
|---|------|-------|-------------|
| 1 | `includes/helpers/encryption.php` | 1 | 80 |
| 2 | `includes/helpers/sanitization.php` | 1 | 120 |
| 3 | `includes/helpers/capabilities.php` | 1 | 70 |
| 4 | `includes/helpers/logger.php` | 1 | 60 |
| 5 | `includes/class-api-client.php` | 2 | 350 |
| 6 | `includes/class-rest-api.php` | 2 | 400 |
| 7 | `includes/class-admin.php` | 2 | 300 |
| 8 | `includes/class-cron.php` | 2 | 150 |
| 9 | `includes/class-hooks.php` | 2 | 180 |
| 10 | `includes/class-activator.php` | 2 | 150 |
| 11 | `includes/class-deactivator.php` | 2 | 50 |
| 12 | `includes/class-i18n.php` | 2 | 30 |
| 13 | `includes/class-wp-claw.php` | 2 | 250 |
| 14 | `includes/class-module-base.php` | 2 | 60 |
| 15 | `includes/modules/class-module-seo.php` | 3 | 200 |
| 16 | `includes/modules/class-module-security.php` | 3 | 250 |
| 17 | `includes/modules/class-module-content.php` | 3 | 150 |
| 18 | `includes/modules/class-module-crm.php` | 3 | 180 |
| 19 | `includes/modules/class-module-commerce.php` | 3 | 280 |
| 20 | `includes/modules/class-module-performance.php` | 3 | 180 |
| 21 | `includes/modules/class-module-forms.php` | 3 | 150 |
| 22 | `includes/modules/class-module-analytics.php` | 3 | 200 |
| 23 | `includes/modules/class-module-backup.php` | 3 | 250 |
| 24 | `includes/modules/class-module-social.php` | 3 | 150 |
| 25 | `includes/modules/class-module-chat.php` | 3 | 220 |
| 26 | `admin/css/wp-claw-admin.css` | 4 | 400 |
| 27 | `admin/js/wp-claw-admin.js` | 4 | 300 |
| 28 | `admin/views/dashboard.php` | 4 | 180 |
| 29 | `admin/views/settings.php` | 4 | 200 |
| 30 | `admin/views/agents.php` | 4 | 120 |
| 31 | `admin/views/proposals.php` | 4 | 120 |
| 32 | `admin/views/modules.php` | 4 | 80 |
| 33 | `public/css/wp-claw-public.css` | 5 | 100 |
| 34 | `public/css/wp-claw-chat.css` | 5 | 200 |
| 35 | `public/js/wp-claw-public.js` | 5 | 150 |
| 36 | `public/js/wp-claw-chat.js` | 5 | 400 |
| 37 | `wp-claw.php` | 6 | 80 |
| 38 | `uninstall.php` | 6 | 50 |
| 39 | `readme.txt` | 6 | 200 |
| 40 | `composer.json` | 6 | 25 |
| 41 | `phpcs.xml` | 6 | 20 |
| -- | **TOTAL** | -- | **~6,700** |

**Note:** `languages/wp-claw.pot` is generated (not hand-written) via `wp i18n make-pot`. Directory protection `index.php` files are placed in every directory during implementation (trivial, not counted). `assets/` directory contents (icons, banners, screenshots) are design assets created separately.

---

## 7. Constraints and Decisions

1. **No autoloader** — Manual `require_once` in `wp-claw.php`. Avoids Composer dependency for production. Keeps the plugin zero-dependency for wordpress.org.

2. **No TypeScript/build step for admin JS** — Vanilla JS. Keeps the plugin simple and avoids wp-scripts build dependency. Can add React admin later if needed.

3. **No custom post types** — Tasks, proposals, and analytics use custom tables, not CPTs. Cleaner separation from WP content.

4. **WooCommerce conditional** — Commerce module checks `class_exists('WooCommerce')` in `is_available()`. All WooCommerce hooks wrapped in availability check. Plugin works fully without WooCommerce.

5. **Chat widget as separate assets** — `wp-claw-chat.js` and `wp-claw-chat.css` only loaded when chat module enabled. Reduces payload for non-chat users.

6. **Backup directory in uploads** — `wp-content/uploads/wp-claw-backups/` for compatibility with all hosting. Protected with `.htaccess` deny rule AND `index.php` (for nginx which ignores `.htaccess`).

7. **No direct AJAX** — All client-server communication uses WP REST API, not `admin-ajax.php`. Modern, type-safe, and better for caching.

8. **Transient-based task queue** — Hook events queue tasks in a transient, processed on `shutdown`. Prevents blocking page loads with HTTP calls to Klawty. Race-condition-safe via transient lock.

9. **Directory protection** — Every PHP directory gets an `index.php` containing `<?php // Silence is golden.` to prevent directory listing on servers without `.htaccess` support.

10. **Standard error shape** — All modules return errors as `WP_Error` with code format `wp_claw_{module}_{error}`, e.g., `new WP_Error('wp_claw_seo_post_not_found', 'Post not found', ['status' => 404])`. The REST API translates `WP_Error` objects to JSON responses with matching HTTP status codes.

11. **GDPR external-request consent** — The plugin's core functionality requires HTTP calls to the Klawty instance. Consent is implied by the admin explicitly entering the API key and activating the plugin, which constitutes informed opt-in. This is documented in the plugin description and settings page. Analytics pixel requires separate opt-in via cookie consent (see Section 2.3.8).

12. **Free vs Managed feature gating** — Enforced on the Klawty side, not in the plugin. The API returns 429 or a specific error code when daily action limits are reached. The plugin displays the error message to the admin. The plugin itself does not track or enforce action counts — this avoids client-side bypass.

13. **DB upgrade path** — `WP_Claw::init()` compares `WP_CLAW_DB_VERSION` constant against `get_option('wp_claw_db_version')`. If the constant is newer, runs `Activator::create_tables()` (idempotent via `dbDelta()`) and updates the stored version. This handles schema changes across plugin updates.

14. **composer.json** — Dev dependencies only: `wp-coding-standards/wpcs` (^3.0), `dealerdirect/phpcodesniffer-composer-installer` (^1.0), `phpunit/phpunit` (^9.0), `yoast/phpunit-polyfills` (^2.0). No runtime dependencies. Not shipped in the wordpress.org ZIP.

15. **phpcs.xml** — Configures `WordPress-Extra` ruleset, excludes `vendor/`, `node_modules/`, and `tests/` directories. Sets text domain to `wp-claw` and minimum WP version to 6.4.

---

## 8. Testing Strategy

**Unit tests (PHPUnit):**
- Encryption: encrypt/decrypt cycle, corrupted data handling, missing sodium fallback
- Sanitization: each sanitizer with valid/invalid/malicious input
- API client: mock `wp_remote_request`, test retry logic, circuit breaker thresholds
- REST API: signature verification (valid, invalid, expired, missing)
- Modules: `get_allowed_actions()` completeness, `handle_action()` with mocked API client
- Activator: table creation DDL, default options
- Uninstall: clean removal verification

**Integration tests:**
- Full request cycle: WP hook -> task queue -> API client call
- Proposal lifecycle: create -> approve/reject -> status update
- Chat: message send -> rate limit -> response rendering
- Cron: event scheduling, handler execution

**E2E tests (Cypress):**
- Admin dashboard loads, shows agent cards
- Settings: enter API key, test connection, save
- Proposals: approve button sends correct REST request
- Chat widget: opens, sends message, receives response
- Module toggles: enable/disable persists correctly
