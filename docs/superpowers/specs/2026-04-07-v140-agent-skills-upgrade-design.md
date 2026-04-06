# WP-Claw v1.4.0 — Agent Skills Upgrade Design

> **Date:** 2026-04-07
> **Author:** Claude Code (brainstorm session with Islem)
> **Version target:** v1.4.0
> **Status:** Approved design, pending implementation plan

## Summary

Upgrade WP-Claw with 6 new capabilities and comprehensive hardening, inspired by two open-source projects:
- **[WordPress/agent-skills](https://github.com/WordPress/agent-skills)** (1,143 stars) — Official WordPress AI skill library with canonical patterns for REST API, security, performance, and plugin architecture.
- **[Sarai-Chinwag/wp-openclaw](https://github.com/Sarai-Chinwag/wp-openclaw)** (56 stars) — VPS deployment kit with Data Machine prompt queue/self-chaining pattern.

Delivered as 7 feature slices, each bundling a new capability with related hardening work.

## Motivation

1. **REST arg schemas**: Only 7 of 26 routes have `validate_callback`/`sanitize_callback` — the rest rely on manual sanitization in handlers. Official `agent-skills` patterns require schemas on all endpoints.
2. **Uninstall gap**: `wp_claw_daily_digest` and `wp_claw_weekly_report` cron hooks missing from `uninstall.php`.
3. **Performance module is thin**: Stores CWV/PSI transients and does revision cleanup, but no autoload bloat detection, no object cache inspection, no structured diagnostics.
4. **No task chaining**: Every cron event is fire-and-forget. Agents cannot self-schedule follow-up work for multi-phase workflows.
5. **No WP 6.9 Abilities API integration**: WP-Claw modules are invisible to the standard WordPress capability discovery mechanism.
6. **Self-hosted auth is HMAC-only**: Power users running their own Klawty have no WordPress-native auth option.
7. **No live demo**: Prospects cannot try WP-Claw without provisioning a managed instance.

## Architecture: Feature Slices Approach

Each slice delivers one new capability AND hardens the routes/cron it touches. Slices are independently committable and testable.

---

## Slice 1: Structured Site Triage (`/state` upgrade)

### What changes

Extend the existing `run_sync_state()` in `class-cron.php` to produce a structured triage report inspired by `WordPress/agent-skills`' `detect_wp_project.mjs` output format. No new files — extends existing methods.

### Current response

```json
{
  "wordpress_version": "6.8",
  "theme": "flavor",
  "active_plugins": ["..."],
  "post_counts": {"..."},
  "woocommerce": {"..."},
  "enabled_modules": ["..."]
}
```

### New sections added to response

```json
{
  "signals": {
    "has_woocommerce": true,
    "has_block_theme": false,
    "has_multisite": false,
    "has_object_cache": true,
    "has_page_cache": false,
    "ssl_active": true,
    "debug_mode": false,
    "cron_disabled": false,
    "autoload_bytes": 245000
  },
  "tooling": {
    "php_version": "8.2.4",
    "mysql_version": "8.0.35",
    "server_software": "nginx/1.24",
    "memory_limit": "256M",
    "max_execution_time": 30,
    "upload_max_filesize": "64M"
  },
  "health": {
    "wp_cron_overdue_count": 0,
    "autoload_bloat": false,
    "failed_plugins": [],
    "db_tables_missing": [],
    "disk_free_pct": 72
  },
  "recommendations": []
}
```

### Hardening bundled

Add `args` with `validate_callback` + `sanitize_callback` to `/state`, `/execute`, and `/webhook` route registrations.

### Files touched

- `class-cron.php` — extend `run_sync_state()`, add helper methods for signals/tooling/health
- `class-rest-api.php` — arg schemas on 3 routes

---

## Slice 2: Performance Diagnostic Pipeline

### What changes

Upgrade `Module_Performance` with a structured diagnostic sequence modeled on `agent-skills`' `perf_inspect.mjs`.

### New methods in `Module_Performance`

| Method | What it does |
|---|---|
| `run_diagnostics()` | Orchestrator — runs all checks, builds report, stores in transient (24h TTL) |
| `check_autoload_bloat()` | Queries `wp_options` for autoloaded rows, sums sizes, flags if > 800KB. Returns top 10 offenders. |
| `check_object_cache()` | Detects `object-cache.php` drop-in, identifies provider (Redis/Memcached/none) |
| `check_page_cache()` | Detects `advanced-cache.php` drop-in, checks for known caching plugins |
| `check_cron_health()` | Counts overdue cron events, identifies stuck/zombie hooks |
| `check_database_bloat()` | Counts orphaned postmeta, expired transients, trashed posts, spam comments, revisions |
| `check_autoload_self()` | Audits WP-Claw's own `wp_claw_*` options — flags any autoloaded and > 10KB |

### Diagnostic report format

```json
{
  "generated_at": "2026-04-07T14:00:00Z",
  "score": 72,
  "checks": [
    { "id": "autoload_bloat", "status": "warning", "value": "1.2MB", "threshold": "800KB", "top_offenders": ["..."] },
    { "id": "object_cache", "status": "pass", "provider": "redis", "connected": true },
    { "id": "page_cache", "status": "fail", "detail": "No page caching detected" },
    { "id": "cron_health", "status": "pass", "overdue": 0, "total": 18 },
    { "id": "database_bloat", "status": "warning", "orphaned_meta": 4200, "expired_transients": 89, "revisions": 12500 },
    { "id": "self_audit", "status": "pass", "detail": "All wp_claw_* options under 10KB" }
  ],
  "recommendations": [
    "Install a page caching plugin or enable server-level caching",
    "Run database cleanup: 4,200 orphaned postmeta rows, 89 expired transients"
  ]
}
```

### Cron change

Current `wp_claw_performance_check` cron hits Klawty to create a task. New behavior: first run `run_diagnostics()` locally (lightweight PHP queries), then dispatch results to Klawty so Selma has real data to analyze.

### Admin view

Performance section in dashboard gets a "Site Health Report" card showing latest diagnostic score + check statuses. Clickable to expand details.

### Hardening bundled

Audit performance cron handler for idempotency and execution time.

### Files touched

- `includes/modules/class-module-performance.php` — 7 new methods
- `class-cron.php` — modified performance handler
- `admin/views/dashboard.php` — health report card

---

## Slice 3: Task Chaining (Queue + Dashboard + Pause/Cancel)

### Architecture: Option C (Klawty owns execution, WP-Claw has visibility + control)

Adapted from wp-openclaw's Data Machine prompt queue pattern to WP-Claw's distributed architecture. Klawty orchestrator handles execution sequencing; WP-Claw stores chain state for dashboard visibility and admin control (pause/resume/cancel).

### New database table

```sql
CREATE TABLE {prefix}wp_claw_task_chains (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chain_id        VARCHAR(64) NOT NULL,
    parent_task_id  VARCHAR(64) DEFAULT NULL,
    agent           VARCHAR(32) NOT NULL,
    title           VARCHAR(255) NOT NULL,
    prompt          TEXT NOT NULL,
    step_order      INT UNSIGNED NOT NULL,
    status          ENUM('queued','dispatched','working','done','failed','paused','cancelled') DEFAULT 'queued',
    klawty_task_id  VARCHAR(64) DEFAULT NULL,
    result_summary  TEXT DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    dispatched_at   DATETIME DEFAULT NULL,
    completed_at    DATETIME DEFAULT NULL,
    INDEX idx_chain (chain_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Lifecycle

1. Klawty agent completes a task, decides follow-up is needed
2. Orchestrator POSTs to WP-Claw webhook: `{ "event": "task_chain", "chain_id": "abc123", "next_steps": [...] }`
3. WP-Claw inserts rows into `wp_claw_task_chains`, all status `queued`
4. Hourly sync cron finds chains where step N-1 is `done` and step N is `queued`, dispatches step N to Klawty
5. Klawty completes step → webhook back → status updated to `done`
6. Next cron cycle dispatches next queued step
7. Admin can pause/resume/cancel chains at any time

### Constitutional constraints

- Max 10 steps per chain (prevents runaway loops)
- Max 3 active chains per agent simultaneously
- T3 daily counter applies — structural changes in chains count toward daily 5
- Health-fail halt stops all chain dispatching
- Each step is proposal-eligible — destructive actions pause for approval

### New REST endpoints

- `GET /wp-claw/v1/chains` — list active chains (capability: `manage_options`)
- `POST /wp-claw/v1/chains/{id}/pause` — pause chain (capability: `manage_options`)
- `POST /wp-claw/v1/chains/{id}/resume` — resume chain (capability: `manage_options`)
- `POST /wp-claw/v1/chains/{id}/cancel` — cancel chain (capability: `manage_options`)

### Admin UI

- Dashboard: "Active Chains" card — agent avatar, chain title, progress bar, pause/cancel buttons
- Proposals page: chain-linked proposals show breadcrumb ("Step 2 of 5 — SEO audit chain")

### Hardening bundled

- Arg schemas on `/command`, `/proposals/{id}/approve`, `/proposals/{id}/reject`
- Fix missing `wp_claw_daily_digest` and `wp_claw_weekly_report` in `uninstall.php` cron list
- New table in `class-activator.php` (create) and `uninstall.php` (drop)

### Files touched

- `class-activator.php` — new table
- `class-rest-api.php` — 4 new routes + arg schemas on existing routes
- `class-cron.php` — chain dispatch logic
- `admin/views/dashboard.php` — Active Chains card
- `admin/js/wp-claw-admin.js` — pause/resume/cancel handlers
- `uninstall.php` — drop table + fix cron hooks

---

## Slice 4: Abilities API Registration

### What changes

Register each WP-Claw module as a WordPress 6.9 Ability via `wp_register_ability()`, discoverable at `/wp-json/wp-abilities/v1/`.

### Registration

New method `register_abilities()` in `class-wp-claw.php`, hooked on `init`:

- Registers `wp-claw` ability category
- Registers 12 abilities (one per module) with label, description, category, and `is_enabled` flag
- Guarded by `function_exists( 'wp_register_ability' )` for WP < 6.9 compatibility

### Why it matters

- Other AI agents/plugins can discover WP-Claw's modules through standard WordPress API
- Theme developers can check `wp_has_ability( 'wp-claw-seo' )` to avoid conflicts
- Positions WP-Claw as a first-class WP 6.9 citizen

### Files touched

- `class-wp-claw.php` — new method + hook

---

## Slice 5: Application Passwords as Alternative Auth

### What changes

Add Application Passwords (WP 5.6+) as an alternative auth mode for self-hosted users alongside the existing Bearer + HMAC method.

### Two modes

| Mode | Audience | Auth mechanism |
|---|---|---|
| **Managed** (default) | 90% — via ai-agent-builder.ai | Bearer + HMAC-SHA256 (unchanged) |
| **Self-hosted** | Power users with own Klawty | Standard WP Application Password (Basic Auth over HTTPS) |

### Auth resolution in `verify_signature()`

```
1. HMAC header present? → verify_hmac_signature() (managed mode)
2. WP user authenticated via Basic Auth + manage_options? → allow (app password mode)
3. Neither? → 401
```

HMAC takes precedence if both are present.

### Settings UI

New connection mode selector. When "Self-Hosted" is selected:
- "Generate Application Password" button
- Stores UUID for revocation (not the password itself)
- Shows configuration instructions for Klawty instance

### Security

- Application Passwords require HTTPS (WP core enforced)
- Individually revocable from Users → Profile
- Auth mode logged in `wp_claw_command_log` for audit trail

### Hardening bundled

Arg schemas on remaining auth-related routes: `/chat/send`, `/chat/history`, `/analytics`, `/inline-edit`, `/agent-action`.

### Files touched

- `class-rest-api.php` — refactor `verify_signature()` + arg schemas on 6 routes
- `admin/views/settings.php` — connection mode selector + generate button
- `admin/js/wp-claw-admin.js` — generation AJAX handler

---

## Slice 6: WP Playground Blueprint for Demos

### What changes

A Blueprint JSON that spins up a disposable WordPress + WooCommerce + WP-Claw instance in WordPress Playground. Embeddable on wp-claw.ai as a zero-cost "Try it live" iframe.

### Blueprint

`playground/blueprint.json` — installs WP 6.9 + PHP 8.2, adds WooCommerce plugin, installs WP-Claw from release ZIP, imports demo content WXR, sets demo mode option, logs in as admin.

### Demo mode

When `wp_claw_demo_mode` option is `"1"`:
- All API calls return mock data (no Klawty connection needed)
- 6 agents shown as active with sample task history
- 3 pre-seeded proposals for approve/reject interaction
- 1 sample task chain showing chain UI
- Pre-built performance diagnostic report
- Chat widget returns canned responses (no LLM cost)
- Admin banner: "This is a demo. [Connect to a real instance →]"

### Demo data provider

New file: `includes/class-demo-provider.php` (~200 lines). Static methods returning mock data arrays. Guarded by single check at top of each REST handler:

```php
if ( get_option( 'wp_claw_demo_mode' ) ) {
    return rest_ensure_response( Demo_Provider::proposals() );
}
```

### Demo content

`playground/demo-content.xml` — WXR file with:
- 5 WooCommerce products (simple, variable, digital)
- 10 blog posts (some with missing meta for SEO dashboard)
- 2 pages (About, Contact)
- Sample order history

### Embedding on wp-claw.ai

```html
<iframe
  src="https://playground.wordpress.net/?blueprint-url=https://wp-claw.ai/playground/blueprint.json&redirect=/wp-admin/admin.php?page=wp-claw"
  style="width: 100%; height: 700px; border: 1px solid #e5e7eb; border-radius: 12px;"
></iframe>
```

### Files created

- `playground/blueprint.json`
- `playground/demo-content.xml`
- `includes/class-demo-provider.php`

### Files touched

- `class-rest-api.php` — demo mode guards
- `admin/views/dashboard.php` — demo banner

---

## Slice 7: Final Sweep

### REST arg schemas — remaining routes

Add schemas to ~8 routes not covered by slices 1-5:

| Route | Args to add |
|---|---|
| `/command/setup-pin` | `pin`: string, 4-8 chars, `sanitize_text_field` |
| `/command/verify-pin` | `pin`: same |
| `/proposals` (GET) | `status`: enum filter, `sanitize_key` |
| `/proposals/{id}` (GET) | `id`: integer, `absint` |
| `/activity` (GET) | `page`, `per_page`, `agent`, `id` with types and sanitizers |
| `/agents` (GET) | Optional `agent` filter |
| `/health` (GET) | Explicit empty `args => array()` |
| `/reports/{type}` (GET) | `type`: enum, `sanitize_key` |

### Uninstall verification

- 11 tables dropped (10 existing + `task_chains`)
- All `wp_claw_*` options bulk-deleted
- All transients bulk-deleted
- 20 cron hooks cleared (18 + daily_digest + weekly_report)
- Capabilities removed
- Backup directory removed
- Demo content cleanup if present

### Cron audit — batch limits

| Cron handler | Issue | Fix |
|---|---|---|
| `wp_claw_file_integrity` | Scans all plugin/theme files unbounded | Batch limit: 500 files per run, bookmark-based continuation |
| `wp_claw_malware_scan` | Similarly unbounded | Same batch treatment |
| `wp_claw_segmentation` | Unbounded WooCommerce queries | Add `LIMIT` guard for large stores |

### Version bump

All slices together = **v1.4.0**.

### Files touched

- `class-rest-api.php` — arg schemas on remaining routes
- `uninstall.php` — verification pass
- `class-cron.php` — batch limits
- `includes/modules/class-module-security.php` — batch file scanning
- `CHANGELOG.md`, `README.md`, `readme.txt` — version bump + documentation

---

## File Impact Summary

| File | Slices touching it |
|---|---|
| `class-rest-api.php` | 1, 3, 5, 6, 7 |
| `class-cron.php` | 1, 2, 3, 7 |
| `class-wp-claw.php` | 4 |
| `class-activator.php` | 3 |
| `uninstall.php` | 3, 7 |
| `class-module-performance.php` | 2 |
| `class-module-security.php` | 7 |
| `admin/views/dashboard.php` | 2, 3, 6 |
| `admin/views/settings.php` | 5 |
| `admin/js/wp-claw-admin.js` | 3, 5 |
| **New:** `class-demo-provider.php` | 6 |
| **New:** `playground/blueprint.json` | 6 |
| **New:** `playground/demo-content.xml` | 6 |

## Success Criteria

1. All 26+ REST routes have explicit `args` with `validate_callback` and `sanitize_callback`
2. `uninstall.php` cleans up all 11 tables, all cron hooks (20), all options, all transients
3. Cron handlers are idempotent, batched where needed, under 30s execution
4. Task chains work end-to-end: Klawty creates chain → WP-Claw stores → dispatches steps → admin can pause/cancel
5. Performance diagnostics produce structured report with score and actionable recommendations
6. Abilities registered and visible at `/wp-json/wp-abilities/v1/` on WP 6.9+
7. Application Passwords auth works for self-hosted mode
8. Playground Blueprint boots a working demo with mock data in under 60 seconds
9. Plugin passes `phpcs --standard=WordPress-Extra` after all changes

## Source References

- WordPress/agent-skills: `wp-rest-api` skill (arg schemas, `permission_callback`, response patterns)
- WordPress/agent-skills: `wp-performance` skill + `perf_inspect.mjs` (diagnostic sequence)
- WordPress/agent-skills: `wp-plugin-development` skill (lifecycle, security, uninstall patterns)
- WordPress/agent-skills: `wp-abilities-api` skill (Abilities API registration)
- Sarai-Chinwag/wp-openclaw: Data Machine prompt queue pattern (task chaining)
- Sarai-Chinwag/wp-openclaw: `docs/securing-openclaw-vps.md` (cron safety principles)
- WordPress/agent-skills: `eval/scenarios/` format (potential future adoption for agent testing)
