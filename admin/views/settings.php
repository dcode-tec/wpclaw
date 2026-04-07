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

		<!-- Application Password section (self-hosted mode only) -->
		<div
			id="wp-claw-app-password-section"
			style="<?php echo esc_attr( 'self-hosted' === $connection_mode ? '' : 'display:none;' ); ?>margin-top:16px;padding:16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;"
		>
			<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Application Password — Self-Hosted Auth', 'claw-agent' ); ?></h3>
			<p class="wpc-kpi-label" style="margin-bottom:12px;">
				<?php esc_html_e( 'Generate a WordPress Application Password for your Klawty instance to authenticate requests. The password is shown only once — copy it to your Klawty instance configuration.', 'claw-agent' ); ?>
			</p>
			<button
				type="button"
				class="wpc-btn wpc-btn--secondary"
				id="wp-claw-generate-app-password"
			>
				<?php esc_html_e( 'Generate Application Password', 'claw-agent' ); ?>
			</button>
			<div id="wp-claw-app-password-result" style="display:none;margin-top:12px;">
				<p class="wpc-kpi-label" style="color:#d97706;font-weight:600;margin-bottom:6px;">
					&#9888; <?php esc_html_e( 'Copy this password now — it will not be shown again.', 'claw-agent' ); ?>
				</p>
				<code
					id="wp-claw-app-password-value"
					style="display:block;padding:8px 12px;background:#fff;border:1px solid #d1d5db;border-radius:4px;font-size:14px;word-break:break-all;"
					aria-live="polite"
				></code>
			</div>
		</div>
	</section>

	<!-- Business Profile -->
	<?php
	$_wpc_prof_check = get_option( 'wp_claw_business_profile', array() );
	$_wpc_prof_is_empty = empty( $_wpc_prof_check['business_name'] ) && empty( $_wpc_prof_check['description'] );
	?>
	<section class="wpc-card" style="margin-top: 20px;<?php echo $_wpc_prof_is_empty ? ' border: 2px solid #f59e0b; box-shadow: 0 0 0 4px rgba(245,158,11,0.15);' : ''; ?>" id="wpc-business-profile-section">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Your Business', 'claw-agent' ); ?></h2>
		<?php if ( $_wpc_prof_is_empty ) : ?>
		<div class="wpc-alert-banner wpc-alert-banner--warning" style="margin-bottom: 16px;">
			<strong>📋 <?php esc_html_e( 'Complete this section', 'claw-agent' ); ?></strong> &mdash;
			<?php esc_html_e( 'Your AI agents are working without knowing your business. Fill in at least your business name and description for better, more relevant results.', 'claw-agent' ); ?>
		</div>
		<?php endif; ?>
		<p class="wpc-kpi-label" style="margin-bottom: 16px;">
			<?php esc_html_e( 'Help your AI agents understand your business. This is synced to your Klawty instance.', 'claw-agent' ); ?>
		</p>
		<?php $profile = get_option( 'wp_claw_business_profile', array() ); ?>
		<form id="wpc-business-profile-form">
			<?php wp_nonce_field( 'wp_claw_save_profile', 'wp_claw_profile_nonce' ); ?>
			<table class="wpc-agent-table" style="margin-bottom: 16px;">
				<tbody>
					<tr>
						<td style="width: 200px;"><label for="wpc-biz-name"><strong><?php esc_html_e( 'Business Name', 'claw-agent' ); ?></strong></label></td>
						<td><input type="text" id="wpc-biz-name" name="business_name" value="<?php echo esc_attr( isset( $profile['business_name'] ) ? $profile['business_name'] : '' ); ?>" maxlength="200" style="width: 100%;"></td>
					</tr>
					<tr>
						<td><label for="wpc-biz-industry"><strong><?php esc_html_e( 'Industry / Sector', 'claw-agent' ); ?></strong></label></td>
						<td><input type="text" id="wpc-biz-industry" name="industry" value="<?php echo esc_attr( isset( $profile['industry'] ) ? $profile['industry'] : '' ); ?>" maxlength="200" style="width: 100%;"></td>
					</tr>
					<tr>
						<td><label for="wpc-biz-desc"><strong><?php esc_html_e( 'What does your business do?', 'claw-agent' ); ?></strong></label></td>
						<td><textarea id="wpc-biz-desc" name="description" rows="3" maxlength="1000" style="width: 100%;"><?php echo esc_textarea( isset( $profile['description'] ) ? $profile['description'] : '' ); ?></textarea></td>
					</tr>
					<tr>
						<td><label for="wpc-biz-role"><strong><?php esc_html_e( 'Your role', 'claw-agent' ); ?></strong></label></td>
						<td><input type="text" id="wpc-biz-role" name="owner_role" value="<?php echo esc_attr( isset( $profile['owner_role'] ) ? $profile['owner_role'] : '' ); ?>" maxlength="200" style="width: 100%;"></td>
					</tr>
					<tr>
						<td><label for="wpc-biz-goal"><strong><?php esc_html_e( 'Top goal for your AI team', 'claw-agent' ); ?></strong></label></td>
						<td><textarea id="wpc-biz-goal" name="top_goal" rows="2" maxlength="500" style="width: 100%;"><?php echo esc_textarea( isset( $profile['top_goal'] ) ? $profile['top_goal'] : '' ); ?></textarea></td>
					</tr>
					<tr>
						<td><label for="wpc-biz-never"><strong><?php esc_html_e( 'What should agents NEVER do?', 'claw-agent' ); ?></strong></label></td>
						<td><textarea id="wpc-biz-never" name="never_do" rows="2" maxlength="500" style="width: 100%;"><?php echo esc_textarea( isset( $profile['never_do'] ) ? $profile['never_do'] : '' ); ?></textarea></td>
					</tr>
					<tr>
						<td><label for="wpc-biz-extra"><strong><?php esc_html_e( 'Anything else agents should know?', 'claw-agent' ); ?></strong></label></td>
						<td><textarea id="wpc-biz-extra" name="extra_context" rows="3" maxlength="1000" style="width: 100%;"><?php echo esc_textarea( isset( $profile['extra_context'] ) ? $profile['extra_context'] : '' ); ?></textarea></td>
					</tr>
				</tbody>
			</table>
			<button type="submit" class="wpc-btn wpc-btn--primary" id="wpc-save-profile"><?php esc_html_e( 'Save Business Profile', 'claw-agent' ); ?></button>
			<span id="wpc-profile-status" style="margin-left: 12px;" aria-live="polite"></span>
		</form>
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

	<!-- Notifications (v1.3.0) -->
	<?php $notif_settings = \WPClaw\Notifications::get_settings(); ?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Notifications', 'claw-agent' ); ?></h2>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Configure how and when WP-Claw sends email alerts and digest reports.', 'claw-agent' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_settings' ); ?>

			<table class="wpc-agent-table">
				<tbody>

					<!-- Email notifications master switch -->
					<tr>
						<td><label for="wpc-notif-enabled"><strong><?php esc_html_e( 'Email notifications', 'claw-agent' ); ?></strong></label></td>
						<td>
							<label for="wpc-notif-enabled" style="display:inline-block;cursor:pointer;">
								<div class="wpc-toggle-switch">
									<input
										type="checkbox"
										id="wpc-notif-enabled"
										name="wp_claw_notification_settings[enabled]"
										value="1"
										<?php checked( ! empty( $notif_settings['enabled'] ) ); ?>
									>
									<span class="wpc-toggle-switch__slider"></span>
								</div>
							</label>
						</td>
					</tr>

					<!-- Notification email -->
					<tr>
						<td><label for="wpc-notif-email"><?php esc_html_e( 'Notification email', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="email"
								id="wpc-notif-email"
								name="wp_claw_notification_settings[email]"
								value="<?php echo esc_attr( isset( $notif_settings['email'] ) ? $notif_settings['email'] : '' ); ?>"
								placeholder="<?php esc_attr_e( 'defaults to admin email', 'claw-agent' ); ?>"
								style="width:100%;max-width:360px"
							>
						</td>
					</tr>

					<!-- Real-time alerts -->
					<tr>
						<td><label for="wpc-notif-realtime"><?php esc_html_e( 'Real-time alerts', 'claw-agent' ); ?></label></td>
						<td>
							<label for="wpc-notif-realtime" style="display:inline-block;cursor:pointer;">
								<div class="wpc-toggle-switch">
									<input
										type="checkbox"
										id="wpc-notif-realtime"
										name="wp_claw_notification_settings[realtime_alerts]"
										value="1"
										<?php checked( ! empty( $notif_settings['realtime_alerts'] ) ); ?>
									>
									<span class="wpc-toggle-switch__slider"></span>
								</div>
							</label>
						</td>
					</tr>

					<!-- Daily digest -->
					<tr>
						<td><label for="wpc-notif-daily-digest"><?php esc_html_e( 'Daily digest', 'claw-agent' ); ?></label></td>
						<td>
							<label for="wpc-notif-daily-digest" style="display:inline-block;cursor:pointer;">
								<div class="wpc-toggle-switch">
									<input
										type="checkbox"
										id="wpc-notif-daily-digest"
										name="wp_claw_notification_settings[daily_digest]"
										value="1"
										<?php checked( ! empty( $notif_settings['daily_digest'] ) ); ?>
									>
									<span class="wpc-toggle-switch__slider"></span>
								</div>
							</label>
						</td>
					</tr>

					<!-- Delivery time -->
					<tr>
						<td><label for="wpc-notif-digest-hour"><?php esc_html_e( 'Delivery time (hour)', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wpc-notif-digest-hour"
								name="wp_claw_notification_settings[digest_hour]"
								value="<?php echo esc_attr( isset( $notif_settings['digest_hour'] ) ? (int) $notif_settings['digest_hour'] : 8 ); ?>"
								min="0"
								max="23"
								style="width:80px"
							>
							<span class="wpc-kpi-label" style="margin-left:8px;"><?php esc_html_e( '(0–23, site time)', 'claw-agent' ); ?></span>
						</td>
					</tr>

					<!-- Digest format -->
					<tr>
						<td><label for="wpc-notif-digest-format"><?php esc_html_e( 'Digest format', 'claw-agent' ); ?></label></td>
						<td>
							<select id="wpc-notif-digest-format" name="wp_claw_notification_settings[digest_format]">
								<option value="html" <?php selected( isset( $notif_settings['digest_format'] ) ? $notif_settings['digest_format'] : 'html', 'html' ); ?>>
									<?php esc_html_e( 'Rich HTML', 'claw-agent' ); ?>
								</option>
								<option value="text" <?php selected( isset( $notif_settings['digest_format'] ) ? $notif_settings['digest_format'] : 'html', 'text' ); ?>>
									<?php esc_html_e( 'Plain text', 'claw-agent' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<!-- Weekly report -->
					<tr>
						<td><label for="wpc-notif-weekly-report"><?php esc_html_e( 'Weekly report', 'claw-agent' ); ?></label></td>
						<td>
							<label for="wpc-notif-weekly-report" style="display:inline-block;cursor:pointer;">
								<div class="wpc-toggle-switch">
									<input
										type="checkbox"
										id="wpc-notif-weekly-report"
										name="wp_claw_notification_settings[weekly_report]"
										value="1"
										<?php checked( ! empty( $notif_settings['weekly_report'] ) ); ?>
									>
									<span class="wpc-toggle-switch__slider"></span>
								</div>
							</label>
						</td>
					</tr>

					<!-- Weekly day -->
					<tr>
						<td><label for="wpc-notif-weekly-day"><?php esc_html_e( 'Weekly report day', 'claw-agent' ); ?></label></td>
						<td>
							<?php
							$_wpc_days = array(
								0 => __( 'Monday', 'claw-agent' ),
								1 => __( 'Tuesday', 'claw-agent' ),
								2 => __( 'Wednesday', 'claw-agent' ),
								3 => __( 'Thursday', 'claw-agent' ),
								4 => __( 'Friday', 'claw-agent' ),
								5 => __( 'Saturday', 'claw-agent' ),
								6 => __( 'Sunday', 'claw-agent' ),
							);
							$_wpc_weekly_day = isset( $notif_settings['weekly_day'] ) ? (int) $notif_settings['weekly_day'] : 0;
							?>
							<select id="wpc-notif-weekly-day" name="wp_claw_notification_settings[weekly_day]">
								<?php foreach ( $_wpc_days as $_wpc_day_val => $_wpc_day_label ) : ?>
									<option value="<?php echo esc_attr( (string) $_wpc_day_val ); ?>" <?php selected( $_wpc_weekly_day, $_wpc_day_val ); ?>>
										<?php echo esc_html( $_wpc_day_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<!-- Weekly time -->
					<tr>
						<td><label for="wpc-notif-weekly-hour"><?php esc_html_e( 'Weekly report time (hour)', 'claw-agent' ); ?></label></td>
						<td>
							<input
								type="number"
								id="wpc-notif-weekly-hour"
								name="wp_claw_notification_settings[weekly_hour]"
								value="<?php echo esc_attr( isset( $notif_settings['weekly_hour'] ) ? (int) $notif_settings['weekly_hour'] : 9 ); ?>"
								min="0"
								max="23"
								style="width:80px"
							>
							<span class="wpc-kpi-label" style="margin-left:8px;"><?php esc_html_e( '(0–23, site time)', 'claw-agent' ); ?></span>
						</td>
					</tr>

				</tbody>
			</table>

			<!-- Per-agent mute toggles -->
			<h3 style="margin-top:24px;margin-bottom:8px;font-size:14px;font-weight:600;">
				<?php esc_html_e( 'Mute notifications per agent', 'claw-agent' ); ?>
			</h3>
			<p class="wpc-kpi-label" style="margin-bottom:12px;">
				<?php esc_html_e( 'Checked agents will NOT send notifications. Uncheck to receive their alerts.', 'claw-agent' ); ?>
			</p>

			<?php
			$_wpc_muted_agents = isset( $notif_settings['muted_agents'] ) && is_array( $notif_settings['muted_agents'] )
				? $notif_settings['muted_agents']
				: array();
			$_wpc_agents = array(
				array( 'slug' => 'architect', 'name' => 'Karim', 'role' => __( 'The Architect', 'claw-agent' ) ),
				array( 'slug' => 'scribe',    'name' => 'Lina',  'role' => __( 'The Scribe', 'claw-agent' ) ),
				array( 'slug' => 'sentinel',  'name' => 'Bastien', 'role' => __( 'The Sentinel', 'claw-agent' ) ),
				array( 'slug' => 'commerce',  'name' => 'Hugo',  'role' => __( 'Commerce Lead', 'claw-agent' ) ),
				array( 'slug' => 'analyst',   'name' => 'Selma', 'role' => __( 'The Analyst', 'claw-agent' ) ),
				array( 'slug' => 'concierge', 'name' => 'Marc',  'role' => __( 'The Concierge', 'claw-agent' ) ),
			);
			?>
			<table class="wpc-agent-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Agent', 'claw-agent' ); ?></th>
						<th><?php esc_html_e( 'Role', 'claw-agent' ); ?></th>
						<th><?php esc_html_e( 'Muted', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $_wpc_agents as $_wpc_agent ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $_wpc_agent['name'] ); ?></strong>
							<span class="wpc-badge wpc-badge--idle" style="margin-left:6px;"><?php echo esc_html( $_wpc_agent['slug'] ); ?></span>
						</td>
						<td><?php echo esc_html( $_wpc_agent['role'] ); ?></td>
						<td>
							<label for="wpc-mute-<?php echo esc_attr( $_wpc_agent['slug'] ); ?>" style="display:inline-block;cursor:pointer;">
								<div class="wpc-toggle-switch">
									<input
										type="checkbox"
										id="wpc-mute-<?php echo esc_attr( $_wpc_agent['slug'] ); ?>"
										name="wp_claw_notification_settings[muted_agents][]"
										value="<?php echo esc_attr( $_wpc_agent['slug'] ); ?>"
										<?php checked( in_array( $_wpc_agent['slug'], $_wpc_muted_agents, true ) ); ?>
									>
									<span class="wpc-toggle-switch__slider"></span>
								</div>
							</label>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Notification Settings', 'claw-agent' ) ); ?>
		</form>

		<!-- Send Test Email -->
		<div style="margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;">
			<button type="button" class="wpc-btn wpc-btn--primary" id="wpc-test-email-btn">
				<?php esc_html_e( 'Send Test Email', 'claw-agent' ); ?>
			</button>
			<span id="wpc-test-email-status" style="margin-left:12px;font-size:13px;" aria-live="polite"></span>
		</div>
	</section>

	<script>
	document.getElementById('wpc-test-email-btn').addEventListener('click', function() {
		var btn = this;
		var status = document.getElementById('wpc-test-email-status');
		btn.disabled = true;
		status.textContent = '<?php echo esc_js( __( 'Sending...', 'claw-agent' ) ); ?>';

		var fd = new FormData();
		fd.append('action', 'wp_claw_send_test_email');
		fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wp_claw_test_email' ) ); ?>');

		fetch(ajaxurl, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				status.textContent = data.success ? data.data.message : (data.data || '<?php echo esc_js( __( 'Error', 'claw-agent' ) ); ?>');
				status.style.color = data.success ? '#059669' : '#dc2626';
				btn.disabled = false;
			})
			.catch(function() {
				status.textContent = '<?php echo esc_js( __( 'Network error', 'claw-agent' ) ); ?>';
				status.style.color = '#dc2626';
				btn.disabled = false;
			});
	});
	</script>

	<!-- System Status (v1.2.0) -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'System Status', 'claw-agent' ); ?></h2>

		<?php
		$is_halted     = (bool) get_option( 'wp_claw_operations_halted' );
		$t3_count      = (int) get_transient( 'wp_claw_t3_daily_count' );
		$cb_failures   = (int) get_transient( 'wp_claw_circuit_failures' );
		$cb_open_until = (int) get_transient( 'wp_claw_circuit_open_until' );
		$cb_is_open    = $cb_open_until > time();
		?>

		<table class="wpc-agent-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Circuit Breaker', 'claw-agent' ); ?></td>
					<td>
						<?php if ( $cb_is_open ) : ?>
							<span class="wpc-badge wpc-badge--error">
								<span class="wpc-status-dot wpc-status-dot--red"></span>
								<?php
								printf(
									/* translators: %s: number of seconds remaining */
									esc_html__( 'Open — retries in %s s', 'claw-agent' ),
									esc_html( (string) ( $cb_open_until - time() ) )
								);
								?>
							</span>
							<button type="button" class="wpc-btn wpc-btn--sm wpc-btn--ghost wpc-admin-reset-circuit-breaker">
								<?php esc_html_e( 'Reset Circuit Breaker', 'claw-agent' ); ?>
							</button>
						<?php elseif ( $cb_failures > 0 ) : ?>
							<span class="wpc-badge wpc-badge--pending">
								<span class="wpc-status-dot wpc-status-dot--yellow"></span>
								<?php
								printf(
									/* translators: %d: number of consecutive failures */
									esc_html__( '%d consecutive failure(s)', 'claw-agent' ),
									$cb_failures
								);
								?>
							</span>
							<button type="button" class="wpc-btn wpc-btn--sm wpc-btn--ghost wpc-admin-reset-circuit-breaker">
								<?php esc_html_e( 'Reset', 'claw-agent' ); ?>
							</button>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--active">
								<span class="wpc-status-dot wpc-status-dot--green"></span>
								<?php esc_html_e( 'Closed (healthy)', 'claw-agent' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
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
