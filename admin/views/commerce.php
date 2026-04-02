<?php
/**
 * Commerce & CRM dashboard admin view — agent-first layout centred on Hugo's reports.
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.2.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

global $wpdb;

$plugin       = \WPClaw\WP_Claw::get_instance();
$commerce_mod = $plugin->get_module( 'commerce' );
$crm_mod      = $plugin->get_module( 'crm' );

// If both modules are missing, show enable notice and bail.
if ( null === $commerce_mod && null === $crm_mod ) {
	?>
	<div class="wpc-admin-wrap">
		<h1 class="wpc-section-heading"><?php esc_html_e( 'Commerce & CRM', 'claw-agent' ); ?></h1>
		<div class="wpc-alert-banner">
			<p>
				<?php
				printf(
					/* translators: %s: URL to modules settings page */
					esc_html__( 'The Commerce and CRM modules are not enabled. %s to activate them.', 'claw-agent' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-modules' ) ) . '">' . esc_html__( 'Go to Modules', 'claw-agent' ) . '</a>'
				);
				?>
			</p>
		</div>
	</div>
	<?php
	return;
}

$com        = null !== $commerce_mod ? $commerce_mod->get_state() : array();
$woo_active = isset( $com['available'] ) && $com['available'];
$crm        = null !== $crm_mod ? $crm_mod->get_state() : array();
$crm_active = null !== $crm_mod;

/** @return string Formatted price (WooCommerce-aware). */
$wp_claw_format_price = function ( $value ) {
	if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_price' ) ) {
		return wp_strip_all_tags( wc_price( (float) $value ) );
	}
	return number_format( (float) $value, 2 ) . ' EUR';
};

/** @return string "X ago" or "---". */
$wp_claw_time_ago = function ( $timestamp ) {
	if ( ! $timestamp ) { return '---'; }
	/* translators: %s: human-readable time difference */
	return sprintf( __( '%s ago', 'claw-agent' ), human_time_diff( $timestamp ) );
};

$daily_revenue    = isset( $com['daily_revenue'] ) ? (float) $com['daily_revenue'] : 0.0;
$daily_orders     = isset( $com['daily_orders'] ) ? (int) $com['daily_orders'] : 0;
$avg_order_value  = $daily_orders > 0 ? $daily_revenue / $daily_orders : 0.0;
$abandoned_count  = isset( $com['abandoned_carts_count'] ) ? (int) $com['abandoned_carts_count'] : 0;
$abandoned_value  = isset( $com['abandoned_carts_value'] ) ? (float) $com['abandoned_carts_value'] : 0.0;
$fraud_flags      = isset( $com['fraud_flags_today'] ) ? (int) $com['fraud_flags_today'] : 0;

// Pending email drafts count — direct query for accuracy.
$drafts_table = $wpdb->prefix . 'wp_claw_email_drafts';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_drafts = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE status = %s',
		$drafts_table,
		'draft'
	)
);

$carts_table = $wpdb->prefix . 'wp_claw_abandoned_carts';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$abandoned_carts = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d',
		$carts_table,
		'abandoned',
		20
	)
);

// Recovered count for badge.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recovered_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE status = %s',
		$carts_table,
		'recovered'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$email_drafts = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d',
		$drafts_table,
		'draft',
		20
	)
);

$pipeline_stages   = isset( $crm['pipeline_stages'] ) ? (array) $crm['pipeline_stages'] : array();
$total_leads       = isset( $crm['total_leads'] ) ? (int) $crm['total_leads'] : 0;
$customer_segments = isset( $com['customer_segments'] ) ? (array) $com['customer_segments'] : array();

?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<?php if ( null !== $commerce_mod && ! $woo_active ) : ?>
	<div class="wpc-alert-banner">
		<p>
			<?php
			$woo_message = isset( $com['message'] ) ? sanitize_text_field( $com['message'] ) : '';
			if ( '' !== $woo_message ) {
				echo esc_html( $woo_message );
			} else {
				esc_html_e( 'WooCommerce is not active. Commerce features are unavailable, but CRM data is shown below.', 'claw-agent' );
			}
			?>
		</p>
	</div>
	<?php endif; ?>

	<!-- 1. Agent Status Bar -->
	<div class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<span style="font-size:1.5rem;" aria-hidden="true">💼</span>
			<div>
				<strong><?php esc_html_e( 'Hugo — Commerce Lead', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active" id="wpc-commerce-status"><?php esc_html_e( 'Active', 'claw-agent' ); ?></span>
			</div>
		</div>
		<div style="display:flex;align-items:center;gap:12px;">
			<span class="wpc-kpi-label" id="wpc-commerce-last-review"><?php esc_html_e( 'Loading\xe2\x80\xa6', 'claw-agent' ); ?></span>
			<button
				type="button"
				class="wpc-btn wpc-btn--primary wpc-btn--sm wpc-request-scan"
				data-agent="commerce"
				data-title="<?php esc_attr_e( 'Manual pipeline review', 'claw-agent' ); ?>"
				data-description="<?php esc_attr_e( 'Admin requested CRM and commerce review. Run commerce_get_abandoned_carts, commerce_get_daily_order_summary, crm_get_leads, crm_get_pipeline_health. Report recovery rate and pipeline status.', 'claw-agent' ); ?>"
			>
				<?php esc_html_e( 'Request Pipeline Review', 'claw-agent' ); ?>
			</button>
		</div>
	</div>

	<!-- 2. Latest Commerce Report (hero) -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Latest Commerce Report', 'claw-agent' ); ?></h3>
		<div
			id="wpc-commerce-latest-report"
			data-agent="commerce"
			data-endpoint="reports"
			data-limit="1"
		>
			<p class="wpc-empty-state"><?php esc_html_e( "Loading Hugo\xe2\x80\x99s latest commerce analysis\xe2\x80\xa6", 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- 3. KPI Cards -->
	<section class="wpc-kpi-grid--6">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( $wp_claw_format_price( $daily_revenue ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Daily Revenue', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $daily_orders ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Daily Orders', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( $wp_claw_format_price( $avg_order_value ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Avg Order Value', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $abandoned_count > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $abandoned_count ) ); ?></span>
			<span class="wpc-kpi-label">
				<?php esc_html_e( 'Abandoned Carts', 'claw-agent' ); ?>
				<?php if ( $abandoned_value > 0 ) : ?>
					<br><small><?php echo esc_html( $wp_claw_format_price( $abandoned_value ) ); ?></small>
				<?php endif; ?>
			</span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $pending_drafts > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $pending_drafts ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Pending Email Drafts', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $fraud_flags > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $fraud_flags ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Fraud Alerts Today', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- 4. Review History (last 10 reports, 30d) -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Review History', 'claw-agent' ); ?></h3>
		<div
			id="wpc-commerce-review-history"
			data-agent="commerce"
			data-endpoint="reports"
			data-limit="10"
			data-since="30d"
		>
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading review history\xe2\x80\xa6', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- 5. Recent Actions (last 24h) -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Recent Actions', 'claw-agent' ); ?></h3>
		<div
			id="wpc-commerce-recent-actions"
			data-agent="commerce"
			data-endpoint="activity"
			data-since="24h"
			data-limit="15"
		>
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading recent activity\xe2\x80\xa6', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- 6. Detailed Data -->

	<!-- Abandoned Cart Queue -->
	<?php if ( $woo_active ) : ?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading">
			<?php esc_html_e( 'Abandoned Cart Queue', 'claw-agent' ); ?>
			<?php if ( $recovered_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--done">
					<?php
					printf(
						/* translators: %d: number of recovered carts */
						esc_html__( '%d recovered', 'claw-agent' ),
						$recovered_count
					);
					?>
				</span>
			<?php endif; ?>
		</h2>

		<?php if ( empty( $abandoned_carts ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No abandoned carts detected.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Session', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Cart Total', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Currency', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Age', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email Step', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $abandoned_carts as $cart ) : ?>
						<?php
						$cart_session  = isset( $cart->session_id ) ? substr( sanitize_text_field( (string) $cart->session_id ), 0, 8 ) : '---';
						$cart_email    = isset( $cart->email ) ? sanitize_email( (string) $cart->email ) : '';
						$cart_total    = isset( $cart->cart_total ) ? (float) $cart->cart_total : 0.0;
						$cart_currency = isset( $cart->currency ) ? sanitize_text_field( (string) $cart->currency ) : 'EUR';
						$cart_step     = isset( $cart->email_step ) ? (int) $cart->email_step : 0;
						$cart_status   = isset( $cart->status ) ? sanitize_key( (string) $cart->status ) : 'unknown';
						$cart_created  = isset( $cart->created_at ) ? strtotime( $cart->created_at ) : 0;
						?>
					<tr>
						<td><code><?php echo esc_html( $cart_session ); ?></code></td>
						<td><?php echo esc_html( $cart_email ? $cart_email : '---' ); ?></td>
						<td><?php echo esc_html( number_format( $cart_total, 2 ) ); ?></td>
						<td><?php echo esc_html( strtoupper( $cart_currency ) ); ?></td>
						<td><?php echo esc_html( $wp_claw_time_ago( $cart_created ) ); ?></td>
						<td><?php echo esc_html( $cart_step ); ?></td>
						<td>
							<span class="wpc-badge wpc-badge--<?php echo esc_attr( 'recovered' === $cart_status ? 'done' : 'pending' ); ?>">
								<?php echo esc_html( ucfirst( $cart_status ) ); ?>
							</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<!-- Email Draft Approval -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading">
			<?php esc_html_e( 'Email Draft Approval', 'claw-agent' ); ?>
			<?php if ( $pending_drafts > 0 ) : ?>
				<span class="wpc-badge wpc-badge--pending"><?php echo esc_html( number_format_i18n( $pending_drafts ) ); ?></span>
			<?php endif; ?>
		</h2>

		<?php if ( empty( $email_drafts ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No pending email drafts.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Recipient', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subject', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Language', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $email_drafts as $draft ) : ?>
						<?php
						$draft_id       = isset( $draft->id ) ? absint( $draft->id ) : 0;
						$draft_name     = isset( $draft->recipient_name ) ? sanitize_text_field( (string) $draft->recipient_name ) : '';
						$draft_email    = isset( $draft->recipient_email ) ? sanitize_email( (string) $draft->recipient_email ) : '';
						$draft_subject  = isset( $draft->subject ) ? sanitize_text_field( (string) $draft->subject ) : '';
						$draft_body     = isset( $draft->body ) ? (string) $draft->body : '';
						$draft_language = isset( $draft->language ) ? sanitize_text_field( (string) $draft->language ) : '';
						$draft_created  = isset( $draft->created_at ) ? strtotime( $draft->created_at ) : 0;
						?>
					<tr>
						<td><?php echo esc_html( $draft_name ? $draft_name : '---' ); ?></td>
						<td><?php echo esc_html( $draft_email ? $draft_email : '---' ); ?></td>
						<td><?php echo esc_html( $draft_subject ); ?></td>
						<td><?php echo esc_html( strtoupper( $draft_language ) ); ?></td>
						<td><?php echo esc_html( $wp_claw_time_ago( $draft_created ) ); ?></td>
						<td>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-expand-toggle"
								aria-expanded="false"
								data-target="wpc-draft-preview-<?php echo esc_attr( $draft_id ); ?>"
							>
								<?php esc_html_e( 'Preview', 'claw-agent' ); ?>
							</button>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--success wpc-admin-email-approve"
								data-id="<?php echo esc_attr( $draft_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_email_draft_' . $draft_id ) ); ?>"
							>
								<?php esc_html_e( 'Approve', 'claw-agent' ); ?>
							</button>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--danger wpc-admin-email-reject"
								data-id="<?php echo esc_attr( $draft_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_email_draft_' . $draft_id ) ); ?>"
							>
								<?php esc_html_e( 'Reject', 'claw-agent' ); ?>
							</button>
						</td>
					</tr>
					<tr class="wpc-expandable-row" id="wpc-draft-preview-<?php echo esc_attr( $draft_id ); ?>">
						<td colspan="6">
							<div class="wpc-expandable-row__content">
								<?php echo wp_kses_post( $draft_body ); ?>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- Lead Pipeline -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Lead Pipeline', 'claw-agent' ); ?></h2>

		<?php if ( ! $crm_active ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'Enable the CRM module to see lead pipeline data.', 'claw-agent' ); ?></p>
			</div>
		<?php elseif ( $total_leads < 1 ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No leads captured yet. Pipeline data will appear here once leads flow in.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<?php
			// Map task statuses to consolidated pipeline segments.
			$status_to_segment = array(
				'new' => 'new', 'pending' => 'new',
				'qualified' => 'qualified', 'running' => 'qualified',
				'proposal' => 'proposal',
				'done' => 'won', 'won' => 'won',
				'failed' => 'lost', 'lost' => 'lost',
			);
			$segments = array( 'new' => 0, 'qualified' => 0, 'proposal' => 0, 'won' => 0, 'lost' => 0 );
			foreach ( $pipeline_stages as $skey => $sval ) {
				$skey = sanitize_key( (string) $skey );
				$dest = isset( $status_to_segment[ $skey ] ) ? $status_to_segment[ $skey ] : 'new';
				$segments[ $dest ] += absint( $sval );
			}
			?>

			<div class="wpc-pipeline-bar">
				<?php foreach ( $segments as $seg_name => $seg_count ) :
					if ( $seg_count < 1 ) { continue; }
					$pct = round( ( $seg_count / $total_leads ) * 100, 1 );
					?>
					<div class="wpc-pipeline-bar__segment --<?php echo esc_attr( $seg_name ); ?>"
						style="width: <?php echo esc_attr( $pct ); ?>%"
						title="<?php echo esc_attr( ucfirst( $seg_name ) . ': ' . $seg_count ); ?>"></div>
				<?php endforeach; ?>
			</div>

			<div class="wpc-tier-badges" style="margin-top: 12px;">
				<?php foreach ( $segments as $seg_key => $seg_val ) :
					$dot_color = 'won' === $seg_key ? 'green' : ( 'lost' === $seg_key ? 'red' : 'yellow' );
					?>
					<span class="wpc-badge">
						<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $dot_color ); ?>"></span>
						<?php echo esc_html( ucfirst( $seg_key ) ); ?>
						<strong><?php echo esc_html( number_format_i18n( $seg_val ) ); ?></strong>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- Customer Segments -->
	<?php if ( $woo_active && ! empty( $customer_segments ) ) : ?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Customer Segments', 'claw-agent' ); ?></h2>

		<?php
		$total_segment_count = 0;
		foreach ( $customer_segments as $segment ) {
			$total_segment_count += isset( $segment['count'] ) ? absint( $segment['count'] ) : 0;
		}
		?>

		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Segment', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Count', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Percentage', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $customer_segments as $segment ) : ?>
					<?php
					$seg_name  = isset( $segment['name'] ) ? sanitize_text_field( (string) $segment['name'] ) : __( 'Unknown', 'claw-agent' );
					$seg_count = isset( $segment['count'] ) ? absint( $segment['count'] ) : 0;
					$seg_pct   = $total_segment_count > 0 ? round( ( $seg_count / $total_segment_count ) * 100, 1 ) : 0;
					?>
				<tr>
					<td><?php echo esc_html( $seg_name ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $seg_count ) ); ?></td>
					<td><?php echo esc_html( $seg_pct . '%' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>


</div>
