# WP-Claw v1.4.0 — Agent Skills Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade WP-Claw with 6 new capabilities (structured site triage, performance diagnostics, task chaining, module discovery, Application Passwords auth, Playground demo) and harden all 26 REST routes with arg schemas.

**Architecture:** 7 feature slices delivered sequentially. Each slice bundles one new capability with related REST/cron hardening. PHP 7.4+, WordPress 6.4+, WPCS-compliant.

**Tech Stack:** PHP (WordPress plugin), vanilla JS, WordPress REST API, WP-Cron, `$wpdb`, `dbDelta()`

**Spec:** `docs/superpowers/specs/2026-04-07-v140-agent-skills-upgrade-design.md`

---

## File Structure

### Modified files

| File | Responsibility | Slices |
|---|---|---|
| `includes/class-rest-api.php` | All REST route registration + handlers | 1, 3, 4, 5, 6, 7 |
| `includes/class-cron.php` | Cron event handlers + state sync | 1, 2, 3, 7 |
| `includes/modules/class-module-performance.php` | Performance diagnostics | 2 |
| `includes/class-activator.php` | DB table creation + cron scheduling | 3 |
| `includes/class-wp-claw.php` | Main plugin class + future abilities stub | 4 |
| `includes/modules/class-module-security.php` | File integrity + malware scan batching | 7 |
| `uninstall.php` | Cleanup on plugin removal | 3, 7 |
| `admin/views/dashboard.php` | Admin dashboard cards | 2, 3, 6 |
| `admin/views/proposals.php` | Proposal list + chain breadcrumbs | 3 |
| `admin/views/settings.php` | Connection mode selector | 5 |
| `admin/js/wp-claw-admin.js` | Frontend JS handlers | 3, 5 |
| `admin/css/wp-claw-admin.css` | Admin styles | 2, 3 |
| `wp-claw.php` | Version constant | 7 |
| `readme.txt` | Plugin metadata | 7 |
| `CHANGELOG.md` | Change log | 7 |

### New files

| File | Responsibility | Slice |
|---|---|---|
| `includes/class-demo-provider.php` | Mock data for demo mode | 6 |
| `playground/blueprint.json` | WP Playground Blueprint | 6 |
| `playground/demo-content.xml` | WXR demo import data | 6 |

---

## Task 1: Slice 1 — Structured Site Triage + Route Hardening (3 routes)

**Files:**
- Modify: `includes/class-cron.php:178-230` (extend `run_sync_state()`)
- Modify: `includes/class-rest-api.php:116-144` (arg schemas on `/execute`, `/state`, `/webhook`)

### Triage helpers in class-cron.php

- [ ] **Step 1: Add `get_site_signals()` helper method after `run_sync_state()`**

```php
/**
 * Gather site environment signals for triage.
 *
 * @since 1.4.0
 * @return array
 */
private function get_site_signals(): array {
    global $wpdb;

    // Autoload byte size.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $autoload_bytes = (int) $wpdb->get_var(
        "SELECT SUM( LENGTH( option_value ) ) FROM {$wpdb->options} WHERE autoload = 'yes'"
    );

    $theme = wp_get_theme();

    return array(
        'has_woocommerce'  => class_exists( 'WooCommerce' ),
        'has_block_theme'  => (bool) $theme->is_block_theme(),
        'has_multisite'    => is_multisite(),
        'has_object_cache' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
        'has_page_cache'   => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
        'ssl_active'       => is_ssl(),
        'debug_mode'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        'autoload_bytes'   => $autoload_bytes,
    );
}
```

- [ ] **Step 2: Add `get_site_tooling()` helper**

```php
/**
 * Gather server tooling information.
 *
 * @since 1.4.0
 * @return array
 */
private function get_site_tooling(): array {
    global $wpdb;

    return array(
        'php_version'          => PHP_VERSION,
        'mysql_version'        => $wpdb->db_version(),
        'server_software'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
        'memory_limit'         => ini_get( 'memory_limit' ) ?: 'unknown',
        'max_execution_time'   => (int) ini_get( 'max_execution_time' ),
        'upload_max_filesize'  => ini_get( 'upload_max_filesize' ) ?: 'unknown',
    );
}
```

- [ ] **Step 3: Add `get_site_health()` helper**

```php
/**
 * Gather site health indicators.
 *
 * @since 1.4.0
 * @return array
 */
private function get_site_health(): array {
    global $wpdb;

    // Count overdue cron events.
    $crons   = _get_cron_array();
    $now     = time();
    $overdue = 0;
    if ( is_array( $crons ) ) {
        foreach ( $crons as $timestamp => $hooks ) {
            if ( $timestamp < $now ) {
                $overdue += count( $hooks );
            }
        }
    }

    // Autoload bloat check (> 800KB).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $autoload_total = (int) $wpdb->get_var(
        "SELECT SUM( LENGTH( option_value ) ) FROM {$wpdb->options} WHERE autoload = 'yes'"
    );

    // Check for missing WP-Claw tables.
    $expected_tables = array(
        'wp_claw_tasks', 'wp_claw_proposals', 'wp_claw_analytics',
        'wp_claw_command_log', 'wp_claw_file_hashes', 'wp_claw_ab_tests',
        'wp_claw_abandoned_carts', 'wp_claw_email_drafts',
        'wp_claw_cwv_history', 'wp_claw_snapshots',
    );
    $missing_tables = array();
    foreach ( $expected_tables as $table ) {
        $full_name = $wpdb->prefix . $table;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) );
        if ( null === $exists ) {
            $missing_tables[] = $table;
        }
    }

    // Failed plugins (active but with errors).
    $failed = array();
    if ( function_exists( 'get_plugin_updates' ) ) {
        // Only check if admin context loaded.
        $active = (array) get_option( 'active_plugins', array() );
        foreach ( $active as $plugin_file ) {
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if ( ! file_exists( $plugin_path ) ) {
                $failed[] = $plugin_file;
            }
        }
    }

    // Disk free percentage.
    $disk_free_pct = null;
    $disk_free     = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false;
    $disk_total    = function_exists( 'disk_total_space' ) ? @disk_total_space( ABSPATH ) : false;
    if ( false !== $disk_free && false !== $disk_total && $disk_total > 0 ) {
        $disk_free_pct = (int) round( ( $disk_free / $disk_total ) * 100 );
    }

    return array(
        'wp_cron_overdue_count' => $overdue,
        'autoload_bloat'        => $autoload_total > 800000,
        'failed_plugins'        => $failed,
        'db_tables_missing'     => $missing_tables,
        'disk_free_pct'         => $disk_free_pct,
    );
}
```

- [ ] **Step 4: Extend `run_sync_state()` to include triage sections**

In `run_sync_state()`, after the existing `$state` array is built (around line 193), add:

```php
$state['signals']         = $this->get_site_signals();
$state['tooling']         = $this->get_site_tooling();
$state['health']          = $this->get_site_health();
$state['recommendations'] = $this->build_recommendations( $state['signals'], $state['health'] );
```

- [ ] **Step 5: Add `build_recommendations()` helper**

```php
/**
 * Build actionable recommendations from signals and health data.
 *
 * @since 1.4.0
 *
 * @param array $signals Site signals.
 * @param array $health  Site health data.
 * @return string[]
 */
private function build_recommendations( array $signals, array $health ): array {
    $recs = array();

    if ( ! $signals['has_object_cache'] ) {
        $recs[] = 'Install an object cache drop-in (Redis or Memcached) for better performance.';
    }
    if ( ! $signals['has_page_cache'] ) {
        $recs[] = 'Install a page caching plugin or enable server-level caching.';
    }
    if ( $health['autoload_bloat'] ) {
        $recs[] = 'Autoloaded options exceed 800KB — review and disable autoload on large options.';
    }
    if ( $signals['debug_mode'] ) {
        $recs[] = 'WP_DEBUG is enabled — disable in production for security and performance.';
    }
    if ( ! $signals['ssl_active'] ) {
        $recs[] = 'SSL is not active — enable HTTPS for security.';
    }
    if ( $health['wp_cron_overdue_count'] > 5 ) {
        $recs[] = sprintf( '%d overdue cron events detected — check cron execution.', $health['wp_cron_overdue_count'] );
    }
    if ( ! empty( $health['db_tables_missing'] ) ) {
        $recs[] = 'Missing WP-Claw tables: ' . implode( ', ', $health['db_tables_missing'] ) . '. Deactivate and reactivate the plugin.';
    }

    return $recs;
}
```

- [ ] **Step 6: Verify state sync works**

Run: `wp option get wp_claw_last_sync` (or trigger manually if Klawty is connected).

### Route hardening — 3 HMAC-signed routes

- [ ] **Step 7: Add arg schemas to `/execute` route (line 116)**

Replace the route registration at line 116-124 with:

```php
register_rest_route(
    self::NAMESPACE,
    '/execute',
    array(
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'handle_execute' ),
        'permission_callback' => array( $this, 'verify_signature' ),
        'args'                => array(
            'action' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'module' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ),
            'params' => array(
                'type'    => 'object',
                'default' => array(),
            ),
        ),
    )
);
```

- [ ] **Step 8: Add arg schemas to `/state` route (line 126)**

```php
register_rest_route(
    self::NAMESPACE,
    '/state',
    array(
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => array( $this, 'handle_state' ),
        'permission_callback' => array( $this, 'verify_signature' ),
        'args'                => array(),
    )
);
```

- [ ] **Step 9: Add arg schemas to `/webhook` route (line 136)**

```php
register_rest_route(
    self::NAMESPACE,
    '/webhook',
    array(
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'handle_webhook' ),
        'permission_callback' => array( $this, 'verify_signature' ),
        'args'                => array(
            'event' => array(
                'type'              => 'string',
                'default'           => 'task_update',
                'sanitize_callback' => 'sanitize_key',
            ),
            'task_id' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'status' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ),
        ),
    )
);
```

- [ ] **Step 10: Commit Slice 1**

```bash
git add includes/class-cron.php includes/class-rest-api.php
git commit -m "feat(v1.4.0): slice 1 — structured site triage + arg schemas on 3 routes

Extend run_sync_state() with signals/tooling/health/recommendations sections.
Add validate/sanitize schemas to /execute, /state, /webhook routes."
```

---

## Task 2: Slice 2 — Performance Diagnostic Pipeline

**Files:**
- Modify: `includes/modules/class-module-performance.php` (7 new methods)
- Modify: `includes/class-cron.php` (modified performance handler)
- Modify: `admin/views/dashboard.php` (health report card)

### Diagnostic methods

- [ ] **Step 1: Add `check_autoload_bloat()` to `Module_Performance`**

Add after `handle_store_pagespeed_data()` (end of action handlers section):

```php
// -------------------------------------------------------------------------
// Diagnostic checks (v1.4.0)
// -------------------------------------------------------------------------

/**
 * Check autoloaded options total size and top offenders.
 *
 * @since 1.4.0
 * @return array Diagnostic check result.
 */
private function check_autoload_bloat(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $total = (int) $wpdb->get_var(
        "SELECT SUM( LENGTH( option_value ) ) FROM {$wpdb->options} WHERE autoload = 'yes'"
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $top = $wpdb->get_results(
        "SELECT option_name, LENGTH( option_value ) AS size_bytes
         FROM {$wpdb->options}
         WHERE autoload = 'yes'
         ORDER BY size_bytes DESC
         LIMIT 10",
        ARRAY_A
    );

    $threshold = 800000; // 800KB.
    $status    = $total > $threshold ? 'warning' : 'pass';

    return array(
        'id'            => 'autoload_bloat',
        'status'        => $status,
        'value'         => size_format( $total ),
        'value_bytes'   => $total,
        'threshold'     => size_format( $threshold ),
        'top_offenders' => is_array( $top ) ? $top : array(),
    );
}
```

- [ ] **Step 2: Add `check_object_cache()`**

```php
/**
 * Detect object cache drop-in and identify provider.
 *
 * @since 1.4.0
 * @return array Diagnostic check result.
 */
private function check_object_cache(): array {
    $drop_in = WP_CONTENT_DIR . '/object-cache.php';
    $exists  = file_exists( $drop_in );

    $provider = 'none';
    if ( $exists ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local drop-in.
        $contents = file_get_contents( $drop_in );
        if ( false !== $contents ) {
            if ( false !== stripos( $contents, 'redis' ) ) {
                $provider = 'redis';
            } elseif ( false !== stripos( $contents, 'memcache' ) ) {
                $provider = 'memcached';
            } else {
                $provider = 'unknown';
            }
        }
    }

    $connected = $exists && wp_using_ext_object_cache();

    return array(
        'id'        => 'object_cache',
        'status'    => $exists && $connected ? 'pass' : 'fail',
        'provider'  => $provider,
        'connected' => $connected,
    );
}
```

- [ ] **Step 3: Add `check_page_cache()`**

```php
/**
 * Detect page cache drop-in and known caching plugins.
 *
 * @since 1.4.0
 * @return array Diagnostic check result.
 */
private function check_page_cache(): array {
    $drop_in = WP_CONTENT_DIR . '/advanced-cache.php';
    $exists  = file_exists( $drop_in );

    $known_plugins = array(
        'wp-super-cache/wp-cache.php',
        'w3-total-cache/w3-total-cache.php',
        'wp-fastest-cache/wpFastestCache.php',
        'litespeed-cache/litespeed-cache.php',
        'wp-rocket/wp-rocket.php',
    );

    $active_cache_plugin = null;
    $active_plugins      = (array) get_option( 'active_plugins', array() );
    foreach ( $known_plugins as $plugin ) {
        if ( in_array( $plugin, $active_plugins, true ) ) {
            $active_cache_plugin = dirname( $plugin );
            break;
        }
    }

    $has_cache = $exists || null !== $active_cache_plugin;

    $result = array(
        'id'     => 'page_cache',
        'status' => $has_cache ? 'pass' : 'fail',
    );

    if ( null !== $active_cache_plugin ) {
        $result['plugin'] = $active_cache_plugin;
    }
    if ( ! $has_cache ) {
        $result['detail'] = 'No page caching detected';
    }

    return $result;
}
```

- [ ] **Step 4: Add `check_cron_health()`**

```php
/**
 * Count overdue cron events and total scheduled hooks.
 *
 * @since 1.4.0
 * @return array Diagnostic check result.
 */
private function check_cron_health(): array {
    $crons   = _get_cron_array();
    $now     = time();
    $overdue = 0;
    $total   = 0;

    if ( is_array( $crons ) ) {
        foreach ( $crons as $timestamp => $hooks ) {
            $count = count( $hooks );
            $total += $count;
            if ( $timestamp < $now ) {
                $overdue += $count;
            }
        }
    }

    return array(
        'id'      => 'cron_health',
        'status'  => $overdue > 5 ? 'warning' : 'pass',
        'overdue' => $overdue,
        'total'   => $total,
    );
}
```

- [ ] **Step 5: Add `check_database_bloat()`**

```php
/**
 * Count orphaned postmeta, expired transients, trashed posts, spam, revisions.
 *
 * @since 1.4.0
 * @return array Diagnostic check result.
 */
private function check_database_bloat(): array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    $orphaned_meta = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.ID IS NULL"
    );

    $now               = time();
    $expired_transients = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_value < %d",
            $wpdb->esc_like( '_transient_timeout_' ) . '%',
            $now
        )
    );

    $trashed = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
            'trash'
        )
    );

    $spam = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = %s",
            'spam'
        )
    );

    $revisions = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
            'revision'
        )
    );

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    $total_waste = $orphaned_meta + $expired_transients + $trashed + $spam;
    $status      = $total_waste > 1000 ? 'warning' : 'pass';

    return array(
        'id'                  => 'database_bloat',
        'status'              => $status,
        'orphaned_meta'       => $orphaned_meta,
        'expired_transients'  => $expired_transients,
        'trashed_posts'       => $trashed,
        'spam_comments'       => $spam,
        'revisions'           => $revisions,
    );
}
```

- [ ] **Step 6: Add `check_autoload_self()`**

```php
/**
 * Audit WP-Claw's own options for autoload bloat.
 *
 * @since 1.4.0
 * @return array Diagnostic check result.
 */
private function check_autoload_self(): array {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $bloated = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, LENGTH( option_value ) AS size_bytes
             FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND autoload = 'yes'
               AND LENGTH( option_value ) > 10240
             ORDER BY size_bytes DESC",
            $wpdb->esc_like( 'wp_claw_' ) . '%'
        ),
        ARRAY_A
    );

    $has_bloat = is_array( $bloated ) && count( $bloated ) > 0;

    return array(
        'id'       => 'self_audit',
        'status'   => $has_bloat ? 'warning' : 'pass',
        'detail'   => $has_bloat
            ? sprintf( '%d wp_claw_* options exceed 10KB with autoload on', count( $bloated ) )
            : 'All wp_claw_* options under 10KB',
        'offenders' => $has_bloat ? $bloated : array(),
    );
}
```

- [ ] **Step 7: Add `run_diagnostics()` orchestrator**

```php
/**
 * Run all diagnostic checks and build a scored report.
 *
 * Stores the report in a transient with 24h TTL so the admin dashboard
 * and Klawty agent can read it without re-running checks.
 *
 * @since 1.4.0
 * @return array The full diagnostic report.
 */
public function run_diagnostics(): array {
    $checks = array(
        $this->check_autoload_bloat(),
        $this->check_object_cache(),
        $this->check_page_cache(),
        $this->check_cron_health(),
        $this->check_database_bloat(),
        $this->check_autoload_self(),
    );

    // Score: start at 100, deduct per issue.
    $score = 100;
    $recs  = array();
    foreach ( $checks as $check ) {
        if ( 'fail' === $check['status'] ) {
            $score -= 15;
        } elseif ( 'warning' === $check['status'] ) {
            $score -= 8;
        }

        // Build recommendations.
        switch ( $check['id'] ) {
            case 'autoload_bloat':
                if ( 'warning' === $check['status'] ) {
                    $recs[] = sprintf( 'Autoloaded options total %s (threshold: %s) — review top offenders.', $check['value'], $check['threshold'] );
                }
                break;
            case 'object_cache':
                if ( 'fail' === $check['status'] ) {
                    $recs[] = 'Install an object cache drop-in (Redis or Memcached).';
                }
                break;
            case 'page_cache':
                if ( 'fail' === $check['status'] ) {
                    $recs[] = 'Install a page caching plugin or enable server-level caching.';
                }
                break;
            case 'cron_health':
                if ( 'warning' === $check['status'] ) {
                    $recs[] = sprintf( '%d overdue cron events — check WP-Cron execution.', $check['overdue'] );
                }
                break;
            case 'database_bloat':
                if ( 'warning' === $check['status'] ) {
                    $recs[] = sprintf(
                        'Database cleanup needed: %d orphaned meta, %d expired transients, %d revisions.',
                        $check['orphaned_meta'],
                        $check['expired_transients'],
                        $check['revisions']
                    );
                }
                break;
            case 'self_audit':
                if ( 'warning' === $check['status'] ) {
                    $recs[] = 'Some wp_claw_* options are large and autoloaded — consider disabling autoload.';
                }
                break;
        }
    }

    $report = array(
        'generated_at'    => gmdate( 'c' ),
        'score'           => max( 0, $score ),
        'checks'          => $checks,
        'recommendations' => $recs,
    );

    set_transient( 'wp_claw_perf_report', $report, DAY_IN_SECONDS );

    return $report;
}
```

- [ ] **Step 8: Modify performance cron handler in `class-cron.php`**

In `run_module_cron()`, add a special case for 'performance' that runs diagnostics first:

After the existing `$module_slug = sanitize_key( $module_slug );` line in `run_module_cron()`, add before the existing dispatch logic:

```php
// For performance module, run local diagnostics first, then send results to Klawty.
if ( 'performance' === $module_slug ) {
    $plugin = WP_Claw::get_instance();
    $module = $plugin->get_module( 'performance' );
    if ( null !== $module && method_exists( $module, 'run_diagnostics' ) ) {
        $report = $module->run_diagnostics();
        wp_claw_log_debug( 'Performance diagnostics completed.', array( 'score' => $report['score'] ) );
    }
}
```

- [ ] **Step 9: Add health report card to `admin/views/dashboard.php`**

Add after the existing KPI cards section. The card reads the `wp_claw_perf_report` transient and renders score + checks:

```php
<?php
$perf_report = get_transient( 'wp_claw_perf_report' );
if ( is_array( $perf_report ) && ! empty( $perf_report['checks'] ) ) :
    $score_class = 'wp-claw-score-good';
    if ( $perf_report['score'] < 50 ) {
        $score_class = 'wp-claw-score-bad';
    } elseif ( $perf_report['score'] < 75 ) {
        $score_class = 'wp-claw-score-warning';
    }
?>
<div class="wp-claw-card wp-claw-health-report">
    <h3><?php esc_html_e( 'Site Health Report', 'claw-agent' ); ?></h3>
    <div class="wp-claw-health-score <?php echo esc_attr( $score_class ); ?>">
        <?php echo esc_html( $perf_report['score'] ); ?><span>/100</span>
    </div>
    <div class="wp-claw-health-checks">
        <?php foreach ( $perf_report['checks'] as $check ) : ?>
            <div class="wp-claw-health-check wp-claw-check-<?php echo esc_attr( $check['status'] ); ?>">
                <span class="wp-claw-check-indicator"></span>
                <span class="wp-claw-check-id"><?php echo esc_html( ucwords( str_replace( '_', ' ', $check['id'] ) ) ); ?></span>
                <span class="wp-claw-check-status"><?php echo esc_html( $check['status'] ); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ( ! empty( $perf_report['recommendations'] ) ) : ?>
        <div class="wp-claw-health-recs">
            <h4><?php esc_html_e( 'Recommendations', 'claw-agent' ); ?></h4>
            <ul>
                <?php foreach ( $perf_report['recommendations'] as $rec ) : ?>
                    <li><?php echo esc_html( $rec ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <p class="wp-claw-health-timestamp">
        <?php
        /* translators: %s: date/time of last report */
        printf( esc_html__( 'Last checked: %s', 'claw-agent' ), esc_html( $perf_report['generated_at'] ) );
        ?>
    </p>
</div>
<?php endif; ?>
```

- [ ] **Step 10: Commit Slice 2**

```bash
git add includes/modules/class-module-performance.php includes/class-cron.php admin/views/dashboard.php
git commit -m "feat(v1.4.0): slice 2 — performance diagnostic pipeline

7 diagnostic checks: autoload bloat, object cache, page cache, cron health,
database bloat, self-audit. Scored report in transient. Dashboard health card.
Cron handler runs diagnostics locally before dispatching to Klawty."
```

---

## Task 3: Slice 3 — Task Chaining (Queue + Dashboard + Pause/Cancel)

**Files:**
- Modify: `includes/class-activator.php` (new table)
- Modify: `includes/class-rest-api.php` (4 new routes + webhook routing + arg schemas)
- Modify: `includes/class-cron.php` (chain dispatch)
- Modify: `admin/views/dashboard.php` (Active Chains card)
- Modify: `admin/views/proposals.php` (chain breadcrumb)
- Modify: `admin/js/wp-claw-admin.js` (pause/resume/cancel)
- Modify: `uninstall.php` (drop table + fix cron hooks)

- [ ] **Step 1: Add `wp_claw_task_chains` table to `class-activator.php`**

In the `create_tables()` method, add a new `$sql[]` entry after the last existing table (matches the existing `$sql = array()` + `$sql[]` pattern):

```php
// Task chains table (v1.4.0).
$sql[] = "CREATE TABLE {$wpdb->prefix}wp_claw_task_chains (
    id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    chain_id        varchar(64) NOT NULL,
    parent_task_id  varchar(64) DEFAULT NULL,
    agent           varchar(32) NOT NULL,
    title           varchar(255) NOT NULL,
    prompt          text NOT NULL,
    step_order      int(10) UNSIGNED NOT NULL,
    status          varchar(20) NOT NULL DEFAULT 'queued',
    klawty_task_id  varchar(64) DEFAULT NULL,
    result_summary  text DEFAULT NULL,
    created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dispatched_at   datetime DEFAULT NULL,
    completed_at    datetime DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_chain (chain_id),
    KEY idx_status (status)
) $charset_collate;\n\n";
```

- [ ] **Step 2: Add webhook event routing in `handle_webhook()` (line 802)**

Replace the existing `handle_webhook()` method:

```php
public function handle_webhook( \WP_REST_Request $request ) {
    global $wpdb;

    $body       = $request->get_json_params();
    $body       = is_array( $body ) ? $body : array();
    $event_type = isset( $body['event'] ) ? sanitize_key( (string) $body['event'] ) : 'task_update';

    wp_claw_log(
        'Webhook received.',
        'info',
        array( 'event' => $event_type )
    );

    switch ( $event_type ) {
        case 'task_chain':
            return $this->handle_chain_webhook( $body );

        case 'task_update':
        default:
            return $this->handle_task_update_webhook( $body );
    }
}
```

- [ ] **Step 3: Extract existing webhook logic into `handle_task_update_webhook()`**

```php
/**
 * Handle a task status update webhook from Klawty.
 *
 * @since 1.4.0
 * @param array $body Webhook payload.
 * @return \WP_REST_Response
 */
private function handle_task_update_webhook( array $body ): \WP_REST_Response {
    global $wpdb;

    $task_id = isset( $body['task_id'] ) ? sanitize_text_field( (string) $body['task_id'] ) : '';
    $status  = isset( $body['status'] ) ? sanitize_key( (string) $body['status'] ) : '';

    $allowed_statuses = array( 'pending', 'in_progress', 'review', 'done', 'failed', 'cancelled' );

    if ( ! empty( $task_id ) && ! empty( $status ) && in_array( $status, $allowed_statuses, true ) ) {
        $now = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->prefix . 'wp_claw_tasks',
            array(
                'status'     => $status,
                'updated_at' => $now,
            ),
            array( 'task_id' => $task_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );

        // Also update any chain step linked to this task.
        if ( in_array( $status, array( 'done', 'failed' ), true ) ) {
            $summary = isset( $body['summary'] ) ? sanitize_textarea_field( (string) $body['summary'] ) : null;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $wpdb->prefix . 'wp_claw_task_chains',
                array(
                    'status'         => $status,
                    'result_summary' => $summary,
                    'completed_at'   => $now,
                ),
                array( 'klawty_task_id' => $task_id ),
                array( '%s', '%s', '%s' ),
                array( '%s' )
            );
        }
    }

    return rest_ensure_response( array( 'received' => true ) );
}
```

- [ ] **Step 4: Add `handle_chain_webhook()` for new chain events**

```php
/**
 * Handle a task_chain webhook — insert chain steps.
 *
 * @since 1.4.0
 * @param array $body Webhook payload.
 * @return \WP_REST_Response|\WP_Error
 */
private function handle_chain_webhook( array $body ) {
    global $wpdb;

    $chain_id       = isset( $body['chain_id'] ) ? sanitize_text_field( (string) $body['chain_id'] ) : '';
    $parent_task_id = isset( $body['parent_task_id'] ) ? sanitize_text_field( (string) $body['parent_task_id'] ) : null;
    $next_steps     = isset( $body['next_steps'] ) && is_array( $body['next_steps'] ) ? $body['next_steps'] : array();

    if ( empty( $chain_id ) || empty( $next_steps ) ) {
        return new \WP_Error(
            'wp_claw_invalid_chain',
            __( 'chain_id and next_steps are required.', 'claw-agent' ),
            array( 'status' => 400 )
        );
    }

    // Max 10 steps per chain.
    if ( count( $next_steps ) > 10 ) {
        $next_steps = array_slice( $next_steps, 0, 10 );
    }

    // Check active chain limit per agent.
    $first_agent = isset( $next_steps[0]['agent'] ) ? sanitize_key( $next_steps[0]['agent'] ) : '';
    if ( ! empty( $first_agent ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $active_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT( DISTINCT chain_id ) FROM {$wpdb->prefix}wp_claw_task_chains
                 WHERE agent = %s AND status IN ('queued', 'dispatched', 'working')",
                $first_agent
            )
        );
        if ( $active_count >= 3 ) {
            return new \WP_Error(
                'wp_claw_chain_limit',
                __( 'Maximum 3 active chains per agent.', 'claw-agent' ),
                array( 'status' => 429 )
            );
        }
    }

    $step_order = 1;
    foreach ( $next_steps as $step ) {
        $agent  = isset( $step['agent'] ) ? sanitize_key( (string) $step['agent'] ) : '';
        $title  = isset( $step['title'] ) ? sanitize_text_field( (string) $step['title'] ) : '';
        $prompt = isset( $step['prompt'] ) ? sanitize_textarea_field( (string) $step['prompt'] ) : '';

        if ( empty( $agent ) || empty( $title ) || empty( $prompt ) ) {
            continue;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $wpdb->prefix . 'wp_claw_task_chains',
            array(
                'chain_id'       => $chain_id,
                'parent_task_id' => $parent_task_id,
                'agent'          => $agent,
                'title'          => $title,
                'prompt'         => $prompt,
                'step_order'     => $step_order,
                'status'         => 'queued',
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );
        ++$step_order;
    }

    wp_claw_log( 'Task chain created.', 'info', array( 'chain_id' => $chain_id, 'steps' => $step_order - 1 ) );

    return rest_ensure_response( array( 'received' => true, 'chain_id' => $chain_id, 'steps_created' => $step_order - 1 ) );
}
```

- [ ] **Step 5: Register 4 chain REST routes in `register_routes()`**

Add after the existing route registrations (before the closing `}` of `register_routes()`):

```php
// ----- Task chain routes (v1.4.0) -----

register_rest_route(
    self::NAMESPACE,
    '/chains',
    array(
        'methods'             => \WP_REST_Server::READABLE,
        'callback'            => array( $this, 'handle_chains_list' ),
        'permission_callback' => static function () {
            return current_user_can( 'manage_options' );
        },
        'args'                => array(
            'status' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_key',
            ),
        ),
    )
);

register_rest_route(
    self::NAMESPACE,
    '/chains/(?P<id>[\d]+)/pause',
    array(
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'handle_chain_pause' ),
        'permission_callback' => static function () {
            return current_user_can( 'manage_options' );
        },
        'args'                => array(
            'id' => array(
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    )
);

register_rest_route(
    self::NAMESPACE,
    '/chains/(?P<id>[\d]+)/resume',
    array(
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'handle_chain_resume' ),
        'permission_callback' => static function () {
            return current_user_can( 'manage_options' );
        },
        'args'                => array(
            'id' => array(
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    )
);

register_rest_route(
    self::NAMESPACE,
    '/chains/(?P<id>[\d]+)/cancel',
    array(
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'handle_chain_cancel' ),
        'permission_callback' => static function () {
            return current_user_can( 'manage_options' );
        },
        'args'                => array(
            'id' => array(
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    )
);
```

- [ ] **Step 6: Implement chain list/pause/resume/cancel handlers**

Add these methods to the REST_API class:

```php
/**
 * List active task chains.
 *
 * @since 1.4.0
 * @return \WP_REST_Response
 */
public function handle_chains_list( \WP_REST_Request $request ) {
    global $wpdb;

    $status_filter = $request->get_param( 'status' );

    $where = "WHERE status NOT IN ('done', 'failed', 'cancelled')";
    if ( ! empty( $status_filter ) ) {
        $where = $wpdb->prepare( 'WHERE status = %s', $status_filter );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $chains = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}wp_claw_task_chains {$where} ORDER BY chain_id, step_order ASC LIMIT 200",
        ARRAY_A
    );

    return rest_ensure_response( is_array( $chains ) ? $chains : array() );
}

/**
 * Pause all queued steps in a chain.
 *
 * @since 1.4.0
 * @return \WP_REST_Response
 */
public function handle_chain_pause( \WP_REST_Request $request ) {
    global $wpdb;

    // Get chain_id from any row with this id.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT chain_id FROM {$wpdb->prefix}wp_claw_task_chains WHERE id = %d",
            $request->get_param( 'id' )
        )
    );

    if ( ! $row ) {
        return new \WP_Error( 'not_found', __( 'Chain step not found.', 'claw-agent' ), array( 'status' => 404 ) );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}wp_claw_task_chains SET status = 'paused' WHERE chain_id = %s AND status = 'queued'",
            $row->chain_id
        )
    );

    return rest_ensure_response( array( 'paused' => (int) $updated, 'chain_id' => $row->chain_id ) );
}

/**
 * Resume paused steps in a chain.
 *
 * @since 1.4.0
 * @return \WP_REST_Response
 */
public function handle_chain_resume( \WP_REST_Request $request ) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT chain_id FROM {$wpdb->prefix}wp_claw_task_chains WHERE id = %d",
            $request->get_param( 'id' )
        )
    );

    if ( ! $row ) {
        return new \WP_Error( 'not_found', __( 'Chain step not found.', 'claw-agent' ), array( 'status' => 404 ) );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}wp_claw_task_chains SET status = 'queued' WHERE chain_id = %s AND status = 'paused'",
            $row->chain_id
        )
    );

    return rest_ensure_response( array( 'resumed' => (int) $updated, 'chain_id' => $row->chain_id ) );
}

/**
 * Cancel all non-completed steps in a chain.
 *
 * @since 1.4.0
 * @return \WP_REST_Response
 */
public function handle_chain_cancel( \WP_REST_Request $request ) {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT chain_id FROM {$wpdb->prefix}wp_claw_task_chains WHERE id = %d",
            $request->get_param( 'id' )
        )
    );

    if ( ! $row ) {
        return new \WP_Error( 'not_found', __( 'Chain step not found.', 'claw-agent' ), array( 'status' => 404 ) );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}wp_claw_task_chains SET status = 'cancelled'
             WHERE chain_id = %s AND status IN ('queued', 'paused', 'dispatched')",
            $row->chain_id
        )
    );

    return rest_ensure_response( array( 'cancelled' => (int) $updated, 'chain_id' => $row->chain_id ) );
}
```

- [ ] **Step 7: Add chain dispatch logic to `class-cron.php`**

Add a new method `run_chain_dispatch()` and call it from `run_sync_state()`:

```php
/**
 * Dispatch the next queued step for each active chain.
 *
 * Called from run_sync_state() on every hourly sync cycle.
 * Respects constitutional constraints (health-fail halt, T3 daily counter).
 *
 * @since 1.4.0
 * @return void
 */
private function run_chain_dispatch(): void {
    global $wpdb;

    // Respect health-fail halt.
    if ( get_option( 'wp_claw_operations_halted' ) ) {
        return;
    }

    // Find chains with a completed step whose next step is queued.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $next_steps = $wpdb->get_results(
        "SELECT ns.*
         FROM {$wpdb->prefix}wp_claw_task_chains ns
         INNER JOIN {$wpdb->prefix}wp_claw_task_chains prev
             ON prev.chain_id = ns.chain_id
             AND prev.step_order = ns.step_order - 1
             AND prev.status = 'done'
         WHERE ns.status = 'queued'
         ORDER BY ns.created_at ASC
         LIMIT 5",
        ARRAY_A
    );

    // Also dispatch first steps (step_order = 1) that are still queued.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $first_steps = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}wp_claw_task_chains
         WHERE step_order = 1 AND status = 'queued'
         ORDER BY created_at ASC
         LIMIT 5",
        ARRAY_A
    );

    $to_dispatch = array_merge(
        is_array( $first_steps ) ? $first_steps : array(),
        is_array( $next_steps ) ? $next_steps : array()
    );

    foreach ( $to_dispatch as $step ) {
        $result = $this->api_client->create_task(
            sanitize_key( $step['agent'] ),
            sanitize_text_field( $step['title'] ),
            sanitize_textarea_field( $step['prompt'] )
        );

        if ( is_wp_error( $result ) ) {
            wp_claw_log_warning( 'Chain dispatch failed.', array( 'chain_id' => $step['chain_id'], 'step' => $step['step_order'] ) );
            continue;
        }

        $klawty_task_id = isset( $result['task_id'] ) ? sanitize_text_field( $result['task_id'] ) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $wpdb->prefix . 'wp_claw_task_chains',
            array(
                'status'          => 'dispatched',
                'klawty_task_id'  => $klawty_task_id,
                'dispatched_at'   => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $step['id'] ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
    }
}
```

Then add to `run_sync_state()`, after the existing state sync logic:

```php
// Dispatch pending chain steps.
$this->run_chain_dispatch();
```

- [ ] **Step 8: Add arg schemas to `/command` route (line 192)**

Add `args` to the `/command` route registration at line 192:

```php
'args' => array(
    'command' => array(
        'required'          => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ),
    'pin' => array(
        'required'          => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ),
),
```

- [ ] **Step 9: Fix `uninstall.php` — add missing cron hooks + new table**

Add to the `$cron_hooks` array in `uninstall.php`:
```php
'wp_claw_daily_digest',
'wp_claw_weekly_report',
```

Add to the table drop section:
```php
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wp_claw_task_chains' ) );
```

- [ ] **Step 10: Add Active Chains card to dashboard + chain breadcrumb to proposals**

Dashboard card (add to `admin/views/dashboard.php`):

```php
<?php
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$active_chains = $wpdb->get_results(
    "SELECT chain_id, agent, title, step_order, status
     FROM {$wpdb->prefix}wp_claw_task_chains
     WHERE status IN ('queued', 'dispatched', 'working', 'paused')
     ORDER BY chain_id, step_order ASC
     LIMIT 50",
    ARRAY_A
);

if ( ! empty( $active_chains ) ) :
    // Group by chain_id.
    $grouped = array();
    foreach ( $active_chains as $step ) {
        $grouped[ $step['chain_id'] ][] = $step;
    }
?>
<div class="wp-claw-card wp-claw-chains-card">
    <h3><?php esc_html_e( 'Active Chains', 'claw-agent' ); ?></h3>
    <?php foreach ( $grouped as $cid => $steps ) :
        $total     = count( $steps );
        $done      = count( array_filter( $steps, static function ( $s ) { return 'done' === $s['status']; } ) );
        $agent     = $steps[0]['agent'];
        $title     = $steps[0]['title'];
        $first_id  = $steps[0]['step_order'];
    ?>
    <div class="wp-claw-chain-item" data-chain-id="<?php echo esc_attr( $cid ); ?>">
        <div class="wp-claw-chain-header">
            <strong><?php echo esc_html( ucfirst( $agent ) ); ?></strong>
            <span><?php echo esc_html( $title ); ?></span>
            <span class="wp-claw-chain-progress"><?php echo esc_html( $done . '/' . $total ); ?></span>
        </div>
        <div class="wp-claw-chain-bar">
            <div class="wp-claw-chain-bar-fill" style="width: <?php echo esc_attr( $total > 0 ? round( ( $done / $total ) * 100 ) : 0 ); ?>%"></div>
        </div>
        <div class="wp-claw-chain-actions">
            <button class="button button-small" data-chain-action="pause" data-chain-step-id="<?php echo esc_attr( $steps[0]['id'] ?? '' ); ?>"><?php esc_html_e( 'Pause', 'claw-agent' ); ?></button>
            <button class="button button-small button-link-delete" data-chain-action="cancel" data-chain-step-id="<?php echo esc_attr( $steps[0]['id'] ?? '' ); ?>"><?php esc_html_e( 'Cancel', 'claw-agent' ); ?></button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 11: Add JS handlers for chain pause/resume/cancel to `wp-claw-admin.js`**

```javascript
// Chain actions (v1.4.0).
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-chain-action]');
    if (!btn) return;

    var action  = btn.dataset.chainAction;
    var stepId  = btn.dataset.chainStepId;
    if (!action || !stepId) return;

    btn.disabled = true;
    btn.textContent = action === 'pause' ? 'Pausing...' : action === 'resume' ? 'Resuming...' : 'Cancelling...';

    wp.apiRequest({
        path: wpClaw.restNs + '/chains/' + stepId + '/' + action,
        method: 'POST',
    }).done(function() {
        location.reload();
    }).fail(function() {
        btn.disabled = false;
        btn.textContent = action.charAt(0).toUpperCase() + action.slice(1);
    });
});
```

- [ ] **Step 12: Commit Slice 3**

```bash
git add includes/class-activator.php includes/class-rest-api.php includes/class-cron.php admin/views/dashboard.php admin/views/proposals.php admin/js/wp-claw-admin.js uninstall.php
git commit -m "feat(v1.4.0): slice 3 — task chaining with dashboard + pause/cancel

New wp_claw_task_chains table. Webhook event routing for task_chain events.
4 chain REST routes (list/pause/resume/cancel). Chain dispatch in sync cron.
Active Chains dashboard card. Fix missing cron hooks in uninstall.php."
```

---

## Task 4: Slice 4 — Module Discovery REST Endpoint

**Files:**
- Modify: `includes/class-rest-api.php` (new route)
- Modify: `includes/class-wp-claw.php` (future abilities stub)

- [ ] **Step 1: Register `/abilities` route in `register_routes()`**

```php
// ----- Module discovery (v1.4.0) -----

register_rest_route(
    self::NAMESPACE,
    '/abilities',
    array(
        'methods'             => 'GET',
        'callback'            => array( $this, 'handle_abilities' ),
        'permission_callback' => '__return_true',
        'args'                => array(),
    )
);
```

- [ ] **Step 2: Implement `handle_abilities()` handler**

```php
/**
 * Return WP-Claw module capabilities in a discoverable format.
 *
 * @since 1.4.0
 * @return \WP_REST_Response
 */
public function handle_abilities(): \WP_REST_Response {
    $modules = array(
        'seo'         => array( 'label' => 'WP-Claw SEO', 'description' => 'AI-powered SEO optimization, meta management, schema markup', 'agent' => 'scribe' ),
        'security'    => array( 'label' => 'WP-Claw Security', 'description' => 'File integrity monitoring, malware scanning, IP blocking, SSL checks', 'agent' => 'sentinel' ),
        'content'     => array( 'label' => 'WP-Claw Content', 'description' => 'Content drafting, translation, freshness scanning, thin content expansion', 'agent' => 'scribe' ),
        'crm'         => array( 'label' => 'WP-Claw CRM', 'description' => 'Lead capture, scoring, pipeline management, email draft approval', 'agent' => 'commerce' ),
        'commerce'    => array( 'label' => 'WP-Claw Commerce', 'description' => 'WooCommerce: abandoned carts, fraud detection, customer segments', 'agent' => 'commerce' ),
        'performance' => array( 'label' => 'WP-Claw Performance', 'description' => 'Core Web Vitals, database cleanup, autoload analysis, caching audit', 'agent' => 'analyst' ),
        'analytics'   => array( 'label' => 'WP-Claw Analytics', 'description' => 'Privacy-first tracking, anomaly detection, funnel analysis', 'agent' => 'analyst' ),
        'backup'      => array( 'label' => 'WP-Claw Backup', 'description' => 'Database and file backups, targeted snapshots, rollback', 'agent' => 'sentinel' ),
        'social'      => array( 'label' => 'WP-Claw Social', 'description' => 'Social media formatting, scheduling, posting history', 'agent' => 'scribe' ),
        'chat'        => array( 'label' => 'WP-Claw Chat', 'description' => 'Live chat widget, product recommendations, lead capture, FAQ', 'agent' => 'concierge' ),
        'forms'       => array( 'label' => 'WP-Claw Forms', 'description' => 'Form creation and submission tracking', 'agent' => 'architect' ),
        'audit'       => array( 'label' => 'WP-Claw Audit', 'description' => 'Site audit, plugin versions, SSL check, disk and database stats', 'agent' => 'architect' ),
    );

    $enabled = (array) get_option( 'wp_claw_enabled_modules', array() );

    $abilities = array();
    foreach ( $modules as $slug => $meta ) {
        $abilities[] = array(
            'id'          => 'wp-claw-' . $slug,
            'label'       => $meta['label'],
            'description' => $meta['description'],
            'enabled'     => in_array( $slug, $enabled, true ),
            'agent'       => $meta['agent'],
        );
    }

    return rest_ensure_response( array(
        'plugin'    => 'claw-agent',
        'version'   => defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : 'unknown',
        'category'  => 'wp-claw',
        'abilities' => $abilities,
    ) );
}
```

- [ ] **Step 3: Add future-ready abilities stub in `class-wp-claw.php`**

Add method to `WP_Claw` class:

```php
/**
 * Register WP-Claw abilities via WordPress Abilities API when available.
 *
 * No-op until WordPress ships wp_register_ability().
 *
 * @since 1.4.0
 * @return void
 */
public function register_native_abilities(): void {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }
    // Future: call wp_register_ability() for each module.
}
```

Hook it in `__construct()` or `init()`:

```php
add_action( 'init', array( $this, 'register_native_abilities' ) );
```

- [ ] **Step 4: Commit Slice 4**

```bash
git add includes/class-rest-api.php includes/class-wp-claw.php
git commit -m "feat(v1.4.0): slice 4 — module discovery endpoint /abilities

GET /wp-claw/v1/abilities returns all 12 modules with enabled status and agent mapping.
Future-ready stub for native WP Abilities API when shipped."
```

---

## Task 5: Slice 5 — Application Passwords Auth + Route Hardening (6 routes)

**Files:**
- Modify: `includes/class-rest-api.php:494-559` (refactor `verify_signature()`)
- Modify: `includes/class-rest-api.php` (arg schemas on 6 routes)
- Modify: `admin/views/settings.php` (connection mode selector)
- Modify: `admin/js/wp-claw-admin.js` (app password generation)

- [ ] **Step 1: Refactor `verify_signature()` into dispatcher + HMAC-only method**

Rename the existing method at line 494 from `verify_signature()` to `verify_hmac_signature()`. **Important:** In the renamed method, remove the early `empty($signature) || empty($timestamp)` guard (lines 499-509) — this check is now handled by the new dispatcher which only calls `verify_hmac_signature()` when a signature header IS present. The renamed method should start from the timestamp format check (line 512).

Then add a NEW `verify_signature()` dispatcher before the renamed method:

```php
/**
 * Authenticate inbound agent requests — HMAC or Application Password.
 *
 * @since 1.4.0
 * @param \WP_REST_Request $request The incoming REST request.
 * @return true|\WP_Error
 */
public function verify_signature( \WP_REST_Request $request ) {
    // Path 1: HMAC header present → managed mode.
    $signature = $request->get_header( 'x-wpclaw-signature' );
    if ( ! empty( $signature ) ) {
        return $this->verify_hmac_signature( $request );
    }

    // Path 2: Application Password (Basic Auth) → self-hosted mode.
    if ( ! is_ssl() ) {
        return new \WP_Error(
            'wp_claw_ssl_required',
            __( 'Application Passwords require HTTPS.', 'claw-agent' ),
            array( 'status' => 403 )
        );
    }

    $user = wp_get_current_user();
    if ( $user->ID > 0 && current_user_can( 'manage_options' ) ) {
        // Log auth mode for audit trail.
        wp_claw_log_debug( 'Authenticated via Application Password.', array( 'user' => $user->user_login ) );
        return true;
    }

    return new \WP_Error(
        'wp_claw_unauthorized',
        __( 'Invalid authentication. Provide HMAC signature or Application Password.', 'claw-agent' ),
        array( 'status' => 401 )
    );
}
```

- [ ] **Step 2: Add arg schemas to 6 remaining public/admin routes**

Add `args` to these route registrations:

`/chat/send` (line 148):
```php
'args' => array(
    'session_id' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
    'message'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ),
    'page_url'   => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
),
```

`/chat/history` (line 158):
```php
'args' => array(
    'session_id' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
),
```

`/analytics` (line 168):
```php
'args' => array(
    'event' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
    'url'   => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ),
),
```

`/inline-edit` (line 451):
```php
'args' => array(
    'type'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
    'id'    => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
    'value' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
),
```

`/agent-action` (line 463):
```php
'args' => array(
    'action' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
    'agent'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
    'params' => array( 'type' => 'object', 'default' => array() ),
),
```

`/settings/modules` (line 378):
```php
'args' => array(
    'modules' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
),
```

- [ ] **Step 3: Add connection mode selector to `admin/views/settings.php`**

In the Connection section, add after the existing API key fields:

```php
<tr>
    <th scope="row"><?php esc_html_e( 'Connection Mode', 'claw-agent' ); ?></th>
    <td>
        <select id="wp-claw-connection-mode" name="wp_claw_connection_mode">
            <option value="managed" <?php selected( get_option( 'wp_claw_connection_mode', 'managed' ), 'managed' ); ?>>
                <?php esc_html_e( 'Managed (ai-agent-builder.ai)', 'claw-agent' ); ?>
            </option>
            <option value="self-hosted" <?php selected( get_option( 'wp_claw_connection_mode', 'managed' ), 'self-hosted' ); ?>>
                <?php esc_html_e( 'Self-Hosted (Application Password)', 'claw-agent' ); ?>
            </option>
        </select>
        <div id="wp-claw-app-password-section" style="display:none; margin-top: 12px;">
            <button type="button" class="button" id="wp-claw-generate-app-password">
                <?php esc_html_e( 'Generate Application Password', 'claw-agent' ); ?>
            </button>
            <p class="description">
                <?php esc_html_e( 'Creates a WordPress Application Password for your Klawty instance. The password is shown once — save it in your Klawty configuration.', 'claw-agent' ); ?>
            </p>
            <div id="wp-claw-app-password-result" style="display:none;">
                <code id="wp-claw-app-password-value"></code>
                <p class="description"><?php esc_html_e( 'Copy this password now. It will not be shown again.', 'claw-agent' ); ?></p>
            </div>
        </div>
    </td>
</tr>
```

- [ ] **Step 4: Add JS for connection mode toggle + app password generation**

```javascript
// Connection mode toggle (v1.4.0).
var modeSelect = document.getElementById('wp-claw-connection-mode');
var appSection = document.getElementById('wp-claw-app-password-section');
if (modeSelect && appSection) {
    function toggleAppSection() {
        appSection.style.display = modeSelect.value === 'self-hosted' ? 'block' : 'none';
    }
    modeSelect.addEventListener('change', toggleAppSection);
    toggleAppSection();
}

// Generate Application Password.
var genBtn = document.getElementById('wp-claw-generate-app-password');
if (genBtn) {
    genBtn.addEventListener('click', function() {
        genBtn.disabled = true;
        genBtn.textContent = wpClaw.i18n.generating || 'Generating...';

        wp.apiRequest({
            path: '/wp/v2/users/me/application-passwords',
            method: 'POST',
            data: { name: 'WP-Claw Klawty Instance' },
        }).done(function(res) {
            var resultDiv = document.getElementById('wp-claw-app-password-result');
            var valueEl   = document.getElementById('wp-claw-app-password-value');
            if (resultDiv && valueEl && res.password) {
                valueEl.textContent = res.password;
                resultDiv.style.display = 'block';
            }
            // Store the UUID for future revocation.
            if (res.uuid) {
                wp.apiRequest({
                    path: wpClaw.restNs + '/settings/modules',
                    method: 'POST',
                    data: { app_password_uuid: res.uuid },
                });
            }
        }).fail(function() {
            genBtn.disabled = false;
            genBtn.textContent = wpClaw.i18n.generate || 'Generate Application Password';
        });
    });
}
```

- [ ] **Step 5: Commit Slice 5**

```bash
git add includes/class-rest-api.php admin/views/settings.php admin/js/wp-claw-admin.js
git commit -m "feat(v1.4.0): slice 5 — Application Passwords auth + arg schemas on 6 routes

Dual auth: HMAC for managed, Application Passwords for self-hosted.
Connection mode selector in settings with app password generation.
Arg schemas added to /chat/send, /chat/history, /analytics,
/inline-edit, /agent-action, /settings/modules."
```

---

## Task 6: Slice 6 — WP Playground Blueprint + Demo Mode

**Files:**
- Create: `playground/blueprint.json`
- Create: `playground/demo-content.xml`
- Create: `includes/class-demo-provider.php`
- Modify: `includes/class-rest-api.php` (demo mode guards)
- Modify: `admin/views/dashboard.php` (demo banner)

- [ ] **Step 1: Create `playground/blueprint.json`**

```json
{
    "$schema": "https://playground.wordpress.net/blueprint-schema.json",
    "preferredVersions": {
        "php": "8.2",
        "wp": "6.9"
    },
    "features": {
        "networking": true
    },
    "plugins": [
        "woocommerce"
    ],
    "steps": [
        {
            "step": "installPlugin",
            "pluginData": {
                "resource": "url",
                "url": "https://wp-claw.ai/releases/claw-agent-latest.zip"
            }
        },
        {
            "step": "setSiteOptions",
            "options": {
                "blogname": "WP-Claw Demo Store",
                "permalink_structure": "/%postname%/",
                "wp_claw_demo_mode": "1",
                "wp_claw_enabled_modules": "a:8:{i:0;s:3:\"seo\";i:1;s:8:\"security\";i:2;s:7:\"content\";i:3;s:8:\"commerce\";i:4;s:11:\"performance\";i:5;s:9:\"analytics\";i:6;s:4:\"chat\";i:7;s:5:\"audit\";}"
            }
        },
        {
            "step": "login",
            "username": "admin",
            "password": "password"
        }
    ]
}
```

- [ ] **Step 2: Create `includes/class-demo-provider.php`**

```php
<?php
/**
 * Demo data provider for WP Playground demo mode.
 *
 * Returns mock data when wp_claw_demo_mode option is set,
 * allowing the full admin UI to work without a Klawty connection.
 *
 * @package    WPClaw
 * @subpackage WPClaw/includes
 * @since      1.4.0
 */

namespace WPClaw;

defined( 'ABSPATH' ) || exit;

/**
 * Static mock data provider for demo mode.
 *
 * @since 1.4.0
 */
class Demo_Provider {

    /**
     * Check if demo mode is active.
     *
     * @since 1.4.0
     * @return bool
     */
    public static function is_active(): bool {
        return (bool) get_option( 'wp_claw_demo_mode', false );
    }

    /**
     * Mock agent statuses.
     *
     * @since 1.4.0
     * @return array
     */
    public static function agents(): array {
        return array(
            array( 'name' => 'Karim', 'role' => 'architect', 'status' => 'idle', 'tasks_completed' => 47, 'uptime' => '99.2%' ),
            array( 'name' => 'Lina', 'role' => 'scribe', 'status' => 'working', 'current_task' => 'Auditing meta descriptions', 'tasks_completed' => 112, 'uptime' => '98.8%' ),
            array( 'name' => 'Bastien', 'role' => 'sentinel', 'status' => 'idle', 'tasks_completed' => 89, 'uptime' => '99.9%' ),
            array( 'name' => 'Hugo', 'role' => 'commerce', 'status' => 'working', 'current_task' => 'Recovering abandoned cart #1042', 'tasks_completed' => 63, 'uptime' => '99.1%' ),
            array( 'name' => 'Selma', 'role' => 'analyst', 'status' => 'idle', 'tasks_completed' => 34, 'uptime' => '99.5%' ),
            array( 'name' => 'Marc', 'role' => 'concierge', 'status' => 'working', 'current_task' => 'Assisting visitor on /shop', 'tasks_completed' => 201, 'uptime' => '99.7%' ),
        );
    }

    /**
     * Mock proposals.
     *
     * @since 1.4.0
     * @return array
     */
    public static function proposals(): array {
        return array(
            array( 'id' => 'demo-1', 'agent' => 'scribe', 'title' => 'Add schema markup to 15 product pages', 'action' => 'seo', 'status' => 'pending', 'created_at' => gmdate( 'c', strtotime( '-2 hours' ) ) ),
            array( 'id' => 'demo-2', 'agent' => 'sentinel', 'title' => 'Block 3 IPs with repeated failed logins', 'action' => 'security', 'status' => 'pending', 'created_at' => gmdate( 'c', strtotime( '-45 minutes' ) ) ),
            array( 'id' => 'demo-3', 'agent' => 'scribe', 'title' => 'Refresh stale content on 8 blog posts', 'action' => 'content', 'status' => 'pending', 'created_at' => gmdate( 'c', strtotime( '-20 minutes' ) ) ),
        );
    }

    /**
     * Mock health check.
     *
     * @since 1.4.0
     * @return array
     */
    public static function health(): array {
        return array( 'status' => 'healthy', 'agents_active' => 6, 'uptime' => '99.4%', 'last_check' => gmdate( 'c' ) );
    }

    /**
     * Mock task chain.
     *
     * @since 1.4.0
     * @return array
     */
    public static function chains(): array {
        return array(
            array( 'id' => 1, 'chain_id' => 'demo-chain-1', 'agent' => 'scribe', 'title' => 'Full SEO audit', 'step_order' => 1, 'status' => 'done', 'result_summary' => 'Found 15 pages missing meta descriptions.' ),
            array( 'id' => 2, 'chain_id' => 'demo-chain-1', 'agent' => 'scribe', 'title' => 'Fix missing meta', 'step_order' => 2, 'status' => 'working' ),
            array( 'id' => 3, 'chain_id' => 'demo-chain-1', 'agent' => 'scribe', 'title' => 'Verify SEO scores', 'step_order' => 3, 'status' => 'queued' ),
        );
    }

    /**
     * Mock performance report.
     *
     * @since 1.4.0
     * @return array
     */
    public static function performance_report(): array {
        return array(
            'generated_at'    => gmdate( 'c' ),
            'score'           => 78,
            'checks'          => array(
                array( 'id' => 'autoload_bloat', 'status' => 'pass', 'value' => '420 KB', 'threshold' => '800 KB' ),
                array( 'id' => 'object_cache', 'status' => 'pass', 'provider' => 'redis', 'connected' => true ),
                array( 'id' => 'page_cache', 'status' => 'fail', 'detail' => 'No page caching detected' ),
                array( 'id' => 'cron_health', 'status' => 'pass', 'overdue' => 0, 'total' => 18 ),
                array( 'id' => 'database_bloat', 'status' => 'warning', 'orphaned_meta' => 320, 'expired_transients' => 45, 'revisions' => 890 ),
                array( 'id' => 'self_audit', 'status' => 'pass', 'detail' => 'All wp_claw_* options under 10KB' ),
            ),
            'recommendations' => array(
                'Install a page caching plugin or enable server-level caching.',
                'Run database cleanup: 320 orphaned meta, 45 expired transients.',
            ),
        );
    }
}
```

- [ ] **Step 3: Add demo mode guards to key REST handlers**

In `handle_health()`, `handle_agents()`, `handle_chains_list()`, and `handle_abilities()`, add at the top of each method:

```php
if ( Demo_Provider::is_active() ) {
    return rest_ensure_response( Demo_Provider::health() ); // or ::agents(), ::chains(), etc.
}
```

Also guard `handle_proxy_reports()` and `handle_proxy_activity()` with demo mock data.

- [ ] **Step 4: Add demo banner to dashboard**

At the top of `admin/views/dashboard.php`:

```php
<?php if ( get_option( 'wp_claw_demo_mode' ) ) : ?>
<div class="notice notice-info wp-claw-demo-banner">
    <p>
        <strong><?php esc_html_e( 'Demo Mode', 'claw-agent' ); ?></strong> —
        <?php esc_html_e( 'This is a demo environment with sample data. No real agents are connected.', 'claw-agent' ); ?>
        <a href="https://wp-claw.ai/pricing" target="_blank" rel="noopener"><?php esc_html_e( 'Connect to a real instance', 'claw-agent' ); ?> &rarr;</a>
    </p>
</div>
<?php endif; ?>
```

- [ ] **Step 5: Load `Demo_Provider` class in `class-wp-claw.php`**

The plugin uses explicit `require_once` (not autoloading). Add to `class-wp-claw.php` in the includes section:

```php
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-demo-provider.php';
```

- [ ] **Step 6: Create placeholder `playground/demo-content.xml`**

Create a minimal WXR skeleton. Full content will be populated with sample WooCommerce products and blog posts. The WXR file should contain:
- 5 products (3 simple, 1 variable, 1 digital)
- 10 blog posts (5 with complete SEO meta, 5 with missing meta)
- 2 pages (About, Contact)

- [ ] **Step 7: Commit Slice 6**

```bash
git add includes/class-demo-provider.php includes/class-wp-claw.php playground/blueprint.json playground/demo-content.xml includes/class-rest-api.php admin/views/dashboard.php
git commit -m "feat(v1.4.0): slice 6 — WP Playground Blueprint + demo mode

Blueprint for instant demo at playground.wordpress.net.
Demo_Provider class returns mock data for all admin endpoints.
Demo banner in dashboard links to wp-claw.ai pricing."
```

---

## Task 7: Slice 7 — Final Sweep (remaining routes + cron audit + version bump)

**Files:**
- Modify: `includes/class-rest-api.php` (arg schemas on 5 remaining routes)
- Modify: `includes/class-cron.php` (batch limits)
- Modify: `includes/modules/class-module-security.php` (batch file scanning)
- Modify: `uninstall.php` (final verification)
- Modify: `wp-claw.php` (version bump)
- Modify: `readme.txt` (version bump)
- Modify: `CHANGELOG.md` (full v1.4.0 entry)

- [ ] **Step 1: Add arg schemas to remaining 5 routes**

`/command/setup-pin` (line 180): add `'args' => array( 'pin' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) )`

`/activity` (line 404): add `'args' => array( 'page' => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ), 'per_page' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ), 'agent' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ), 'id' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) )`

`/agents` (line 366): add `'args' => array( 'agent' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) )`

`/health` (line 354): add `'args' => array()`

`/reports` (line 392): add `'args' => array( 'type' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) )`

Also add explicit `'args' => array()` to these routes that take no parameters:
- `/admin/module-states` (line 258)
- `/admin/resume-operations` (line 328)
- `/admin/reset-circuit-breaker` (line 340)
- `/command/history` (line 204)
- `/create-task` (line 437)
- `/profile` GET (line 416)

- [ ] **Step 2: Add batch limits to `run_file_integrity()` in `class-cron.php`**

Add a bookmark-based batch pattern. At the start of the file integrity handler:

```php
$batch_size = 500;
$bookmark   = (int) get_transient( 'wp_claw_integrity_bookmark' );
```

After scanning `$batch_size` files, save the bookmark:

```php
set_transient( 'wp_claw_integrity_bookmark', $bookmark + $batch_size, HOUR_IN_SECONDS );
```

When all files are scanned, delete the bookmark:

```php
delete_transient( 'wp_claw_integrity_bookmark' );
```

Apply the same pattern to `run_malware_scan()`.

- [ ] **Step 3: Add LIMIT guard to segmentation in `class-module-security.php` or `class-cron.php`**

In the `run_segmentation()` handler, add `LIMIT 1000` to the WooCommerce customer query to prevent unbounded queries on large stores.

- [ ] **Step 4: Final uninstall.php verification**

Verify the file now drops 11 tables and clears 20 cron hooks. No code change expected if Slice 3 was done correctly — just verify by reading the file.

- [ ] **Step 5: Bump version to 1.4.0**

In `wp-claw.php`, update the version constant:
```php
define( 'WP_CLAW_VERSION', '1.4.0' );
```

In `readme.txt`, update:
```
Stable tag: 1.4.0
```

- [ ] **Step 6: Write CHANGELOG.md entry for v1.4.0**

```markdown
## [1.4.0] — 2026-04-XX — Agent Skills Upgrade

### Added
- **Structured Site Triage**: `/state` sync now includes signals, tooling, health, and recommendations sections
- **Performance Diagnostic Pipeline**: 7 diagnostic checks (autoload bloat, object cache, page cache, cron health, database bloat, self-audit) with scored report and dashboard card
- **Task Chaining**: Agents can self-schedule multi-step workflows. New `wp_claw_task_chains` table, webhook event routing, 4 REST endpoints (list/pause/resume/cancel), dashboard Active Chains card
- **Module Discovery**: `GET /wp-claw/v1/abilities` endpoint returns all 12 modules with status and agent mapping
- **Application Passwords Auth**: Self-hosted users can use WordPress Application Passwords instead of HMAC. Connection mode selector in Settings
- **WP Playground Blueprint**: Instant demo environment via playground.wordpress.net with demo mode and mock data

### Changed
- **REST Hardening**: All 30+ routes now have explicit `args` with `validate_callback` and `sanitize_callback`
- **Cron Batching**: File integrity and malware scans now process 500 files per run with bookmark-based continuation
- **Performance Cron**: Runs local diagnostics before dispatching to Klawty agent

### Fixed
- Missing `wp_claw_daily_digest` and `wp_claw_weekly_report` hooks in `uninstall.php`
- Segmentation cron query unbounded on large WooCommerce stores (added LIMIT 1000)
```

- [ ] **Step 7: Run PHPCS check**

```bash
./vendor/bin/phpcs --standard=WordPress-Extra wp-claw.php includes/ admin/ public/ --ignore=*/node_modules/*
```

Fix any violations.

- [ ] **Step 8: Commit Slice 7**

```bash
git add includes/class-rest-api.php includes/class-cron.php includes/modules/class-module-security.php uninstall.php wp-claw.php readme.txt CHANGELOG.md
git commit -m "feat(v1.4.0): slice 7 — final sweep, all routes hardened, version bump

Arg schemas on all remaining routes. Batch limits for file integrity,
malware scan, and segmentation cron handlers. Version bump to 1.4.0.
Full CHANGELOG entry."
```

---

## Task 8: Build ZIP + Ship to Desktop

- [ ] **Step 1: Build the release ZIP**

```bash
cd /Users/inscape/.openclaw/workspace/repos/agent-builder/wp-claw
zip -r ~/Desktop/claw-agent-1.4.0.zip . \
    -x "*.git*" \
    -x "node_modules/*" \
    -x "vendor/*" \
    -x "tests/*" \
    -x "docs/*" \
    -x "*.md" \
    -x "composer.*" \
    -x "phpcs.xml" \
    -x "phpunit.xml*" \
    -x ".phpcs*" \
    -x "workspace/*" \
    -x "vision.md"
```

- [ ] **Step 2: Verify ZIP contents**

```bash
unzip -l ~/Desktop/claw-agent-1.4.0.zip | head -40
```

Confirm: `wp-claw.php`, `includes/`, `admin/`, `public/`, `playground/`, `readme.txt`, `uninstall.php` are present. No `docs/`, `tests/`, `vendor/`, `.git/`.
