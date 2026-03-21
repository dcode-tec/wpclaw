# WP-Claw — The AI Operating Layer for WordPress

> **Read first:** `../docs/KLAWTY-ECOSYSTEM-STRATEGY.md` for the full Klawty ecosystem context (revenue model, portal architecture, plugin system, competitive landscape).

WP-Claw is a WordPress plugin that replaces 10-15 individual plugins with a single AI-powered operating layer. It connects any WordPress site to a managed Klawty AI agent instance via REST/HTTP. The agents handle SEO, security, content, e-commerce, analytics, CRM, forms, backups, performance, and custom development — autonomously.

**This is NOT an AI chatbot plugin. This is an operating system layer.**

---

## Identity

- **Product**: WP-Claw — AI operating layer for WordPress
- **WordPress.org slug**: `wp-claw`
- **Domain**: wp-claw.ai
- **PHP namespace**: `WPClaw`
- **Text domain**: `wp-claw` (i18n)
- **Minimum PHP**: 7.4 (WordPress minimum as of 6.8)
- **Minimum WP**: 6.4
- **Tested up to**: 6.8
- **License**: GPL-2.0-or-later (required by wordpress.org)
- **Owner**: dcode technologies S.a r.l., Luxembourg
- **Parent product**: Klawty OS (klawty.ai) — open-source agent operating system
- **Commercial backend**: ai-agent-builder.ai — managed Klawty instances

See `../docs/KLAWTY-ECOSYSTEM-STRATEGY.md` for full ecosystem context, pricing, verticals, and revenue architecture.

---

## Architecture

```
WordPress Site (any hosting)              Klawty Instance (managed by dcode)
┌───────────────────────────┐             ┌─────────────────────────────┐
│  WP-Claw Plugin (PHP)     │   HTTPS     │  5 WordPress-Specialized    │
│                           │◄───────────►│  AI Agents                  │
│  Core:                    │   REST/JSON  │                             │
│  ├── API Client           │             │  Architect (main)           │
│  ├── WP REST Bridge       │             │    Orchestrates, custom dev │
│  ├── WP-Cron Scheduler    │             │                             │
│  ├── Hook Registry        │             │  Scribe                     │
│  └── Module Loader        │             │    Content, SEO, social     │
│                           │             │                             │
│  Modules:                 │             │  Sentinel                   │
│  ├── SEO                  │             │    Security, backups, WAF   │
│  ├── Security             │             │                             │
│  ├── Content              │             │  Commerce                   │
│  ├── CRM & Leads          │             │    WooCommerce, CRM, leads  │
│  ├── Commerce (WooC)      │             │                             │
│  ├── Performance          │             │  Analyst                    │
│  ├── Forms                │             │    Analytics, A/B, reports  │
│  ├── Analytics            │             │                             │
│  ├── Backup               │             │  Concierge                  │
│  ├── Social               │             │    Live chat, product recs  │
│  └── Chat Widget          │             │    customer advisor, FAQ    │
│                           │             │                             │
│  Frontend Chat Widget     │             │  + Klawty runtime:          │
│  (floating, customizable) │             │    5-tier routing, memory,  │
│                           │             │    proposals, discovery,    │
│  Admin Dashboard (React)  │             │    dedup, circuit breaker   │
│  Settings Page            │             │                             │
│  Agent Status Widget      │             │  Gateway: HTTPS (managed)   │
│  WP Admin Bar Badge       │             │  or localhost:2508 (self)   │
└───────────────────────────┘             └─────────────────────────────┘
```

### Two Connection Modes

| Mode | Klawty Location | Auth | Use Case |
|------|----------------|------|----------|
| **Managed** | `https://wp-{id}.ai-agent-builder.ai` | API key (stored encrypted in wp_options) | 90% of customers — any hosting |
| **Self-hosted** | `http://localhost:2508` | Local token | VPS power users running Klawty natively |

### Communication Protocol

```
WP-Claw to Klawty:
  POST /api/tasks          — Create task for agent
  GET  /api/tasks/{id}     — Check task status
  GET  /api/agents         — Agent team status
  GET  /api/proposals      — Pending proposals
  POST /api/proposals/{id} — Approve/reject proposal
  GET  /api/health         — System health check
  POST /api/hooks          — Register WP event hooks

Klawty to WP-Claw (via WP REST API):
  POST /wp-json/wp-claw/v1/execute   — Execute WP action (create post, update option, etc.)
  GET  /wp-json/wp-claw/v1/state     — Get WP site state (plugins, theme, posts, orders)
  POST /wp-json/wp-claw/v1/webhook   — Receive agent completion notifications
```

The WP REST API endpoints are the **only** way agents interact with WordPress. Agents NEVER have direct database access, NEVER execute arbitrary PHP, NEVER modify files directly.

---

## Coding Standards — MANDATORY

### WordPress Coding Standards (WPCS)

This plugin MUST pass `phpcs` with WordPress-Extra ruleset. No exceptions.

```bash
# Install
composer require --dev wp-coding-standards/wpcs dealerdirect/phpcodesniffer-composer-installer

# Run
./vendor/bin/phpcs --standard=WordPress-Extra wp-claw.php includes/ admin/ public/

# Auto-fix
./vendor/bin/phpcbf --standard=WordPress-Extra wp-claw.php includes/ admin/ public/
```

### PHP Rules

- **Namespace**: `WPClaw\` for all classes
- **Class files**: `class-{name}.php` (WordPress convention)
- **Prefixing**: All functions, hooks, options, transients, cron events, REST routes prefixed with `wp_claw_`
- **Escaping**: ALL output MUST be escaped. `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`. No exceptions.
- **Sanitization**: ALL input MUST be sanitized. `sanitize_text_field()`, `absint()`, `sanitize_email()`, etc.
- **Nonces**: ALL form submissions and AJAX requests MUST verify nonces. `wp_nonce_field()`, `wp_verify_nonce()`.
- **Capabilities**: ALL admin actions MUST check capabilities. `current_user_can('manage_options')`.
- **Prepared statements**: ALL database queries MUST use `$wpdb->prepare()`. NEVER concatenate SQL.
- **No direct file access**: Every PHP file starts with `defined('ABSPATH') || exit;`
- **No code evaluation**: NEVER use functions that execute arbitrary code strings.
- **No `file_get_contents()`**: Use `wp_remote_get()` for HTTP, `WP_Filesystem` for files.
- **No raw superglobals**: Always go through `sanitize_*()` functions for `$_GET/$_POST/$_REQUEST`.
- **Type declarations**: Use PHP 7.4+ typed properties and return types where possible.
- **DocBlocks**: Every class, method, and function MUST have a PHPDoc block.

### JavaScript Rules

- **Admin scripts**: Vanilla JS or lightweight React (wp-scripts build). No jQuery dependency.
- **Enqueue properly**: `wp_enqueue_script()` / `wp_enqueue_style()` with proper dependencies.
- **Localization**: Use `wp_localize_script()` or `wp_add_inline_script()` for passing data to JS.
- **Nonces in AJAX**: Always pass and verify nonces for admin-ajax.php or REST requests.

### CSS Rules

- **Prefix all selectors**: `.wp-claw-*` to avoid conflicts.
- **Admin styles**: Only load on WP-Claw admin pages (`admin_enqueue_scripts` with page hook check).
- **Frontend styles**: Minimal. Only what's needed for forms/widgets. NEVER override theme styles globally.

---

## Security Model — CRITICAL

### The #1 Rule

**Agents NEVER have direct access to WordPress internals.** Every agent action goes through the WP REST API bridge, which validates, sanitizes, and capability-checks before executing.

### API Key Security

```php
// NEVER store API keys in plaintext
// Use sodium_crypto_secretbox with wp_salt() as key
$encrypted = wp_claw_encrypt( $api_key );
update_option( 'wp_claw_api_key', $encrypted );

// Retrieve
$api_key = wp_claw_decrypt( get_option( 'wp_claw_api_key' ) );
```

### Webhook Signature Verification

```php
// Klawty signs every webhook with HMAC-SHA256
// The shared secret is the API key
$signature = hash_hmac( 'sha256', $request_body, $api_key );
if ( ! hash_equals( $signature, $received_signature ) ) {
    return new WP_Error( 'invalid_signature', 'Webhook signature mismatch', array( 'status' => 403 ) );
}
```

### Action Allowlist

Agents can ONLY execute actions in the allowlist. The allowlist is defined per module and enforced by the REST API bridge. No arbitrary code execution. No dynamic function calls. No filesystem access outside WP APIs.

### What Agents CANNOT Do

- Execute arbitrary PHP code
- Run SQL queries directly
- Modify plugin/theme files
- Install/deactivate plugins
- Create admin users
- Access wp-config.php
- Modify .htaccess (except via Security module's predefined rules)
- Send emails without proposal approval
- Delete content without proposal approval

---

## Module System

Each module is a self-contained class that:
1. Registers its WP hooks (actions/filters)
2. Defines its Klawty agent mapping (which agent handles this module)
3. Exposes its REST API actions (allowlisted)
4. Provides its admin view
5. Can be enabled/disabled independently via settings

### Module to Agent Mapping

| Module | Agent | Trigger Events |
|--------|-------|----------------|
| SEO | Scribe | `save_post`, `publish_post`, daily cron |
| Security | Sentinel | `wp_login_failed`, `wp_login`, hourly cron |
| Content | Scribe | Manual trigger, scheduled cron |
| CRM | Commerce | Form submission hooks, contact form hooks |
| Commerce | Commerce | `woocommerce_order_status_changed`, `woocommerce_low_stock`, daily cron |
| Performance | Analyst | Weekly cron, manual trigger |
| Forms | Architect | Manual trigger (admin builds form) |
| Analytics | Analyst | `wp_footer` (pixel), weekly cron (report) |
| Backup | Sentinel | Daily cron, manual trigger |
| Social | Scribe | `publish_post` (auto-share), manual trigger |
| Chat | Concierge | Real-time via REST (visitor sends message, agent responds) |

See `class-cron.php` for full WP-Cron event schedule. See `class-activator.php` for database table schemas.

---

## i18n / l10n

- All user-facing strings wrapped in `__()` or `esc_html__()`
- Text domain: `wp-claw`
- POT file generated with `wp i18n make-pot . languages/wp-claw.pot`
- Priority languages: English (default), French, German (EU market)

---

## What NOT to Do

- Do NOT use superglobals directly — always sanitize
- Do NOT output HTML without escaping — every echo must use esc_*
- Do NOT run SQL without $wpdb->prepare() — SQL injection is fatal
- Do NOT store API keys in plaintext — use sodium encryption
- Do NOT load admin assets on non-WP-Claw pages — check page hook
- Do NOT use jQuery — vanilla JS or React via wp-scripts
- Do NOT call external APIs without user consent — GDPR requirement
- Do NOT create admin notices that nag — one-time, dismissible only
- Do NOT modify core WP tables — use custom tables with prefix
- Do NOT use file_get_contents() or curl — use wp_remote_*()
- Do NOT skip nonce verification — ever, for any form or AJAX call
- Do NOT skip capability checks — ever, for any admin action
- Do NOT bundle unnecessary dependencies — keep the plugin lightweight
- Do NOT access the filesystem directly — use WP_Filesystem
- Do NOT assume WooCommerce is installed — check class_exists('WooCommerce')
- Do NOT execute arbitrary code strings — use predefined action handlers only

## What to ALWAYS Do

- **Prefix everything**: `wp_claw_` for functions, options, hooks, cron, transients, REST routes
- **Escape output**: Every echo, every attribute, every URL
- **Sanitize input**: Every superglobal, form field, REST parameter
- **Verify nonces**: Every form submission, every AJAX call, every REST request
- **Check capabilities**: Every admin action, every settings change
- **Prepare queries**: Every $wpdb query
- **Use WP APIs**: wp_remote_get() for HTTP, WP_Filesystem for files
- **Enqueue properly**: wp_enqueue_script() with dependencies and version
- **Document everything**: PHPDoc on every class, method, property, function
- **Test everything**: Unit tests for core logic, integration tests for WP hooks
- **Support i18n**: Wrap ALL strings in __() or esc_html__()
- **Respect user roles**: Admin-only actions behind manage_options, editor actions behind edit_posts
- **Clean uninstall**: uninstall.php removes tables, options, cron events, transients
