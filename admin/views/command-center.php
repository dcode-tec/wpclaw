<?php
/**
 * Command Center admin view.
 *
 * Secure chat interface for giving direct instructions to the AI agent team.
 * Commands are routed through Atlas (the orchestrator agent).
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Security: check capability.
if ( ! function_exists( 'wp_claw_current_user_can' ) || ! wp_claw_current_user_can( 'command_center' ) ) {
	wp_die( esc_html__( 'You do not have permission to access the Command Center.', 'wp-claw' ) );
}

$pin_set = \WPClaw\Command_Center::is_pin_set();
?>
<div class="wrap wp-claw-admin-wrap">

	<h1 class="wp-claw-admin-title">
		<?php esc_html_e( '🏗️ Command Center', 'wp-claw' ); ?>
	</h1>
	<p class="wp-claw-admin-subtitle">
		<?php esc_html_e( 'Give instructions directly to your AI team. Commands are routed through Atlas (orchestrator).', 'wp-claw' ); ?>
	</p>

	<?php if ( ! $pin_set ) : ?>
		<!-- ------------------------------------------------------------------ -->
		<!-- PIN Setup                                                            -->
		<!-- ------------------------------------------------------------------ -->
		<div class="wp-claw-admin-card wp-claw-cc-setup">
			<h2><?php esc_html_e( 'Set Up Command Center PIN', 'wp-claw' ); ?></h2>
			<p><?php esc_html_e( 'For security, every command requires a PIN. Choose a 4-8 digit PIN that only you know.', 'wp-claw' ); ?></p>
			<div class="wp-claw-cc-pin-setup">
				<input
					type="password"
					id="wp-claw-cc-new-pin"
					maxlength="8"
					minlength="4"
					placeholder="<?php esc_attr_e( 'Enter PIN (4-8 digits)', 'wp-claw' ); ?>"
					class="wp-claw-cc-input"
					autocomplete="new-password"
				/>
				<input
					type="password"
					id="wp-claw-cc-confirm-pin"
					maxlength="8"
					minlength="4"
					placeholder="<?php esc_attr_e( 'Confirm PIN', 'wp-claw' ); ?>"
					class="wp-claw-cc-input"
					autocomplete="new-password"
				/>
				<button type="button" id="wp-claw-cc-save-pin" class="button button-primary">
					<?php esc_html_e( 'Save PIN', 'wp-claw' ); ?>
				</button>
			</div>
			<div id="wp-claw-cc-pin-error" class="wp-claw-cc-error-msg" style="display:none;"></div>
			<?php wp_nonce_field( 'wp_claw_setup_pin', 'wp_claw_pin_nonce' ); ?>
		</div>

	<?php else : ?>
		<!-- ------------------------------------------------------------------ -->
		<!-- Command Center Chat                                                  -->
		<!-- ------------------------------------------------------------------ -->
		<div class="wp-claw-admin-card wp-claw-cc-chat-container">

			<!-- Messages area -->
			<div id="wp-claw-cc-messages" class="wp-claw-cc-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Command Center messages', 'wp-claw' ); ?>">
				<div class="wp-claw-cc-message wp-claw-cc-system">
					<span class="wp-claw-cc-badge"><?php esc_html_e( 'System', 'wp-claw' ); ?></span>
					<p><?php esc_html_e( 'Command Center active. 7 security layers verified. Type a command for your AI team.', 'wp-claw' ); ?></p>
				</div>
			</div>

			<!-- Input area -->
			<div class="wp-claw-cc-input-area">
				<div class="wp-claw-cc-pin-field">
					<label for="wp-claw-cc-pin" class="screen-reader-text">
						<?php esc_html_e( 'PIN', 'wp-claw' ); ?>
					</label>
					<input
						type="password"
						id="wp-claw-cc-pin"
						maxlength="8"
						placeholder="<?php esc_attr_e( 'PIN', 'wp-claw' ); ?>"
						class="wp-claw-cc-input wp-claw-cc-pin-input"
						autocomplete="off"
						inputmode="numeric"
					/>
				</div>
				<div class="wp-claw-cc-prompt-field">
					<label for="wp-claw-cc-prompt" class="screen-reader-text">
						<?php esc_html_e( 'Command', 'wp-claw' ); ?>
					</label>
					<input
						type="text"
						id="wp-claw-cc-prompt"
						maxlength="2000"
						placeholder="<?php esc_attr_e( 'Type a command for your AI team...', 'wp-claw' ); ?>"
						class="wp-claw-cc-input wp-claw-cc-prompt-input"
						autocomplete="off"
					/>
				</div>
				<button
					type="button"
					id="wp-claw-cc-send"
					class="button button-primary wp-claw-cc-send-btn"
					disabled
					aria-disabled="true"
				>
					<?php esc_html_e( 'Send', 'wp-claw' ); ?>
				</button>
			</div>

			<?php wp_nonce_field( 'wp_claw_command_nonce', 'wp_claw_command_nonce_field' ); ?>

			<!-- Security status bar -->
			<div class="wp-claw-cc-security-bar" aria-label="<?php esc_attr_e( 'Security status', 'wp-claw' ); ?>">
				<span class="wp-claw-cc-lock" aria-hidden="true">🔒</span>
				<span><?php esc_html_e( '7-layer security active', 'wp-claw' ); ?></span>
				<span class="wp-claw-cc-separator" aria-hidden="true">·</span>
				<span id="wp-claw-cc-rate-status"><?php esc_html_e( 'Loading rate limits...', 'wp-claw' ); ?></span>
			</div>

		</div><!-- /.wp-claw-cc-chat-container -->

		<!-- ------------------------------------------------------------------ -->
		<!-- Command History (collapsible)                                        -->
		<!-- ------------------------------------------------------------------ -->
		<div class="wp-claw-admin-card wp-claw-cc-history">

			<button
				type="button"
				class="wp-claw-cc-history-toggle"
				id="wp-claw-cc-history-toggle"
				aria-expanded="false"
				aria-controls="wp-claw-cc-history-body"
			>
				<span><?php esc_html_e( '📋 Recent Command History', 'wp-claw' ); ?></span>
				<span class="wp-claw-cc-toggle-icon" aria-hidden="true">▼</span>
			</button>

			<div
				id="wp-claw-cc-history-body"
				class="wp-claw-cc-history-body"
				style="display:none;"
				role="region"
				aria-labelledby="wp-claw-cc-history-toggle"
			>
				<table class="wp-claw-cc-history-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'wp-claw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'wp-claw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Command', 'wp-claw' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'wp-claw' ); ?></th>
						</tr>
					</thead>
					<tbody id="wp-claw-cc-history-rows">
						<tr>
							<td colspan="4"><?php esc_html_e( 'Loading...', 'wp-claw' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div><!-- /#wp-claw-cc-history-body -->

		</div><!-- /.wp-claw-cc-history -->

	<?php endif; ?>

</div><!-- /.wrap -->
