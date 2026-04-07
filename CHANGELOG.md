# Changelog

All notable changes to WP-Claw are documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.4.0] — 2026-04-07 — Agent Skills Upgrade + Governance Layer + Admin Views

### Added — Admin Views (4 new pages + 2 extensions)
- **[NEW PAGE]** `admin/views/backup.php` (~430 lines) — Bastien agent-first layout: KPI grid (last backup, count, size, retention), backup list table with restore/delete actions, snapshots table, retention policy inline-edit, JS-loaded activity timeline.
- **[NEW PAGE]** `admin/views/chat.php` (~450 lines) — Marc agent-first layout: KPI grid (sessions, FAQs, escalations, response time), escalation queue with urgency badges, conversation transcripts (`.wpc-transcript`/`.wpc-message`), FAQ management with inline-edit, widget configuration.
- **[NEW PAGE]** `admin/views/social.php` (~350 lines) — Lina agent-first layout: KPI grid (scheduled, recent, platforms), scheduled posts with platform badges (`.wpc-platform-badge`), posting history timeline, platform status cards.
- **[NEW PAGE]** `admin/views/forms.php` (~455 lines) — Thomas agent-first layout: KPI grid (forms, submissions, top form), forms list with expandable submissions, recent submissions timeline.
- **[EXTENSION]** `admin/views/analytics.php` (+241 lines) — Performance tab via `.wpc-nav-tabs`: CWV gauge cards (LCP/FID/CLS with color thresholds), PageSpeed score, DB optimization table (revisions/spam/transients with cleanup buttons), cache strategy.
- **[EXTENSION]** `admin/views/dashboard.php` (+110 lines) — Infrastructure Health audit section: WP/PHP versions, SSL countdown, disk usage bar, DB size, plugin update count with badges.
- **[MENU]** 4 new submenus registered in `class-admin.php`: Backup, Chat, Social, Forms. Total admin pages: 14 (was 10).
- **[CSS]** Premium polish: KPI card hover lift, skeleton loading shimmer, CWV gauge cards, chat transcript bubbles, platform badges (LinkedIn/X/Facebook).
- **[JS]** `wpClawShowSkeleton()` safe DOM helper for loading states.

### Added — Governance Layer (Klawty-side plugin, `tools/marketplace-plugins/wp-claw-agents/`)
- **[P0]** `src/governance/tier-registry.ts` — 134 tools mapped to 5 risk tiers: auto (64), auto+ (38), propose (27), confirm (5), block (0). Unknown tools default to `propose`.
- **[P0]** `src/governance/proposal-store.ts` — SQLite `proposals` + `governance_log` tables. Lifecycle: pending → approved/rejected/expired/rolled_back. 15-min rollback window for propose tier, explicit approval for confirm tier.
- **[P0]** `src/governance/wp-security.ts` — Input sanitization (SQL injection, XSS, path traversal), per-agent rate limiting (20 writes/hour, 5 deletes/day), constitutional enforcement (T3 daily limit, operations halt on 2 consecutive health failures).
- **[P0]** `src/governance/policy-engine.ts` — Central orchestrator: tier lookup → security validation → rate limit → proposal creation. Exposes approve/reject/listPending API.
- **[P0]** `coordinated-client.ts` — PolicyEngine wired as pre-execution check. `toolName` threaded as 5th parameter through 118 `execute()` calls across 7 tool files. `forAgent()` forwards policy engine to child instances. Propose-tier tools get 15-min rollback window after execution.
- **[P0]** `plugin.ts` — `/api/proposals` HTTP route (GET pending, POST approve/reject). Proposal expiry service (24h max, hourly check).
- **[P1]** `src/governance/budget-tracker.ts` — Per-agent daily cost caps: Thomas $3, Lina $4, Bastien $1.50, Hugo $3, Selma $2, Marc $2.50. Tracks via `agent_costs` SQLite table.
- **[P1]** `src/services/alert-service.ts` — Formatted notifications for proposals, security threats, budget warnings, stuck agents. Emits to customer's configured channel (Discord/WhatsApp/Telegram) via Klawty OS.
- **[P2]** `src/services/pattern-memory.ts` — Records tool success/failure patterns. SHA-256 input hashing, 7-day rolling window, skip-tool recommendation after 3+ consecutive failures.
- **[P2]** `src/services/skill-improvement-service.ts` — Upgraded from 54→200 lines. Uses PatternMemory for two-pass analysis: investigate (< 50% success rate) + optimize (> 90% success rate). Max 3 insights per agent per cycle.
- **[TESTS]** 9 new test files (tier-registry, proposal-store, wp-security, policy-engine, e2e-governance, budget-tracker, alert-service, pattern-memory, skill-improvement). 52+ assertions, 0 failures.
- **[BUILD]** Plugin version bumped to 1.4.0. Bundle: 213KB (dist/plugin.js). All 7 governance components verified in bundle.

### Added
- **Structured Site Triage**: `/state` sync now includes signals, tooling, health, and recommendations
- **Performance Diagnostic Pipeline**: 7 diagnostic checks with scored report and dashboard card
- **Task Chaining**: Multi-step autonomous workflows with dashboard visibility and pause/cancel
- **Module Discovery**: `GET /wp-claw/v1/abilities` endpoint for programmatic module discovery
- **Application Passwords Auth**: Self-hosted users can use WP Application Passwords (Basic Auth over HTTPS)
- **WP Playground Blueprint**: Instant demo environment with mock data

### Changed
- **REST Hardening (Slice 7)**: All routes now have explicit `args` with validate/sanitize callbacks. Routes completed: `/command/setup-pin` (pin arg, 4-8 chars), `/command/history`, `/admin/module-states`, `/admin/resume-operations`, `/admin/reset-circuit-breaker`, `/health`, `/agents` (optional agent filter), `/reports` (optional type filter), `/activity` (page, per_page, agent, id), `/create-task` (agent, title, module, params), `/profile` GET+POST.
- **Cron Batching**: File integrity and malware scans now process max 500 files per run using transient bookmarks (`wp_claw_integrity_bookmark`, `wp_claw_malware_bookmark`). Bookmark resets when all files are processed.
- **Segmentation Cron**: Added `LIMIT 1000` to WooCommerce customer query in `run_segmentation()` — prevents memory exhaustion on large stores.
- **Performance Cron**: Runs local diagnostics before dispatching to Klawty.

### Fixed
- Missing `wp_claw_daily_digest` and `wp_claw_weekly_report` cron hooks verified present in `uninstall.php` cleanup list.

---

## [1.4.0-slice5] — 2026-04-07 — Application Passwords auth + arg schemas on 6 routes

### Added — Dual Authentication (Slice 5)
- **[AUTH]** `verify_signature()` in `class-rest-api.php` refactored into a dispatcher: detects `X-WPClaw-Signature` header to route to HMAC path (managed mode) or Application Password / Basic Auth path (self-hosted mode).
- **[AUTH]** New `verify_hmac_signature()` method — carries the original HMAC validation logic. Called exclusively by the dispatcher when the signature header is present. Early empty-header guard removed (handled by dispatcher).
- **[AUTH]** Self-hosted Application Password path: enforces HTTPS via `is_ssl()`, validates `manage_options` capability on the authenticated user. Returns `WP_Error` 403 if not HTTPS, 401 if not authenticated.
- **[SETTINGS]** Application Password section added to Connection card in `admin/views/settings.php`: shown when connection mode is `self-hosted`, hidden otherwise. Contains "Generate Application Password" button and one-time result display with "copy now" warning.
- **[JS]** `initConnectionMode()` IIFE in `wp-claw-admin.js`: toggles `#wp-claw-app-password-section` on `#wp_claw_connection_mode` change. Generate button calls `wp.apiRequest()` to `POST /wp/v2/users/me/application-passwords` with name `WP-Claw Klawty Instance`, displays password in result area or error message on failure.

### Added — REST API Arg Schemas (Slice 5)
- **[API]** `/chat/send`: `session_id` (required string), `message` (required string, sanitize_textarea_field), `page_url` (optional string, esc_url_raw).
- **[API]** `/chat/history`: `session_id` (required string, sanitize_text_field).
- **[API]** `/analytics`: `event` (required string, sanitize_key), `url` (optional string, esc_url_raw).
- **[API]** `/inline-edit`: `type` (required string, sanitize_key), `id` (required integer, absint), `value` (required string, sanitize_text_field).
- **[API]** `/agent-action`: `action` (required string, sanitize_key), `agent` (required string, sanitize_key), `params` (optional object, default `[]`).
- **[API]** `/settings/modules`: `modules` (required array, items type string).

---

## [1.3.1] — 2026-04-04 — Email notifications, agent ideas, interactive admin pages

### Added — Email Notification System (Pillar 1)
- **[NEW FILE]** `includes/class-notifications.php` — 1,410 lines. 6 public + 17 private methods handling real-time alerts, daily digest, weekly report with branded HTML/text email templates.
- **[CRON]** `wp_claw_daily_digest` (daily) and `wp_claw_weekly_report` (weekly) WP-Cron events registered in activator/deactivator.
- **[SETTINGS]** Full Notifications section in Settings page: master toggle, email override, real-time alerts toggle, daily digest with hour picker + format selector, weekly report with day + hour picker, per-agent mute toggles for all 6 agents.
- **[AJAX]** "Send Test Email" button with `wp_ajax_wp_claw_send_test_email` handler, nonce + capability verified.
- **[ALERTS]** Real-time email alerts hooked into: malware scan results (security module), SSL expiry < 14 days (cron).
- **[ADMIN]** `register_setting()` + `sanitize_notification_settings()` for the `wp_claw_notification_settings` option with full validation.

### Added — Agent Idea Generation (Pillar 2)
- **[ORCHESTRATOR]** 6 daily "Generate daily idea" schedules in `cold-start.ts` DEFAULT_SCHEDULES — one per agent role (scribe, sentinel, commerce, analyst, concierge, architect).
- **[UI]** "Ideas 💡" tab on Proposals page with `action='idea'` query filter and idea-specific empty state.
- **[UI]** Yellow gradient "Agent Ideas" card on Dashboard showing pending idea count with "Review Ideas" button.
- **[EMAIL]** "Ideas from your team" section added to daily digest (HTML yellow cards + plain text).

### Added — Interactive Admin Pages (Pillar 3)
- **[API]** `POST /wp-claw/v1/inline-edit` — handles 8 edit types: meta_title, meta_desc, schema, product_price, product_stock, block_ip, unblock_ip, toggle_module.
- **[API]** `POST /wp-claw/v1/agent-action` — creates agent tasks for complex actions (same pattern as Command Center).
- **[JS]** `wpClawInlineEdit()`, `doInlineEdit()`, `wpClawAgentAction()` + event delegation on `[data-inline-edit]` and `[data-agent-action]` attributes (+277 lines).
- **[SEO]** Clickable meta title/desc cells with inline edit + "Auto-fix All Missing Meta" agent button.
- **[COMMERCE]** Clickable price/stock cells with inline edit.
- **[SECURITY]** Direct "Block IP" on login attempts, direct "Unblock" on blocked IPs (replaces agent-assisted flow).
- **[DASHBOARD]** KPI cards → navigation links, module health toggles, activity timeline expansion.

### Fixed — Bugs Found in Testing
- **[JS]** Inline edit + agent action REST URLs doubled namespace (`wp-claw/v1/wp-claw/v1/...` → 404). Fixed to use relative path from `wpClaw.restUrl`.
- **[JS]** Deprecated `event` global replaced with `triggerEl` parameter in `wpClawAgentAction()`.
- **[JS]** `doInlineEdit()` now sends `module` field for `toggle_module` type.
- **[PHP]** Notification toggles (enabled, realtime, digest, weekly, per-agent mute) not clickable — `wpc-toggle-switch__slider` overlay blocked checkbox. Wrapped all 11 toggles in `<label for="...">` elements.
- **[PHP]** Email header removed `⚡` thunder icon. Agent avatars now use profile images (`Karim.png`, etc.) instead of letter circles.

### Fixed — Klawty Instance (wp-claw-agents plugin)
- **[TIMEOUT]** Security tools (`file_integrity`, `compare_hashes`, `malware_scan`, `compute_hashes`) increased from 30s → 90s. `getState` increased 30s → 60s. Eliminated timeout failures on heavy filesystem scans.
- **[CRASH]** `emit_event` tool crashed with `NOT NULL constraint failed: agent_events.type` when LLM omitted `event_type` param (20 failures). Added null guard with error return.
- **[PATH]** Doubled workspace path (`workspace/workspace/skills/`) caused ENOENT. Created symlink on instance.
- **[CONFIG]** `"missing site_url or api_key"` warning on every restart — `api.pluginConfig` empty on early `register()` calls. Added fallback: reads `klawty.json` directly via `KLAWTY_STATE_DIR` env var. Zero warnings after fix.

### Added — Data Layer Fixes (Session 2, 2026-04-05)
- **[SEO]** Added `post_type='product'` to ALL 10 SEO module queries — agents + admin now see 200+ WooCommerce products. Meta coverage KPIs show real percentages.
- **[CONTENT]** Added `post_type='product'` to 2 content freshness queries.
- **[SQL]** Fixed unprepared SQL in `get_state()` stale content count — wrapped in `$wpdb->prepare()`.

### Added — Task Lifecycle System (Session 2, 2026-04-05)
- **[BACKEND]** Task dedup in `task-creator-handler.ts` — checks for existing pending task with same agent+title before INSERT. Returns existing task_id with `{ existing: true }`.
- **[BACKEND]** Single-task query in `activity-handler.ts` — `GET /api/activity?id=X` returns one task with status, summary, agent info.
- **[PHP]** Extended `/activity` proxy to pass through `id` query param.
- **[JS]** `wpClawTaskManager` module (~420 lines): localStorage with 24h TTL, adaptive polling (30s→15s→backoff), status bar rendering (queued/working/done/failed), cross-tab persistence, dedup on click.
- **[PHP]** `data-task-key` attribute on 10 action buttons across 4 pages (security, seo, commerce, analytics). Old conflicting click handlers neutralized with `data-task-key` guard.

### Added — Empty States (Session 2, 2026-04-05)
- **[JS]** `wpClawSetEmptyState()` + 10-second timeout for 10 async loading containers. Shows contextual "No data yet" messages instead of permanent "Loading...".
- **[PHP]** Empty states for: A/B tests, stale content, file hashes, activity timeline, analytics data collection banner.
- **[FIX]** `dataset.loaded = 'true'` markers in all 4 view files' inline scripts — prevents timeout from overwriting real data that loaded before 10s.

### Fixed — Asset Loading (Session 2, 2026-04-05)
- **[CRITICAL]** Removed `.min` suffix logic from `class-wp-claw.php` and `class-module-chat.php`. Minified files (`wp-claw-public.min.js`, `wp-claw-chat.min.js`, etc.) don't exist — production sites silently failed to load analytics pixel + chat widget. Now loads source files directly.
- **[FIX]** Analytics pixel filename mismatch: PHP enqueued `wp-claw-analytics.js` (doesn't exist) instead of `wp-claw-public.js` (the actual pixel). Fixed to correct filename.

---

## [1.2.2] — 2026-04-02 — Security fixes, circuit breaker UI, live health polling

### Fixed — Security (3 bugs)
- **[CRITICAL]** Removed DEBUG logging in `class-api-client.php` that exposed first 8 + last 8 characters of the API key on every request. Was marked "TEMPORARY" but shipped in v1.2.1.
- **[CRITICAL]** `wp_claw_encrypt()` now returns empty string when `wp_salt('auth')` is the default placeholder. Previously logged a warning but continued encrypting with a predictable key — any WordPress installation with default salts had effectively no encryption.
- **[BUG]** `wp_claw_current_user_can()` crashed with TypeError when `get_userdata()` returned `false` (invalid user IDs). PHP's `??` null-coalescing doesn't catch `false->property`. Now safely checks the return value before accessing `->user_login`.

### Fixed — Capabilities
- **[BUG]** Command Center view (`admin/views/command-center.php`) checked `wp_claw_current_user_can('command_center')` — missing the `wp_claw_` prefix. The capability `command_center` doesn't exist on any role, so all users (including admins) got `wp_die()`. Fixed to `wp_claw_command_center`.

### Fixed — Admin UI
- **[BUG]** "Test Connection" success/failure never updated the connection banner on the Settings page. JS was targeting `.wpc-connection-status .wpc-status-dot` which doesn't exist — the banner uses `.wpc-connection-banner`. Rewrote to update the actual banner element.
- **[BUG]** "Resume Operations" button updated the dashboard alert banner but not the Settings page table row. Added DOM update for the Operations Status badge in the System Status table.

### Added — Circuit Breaker Management
- **[API]** `POST /admin/reset-circuit-breaker` — clears `wp_claw_circuit_failures` and `wp_claw_circuit_open_until` transients. Gated by `wp_claw_manage_settings` capability.
- **[UI]** System Status section in Settings now shows Circuit Breaker state: red "Open" with countdown + reset button, yellow with failure count + reset button, or green "Closed (healthy)".
- **[JS]** Click handler for reset button updates the row inline to green state on success.

### Added — Agents Endpoint Hardening
- **[API]** `handle_agents()` now validates instance URL before creating API client (parity with `handle_health()`). Returns 400 with helpful message instead of opaque 502 when URL is not configured.

### Added — Live Health Polling
- **[JS]** Settings page now auto-polls `/health` every 30 seconds (silent — banner only, no toast notifications). Connection banner updates in real-time.
- **[JS]** Immediate health check on Settings page load — banner reflects current state without requiring manual "Test Connection" click.
- **[JS]** Extracted `updateConnectionBanner(connected, message)` and `checkHealth(silent)` helper functions — shared by button click and auto-poll.

### Changed — Connection Banner Behavior
- **[JS]** Banner now updates for ALL response types: 200+ok → green, 200+non-ok → red, 404/500/502/network error → red with error message. Previously only showed a toast without updating the banner.

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
