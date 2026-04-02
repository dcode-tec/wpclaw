<?php
/**
 * Reports admin view.
 *
 * Displays agent activity reports with agent and time-period filtering.
 * The report list is populated asynchronously via the REST API by
 * wp-claw-admin.js (initReportsPage).
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.2.2
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php esc_html_e( 'Agent Reports', 'claw-agent' ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- Filter bar -->
	<div class="wpc-card" style="margin-bottom: 20px;">
		<div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">

			<div>
				<label class="wpc-kpi-label" for="wpc-report-agent">
					<?php esc_html_e( 'Agent', 'claw-agent' ); ?>
				</label>
				<select id="wpc-report-agent" style="margin-left: 4px;">
					<option value=""><?php esc_html_e( 'All Agents', 'claw-agent' ); ?></option>
					<option value="karim"><?php esc_html_e( '🏗️ Karim — Architect', 'claw-agent' ); ?></option>
					<option value="lina"><?php esc_html_e( '✍️ Lina — Scribe', 'claw-agent' ); ?></option>
					<option value="bastien"><?php esc_html_e( '🛡️ Bastien — Sentinel', 'claw-agent' ); ?></option>
					<option value="hugo"><?php esc_html_e( '💼 Hugo — Commerce', 'claw-agent' ); ?></option>
					<option value="selma"><?php esc_html_e( '📊 Selma — Analyst', 'claw-agent' ); ?></option>
					<option value="marc"><?php esc_html_e( '💬 Marc — Concierge', 'claw-agent' ); ?></option>
				</select>
			</div>

			<div>
				<label class="wpc-kpi-label" for="wpc-report-since">
					<?php esc_html_e( 'Period', 'claw-agent' ); ?>
				</label>
				<select id="wpc-report-since" style="margin-left: 4px;">
					<option value="24h"><?php esc_html_e( 'Last 24 hours', 'claw-agent' ); ?></option>
					<option value="7d" selected="selected"><?php esc_html_e( 'Last 7 days', 'claw-agent' ); ?></option>
					<option value="30d"><?php esc_html_e( 'Last 30 days', 'claw-agent' ); ?></option>
				</select>
			</div>

			<button
				type="button"
				class="wpc-btn wpc-btn--primary wpc-btn--sm"
				id="wpc-report-filter"
			>
				<?php esc_html_e( 'Filter', 'claw-agent' ); ?>
			</button>

			<span id="wpc-report-count" class="wpc-kpi-label" style="margin-left: auto;"></span>
		</div>
	</div>

	<!-- Reports container — populated by initReportsPage() in wp-claw-admin.js -->
	<div id="wpc-reports-list">
		<p class="wpc-empty-state" style="text-align: center; padding: 40px;">
			<?php esc_html_e( 'Loading reports\u2026', 'claw-agent' ); ?>
		</p>
	</div>

</div>
