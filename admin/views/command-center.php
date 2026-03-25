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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

// Security: check capability.
if ( ! function_exists( 'wp_claw_current_user_can' ) || ! wp_claw_current_user_can( 'command_center' ) ) {
	wp_die( esc_html__( 'You do not have permission to access the Command Center.', 'claw-agent' ) );
}

$pin_set = \WPClaw\Command_Center::is_pin_set();
?>
<div class="wpc-admin-wrap">

	<header>
		<h1 class="wpc-section-heading">
			<?php esc_html_e( 'Command Center', 'claw-agent' ); ?>
		</h1>
		<p class="wpc-kpi-label">
			<?php esc_html_e( 'Give instructions directly to your AI team. Commands are routed through Atlas (orchestrator).', 'claw-agent' ); ?>
		</p>
	</header>

	<?php if ( ! $pin_set ) : ?>
		<!-- PIN Setup -->
		<section class="wpc-card">
			<h2 class="wpc-section-heading"><?php esc_html_e( 'Set Up Command Center PIN', 'claw-agent' ); ?></h2>
			<p><?php esc_html_e( 'For security, every command requires a PIN. Choose a 4-8 digit PIN that only you know.', 'claw-agent' ); ?></p>

			<div class="wpc-kpi-grid">
				<div class="wpc-kpi-card">
					<label for="wp-claw-cc-new-pin" class="wpc-kpi-label">
						<?php esc_html_e( 'New PIN', 'claw-agent' ); ?>
					</label>
					<input
						type="password"
						id="wp-claw-cc-new-pin"
						maxlength="8"
						minlength="4"
						placeholder="<?php esc_attr_e( '4-8 digits', 'claw-agent' ); ?>"
						autocomplete="new-password"
					>
				</div>
				<div class="wpc-kpi-card">
					<label for="wp-claw-cc-confirm-pin" class="wpc-kpi-label">
						<?php esc_html_e( 'Confirm PIN', 'claw-agent' ); ?>
					</label>
					<input
						type="password"
						id="wp-claw-cc-confirm-pin"
						maxlength="8"
						minlength="4"
						placeholder="<?php esc_attr_e( 'Re-enter PIN', 'claw-agent' ); ?>"
						autocomplete="new-password"
					>
				</div>
			</div>

			<p>
				<button type="button" id="wp-claw-cc-save-pin" class="wpc-btn wpc-btn--primary">
					<?php esc_html_e( 'Save PIN', 'claw-agent' ); ?>
				</button>
			</p>
			<div id="wp-claw-cc-pin-error" class="wpc-badge wpc-badge--failed" hidden></div>
			<?php wp_nonce_field( 'wp_claw_setup_pin', 'wp_claw_pin_nonce' ); ?>
		</section>

	<?php else : ?>
		<!-- Command Center Chat -->
		<section class="wpc-card wpc-cc-chat-container">

			<!-- Messages area -->
			<div id="wp-claw-cc-messages" class="wpc-activity-feed" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Command Center messages', 'claw-agent' ); ?>">
				<div class="wpc-activity-item">
					<span class="wpc-badge wpc-badge--active"><?php esc_html_e( 'System', 'claw-agent' ); ?></span>
					<span><?php esc_html_e( 'Command Center active. 7 security layers verified. Type a command for your AI team.', 'claw-agent' ); ?></span>
				</div>
			</div>

			<!-- Input area -->
			<div class="wpc-cc-input-area">
				<div>
					<label for="wp-claw-cc-pin" class="screen-reader-text">
						<?php esc_html_e( 'PIN', 'claw-agent' ); ?>
					</label>
					<input
						type="password"
						id="wp-claw-cc-pin"
						maxlength="8"
						placeholder="<?php esc_attr_e( 'PIN', 'claw-agent' ); ?>"
						autocomplete="off"
						inputmode="numeric"
					>
				</div>
				<div>
					<label for="wp-claw-cc-prompt" class="screen-reader-text">
						<?php esc_html_e( 'Command', 'claw-agent' ); ?>
					</label>
					<input
						type="text"
						id="wp-claw-cc-prompt"
						maxlength="2000"
						placeholder="<?php esc_attr_e( 'Type a command for your AI team...', 'claw-agent' ); ?>"
						autocomplete="off"
					>
				</div>
				<button
					type="button"
					id="wp-claw-cc-send"
					class="wpc-btn wpc-btn--primary"
					disabled
					aria-disabled="true"
				>
					<?php esc_html_e( 'Send', 'claw-agent' ); ?>
				</button>
			</div>

			<?php wp_nonce_field( 'wp_claw_command_nonce', 'wp_claw_command_nonce_field' ); ?>

			<!-- Security status bar -->
			<div class="wpc-connection-banner wpc-connection-banner--connected" aria-label="<?php esc_attr_e( 'Security status', 'claw-agent' ); ?>">
				<span class="wpc-status-dot wpc-status-dot--green"></span>
				<span><?php esc_html_e( '7-layer security active', 'claw-agent' ); ?></span>
				<span id="wp-claw-cc-rate-status">
					<?php esc_html_e( 'Loading rate limits...', 'claw-agent' ); ?>
				</span>
			</div>

		</section>

		<!-- Command History (collapsible) -->
		<section class="wpc-card">

			<button
				type="button"
				class="wpc-btn wpc-btn--ghost"
				id="wp-claw-cc-history-toggle"
				aria-expanded="false"
				aria-controls="wp-claw-cc-history-body"
			>
				<?php esc_html_e( 'Recent Command History', 'claw-agent' ); ?>
			</button>

			<div
				id="wp-claw-cc-history-body"
				hidden
				role="region"
				aria-labelledby="wp-claw-cc-history-toggle"
			>
				<table class="wpc-agent-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Time', 'claw-agent' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Command', 'claw-agent' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'claw-agent' ); ?></th>
						</tr>
					</thead>
					<tbody id="wp-claw-cc-history-rows">
						<tr>
							<td colspan="4"><?php esc_html_e( 'Loading...', 'claw-agent' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

		</section>

	<?php endif; ?>

</div>
