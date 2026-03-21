# Changelog

All notable changes to WP-Claw are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
