# WP-Claw Full Build Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete WP-Claw WordPress plugin — 41 files, 6 layers, ~6,700 lines — connecting WordPress sites to Klawty AI agent instances via REST/HTTP.

**Architecture:** Bottom-up layered build. Helpers (pure functions, zero deps) first, then core classes (API client, REST bridge, admin, cron, hooks, lifecycle), then 11 modules (each extending Module_Base), then admin views/assets, public chat widget + analytics pixel, and finally root bootstrap files. Each layer only depends on layers below it.

**Tech Stack:** PHP 7.4+, WordPress 6.4+ APIs, sodium_crypto_secretbox, WP REST API, WP-Cron, vanilla JS, CSS. Dev: PHPCS (WordPress-Extra), PHPUnit. No Composer runtime deps.

**Spec:** `docs/superpowers/specs/2026-03-21-wp-claw-full-build-design.md`

**WPCS rules (apply to ALL PHP files):**
- Every file starts with `<?php` followed by a PHPDoc block, then `defined( 'ABSPATH' ) || exit;`
- All output escaped: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- All input sanitized: `sanitize_text_field()`, `absint()`, etc.
- All DB queries: `$wpdb->prepare()`
- All admin actions: nonce verification + capability checks
- Text domain: `wp-claw` for all translatable strings
- Prefix: `wp_claw_` for all functions, options, hooks, transients, cron events

**PHP file header template (use for EVERY PHP file):**
```php
<?php
/**
 * [Description]
 *
 * @package    WPClaw
 * @subpackage WPClaw/[subpackage]
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;
```

---

## File Map

### Layer 1: Helpers (4 files)
| File | Responsibility |
|------|---------------|
| `includes/helpers/logger.php` | Structured logging with severity levels |
| `includes/helpers/encryption.php` | sodium_crypto_secretbox encrypt/decrypt for API keys |
| `includes/helpers/sanitization.php` | Data-structure-specific sanitization wrappers |
| `includes/helpers/capabilities.php` | Custom capabilities + role management |

### Layer 2: Core (10 files)
| File | Responsibility |
|------|---------------|
| `includes/class-api-client.php` | HTTP client to Klawty with circuit breaker + retry |
| `includes/class-module-base.php` | Abstract base class for all modules |
| `includes/class-rest-api.php` | WP REST endpoints + HMAC signature verification |
| `includes/class-admin.php` | Admin menu, settings, asset enqueuing |
| `includes/class-cron.php` | WP-Cron event registration + handlers |
| `includes/class-hooks.php` | WP action/filter registry mapping to modules |
| `includes/class-activator.php` | Activation: tables, options, capabilities, cron |
| `includes/class-deactivator.php` | Deactivation: clear cron, delete transients |
| `includes/class-i18n.php` | Text domain loader |
| `includes/class-wp-claw.php` | Main singleton orchestrator |

### Layer 3: Modules (11 files)
| File | Agent | Responsibility |
|------|-------|---------------|
| `includes/modules/class-module-seo.php` | Scribe | SEO meta, schema, sitemap |
| `includes/modules/class-module-security.php` | Sentinel | Login monitoring, file integrity, WAF |
| `includes/modules/class-module-content.php` | Scribe | Post/page creation, translation |
| `includes/modules/class-module-crm.php` | Commerce | Lead capture, scoring, pipeline |
| `includes/modules/class-module-commerce.php` | Commerce | WooCommerce integration |
| `includes/modules/class-module-performance.php` | Analyst | CWV, DB cleanup, cache |
| `includes/modules/class-module-forms.php` | Architect | Form builder, submissions |
| `includes/modules/class-module-analytics.php` | Analyst | Privacy-first analytics |
| `includes/modules/class-module-backup.php` | Sentinel | DB + uploads backup |
| `includes/modules/class-module-social.php` | Scribe | Social post generation |
| `includes/modules/class-module-chat.php` | Concierge | Chat widget + REST endpoints |

### Layer 4: Admin Assets (7 files)
| File | Responsibility |
|------|---------------|
| `admin/css/wp-claw-admin.css` | Admin dashboard styles |
| `admin/js/wp-claw-admin.js` | Admin interactions (AJAX, settings, refresh) |
| `admin/views/dashboard.php` | Main dashboard view |
| `admin/views/settings.php` | Settings page |
| `admin/views/agents.php` | Agent team overview |
| `admin/views/proposals.php` | Proposal approve/reject table |
| `admin/views/modules.php` | Per-module settings |

### Layer 5: Public Assets (4 files)
| File | Responsibility |
|------|---------------|
| `public/css/wp-claw-public.css` | Analytics pixel minimal styles |
| `public/css/wp-claw-chat.css` | Chat widget styles |
| `public/js/wp-claw-public.js` | Analytics pixel |
| `public/js/wp-claw-chat.js` | Chat widget logic |

### Layer 6: Root (5 files)
| File | Responsibility |
|------|---------------|
| `wp-claw.php` | Plugin entry point, constants, bootstrap |
| `uninstall.php` | Clean removal |
| `readme.txt` | WordPress.org listing |
| `composer.json` | Dev dependencies |
| `phpcs.xml` | PHPCS configuration |

### Directory Protection
Every PHP directory gets an `index.php` containing:
```php
<?php
// Silence is golden.
```
Directories: `includes/`, `includes/helpers/`, `includes/modules/`, `admin/`, `admin/css/`, `admin/js/`, `admin/views/`, `public/`, `public/css/`, `public/js/`, `languages/`

---

## Task 1: Build Infrastructure

**Files:**
- Create: `composer.json`
- Create: `phpcs.xml`
- Create: 11 `index.php` directory protection files

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "dcode/wp-claw",
    "description": "WP-Claw — The AI Operating Layer for WordPress",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require-php": "7.4",
    "require-dev": {
        "wp-coding-standards/wpcs": "^3.0",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
        "phpunit/phpunit": "^9.0",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

- [ ] **Step 2: Create phpcs.xml**

```xml
<?xml version="1.0"?>
<ruleset name="WP-Claw">
    <description>WordPress coding standards for WP-Claw</description>

    <rule ref="WordPress-Extra"/>

    <config name="testVersion" value="7.4-"/>
    <config name="text_domain" value="wp-claw"/>
    <config name="minimum_wp_version" value="6.4"/>

    <arg name="extensions" value="php"/>
    <arg value="s"/>

    <file>wp-claw.php</file>
    <file>uninstall.php</file>
    <file>includes/</file>
    <file>admin/</file>
    <file>public/</file>

    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>node_modules/*</exclude-pattern>
    <exclude-pattern>tests/*</exclude-pattern>
</ruleset>
```

- [ ] **Step 3: Create all index.php protection files**

Create identical `index.php` in each directory:
- `includes/index.php`
- `includes/helpers/index.php`
- `includes/modules/index.php`
- `admin/index.php`
- `admin/css/index.php`
- `admin/js/index.php`
- `admin/views/index.php`
- `public/index.php`
- `public/css/index.php`
- `public/js/index.php`
- `languages/index.php`

Each containing:
```php
<?php
// Silence is golden.
```

- [ ] **Step 4: Commit**

```bash
git add composer.json phpcs.xml */index.php
git commit -m "build: add composer.json, phpcs.xml, directory protection files"
```

---

## Task 2: Layer 1 — Helpers

**Files:**
- Create: `includes/helpers/logger.php`
- Create: `includes/helpers/encryption.php`
- Create: `includes/helpers/sanitization.php`
- Create: `includes/helpers/capabilities.php`

These 4 files are pure utility functions with zero dependencies on WP-Claw classes. They use only WordPress core APIs.

- [ ] **Step 1: Create logger.php**

Structured logging with `[WP-Claw]` prefix. Only logs when `WP_DEBUG_LOG` is true. Debug level requires `WP_CLAW_DEBUG` constant. Context array JSON-encoded. Messages truncated at 1000 chars. Never logs sensitive data.

Reference: Spec Section 2.1.4.

- [ ] **Step 2: Create encryption.php**

Two functions: `wp_claw_encrypt()` and `wp_claw_decrypt()`. Uses `sodium_crypto_secretbox` with key derived from `wp_salt('auth')` via SHA-256. 24-byte random nonce prepended to ciphertext, base64 encoded for storage. Returns empty string on decrypt failure. Calls `sodium_memzero()` on key after use. Fallback to `openssl_encrypt('aes-256-cbc')` if sodium unavailable.

Reference: Spec Section 2.1.1.

- [ ] **Step 3: Create sanitization.php**

Five functions: `wp_claw_sanitize_api_response()`, `wp_claw_sanitize_task_data()`, `wp_claw_sanitize_proposal_data()`, `wp_claw_sanitize_chat_message()`, `wp_claw_sanitize_module_settings()`. Each whitelists expected keys, applies appropriate `sanitize_*()` per field type, strips unexpected keys. Chat messages: 2000 char max, strip all HTML. Module settings: per-module schema validation via switch.

Reference: Spec Section 2.1.2.

- [ ] **Step 4: Create capabilities.php**

Three functions: `wp_claw_add_capabilities()`, `wp_claw_remove_capabilities()`, `wp_claw_current_user_can()`. Seven capabilities mapped to admin/editor roles per spec table. Add on activation, remove on uninstall.

Capabilities:
- `wp_claw_manage_agents` -> Administrator
- `wp_claw_approve_proposals` -> Administrator
- `wp_claw_view_dashboard` -> Editor, Administrator
- `wp_claw_manage_settings` -> Administrator
- `wp_claw_manage_modules` -> Administrator
- `wp_claw_view_analytics` -> Editor, Administrator
- `wp_claw_manage_chat` -> Administrator

Reference: Spec Section 2.1.3.

- [ ] **Step 5: Syntax check all helpers**

```bash
php -l includes/helpers/logger.php
php -l includes/helpers/encryption.php
php -l includes/helpers/sanitization.php
php -l includes/helpers/capabilities.php
```

Expected: No syntax errors detected for each file.

- [ ] **Step 6: Commit**

```bash
git add includes/helpers/
git commit -m "feat: add Layer 1 helpers — logger, encryption, sanitization, capabilities"
```

---

## Task 3: Layer 2 — Core Classes (Part 1: Foundation)

**Files:**
- Create: `includes/class-module-base.php`
- Create: `includes/class-i18n.php`
- Create: `includes/class-activator.php`
- Create: `includes/class-deactivator.php`

These are the simpler core classes with no cross-dependencies.

- [ ] **Step 1: Create class-module-base.php**

Abstract class `Module_Base` in namespace `WPClaw`. Properties: `$api_client`, `$slug`, `$agent`. Abstract methods: `get_slug(): string`, `get_name(): string`, `get_agent(): string`, `get_allowed_actions(): array`, `handle_action( string $action, array $params ): array|WP_Error`, `get_state(): array`, `register_hooks(): void`. Concrete methods: `is_available(): bool` (returns true), `get_settings_fields(): array` (returns empty array). Constructor takes `API_Client $api_client`.

Reference: Spec Section 2.3.0.

- [ ] **Step 2: Create class-i18n.php**

Class `I18n` in namespace `WPClaw`. One method: `load_textdomain()` calls `load_plugin_textdomain( 'wp-claw', false, dirname( plugin_basename( WP_CLAW_PLUGIN_FILE ) ) . '/languages' )`. Registered on `init` hook.

Reference: Spec Section 2.2.8.

- [ ] **Step 3: Create class-activator.php**

Static class `Activator` in namespace `WPClaw`. Static method `activate()`:
1. Check PHP >= 7.4 and WP >= 6.4, `wp_die()` if unmet
2. Call `self::create_tables()` — uses `dbDelta()` with DDL from Spec Section 3 (3 tables: `wp_claw_tasks`, `wp_claw_proposals`, `wp_claw_analytics`)
3. Set default options: `wp_claw_enabled_modules` (all 11 enabled), `wp_claw_db_version`, `wp_claw_connection_mode` ('managed'), `wp_claw_chat_enabled` (true), `wp_claw_chat_position` ('bottom-right'), `wp_claw_chat_welcome`, `wp_claw_chat_agent_name` ('Concierge'), `wp_claw_analytics_enabled` (false)
4. Call `wp_claw_add_capabilities()`
5. Schedule cron events (9 events: 8 from Spec Section 2.2.4 + `wp_claw_analytics_cleanup` weekly)
6. Set transient `wp_claw_activation_redirect` (30s TTL)
7. `flush_rewrite_rules()`

Static method `create_tables()`: public, uses `$wpdb`, `dbDelta()`, full DDL from spec. Must `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` for `dbDelta()`.

Reference: Spec Section 2.2.6.

- [ ] **Step 4: Create class-deactivator.php**

Static class `Deactivator` in namespace `WPClaw`. Static method `deactivate()`:
1. Clear all 9 `wp_claw_*` cron events via `wp_clear_scheduled_hook()`
2. Delete transients: `wp_claw_task_queue`, `wp_claw_last_health`, `wp_claw_circuit_failures`, `wp_claw_update_data`, `wp_claw_queue_lock`
3. Log deactivation via `wp_claw_log()`

Does NOT delete options or tables (user might reactivate).

Reference: Spec Section 2.2.7.

- [ ] **Step 5: Syntax check**

```bash
php -l includes/class-module-base.php
php -l includes/class-i18n.php
php -l includes/class-activator.php
php -l includes/class-deactivator.php
```

- [ ] **Step 6: Commit**

```bash
git add includes/class-module-base.php includes/class-i18n.php includes/class-activator.php includes/class-deactivator.php
git commit -m "feat: add Layer 2 core — module base, i18n, activator, deactivator"
```

---

## Task 4: Layer 2 — Core Classes (Part 2: API Client)

**Files:**
- Create: `includes/class-api-client.php`

The API client is the most complex core class. Other classes depend on it.

- [ ] **Step 1: Create class-api-client.php**

Class `API_Client` in namespace `WPClaw`. Properties: `$base_url`, `$api_key`, `$connection_mode`, `$consecutive_failures`, `$circuit_open_until`.

Constructor: loads connection settings from wp_options, decrypts API key.

**Public methods** (each calls `$this->request()`):
- `create_task( array $data ): array|WP_Error` — POST `/api/tasks` (30s timeout)
- `get_task( string $task_id ): array|WP_Error` — GET `/api/tasks/{id}`
- `get_agents(): array|WP_Error` — GET `/api/agents`
- `get_proposals(): array|WP_Error` — GET `/api/proposals`
- `resolve_proposal( string $id, string $action ): array|WP_Error` — POST `/api/proposals/{id}`
- `health_check(): array|WP_Error` — GET `/api/health`
- `register_hooks( array $hooks ): array|WP_Error` — POST `/api/hooks`
- `send_chat_message( array $data ): array|WP_Error` — POST `/api/chat`
- `check_for_updates(): array|WP_Error` — GET `/api/updates/check`
- `is_connected(): bool` — checks circuit state + last health transient

**Private `request()` method** — 11-step flow from Spec Section 2.2.1:
1. Check circuit breaker
2. Build URL
3. JSON-encode body
4. Generate timestamp
5. HMAC signature: `hash_hmac('sha256', $timestamp . '.' . $body, $api_key)`
6. Set headers (Content-Type, X-WPClaw-Signature, X-WPClaw-Timestamp)
7. `wp_remote_request()` with timeout
8. Error check + failure tracking
9. HTTP status check (2xx/429/5xx) with retry
10. JSON decode + sanitize
11. Return

**Circuit breaker**: 3 failures -> open 5min, doubles each time (max 60min). Tracked in transient `wp_claw_circuit_failures`. Success resets to 0.

Reference: Spec Section 2.2.1.

- [ ] **Step 2: Syntax check**

```bash
php -l includes/class-api-client.php
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-api-client.php
git commit -m "feat: add API client with circuit breaker, HMAC signing, retry logic"
```

---

## Task 5: Layer 2 — Core Classes (Part 3: REST API)

**Files:**
- Create: `includes/class-rest-api.php`

- [ ] **Step 1: Create class-rest-api.php**

Class `REST_API` in namespace `WPClaw`. Constructor takes `API_Client`. Registers routes on `rest_api_init` hook.

**Routes** (namespace `wp-claw/v1`):
- `POST /execute` — `handle_execute()`, permission: `verify_signature()`
- `GET /state` — `handle_state()`, permission: `verify_signature()`
- `POST /webhook` — `handle_webhook()`, permission: `verify_signature()`
- `POST /chat/send` — `handle_chat_send()`, permission: `__return_true` (public, rate-limited in handler)
- `GET /chat/history` — `handle_chat_history()`, permission: `__return_true` (public)
- `POST /analytics` — `handle_analytics()`, permission: `__return_true` (public, rate-limited in handler)
- `POST /proposals/(?P<id>[\\w-]+)/approve` — `handle_proposal_approve()`, permission: `current_user_can('wp_claw_approve_proposals')`
- `POST /proposals/(?P<id>[\\w-]+)/reject` — `handle_proposal_reject()`, permission: `current_user_can('wp_claw_approve_proposals')`

**`verify_signature()`**: Extract `X-WPClaw-Signature` and `X-WPClaw-Timestamp` headers. Reject if timestamp > 300s old (replay protection). Compute expected HMAC: `hash_hmac('sha256', $timestamp . '.' . $body, $api_key)`. Compare with `hash_equals()`. Return `true` or `WP_Error`.

**`handle_execute()`**: Extract `module` + `action` from body. Look up module via `WP_Claw::get_instance()->get_module()`. Check action in `get_allowed_actions()`. Call `handle_action()`. Log to tasks table. Return result.

**`handle_state()`**: Return site state array (WP version, PHP version, theme, plugins, post counts, WooCommerce status, enabled modules, pending proposals count).

**`handle_analytics()`**: Rate limit 1/s per IP hash (transient). Validate `page_url` (max 512 chars, valid URL). Max payload 2KB. Compute server-side session hash: `hash('sha256', $ip . $ua . gmdate('Y-m-d'))`. Insert into analytics table via `$wpdb->insert()` with `$wpdb->prepare()`.

**`handle_chat_send()`**: Rate limit 20 msg/min per session hash (transient). Sanitize message (2000 char max, strip HTML). Forward to Klawty via `$api_client->send_chat_message()`. Return response.

**`handle_proposal_approve/reject()`**: Get proposal ID from route param. Call `$api_client->resolve_proposal()`. Update local proposals table. Return result.

Reference: Spec Section 2.2.2.

- [ ] **Step 2: Syntax check**

```bash
php -l includes/class-rest-api.php
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-rest-api.php
git commit -m "feat: add REST API bridge with HMAC verification, rate limiting, analytics"
```

---

## Task 6: Layer 2 — Core Classes (Part 4: Admin, Cron, Hooks)

**Files:**
- Create: `includes/class-admin.php`
- Create: `includes/class-cron.php`
- Create: `includes/class-hooks.php`

- [ ] **Step 1: Create class-admin.php**

Class `Admin` in namespace `WPClaw`. Constructor takes `API_Client`.

**Menu**: Top-level "WP-Claw" (dashicons-superhero) with 5 submenus: Dashboard (wp_claw_view_dashboard), Agents (wp_claw_manage_agents), Proposals (wp_claw_approve_proposals), Settings (wp_claw_manage_settings), Modules (wp_claw_manage_modules).

**Asset loading**: `admin_enqueue_scripts` checks `$hook_suffix` against page slugs. Loads `admin/css/wp-claw-admin.css` and `admin/js/wp-claw-admin.js` with `wp_localize_script()` passing `restUrl`, `ajaxUrl`, `nonce` (wp_rest), `adminNonce`, `agents`, `modules`, `i18n`.

**Settings registration**: Uses Settings API. Sections: Connection, Modules, Chat, Analytics, Updates. All options per Spec Section 2.2.3 table.

**Admin bar**: Adds status badge on `admin_bar_menu` hook — green/yellow/red dot based on `is_connected()`.

**Activation redirect**: Checks `wp_claw_activation_redirect` transient on `admin_init`, redirects to settings page, deletes transient.

**View rendering**: Each submenu callback includes the corresponding `admin/views/*.php` file.

Reference: Spec Section 2.2.3.

- [ ] **Step 2: Create class-cron.php**

Class `Cron` in namespace `WPClaw`. Constructor takes `API_Client`.

**8 events** (all using built-in WP schedules — NO custom intervals):
- `wp_claw_health_check` (hourly) -> `run_health_check()`
- `wp_claw_sync_state` (hourly) -> `run_sync_state()`
- `wp_claw_update_check` (twicedaily) -> `run_update_check()`
- `wp_claw_security_scan` (twicedaily) -> `run_module_cron('security')`
- `wp_claw_backup` (daily) -> `run_module_cron('backup')`
- `wp_claw_seo_audit` (daily) -> `run_module_cron('seo')`
- `wp_claw_analytics_report` (weekly) -> `run_module_cron('analytics')`
- `wp_claw_performance_check` (weekly) -> `run_module_cron('performance')`

Plus: `wp_claw_analytics_cleanup` (weekly) -> purge analytics records older than retention period (default 90 days).

`run_health_check()`: Call `$api_client->health_check()`, store in transient `wp_claw_last_health` (1h TTL). Log on failure.

`run_sync_state()`: Gather state, POST to Klawty, store sync timestamp.

`run_module_cron($module)`: Check if enabled, create task via API client.

Reference: Spec Section 2.2.4.

- [ ] **Step 3: Create class-hooks.php**

Class `Hooks` in namespace `WPClaw`. Constructor takes `API_Client`.

**Hook map**: Static array mapping WP actions to module slugs:
```
save_post -> [seo, content]
publish_post -> [seo, social]
wp_login_failed -> [security]
wp_login -> [security]
comment_post -> [content]
woocommerce_order_status_changed -> [commerce]
woocommerce_low_stock -> [commerce]
woocommerce_new_order -> [commerce]
wpforms_process_complete -> [crm]
gform_after_submission -> [crm]
wpcf7_mail_sent -> [crm]
```

`register_hooks()`: Iterate map. Skip disabled modules. WooCommerce hooks wrapped in `class_exists('WooCommerce')` guard. Each callback builds task payload and calls `wp_claw_queue_task()`.

`wp_claw_queue_task()`: Appends to transient array `wp_claw_task_queue`.

`process_queue()`: Registered on `shutdown` hook. Acquires transient lock `wp_claw_queue_lock` (30s TTL). Iterates queue, calls `$api_client->create_task()` for each. Removes successful items individually. Releases lock.

Reference: Spec Section 2.2.5.

- [ ] **Step 4: Syntax check**

```bash
php -l includes/class-admin.php
php -l includes/class-cron.php
php -l includes/class-hooks.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/class-admin.php includes/class-cron.php includes/class-hooks.php
git commit -m "feat: add admin, cron scheduler, and hook registry with task queue"
```

---

## Task 7: Layer 2 — Core Classes (Part 5: Main Singleton)

**Files:**
- Create: `includes/class-wp-claw.php`

- [ ] **Step 1: Create class-wp-claw.php**

Class `WP_Claw` in namespace `WPClaw`. Singleton pattern.

**Properties**: `$instance`, `$api_client`, `$rest_api`, `$admin`, `$cron`, `$hooks`, `$i18n`, `$modules` (array).

**Module registry** (static array):
```php
'seo' => 'WPClaw\\Modules\\Module_SEO',
'security' => 'WPClaw\\Modules\\Module_Security',
'content' => 'WPClaw\\Modules\\Module_Content',
'crm' => 'WPClaw\\Modules\\Module_CRM',
'commerce' => 'WPClaw\\Modules\\Module_Commerce',
'performance' => 'WPClaw\\Modules\\Module_Performance',
'forms' => 'WPClaw\\Modules\\Module_Forms',
'analytics' => 'WPClaw\\Modules\\Module_Analytics',
'backup' => 'WPClaw\\Modules\\Module_Backup',
'social' => 'WPClaw\\Modules\\Module_Social',
'chat' => 'WPClaw\\Modules\\Module_Chat',
```

**`init()` sequence** (11 steps from Spec Section 2.2.9):
1. Require helpers
2. DB upgrade check (compare `WP_CLAW_DB_VERSION` vs stored `wp_claw_db_version`)
3. Instantiate I18n
4. Instantiate API_Client
5. Instantiate REST_API
6. Instantiate Cron
7. Instantiate Hooks
8. Load enabled modules (check `wp_claw_enabled_modules` option)
9. If `is_admin()`, instantiate Admin
10. If front-end + chat enabled, enqueue public assets via `wp_enqueue_scripts` hook
11. Check activation redirect

**Public API**: `get_instance()`, `get_api_client()`, `get_module($slug)`, `get_enabled_modules()`, `is_module_enabled($slug)`.

Reference: Spec Section 2.2.9.

- [ ] **Step 2: Syntax check**

```bash
php -l includes/class-wp-claw.php
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-wp-claw.php
git commit -m "feat: add main WP_Claw singleton with module loader and DB upgrade"
```

---

## Task 7b: Update System Integration

**Files:**
- Modify: `includes/class-wp-claw.php` (add update hooks in `init()`)

The update system hooks into WordPress core's plugin update infrastructure. No separate file needed — the logic lives in the main singleton class.

- [ ] **Step 1: Add update system hooks to class-wp-claw.php**

Add three filter registrations in `init()` (after module loading):

1. `pre_set_site_transient_update_plugins` -> `check_for_updates()`: calls `$api_client->check_for_updates()` passing `WP_CLAW_VERSION`, WP version, PHP version. If a newer version exists, inject update data into the transient (slug, new_version, url, package URL, tested WP version).

2. `plugins_api` -> `plugin_info()`: When WordPress requests info for `wp-claw`, return plugin info object (name, slug, version, author, homepage, sections with description + changelog + installation).

3. `upgrader_post_install` -> `post_install()`: After update extraction, rename directory if needed, return `$result`.

**Ed25519 signature verification:** Before returning the package URL, verify the download signature via `sodium_crypto_sign_verify_detached()` using the pinned public key constant `WP_CLAW_UPDATE_PUBLIC_KEY`. If verification fails, return `WP_Error` and do not offer the update.

**Pre-update backup:** In `post_install()`, before applying, copy current plugin directory to `wp-content/uploads/wp-claw-backups/pre-update-{version}/` via `WP_Filesystem`.

**Transient caching:** Update check response cached in `wp_claw_update_data` transient (12h TTL). Invalidated on manual check or reactivation.

Also add the `WP_CLAW_UPDATE_PUBLIC_KEY` constant to `wp-claw.php` (placeholder value for now — real key generated at first release).

Reference: Spec Section 5.

- [ ] **Step 2: Syntax check**

```bash
php -l includes/class-wp-claw.php
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-wp-claw.php
git commit -m "feat: add update system with Ed25519 verification and WordPress native integration"
```

---

## Task 8: Layer 3 — Modules (Batch 1: SEO, Security, Content)

**Files:**
- Create: `includes/modules/class-module-seo.php`
- Create: `includes/modules/class-module-security.php`
- Create: `includes/modules/class-module-content.php`

All modules extend `Module_Base`. Each implements: `get_slug()`, `get_name()`, `get_agent()`, `get_allowed_actions()`, `handle_action()`, `get_state()`, `register_hooks()`.

- [ ] **Step 1: Create class-module-seo.php**

`Module_SEO` extends `Module_Base`. Slug: `seo`. Agent: `scribe`. Allowed actions: `update_post_meta_title`, `update_post_meta_description`, `update_schema_markup`, `generate_sitemap`, `analyze_content`, `suggest_internal_links`, `update_robots_txt`.

`handle_action()`: Switch on action. For `update_post_meta_title`: validate `$params['post_id']` with `absint()`, check post exists with `get_post()`, update with `update_post_meta($post_id, '_wp_claw_seo_title', sanitize_text_field($params['title']))`. Similar pattern for each action. Return `['success' => true, 'data' => ...]` or `WP_Error`.

`register_hooks()`: `save_post` -> queue SEO audit. `publish_post` -> queue sitemap update.

`get_state()`: Return total posts, posts with/without meta titles/descriptions, last sitemap time.

Reference: Spec Section 2.3.1.

- [ ] **Step 2: Create class-module-security.php**

`Module_Security` extends `Module_Base`. Slug: `security`. Agent: `sentinel`. Allowed actions: `block_ip`, `update_security_headers`, `log_security_event`, `run_file_integrity_check`, `enable_brute_force_protection`, `update_htaccess_rules`, `get_login_attempts`.

`handle_action()`: `block_ip` validates IP format, stores in option `wp_claw_blocked_ips`. `update_security_headers` sets predefined headers via `send_headers` hook. `get_login_attempts` queries from option `wp_claw_login_attempts`.

`register_hooks()`: `wp_login_failed` -> log attempt, check threshold. `wp_login` -> log success.

`get_state()`: Failed logins (24h), blocked IPs count, file integrity status, last scan time.

Reference: Spec Section 2.3.2.

- [ ] **Step 3: Create class-module-content.php**

`Module_Content` extends `Module_Base`. Slug: `content`. Agent: `scribe`. Allowed actions: `create_draft_post`, `update_post_content`, `create_page`, `translate_post`, `generate_excerpt`.

`handle_action()`: `create_draft_post` uses `wp_insert_post()` with `post_status => 'draft'`. `update_post_content` uses `wp_update_post()`. All sanitize inputs via `wp_kses_post()` for content, `sanitize_text_field()` for titles.

`register_hooks()`: None by default.

`get_state()`: Post counts by status, recently modified posts (7 days).

Reference: Spec Section 2.3.3.

- [ ] **Step 4: Syntax check**

```bash
php -l includes/modules/class-module-seo.php
php -l includes/modules/class-module-security.php
php -l includes/modules/class-module-content.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/modules/class-module-seo.php includes/modules/class-module-security.php includes/modules/class-module-content.php
git commit -m "feat: add SEO, Security, and Content modules"
```

---

## Task 9: Layer 3 — Modules (Batch 2: CRM, Commerce, Performance)

**Files:**
- Create: `includes/modules/class-module-crm.php`
- Create: `includes/modules/class-module-commerce.php`
- Create: `includes/modules/class-module-performance.php`

- [ ] **Step 1: Create class-module-crm.php**

Slug: `crm`. Agent: `commerce`. Allowed actions: `capture_lead`, `update_lead_status`, `score_lead`, `create_followup_task`, `get_leads`.

`handle_action()`: `capture_lead` sanitizes name/email/source/notes, stores as task with `module='crm'` and structured details JSON in tasks table. `score_lead` validates score 1-100 via `absint()` + `min(100, ...)`.

`register_hooks()`: Form plugin hooks: `wpforms_process_complete`, `gform_after_submission`, `wpcf7_mail_sent` -> extract form data, capture as lead.

Reference: Spec Section 2.3.4.

- [ ] **Step 2: Create class-module-commerce.php**

Slug: `commerce`. Agent: `commerce`. Allowed actions: `update_stock_alert`, `update_product_price`, `create_coupon`, `get_orders`, `get_products`, `send_abandoned_cart_reminder`, `update_product_description`.

**Critical**: `is_available()` returns `class_exists('WooCommerce')`.

`handle_action()`: Uses WooCommerce API: `wc_get_product()`, `wc_get_orders()`, `WC_Coupon` class. All wrapped in WooCommerce availability checks.

`register_hooks()`: `woocommerce_order_status_changed`, `woocommerce_low_stock`, `woocommerce_new_order`.

Reference: Spec Section 2.3.5.

- [ ] **Step 3: Create class-module-performance.php**

Slug: `performance`. Agent: `analyst`. Allowed actions: `get_core_web_vitals`, `run_db_cleanup`, `optimize_images`, `suggest_cache_strategy`, `get_page_speed_data`.

`handle_action()`: `run_db_cleanup` uses `$wpdb->query()` with `$wpdb->prepare()` to delete post revisions (limit 500), spam comments, expired transients. All queries prepared. Returns counts of deleted items.

`get_state()`: DB size, autoloaded options size, revision count, spam count, transient count.

Reference: Spec Section 2.3.6.

- [ ] **Step 4: Syntax check**

```bash
php -l includes/modules/class-module-crm.php
php -l includes/modules/class-module-commerce.php
php -l includes/modules/class-module-performance.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/modules/class-module-crm.php includes/modules/class-module-commerce.php includes/modules/class-module-performance.php
git commit -m "feat: add CRM, Commerce (WooCommerce), and Performance modules"
```

---

## Task 10: Layer 3 — Modules (Batch 3: Forms, Analytics, Backup)

**Files:**
- Create: `includes/modules/class-module-forms.php`
- Create: `includes/modules/class-module-analytics.php`
- Create: `includes/modules/class-module-backup.php`

- [ ] **Step 1: Create class-module-forms.php**

Slug: `forms`. Agent: `architect`. Allowed actions: `create_form`, `get_submissions`, `update_form`, `delete_submission`.

`handle_action()`: Forms stored as serialized arrays in option `wp_claw_forms`. Submissions stored as tasks with `module='forms'`. `delete_submission` returns `WP_Error('wp_claw_forms_propose_required')` suggesting PROPOSE tier.

Reference: Spec Section 2.3.7.

- [ ] **Step 2: Create class-module-analytics.php**

Slug: `analytics`. Agent: `analyst`. Allowed actions: `get_pageviews`, `get_top_pages`, `get_referrers`, `get_device_breakdown`, `generate_report`.

`handle_action()`: All read from `wp_claw_analytics` table via prepared queries. `get_pageviews` accepts date range params. `get_top_pages` does `GROUP BY page_url ORDER BY COUNT(*) DESC LIMIT 10`. `generate_report` aggregates all data into a summary array.

`register_hooks()`: Enqueue analytics pixel on `wp_footer` (only when analytics module enabled).

Reference: Spec Section 2.3.8.

- [ ] **Step 3: Create class-module-backup.php**

Slug: `backup`. Agent: `sentinel`. Allowed actions: `create_backup`, `list_backups`, `restore_backup`, `delete_old_backups`, `verify_backup`.

`handle_action()`: Uses `WP_Filesystem` API for all file ops. `create_backup` exports DB tables via `$wpdb`, compresses with `gzencode()`, saves to `wp-content/uploads/wp-claw-backups/{timestamp}/`. Creates `.htaccess` with `deny from all` and `index.php` in backup dir. `restore_backup` returns `WP_Error('wp_claw_backup_confirm_required')` (CONFIRM tier). `list_backups` uses `WP_Filesystem->dirlist()`. Retention default: 7 days.

Reference: Spec Section 2.3.9.

- [ ] **Step 4: Syntax check**

```bash
php -l includes/modules/class-module-forms.php
php -l includes/modules/class-module-analytics.php
php -l includes/modules/class-module-backup.php
```

- [ ] **Step 5: Commit**

```bash
git add includes/modules/class-module-forms.php includes/modules/class-module-analytics.php includes/modules/class-module-backup.php
git commit -m "feat: add Forms, Analytics, and Backup modules"
```

---

## Task 11: Layer 3 — Modules (Batch 4: Social, Chat)

**Files:**
- Create: `includes/modules/class-module-social.php`
- Create: `includes/modules/class-module-chat.php`

- [ ] **Step 1: Create class-module-social.php**

Slug: `social`. Agent: `scribe`. Allowed actions: `create_social_post`, `schedule_post`, `get_scheduled_posts`.

`handle_action()`: Social posts stored as tasks with `module='social'` and details JSON containing platform, text, scheduled_time. Actual posting happens on Klawty side.

`register_hooks()`: `publish_post` -> queue social post generation task.

Reference: Spec Section 2.3.10.

- [ ] **Step 2: Create class-module-chat.php**

Slug: `chat`. Agent: `concierge`. Allowed actions: `get_product_catalog`, `get_order_status`, `search_knowledge_base`, `capture_chat_lead`, `escalate_to_human`.

`handle_action()`: `get_product_catalog` uses `wc_get_products()` if WooCommerce available, falls back to recent posts. `search_knowledge_base` queries option `wp_claw_chat_faq` (array of `{question, answer}` pairs). `capture_chat_lead` delegates to CRM module.

`register_hooks()`: `wp_footer` -> render chat container div. `wp_enqueue_scripts` -> conditionally enqueue `public/js/wp-claw-chat.js` and `public/css/wp-claw-chat.css` with localized config data (position, colors, welcome message, agent name, rest URL, business hours).

Reference: Spec Section 2.3.11.

- [ ] **Step 3: Syntax check**

```bash
php -l includes/modules/class-module-social.php
php -l includes/modules/class-module-chat.php
```

- [ ] **Step 4: Commit**

```bash
git add includes/modules/class-module-social.php includes/modules/class-module-chat.php
git commit -m "feat: add Social and Chat modules with frontend widget hooks"
```

---

## Task 12: Layer 4 — Admin CSS

**Files:**
- Create: `admin/css/wp-claw-admin.css`

- [ ] **Step 1: Create wp-claw-admin.css**

~400 lines. All selectors prefixed `.wp-claw-admin-*`. Key components:

- **Dashboard grid**: CSS Grid, 4-column KPI row, responsive (2-col on tablet, 1-col on mobile)
- **Agent cards**: Flex row, colored left border (green=healthy, yellow=degraded, red=down), agent emoji, name, role, status, current task
- **KPI cards**: Large number, label, subtle background, icon
- **Proposal table**: Standard WP table styling + action buttons (approve green, reject red)
- **Settings form**: Standard WP admin form layout, password field with show/hide toggle
- **Module toggles**: Custom switch (checkbox + label slider)
- **Connection indicator**: Pulsing dot animation (green/yellow/red)
- **Task list**: Compact list with agent badge, title, status pill
- **Status pills**: Colored badges for pending/in_progress/done/failed
- **Responsive**: Stack on < 782px (WP admin breakpoint)
- Use `var(--wp-admin-theme-color)` for accent color compatibility

- [ ] **Step 2: Commit**

```bash
git add admin/css/wp-claw-admin.css
git commit -m "feat: add admin dashboard styles"
```

---

## Task 13: Layer 4 — Admin JS

**Files:**
- Create: `admin/js/wp-claw-admin.js`

- [ ] **Step 1: Create wp-claw-admin.js**

~300 lines. Vanilla JS, no jQuery. Uses `wpClaw` object from `wp_localize_script`.

**Features:**
1. **Proposal actions**: Click handler on approve/reject buttons. `fetch(wpClaw.restUrl + 'proposals/' + id + '/approve', { method: 'POST', headers: { 'X-WP-Nonce': wpClaw.nonce } })`. Update row on success. Show error notice on failure.

2. **Dashboard refresh**: `setInterval()` every 60s on dashboard page. Fetch agent status from REST API. Update agent cards, KPI numbers.

3. **Settings form**: Validate API key (non-empty). Connection test button: calls health check endpoint, shows success/failure notice. Module toggles: AJAX save via REST on change.

4. **Admin notices**: Dismissible notices (`.wp-claw-admin-notice .notice-dismiss` click handler removes parent).

5. **Tab navigation**: For module settings page. Click tab -> show panel, update active state.

All fetch calls include `'X-WP-Nonce': wpClaw.nonce` header.

- [ ] **Step 2: Commit**

```bash
git add admin/js/wp-claw-admin.js
git commit -m "feat: add admin JS — proposal actions, dashboard refresh, settings"
```

---

## Task 14: Layer 4 — Admin Views

**Files:**
- Create: `admin/views/dashboard.php`
- Create: `admin/views/settings.php`
- Create: `admin/views/agents.php`
- Create: `admin/views/proposals.php`
- Create: `admin/views/modules.php`

All views are included by `class-admin.php`. All output MUST be escaped. All forms MUST include nonce fields.

- [ ] **Step 1: Create dashboard.php**

Layout: wrap div with `.wp-claw-admin-dashboard`. KPI row (4 cards: tasks count, pending proposals, active agents, health status). Recent tasks list (last 10 from tasks table, each with agent badge + title + status pill). Agent team grid (cards from `$api_client->get_agents()` with transient cache). All dynamic values via `esc_html()`.

- [ ] **Step 2: Create settings.php**

WordPress Settings API rendering: `settings_fields('wp_claw_settings')`, `do_settings_sections('wp-claw-settings')`, `submit_button()`. Connection section with masked API key input, mode radio, instance URL (conditionally shown). Manual "Test Connection" button. "Check for Updates" button.

- [ ] **Step 3: Create agents.php**

Agent team page. Fetches agents from API client (transient-cached 5min). Grid of agent cards: name, emoji, role, current task or "Idle", health dot, tasks today, uptime. Graceful fallback if API unreachable: "Cannot connect to Klawty instance" notice.

- [ ] **Step 4: Create proposals.php**

WP List Table style. Columns: Agent, Action, Details (truncated to 100 chars), Created (human_time_diff), Status, Actions. Approve/Reject buttons with `data-proposal-id` attribute. Handled by admin JS. Empty state: "No pending proposals."

- [ ] **Step 5: Create modules.php**

Tab interface. One tab per enabled module. Each tab shows module name, description, status (enabled/disabled), agent assignment, and settings fields from `$module->get_settings_fields()`. Uses standard WP form rendering.

- [ ] **Step 6: Syntax check all views**

```bash
php -l admin/views/dashboard.php
php -l admin/views/settings.php
php -l admin/views/agents.php
php -l admin/views/proposals.php
php -l admin/views/modules.php
```

- [ ] **Step 7: Commit**

```bash
git add admin/views/
git commit -m "feat: add admin views — dashboard, settings, agents, proposals, modules"
```

---

## Task 15: Layer 5 — Public CSS

**Files:**
- Create: `public/css/wp-claw-public.css`
- Create: `public/css/wp-claw-chat.css`

- [ ] **Step 1: Create wp-claw-public.css**

~100 lines. Minimal styles for analytics pixel (hidden), and any non-chat public elements. All selectors `.wp-claw-*`.

- [ ] **Step 2: Create wp-claw-chat.css**

~200 lines. All selectors `.wp-claw-chat-*`. Components:
- **Button**: Fixed position (bottom-right/bottom-left configurable via CSS class), 56px circle, customizable `--wp-claw-accent` color, box-shadow, z-index 999999, hover scale
- **Window**: 380px wide, 500px tall, border-radius, box-shadow, flex column (header + messages + input)
- **Header**: Agent name, close button, colored bar
- **Messages**: Scrollable flex column. Agent bubbles (left, light bg). Visitor bubbles (right, accent bg, white text)
- **Product cards**: Inline cards within chat (image, title, price, link)
- **Quick replies**: Horizontal scroll row of pill buttons
- **Typing indicator**: Three bouncing dots animation
- **Input**: Text input + send button, border-top separator
- **Mobile**: Full-width below 480px, full-height below 600px
- **Transitions**: Smooth open/close with transform + opacity

- [ ] **Step 3: Commit**

```bash
git add public/css/
git commit -m "feat: add public CSS — analytics minimal + chat widget styles"
```

---

## Task 16: Layer 5 — Public JS

**Files:**
- Create: `public/js/wp-claw-public.js`
- Create: `public/js/wp-claw-chat.js`

- [ ] **Step 1: Create wp-claw-public.js**

~150 lines. `WPClawAnalytics` class.

```javascript
class WPClawAnalytics {
    constructor(config) // { restUrl }
    init()              // check consent, fire on DOMContentLoaded
    hasConsent()        // check window.wpClawAnalyticsConsent || cookie || !doNotTrack
    trackPageview()     // POST /wp-json/wp-claw/v1/analytics with page_url, referrer, device_type
    getDeviceType()     // mobile (<768) / tablet (<1024) / desktop
}
```

- Consent gate: only fires if `window.wpClawAnalyticsConsent === true` OR cookie `wp_claw_analytics_consent=1` exists. Respects `navigator.doNotTrack === '1'` (skip). Does NOT fire by default if no consent signal detected.
- Session hash computed server-side (client does NOT send it).
- One pageview per page load, debounced.
- Uses `fetch()` with `keepalive: true` for reliability during page unload.

- [ ] **Step 2: Create wp-claw-chat.js**

~400 lines. `WPClawChat` class.

```javascript
class WPClawChat {
    constructor(config) // { position, accentColor, welcomeMessage, agentName, agentAvatar, restUrl, businessHours, faq }
    init()              // create DOM, bind events, check business hours, show welcome
    createDOM()         // build button + window HTML, inject into body
    toggle()            // open/close window with animation
    open() / close()
    sendMessage(text)   // POST /wp-json/wp-claw/v1/chat/send { session_id, message, page_url }
    receiveMessage(data)// render agent response, suggestions, products
    renderMessage(msg)  // append bubble to message list, auto-scroll
    renderProducts(arr) // render product cards inside chat
    renderSuggestions(arr) // render quick reply buttons
    showTyping()        // show typing indicator
    hideTyping()        // hide typing indicator
    checkBusinessHours()// compare current time against config, show/hide or "leave message" mode
    getSessionId()      // crypto.randomUUID() stored in sessionStorage, or fallback
}
```

- Session ID: `crypto.randomUUID()` with fallback for older browsers (`Math.random()` based UUID v4). Stored in `sessionStorage`.
- Message polling: `setInterval(3000)` while window open, fetch new messages.
- Product cards: thumbnail, title, price, "View" link to product page.
- Quick replies: click sends the reply text as a message.
- Accessibility: `role="dialog"`, `aria-label`, keyboard navigation (Escape closes, Tab traps focus in window).
- All user input stripped of HTML before sending.

- [ ] **Step 3: Commit**

```bash
git add public/js/
git commit -m "feat: add public JS — analytics pixel with consent gate, chat widget"
```

---

## Task 17: Layer 6 — Root Files

**Files:**
- Create: `wp-claw.php`
- Create: `uninstall.php`
- Create: `readme.txt`

- [ ] **Step 1: Create wp-claw.php**

~100 lines. Plugin entry point.

Plugin header with all required fields (Name, URI, Description, Version 1.0.0, Requires WP 6.4, Requires PHP 7.4, Author dcode technologies, License GPL-2.0-or-later, Text Domain wp-claw, Domain Path /languages).

Constants: `WP_CLAW_VERSION` ('1.0.0'), `WP_CLAW_DB_VERSION` ('1.0.0' — string, compared against `get_option('wp_claw_db_version')` for upgrade checks), `WP_CLAW_PLUGIN_FILE`, `WP_CLAW_PLUGIN_DIR`, `WP_CLAW_PLUGIN_URL`, `WP_CLAW_PLUGIN_BASENAME`.

Manual requires in dependency order:
1. Helpers (logger, encryption, sanitization, capabilities)
2. Module base
3. Core classes (api-client, rest-api, admin, cron, hooks, activator, deactivator, i18n, wp-claw)
4. All 11 modules

Activation/deactivation hooks:
```php
register_activation_hook( __FILE__, array( 'WPClaw\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPClaw\\Deactivator', 'deactivate' ) );
```

Boot on `plugins_loaded`:
```php
add_action( 'plugins_loaded', function () {
    WPClaw\WP_Claw::get_instance()->init();
} );
```

- [ ] **Step 2: Create uninstall.php**

~60 lines. Guards with `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;`.

**Note:** `uninstall.php` runs WITHOUT the main plugin bootstrap. Must manually `require_once` the capabilities helper file for `wp_claw_remove_capabilities()`.

1. Drop 3 tables: `{prefix}wp_claw_tasks`, `{prefix}wp_claw_proposals`, `{prefix}wp_claw_analytics`
2. Delete options: `DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp_claw_%'`
3. Delete transients: both `_transient_wp_claw_%` and `_transient_timeout_wp_claw_%`
4. Remove capabilities: `require_once` capabilities helper, call `wp_claw_remove_capabilities()`
5. Clear cron: all 9 `wp_claw_*` hooks
6. Delete backup directory: `WP_Filesystem()`, `$wp_filesystem->delete()` on `wp-content/uploads/wp-claw-backups/`

- [ ] **Step 3: Create readme.txt**

WordPress.org standard format. ~200 lines.

Header: `=== WP-Claw ===`, Contributors: dcodetec, Tags, Requires at least: 6.4, Tested up to: 6.8, Stable tag: 1.0.0, Requires PHP: 7.4, License: GPLv2 or later.

Sections: Description (what it does, modules, agents), Installation (3 methods), FAQ (5 questions from README.md), Screenshots (4 placeholders), Changelog (1.0.0 Initial release), Upgrade Notice.

- [ ] **Step 4: Syntax check root PHP files**

```bash
php -l wp-claw.php
php -l uninstall.php
```

- [ ] **Step 5: Commit**

```bash
git add wp-claw.php uninstall.php readme.txt
git commit -m "feat: add plugin entry point, uninstall handler, wordpress.org readme"
```

---

## Task 18: Integration Verification

**Files:** None created. Verification only.

- [ ] **Step 1: Syntax check ALL PHP files**

```bash
find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" | while read f; do php -l "$f"; done
```

Expected: `No syntax errors detected` for every file.

- [ ] **Step 2: Verify file count**

```bash
find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" | wc -l
find . -name "*.js" | wc -l
find . -name "*.css" | wc -l
```

Expected: ~28 PHP files (including index.php), 2 JS files, 2 CSS files + admin assets.

- [ ] **Step 3: Verify all classes reference correctly**

Check that `wp-claw.php` requires every class file and that the module registry in `class-wp-claw.php` matches the actual module class names.

- [ ] **Step 4: Verify escaping compliance**

Quick grep for unescaped output patterns in admin views:

```bash
grep -rn "echo \$" admin/views/ || echo "No unescaped echo found"
grep -rn "<?=" admin/views/ || echo "No short echo tags found"
```

Expected: No matches (all output should use `esc_html()`, `esc_attr()`, etc.)

- [ ] **Step 5: Update CHANGELOG.md**

Add entry for v1.0.0 with all implemented features.

- [ ] **Step 6: Final commit**

```bash
git add CHANGELOG.md
git commit -m "docs: update CHANGELOG for v1.0.0 full build"
```

---

## Execution Notes

**Parallel opportunities within tasks:**
- Task 8, 9, 10, 11 (all module batches) are independent of each other — can run in parallel
- Task 12 + 13 (admin CSS + JS) are independent — can run in parallel
- Task 15 + 16 (public CSS + JS) are independent — can run in parallel

**Dependencies (must be sequential):**
- Task 1 before everything
- Task 2 (helpers) before Task 3-7 (core)
- Task 3 (foundation core) before Task 4 (API client)
- Task 4 (API client) before Task 5-7 (REST, admin, hooks, singleton)
- Task 7 (singleton) before Task 8-11 (modules) — modules need the registry
- Tasks 2-11 (all PHP) before Task 17 (root bootstrap — requires all files)
- Task 17 before Task 18 (verification)

**Critical order:**
```
1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 7b -> [8,9,10,11 parallel] -> [12,13 parallel] -> 14 -> [15,16 parallel] -> 17 -> 18
```

**Note on testing:** Test scaffolding (PHPUnit config, bootstrap, test directory structure) is deferred to a follow-up task after the initial build is verified. The plan focuses on getting all production code in place first.
