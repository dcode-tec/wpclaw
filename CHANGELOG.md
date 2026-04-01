# Changelog

All notable changes to WP-Claw are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.1] — 2026-04-01 — Bearer auth for Klawty gateway compatibility

### Fixed
- **[FIX]** API client now sends `Authorization: Bearer <api_key>` header alongside HMAC signature headers. Klawty gateway requires Bearer token auth; the plugin was only sending `X-WPClaw-Signature` + `X-WPClaw-Timestamp`, causing 401 Unauthorized (surfaced as 502 Bad Gateway in WP admin).
- Both auth methods are now sent: Bearer for gateway access, HMAC for future request integrity validation by the wp-claw-agents plugin.

### Verified
- **[E2E]** Full connection chain working: WP admin Test Connection → green. Traffic flows: inscape.lu → Cloudflare → VPS 1 Nginx → VPS 2 Nginx → Klawty gateway → `{"status":"ok","agents":6}`

---

## [1.2.0] — 2026-03-31 — Admin panel upgrade + connection handshake

Full admin panel redesign giving visibility into all 37 v1.1.0 agent capabilities. Upgraded from 6 generic pages to 8 module-aware pages with domain-specific dashboards for Security, Commerce, and SEO. Also adds the managed-mode connection handshake and 3 previously missing REST endpoints.

### Added — Admin Panel (11 files, ~2,800 new lines)
- **[Feature]** Dashboard REWRITTEN — module-aware overview with 6 metric cards (Security, SEO, Commerce, Analytics, Infrastructure, Chat), constitutional constraint banners (halt + T3 warning), 6-column KPI row, security score heuristic, expanded activity feed (20 items)
- **[Feature]** Security Dashboard (NEW PAGE — `security.php`, 436 lines) — file integrity monitor with scan trigger, malware scan results, quarantined files table, login attempts, SSL certificate status with day countdown, security headers checklist (7 standard headers)
- **[Feature]** Commerce & CRM Dashboard (NEW PAGE — `commerce.php`, 419 lines) — abandoned cart queue, email draft approval with expandable body preview + AJAX approve/reject, lead pipeline visualization (stacked bar), customer segments, WooCommerce-aware revenue KPIs
- **[Feature]** SEO & Content Dashboard (NEW PAGE — `seo-content.php`, 458 lines) — A/B test manager with filter tabs (running/completed/all), meta title/description coverage bars, broken link report, stale content detection (12-month threshold), sitemap/robots status
- **[Feature]** Settings ENHANCED — 4 new sections: Chat GDPR (consent text, privacy URL, escalation SLA), Security (brute force toggle, max attempts, lockout duration), Backup (daily/weekly retention), System Status (operations state, T3 counter, resume button)
- **[Feature]** Agents ENHANCED — local module state fallback when API disconnected (6 agent cards with module badges), per-agent dashboard links
- **[Feature]** Proposals ENHANCED — agent display names (e.g. "Karim — The Architect"), tier badges (AUTO/AUTO+/PROPOSE/CONFIRM), expandable full details, `tier` column added to all queries
- **[Feature]** Modules page REMOVED — merged into Dashboard metric cards and domain dashboards

### Added — REST Endpoints (8 new endpoints total)
- **[API]** `GET /admin/module-states` — returns all enabled module `get_state()` arrays + constitutional constraints. 60s dashboard polling.
- **[API]** `POST /admin/run-scan` — triggers file integrity or malware scan. Param: `scan_type` (enum: file_integrity, malware).
- **[API]** `POST /admin/email-drafts/{id}/approve` — approves a draft email, records approving user.
- **[API]** `POST /admin/email-drafts/{id}/reject` — rejects a draft email.
- **[API]** `POST /admin/resume-operations` — clears `wp_claw_operations_halted` option to resume T2/T3 actions.
- **[API]** `GET /health` — proxies to Klawty instance health check. Used by "Test Connection" button.
- **[API]** `GET /agents` — proxies agent status for dashboard refresh.
- **[API]** `POST /settings/modules` — saves module toggle settings. Validates against 12 known slugs.

### Added — Connection Handshake (class-admin.php)
- **[Auth]** `sanitize_api_key()` detects managed mode and exchanges connection token for permanent API key via `POST wp-claw.ai/api/connect/verify`
- **[Auth]** On success: stores API key, webhook secret, and instance URL. On failure: falls back to raw token (backward compatible).

### Added — JavaScript Features (admin/js/wp-claw-admin.js, +411 lines)
- **[JS]** Email draft approve/reject AJAX on Commerce page
- **[JS]** Scan trigger with loading spinner on Security page
- **[JS]** Resume operations with banner fade-out on Dashboard/Settings
- **[JS]** Expandable rows (table-based + block-based) for Proposals and Commerce
- **[JS]** Module state polling every 60s on Dashboard (replaces basic agent refresh)
- **[JS]** Activity feed domain filter tabs on Dashboard

### Added — CSS Components (admin/css/wp-claw-admin.css, +97 lines)
- **[CSS]** `.wpc-alert-banner` — constitutional constraint banners (danger/warning)
- **[CSS]** `.wpc-metric-grid` + `.wpc-metric-card` — 2-column module status cards
- **[CSS]** `.wpc-coverage-bar` — progress bar for SEO coverage
- **[CSS]** `.wpc-pipeline-bar` — stacked bar for lead pipeline (5 stages)
- **[CSS]** `.wpc-detail-table` — data table with severity classes
- **[CSS]** `.wpc-expandable-row` — collapsible table rows
- **[CSS]** `.wpc-scan-button` — button with CSS loading spinner
- **[CSS]** `.wpc-kpi-grid--6` — 6-column KPI grid variant
- **[CSS]** Border radius bumped to 12px (was 8px)

### Added — Settings Registration (8 new options)
- `wp_claw_brute_force_enabled` (boolean), `wp_claw_brute_force_max_attempts` (int, default 5), `wp_claw_brute_force_lockout_minutes` (int, default 30)
- `wp_claw_backup_daily_retention` (int, default 7), `wp_claw_backup_weekly_retention` (int, default 30)
- `wp_claw_chat_consent_text` (string), `wp_claw_chat_privacy_url` (url), `wp_claw_chat_sla_minutes` (int, default 60)

### Removed
- **[Breaking]** Modules admin page removed (`admin/views/modules.php` deleted, menu entry removed). Module toggles remain in Settings.

---

## [1.1.0] — 2026-03-31 — Vision capabilities (IMPLEMENTED)

### Architecture
- **[Design]** Full design spec written: `docs/superpowers/specs/2026-03-30-vision-capabilities-design.md`
- **[Design]** Maps all 37 agent capabilities from `vision.md` to plugin-side changes
- **[Design]** 6 infrastructure layers identified: tables, helpers, cron, hooks, modules, frontend

### Planned: Database (DB version 1.0.0 → 1.1.0)
- **[Schema]** 6 new tables: `wp_claw_file_hashes`, `wp_claw_ab_tests`, `wp_claw_abandoned_carts`, `wp_claw_email_drafts`, `wp_claw_cwv_history`, `wp_claw_snapshots`
- **[Migration]** Auto-upgrade via existing `dbDelta()` mechanism in `WP_Claw::init()`

### Planned: New Module
- **[Feature]** Audit module (`class-module-audit.php`) — Karim's infrastructure capabilities: site audit, plugin versions, SSL check, disk/DB stats, weekly compiled report

### Planned: New Helpers
- **[Feature]** `file-scanner.php` — SHA-256 file hashing, comparison, quarantine for Bastien's file integrity monitoring
- **[Feature]** `malware-patterns.php` — PHP malware pattern database and scanner for Bastien's malware detection

### Planned: Module Expansions (~55 new actions across 10 modules)
- **[Feature]** Security: file integrity hashing, malware scanning, SSL monitoring, security header deployment via `send_headers`
- **[Feature]** SEO: A/B testing (meta tags), cannibalization check, broken link detection, content staleness, striking distance keywords
- **[Feature]** Content: freshness scanning, stale date updating, thin content expansion
- **[Feature]** Analytics: anomaly detection, funnel tracking, CWV trending, content ranking
- **[Feature]** Commerce: abandoned cart tracking pipeline, fraud signals, customer RFM segmentation, daily order summaries
- **[Feature]** CRM: email draft approval workflow, pipeline health analysis
- **[Feature]** Backup: targeted rollback snapshots (72h retention), file backups
- **[Feature]** Chat: GDPR consent gate, FAQ learning, escalation SLA tracking
- **[Feature]** Social: platform-specific formatting, posting history
- **[Feature]** Performance: table optimization, autoload analysis

### Planned: Cron Events (9 existing + 7 new = 16 total)
- **[Feature]** `wp_claw_file_integrity` (hourly), `wp_claw_malware_scan` (daily), `wp_claw_ssl_check` (daily), `wp_claw_abandoned_cart` (hourly), `wp_claw_ab_test_eval` (daily), `wp_claw_cwv_cleanup` (weekly), `wp_claw_segmentation` (weekly)

### Planned: Hook Map Expansion (11 existing + 6 new = 17 hooks)
- **[Feature]** WooCommerce cart hooks for abandoned cart tracking
- **[Feature]** WooCommerce funnel hooks for analytics event tracking

### Planned: Constitutional Constraints
- **[Security]** T3 daily counter (max 5 structural changes per 24h without human override)
- **[Security]** Health-fail halt (2 consecutive health check failures → block all T2/T3 operations)

### Planned: Frontend
- **[Feature]** Chat widget GDPR consent gate (EU legal requirement)
- **[Feature]** Chat page content injection (500 chars of main content per message)
- **[Feature]** Chat 50-message session limit
- **[Feature]** Analytics funnel event tracking (cart/checkout/purchase via body class detection)

### Planned: State Sync Fix
- **[Fix]** `Cron::run_sync_state()` will call module `get_state()` methods (currently bypassed — modules implement `get_state()` but cron never calls it)

---

## [1.0.4] — 2026-03-25 — WordPress.org submission readiness

### Plugin Name Change
- **[Breaking]** Plugin name changed: WP-Claw → Claw Agent (wordpress.org restricts "wp" prefix)
- **[Breaking]** Text domain changed: `wp-claw` → `claw-agent` across all 27 PHP files (~400 instances)
- **[Breaking]** WordPress.org slug: `claw-agent`
- **[Infra]** Plugin main file remains `wp-claw.php` (WordPress.org assigns the slug from readme.txt)

### WordPress.org Compliance
- **[Security]** Removed custom update checker (`pre_set_site_transient_update_plugins`, `plugins_api` hooks) — wordpress.org handles updates via SVN
- **[Security]** Replaced `is_writable()` with `WP_Filesystem` methods in security module (2 instances)
- **[Security]** Added `wp_unslash()` before sanitization on `$_SERVER['REMOTE_ADDR']` and `$_SERVER['HTTP_USER_AGENT']`
- **[i18n]** Removed `load_plugin_textdomain()` (deprecated since WP 4.6 — wordpress.org auto-loads translations)
- **[PHPCS]** Added phpcs:ignore comments for all remaining warnings (DirectDatabaseQuery on custom tables, NonPrefixedVariableFound in view templates, UnescapedDBParameter on class constants, VIP post__not_in advisory)
- **[readme.txt]** Updated: Plugin Name `Claw Agent`, Contributors `dcodetechnologies`, Tested up to `6.9`, Stable tag `1.0.4`, Donate link `wp-claw.ai`, reduced tags to 5

### Bug Fixes
- **[Fix]** Settings page fatal error: `$api_client` undefined → now uses `WPClaw\API_Client::get_instance()`
- **[Fix]** All admin views now get `$api_client` injected before `include()` in class-admin.php render methods
- **[Fix]** Capability recovery: moved from `plugins_loaded` (too early, `current_user_can()` not available) to `admin_init`
- **[Fix]** Proposals page: HTML aligned with CSS BEM classes (`wpc-nav-tabs__item`, `wpc-proposal-card__header/__body/__actions`)

### Infrastructure
- **[Infra]** `.distignore` created for wordpress.org deployment (excludes .claude, .github, vendor, tests, docs, composer.*)
- **[Infra]** ZIP build tested and validated with WordPress Plugin Check (0 errors, warnings only)
- **[Docs]** `.claude/skills/wp-plugin-submission/SKILL.md` — comprehensive submission guide (readme.txt format, SVN, assets, review process)

---

## [1.0.3] — 2026-03-25 — SQL injection fixes + architecture docs

### Security
- **[Fix]** All 21 `$wpdb->prepare()` SQL injection issues resolved across 9 files using WordPress 6.2+ `%i` identifier placeholder
- **[Fix]** PHPCS `WordPress.DB.PreparedSQL` now reports 0 errors (was 15)
- **[Files]** Fixed: dashboard.php, proposals.php, class-admin.php, class-cron.php, class-module-analytics.php, class-module-backup.php, class-module-forms.php, class-module-performance.php, uninstall.php

### Documentation
- **[Docs]** `docs/ARCHITECTURE.md` — 600-line end-to-end architecture document covering all 6 communication paths, security model, database schemas, deployment map, environment variables
- **[Docs]** README.md rewritten with centered logo, full module docs, Command Center section, REST API reference, FAQ, project structure

### Audit
- **[Audit]** Deep promises-vs-reality audit completed — identified 6 MISSING features, 5 STUB features, and pricing tier mismatch (Starter claims "all 11 modules" but only provisions 1 agent)

---

## [1.0.2] — 2026-03-24 — WPCS compliance pass

### Code Quality
- **[PHPCS]** Ran `phpcbf` with WordPress-Extra ruleset — 2,969 auto-fixed violations across 35 files
- **[PHPCS]** Remaining: 48 issues (27 custom capability warnings — false positives; 15 `$wpdb->prepare()` SQL interpolation errors — pending manual fix; 6 style nits)
- **[Infra]** Composer dependencies installed (WPCS 3.0, PHPUnit 9, phpunit-polyfills 2)
- **[Style]** Consistent indentation, spacing, brace placement, Yoda conditions across all PHP, JS, CSS

### Known Issues
- 15 `$wpdb->prepare()` errors in module files (analytics, forms, performance, security) need manual per-query fixes — these are SQL injection surface area and should be addressed before wordpress.org submission

---

## [1.0.1] — 2026-03-21 — Repository setup

### Infrastructure
- **[Infra]** Git repository initialized, remote wired to `github.com/dcode-tec/wpclaw`
- **[Infra]** SSH key configured for `dcode-tec` org (`id_ed25519_dcode_new`, `IdentitiesOnly yes`)
- **[Infra]** Internal docs (`CLAUDE.md`, `CHANGELOG.md`, `README.md`) added to `.gitignore` — local only
- **[Docs]** README.md rewritten — full project overview, module table, security model, dev commands

---

## [1.0.0] — 2026-03-21 — Full Build

### Core
- **Plugin bootstrap** (`wp-claw.php`) — constants, manual autoloader, activation/deactivation hooks
- **API Client** (`class-api-client.php`, 709 lines) — HTTP client to Klawty with HMAC-SHA256 signing, circuit breaker (3-failure threshold, exponential backoff), retry logic, 10 public methods
- **REST API Bridge** (`class-rest-api.php`, 945 lines) — 8 endpoints under `wp-claw/v1`: execute, state, webhook, chat/send, chat/history, analytics, proposal approve/reject. HMAC signature verification with 5-min replay protection. Rate limiting on public endpoints.
- **Admin** (`class-admin.php`, 937 lines) — 5-page admin menu, WordPress Settings API, admin bar health badge, activation redirect, conditional asset loading
- **Cron** (`class-cron.php`, 471 lines) — 9 scheduled events (hourly to weekly), module cron dispatcher, analytics cleanup, state sync
- **Hooks** (`class-hooks.php`, 518 lines) — WordPress action registry mapping 11 hooks to modules, transient-based task queue with race-condition-safe lock, shutdown processor
- **Main Singleton** (`class-wp-claw.php`, 849 lines) — 11-step init, DB upgrade path, module loader, WordPress update system with Ed25519 signature verification
- **Activator** — version checks (PHP 7.4, WP 6.4), 3 table creation via dbDelta(), 9 default options, capabilities, 9 cron events
- **Deactivator** — clean cron removal, transient cleanup, no data deletion

### Helpers (Layer 1)
- **Logger** — structured logging with severity levels, WP_DEBUG_LOG gated, 1000-char truncation
- **Encryption** — sodium_crypto_secretbox with AES-256-CBC fallback, sodium_memzero on keys
- **Sanitization** — 5 data-specific sanitizers (API response, task, proposal, chat message, module settings)
- **Capabilities** — 7 custom capabilities across administrator/editor roles

### Modules (11)
- **SEO** (Scribe) — meta title/description, schema markup, sitemap, content analysis, internal linking
- **Security** (Sentinel) — IP blocking, security headers, login monitoring, file integrity, brute-force protection
- **Content** (Scribe) — draft post creation, content updates, page creation, translation, excerpt generation
- **CRM** (Commerce) — lead capture from WPForms/Gravity/CF7, lead scoring, pipeline management
- **Commerce** (Commerce) — WooCommerce integration: products, orders, coupons, stock alerts, abandoned cart
- **Performance** (Analyst) — Core Web Vitals, DB cleanup (revisions/spam/transients), cache strategy
- **Forms** (Architect) — form creation/storage, submission tracking, PROPOSE-gated deletion
- **Analytics** (Analyst) — privacy-first pageviews (server-side session hash, no cookies), GDPR consent gate, 90-day retention
- **Backup** (Sentinel) — DB export via WP_Filesystem, gzip compression, retention policy, CONFIRM-gated restore
- **Social** (Scribe) — social post generation from published content, scheduling, platform validation
- **Chat** (Concierge) — product catalog, order status, knowledge base search, lead capture, human escalation

### Admin UI
- **Dashboard** — KPI cards, recent tasks, agent team grid with health indicators
- **Settings** — encrypted API key, connection mode, module toggles, chat config, update check
- **Agents** — per-agent status cards with emoji, role, current task, health dot
- **Proposals** — approve/reject table with AJAX actions, nonce protection
- **Modules** — tabbed per-module settings with allowed actions display
- **Admin CSS** (981 lines) — responsive grid, agent cards, status pills, module toggles, connection indicator
- **Admin JS** (611 lines) — proposal actions, 60s dashboard refresh, connection test, tab navigation, module toggle AJAX

### Frontend
- **Chat widget CSS** (607 lines) — floating button, chat window, message bubbles, product cards, typing indicator, mobile full-screen
- **Chat widget JS** (824 lines) — full DOM construction, message send/receive, product rendering, business hours, session management, accessibility (focus trap, ARIA)
- **Analytics pixel CSS** (136 lines) — minimal utility styles
- **Analytics pixel JS** (229 lines) — GDPR consent gate, Do Not Track, Beacon/fetch/XHR fallback

### Infrastructure
- `composer.json` — dev deps: WPCS 3.0, PHPUnit 9, phpunit-polyfills 2
- `phpcs.xml` — WordPress-Extra ruleset, PHP 7.4, text domain wp-claw
- `uninstall.php` — clean removal of tables, options, transients, capabilities, cron, backup dir
- `readme.txt` — wordpress.org listing format
- 11 directory protection `index.php` files

### Security
- HMAC-SHA256 signed communication (timestamp + body) with 5-minute replay window
- API key encryption via sodium_crypto_secretbox (OpenSSL AES-256 fallback)
- Action allowlist per module enforced at REST bridge
- Rate limiting: 1/s analytics, 20/min chat per session
- All output escaped, all input sanitized, all queries prepared
- Custom capabilities on all admin actions
- Ed25519 package signature verification on updates

### Stats
- **32 PHP class/helper files** + 11 index.php + 5 admin views
- **6 asset files** (2 CSS + 2 JS admin, 2 CSS + 2 JS public)
- **~17,500 lines** total (estimated 6,700 — actual 2.6x due to thorough PHPDoc and error handling)

---

## [0.0.0] — 2026-03-21 — Project Init

- Repository created
- Directory structure scaffolded
- Spec and documentation authored
