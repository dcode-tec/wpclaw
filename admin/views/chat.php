<?php
/**
 * Chat dashboard admin view — agent-first layout centred on Marc (Concierge).
 *
 * Layout:
 *   1. Module disabled check — banner if chat module not enabled
 *   2. Agent status bar — Marc, Concierge, sessions today, escalation count
 *   3. KPI grid (4 cards) — Sessions Today, FAQ Entries, Escalations, Avg Response Time
 *   4. Escalation Queue — table with urgency badges, Resolve button
 *   5. Recent Conversations — JS-loaded, expandable rows with transcripts
 *   6. Learned FAQs — table with inline edit
 *   7. Widget Configuration — inline edit fields for agent settings
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

global $wpdb;

// Get chat module state.
$plugin      = \WPClaw\WP_Claw::get_instance();
$chat_module = $plugin->get_module( 'chat' );

// If module is disabled, show enable notice and bail.
if ( null === $chat_module ) : ?>
<div class="wpc-admin-wrap">
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<?php
		printf(
			/* translators: %s: Link to settings page */
			esc_html__( 'The Chat module is not enabled. %s to activate it.', 'claw-agent' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
		);
		?>
	</div>
</div>
	<?php
	return;
endif;

$chat = $chat_module->get_state();

// Extract values safely with defaults.
$sessions_today     = isset( $chat['chat_sessions_today'] ) ? (int) $chat['chat_sessions_today'] : 0;
$faq_count          = isset( $chat['faq_entry_count'] ) ? (int) $chat['faq_entry_count'] : 0;
$escalations        = isset( $chat['unresolved_escalations'] ) ? (int) $chat['unresolved_escalations'] : 0;
$avg_response_time  = isset( $chat['avg_response_time_min'] ) ? (float) $chat['avg_response_time_min'] : 0.0;
$faq_coverage       = isset( $chat['faq_coverage_percent'] ) ? (float) $chat['faq_coverage_percent'] : 0.0;

// -------------------------------------------------------------------------
// Database queries (Escalation Queue + Learned FAQs).
// -------------------------------------------------------------------------

$escalations_table = $wpdb->prefix . 'wp_claw_chat_escalations';
$faq_table         = $wpdb->prefix . 'wp_claw_chat_faq';

// Fetch open escalations (limit 50).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$escalation_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, visitor_id, topic, wait_time_min, page_url, created_at
		 FROM %i
		 WHERE status = %s
		 ORDER BY created_at ASC
		 LIMIT %d",
		$escalations_table,
		'open',
		50
	)
);
if ( ! is_array( $escalation_rows ) ) {
	$escalation_rows = array();
}

// Fetch learned FAQ entries (limit 100).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$faq_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, question, answer, times_used
		 FROM %i
		 ORDER BY times_used DESC
		 LIMIT %d",
		$faq_table,
		100
	)
);
if ( ! is_array( $faq_rows ) ) {
	$faq_rows = array();
}

// -------------------------------------------------------------------------
// Widget configuration options.
// -------------------------------------------------------------------------
$cfg_agent_name = sanitize_text_field( (string) get_option( 'wp_claw_chat_agent_name', 'Marc' ) );
$cfg_welcome    = sanitize_textarea_field( (string) get_option( 'wp_claw_chat_welcome', '' ) );
$cfg_position   = sanitize_key( (string) get_option( 'wp_claw_chat_position', 'bottom-right' ) );
$cfg_sla_min    = (int) get_option( 'wp_claw_chat_escalation_sla_min', 5 );

// Position options.
$position_options = array(
	'bottom-right' => __( 'Bottom Right', 'claw-agent' ),
	'bottom-left'  => __( 'Bottom Left', 'claw-agent' ),
	'top-right'    => __( 'Top Right', 'claw-agent' ),
	'top-left'     => __( 'Top Left', 'claw-agent' ),
);

// -------------------------------------------------------------------------
// Helper: urgency badge class from wait time.
// -------------------------------------------------------------------------
/**
 * Returns the badge modifier class for an escalation row based on wait time.
 *
 * @param float $wait_min Wait time in minutes.
 * @return string Badge modifier class (without prefix).
 */
function wp_claw_chat_urgency_class( $wait_min ) {
	$wait_min = (float) $wait_min;
	if ( $wait_min < 5 ) {
		return 'active';
	}
	if ( $wait_min <= 15 ) {
		return 'idle';
	}
	return 'error';
}

/**
 * Returns the urgency label for an escalation row.
 *
 * @param float $wait_min Wait time in minutes.
 * @return string Translated label.
 */
function wp_claw_chat_urgency_label( $wait_min ) {
	$wait_min = (float) $wait_min;
	if ( $wait_min < 5 ) {
		return __( 'Active', 'claw-agent' );
	}
	if ( $wait_min <= 15 ) {
		return __( 'Idle', 'claw-agent' );
	}
	return __( 'Urgent', 'claw-agent' );
}

// FAQ coverage badge.
$faq_coverage_class = 'failed';
if ( $faq_coverage >= 80 ) {
	$faq_coverage_class = 'done';
} elseif ( $faq_coverage >= 50 ) {
	$faq_coverage_class = 'pending';
}
?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- ================================================================
		2. AGENT STATUS BAR
		================================================================ -->
	<section class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<?php echo wp_claw_agent_avatar( 'Marc', 36 ); ?>
			<div>
				<strong><?php esc_html_e( 'Marc — Concierge', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $escalations > 0 ? 'error' : 'active' ); ?>" id="wpc-chat-agent-health">
					<?php echo esc_html( $escalations > 0 ? __( 'Escalation', 'claw-agent' ) : __( 'Attending', 'claw-agent' ) ); ?>
				</span>
				<br>
				<span class="wpc-kpi-label">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of sessions */
							_n( '%d session today', '%d sessions today', $sessions_today, 'claw-agent' ),
							number_format_i18n( $sessions_today )
						)
					);
					?>
				</span>
				<?php if ( $escalations > 0 ) : ?>
				<span class="wpc-kpi-label" style="margin-left:12px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of unresolved escalations */
							_n( '%d unresolved escalation', '%d unresolved escalations', $escalations, 'claw-agent' ),
							number_format_i18n( $escalations )
						)
					);
					?>
				</span>
				<?php endif; ?>
			</div>
		</div>
		<button
			class="wpc-btn wpc-btn--primary wpc-request-scan"
			type="button"
			data-agent="concierge"
			data-title="Review chat performance"
			data-description="Analyse today's chat sessions. Review unresolved escalations, identify recurring visitor questions, update the FAQ knowledge base, and suggest welcome message improvements. Respond with a brief summary of chat health and top action items."
			data-task-key="chat_concierge_review"
		>
			<?php esc_html_e( 'Ask Marc to Review', 'claw-agent' ); ?>
		</button>
	</section>

	<!-- ================================================================
		3. KPI GRID — PHP-rendered from module get_state()
		================================================================ -->
	<section class="wpc-kpi-grid wpc-kpi-grid--4">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $sessions_today ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Sessions Today', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $faq_count ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'FAQ Entries', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $escalations > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $escalations ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Unresolved Escalations', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $avg_response_time, 1 ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Avg Response (min)', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- FAQ COVERAGE -->
	<section class="wpc-card" style="margin-top:20px;margin-bottom:20px;display:flex;align-items:center;gap:16px;">
		<span class="wpc-badge wpc-badge--<?php echo esc_attr( $faq_coverage_class ); ?>">
			<?php echo esc_html( number_format_i18n( $faq_coverage, 1 ) . '%' ); ?>
		</span>
		<span class="wpc-kpi-label"><?php esc_html_e( 'FAQ Coverage — share of visitor questions answered by the knowledge base', 'claw-agent' ); ?></span>
	</section>

	<!-- ================================================================
		4. ESCALATION QUEUE
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Escalation Queue', 'claw-agent' ); ?></h2>
			<?php if ( $escalations > 0 ) : ?>
			<span class="wpc-badge wpc-badge--error">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of open escalations */
						_n( '%d open', '%d open', $escalations, 'claw-agent' ),
						number_format_i18n( $escalations )
					)
				);
				?>
			</span>
			<?php endif; ?>
		</div>

		<?php if ( empty( $escalation_rows ) ) : ?>
		<p class="wpc-empty-state"><?php esc_html_e( 'No open escalations. Marc is handling all conversations within SLA.', 'claw-agent' ); ?></p>
		<?php else : ?>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Visitor', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Topic', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Wait Time', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Page', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $escalation_rows as $row ) : ?>
				<?php
				$wait     = isset( $row->wait_time_min ) ? (float) $row->wait_time_min : 0.0;
				$urg_cls  = wp_claw_chat_urgency_class( $wait );
				$urg_lbl  = wp_claw_chat_urgency_label( $wait );
				$page_url = isset( $row->page_url ) ? esc_url( $row->page_url ) : '';
				$page_host = $page_url ? wp_parse_url( $page_url, PHP_URL_PATH ) : '';
				?>
				<tr>
					<td>
						<code><?php echo esc_html( ! empty( $row->visitor_id ) ? $row->visitor_id : "\u{2014}" ); ?></code>
					</td>
					<td><?php echo esc_html( ! empty( $row->topic ) ? $row->topic : "\u{2014}" ); ?></td>
					<td>
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $urg_cls ); ?>">
							<?php echo esc_html( $urg_lbl ); ?>
						</span>
						<span class="wpc-kpi-label" style="margin-left:6px;">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of minutes */
									__( '%s min', 'claw-agent' ),
									number_format_i18n( $wait, 0 )
								)
							);
							?>
						</span>
					</td>
					<td>
						<?php if ( $page_url ) : ?>
						<a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $page_host ? $page_host : $page_url ); ?>
						</a>
						<?php else : ?>
						<?php echo esc_html( "\u{2014}" ); ?>
						<?php endif; ?>
					</td>
					<td>
						<button
							type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-agent-action="resolve_escalation"
							data-target-id="<?php echo esc_attr( (int) $row->id ); ?>"
							style="color:#059669;font-size:0.75rem;"
						>
							<?php esc_html_e( 'Resolve', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>

	<!-- ================================================================
		5. RECENT CONVERSATIONS — JS-loaded, expandable rows
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Recent Conversations', 'claw-agent' ); ?></h2>
		<div id="wpc-chat-conversations" data-agent="concierge" data-limit="20">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading recent conversations...', 'claw-agent' ); ?></p>
		</div>
		<!-- JS-rendered template structure (hidden, cloned by JS):
		<table class="wpc-detail-table">
			<tbody>
				<tr class="wpc-expandable-row" data-session-id="{id}">
					<td>Visitor</td><td>Started</td><td>Duration</td><td>Resolved</td><td>▶</td>
				</tr>
				<tr class="wpc-conversation-transcript" style="display:none;">
					<td colspan="5">
						<div class="wpc-transcript">
							<div class="wpc-message wpc-message--visitor">...</div>
							<div class="wpc-message wpc-message--agent">...</div>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
		-->
	</section>

	<!-- ================================================================
		6. LEARNED FAQs
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Learned FAQs', 'claw-agent' ); ?></h2>
			<button
				type="button"
				class="wpc-btn wpc-btn--primary wpc-request-scan"
				data-agent="concierge"
				data-title="Expand FAQ knowledge base"
				data-description="Review all recent chat sessions that ended without a resolved answer. Identify the top 5 unanswered visitor questions and write concise, helpful FAQ entries for them. Add them to the knowledge base."
				data-task-key="chat_concierge_faq_expand"
			>
				<?php esc_html_e( 'Ask Marc to Expand FAQs', 'claw-agent' ); ?>
			</button>
		</div>

		<?php if ( empty( $faq_rows ) ) : ?>
		<p class="wpc-empty-state"><?php esc_html_e( "Marc hasn't learned any FAQs yet. As visitors ask questions, Marc builds a knowledge base automatically. You can also ask Marc to expand the FAQs from recent conversations.", 'claw-agent' ); ?></p>
		<?php else : ?>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Question', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Answer', 'claw-agent' ); ?></th>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Times Used', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $faq_rows as $faq ) : ?>
				<tr>
					<td><?php echo esc_html( ! empty( $faq->question ) ? $faq->question : "\u{2014}" ); ?></td>
					<td style="max-width:360px;">
						<span class="wpc-faq-answer">
							<?php echo esc_html( ! empty( $faq->answer ) ? wp_trim_words( $faq->answer, 20 ) : "\u{2014}" ); ?>
						</span>
					</td>
					<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) ( $faq->times_used ?? 0 ) ) ); ?></td>
					<td>
						<button
							type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-inline-edit="faq_answer"
							data-target-id="<?php echo esc_attr( (int) $faq->id ); ?>"
							data-current-value="<?php echo esc_attr( $faq->answer ?? '' ); ?>"
							style="font-size:0.75rem;"
						>
							<?php esc_html_e( 'Edit', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>

	<!-- ================================================================
		7. WIDGET CONFIGURATION
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:30px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Widget Configuration', 'claw-agent' ); ?></h2>
		<p style="color:#6b7280;font-size:0.875rem;margin-bottom:16px;">
			<?php esc_html_e( 'These settings control the chat widget shown to your visitors. Changes take effect immediately.', 'claw-agent' ); ?>
		</p>

		<table class="wpc-detail-table">
			<tbody>

				<!-- Agent Name -->
				<tr>
					<th scope="row" style="width:220px;">
						<?php esc_html_e( 'Agent Name', 'claw-agent' ); ?>
					</th>
					<td>
						<span
							class="wpc-inline-edit-value"
							data-inline-edit="wp_claw_chat_agent_name"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_agent_name ); ?>"
						>
							<?php echo esc_html( $cfg_agent_name ); ?>
						</span>
						<button
							type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-inline-edit="wp_claw_chat_agent_name"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_agent_name ); ?>"
							style="font-size:0.75rem;margin-left:8px;"
						>
							<?php esc_html_e( 'Edit', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>

				<!-- Welcome Message -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Welcome Message', 'claw-agent' ); ?></th>
					<td>
						<span
							class="wpc-inline-edit-value"
							data-inline-edit="wp_claw_chat_welcome"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_welcome ); ?>"
						>
							<?php echo $cfg_welcome ? esc_html( wp_trim_words( $cfg_welcome, 15 ) ) : '<em>' . esc_html__( '(not set)', 'claw-agent' ) . '</em>'; ?>
						</span>
						<button
							type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-inline-edit="wp_claw_chat_welcome"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_welcome ); ?>"
							style="font-size:0.75rem;margin-left:8px;"
						>
							<?php esc_html_e( 'Edit', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>

				<!-- Widget Position -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Widget Position', 'claw-agent' ); ?></th>
					<td>
						<span
							class="wpc-inline-edit-value"
							data-inline-edit="wp_claw_chat_position"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_position ); ?>"
						>
							<?php echo esc_html( isset( $position_options[ $cfg_position ] ) ? $position_options[ $cfg_position ] : $cfg_position ); ?>
						</span>
						<button
							type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-inline-edit="wp_claw_chat_position"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_position ); ?>"
							style="font-size:0.75rem;margin-left:8px;"
						>
							<?php esc_html_e( 'Edit', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>

				<!-- Escalation SLA -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Escalation SLA (min)', 'claw-agent' ); ?></th>
					<td>
						<span
							class="wpc-inline-edit-value"
							data-inline-edit="wp_claw_chat_escalation_sla_min"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_sla_min ); ?>"
						>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: SLA in minutes */
									_n( '%d minute', '%d minutes', $cfg_sla_min, 'claw-agent' ),
									number_format_i18n( $cfg_sla_min )
								)
							);
							?>
						</span>
						<button
							type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-inline-edit="wp_claw_chat_escalation_sla_min"
							data-target-id="0"
							data-current-value="<?php echo esc_attr( $cfg_sla_min ); ?>"
							style="font-size:0.75rem;margin-left:8px;"
						>
							<?php esc_html_e( 'Edit', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>

				<!-- Module enabled check -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Chat Widget', 'claw-agent' ); ?></th>
					<td>
						<?php
						$widget_enabled = in_array( 'chat', (array) get_option( 'wp_claw_enabled_modules', array() ), true );
						?>
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $widget_enabled ? 'active' : 'error' ); ?>">
							<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $widget_enabled ? 'green' : 'red' ); ?>"></span>
							<?php echo esc_html( $widget_enabled ? __( 'Enabled', 'claw-agent' ) : __( 'Disabled', 'claw-agent' ) ); ?>
						</span>
						<?php if ( ! $widget_enabled ) : ?>
						<a
							href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ); ?>"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							style="margin-left:8px;font-size:0.75rem;"
						>
							<?php esc_html_e( 'Enable in Settings', 'claw-agent' ); ?>
						</a>
						<?php endif; ?>
					</td>
				</tr>

			</tbody>
		</table>
	</section>

</div><!-- .wpc-admin-wrap -->
