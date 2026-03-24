<?php
/**
 * Abstract base class for all WP-Claw modules.
 *
 * Every module (SEO, Security, Content, etc.) extends this class.
 * Provides shared access to the API client and enforces the module
 * contract via abstract methods.
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
 * Abstract base class that all WP-Claw modules must extend.
 *
 * Defines the contract every module must fulfil and provides
 * shared access to the Klawty API client. The module loader
 * calls register_hooks() on each enabled module during init.
 *
 * @since 1.0.0
 */
abstract class Module_Base {

	/**
	 * API client instance used to communicate with the Klawty instance.
	 *
	 * @since 1.0.0
	 *
	 * @var API_Client
	 */
	protected API_Client $api_client;

	/**
	 * Module slug, populated on first call to get_slug().
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $slug = '';

	/**
	 * Agent name responsible for this module, populated on first call to get_agent().
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected string $agent = '';

	/**
	 * Constructor.
	 *
	 * Stores the shared API client for use by child classes.
	 *
	 * @since 1.0.0
	 *
	 * @param API_Client $api_client The Klawty API client instance.
	 */
	public function __construct( API_Client $api_client ) {
		$this->api_client = $api_client;
	}

	// -------------------------------------------------------------------------
	// Abstract methods — all child classes must implement these.
	// -------------------------------------------------------------------------

	/**
	 * Return the module's machine-readable slug (e.g. 'seo', 'security').
	 *
	 * Must be lowercase with hyphens only, unique across all modules.
	 * Used as the key in option arrays and REST API parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return string Module slug.
	 */
	abstract public function get_slug(): string;

	/**
	 * Return the module's human-readable name (e.g. 'SEO', 'Security').
	 *
	 * Used in admin UI labels and log messages.
	 *
	 * @since 1.0.0
	 *
	 * @return string Module display name.
	 */
	abstract public function get_name(): string;

	/**
	 * Return the Klawty agent responsible for this module (e.g. 'scribe', 'sentinel').
	 *
	 * Used when dispatching tasks to the Klawty API.
	 *
	 * @since 1.0.0
	 *
	 * @return string Agent name.
	 */
	abstract public function get_agent(): string;

	/**
	 * Return the list of actions this module exposes through the REST bridge.
	 *
	 * The allowlist is enforced by the REST API bridge before any action
	 * is executed. Only actions present in this array can be triggered
	 * by an agent via the /wp-json/wp-claw/v1/execute endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Flat array of allowed action strings.
	 */
	abstract public function get_allowed_actions(): array;

	/**
	 * Handle an inbound agent action.
	 *
	 * Called by the REST bridge after allowlist verification. Each module
	 * is responsible for routing to the correct internal handler based on
	 * the $action string, performing the WordPress operation, and returning
	 * a structured result array.
	 *
	 * On success: return an associative array with at least a 'success' key.
	 * On failure: return a WP_Error instance — the REST bridge converts it to
	 * the appropriate HTTP error response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform (must be in get_allowed_actions()).
	 * @param array  $params Parameters sent by the agent with this action.
	 *
	 * @return array|\WP_Error Result array on success, WP_Error on failure.
	 */
	abstract public function handle_action( string $action, array $params );

	/**
	 * Return the current WordPress state relevant to this module.
	 *
	 * Called by the state sync cron to send a structured snapshot of the
	 * site's current state to the Klawty instance, so agents have fresh
	 * context when making decisions.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of state data.
	 */
	abstract public function get_state(): array;

	/**
	 * Register WordPress action and filter hooks for this module.
	 *
	 * Called once during plugin init after the module is instantiated.
	 * Must use add_action() / add_filter() with proper priorities.
	 * Must NOT execute any logic immediately — only register hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function register_hooks(): void;

	// -------------------------------------------------------------------------
	// Concrete methods — can be overridden by child classes.
	// -------------------------------------------------------------------------

	/**
	 * Whether this module is available on the current WordPress installation.
	 *
	 * Override in child classes to add prerequisite checks. For example,
	 * the Commerce module overrides this to return false when WooCommerce
	 * is not active.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the module is available.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Return the admin settings fields for this module.
	 *
	 * Override in child classes that expose settings in the admin UI.
	 * Each entry describes a settings field for the module's settings section.
	 *
	 * Expected array structure per field:
	 * [
	 *   'id'          => 'wp_claw_{slug}_{field}',
	 *   'title'       => __( 'Field Label', 'wp-claw' ),
	 *   'type'        => 'text'|'checkbox'|'select'|'textarea',
	 *   'description' => __( 'Helpful description.', 'wp-claw' ),
	 *   'default'     => '',
	 *   'options'     => [],  // only for 'select' type
	 * ]
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of field definition arrays, or empty array if no settings.
	 */
	public function get_settings_fields(): array {
		return array();
	}
}
