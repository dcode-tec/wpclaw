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

$valid_filters = array( 'pending', 'all', 'approved', 'rejected' );
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
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- Filter Tabs -->
	<nav class="wpc-nav-tabs" aria-label="<?php esc_attr_e( 'Proposal filters', 'claw-agent' ); ?>">
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>"
			class="wpc-nav-tabs__item <?php echo esc_attr( 'pending' === $current_filter ? 'wpc-nav-tabs__item--active' : '' ); ?>"
			<?php echo 'pending' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Pending', 'claw-agent' ); ?>
			<?php if ( $pending_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--pending"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></span>
			<?php endif; ?>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=approved' ) ); ?>"
			class="wpc-nav-tabs__item <?php echo esc_attr( 'approved' === $current_filter ? 'wpc-nav-tabs__item--active' : '' ); ?>"
			<?php echo 'approved' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Approved', 'claw-agent' ); ?>
			<?php if ( $approved_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--done"><?php echo esc_html( number_format_i18n( $approved_count ) ); ?></span>
			<?php endif; ?>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=rejected' ) ); ?>"
			class="wpc-nav-tabs__item <?php echo esc_attr( 'rejected' === $current_filter ? 'wpc-nav-tabs__item--active' : '' ); ?>"
			<?php echo 'rejected' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'Rejected', 'claw-agent' ); ?>
			<?php if ( $rejected_count > 0 ) : ?>
				<span class="wpc-badge wpc-badge--failed"><?php echo esc_html( number_format_i18n( $rejected_count ) ); ?></span>
			<?php endif; ?>
		</a>
		<a
			href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=all' ) ); ?>"
			class="wpc-nav-tabs__item <?php echo esc_attr( 'all' === $current_filter ? 'wpc-nav-tabs__item--active' : '' ); ?>"
			<?php echo 'all' === $current_filter ? 'aria-current="page"' : ''; ?>
		>
			<?php esc_html_e( 'All', 'claw-agent' ); ?>
			<span class="wpc-badge wpc-badge--idle"><?php echo esc_html( number_format_i18n( $total_proposals ) ); ?></span>
		</a>
	</nav>

	<!-- Empty State -->
	<?php if ( empty( $proposals ) ) : ?>
	<div class="wpc-empty-state">
		<p>
			<?php
			if ( 'pending' === $current_filter ) {
				esc_html_e( 'No pending proposals. Your agents are operating autonomously.', 'claw-agent' );
			} elseif ( 'approved' === $current_filter ) {
				esc_html_e( 'No approved proposals yet.', 'claw-agent' );
			} elseif ( 'rejected' === $current_filter ) {
				esc_html_e( 'No rejected proposals.', 'claw-agent' );
			} else {
				esc_html_e( 'No proposals recorded yet.', 'claw-agent' );
			}
			?>
		</p>
	</div>

	<?php else : ?>

	<!-- Proposal Cards -->
	<div class="wpc-proposal-list">
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
			?>
		<article
			class="wpc-proposal-card <?php echo esc_attr( $is_pending ? 'wpc-proposal-card--pending' : '' ); ?>"
			data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
		>
			<div class="wpc-proposal-card__header">
				<span class="wpc-proposal-card__agent wpc-badge wpc-badge--active">
					<?php
					$proposal_display_name = isset( $wp_claw_agent_display_names[ $proposal_agent ] )
						? $wp_claw_agent_display_names[ $proposal_agent ]
						: ucfirst( $proposal_agent );
					echo esc_html( $proposal_display_name );
					?>
				</span>
				<span class="wpc-proposal-card__action">
					<code><?php echo esc_html( $proposal_action ); ?></code>
				</span>
				<?php if ( $proposal_age ) : ?>
				<span class="wpc-proposal-card__timer">
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
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $badge_class ); ?>">
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', $proposal_status ) ) ); ?>
				</span>
				<?php if ( ! empty( $proposal->tier ) ) : ?>
					<?php
					$tier_class_map = array( 'AUTO' => 'auto', 'AUTO+' => 'auto-plus', 'PROPOSE' => 'propose', 'CONFIRM' => 'confirm' );
					$tier_val       = strtoupper( sanitize_text_field( (string) $proposal->tier ) );
					$tier_css       = isset( $tier_class_map[ $tier_val ] ) ? $tier_class_map[ $tier_val ] : 'idle';
					?>
					<span class="wpc-badge wpc-badge--<?php echo esc_attr( $tier_css ); ?>">
						<?php echo esc_html( $tier_val ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $is_pending ) : ?>
				<span class="wpc-proposal-card__actions">
					<button
						type="button"
						class="wpc-btn wpc-btn--success wpc-admin-btn-approve"
						data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_proposal_' . $proposal_id ) ); ?>"
					>
						<?php esc_html_e( 'Approve', 'claw-agent' ); ?>
					</button>
					<button
						type="button"
						class="wpc-btn wpc-btn--danger wpc-admin-btn-reject"
						data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_proposal_' . $proposal_id ) ); ?>"
					>
						<?php esc_html_e( 'Reject', 'claw-agent' ); ?>
					</button>
				</span>
				<?php endif; ?>
			</div>

			<div class="wpc-proposal-card__body">
				<p><?php echo esc_html( $proposal_excerpt ); ?></p>
				<?php if ( strlen( $proposal_raw ) > strlen( $proposal_excerpt ) ) : ?>
					<button type="button" class="wpc-expand-toggle"><?php esc_html_e( 'Show full details', 'claw-agent' ); ?></button>
					<div class="wpc-expandable-row__content" style="display:none; margin-top: 8px;">
						<?php echo wp_kses_post( nl2br( $proposal_raw ) ); ?>
					</div>
				<?php endif; ?>
			</div>

		</article>
		<?php endforeach; ?>
	</div>

	<p class="wpc-kpi-label">
		<?php esc_html_e( 'Approving or rejecting a proposal sends the decision to the Klawty instance via the REST API.', 'claw-agent' ); ?>
	</p>

	<?php endif; ?>

</div>
