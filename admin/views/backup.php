<?php
/**
 * Backup dashboard admin view — agent-first layout centred on Bastien (The Sentinel).
 *
 * Layout:
 *   1. Agent status bar — last/next backup, create backup + snapshot buttons
 *   2. KPI cards — PHP-rendered from module get_state() (live WP data)
 *   3. Database backups table — PHP-rendered from wp_claw_snapshots
 *   4. Snapshots table — PHP-rendered from wp_claw_snapshots (type = 'snapshot')
 *   5. Retention policy card — current policy with inline-edit
 *   6. Recent backup activity — JS-loaded with empty state timeout
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

// Get backup module state.
$plugin        = \WPClaw\WP_Claw::get_instance();
$backup_module = $plugin->get_module( 'backup' );

// If module is disabled, show enable notice and bail.
if ( null === $backup_module ) : ?>
<div class="wpc-admin-wrap">
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<?php
		printf(
			/* translators: %s: Link to settings page */
			esc_html__( 'The Backup module is not enabled. %s to activate it.', 'claw-agent' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
		);
		?>
	</div>
</div>
	<?php
	return;
endif;

$bak = $backup_module->get_state();

// Extract values safely with defaults.
$last_backup_at   = isset( $bak['last_backup_at'] ) ? sanitize_text_field( (string) $bak['last_backup_at'] ) : '';
$backup_count     = isset( $bak['backup_count'] ) ? (int) $bak['backup_count'] : 0;
$total_size_bytes = isset( $bak['total_size_bytes'] ) ? (int) $bak['total_size_bytes'] : 0;
$retention_days   = (int) get_option( 'wp_claw_backup_retention_days', 30 );

// -------------------------------------------------------------------------
// Database queries (backup list + snapshot list).
// -------------------------------------------------------------------------

$snapshots_table = $wpdb->prefix . 'wp_claw_snapshots';

// Full backups (backup_type = 'backup'), limit 50.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$db_backups = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, created_at, file_size_bytes, backup_type, status FROM %i WHERE backup_type = %s ORDER BY created_at DESC LIMIT %d",
		$snapshots_table,
		'backup',
		50
	)
);

// Snapshots (backup_type = 'snapshot'), limit 50.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$snapshots = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT id, created_at, file_size_bytes, backup_type, status FROM %i WHERE backup_type = %s ORDER BY created_at DESC LIMIT %d",
		$snapshots_table,
		'snapshot',
		50
	)
);

// -------------------------------------------------------------------------
// Computed display values.
// -------------------------------------------------------------------------

// Last backup — human time diff or "Never".
$last_backup_display = '';
$next_backup_display = '';
if ( $last_backup_at ) {
	$last_ts             = strtotime( $last_backup_at );
	$last_backup_display = $last_ts
		? human_time_diff( $last_ts, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'claw-agent' )
		: esc_html( $last_backup_at );

	// Next backup = last + 24 h.
	$next_ts = $last_ts ? $last_ts + DAY_IN_SECONDS : 0;
	if ( $next_ts > current_time( 'timestamp' ) ) {
		$next_backup_display = __( 'in ', 'claw-agent' ) . human_time_diff( current_time( 'timestamp' ), $next_ts );
	} else {
		$next_backup_display = __( 'overdue', 'claw-agent' );
	}
} else {
	$last_backup_display = __( 'Never', 'claw-agent' );
	$next_backup_display = __( 'pending schedule', 'claw-agent' );
}

// Badge class for last-backup KPI.
$last_backup_badge_class = $last_backup_at ? 'done' : 'idle';
?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- ================================================================
		1. AGENT STATUS BAR
		================================================================ -->
	<div class="wpc-card" style="margin-bottom: 20px;">
		<div class="wpc-card__header">
			<h2 class="wpc-card__title"><?php esc_html_e( 'Bastien — Sentinel', 'claw-agent' ); ?></h2>
			<div style="display: flex; gap: 8px;">
				<button
					class="button wpc-scan-button"
					type="button"
					data-agent-action="backup_create"
					data-task-key="backup-now"
					data-agent="sentinel"
					data-title="<?php esc_attr_e( 'Create database backup', 'claw-agent' ); ?>"
					data-description="<?php esc_attr_e( 'Create a full database backup immediately. Verify the backup file is complete and log the result.', 'claw-agent' ); ?>"
				>
					<?php esc_html_e( 'Create Backup Now', 'claw-agent' ); ?>
					<span class="wpc-spinner"></span>
				</button>
				<button
					class="button"
					type="button"
					data-agent-action="backup_create_snapshot"
					data-task-key="snapshot-now"
					data-agent="sentinel"
					data-title="<?php esc_attr_e( 'Create targeted snapshot', 'claw-agent' ); ?>"
					data-description="<?php esc_attr_e( 'Create a targeted 72-hour snapshot of changed files and database tables. Store with expiry metadata.', 'claw-agent' ); ?>"
				>
					<?php esc_html_e( 'Create Snapshot', 'claw-agent' ); ?>
				</button>
			</div>
		</div>
		<p style="margin: 0; color: var(--wpc-dim);">
			<?php
			printf(
				/* translators: 1: time since last backup, 2: time until next backup */
				esc_html__( 'Last backup: %1$s · Next: %2$s', 'claw-agent' ),
				'<strong>' . esc_html( $last_backup_display ) . '</strong>',
				esc_html( $next_backup_display )
			);
			?>
		</p>
	</div>

	<!-- ================================================================
		2. KPI CARDS — PHP-rendered from module get_state()
		================================================================ -->
	<div class="wpc-kpi-grid">

		<article class="wpc-kpi-card">
			<span class="wpc-badge wpc-badge--<?php echo esc_attr( $last_backup_badge_class ); ?>">
				<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $last_backup_at ? 'green' : 'yellow' ); ?>"></span>
				<?php echo esc_html( $last_backup_display ); ?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Last Backup', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $backup_count ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Total Backups', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value">
				<?php echo esc_html( $total_size_bytes > 0 ? size_format( $total_size_bytes ) : '—' ); ?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Storage Used', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span
				class="wpc-kpi-value wpc-badge wpc-badge--active"
				data-inline-edit="retention_days"
				data-target-id="wp_claw_backup_retention_days"
				data-current-value="<?php echo esc_attr( $retention_days ); ?>"
				style="cursor: pointer;"
			>
				<?php echo esc_html( number_format_i18n( $retention_days ) ); ?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Retention Days', 'claw-agent' ); ?></span>
		</article>

	</div>

	<!-- ================================================================
		3. DATABASE BACKUPS TABLE
		================================================================ -->
	<div class="wpc-card" style="margin-top: 20px; margin-bottom: 20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Database Backups', 'claw-agent' ); ?></h3>
		<?php if ( empty( $db_backups ) ) : ?>
			<div class="wpc-empty-state">
				<p class="wpc-empty-state__text">
					<?php esc_html_e( 'No database backups yet. Bastien creates full backups on the daily schedule, or click "Create Backup Now" above to create one immediately.', 'claw-agent' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $db_backups as $backup ) : ?>
						<?php
						$backup_id     = absint( $backup->id );
						$backup_date   = ! empty( $backup->created_at )
							? wp_date( 'M j, Y H:i', strtotime( $backup->created_at ) )
							: "\u{2014}";
						$backup_size   = ! empty( $backup->file_size_bytes )
							? size_format( (int) $backup->file_size_bytes )
							: "\u{2014}";
						$backup_status = sanitize_key( $backup->status ?? 'unknown' );
						$status_class  = 'complete' === $backup_status ? 'done' : ( 'failed' === $backup_status ? 'failed' : 'idle' );
						?>
						<tr>
							<td><?php echo esc_html( $backup_date ); ?></td>
							<td><?php echo esc_html( $backup_size ); ?></td>
							<td><?php echo esc_html( ucfirst( $backup->backup_type ?? 'backup' ) ); ?></td>
							<td>
								<span class="wpc-badge wpc-badge--<?php echo esc_attr( $status_class ); ?>">
									<?php echo esc_html( ucfirst( $backup_status ) ); ?>
								</span>
							</td>
							<td>
								<button
									class="wpc-btn wpc-btn--sm wpc-btn--ghost"
									type="button"
									data-agent-action="backup_restore"
									data-task-key="<?php echo esc_attr( 'restore-' . $backup_id ); ?>"
									data-agent="sentinel"
									data-title="<?php esc_attr_e( 'Restore from backup', 'claw-agent' ); ?>"
									data-description="<?php echo esc_attr( sprintf( /* translators: %d: backup ID */ __( 'Restore WordPress database from backup ID %d. Verify data integrity before and after restore. Create a pre-restore snapshot first.', 'claw-agent' ), $backup_id ) ); ?>"
								>
									<?php esc_html_e( 'Restore', 'claw-agent' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- ================================================================
		4. SNAPSHOTS TABLE
		================================================================ -->
	<div class="wpc-card" style="margin-bottom: 20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Targeted Snapshots', 'claw-agent' ); ?></h3>
		<?php if ( empty( $snapshots ) ) : ?>
			<div class="wpc-empty-state">
				<p class="wpc-empty-state__text">
					<?php esc_html_e( 'No snapshots yet. Bastien creates 72-hour targeted snapshots before risky operations such as plugin updates, theme changes, or content migrations.', 'claw-agent' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $snapshots as $snap ) : ?>
						<?php
						$snap_id     = absint( $snap->id );
						$snap_date   = ! empty( $snap->created_at )
							? wp_date( 'M j, Y H:i', strtotime( $snap->created_at ) )
							: "\u{2014}";
						$snap_size   = ! empty( $snap->file_size_bytes )
							? size_format( (int) $snap->file_size_bytes )
							: "\u{2014}";
						$snap_status = sanitize_key( $snap->status ?? 'unknown' );
						$snap_class  = 'complete' === $snap_status ? 'done' : ( 'failed' === $snap_status ? 'failed' : 'idle' );
						?>
						<tr>
							<td><?php echo esc_html( $snap_date ); ?></td>
							<td><?php echo esc_html( $snap_size ); ?></td>
							<td><?php echo esc_html( ucfirst( $snap->backup_type ?? 'snapshot' ) ); ?></td>
							<td>
								<span class="wpc-badge wpc-badge--<?php echo esc_attr( $snap_class ); ?>">
									<?php echo esc_html( ucfirst( $snap_status ) ); ?>
								</span>
							</td>
							<td>
								<button
									class="wpc-btn wpc-btn--sm wpc-btn--ghost"
									type="button"
									data-agent-action="backup_restore"
									data-task-key="<?php echo esc_attr( 'restore-' . $snap_id ); ?>"
									data-agent="sentinel"
									data-title="<?php esc_attr_e( 'Restore from snapshot', 'claw-agent' ); ?>"
									data-description="<?php echo esc_attr( sprintf( /* translators: %d: snapshot ID */ __( 'Restore WordPress from snapshot ID %d. Verify site integrity after restore. Log all restored items.', 'claw-agent' ), $snap_id ) ); ?>"
								>
									<?php esc_html_e( 'Restore', 'claw-agent' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- ================================================================
		5. RETENTION POLICY CARD
		================================================================ -->
	<div class="wpc-card" style="margin-bottom: 20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Retention Policy', 'claw-agent' ); ?></h3>
		<table class="wpc-detail-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep backups for', 'claw-agent' ); ?></th>
					<td>
						<span
							class="wpc-badge wpc-badge--active"
							data-inline-edit="retention_days"
							data-target-id="wp_claw_backup_retention_days"
							data-current-value="<?php echo esc_attr( $retention_days ); ?>"
							style="cursor: pointer; text-decoration: underline dotted;"
							title="<?php esc_attr_e( 'Click to edit retention period', 'claw-agent' ); ?>"
						>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of days */
									_n( '%d day', '%d days', $retention_days, 'claw-agent' ),
									$retention_days
								)
							);
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-delete expired', 'claw-agent' ); ?></th>
					<td>
						<span class="wpc-badge wpc-badge--done">
							<?php esc_html_e( 'Enabled', 'claw-agent' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Snapshot window', 'claw-agent' ); ?></th>
					<td><?php esc_html_e( '72 hours', 'claw-agent' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Schedule', 'claw-agent' ); ?></th>
					<td><?php esc_html_e( 'Daily (WP-Cron)', 'claw-agent' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- ================================================================
		6. RECENT BACKUP ACTIVITY — JS-loaded
		================================================================ -->
	<div class="wpc-card">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Recent Backup Activity', 'claw-agent' ); ?></h3>
		<div id="wpc-backup-activity" data-loaded="false">
			<div class="wpc-empty-state">
				<p class="wpc-empty-state__text"><?php esc_html_e( 'Loading activity...', 'claw-agent' ); ?></p>
			</div>
		</div>
	</div>

</div>

<script>
( function () {
	'use strict';

	/**
	 * After a short delay, replace the loading placeholder with a proper empty
	 * state message if the container has not been populated by JS yet.
	 *
	 * @param {string} containerId  ID of the container element.
	 * @param {string} message      Empty-state message to show.
	 */
	function wpClawSetEmptyState( containerId, message ) {
		setTimeout( function () {
			var container = document.getElementById( containerId );
			if ( ! container || container.getAttribute( 'data-loaded' ) === 'true' ) {
				return;
			}

			var wrap  = document.createElement( 'div' );
			var inner = document.createElement( 'p' );

			wrap.className  = 'wpc-empty-state';
			inner.className = 'wpc-empty-state__text';
			inner.textContent = message;
			wrap.appendChild( inner );

			while ( container.firstChild ) {
				container.removeChild( container.firstChild );
			}
			container.appendChild( wrap );
		}, 6000 );
	}

	wpClawSetEmptyState(
		'wpc-backup-activity',
		<?php echo wp_json_encode( __( 'Bastien will report backup activity here once the Klawty instance is connected.', 'claw-agent' ) ); ?>
	);
} )();
</script>
