---
name: wp-plugin-architecture
description: WordPress plugin architecture patterns — singleton, hooks, module system, activation/deactivation, autoloading, admin pages, settings API, custom post types, taxonomies
keywords: [wordpress, plugin, architecture, hooks, module, singleton, settings, admin, activation, deactivation]
---

# WordPress Plugin Architecture

## Plugin Bootstrap Pattern

Main plugin file (`wp-claw.php`) defines constants, registers activation/deactivation hooks, and bootstraps the main class:

```php
<?php
/**
 * Plugin Name: WP-Claw
 * Plugin URI:  https://wp-claw.ai
 * Description: AI operating layer for WordPress — replaces 10+ plugins with one AI brain.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.4
 * Author:      dcode technologies
 * Author URI:  https://d-code.lu
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-claw
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_CLAW_VERSION', '1.0.0' );
define( 'WP_CLAW_FILE', __FILE__ );
define( 'WP_CLAW_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CLAW_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_CLAW_BASENAME', plugin_basename( __FILE__ ) );

register_activation_hook( __FILE__, array( 'WPClaw\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPClaw\\Deactivator', 'deactivate' ) );

require_once WP_CLAW_DIR . 'includes/class-wp-claw.php';

function wp_claw() {
    return WPClaw\WP_Claw::instance();
}

wp_claw();
```

## Singleton Pattern

```php
namespace WPClaw;

class WP_Claw {
    private static ?self $instance = null;
    private array $modules = [];

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->load_modules();
    }
}
```

## Module Loader Pattern

Modules are self-registering. Each module extends an abstract base class and is loaded conditionally:

```php
private function load_modules(): void {
    $module_classes = [
        'seo'         => Modules\SEO::class,
        'security'    => Modules\Security::class,
        'content'     => Modules\Content::class,
        'crm'         => Modules\CRM::class,
        'commerce'    => Modules\Commerce::class,    // only if WooCommerce active
        'performance' => Modules\Performance::class,
        'forms'       => Modules\Forms::class,
        'analytics'   => Modules\Analytics::class,
        'backup'      => Modules\Backup::class,
        'social'      => Modules\Social::class,
    ];

    foreach ( $module_classes as $id => $class ) {
        if ( 'commerce' === $id && ! class_exists( 'WooCommerce' ) ) {
            continue;
        }
        $module = new $class( $this->api_client );
        if ( $module->is_enabled() ) {
            $module->register_hooks();
            $this->modules[ $id ] = $module;
        }
    }
}
```

## Settings API Pattern

Use WordPress Settings API for all options:

```php
register_setting( 'wp_claw_settings', 'wp_claw_api_key', [
    'type'              => 'string',
    'sanitize_callback' => 'sanitize_text_field',
    'default'           => '',
] );

add_settings_section( 'wp_claw_connection', __( 'Connection', 'wp-claw' ), null, 'wp-claw-settings' );
add_settings_field( 'wp_claw_api_key', __( 'API Key', 'wp-claw' ), [ $this, 'render_api_key_field' ], 'wp-claw-settings', 'wp_claw_connection' );
```

## Hook Priority Convention

- 5: Early hooks (security checks, authentication)
- 10: Default priority (standard operations)
- 20: Late hooks (output modification, analytics)
- 99: Last resort (cleanup, final filters)

## Admin Menu Structure

```php
add_menu_page(
    __( 'WP-Claw', 'wp-claw' ),
    __( 'WP-Claw', 'wp-claw' ),
    'manage_options',
    'wp-claw',
    [ $this, 'render_dashboard' ],
    'dashicons-superhero-alt',
    30
);

add_submenu_page( 'wp-claw', __( 'Agents', 'wp-claw' ), __( 'Agents', 'wp-claw' ), 'manage_options', 'wp-claw-agents', [ $this, 'render_agents' ] );
add_submenu_page( 'wp-claw', __( 'Settings', 'wp-claw' ), __( 'Settings', 'wp-claw' ), 'manage_options', 'wp-claw-settings', [ $this, 'render_settings' ] );
```