<?php
/**
 * Admin UI — menus, settings, asset loading, admin bar badge.
 *
 * Registers the WP-Claw top-level menu and eight submenus, enqueues
 * admin assets only on WP-Claw pages, registers all plugin settings,
 * adds an admin bar status badge, and handles the post-activation redirect.
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
 * Manages all WordPress admin UI integration for WP-Claw.
 *
 * Responsibilities:
 *  - Register the top-level admin menu and eight submenus with granular capabilities.
 *  - Enqueue admin CSS and JS only on WP-Claw pages (no leaking to other screens).
 *  - Register and sanitize all plugin settings via the WordPress Settings API.
 *  - Add a real-time status badge to the admin bar (green/yellow/red dot).
 *  - Handle the post-activation redirect to the settings page.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * API client used to determine connection status for the admin bar badge.
	 *
	 * @since 1.0.0
	 *
	 * @var API_Client
	 */
	private API_Client $api_client;

	/**
	 * Page hook suffixes returned by add_menu_page() / add_submenu_page().
	 *
	 * Used by enqueue_assets() to restrict asset loading to WP-Claw screens.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private array $page_hooks = array();

	/**
	 * Constructor.
	 *
	 * Wires all admin hooks. Hooks are registered here; logic executes later.
	 *
	 * @since 1.0.0
	 *
	 * @param API_Client $api_client The Klawty API client instance.
	 */
	public function __construct( API_Client $api_client ) {
		$this->api_client = $api_client;

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_init', array( $this, 'maybe_recover_capabilities' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 100 );
		add_action( 'admin_post_wp_claw_clear_local_data', array( $this, 'handle_clear_local_data' ) );
		add_action( 'wp_ajax_wp_claw_save_profile', array( $this, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_wp_claw_send_test_email', array( $this, 'ajax_send_test_email' ) );
	}

	// -------------------------------------------------------------------------
	// Admin menus
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level WP-Claw menu and all submenus.
	 *
	 * Uses granular capabilities so site administrators can grant access to
	 * individual sections without giving full plugin management rights.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menus(): void {
		// Top-level menu entry. Renders the Dashboard view.
		$this->page_hooks[] = add_menu_page(
			__( 'WP-Claw', 'claw-agent' ),
			__( 'WP-Claw', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'claw-agent',
			array( $this, 'render_dashboard' ),
			'dashicons-superhero',
			80
		);

		// Dashboard (first submenu mirrors the top-level item).
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Dashboard — WP-Claw', 'claw-agent' ),
			__( 'Dashboard', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'claw-agent',
			array( $this, 'render_dashboard' )
		);

		// Agents overview.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Agents — WP-Claw', 'claw-agent' ),
			__( 'Agents', 'claw-agent' ),
			'wp_claw_manage_agents',
			'wp-claw-agents',
			array( $this, 'render_agents' )
		);

		// Proposals queue.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Proposals — WP-Claw', 'claw-agent' ),
			__( 'Proposals', 'claw-agent' ),
			'wp_claw_approve_proposals',
			'wp-claw-proposals',
			array( $this, 'render_proposals' )
		);

		// Security dashboard.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Security — WP-Claw', 'claw-agent' ),
			__( 'Security', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-security',
			array( $this, 'render_security' )
		);

		// Commerce & CRM dashboard.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Commerce & CRM — WP-Claw', 'claw-agent' ),
			__( 'Commerce & CRM', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-commerce',
			array( $this, 'render_commerce' )
		);

		// SEO & Content dashboard.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'SEO & Content — WP-Claw', 'claw-agent' ),
			__( 'SEO & Content', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-seo',
			array( $this, 'render_seo_content' )
		);

		// Analytics dashboard.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Analytics — WP-Claw', 'claw-agent' ),
			__( 'Analytics', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-analytics',
			array( $this, 'render_analytics' )
		);

		// Backup management.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Backup — WP-Claw', 'claw-agent' ),
			__( 'Backup', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-backup',
			array( $this, 'render_backup' )
		);

		// Chat management.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Chat — WP-Claw', 'claw-agent' ),
			__( 'Chat', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-chat',
			array( $this, 'render_chat' )
		);

		// Social management.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Social — WP-Claw', 'claw-agent' ),
			__( 'Social', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-social',
			array( $this, 'render_social' )
		);

		// Forms management.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Forms — WP-Claw', 'claw-agent' ),
			__( 'Forms', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-forms',
			array( $this, 'render_forms' )
		);

		// Settings page.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Settings — WP-Claw', 'claw-agent' ),
			__( 'Settings', 'claw-agent' ),
			'wp_claw_manage_settings',
			'wp-claw-settings',
			array( $this, 'render_settings' )
		);

		// Command Center.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Command Center — WP-Claw', 'claw-agent' ),
			__( '🏗️ Command Center', 'claw-agent' ),
			'wp_claw_command_center',
			'wp-claw-command-center',
			array( $this, 'render_command_center_page' )
		);

		// Reports.
		$this->page_hooks[] = add_submenu_page(
			'claw-agent',
			__( 'Reports — WP-Claw', 'claw-agent' ),
			__( 'Reports', 'claw-agent' ),
			'wp_claw_view_dashboard',
			'wp-claw-reports',
			array( $this, 'render_reports' )
		);
	}

	// -------------------------------------------------------------------------
	// Asset loading
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin CSS and JS on WP-Claw admin pages only.
	 *
	 * Assets are never loaded on other admin screens to avoid CSS/JS conflicts
	 * and unnecessary HTTP requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_wp_claw_page( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'wp-claw-admin',
			WP_CLAW_PLUGIN_URL . 'admin/css/wp-claw-admin.css',
			array(),
			WP_CLAW_VERSION
		);

		wp_enqueue_script(
			'wp-claw-admin',
			WP_CLAW_PLUGIN_URL . 'admin/js/wp-claw-admin.js',
			array(),
			WP_CLAW_VERSION . '.' . time(),
			true
		);

		// Determine the current WP-Claw page identifier for the JS router.
		$current_page = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		$raw_page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		if ( 'wp-claw-command-center' === $raw_page ) {
			$current_page = 'command-center';
		} elseif ( 'claw-agent' === $raw_page ) {
			$current_page = 'dashboard';
		} elseif ( '' !== $raw_page && 0 === strpos( $raw_page, 'wp-claw-' ) ) {
			$current_page = substr( $raw_page, strlen( 'wp-claw-' ) );
		}

		wp_localize_script(
			'wp-claw-admin',
			'wpClaw',
			array(
				'restUrl'    => rest_url( 'wp-claw/v1/' ),
				'avatarUrl'  => WP_CLAW_PLUGIN_URL . 'public/avatars/',
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'adminNonce' => wp_create_nonce( 'wp_claw_admin' ),
				'page'       => $current_page,
				'i18n'       => array(
					'approve'        => __( 'Approve', 'claw-agent' ),
					'reject'         => __( 'Reject', 'claw-agent' ),
					'confirmReject'  => __( 'Are you sure you want to reject this proposal?', 'claw-agent' ),
					'saving'         => __( 'Saving\u2026', 'claw-agent' ),
					'saved'          => __( 'Saved.', 'claw-agent' ),
					'error'          => __( 'An error occurred. Please try again.', 'claw-agent' ),
					'connected'      => __( 'Connected', 'claw-agent' ),
					'disconnected'   => __( 'Disconnected', 'claw-agent' ),
					'testConnection' => __( 'Test connection', 'claw-agent' ),
					'noCommands'     => __( 'No commands yet.', 'claw-agent' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings API
	// -------------------------------------------------------------------------

	/**
	 * Register all WP-Claw settings, sections, and fields.
	 *
	 * All sanitize callbacks run before the value reaches the database.
	 * The API key is encrypted on save so it is never stored in plaintext.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// ----- Connection settings -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_connection_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_connection_mode' ),
				'default'           => 'managed',
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_instance_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_instance_url' ),
				'default'           => '',
			)
		);

		// ----- Module toggles -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_enabled_modules',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_enabled_modules' ),
				'default'           => array(),
			)
		);

		// ----- Chat widget settings -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_position',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_chat_position' ),
				'default'           => 'bottom-right',
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_welcome',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'Hi! How can I help you today?', 'claw-agent' ),
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_agent_name',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'Assistant', 'claw-agent' ),
			)
		);

		// ----- Analytics settings -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_analytics_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		// ----- Settings sections -----
		add_settings_section(
			'wp_claw_connection',
			__( 'Klawty Connection', 'claw-agent' ),
			array( $this, 'render_connection_section_description' ),
			'wp-claw-settings'
		);

		add_settings_section(
			'wp_claw_chat',
			__( 'Chat Widget', 'claw-agent' ),
			array( $this, 'render_chat_section_description' ),
			'wp-claw-settings'
		);

		add_settings_section(
			'wp_claw_analytics_section',
			__( 'Analytics', 'claw-agent' ),
			'__return_false',
			'wp-claw-settings'
		);

		// ----- Settings fields — Connection -----
		add_settings_field(
			'wp_claw_api_key',
			__( 'API Key', 'claw-agent' ),
			array( $this, 'render_field_api_key' ),
			'wp-claw-settings',
			'wp_claw_connection'
		);

		add_settings_field(
			'wp_claw_connection_mode',
			__( 'Connection Mode', 'claw-agent' ),
			array( $this, 'render_field_connection_mode' ),
			'wp-claw-settings',
			'wp_claw_connection'
		);

		add_settings_field(
			'wp_claw_instance_url',
			__( 'Instance URL', 'claw-agent' ),
			array( $this, 'render_field_instance_url' ),
			'wp-claw-settings',
			'wp_claw_connection'
		);

		// ----- Settings fields — Chat -----
		add_settings_field(
			'wp_claw_chat_enabled',
			__( 'Enable Chat Widget', 'claw-agent' ),
			array( $this, 'render_field_chat_enabled' ),
			'wp-claw-settings',
			'wp_claw_chat'
		);

		add_settings_field(
			'wp_claw_chat_position',
			__( 'Widget Position', 'claw-agent' ),
			array( $this, 'render_field_chat_position' ),
			'wp-claw-settings',
			'wp_claw_chat'
		);

		add_settings_field(
			'wp_claw_chat_welcome',
			__( 'Welcome Message', 'claw-agent' ),
			array( $this, 'render_field_chat_welcome' ),
			'wp-claw-settings',
			'wp_claw_chat'
		);

		add_settings_field(
			'wp_claw_chat_agent_name',
			__( 'Agent Display Name', 'claw-agent' ),
			array( $this, 'render_field_chat_agent_name' ),
			'wp-claw-settings',
			'wp_claw_chat'
		);

		// ----- Settings fields — Analytics -----
		add_settings_field(
			'wp_claw_analytics_enabled',
			__( 'Enable Analytics Tracking', 'claw-agent' ),
			array( $this, 'render_field_analytics_enabled' ),
			'wp-claw-settings',
			'wp_claw_analytics_section'
		);

		// ----- Security settings (v1.2.0) -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_brute_force_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_brute_force_max_attempts',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 5,
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_brute_force_lockout_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 30,
			)
		);

		// ----- Backup settings (v1.2.0) -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_backup_daily_retention',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 7,
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_backup_weekly_retention',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 30,
			)
		);

		// ----- Chat GDPR settings (v1.2.0) -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_consent_text',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_privacy_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'wp_claw_settings',
			'wp_claw_chat_sla_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 60,
			)
		);

		// ----- Notification settings (v1.3.0) -----
		register_setting(
			'wp_claw_settings',
			'wp_claw_notification_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_notification_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize notification settings array.
	 *
	 * Validates each field to its expected type and range before saving to the
	 * database. Unknown keys are silently dropped.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $input Raw input from the settings form.
	 *
	 * @return array Sanitized notification settings.
	 */
	public function sanitize_notification_settings( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$valid_agents = array( 'architect', 'scribe', 'sentinel', 'commerce', 'analyst', 'concierge' );
		$output       = array();

		// enabled — boolean (checkbox).
		$output['enabled'] = ! empty( $input['enabled'] );

		// email — sanitized email address.
		$output['email'] = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';

		// realtime_alerts — boolean (checkbox).
		$output['realtime_alerts'] = ! empty( $input['realtime_alerts'] );

		// daily_digest — boolean (checkbox).
		$output['daily_digest'] = ! empty( $input['daily_digest'] );

		// digest_hour — integer 0–23.
		$digest_hour          = isset( $input['digest_hour'] ) ? (int) $input['digest_hour'] : 8;
		$output['digest_hour'] = max( 0, min( 23, $digest_hour ) );

		// digest_format — 'html' or 'text'.
		$output['digest_format'] = ( isset( $input['digest_format'] ) && 'text' === $input['digest_format'] ) ? 'text' : 'html';

		// weekly_report — boolean (checkbox).
		$output['weekly_report'] = ! empty( $input['weekly_report'] );

		// weekly_day — integer 0–6.
		$weekly_day          = isset( $input['weekly_day'] ) ? (int) $input['weekly_day'] : 0;
		$output['weekly_day'] = max( 0, min( 6, $weekly_day ) );

		// weekly_hour — integer 0–23.
		$weekly_hour          = isset( $input['weekly_hour'] ) ? (int) $input['weekly_hour'] : 9;
		$output['weekly_hour'] = max( 0, min( 23, $weekly_hour ) );

		// muted_agents — array of valid agent slugs.
		$raw_muted            = isset( $input['muted_agents'] ) && is_array( $input['muted_agents'] ) ? $input['muted_agents'] : array();
		$output['muted_agents'] = array_values(
			array_intersect(
				array_map( 'sanitize_key', $raw_muted ),
				$valid_agents
			)
		);

		return $output;
	}

	/**
	 * AJAX handler — send a test notification email.
	 *
	 * Verifies the nonce and capability, then calls Notifications::send_alert()
	 * with a test payload. Returns a JSON response with success/error message.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function ajax_send_test_email(): void {
		check_ajax_referer( 'wp_claw_test_email', 'nonce' );

		if ( ! current_user_can( 'wp_claw_manage_settings' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'claw-agent' ) );
		}

		$result = \WPClaw\Notifications::send_alert(
			'test',
			array(
				'agent'   => 'system',
				'details' => array( 'message' => 'This is a test notification from WP-Claw.' ),
			)
		);

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Test email sent!', 'claw-agent' ) ) );
		} else {
			wp_send_json_error( __( 'Failed to send. Check your email configuration.', 'claw-agent' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Admin bar
	// -------------------------------------------------------------------------

	/**
	 * Add a connection-status badge to the WordPress admin bar.
	 *
	 * The badge uses a colored dot (green = connected, yellow = degraded,
	 * red = disconnected) so site administrators can see agent status at a glance
	 * from any admin screen.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Admin_Bar $admin_bar The admin bar object.
	 *
	 * @return void
	 */
	public function add_admin_bar_node( \WP_Admin_Bar $admin_bar ): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			return;
		}

		// Cache connection status for 5 minutes to avoid transient reads on every page load.
		$cached = get_transient( 'wp_claw_admin_bar_status' );

		if ( is_array( $cached ) ) {
			$dot_color = $cached['dot_color'];
			$label     = $cached['label'];
		} else {
			$connected = $this->api_client->is_connected();

			// Transient holds detailed health data set by health_check().
			$health = get_transient( 'wp_claw_health_data' );

			if ( $connected && ! empty( $health['status'] ) && 'degraded' === $health['status'] ) {
				$dot_color = '#f59e0b'; // amber — degraded.
				$label     = 'WP-Claw: Degraded';
			} elseif ( $connected ) {
				$dot_color = '#10b981'; // green — healthy.
				$label     = 'WP-Claw: Connected';
			} else {
				$dot_color = '#ef4444'; // red — disconnected.
				$label     = 'WP-Claw: Disconnected';
			}

			set_transient(
				'wp_claw_admin_bar_status',
				array(
					'dot_color' => $dot_color,
					'label'     => $label,
				),
				5 * MINUTE_IN_SECONDS
			);
		}

		// Translate after cache retrieval so i18n is applied at render time.
		$label = __( $label, 'claw-agent' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText

		$dot = sprintf(
			'<span style="display:inline-block;width:8px;height:8px;border-radius:50%%'
			. ';background:%s;margin-right:6px;vertical-align:middle;" aria-hidden="true"></span>',
			esc_attr( $dot_color )
		);

		$admin_bar->add_node(
			array(
				'id'    => 'wp-claw-status',
				'title' => $dot . esc_html( $label ),
				'href'  => admin_url( 'admin.php?page=wp-claw' ),
				'meta'  => array(
					'title' => esc_attr( $label ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Activation redirect
	// -------------------------------------------------------------------------

	/**
	 * Redirect to the settings page immediately after plugin activation.
	 *
	 * Only redirects once (transient is deleted on read). Does not redirect
	 * during bulk activation (WP sets 'activate-multi' query arg in that case).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'wp_claw_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'wp_claw_activation_redirect' );

		// Never redirect during a bulk activation.
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wp-claw-settings' ) );
		exit;
	}

	/**
	 * Re-add capabilities if they are missing after a file-based upgrade.
	 *
	 * When plugin files are replaced without deactivate/activate (e.g. FTP upload
	 * or auto-update), the activation hook never fires and new capabilities are
	 * never registered. This method checks once per plugin version and adds any
	 * missing capabilities.
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function maybe_recover_capabilities(): void {
		// Quick check: does the current admin have the core WP-Claw cap?
		// If yes, caps are fine — skip the expensive role lookup.
		$user = wp_get_current_user();
		if ( $user->exists() && $user->has_cap( 'manage_options' ) && $user->has_cap( 'wp_claw_command_center' ) ) {
			return;
		}

		// Caps are missing — add them now.
		if ( function_exists( 'wp_claw_add_capabilities' ) ) {
			wp_claw_add_capabilities();
		}

		// Force refresh the current user's capabilities in this request.
		// add_cap() writes to wp_options but the WP_User object was already
		// loaded — without this, current_user_can() still returns false until
		// the NEXT page load.
		if ( $user->exists() ) {
			$user->get_role_caps();
		}
	}

	// -------------------------------------------------------------------------
	// Clear local data handler
	// -------------------------------------------------------------------------

	/**
	 * Handle the "Clear Local Task Log" form submission.
	 *
	 * Verifies the nonce and capability, then truncates the wp_claw_tasks
	 * and wp_claw_proposals tables. Redirects back to the settings page
	 * with a success or error admin notice query arg.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_clear_local_data(): void {
		// Verify nonce — action name must match wp_nonce_field() in settings.php.
		check_admin_referer( 'wp_claw_clear_local_data', 'wp_claw_clear_nonce' );

		// Verify capability.
		if ( ! wp_claw_current_user_can( 'manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'claw-agent' ), 403 );
		}

		global $wpdb;

		$tasks_table     = $wpdb->prefix . 'wp_claw_tasks';
		$proposals_table = $wpdb->prefix . 'wp_claw_proposals';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional bulk delete of local log data.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tasks_table ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $proposals_table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_claw_log( 'Local task log and proposal history cleared by admin.', 'info' );

		wp_safe_redirect(
			add_query_arg(
				'wp_claw_cleared',
				'1',
				admin_url( 'admin.php?page=wp-claw-settings' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// View renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the Dashboard admin page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		$api_client = $this->api_client;
		include WP_CLAW_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Render the Agents admin page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_agents(): void {
		if ( ! current_user_can( 'wp_claw_manage_agents' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		$api_client = $this->api_client;
		include WP_CLAW_PLUGIN_DIR . 'admin/views/agents.php';
	}

	/**
	 * Render the Proposals admin page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_proposals(): void {
		if ( ! current_user_can( 'wp_claw_approve_proposals' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/proposals.php';
	}

	/**
	 * Render the Settings admin page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'wp_claw_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		$api_client = $this->api_client;
		include WP_CLAW_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the Modules admin page (removed in v1.2.0).
	 *
	 * Redirects to the main dashboard. The modules page was merged into the
	 * dashboard and domain dashboards. This method is kept for users who have
	 * bookmarked the old URL.
	 *
	 * @since 1.0.0
	 * @deprecated 1.2.0 Merged into Dashboard.
	 *
	 * @return void
	 */
	public function render_modules(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=claw-agent' ) );
		exit;
	}

	/**
	 * Render the Security dashboard page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_security(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/security.php';
	}

	/**
	 * Render the Commerce & CRM dashboard page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_commerce(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/commerce.php';
	}

	/**
	 * Render the SEO & Content dashboard page.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render_seo_content(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/seo-content.php';
	}

	/**
	 * Render the Analytics admin page.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function render_analytics(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/analytics.php';
	}

	/**
	 * Render the Command Center admin page.
	 *
	 * The view performs its own capability check via wp_claw_current_user_can().
	 * This method mirrors the pattern of all other render_* methods in this class.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_command_center_page(): void {
		if ( ! current_user_can( 'wp_claw_command_center' ) ) {
			wp_die( esc_html__( 'You do not have permission to access the Command Center.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/command-center.php';
	}

	/**
	 * Render the Reports admin page.
	 *
	 * Displays a filterable timeline of agent activity reports fetched from
	 * the Klawty REST API. Actual data is loaded asynchronously by the JS
	 * initReportsPage() function.
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function render_reports(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/reports.php';
	}

	/**
	 * Render the Backup management page.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_backup(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/backup.php';
	}

	/**
	 * Render the Chat management page.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_chat(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/chat.php';
	}

	/**
	 * Render the Social management page.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_social(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/social.php';
	}

	/**
	 * Render the Forms management page.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public function render_forms(): void {
		if ( ! current_user_can( 'wp_claw_view_dashboard' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'claw-agent' ) );
		}
		include WP_CLAW_PLUGIN_DIR . 'admin/views/forms.php';
	}

	// -------------------------------------------------------------------------
	// Settings section descriptions
	// -------------------------------------------------------------------------

	/**
	 * Render the description for the Connection settings section.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_connection_section_description(): void {
		echo '<p>' . esc_html__(
			'Connect WP-Claw to your Klawty AI instance. Choose managed (recommended) to use a dcode-hosted instance, or self-hosted to connect to a local Klawty installation.',
			'claw-agent'
		) . '</p>';
	}

	/**
	 * Render the description for the Chat Widget settings section.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_chat_section_description(): void {
		echo '<p>' . esc_html__(
			'Configure the Concierge agent chat widget that appears on your site\'s frontend. Requires the Chat module to be enabled.',
			'claw-agent'
		) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Settings field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the API key settings field.
	 *
	 * The stored value is always encrypted; the field shows a placeholder
	 * so the actual ciphertext is never displayed to the user.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_api_key(): void {
		$has_key = ! empty( get_option( 'wp_claw_api_key', '' ) );
		?>
		<input
			type="password"
			id="wp_claw_api_key"
			name="wp_claw_api_key"
			value=""
			class="regular-text"
			autocomplete="new-password"
			placeholder="<?php echo esc_attr( $has_key ? __( '(stored — enter new value to change)', 'claw-agent' ) : __( 'Enter your Klawty API key', 'claw-agent' ) ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Your Klawty API key. Stored encrypted. Never sent to any third party.', 'claw-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the connection mode settings field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_connection_mode(): void {
		$mode = sanitize_text_field( (string) get_option( 'wp_claw_connection_mode', 'managed' ) );
		?>
		<select id="wp_claw_connection_mode" name="wp_claw_connection_mode">
			<option value="managed" <?php selected( $mode, 'managed' ); ?>>
				<?php esc_html_e( 'Managed (dcode-hosted)', 'claw-agent' ); ?>
			</option>
			<option value="self-hosted" <?php selected( $mode, 'self-hosted' ); ?>>
				<?php esc_html_e( 'Self-hosted (local Klawty)', 'claw-agent' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Managed connects to your dcode-hosted Klawty instance. Self-hosted connects to a local Klawty instance (e.g. localhost:2508).', 'claw-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the instance URL settings field.
	 *
	 * Shows for both managed and self-hosted modes. In managed mode, this URL
	 * is normally set automatically by the connection handshake. If empty, the
	 * user can enter it manually (e.g. https://kl-{id}.ai-agent-builder.ai).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_instance_url(): void {
		$url  = esc_url( (string) get_option( 'wp_claw_instance_url', '' ) );
		$mode = (string) get_option( 'wp_claw_connection_mode', 'managed' );
		?>
		<input
			type="url"
			id="wp_claw_instance_url"
			name="wp_claw_instance_url"
			value="<?php echo esc_attr( $url ); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr( 'managed' === $mode ? 'https://kl-xxxx.ai-agent-builder.ai' : 'http://localhost:2508' ); ?>"
		>
		<p class="description">
			<?php if ( 'managed' === $mode ) : ?>
				<?php esc_html_e( 'Your managed Klawty instance URL. Normally set automatically during connection. If empty, enter it manually.', 'claw-agent' ); ?>
				<?php if ( '' === $url ) : ?>
					<br><strong style="color: #dc2626;"><?php esc_html_e( 'Instance URL is empty — agents cannot connect. Enter your instance URL above and save.', 'claw-agent' ); ?></strong>
				<?php endif; ?>
			<?php else : ?>
				<?php esc_html_e( 'The base URL of your local Klawty instance.', 'claw-agent' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Render the chat enabled toggle field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_chat_enabled(): void {
		$enabled = (bool) get_option( 'wp_claw_chat_enabled', false );
		?>
		<label for="wp_claw_chat_enabled">
			<input
				type="checkbox"
				id="wp_claw_chat_enabled"
				name="wp_claw_chat_enabled"
				value="1"
				<?php checked( $enabled ); ?>
			>
			<?php esc_html_e( 'Show the AI chat widget on the frontend', 'claw-agent' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Requires the Chat module to be enabled and a connected Klawty instance.', 'claw-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the chat widget position field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_chat_position(): void {
		$position = sanitize_text_field( (string) get_option( 'wp_claw_chat_position', 'bottom-right' ) );
		?>
		<select id="wp_claw_chat_position" name="wp_claw_chat_position">
			<option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>>
				<?php esc_html_e( 'Bottom right', 'claw-agent' ); ?>
			</option>
			<option value="bottom-left" <?php selected( $position, 'bottom-left' ); ?>>
				<?php esc_html_e( 'Bottom left', 'claw-agent' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Render the chat welcome message field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_chat_welcome(): void {
		$welcome = sanitize_text_field( (string) get_option( 'wp_claw_chat_welcome', __( 'Hi! How can I help you today?', 'claw-agent' ) ) );
		?>
		<input
			type="text"
			id="wp_claw_chat_welcome"
			name="wp_claw_chat_welcome"
			value="<?php echo esc_attr( $welcome ); ?>"
			class="regular-text"
		>
		<p class="description">
			<?php esc_html_e( 'The opening message shown to visitors when the chat widget loads.', 'claw-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the chat agent display name field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_chat_agent_name(): void {
		$name = sanitize_text_field( (string) get_option( 'wp_claw_chat_agent_name', __( 'Assistant', 'claw-agent' ) ) );
		?>
		<input
			type="text"
			id="wp_claw_chat_agent_name"
			name="wp_claw_chat_agent_name"
			value="<?php echo esc_attr( $name ); ?>"
			class="regular-text"
			maxlength="60"
		>
		<p class="description">
			<?php esc_html_e( 'The name displayed in the chat widget header (e.g. "Emma", "Support Bot").', 'claw-agent' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the analytics enabled toggle field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_field_analytics_enabled(): void {
		$enabled = (bool) get_option( 'wp_claw_analytics_enabled', false );
		?>
		<label for="wp_claw_analytics_enabled">
			<input
				type="checkbox"
				id="wp_claw_analytics_enabled"
				name="wp_claw_analytics_enabled"
				value="1"
				<?php checked( $enabled ); ?>
			>
			<?php esc_html_e( 'Enable privacy-first pageview and event tracking', 'claw-agent' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Data is stored locally in your WordPress database. No data is sent to external analytics services.', 'claw-agent' ); ?>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Sanitize callbacks
	// -------------------------------------------------------------------------

	/**
	 * Sanitize the API key before saving to the database.
	 *
	 * If the submitted value is empty (user left the field blank to keep
	 * the current key), the existing encrypted value is returned unchanged.
	 * Otherwise the new value is encrypted via wp_claw_encrypt().
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw_value The raw value submitted via the settings form.
	 *
	 * @return string Encrypted ciphertext, or empty string if encryption fails.
	 */
	public function sanitize_api_key( ?string $raw_value = null ): string {
		$raw_value = trim( (string) $raw_value );

		if ( '' === $raw_value ) {
			// User left field blank or field not in form — preserve the existing key.
			return (string) get_option( 'wp_claw_api_key', '' );
		}

		$connection_mode = (string) get_option( 'wp_claw_connection_mode', 'managed' );

		// In managed mode, treat the pasted value as a connection token
		// and exchange it for a permanent API key via the verify handshake.
		if ( 'managed' === $connection_mode ) {
			$verified = $this->verify_connection_token( $raw_value );
			if ( is_wp_error( $verified ) ) {
				add_settings_error(
					'wp_claw_api_key',
					'verify_failed',
					sprintf(
						/* translators: %s: error message from verify endpoint */
						__( 'Connection token verification failed: %s. Token saved as-is.', 'claw-agent' ),
						$verified->get_error_message()
					)
				);
				// Fall through to encrypt the raw token as fallback.
			} else {
				$raw_value = $verified;
			}
		}

		$encrypted = wp_claw_encrypt( $raw_value );

		if ( '' === $encrypted ) {
			// Encryption unavailable — store plaintext (API client has fallback)
			return $raw_value;
		}

		return $encrypted;
	}

	/**
	 * Exchange a connection token for a permanent API key via wp-claw.ai.
	 *
	 * Calls POST wp-claw.ai/api/connect/verify with the token and site info.
	 * On success, stores the webhook secret and instance endpoint, and
	 * returns the permanent API key. On failure, returns a WP_Error.
	 *
	 * @since 1.3.0
	 *
	 * @param string $token The connection token from the dashboard.
	 *
	 * @return string|\WP_Error Permanent API key on success, WP_Error on failure.
	 */
	private function verify_connection_token( string $token ) {
		$site_url   = home_url();
		$server_ip  = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '0.0.0.0';
		$site_hash  = hash( 'sha256', $site_url . $token . $server_ip );

		$verify_url = 'https://wp-claw.ai/api/connect/verify';

		$response = wp_remote_post( $verify_url, array(
			'timeout' => 15,
			'body'    => wp_json_encode( array(
				'token'       => $token,
				'site_url'    => $site_url,
				'site_hash'   => $site_hash,
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
			) ),
			'headers' => array(
				'Content-Type'       => 'application/json',
				'X-WPClaw-Site-Hash' => $site_hash,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['api_key'] ) ) {
			$error_msg = isset( $body['error'] ) ? $body['error'] : "HTTP {$code}";
			return new \WP_Error( 'verify_failed', $error_msg );
		}

		// Store webhook secret (encrypted).
		if ( ! empty( $body['webhook_secret'] ) ) {
			$encrypted_secret = wp_claw_encrypt( $body['webhook_secret'] );
			if ( '' !== $encrypted_secret ) {
				update_option( 'wp_claw_webhook_secret', $encrypted_secret );
			}
		}

		// Store the Klawty proxy endpoint (the URL the plugin should talk to).
		if ( ! empty( $body['klawty_endpoint'] ) ) {
			update_option( 'wp_claw_instance_url', esc_url_raw( $body['klawty_endpoint'] ) );
		}

		return $body['api_key'];
	}

	/**
	 * Sanitize the instance URL setting.
	 *
	 * Preserves the existing value when the field is not present in the
	 * submitted form (multiple forms share the same settings group).
	 *
	 * @since 1.2.2
	 *
	 * @param string|null $value The raw submitted value.
	 *
	 * @return string Sanitized URL or existing value.
	 */
	public function sanitize_instance_url( ?string $value = null ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			// Field was empty or not in the form — keep existing.
			return (string) get_option( 'wp_claw_instance_url', '' );
		}

		return esc_url_raw( $value );
	}

	/**
	 * Sanitize the connection mode setting.
	 *
	 * Only 'managed' and 'self-hosted' are accepted; any other value
	 * falls back to 'managed'.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The raw submitted value.
	 *
	 * @return string Sanitized connection mode.
	 */
	public function sanitize_connection_mode( ?string $value = null ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return (string) get_option( 'wp_claw_connection_mode', 'managed' );
		}
		$allowed = array( 'managed', 'self-hosted' );
		return in_array( $value, $allowed, true ) ? $value : 'managed';
	}

	/**
	 * Sanitize the chat widget position setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value The raw submitted value.
	 *
	 * @return string Sanitized position.
	 */
	public function sanitize_chat_position( ?string $value = null ): string {
		$allowed = array( 'bottom-right', 'bottom-left' );
		return in_array( $value, $allowed, true ) ? $value : 'bottom-right';
	}

	/**
	 * Sanitize the enabled modules array.
	 *
	 * Each module slug is validated against the known module list.
	 * Unknown or malformed slugs are silently dropped.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw submitted value (should be an array).
	 *
	 * @return string[] Sanitized array of valid module slugs.
	 */
	public function sanitize_enabled_modules( $value ): array {
		if ( null === $value ) {
			// Field not in submitted form — keep existing modules.
			return (array) get_option( 'wp_claw_enabled_modules', array() );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$known_modules = array(
			'audit',
			'seo',
			'security',
			'content',
			'crm',
			'commerce',
			'performance',
			'forms',
			'analytics',
			'backup',
			'social',
			'chat',
		);

		$sanitized = array();
		foreach ( $value as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $slug, $known_modules, true ) ) {
				$sanitized[] = $slug;
			}
		}

		return array_unique( $sanitized );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler — save the Business Profile to WP options.
	 *
	 * Accepts the 7 business profile fields, sanitizes each, persists to the
	 * `wp_claw_business_profile` option, and returns JSON success/error.
	 *
	 * Called via: wp_ajax_wp_claw_save_profile (admin only).
	 *
	 * @since 1.2.2
	 *
	 * @return void
	 */
	public function ajax_save_profile(): void {
		check_ajax_referer( 'wp_claw_save_profile', '_wpnonce' );

		if ( ! current_user_can( 'wp_claw_manage_settings' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$fields  = array( 'business_name', 'industry', 'description', 'owner_role', 'top_goal', 'never_do', 'extra_context' );
		$profile = array();

		foreach ( $fields as $f ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_ajax_referer.
			$profile[ $f ] = isset( $_POST[ $f ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $f ] ) ) : '';
		}

		$profile['updated_at'] = current_time( 'c' );

		update_option( 'wp_claw_business_profile', $profile );

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether the given hook suffix belongs to a WP-Claw admin page.
	 *
	 * Compares against the stored page hook suffixes registered by add_menu_page()
	 * and add_submenu_page(). Falls back to a string prefix check to handle
	 * edge cases where hook suffixes haven't been populated yet.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The hook suffix from admin_enqueue_scripts.
	 *
	 * @return bool True if this is a WP-Claw page.
	 */
	private function is_wp_claw_page( string $hook_suffix ): bool {
		if ( in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return true;
		}

		// Fallback: hook suffixes for submenus contain the parent slug.
		return false !== strpos( $hook_suffix, 'claw-agent' );
	}
}
