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
?>
<div class="wrap wp-claw-admin-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_settings' ); ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- Main settings form (Settings API)                                   -->
	<!-- ------------------------------------------------------------------ -->
	<form method="post" action="options.php">
		<?php settings_fields( 'wp_claw_settings' ); ?>
		<?php do_settings_sections( 'wp-claw-settings' ); ?>

		<!-- Module toggles — outside the Settings API sections but inside the form -->
		<h2 class="title"><?php esc_html_e( 'Modules', 'claw-agent' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Enable the modules you want WP-Claw to manage. Each module maps to a Klawty AI agent.', 'claw-agent' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled Modules', 'claw-agent' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<?php esc_html_e( 'Enabled Modules', 'claw-agent' ); ?>
							</legend>
							<?php foreach ( $all_modules as $slug => $label ) : ?>
							<label for="wp_claw_module_<?php echo esc_attr( $slug ); ?>">
								<input
									type="checkbox"
									id="wp_claw_module_<?php echo esc_attr( $slug ); ?>"
									name="wp_claw_enabled_modules[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $enabled_modules, true ) ); ?>
								>
								<?php echo esc_html( $label ); ?>
							</label><br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button(); ?>
	</form>

	<!-- ------------------------------------------------------------------ -->
	<!-- Connection test (outside the settings form — uses JS/REST)         -->
	<!-- ------------------------------------------------------------------ -->
	<hr>

	<h2><?php esc_html_e( 'Connection', 'claw-agent' ); ?></h2>

	<p>
		<?php if ( $is_connected ) : ?>
			<span class="wp-claw-admin-status-dot wp-claw-admin-status-ok"></span>
			<strong><?php esc_html_e( 'Connected', 'claw-agent' ); ?></strong>
			&mdash;
			<?php esc_html_e( 'WP-Claw is communicating with the Klawty instance.', 'claw-agent' ); ?>
		<?php else : ?>
			<span class="wp-claw-admin-status-dot wp-claw-admin-status-disconnected"></span>
			<strong><?php esc_html_e( 'Not connected', 'claw-agent' ); ?></strong>
			&mdash;
			<?php esc_html_e( 'Enter your API key and instance URL above, then test the connection.', 'claw-agent' ); ?>
		<?php endif; ?>
	</p>

	<p>
		<button
			type="button"
			class="button button-secondary wp-claw-admin-test-connection"
			id="wp-claw-test-connection"
		>
			<?php esc_html_e( 'Test Connection', 'claw-agent' ); ?>
		</button>
		<span
			class="wp-claw-admin-test-result"
			id="wp-claw-test-result"
			style="margin-left: 10px;"
			aria-live="polite"
		></span>
	</p>

	<!-- ------------------------------------------------------------------ -->
	<!-- Plugin version / updates                                            -->
	<!-- ------------------------------------------------------------------ -->
	<hr>

	<h2><?php esc_html_e( 'Plugin Version', 'claw-agent' ); ?></h2>

	<p>
		<?php
		printf(
			/* translators: %s: version number */
			esc_html__( 'Current version: %s', 'claw-agent' ),
			'<strong>' . esc_html( $current_version ) . '</strong>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( 'WP-Claw checks for updates automatically via the WordPress update system. Updates are delivered through the standard WordPress plugin updater.', 'claw-agent' ); ?>
	</p>

	<!-- ------------------------------------------------------------------ -->
	<!-- Danger zone                                                         -->
	<!-- ------------------------------------------------------------------ -->
	<hr>

	<h2><?php esc_html_e( 'Data', 'claw-agent' ); ?></h2>

	<p class="description">
		<?php esc_html_e( 'Use the button below to clear the local task log and proposal history. This does not affect your Klawty instance or any agent state.', 'claw-agent' ); ?>
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

</div><!-- /.wrap -->
