# WP-Claw — The AI Operating Layer for WordPress

> **MANDATORY — Read before ANY work:**
> 1. `../docs/ECOSYSTEM.md` — Master ecosystem document. Read BEFORE any implementation, architecture decision, or cross-product change. This is the single source of truth for how all 9 products connect.
> 2. `../docs/KLAWTY-ECOSYSTEM-STRATEGY.md` — Business strategy and pricing.
> 3. `wp-claw/docs/ARCHITECTURE.md` — End-to-end architecture for WP-Claw's 3-system design.
>
> **MANDATORY — Update after ANY work:**
> After every session that produces code changes, bug fixes, upgrades, or architectural decisions, you MUST update ALL of the following:
> 1. `../docs/ECOSYSTEM.md` — Update version numbers, status, feature lists, any changed architecture
> 2. `CHANGELOG.md` — Add entry with version, date, and categorized changes
> 3. `README.md` — Update if architecture, modules, agents, commands, or file structure changed
> 4. Memory files — Update `project_wp_claw_status.md` with new state
>
> **NO EXCEPTIONS.** Failure to update these documents creates drift between reality and documentation, which causes wrong decisions in future sessions. If you are unsure whether a change warrants an update, UPDATE ANYWAY.

WP-Claw connects WordPress sites to managed Klawty instances — in the Teeme future, WP-Claw customers could connect to a Teeme-managed engine instead of self-hosting.

WP-Claw is a WordPress plugin that replaces 10-15 individual plugins with a single AI-powered operating layer. It connects any WordPress site to a managed Klawty AI agent instance via REST/HTTP. The agents handle SEO, security, content, e-commerce, analytics, CRM, forms, backups, performance, and custom development — autonomously.

**This is NOT an AI chatbot plugin. This is an operating system layer.**

### Klawty-Side Plugin (Agent Intelligence)

The Klawty OS plugin that powers the 6 agents lives at `../tools/marketplace-plugins/wp-claw-agents/`. It registers 134 tools (domain tools + coordination + godmode + shared intelligence), 37 skills (domain expertise from vision.md), and 8 HTTP routes (events, chat, health, agents, reports, activity, profile, tasks).

- **Spec:** `../docs/superpowers/specs/2026-03-31-wp-claw-klawty-plugin-design.md`
- **Plan:** `../docs/superpowers/plans/2026-03-31-wp-claw-klawty-plugin.md`
- **Process:** `../tools/marketplace-plugins/wp-claw-agents/docs/CREATION-PROCESS.md`
- **Connection:** Direct HMAC-SHA256 signed HTTP (no proxy after setup). This PHP plugin is the security boundary.

---

## Identity

- **Product**: Claw Agent (marketing name: WP-Claw) — AI operating layer for WordPress
- **WordPress.org slug**: `claw-agent` (wp prefix restricted by wordpress.org)
- **Domain**: wp-claw.ai
- **PHP namespace**: `WPClaw`
- **Text domain**: `claw-agent` (i18n — must match wordpress.org slug)
- **Minimum PHP**: 7.4 (WordPress minimum as of 6.8)
- **Minimum WP**: 6.4
- **Tested up to**: 6.9
- **License**: GPL-2.0-or-later (required by wordpress.org)
- **Plugin version**: 1.3.2 (2026-04-07: Data layer sees 200 WooCommerce products, task lifecycle with dedup + status bars, analytics pixel + chat widget .min fix, 10 empty states, full honest audit. Audit spec: `../docs/superpowers/specs/2026-04-04-wp-claw-honest-audit-spec.md`.)
- **App version**: 2.3.0 (deployed to VPS 1, port 3300)
- **Owner**: dcode technologies S.a r.l., Luxembourg
- **Parent product**: Klawty OS (klawty.ai) — open-source agent operating system
- **Commercial backend**: ai-agent-builder.ai — managed Klawty instances

See `../docs/KLAWTY-ECOSYSTEM-STRATEGY.md` for full ecosystem context, pricing, verticals, and revenue architecture.

## Instance Provisioning

The plugin connects to a **managed Klawty instance** provisioned by ai-agent-builder.ai:

```
Customer subscribes at wp-claw.ai
  → Stripe webhook → POST ai-agent-builder.ai/api/provision
  → Creates workspace, configures 5 agents, starts PM2
  → Returns instance URL (wp-{id}.ai-agent-builder.ai)
  → wp-claw.ai saves URL → plugin reads it from wp_claw_instance_url option
```

The plugin does NOT provision instances. It only CONNECTS to them. The `wp_claw_instance_url` option is set during the connection verification flow (`POST /api/connect/verify` returns the instance URL).

See `../app/CLAUDE.md` for the provisioning API spec.

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
| **Managed** | `https://kl-{id}.ai-agent-builder.ai` | Bearer token + HMAC (stored encrypted in wp_options) | 90% of customers — any hosting |
| **Self-hosted** | `http://localhost:2508` | Local token | VPS power users running Klawty natively |

### Authentication (Dual — CRITICAL)

Every request from the WP plugin to the Klawty gateway carries **two** auth mechanisms:
1. **`Authorization: Bearer <api_key>`** — Required by the Klawty gateway for access. Without this, gateway returns 401.
2. **`X-WPClaw-Signature` + `X-WPClaw-Timestamp`** — HMAC-SHA256 signature (`timestamp.body` signed with api_key) for request integrity. Will be validated by the wp-claw-agents plugin in a future release.

Both are sent on every request. See `class-api-client.php` lines 466-471.

### Network Path (2-hop proxy — verified 2026-04-01)

```
WordPress (any host)
  → https://kl-{id}.ai-agent-builder.ai
  → Cloudflare (terminates SSL, wildcard cert for *.ai-agent-builder.ai)
  → VPS 1 Nginx :80 (forwards all kl-* subdomains to VPS 2:80)
  → VPS 2 Nginx :80 (server_name match → localhost:instance_port)
  → Klawty gateway (Bearer token auth)
```

Hetzner blocks non-standard ports between VPSes — that's why the 2-hop architecture exists. VPS 1 doesn't know individual ports; VPS 2 Nginx handles subdomain→port routing.

### Communication Protocol

```
WP-Claw to Klawty (all requests carry Bearer + HMAC headers):
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

### Module to Agent Mapping (v1.1.0 — 12 modules, 6 agents)

| Module | Agent (Vision Name) | Key Capabilities |
|--------|-------------------|-----------------|
| **Audit** | Thomas (Architect) | Site audit, plugin versions, SSL check, disk/DB stats, weekly report |
| **SEO** | Lina (Scribe) | Meta tags, schema, A/B testing, cannibalization, broken links, stale content |
| **Security** | Bastien (Sentinel) | File integrity, malware scanning, IP blocking, brute force, SSL monitoring, header deployment |
| **Content** | Lina (Scribe) | Drafts, translations, freshness scanning, stale date fixing, thin content expansion |
| **CRM** | Hugo (Commerce) | Lead capture, scoring, pipeline, email draft approval, follow-up drafting |
| **Commerce** | Hugo (Commerce) | WooCommerce: abandoned carts, fraud detection, customer segments, stock thresholds |
| **Performance** | Selma (Analyst) | CWV monitoring, DB cleanup, table optimization, autoload analysis, PageSpeed |
| **Forms** | Thomas (Architect) | Form creation, submission tracking |
| **Analytics** | Selma (Analyst) | Privacy-first tracking, anomaly detection, funnel analysis, content trends |
| **Backup** | Bastien (Sentinel) | DB backup, file backup, targeted snapshots (72h), rollback |
| **Social** | Lina (Scribe) | Platform-specific formatting, post scheduling, posting history |
| **Chat** | Marc (Concierge) | GDPR consent, product catalog, FAQ learning, escalation SLA, lead capture |

### WordPress Agent Skills Mapping (wordpress/agent-skills)

Skills from https://github.com/WordPress/agent-skills injected into each agent's context.

**Shared (all 6 agents):**

| Skill | Purpose |
|-------|---------|
| `wordpress-router` | Classify WordPress repos and projects |
| `wp-project-triage` | Detect project type, tooling, and versions |
| `wp-plugin-development` | Plugin architecture, hooks, and security |

**Per-agent:**

| Agent | Role | Skills |
|-------|------|--------|
| **Karim** | Architect | `wp-rest-api`, `wp-wpcli-and-ops`, `wp-abilities-api`, `wp-phpstan` |
| **Lina** | Scribe | `wp-block-development`, `wp-block-themes`, `wp-interactivity-api`, `wpds` |
| **Bastien** | Sentinel | `wp-performance`, `wp-wpcli-and-ops` |
| **Hugo** | Commerce | `wp-rest-api` |
| **Selma** | Analyst | `wp-performance`, `wp-phpstan` |
| **Marc** | Concierge | `wp-interactivity-api`, `wp-block-development` |

**Not assigned** (dev/testing tools, not relevant to production agents): `wp-playground`, `blueprint`

### Database Tables (10)
`wp_claw_tasks`, `wp_claw_proposals`, `wp_claw_analytics`, `wp_claw_command_log`, `wp_claw_file_hashes`, `wp_claw_ab_tests`, `wp_claw_abandoned_carts`, `wp_claw_email_drafts`, `wp_claw_cwv_history`, `wp_claw_snapshots`

### Email Notifications (v1.3.1)
- **Class:** `includes/class-notifications.php` — all email logic (alerts, digest, weekly report, HTML/text builders)
- **Settings:** `wp_claw_notification_settings` option — enabled, email, realtime_alerts, daily_digest, digest_hour, digest_format, weekly_report, weekly_day, weekly_hour, muted_agents
- **Real-time alerts:** Malware detected, SSL expiring (<14d), backup failed, agent stuck
- **Daily digest:** Agent task stats, pending ideas, failed tasks, security score, KPI snapshot
- **Weekly report:** 7-day trends with ↑↓→, agent performance, cost breakdown, recommendations

### Interactive Admin (v1.3.1)
- **REST endpoints:** `POST /inline-edit` (8 types: meta_title, meta_desc, schema, product_price, product_stock, block_ip, unblock_ip, toggle_module), `POST /agent-action` (creates agent tasks)
- **JS handlers:** `wpClawInlineEdit()`, `doInlineEdit()`, `wpClawAgentAction()` with `data-inline-edit` / `data-agent-action` attribute delegation
- **Interactive pages:** SEO (clickable meta cells + auto-fix), Commerce (clickable price/stock), Security (direct block/unblock IP), Dashboard (KPI links, module toggles, timeline expansion)

### Agent Ideas (v1.3.1)
- **Orchestrator:** 6 daily "Generate daily idea" schedules (one per agent) in `cold-start.ts`
- **Proposals page:** "Ideas" tab filters `action='idea'` proposals
- **Dashboard:** Yellow gradient "Agent Ideas" card with pending count
- **Digest:** Ideas section in daily digest email

### WP-Cron Events (18)
`health_check` (hourly), `sync_state` (hourly), `file_integrity` (hourly), `abandoned_cart` (hourly), `update_check` (twicedaily), `security_scan` (twicedaily), `backup` (daily), `seo_audit` (daily), `malware_scan` (daily), `ssl_check` (daily), `ab_test_eval` (daily), `daily_digest` (daily), `analytics_report` (weekly), `performance_check` (weekly), `analytics_cleanup` (weekly), `cwv_cleanup` (weekly), `segmentation` (weekly), `weekly_report` (weekly)

### Constitutional Constraints
- T3 daily counter: max 5 structural changes per 24h (HTTP 429 if exceeded)
- Health-fail halt: 2 consecutive health check failures halt all T2/T3 operations (HTTP 503)
- Operations resume: manual via admin settings or option deletion

### Admin Panel (v1.2.0 upgrade planned)
Design spec: `docs/superpowers/specs/2026-03-31-admin-panel-upgrade-design.md`
Analysis: `docs/ADMIN-PANEL-ANALYSIS.md`
Current state: 6 pages, 0% visibility of v1.1.0 capabilities
Planned: 8 pages with 3 new domain dashboards (Security, Commerce, SEO/Content)

See `class-cron.php` for cron handlers. See `class-activator.php` for database table schemas.

---

## i18n / l10n

- All user-facing strings wrapped in `__()` or `esc_html__()`
- Text domain: `claw-agent` (changed from `wp-claw` in v1.0.4 — wordpress.org restricts "wp" prefix)
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
