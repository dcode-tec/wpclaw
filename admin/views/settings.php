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
	'audit'       => __( 'Site Audit (Karim)', 'claw-agent' ),
	'seo'         => __( 'SEO (Lina)', 'claw-agent' ),
	'security'    => __( 'Security (Bastien)', 'claw-agent' ),
	'content'     => __( 'Content (Lina)', 'claw-agent' ),
	'crm'         => __( 'CRM & Leads (Hugo)', 'claw-agent' ),
	'commerce'    => __( 'Commerce — WooCommerce (Hugo)', 'claw-agent' ),
	'performance' => __( 'Performance (Selma)', 'claw-agent' ),
	'forms'       => __( 'Forms (Karim)', 'claw-agent' ),
	'analytics'   => __( 'Analytics (Selma)', 'claw-agent' ),
	'backup'      => __( 'Backup (Bastien)', 'claw-agent' ),
	'social'      => __( 'Social Media (Lina)', 'claw-agent' ),
	'chat'        => __( 'Chat Widget (Marc)', 'claw-agent' ),
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
				class="wpc-btn wpc-btn--primary wpc-test-connection"
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
			<?php esc_html_e( 'Configure Marc (The Concierge) chat widget appearance for your visitors.', 'claw-agent' ); ?>
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

	<!-- Chat GDPR Configuration (v1.2.0) -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Chat — GDPR & Privacy', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Configure GDPR consent and privacy settings for Marc (The Concierge) chat widget.', 'claw-agent' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_settings' ); ?>

			<table class="wpc-agent-table">
				<tbody>
					<tr>
						<td><label for="wp-claw-consent-text"><?php esc_html_e( 'Consent message', 'claw-agent' ); ?></label></td>
						<td>
							<textarea
								id="wp-claw-consent-text"
								name="wp_claw_chat_consent_text"
								rows="3"
								style="width:100%;max-width:480px"
							><?php echo esc_textarea( get_option( 'wp_claw_chat_consent_text', '' ) ); ?></textarea>
							<p class="wpc-form-hint"><?php esc_html_e( 'Shown to visitors before they can start a chat. Leave blank to use the default.', 'claw-agent' ); ?></p>
						</td>
					</tr>
					<tr>
						<td><label for="wp-claw-privacy-url"><?php esc_html_e( 'Privacy policy URL', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="url"
								id="wp-claw-privacy-url"
								name="wp_claw_chat_privacy_url"
								value="<?php echo esc_url( get_option( 'wp_claw_chat_privacy_url', '' ) ); ?>"
								style="width:100%;max-width:480px"
								placeholder="https://example.com/privacy"
							>
						</td>
					</tr>
					<tr>
						<td><label for="wp-claw-sla-minutes"><?php esc_html_e( 'Escalation SLA (minutes)', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wp-claw-sla-minutes"
								name="wp_claw_chat_sla_minutes"
								value="<?php echo esc_attr( get_option( 'wp_claw_chat_sla_minutes', 60 ) ); ?>"
								min="5"
								max="1440"
								style="width:100px"
							>
							<p class="wpc-form-hint"><?php esc_html_e( 'If Marc cannot resolve within this time, the visitor is offered human contact.', 'claw-agent' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Chat Settings', 'claw-agent' ) ); ?>
		</form>
	</section>

	<!-- Security Configuration (v1.2.0) -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Security — Brute Force Protection', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Configure login attempt limits managed by Bastien (The Sentinel).', 'claw-agent' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_settings' ); ?>

			<table class="wpc-agent-table">
				<tbody>
					<tr>
						<td><label for="wp-claw-brute-force"><?php esc_html_e( 'Enable brute force protection', 'claw-agent' ); ?></label></td>
						<td>
							<div class="wpc-toggle-switch">
								<input
									type="checkbox"
									id="wp-claw-brute-force"
									name="wp_claw_brute_force_enabled"
									value="1"
									<?php checked( get_option( 'wp_claw_brute_force_enabled', false ) ); ?>
								>
								<span class="wpc-toggle-switch__slider"></span>
							</div>
						</td>
					</tr>
					<tr>
						<td><label for="wp-claw-max-attempts"><?php esc_html_e( 'Max login attempts', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wp-claw-max-attempts"
								name="wp_claw_brute_force_max_attempts"
								value="<?php echo esc_attr( get_option( 'wp_claw_brute_force_max_attempts', 5 ) ); ?>"
								min="1"
								max="20"
								style="width:100px"
							>
						</td>
					</tr>
					<tr>
						<td><label for="wp-claw-lockout"><?php esc_html_e( 'Lockout duration (minutes)', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wp-claw-lockout"
								name="wp_claw_brute_force_lockout_minutes"
								value="<?php echo esc_attr( get_option( 'wp_claw_brute_force_lockout_minutes', 30 ) ); ?>"
								min="5"
								max="1440"
								style="width:100px"
							>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Security Settings', 'claw-agent' ) ); ?>
		</form>
	</section>

	<!-- Backup Configuration (v1.2.0) -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Backup — Retention Policy', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Configure how long Bastien (The Sentinel) keeps backup snapshots.', 'claw-agent' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_settings' ); ?>

			<table class="wpc-agent-table">
				<tbody>
					<tr>
						<td><label for="wp-claw-daily-retention"><?php esc_html_e( 'Daily backup retention (days)', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wp-claw-daily-retention"
								name="wp_claw_backup_daily_retention"
								value="<?php echo esc_attr( get_option( 'wp_claw_backup_daily_retention', 7 ) ); ?>"
								min="1"
								max="90"
								style="width:100px"
							>
						</td>
					</tr>
					<tr>
						<td><label for="wp-claw-weekly-retention"><?php esc_html_e( 'Weekly backup retention (days)', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wp-claw-weekly-retention"
								name="wp_claw_backup_weekly_retention"
								value="<?php echo esc_attr( get_option( 'wp_claw_backup_weekly_retention', 30 ) ); ?>"
								min="7"
								max="365"
								style="width:100px"
							>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Backup Settings', 'claw-agent' ) ); ?>
		</form>
	</section>

	<!-- System Status (v1.2.0) -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'System Status', 'claw-agent' ); ?></h2>

		<?php
		$is_halted = (bool) get_option( 'wp_claw_operations_halted' );
		$t3_count  = (int) get_transient( 'wp_claw_t3_daily_count' );
		?>

		<table class="wpc-agent-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Operations Status', 'claw-agent' ); ?></td>
					<td>
						<?php if ( $is_halted ) : ?>
							<span class="wpc-badge wpc-badge--error">
								<span class="wpc-status-dot wpc-status-dot--red"></span>
								<?php esc_html_e( 'Halted', 'claw-agent' ); ?>
							</span>
							<button type="button" class="wpc-btn wpc-btn--sm wpc-btn--ghost wpc-admin-resume-ops">
								<?php esc_html_e( 'Resume Operations', 'claw-agent' ); ?>
							</button>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--active">
								<span class="wpc-status-dot wpc-status-dot--green"></span>
								<?php esc_html_e( 'Normal', 'claw-agent' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'T3 Structural Changes Today', 'claw-agent' ); ?></td>
					<td>
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $t3_count >= 4 ? 'error' : ( $t3_count >= 3 ? 'pending' : 'idle' ) ); ?>">
							<?php echo esc_html( $t3_count . '/5' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Plugin Version', 'claw-agent' ); ?></td>
					<td><?php echo esc_html( defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.0.0' ); ?></td>
				</tr>
				<?php
				if ( function_exists( 'wp_claw_encryption_diagnostic' ) ) :
					$enc_diag = wp_claw_encryption_diagnostic();
				?>
				<tr>
					<td><?php esc_html_e( 'Encryption', 'claw-agent' ); ?></td>
					<td>
						<?php if ( $enc_diag['roundtrip'] ) : ?>
							<span class="wpc-badge wpc-badge--active">
								<span class="wpc-status-dot wpc-status-dot--green"></span>
								<?php echo esc_html( $enc_diag['sodium'] ? 'Sodium' : 'OpenSSL' ); ?>
							</span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--error">
								<span class="wpc-status-dot wpc-status-dot--red"></span>
								<?php esc_html_e( 'Failed', 'claw-agent' ); ?>
							</span>
							<?php if ( '' !== $enc_diag['error'] ) : ?>
								<br><small><?php echo esc_html( $enc_diag['error'] ); ?></small>
							<?php endif; ?>
						<?php endif; ?>
						<br><small><?php echo esc_html( 'Salt: ' . $enc_diag['salt_fingerprint'] ); ?></small>
					</td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
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


</div>
