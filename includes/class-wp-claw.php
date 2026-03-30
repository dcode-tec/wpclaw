<?php
/**
 * Main WP-Claw plugin class.
 *
 * Singleton orchestrator that bootstraps all plugin subsystems: i18n,
 * API client, REST API, cron, hooks, modules, admin UI, and the WordPress
 * native update integration. Also handles the DB upgrade check on every
 * page load so schema migrations happen automatically after plugin updates.
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
 * Central plugin orchestrator — singleton pattern.
 *
 * Responsibilities:
 *  - DB version check and schema upgrade on plugin update.
 *  - Instantiate and wire all subsystems (API client, REST API, cron, hooks, modules, admin).
 *  - Conditionally load frontend assets (chat widget, analytics pixel).
 *  - (Self-hosted only) Register custom update mechanism.
 *  - Provide a clean public API for other code to reach subsystems.
 *
 * Usage:
 *   $plugin = WP_Claw::get_instance();
 *   $plugin->init(); // called once from the plugins_loaded hook in wp-claw.php
 *
 * @since 1.0.0
 */
class WP_Claw {

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * The single instance of this class.
	 *
	 * @since 1.0.0
	 *
	 * @var self|null
	 */
	private static $instance = null;

	// -------------------------------------------------------------------------
	// Subsystem properties
	// -------------------------------------------------------------------------

	/**
	 * Klawty HTTP client.
	 *
	 * @since 1.0.0
	 *
	 * @var API_Client
	 */
	private $api_client;

	/**
	 * WordPress REST API bridge.
	 *
	 * @since 1.0.0
	 *
	 * @var REST_API
	 */
	private $rest_api;

	/**
	 * Admin UI manager (null on the frontend).
	 *
	 * @since 1.0.0
	 *
	 * @var Admin|null
	 */
	private $admin = null;

	/**
	 * WP-Cron event handlers.
	 *
	 * @since 1.0.0
	 *
	 * @var Cron
	 */
	private $cron;

	/**
	 * WordPress hook registry and task queue.
	 *
	 * @since 1.0.0
	 *
	 * @var Hooks
	 */
	private $hooks;

	/**
	 * Internationalization loader.
	 *
	 * @since 1.0.0
	 *
	 * @var I18n
	 */
	private $i18n;

	/**
	 * Active module instances, keyed by slug.
	 *
	 * Only contains modules that are both enabled in settings and whose
	 * is_available() method returned true during init.
	 *
	 * @since 1.0.0
	 *
	 * @var Module_Base[]
	 */
	private $modules = array();

	// -------------------------------------------------------------------------
	// Module registry
	// -------------------------------------------------------------------------

	/**
	 * Map of module slug to fully-qualified class name.
	 *
	 * Add a new module here to make it eligible for loading. The class must
	 * extend Module_Base and reside in the WPClaw\Modules namespace.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private static $module_registry = array(
		'seo'         => 'WPClaw\\Modules\\Module_SEO',
		'security'    => 'WPClaw\\Modules\\Module_Security',
		'content'     => 'WPClaw\\Modules\\Module_Content',
		'crm'         => 'WPClaw\\Modules\\Module_CRM',
		'commerce'    => 'WPClaw\\Modules\\Module_Commerce',
		'performance' => 'WPClaw\\Modules\\Module_Performance',
		'forms'       => 'WPClaw\\Modules\\Module_Forms',
		'analytics'   => 'WPClaw\\Modules\\Module_Analytics',
		'backup'      => 'WPClaw\\Modules\\Module_Backup',
		'social'      => 'WPClaw\\Modules\\Module_Social',
		'chat'        => 'WPClaw\\Modules\\Module_Chat',
		'audit'       => 'WPClaw\\Modules\\Module_Audit',
	);

	// -------------------------------------------------------------------------
	// Singleton pattern
	// -------------------------------------------------------------------------

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Retrieve (or create) the single plugin instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Bootstrap all plugin subsystems.
	 *
	 * Called once from the plugins_loaded hook in the main plugin file.
	 * Runs in a defined order so dependencies are always available when
	 * later steps need them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init(): void {

		// --- Step 1: DB upgrade check ----------------------------------------
		// Run dbDelta() if the stored schema version is behind the current constant.
		$stored_db_version = get_option( 'wp_claw_db_version', '0' );

		if ( defined( 'WP_CLAW_DB_VERSION' ) && version_compare( $stored_db_version, WP_CLAW_DB_VERSION, '<' ) ) {
			Activator::create_tables();
			update_option( 'wp_claw_db_version', WP_CLAW_DB_VERSION );

			wp_claw_log(
				'DB schema upgraded.',
				'info',
				array(
					'from' => $stored_db_version,
					'to'   => WP_CLAW_DB_VERSION,
				)
			);

			// Schedule any missing cron events (ensures new events are registered on plugin update).
			$required_crons = Activator::get_cron_events();
			foreach ( $required_crons as $hook => $recurrence ) {
				if ( ! wp_next_scheduled( $hook ) ) {
					wp_schedule_event( time(), $recurrence, $hook );
				}
			}
		}

		// --- Step 2: Internationalization ------------------------------------
		$this->i18n = new I18n();
		$this->i18n->init();

		// --- Step 3: API client ---------------------------------------------
		$this->api_client = new API_Client();

		// --- Step 4: REST API bridge ----------------------------------------
		$this->rest_api = new REST_API( $this->api_client );

		// --- Step 5: Cron ---------------------------------------------------
		$this->cron = new Cron( $this->api_client );

		// --- Step 6: Hook registry ------------------------------------------
		$this->hooks = new Hooks( $this->api_client );
		$this->hooks->register_hooks();

		// --- Step 7: Module loader -------------------------------------------
		$this->load_modules();

		// --- Step 8: Admin UI -----------------------------------------------
		if ( is_admin() ) {
			$this->admin = new Admin( $this->api_client );
		}

		// --- Step 9: Frontend asset enqueuing --------------------------------
		if ( ! is_admin() ) {
			$chat_enabled      = $this->is_module_enabled( 'chat' );
			$analytics_enabled = $this->is_module_enabled( 'analytics' );

			if ( $chat_enabled || $analytics_enabled ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
			}
		}

		// --- Step 10: WordPress update system hooks --------------------------
		// Custom update hooks disabled for wordpress.org hosted plugins.
		// WordPress.org handles updates via SVN. See register_update_hooks().
		// $this->register_update_hooks();

		// --- Step 11: Activation redirect note ------------------------------
		// The redirect transient is consumed and deleted by Admin::handle_activation_redirect()
		// on the admin_init hook (inside class-admin.php). Nothing to do here except
		// confirm we do NOT redirect from this context (headers may already be sent).
	}

	// -------------------------------------------------------------------------
	// Module loader (private)
	// -------------------------------------------------------------------------

	/**
	 * Instantiate every enabled module and register its WordPress hooks.
	 *
	 * Only modules present in the wp_claw_enabled_modules option AND whose
	 * is_available() method returns true are loaded. All others are silently
	 * skipped to keep the runtime lean.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function load_modules(): void {
		$enabled = (array) get_option( 'wp_claw_enabled_modules', array() );

		foreach ( $enabled as $slug ) {
			$slug = sanitize_key( $slug );

			// Skip slugs not in the registry.
			if ( ! isset( self::$module_registry[ $slug ] ) ) {
				continue;
			}

			$class = self::$module_registry[ $slug ];

			// Skip if the class hasn't been loaded yet (graceful degradation
			// during partial builds / unit tests).
			if ( ! class_exists( $class ) ) {
				wp_claw_log(
					'Module class not found — skipping.',
					'warning',
					array(
						'slug'  => $slug,
						'class' => $class,
					)
				);
				continue;
			}

			/** @var Module_Base $module */
			$module = new $class( $this->api_client );

			// Skip modules that declare themselves unavailable (e.g. Commerce
			// when WooCommerce is not installed).
			if ( ! $module->is_available() ) {
				wp_claw_log(
					'Module not available on this installation — skipping.',
					'info',
					array( 'slug' => $slug )
				);
				continue;
			}

			$module->register_hooks();
			$this->modules[ $slug ] = $module;
		}
	}

	// -------------------------------------------------------------------------
	// Frontend assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue frontend scripts and styles for the chat widget and analytics pixel.
	 *
	 * Hooked to wp_enqueue_scripts. Only fires when at least one of the chat
	 * or analytics modules is active. Assets are versioned with WP_CLAW_VERSION
	 * so browsers automatically bust their caches on plugin updates.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_public_assets(): void {
		$suffix     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$version    = defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.0.0';
		$plugin_url = defined( 'WP_CLAW_PLUGIN_URL' ) ? WP_CLAW_PLUGIN_URL : plugin_dir_url( __DIR__ );

		// --- Chat widget ---------------------------------------------------
		if ( $this->is_module_enabled( 'chat' ) ) {
			wp_enqueue_style(
				'wp-claw-public',
				$plugin_url . 'public/css/wp-claw-public' . $suffix . '.css',
				array(),
				$version
			);

			wp_enqueue_script(
				'wp-claw-public',
				$plugin_url . 'public/js/wp-claw-public' . $suffix . '.js',
				array(),
				$version,
				true // load in footer
			);

			// Pass REST URL and nonce to the chat widget JS.
			wp_localize_script(
				'wp-claw-public',
				'wpClawChat',
				array(
					'restUrl'   => esc_url_raw( rest_url( 'wp-claw/v1/chat' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'agentName' => esc_html( (string) get_option( 'wp_claw_chat_agent_name', 'Concierge' ) ),
					'welcome'   => esc_html( (string) get_option( 'wp_claw_chat_welcome', __( 'Hi! How can I help you today?', 'claw-agent' ) ) ),
					'position'  => sanitize_text_field( (string) get_option( 'wp_claw_chat_position', 'bottom-right' ) ),
				)
			);
		}

		// --- Analytics pixel -----------------------------------------------
		if ( $this->is_module_enabled( 'analytics' ) ) {
			wp_enqueue_script(
				'wp-claw-analytics',
				$plugin_url . 'public/js/wp-claw-analytics' . $suffix . '.js',
				array(),
				$version,
				true // load in footer
			);

			wp_localize_script(
				'wp-claw-analytics',
				'wpClawAnalytics',
				array(
					'restUrl' => esc_url_raw( rest_url( 'wp-claw/v1/analytics' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Task 7b: WordPress update system integration
	// -------------------------------------------------------------------------

	/*
	 * ── Custom Update System (disabled for wordpress.org) ────────────
	 *
	 * The self-hosted version includes a custom update checker that hooks
	 * into WordPress's plugin update transient. This is not permitted on
	 * wordpress.org, which handles updates via SVN.
	 *
	 * For the self-hosted distribution, re-enable register_update_hooks()
	 * in the init() method and uncomment the methods below.
	 * ─────────────────────────────────────────────────────────────────
	 */

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the API client instance.
	 *
	 * @since 1.0.0
	 *
	 * @return API_Client
	 */
	public function get_api_client(): API_Client {
		return $this->api_client;
	}

	/**
	 * Retrieve an active module instance by slug.
	 *
	 * Returns null if the module is not enabled or not available on this
	 * WordPress installation. Always check the return value before using it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Module slug (e.g. 'seo', 'security', 'chat').
	 *
	 * @return Module_Base|null The module instance or null.
	 */
	public function get_module( string $slug ) {
		$slug = sanitize_key( $slug );
		return isset( $this->modules[ $slug ] ) ? $this->modules[ $slug ] : null;
	}

	/**
	 * Retrieve all active module instances.
	 *
	 * Returns only modules that are both enabled in settings AND whose
	 * is_available() check passed during init. Keyed by slug.
	 *
	 * @since 1.0.0
	 *
	 * @return Module_Base[] Associative array of slug => Module_Base instance.
	 */
	public function get_enabled_modules(): array {
		return $this->modules;
	}

	/**
	 * Check whether a module is currently active.
	 *
	 * A module is considered enabled only when it is both present in the
	 * wp_claw_enabled_modules option AND was successfully instantiated
	 * (i.e. is_available() returned true during init).
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Module slug.
	 *
	 * @return bool True if the module is active.
	 */
	public function is_module_enabled( string $slug ): bool {
		$slug = sanitize_key( $slug );
		return isset( $this->modules[ $slug ] );
	}
}
