<?php
/**
 * Settings admin view.
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

// Module slugs available for the enabled-modules checklist.
// The sanitize_enabled_modules() callback in Admin validates against this same list.
$all_modules = array(
	'seo'         => __( 'SEO', 'claw-agent' ),
	'security'    => __( 'Security', 'claw-agent' ),
	'content'     => __( 'Content', 'claw-agent' ),
	'crm'         => __( 'CRM & Leads', 'claw-agent' ),
	'commerce'    => __( 'Commerce (WooCommerce)', 'claw-agent' ),
	'performance' => __( 'Performance', 'claw-agent' ),
	'forms'       => __( 'Forms', 'claw-agent' ),
	'analytics'   => __( 'Analytics', 'claw-agent' ),
	'backup'      => __( 'Backup', 'claw-agent' ),
	'social'      => __( 'Social Media', 'claw-agent' ),
	'chat'        => __( 'Chat Widget (Concierge)', 'claw-agent' ),
);

$enabled_modules = (array) get_option( 'wp_claw_enabled_modules', array() );
$current_version = defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.0.0';
$is_connected    = $api_client->is_connected();
$connection_mode = get_option( 'wp_claw_connection_mode', 'managed' );
$api_key_set     = '' !== (string) get_option( 'wp_claw_api_key', '' );
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_settings' ); ?>

	<!-- Connection Section -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Connection', 'claw-agent' ); ?></h2>

		<div class="wpc-connection-banner <?php echo esc_attr( $is_connected ? 'wpc-connection-banner--connected' : 'wpc-connection-banner--disconnected' ); ?>">
			<?php if ( $is_connected ) : ?>
				<span class="wpc-status-dot wpc-status-dot--green"></span>
				<strong><?php esc_html_e( 'Connected', 'claw-agent' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'WP-Claw is communicating with the Klawty instance.', 'claw-agent' ); ?>
			<?php else : ?>
				<span class="wpc-status-dot wpc-status-dot--red"></span>
				<strong><?php esc_html_e( 'Not connected', 'claw-agent' ); ?></strong>
				&mdash;
				<?php esc_html_e( 'Enter your API key and instance URL below, then test the connection.', 'claw-agent' ); ?>
			<?php endif; ?>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_settings' ); ?>
			<?php do_settings_sections( 'wp-claw-settings' ); ?>

			<!-- API Key status -->
			<?php if ( $api_key_set ) : ?>
			<p>
				<span class="wpc-badge wpc-badge--done"><?php esc_html_e( 'API key configured', 'claw-agent' ); ?></span>
			</p>
			<?php endif; ?>

			<?php submit_button( __( 'Save Connection Settings', 'claw-agent' ) ); ?>
		</form>

		<p>
			<button
				type="button"
				class="wpc-btn wpc-btn--primary wpc-admin-test-connection"
				id="wp-claw-test-connection"
			>
				<?php esc_html_e( 'Test Connection', 'claw-agent' ); ?>
			</button>
			<span
				class="wpc-badge"
				id="wp-claw-test-result"
				aria-live="polite"
			></span>
		</p>
	</section>

	<!-- Modules Section -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Modules', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Enable the modules you want WP-Claw to manage. Each module maps to a Klawty AI agent.', 'claw-agent' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_settings' ); ?>

			<div class="wpc-module-grid">
				<?php foreach ( $all_modules as $slug => $label ) : ?>
				<label class="wpc-module-card" for="wp_claw_module_<?php echo esc_attr( $slug ); ?>">
					<div>
						<strong><?php echo esc_html( $label ); ?></strong>
						<?php if ( in_array( $slug, $enabled_modules, true ) ) : ?>
							<span class="wpc-badge wpc-badge--active"><?php esc_html_e( 'On', 'claw-agent' ); ?></span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'Off', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="wpc-toggle-switch">
						<input
							type="checkbox"
							id="wp_claw_module_<?php echo esc_attr( $slug ); ?>"
							name="wp_claw_enabled_modules[]"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( in_array( $slug, $enabled_modules, true ) ); ?>
						>
						<span class="wpc-toggle-switch__slider"></span>
					</div>
				</label>
				<?php endforeach; ?>
			</div>

			<?php submit_button( __( 'Save Module Settings', 'claw-agent' ) ); ?>
		</form>
	</section>

	<!-- Chat Widget Section -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Chat Widget', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Configure the Concierge chat widget appearance for your visitors.', 'claw-agent' ); ?>
		</p>

		<?php
		$chat_agent_name = get_option( 'wp_claw_chat_agent_name', 'Concierge' );
		$chat_welcome    = get_option( 'wp_claw_chat_welcome', '' );
		$chat_position   = get_option( 'wp_claw_chat_position', 'bottom-right' );
		?>

		<table class="wpc-agent-table">
			<tbody>
				<tr>
					<td>
						<label for="wp-claw-chat-name"><?php esc_html_e( 'Agent name', 'claw-agent' ); ?></label>
					</td>
					<td>
						<input
							type="text"
							id="wp-claw-chat-name"
							value="<?php echo esc_attr( $chat_agent_name ); ?>"
							readonly
						>
					</td>
				</tr>
				<tr>
					<td>
						<label for="wp-claw-chat-welcome"><?php esc_html_e( 'Welcome message', 'claw-agent' ); ?></label>
					</td>
					<td>
						<input
							type="text"
							id="wp-claw-chat-welcome"
							value="<?php echo esc_attr( $chat_welcome ); ?>"
							readonly
						>
					</td>
				</tr>
				<tr>
					<td>
						<label for="wp-claw-chat-position"><?php esc_html_e( 'Widget position', 'claw-agent' ); ?></label>
					</td>
					<td>
						<select id="wp-claw-chat-position" disabled>
							<option value="bottom-right" <?php selected( $chat_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'claw-agent' ); ?></option>
							<option value="bottom-left" <?php selected( $chat_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'claw-agent' ); ?></option>
						</select>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Chat widget settings are managed via the Modules page when the Chat module is enabled.', 'claw-agent' ); ?>
		</p>
	</section>

	<!-- Data Section -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Data Management', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Clear the local task log and proposal history. This does not affect your Klawty instance or any agent state.', 'claw-agent' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wp_claw_clear_local_data', 'wp_claw_clear_nonce' ); ?>
			<input type="hidden" name="action" value="wp_claw_clear_local_data">
			<?php
			submit_button(
				__( 'Clear Local Task Log', 'claw-agent' ),
				'delete',
				'wp-claw-clear-local-data',
				false,
				array( 'onclick' => 'return confirm(\'' . esc_js( __( 'Delete all locally logged tasks and proposals? This cannot be undone.', 'claw-agent' ) ) . '\');' )
			);
			?>
		</form>
	</section>

	<!-- Version Footer -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Plugin Version', 'claw-agent' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: version number */
				esc_html__( 'WP-Claw v%s', 'claw-agent' ),
				esc_html( $current_version )
			);
			?>
			&mdash;
			<?php esc_html_e( 'Updates are delivered through the standard WordPress plugin updater.', 'claw-agent' ); ?>
		</p>
	</section>

</div>
