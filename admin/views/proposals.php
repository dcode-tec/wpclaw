<?php
/**
 * Proposals admin view.
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

global $wpdb;

// -------------------------------------------------------------------------
// Data gathering — pending proposals from local DB.
// -------------------------------------------------------------------------
$proposals_table = $wpdb->prefix . 'wp_claw_proposals';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$proposals = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT proposal_id, agent, action, tier, details, status, created_at FROM %i WHERE status = %s ORDER BY created_at DESC',
		$proposals_table,
		'pending'
	)
);

// -------------------------------------------------------------------------
// Tab selection — "pending" (default) or "all".
// -------------------------------------------------------------------------
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
$current_filter = isset( $_GET['show'] ) ? sanitize_key( $_GET['show'] ) : 'pending';

$valid_filters = array( 'pending', 'all', 'approved', 'rejected', 'ideas' );
if ( ! in_array( $current_filter, $valid_filters, true ) ) {
	$current_filter = 'pending';
}

if ( 'all' === $current_filter ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$proposals = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT proposal_id, agent, action, tier, details, status, created_at FROM %i ORDER BY created_at DESC LIMIT %d',
			$proposals_table,
			50
		)
	);
} elseif ( 'approved' === $current_filter ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$proposals = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT proposal_id, agent, action, tier, details, status, created_at FROM %i WHERE status IN (%s, %s) ORDER BY created_at DESC LIMIT %d',
			$proposals_table,
			'approved',
			'executed',
			50
		)
	);
} elseif ( 'rejected' === $current_filter ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$proposals = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT proposal_id, agent, action, tier, details, status, created_at FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d',
			$proposals_table,
			'rejected',
			50
		)
	);
} elseif ( 'ideas' === $current_filter ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$proposals = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT proposal_id, agent, action, tier, details, status, created_at FROM %i WHERE action = %s ORDER BY created_at DESC LIMIT %d',
			$proposals_table,
			'idea',
			50
		)
	);
}

// Count per status for tab badges.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$proposal_status_counts_raw = $wpdb->get_results(
	$wpdb->prepare( 'SELECT status, COUNT(*) AS cnt FROM %i GROUP BY status', $proposals_table )
);
$proposal_status_counts = array();
if ( $proposal_status_counts_raw ) {
	foreach ( $proposal_status_counts_raw as $row ) {
		$proposal_status_counts[ sanitize_key( (string) $row->status ) ] = (int) $row->cnt;
	}
}
$total_proposals   = array_sum( $proposal_status_counts );
$pending_count     = isset( $proposal_status_counts['pending'] ) ? $proposal_status_counts['pending'] : 0;
$approved_count    = ( isset( $proposal_status_counts['approved'] ) ? $proposal_status_counts['approved'] : 0 )
					+ ( isset( $proposal_status_counts['executed'] ) ? $proposal_status_counts['executed'] : 0 );
$rejected_count    = isset( $proposal_status_counts['rejected'] ) ? $proposal_status_counts['rejected'] : 0;

/**
 * Map a raw proposal status to a badge modifier class.
 *
 * @param string $status Raw status string.
 * @return string Badge modifier.
 */
$wp_claw_proposal_badge = function ( $status ) {
	$map = array(
		'pending'     => 'pending',
		'approved'    => 'active',
		'rejected'    => 'failed',
		'executing'   => 'active',
		'executed'    => 'done',
		'rolled_back' => 'error',
		'expired'     => 'idle',
	);
	$key = strtolower( sanitize_key( (string) $status ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : 'pending';
};

$wp_claw_agent_display_names = array(
	'architect' => __( 'Karim — The Architect', 'claw-agent' ),
	'scribe'    => __( 'Lina — The Scribe', 'claw-agent' ),
	'sentinel'  => __( 'Bastien — The Sentinel', 'claw-agent' ),
	'commerce'  => __( 'Hugo — Commerce Lead', 'claw-agent' ),
	'analyst'   => __( 'Selma — The Analyst', 'claw-agent' ),
	'concierge' => __( 'Marc — The Concierge', 'claw-agent' ),
);

// Build a lookup of task_id → chain info for breadcrumbs (v1.4.0).
$chains_table = $wpdb->prefix . 'wp_claw_task_chains';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$chain_lookup_rows = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT chain_id, klawty_task_id, title, step_order FROM %i WHERE klawty_task_id IS NOT NULL',
		$chains_table
	)
);
$wp_claw_chain_lookup = array();
if ( $chain_lookup_rows ) {
	foreach ( $chain_lookup_rows as $clr ) {
		$wp_claw_chain_lookup[ sanitize_text_field( (string) $clr->klawty_task_id ) ] = array(
			'chain_id'   => sanitize_text_field( (string) $clr->chain_id ),
			'title'      => sanitize_text_field( (string) $clr->title ),
			'step_order' => (int) $clr->step_order,
		);
	}
}
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p style="color:#6b7280;margin-bottom:16px;"><?php esc_html_e( 'Review and approve agent actions that require your sign-off. High-impact changes are held here before execution.', 'claw-agent' ); ?></p>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- Filter Tabs -->
	<nav style="display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:20px;" aria-label="<?php esc_attr_e( 'Proposal filters', 'claw-agent' ); ?>">
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>"
			style="padding:10px 16px;text-decoration:none;font-size:0.875rem;font-weight:<?php echo 'pending' === $current_filter ? '600' : '400'; ?>;color:<?php echo 'pending' === $current_filter ? '#4f46e5' : '#6b7280'; ?>;border-bottom:2px solid <?php echo 'pending' === $current_filter ? '#4f46e5' : 'transparent'; ?>;margin-bottom:-2px;"
			<?php echo 'pending' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Pending', 'claw-agent' ); ?>
			<?php if ( $pending_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--pending"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></span>
			<?php endif; ?>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=approved' ) ); ?>"
			style="padding:10px 16px;text-decoration:none;font-size:0.875rem;font-weight:<?php echo 'approved' === $current_filter ? '600' : '400'; ?>;color:<?php echo 'approved' === $current_filter ? '#4f46e5' : '#6b7280'; ?>;border-bottom:2px solid <?php echo 'approved' === $current_filter ? '#4f46e5' : 'transparent'; ?>;margin-bottom:-2px;"
			<?php echo 'approved' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Approved', 'claw-agent' ); ?>
			<?php if ( $approved_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--done"><?php echo esc_html( number_format_i18n( $approved_count ) ); ?></span>
			<?php endif; ?>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=rejected' ) ); ?>"
			style="padding:10px 16px;text-decoration:none;font-size:0.875rem;font-weight:<?php echo 'rejected' === $current_filter ? '600' : '400'; ?>;color:<?php echo 'rejected' === $current_filter ? '#4f46e5' : '#6b7280'; ?>;border-bottom:2px solid <?php echo 'rejected' === $current_filter ? '#4f46e5' : 'transparent'; ?>;margin-bottom:-2px;"
			<?php echo 'rejected' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Rejected', 'claw-agent' ); ?>
			<?php if ( $rejected_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--failed"><?php echo esc_html( number_format_i18n( $rejected_count ) ); ?></span>
			<?php endif; ?>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=all' ) ); ?>"
			style="padding:10px 16px;text-decoration:none;font-size:0.875rem;font-weight:<?php echo 'all' === $current_filter ? '600' : '400'; ?>;color:<?php echo 'all' === $current_filter ? '#4f46e5' : '#6b7280'; ?>;border-bottom:2px solid <?php echo 'all' === $current_filter ? '#4f46e5' : 'transparent'; ?>;margin-bottom:-2px;"
			<?php echo 'all' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'All', 'claw-agent' ); ?>
			<span class="wpc-badge wpc-badge--idle"><?php echo esc_html( number_format_i18n( $total_proposals ) ); ?></span>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=ideas' ) ); ?>"
			style="padding:10px 16px;text-decoration:none;font-size:0.875rem;font-weight:<?php echo 'ideas' === $current_filter ? '600' : '400'; ?>;color:<?php echo 'ideas' === $current_filter ? '#4f46e5' : '#6b7280'; ?>;border-bottom:2px solid <?php echo 'ideas' === $current_filter ? '#4f46e5' : 'transparent'; ?>;margin-bottom:-2px;"
			<?php echo 'ideas' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Ideas', 'claw-agent' ); ?>
			💡
		</a>
	</nav>

	<!-- Empty State -->
	<?php if ( empty( $proposals ) ) : ?>
	<section class="wpc-card" style="padding:40px 20px;text-align:center;">
		<?php if ( 'pending' === $current_filter ) : ?>
			<div style="font-size:2.5rem;margin-bottom:12px;">✅</div>
			<h3 style="font-size:1.125rem;font-weight:700;color:#111827;margin-bottom:8px;"><?php esc_html_e( 'No pending proposals', 'claw-agent' ); ?></h3>
			<p style="color:#6b7280;max-width:500px;margin:0 auto 20px;">
				<?php esc_html_e( 'Your agents are operating autonomously. When an agent wants to make a high-impact change (deploy security headers, send an email, update site structure), it will create a proposal here for your approval.', 'claw-agent' ); ?>
			</p>
			<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;max-width:500px;margin:0 auto;">
				<p style="font-size:0.8125rem;color:#166534;margin:0;">
					<strong><?php esc_html_e( 'How proposals work:', 'claw-agent' ); ?></strong><br>
					<?php esc_html_e( 'Read-only actions (scans, analytics) happen automatically. Low-risk changes (meta tags, cache) deploy with a 15-minute rollback window. High-risk changes (new pages, emails, deployments) require your explicit approval here.', 'claw-agent' ); ?>
				</p>
			</div>
		<?php elseif ( 'approved' === $current_filter ) : ?>
			<p style="color:#6b7280;"><?php esc_html_e( 'No approved proposals yet. Proposals you approve will appear here with their execution status.', 'claw-agent' ); ?></p>
		<?php elseif ( 'rejected' === $current_filter ) : ?>
			<p style="color:#6b7280;"><?php esc_html_e( 'No rejected proposals. When you reject a proposal, the agent is notified and the action is cancelled.', 'claw-agent' ); ?></p>
		<?php elseif ( 'ideas' === $current_filter ) : ?>
			<div style="font-size:2.5rem;margin-bottom:12px;">💡</div>
			<h3 style="font-size:1.125rem;font-weight:700;"><?php esc_html_e( 'No ideas yet', 'claw-agent' ); ?></h3>
			<p style="color:#6b7280;max-width:500px;margin:0 auto;">
				<?php esc_html_e( 'Your agents generate 1 idea per day each. Ideas appear here for your review — approve to execute, reject to skip, or modify the scope.', 'claw-agent' ); ?>
			</p>
		<?php else : ?>
			<p style="color:#6b7280;"><?php esc_html_e( 'No proposals recorded yet. Proposals will appear as agents encounter actions that need your approval.', 'claw-agent' ); ?></p>
		<?php endif; ?>
	</section>

	<?php else : ?>

	<!-- Proposal Cards -->
	<div style="display:flex;flex-direction:column;gap:12px;">
		<?php foreach ( $proposals as $proposal ) : ?>
			<?php
			$proposal_id     = sanitize_text_field( (string) $proposal->proposal_id );
			$proposal_agent  = sanitize_text_field( (string) $proposal->agent );
			$proposal_action = sanitize_text_field( (string) $proposal->action );
			$proposal_status = sanitize_key( (string) $proposal->status );
			$proposal_raw    = sanitize_text_field( (string) $proposal->details );
			$proposal_age    = ! empty( $proposal->created_at ) ? strtotime( $proposal->created_at ) : 0;
			$badge_class     = $wp_claw_proposal_badge( $proposal_status );
			$is_pending      = 'pending' === $proposal_status;
			$proposal_excerpt = wp_trim_words( $proposal_raw, 30, '...' );

			$proposal_display_name = isset( $wp_claw_agent_display_names[ $proposal_agent ] )
				? $wp_claw_agent_display_names[ $proposal_agent ]
				: ucfirst( $proposal_agent );
			?>
		<article
			style="background:#fff;border:1px solid <?php echo esc_attr( $is_pending ? '#fbbf24' : '#e5e7eb' ); ?>;border-radius:12px;padding:20px;"
			data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
		>
			<!-- Header -->
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
				<?php echo wp_claw_agent_avatar( $proposal_agent, 32 ); ?>
				<strong style="font-size:0.9375rem;"><?php echo esc_html( $proposal_display_name ); ?></strong>
				<code style="background:#f3f4f6;padding:3px 8px;border-radius:4px;font-size:0.75rem;"><?php echo esc_html( $proposal_action ); ?></code>
				<?php if ( ! empty( $proposal->tier ) ) : ?>
					<?php $tier_val = strtoupper( sanitize_text_field( (string) $proposal->tier ) ); ?>
					<span style="background:<?php echo esc_attr( 'CONFIRM' === $tier_val ? '#fef3c7' : '#e0e7ff' ); ?>;color:<?php echo esc_attr( 'CONFIRM' === $tier_val ? '#92400e' : '#3730a3' ); ?>;padding:2px 8px;border-radius:9999px;font-size:0.6875rem;font-weight:600;">
						<?php echo esc_html( $tier_val ); ?>
					</span>
				<?php endif; ?>
				<?php
				// Chain breadcrumb (v1.4.0).
				$_wpc_chain_info = null;
				// Map proposal_id to task_id via tasks table, then check chain lookup.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$_wpc_linked_task_id = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT task_id FROM %i WHERE task_id = %s',
						$wpdb->prefix . 'wp_claw_tasks',
						$proposal_id
					)
				);
				if ( $_wpc_linked_task_id && isset( $wp_claw_chain_lookup[ $_wpc_linked_task_id ] ) ) {
					$_wpc_chain_info = $wp_claw_chain_lookup[ $_wpc_linked_task_id ];
				}
				if ( $_wpc_chain_info ) :
				?>
				<span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:9999px;font-size:0.6875rem;font-weight:600;" title="<?php echo esc_attr( $_wpc_chain_info['chain_id'] ); ?>">
					<?php
					printf(
						/* translators: 1: chain title, 2: step number */
						esc_html__( 'Chain: %1$s (step %2$d)', 'claw-agent' ),
						esc_html( $_wpc_chain_info['title'] ),
						$_wpc_chain_info['step_order']
					);
					?>
				</span>
				<?php endif; ?>
				<span style="background:<?php echo esc_attr( $is_pending ? '#fef3c7' : ( 'done' === $badge_class || 'active' === $badge_class ? '#dcfce7' : ( 'failed' === $badge_class ? '#fee2e2' : '#f3f4f6' ) ) ); ?>;color:<?php echo esc_attr( $is_pending ? '#92400e' : ( 'done' === $badge_class || 'active' === $badge_class ? '#166534' : ( 'failed' === $badge_class ? '#991b1b' : '#374151' ) ) ); ?>;padding:2px 8px;border-radius:9999px;font-size:0.6875rem;font-weight:600;">
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', $proposal_status ) ) ); ?>
				</span>
				<?php if ( $proposal_age ) : ?>
				<span style="color:#9ca3af;font-size:0.75rem;margin-left:auto;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time difference */
							__( '%s ago', 'claw-agent' ),
							human_time_diff( $proposal_age )
						)
					);
					?>
				</span>
				<?php endif; ?>
			</div>

			<!-- Details -->
			<div style="background:#f9fafb;border-radius:8px;padding:12px 16px;font-size:0.8125rem;color:#374151;margin-bottom:12px;">
				<p style="margin:0;"><?php echo esc_html( $proposal_excerpt ); ?></p>
				<?php if ( strlen( $proposal_raw ) > strlen( $proposal_excerpt ) ) : ?>
					<button type="button" class="wpc-expand-toggle" style="background:none;border:none;color:#4f46e5;cursor:pointer;font-size:0.75rem;padding:4px 0;margin-top:4px;"><?php esc_html_e( 'Show full details', 'claw-agent' ); ?></button>
					<div class="wpc-expandable-row__content" style="display:none;margin-top:8px;white-space:pre-wrap;word-break:break-word;">
						<?php echo wp_kses_post( nl2br( $proposal_raw ) ); ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Actions (pending only) -->
			<?php if ( $is_pending ) : ?>
			<div style="display:flex;gap:8px;">
				<button
					type="button"
					class="wpc-btn wpc-btn--success wpc-admin-btn-approve"
					data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_proposal_' . $proposal_id ) ); ?>"
					style="padding:8px 20px;border-radius:8px;font-size:0.875rem;font-weight:600;background:#16a34a;color:#fff;border:none;cursor:pointer;"
				>
					<?php esc_html_e( 'Approve', 'claw-agent' ); ?>
				</button>
				<button
					type="button"
					class="wpc-btn wpc-btn--danger wpc-admin-btn-reject"
					data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_proposal_' . $proposal_id ) ); ?>"
					style="padding:8px 20px;border-radius:8px;font-size:0.875rem;font-weight:600;background:#dc2626;color:#fff;border:none;cursor:pointer;"
				>
					<?php esc_html_e( 'Reject', 'claw-agent' ); ?>
				</button>
			</div>
			<?php endif; ?>

		</article>
		<?php endforeach; ?>
	</div>

	<?php endif; ?>

</div>
