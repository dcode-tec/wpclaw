<?php
/**
 * WP-Claw — The AI Operating Layer for WordPress.
 *
 * @package    WPClaw
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       WP-Claw
 * Plugin URI:        https://wp-claw.ai
 * Description:       The AI Operating Layer for WordPress — replaces 10-15 plugins with one AI-powered system connected to Klawty.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            dcode technologies
 * Author URI:        https://d-code.lu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-claw
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WP_CLAW_VERSION', '1.0.0' );
define( 'WP_CLAW_DB_VERSION', '1.0.0' );
define( 'WP_CLAW_PLUGIN_FILE', __FILE__ );
define( 'WP_CLAW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CLAW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_CLAW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Helpers — loaded first, no class dependencies.
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/logger.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/encryption.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/sanitization.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/capabilities.php';

// Core classes — load in dependency order.
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-module-base.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-api-client.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-admin.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-cron.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-hooks.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-activator.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-i18n.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-wp-claw.php';

// Modules — each independently togglable.
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-seo.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-security.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-content.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-crm.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-commerce.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-performance.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-forms.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-analytics.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-backup.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-social.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-chat.php';

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( 'WPClaw\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPClaw\\Deactivator', 'deactivate' ) );

// Bootstrap the plugin after all plugins are loaded.
add_action(
	'plugins_loaded',
	function () {
		WPClaw\WP_Claw::get_instance()->init();
	}
);
