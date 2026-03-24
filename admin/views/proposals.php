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

global $wpdb;

// -------------------------------------------------------------------------
// Data gathering — pending proposals from local DB.
// -------------------------------------------------------------------------
$proposals_table = $wpdb->prefix . 'wp_claw_proposals';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$proposals = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT proposal_id, agent, action, details, status, created_at
		 FROM {$proposals_table}
		 WHERE status = %s
		 ORDER BY created_at DESC",
		'pending'
	)
);

// -------------------------------------------------------------------------
// Tab selection — "pending" (default) or "all".
// -------------------------------------------------------------------------
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
$show_all = isset( $_GET['show'] ) && 'all' === sanitize_key( $_GET['show'] );

if ( $show_all ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$proposals = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		"SELECT proposal_id, agent, action, details, status, created_at
		 FROM {$proposals_table}
		 ORDER BY created_at DESC
		 LIMIT 50"
	);
}

/**
 * Map a raw proposal status to a safe CSS class suffix.
 *
 * @param string $status Raw status string.
 * @return string Sanitized CSS class suffix.
 */
$wp_claw_proposal_status_class = function ( $status ) {
	$map = array(
		'pending'     => 'pending',
		'approved'    => 'ok',
		'rejected'    => 'failed',
		'executing'   => 'running',
		'executed'    => 'done',
		'rolled_back' => 'degraded',
		'expired'     => 'unknown',
	);
	$key = strtolower( sanitize_key( (string) $status ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : 'unknown';
};
?>
<div class="wrap wp-claw-admin-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- Filter tabs                                                          -->
	<!-- ------------------------------------------------------------------ -->
	<ul class="subsubsub">
		<li>
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>"
				<?php echo ! $show_all ? 'class="current" aria-current="page"' : ''; ?>
			>
				<?php esc_html_e( 'Pending', 'wp-claw' ); ?>
			</a>
			|
		</li>
		<li>
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=all' ) ); ?>"
				<?php echo $show_all ? 'class="current" aria-current="page"' : ''; ?>
			>
				<?php esc_html_e( 'All (last 50)', 'wp-claw' ); ?>
			</a>
		</li>
	</ul>

	<!-- ------------------------------------------------------------------ -->
	<!-- Empty state                                                          -->
	<!-- ------------------------------------------------------------------ -->
	<?php if ( empty( $proposals ) ) : ?>
	<p class="wp-claw-admin-empty">
		<?php
		if ( $show_all ) {
			esc_html_e( 'No proposals recorded yet.', 'wp-claw' );
		} else {
			esc_html_e( 'No pending proposals. Your agents are operating autonomously.', 'wp-claw' );
		}
		?>
	</p>

	<?php else : ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- Proposal table                                                       -->
	<!-- ------------------------------------------------------------------ -->
	<table class="wp-list-table widefat fixed striped wp-claw-admin-proposals">
		<thead>
			<tr>
				<th scope="col" class="column-agent">
					<?php esc_html_e( 'Agent', 'wp-claw' ); ?>
				</th>
				<th scope="col" class="column-action">
					<?php esc_html_e( 'Action', 'wp-claw' ); ?>
				</th>
				<th scope="col" class="column-details">
					<?php esc_html_e( 'Details', 'wp-claw' ); ?>
				</th>
				<th scope="col" class="column-created">
					<?php esc_html_e( 'Requested', 'wp-claw' ); ?>
				</th>
				<th scope="col" class="column-status">
					<?php esc_html_e( 'Status', 'wp-claw' ); ?>
				</th>
				<th scope="col" class="column-actions">
					<?php esc_html_e( 'Actions', 'wp-claw' ); ?>
				</th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $proposals as $proposal ) : ?>
			<?php
			$proposal_id     = sanitize_text_field( (string) $proposal->proposal_id );
			$proposal_agent  = sanitize_text_field( (string) $proposal->agent );
			$proposal_action = sanitize_text_field( (string) $proposal->action );
			$proposal_status = sanitize_key( (string) $proposal->status );
			$proposal_raw    = sanitize_text_field( (string) $proposal->details );
			$proposal_age    = ! empty( $proposal->created_at ) ? strtotime( $proposal->created_at ) : 0;
			$status_class    = $wp_claw_proposal_status_class( $proposal_status );
			$is_pending      = 'pending' === $proposal_status;

			// Trim details to a readable excerpt — wp_trim_words works on plain text.
			$proposal_excerpt = wp_trim_words( $proposal_raw, 20, '&hellip;' );
			?>
		<tr
			class="wp-claw-admin-proposal-row <?php echo esc_attr( $is_pending ? 'wp-claw-admin-proposal-row--pending' : '' ); ?>"
			data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
		>
			<td class="column-agent">
				<span class="wp-claw-admin-agent-badge wp-claw-admin-agent-badge--<?php echo esc_attr( sanitize_key( $proposal_agent ) ); ?>">
					<?php echo esc_html( ucfirst( $proposal_agent ) ); ?>
				</span>
			</td>

			<td class="column-action">
				<code><?php echo esc_html( $proposal_action ); ?></code>
			</td>

			<td class="column-details">
				<span class="wp-claw-admin-proposal-details" title="<?php echo esc_attr( $proposal_raw ); ?>">
					<?php echo esc_html( $proposal_excerpt ); ?>
				</span>
			</td>

			<td class="column-created">
				<?php if ( $proposal_age ) : ?>
					<span title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $proposal_age ) . ' UTC' ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time difference */
								__( '%s ago', 'wp-claw' ),
								human_time_diff( $proposal_age )
							)
						);
						?>
					</span>
				<?php else : ?>
					&mdash;
				<?php endif; ?>
			</td>

			<td class="column-status">
				<span class="wp-claw-admin-status-pill wp-claw-admin-status-<?php echo esc_attr( $status_class ); ?>">
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', $proposal_status ) ) ); ?>
				</span>
			</td>

			<td class="column-actions">
				<?php if ( $is_pending ) : ?>
				<button
					type="button"
					class="button button-primary wp-claw-admin-btn-approve"
					data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_proposal_' . $proposal_id ) ); ?>"
				>
					<?php esc_html_e( 'Approve', 'wp-claw' ); ?>
				</button>
				<button
					type="button"
					class="button button-secondary wp-claw-admin-btn-reject"
					data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_proposal_' . $proposal_id ) ); ?>"
				>
					<?php esc_html_e( 'Reject', 'wp-claw' ); ?>
				</button>
				<?php else : ?>
				<span class="wp-claw-admin-muted">&mdash;</span>
				<?php endif; ?>
			</td>

		</tr>
		<?php endforeach; ?>
		</tbody>

		<tfoot>
			<tr>
				<th scope="col"><?php esc_html_e( 'Agent', 'wp-claw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'wp-claw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'wp-claw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Requested', 'wp-claw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'wp-claw' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'wp-claw' ); ?></th>
			</tr>
		</tfoot>

	</table><!-- /.wp-claw-admin-proposals -->

	<p class="wp-claw-admin-section-footer">
		<em class="wp-claw-admin-muted">
			<?php esc_html_e( 'Approving or rejecting a proposal sends the decision to the Klawty instance via the REST API.', 'wp-claw' ); ?>
		</em>
	</p>

	<?php endif; ?>

</div><!-- /.wrap -->
