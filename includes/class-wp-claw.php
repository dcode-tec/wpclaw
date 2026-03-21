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
 *  - Register the WordPress native update mechanism (pre_set_site_transient_update_plugins).
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
	);

	// -------------------------------------------------------------------------
	// Transient key constants
	// -------------------------------------------------------------------------

	/**
	 * Transient key for cached update check data (12-hour TTL).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const TRANSIENT_UPDATE_DATA = 'wp_claw_update_data';

	/**
	 * TTL for the update data transient in seconds (12 hours).
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const UPDATE_CACHE_TTL = 43200;

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
		$this->register_update_hooks();

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
					array( 'slug' => $slug, 'class' => $class )
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
		$suffix  = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$version = defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.0.0';
		$plugin_url = defined( 'WP_CLAW_PLUGIN_URL' ) ? WP_CLAW_PLUGIN_URL : plugin_dir_url( dirname( __FILE__ ) );

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
					'welcome'   => esc_html( (string) get_option( 'wp_claw_chat_welcome', __( 'Hi! How can I help you today?', 'wp-claw' ) ) ),
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

	/**
	 * Register WordPress native plugin update hooks.
	 *
	 * Hooks into three filters to integrate WP-Claw with the standard
	 * WordPress plugin update mechanism:
	 *  1. pre_set_site_transient_update_plugins — inject update data into
	 *     the transient that WordPress checks on the Updates screen.
	 *  2. plugins_api — serve plugin metadata for the "View details" modal.
	 *  3. upgrader_post_install — ensure the plugin directory name is correct
	 *     and re-activate the plugin after an automatic update.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_update_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_plugin_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_update_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_update_install' ), 10, 3 );
	}

	/**
	 * Inject update data into the WordPress update transient.
	 *
	 * WordPress calls this filter whenever it checks for plugin updates.
	 * We call our own update API (with a 12-hour transient cache) and, if
	 * a newer version is available, add our plugin to the response list so
	 * WordPress shows the standard update notification.
	 *
	 * @since 1.0.0
	 *
	 * @param object $transient The update_plugins site transient object.
	 *
	 * @return object The (possibly modified) transient object.
	 */
	public function check_plugin_update( $transient ) {
		// WordPress may call this filter before it has finished checking all
		// plugins. If the checked list is empty there is nothing to do yet.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// --- Attempt to load from cache first --------------------------------
		$update_data = get_transient( self::TRANSIENT_UPDATE_DATA );

		// --- Cache miss: call the API ----------------------------------------
		if ( false === $update_data ) {
			$response = $this->api_client->check_for_updates();

			if ( is_wp_error( $response ) ) {
				wp_claw_log_warning(
					'Update check API call failed.',
					array( 'error' => $response->get_error_message() )
				);
				// Store a negative result so we don't hammer the API on every
				// page load when the endpoint is unavailable.
				$update_data = array();
				set_transient( self::TRANSIENT_UPDATE_DATA, $update_data, self::UPDATE_CACHE_TTL );
				return $transient;
			}

			$update_data = $response;
			set_transient( self::TRANSIENT_UPDATE_DATA, $update_data, self::UPDATE_CACHE_TTL );
		}

		// --- Inject update into transient if a newer version exists ----------
		$new_version = isset( $update_data['new_version'] ) ? $update_data['new_version'] : '';
		$current     = defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '0.0.0';

		if ( $new_version && version_compare( $current, $new_version, '<' ) ) {
			if ( ! defined( 'WP_CLAW_PLUGIN_BASENAME' ) ) {
				return $transient;
			}

			// Verify the package signature before surfacing the update.
			$package   = isset( $update_data['download_url'] ) ? $update_data['download_url'] : '';
			$signature = isset( $update_data['signature'] ) ? $update_data['signature'] : '';

			if ( $package && $signature && ! $this->verify_package_signature( $package, $signature ) ) {
				wp_claw_log_error(
					'Update package signature verification failed — update suppressed.',
					array(
						'new_version' => $new_version,
						'package'     => $package,
					)
				);
				return $transient;
			}

			$plugin_data                    = new \stdClass();
			$plugin_data->slug              = 'wp-claw';
			$plugin_data->plugin            = WP_CLAW_PLUGIN_BASENAME;
			$plugin_data->new_version       = sanitize_text_field( $new_version );
			$plugin_data->url               = esc_url_raw( isset( $update_data['url'] ) ? $update_data['url'] : 'https://wp-claw.ai' );
			$plugin_data->package           = esc_url_raw( $package );
			$plugin_data->tested            = sanitize_text_field( isset( $update_data['tested'] ) ? $update_data['tested'] : '' );
			$plugin_data->requires_php      = sanitize_text_field( isset( $update_data['requires_php'] ) ? $update_data['requires_php'] : '7.4' );
			$plugin_data->requires          = sanitize_text_field( isset( $update_data['requires'] ) ? $update_data['requires'] : '6.4' );
			$plugin_data->icons             = array(
				'1x' => esc_url_raw( isset( $update_data['icon_1x'] ) ? $update_data['icon_1x'] : '' ),
				'2x' => esc_url_raw( isset( $update_data['icon_2x'] ) ? $update_data['icon_2x'] : '' ),
			);

			$transient->response[ WP_CLAW_PLUGIN_BASENAME ] = $plugin_data;

			wp_claw_log(
				'Plugin update available.',
				'info',
				array(
					'current' => $current,
					'new'     => $new_version,
				)
			);
		}

		return $transient;
	}

	/**
	 * Serve plugin metadata for the "View details" modal in WP admin.
	 *
	 * WordPress calls the plugins_api filter when a user clicks "View details"
	 * on the Updates screen. We return a stdClass object with all the fields
	 * WordPress expects for the plugin information popup.
	 *
	 * @since 1.0.0
	 *
	 * @param false|object|\WP_Error $result The result object or false.
	 * @param string                 $action The type of information being requested ('plugin_information').
	 * @param object                 $args   Arguments passed by the API caller.
	 *
	 * @return false|object The plugin information object, or the original $result.
	 */
	public function plugin_update_info( $result, $action, $args ) {
		// Only handle plugin_information requests for our own slug.
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'wp-claw' !== $args->slug ) {
			return $result;
		}

		// Load from transient cache or fall back to the live API.
		$update_data = get_transient( self::TRANSIENT_UPDATE_DATA );
		if ( false === $update_data || empty( $update_data ) ) {
			$response = $this->api_client->check_for_updates();
			if ( ! is_wp_error( $response ) ) {
				$update_data = $response;
				set_transient( self::TRANSIENT_UPDATE_DATA, $update_data, self::UPDATE_CACHE_TTL );
			} else {
				// Can't reach the API — return false so WordPress falls back to wp.org.
				return false;
			}
		}

		$version      = isset( $update_data['new_version'] ) ? $update_data['new_version'] : ( defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.0.0' );
		$download_url = isset( $update_data['download_url'] ) ? $update_data['download_url'] : '';
		$tested       = isset( $update_data['tested'] ) ? $update_data['tested'] : '';
		$requires     = isset( $update_data['requires'] ) ? $update_data['requires'] : '6.4';
		$requires_php = isset( $update_data['requires_php'] ) ? $update_data['requires_php'] : '7.4';

		$sections = array(
			'description'  => isset( $update_data['description'] )
				? wp_kses_post( $update_data['description'] )
				: wp_kses_post( '<p>' . __( 'WP-Claw — AI operating layer for WordPress. Replace 10–15 plugins with a single AI-powered system.', 'wp-claw' ) . '</p>' ),
			'changelog'    => isset( $update_data['changelog'] )
				? wp_kses_post( $update_data['changelog'] )
				: '',
			'installation' => isset( $update_data['installation'] )
				? wp_kses_post( $update_data['installation'] )
				: wp_kses_post( '<ol><li>' . __( 'Upload the plugin ZIP or install via the WordPress plugin installer.', 'wp-claw' ) . '</li><li>' . __( 'Activate the plugin.', 'wp-claw' ) . '</li><li>' . __( 'Go to WP-Claw &rsaquo; Settings and enter your Klawty API key.', 'wp-claw' ) . '</li></ol>' ),
		);

		$info                = new \stdClass();
		$info->name          = 'WP-Claw';
		$info->slug          = 'wp-claw';
		$info->version       = sanitize_text_field( $version );
		$info->author        = '<a href="https://d-code.lu">dcode technologies</a>';
		$info->homepage      = 'https://wp-claw.ai';
		$info->requires      = sanitize_text_field( $requires );
		$info->tested        = sanitize_text_field( $tested );
		$info->requires_php  = sanitize_text_field( $requires_php );
		$info->download_link = esc_url_raw( $download_url );
		$info->sections      = $sections;

		return $info;
	}

	/**
	 * Fix the plugin directory name and re-activate after an automatic update.
	 *
	 * When WordPress auto-updates a plugin it extracts the ZIP into a
	 * temporary directory and renames it. If the ZIP root directory name does
	 * not match the plugin slug the plugin deactivates and the admin gets an
	 * "The plugin does not have a valid header" error. This filter ensures the
	 * directory name always matches the expected slug.
	 *
	 * @since 1.0.0
	 *
	 * @param bool|WP_Error $response   Installation response.
	 * @param array         $hook_extra Extra data about what is being updated.
	 * @param array         $result     Installer result data.
	 *
	 * @return bool|WP_Error The original $result (after possible rename).
	 */
	public function post_update_install( $response, $hook_extra, $result ) {
		// Only act on our own plugin.
		if ( ! isset( $hook_extra['plugin'] ) ) {
			return $result;
		}

		if ( ! defined( 'WP_CLAW_PLUGIN_BASENAME' ) ) {
			return $result;
		}

		if ( $hook_extra['plugin'] !== WP_CLAW_PLUGIN_BASENAME ) {
			return $result;
		}

		// The installer places the files in $result['destination'].
		// Ensure the destination directory is named 'wp-claw'.
		if ( empty( $result['destination'] ) ) {
			return $result;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$destination       = trailingslashit( $result['destination'] );
		$correct_dest_name = 'wp-claw';
		$plugins_dir       = trailingslashit( WP_PLUGIN_DIR );
		$correct_dest      = $plugins_dir . $correct_dest_name . '/';

		// If the extracted directory has a different name, rename it.
		if ( $destination !== $correct_dest && $wp_filesystem->is_dir( $destination ) ) {
			// Remove the target if it somehow exists (leftover from a failed update).
			if ( $wp_filesystem->is_dir( $correct_dest ) ) {
				$wp_filesystem->delete( $correct_dest, true );
			}

			$renamed = $wp_filesystem->move( $destination, $correct_dest );

			if ( $renamed ) {
				$result['destination']         = $correct_dest;
				$result['destination_name']    = $correct_dest_name;
				$result['remote_destination']  = $correct_dest;
			} else {
				wp_claw_log_error(
					'post_update_install: failed to rename plugin directory.',
					array(
						'from' => $destination,
						'to'   => $correct_dest,
					)
				);
			}
		}

		// Re-activate the plugin if it was active before the update.
		$was_active = isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'];
		if ( $was_active ) {
			$activate = activate_plugin( WP_CLAW_PLUGIN_BASENAME );

			if ( is_wp_error( $activate ) ) {
				wp_claw_log_error(
					'post_update_install: failed to re-activate plugin after update.',
					array( 'error' => $activate->get_error_message() )
				);
			}
		}

		return $result;
	}

	/**
	 * Verify an Ed25519 signature on an update package URL.
	 *
	 * Future-ready: only enforced when the WP_CLAW_UPDATE_PUBLIC_KEY constant
	 * is defined (Ed25519 public key as raw binary or hex). During the initial
	 * release and in development environments where the constant is absent,
	 * verification is skipped and this method returns true with a log warning.
	 *
	 * The signature is computed over the canonical package URL string (no
	 * trailing whitespace, lowercase scheme). The caller is responsible for
	 * providing the raw binary or base64-encoded signature from the update API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $package_url The download URL of the update package.
	 * @param string $signature   The Ed25519 signature (base64 or hex-encoded).
	 *
	 * @return bool True if the signature is valid (or verification is skipped).
	 */
	private function verify_package_signature( string $package_url, string $signature ): bool {
		// Skip verification if no public key is defined.
		if ( ! defined( 'WP_CLAW_UPDATE_PUBLIC_KEY' ) ) {
			wp_claw_log_warning(
				'WP_CLAW_UPDATE_PUBLIC_KEY not defined — skipping update signature verification.',
				array( 'package_url' => $package_url )
			);
			return true;
		}

		// Bail gracefully if the sodium extension is not available.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			wp_claw_log_warning(
				'sodium extension not available — skipping update signature verification.',
				array( 'package_url' => $package_url )
			);
			return true;
		}

		$public_key = WP_CLAW_UPDATE_PUBLIC_KEY;

		// Accept the key as either raw binary (32 bytes) or hex-encoded (64 chars).
		if ( 64 === strlen( $public_key ) && ctype_xdigit( $public_key ) ) {
			$public_key = hex2bin( $public_key );
		}

		// Validate key length: Ed25519 public keys are always 32 bytes.
		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			wp_claw_log_error(
				'WP_CLAW_UPDATE_PUBLIC_KEY has invalid length — skipping verification.',
				array( 'expected' => SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'got' => strlen( $public_key ) )
			);
			return true; // Fail open (do not block update) but log loudly.
		}

		// Accept signatures as base64 or raw binary.
		$sig_raw = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $sig_raw ) {
			// Not valid base64 — try treating as raw binary.
			$sig_raw = $signature;
		}

		if ( SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig_raw ) ) {
			wp_claw_log_error(
				'Update package signature has invalid length — verification failed.',
				array(
					'expected' => SODIUM_CRYPTO_SIGN_BYTES,
					'got'      => strlen( $sig_raw ),
				)
			);
			return false;
		}

		// The message to verify is the canonical lowercase package URL.
		$message = strtolower( trim( $package_url ) );

		$valid = sodium_crypto_sign_verify_detached( $sig_raw, $message, $public_key );

		if ( ! $valid ) {
			wp_claw_log_error(
				'Update package Ed25519 signature mismatch.',
				array( 'package_url' => $package_url )
			);
		}

		return $valid;
	}

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
