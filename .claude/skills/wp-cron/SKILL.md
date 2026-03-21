---
name: wp-cron
description: WP-Cron scheduling for WP-Claw — registering events, custom intervals, agent trigger cycles, activation/deactivation cleanup
keywords: [wordpress, cron, scheduling, events, intervals, activation, deactivation, cleanup]
---

# WP-Cron for Agent Triggers

## Register Events on Activation

```php
public static function activate(): void {
    $events = [
        'wp_claw_health_check'     => 'hourly',
        'wp_claw_security_scan'    => 'twicedaily',
        'wp_claw_backup'           => 'daily',
        'wp_claw_seo_audit'        => 'daily',
        'wp_claw_analytics_report' => 'weekly',
        'wp_claw_performance_check'=> 'weekly',
        'wp_claw_sync_state'       => 'hourly',
    ];

    foreach ( $events as $hook => $recurrence ) {
        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time(), $recurrence, $hook );
        }
    }
}
```

## Clear Events on Deactivation

```php
public static function deactivate(): void {
    $hooks = [
        'wp_claw_health_check',
        'wp_claw_security_scan',
        'wp_claw_backup',
        'wp_claw_seo_audit',
        'wp_claw_analytics_report',
        'wp_claw_performance_check',
        'wp_claw_sync_state',
    ];

    foreach ( $hooks as $hook ) {
        $timestamp = wp_next_scheduled( $hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $hook );
        }
    }
}
```

## Event Handler Pattern

```php
add_action( 'wp_claw_health_check', [ $this, 'run_health_check' ] );

public function run_health_check(): void {
    $client = wp_claw()->get_api_client();
    $result = $client->get( '/api/health' );

    if ( is_wp_error( $result ) ) {
        wp_claw_log( 'Health check failed', [ 'error' => $result->get_error_message() ] );
        // Update admin notice transient
        set_transient( 'wp_claw_health_warning', $result->get_error_message(), HOUR_IN_SECONDS );
    } else {
        delete_transient( 'wp_claw_health_warning' );
    }
}
```
