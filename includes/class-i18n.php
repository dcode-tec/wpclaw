<?php
/**
 * Internationalization loader.
 *
 * Registers the plugin text domain so translations provided in the
 * /languages directory are picked up by WordPress.
 *
 * @package    WPClaw
 * @subpackage WPClaw/includes
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

namespace WPClaw;

defined( 'ABSPATH' ) || exit;

/**
 * Internationalization loader class.
 *
 * Hooks into the WordPress 'init' action to load the plugin's
 * translated strings from the /languages directory. The class is
 * instantiated by the main WP_Claw class during bootstrap.
 *
 * @since 1.0.0
 */
class I18n {

	/**
	 * Register the i18n loader on the WordPress init hook.
	 *
	 * Called by the main plugin class during bootstrap so that
	 * translated strings are available as early as possible.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Load the plugin text domain.
	 *
	 * Looks for .mo translation files in the plugin's /languages
	 * directory. WordPress will also check the wp-content/languages/plugins
	 * directory for user-supplied translations, which take precedence.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-claw',
			false,
			dirname( plugin_basename( WP_CLAW_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
