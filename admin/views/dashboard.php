<?php
/**
 * Dashboard admin view.
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
// Data gathering
// -------------------------------------------------------------------------

// Recent tasks — last 10 rows from the local task log.
$tasks_table = $wpdb->prefix . 'wp_claw_tasks';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_tasks = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT task_id, agent, module, action, status, created_at FROM %i ORDER BY created_at DESC LIMIT %d',
		$tasks_table,
		10
	)
);

// Pending proposal count.
$proposals_table = $wpdb->prefix . 'wp_claw_proposals';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_proposals = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE status = %s',
		$proposals_table,
		'pending'
	)
);

// Total tasks count.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$total_tasks = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tasks_table )
);

// Tasks by status.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$status_counts_raw = $wpdb->get_results(
	$wpdb->prepare( 'SELECT status, COUNT(*) AS cnt FROM %i GROUP BY status', $tasks_table )
);
$status_counts = array();
if ( $status_counts_raw ) {
	foreach ( $status_counts_raw as $row ) {
		$status_counts[ sanitize_key( (string) $row->status ) ] = (int) $row->cnt;
	}
}
$done_count   = isset( $status_counts['done'] ) ? $status_counts['done'] : 0;
$failed_count = isset( $status_counts['failed'] ) ? $status_counts['failed'] : 0;

// Completion rate.
$completion_rate = $total_tasks > 0 ? round( ( $done_count / $total_tasks ) * 100 ) : 0;

// Tasks by priority.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$priority_counts_raw = $wpdb->get_results(
	$wpdb->prepare( 'SELECT priority, COUNT(*) AS cnt FROM %i GROUP BY priority', $tasks_table )
);
$priority_counts = array();
if ( $priority_counts_raw ) {
	foreach ( $priority_counts_raw as $row ) {
		$priority_counts[ sanitize_key( (string) $row->priority ) ] = (int) $row->cnt;
	}
}

// Tasks by tier.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$tier_counts_raw = $wpdb->get_results(
	$wpdb->prepare( 'SELECT tier, COUNT(*) AS cnt FROM %i GROUP BY tier', $tasks_table )
);
$tier_counts = array();
if ( $tier_counts_raw ) {
	foreach ( $tier_counts_raw as $row ) {
		$tier_counts[ sanitize_text_field( (string) $row->tier ) ] = (int) $row->cnt;
	}
}

// Tasks by agent (for performance table).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$agent_stats_raw = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT agent,
			COUNT(*) AS total,
			SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done,
			SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
			SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS active,
			SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS backlog
		FROM %i GROUP BY agent ORDER BY total DESC",
		$tasks_table
	)
);

// Agent data — try the 5-minute transient first, then a live API call.
$agents = get_transient( 'wp_claw_agents_cache' );

if ( false === $agents ) {
	$api_response = $api_client->get_agents();
	if ( ! is_wp_error( $api_response ) && isset( $api_response['agents'] ) ) {
		$agents = $api_response['agents'];
		set_transient( 'wp_claw_agents_cache', $agents, 5 * MINUTE_IN_SECONDS );
	} else {
		$agents = array();
	}
}

// Connection health.
$is_connected  = $api_client->is_connected();
$health_data   = get_transient( 'wp_claw_health_data' );
$health_status = ( is_array( $health_data ) && ! empty( $health_data['status'] ) )
	? sanitize_text_field( $health_data['status'] )
	: ( $is_connected ? 'ok' : 'disconnected' );

// -------------------------------------------------------------------------
// Status badge helper (inline — views are included, not autoloaded).
// -------------------------------------------------------------------------

/**
 * Return a sanitized CSS class token for a task/agent status string.
 *
 * @param string $status Raw status value.
 * @return string Safe CSS class suffix.
 */
$wp_claw_safe_status_class = function ( $status ) {
	$allowed = array(
		'pending'      => 'pending',
		'running'      => 'running',
		'done'         => 'done',
		'failed'       => 'failed',
		'ok'           => 'ok',
		'degraded'     => 'degraded',
		'disconnected' => 'disconnected',
		'healthy'      => 'ok',
		'idle'         => 'idle',
		'unknown'      => 'unknown',
	);
	$key     = strtolower( sanitize_key( (string) $status ) );
	return isset( $allowed[ $key ] ) ? $allowed[ $key ] : 'unknown';
};

/**
 * Map a status string to a badge CSS modifier.
 *
 * @param string $status Raw status value.
 * @return string Badge modifier class.
 */
$wp_claw_badge_class = function ( $status ) {
	$map = array(
		'done'             => 'done',
		'failed'           => 'failed',
		'running'          => 'active',
		'pending'          => 'pending',
		'pending_approval' => 'pending',
		'proposed'         => 'pending',
		'backlog'          => 'idle',
		'idle'             => 'idle',
	);
	$key = strtolower( sanitize_key( (string) $status ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : 'pending';
};
?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- Connection Banner -->
	<?php if ( $is_connected ) : ?>
	<div class="wpc-connection-banner wpc-connection-banner--connected">
		<span class="wpc-status-dot wpc-status-dot--green"></span>
		<span>
			<?php esc_html_e( 'Connected to Klawty instance', 'claw-agent' ); ?>
			<?php if ( 'degraded' === $health_status ) : ?>
				<span class="wpc-badge wpc-badge--pending"><?php esc_html_e( 'Degraded', 'claw-agent' ); ?></span>
			<?php endif; ?>
		</span>
	</div>
	<?php else : ?>
	<div class="wpc-connection-banner wpc-connection-banner--disconnected">
		<span class="wpc-status-dot wpc-status-dot--red"></span>
		<span>
			<?php
			printf(
				/* translators: %s: Link to settings page */
				esc_html__( 'Not connected to Klawty instance. %s to configure.', 'claw-agent' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
			);
			?>
		</span>
	</div>
	<?php endif; ?>

	<!-- KPI Cards -->
	<section class="wpc-kpi-grid">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $total_tasks ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Total Tasks', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<div class="wpc-donut" data-percent="<?php echo esc_attr( $completion_rate ); ?>">
				<span class="wpc-kpi-value"><?php echo esc_html( $completion_rate ); ?>%</span>
			</div>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Completion Rate', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $pending_proposals > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $pending_proposals ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Pending Proposals', 'claw-agent' ); ?></span>
			<?php if ( $pending_proposals > 0 ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>" class="wpc-btn wpc-btn--ghost">
					<?php esc_html_e( 'Review', 'claw-agent' ); ?>
				</a>
			<?php endif; ?>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( count( $agents ) ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Agents Active', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- Tasks by Status -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Tasks by Status', 'claw-agent' ); ?></h2>
		<div class="wpc-tier-badges">
			<?php
			$all_statuses = array( 'backlog', 'pending', 'running', 'done', 'failed', 'pending_approval', 'proposed' );
			foreach ( $all_statuses as $st ) :
				$cnt = isset( $status_counts[ $st ] ) ? $status_counts[ $st ] : 0;
				if ( 0 === $cnt ) {
					continue;
				}
				?>
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $wp_claw_badge_class( $st ) ); ?>">
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', $st ) ) ); ?>
					<strong><?php echo esc_html( number_format_i18n( $cnt ) ); ?></strong>
				</span>
			<?php endforeach; ?>
			<?php if ( empty( $status_counts ) ) : ?>
				<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'No tasks yet', 'claw-agent' ); ?></span>
			<?php endif; ?>
		</div>
	</section>

	<!-- Tasks by Priority -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Tasks by Priority', 'claw-agent' ); ?></h2>
		<div class="wpc-tier-badges">
			<?php
			$priority_colors = array(
				'critical' => 'red',
				'high'     => 'yellow',
				'medium'   => 'green',
				'low'      => 'green',
			);
			foreach ( $priority_colors as $pri => $color ) :
				$cnt = isset( $priority_counts[ $pri ] ) ? $priority_counts[ $pri ] : 0;
				if ( 0 === $cnt ) {
					continue;
				}
				?>
				<span class="wpc-badge">
					<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $color ); ?>"></span>
					<?php echo esc_html( ucfirst( $pri ) ); ?>
					<strong><?php echo esc_html( number_format_i18n( $cnt ) ); ?></strong>
				</span>
			<?php endforeach; ?>
			<?php if ( empty( $priority_counts ) ) : ?>
				<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'No priority data', 'claw-agent' ); ?></span>
			<?php endif; ?>
		</div>
	</section>

	<!-- Autonomy Tiers -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Autonomy Tiers', 'claw-agent' ); ?></h2>
		<div class="wpc-tier-badges">
			<?php
			$tier_labels = array( 'AUTO', 'AUTO+', 'PROPOSE', 'CONFIRM' );
			foreach ( $tier_labels as $tier ) :
				$cnt = isset( $tier_counts[ $tier ] ) ? $tier_counts[ $tier ] : 0;
				?>
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $cnt > 0 ? 'active' : 'idle' ); ?>">
					<?php echo esc_html( $tier ); ?>
					<strong><?php echo esc_html( number_format_i18n( $cnt ) ); ?></strong>
				</span>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- Agent Performance -->
	<?php if ( ! empty( $agent_stats_raw ) ) : ?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Agent Performance', 'claw-agent' ); ?></h2>
		<table class="wpc-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Agent', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Done', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Failed', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Active', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Backlog', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completion', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $agent_stats_raw as $stat ) : ?>
					<?php
					$agent_total      = (int) $stat->total;
					$agent_done       = (int) $stat->done;
					$agent_failed     = (int) $stat->failed;
					$agent_active     = (int) $stat->active;
					$agent_backlog    = (int) $stat->backlog;
					$agent_pct        = $agent_total > 0 ? round( ( $agent_done / $agent_total ) * 100 ) : 0;
					?>
				<tr>
					<td>
						<strong><?php echo esc_html( ucfirst( sanitize_text_field( (string) $stat->agent ) ) ); ?></strong>
					</td>
					<td><?php echo esc_html( number_format_i18n( $agent_total ) ); ?></td>
					<td>
						<span class="wpc-badge wpc-badge--done"><?php echo esc_html( number_format_i18n( $agent_done ) ); ?></span>
					</td>
					<td>
						<?php if ( $agent_failed > 0 ) : ?>
							<span class="wpc-badge wpc-badge--failed"><?php echo esc_html( number_format_i18n( $agent_failed ) ); ?></span>
						<?php else : ?>
							<?php echo esc_html( '0' ); ?>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $agent_active ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $agent_backlog ) ); ?></td>
					<td>
						<div class="wpc-progress-bar">
							<div class="wpc-agent-bar" data-percent="<?php echo esc_attr( $agent_pct ); ?>"></div>
						</div>
						<span><?php echo esc_html( $agent_pct ); ?>%</span>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>

	<!-- Recent Activity Feed -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Recent Activity', 'claw-agent' ); ?></h2>

		<?php if ( empty( $recent_tasks ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No tasks recorded yet. Activity will appear here once your agents start working.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
		<div class="wpc-activity-feed">
			<?php foreach ( $recent_tasks as $task ) : ?>
			<div class="wpc-activity-item">
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $wp_claw_badge_class( (string) $task->status ) ); ?>">
					<?php echo esc_html( ucfirst( sanitize_text_field( (string) $task->agent ) ) ); ?>
				</span>
				<span>
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', sanitize_text_field( (string) $task->action ) ) ) ); ?>
					<?php if ( ! empty( $task->module ) ) : ?>
						&mdash; <?php echo esc_html( ucfirst( sanitize_text_field( (string) $task->module ) ) ); ?>
					<?php endif; ?>
				</span>
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $wp_claw_badge_class( (string) $task->status ) ); ?>">
					<?php echo esc_html( ucfirst( sanitize_text_field( (string) $task->status ) ) ); ?>
				</span>
				<?php if ( ! empty( $task->created_at ) ) : ?>
				<time>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time difference */
							__( '%s ago', 'claw-agent' ),
							human_time_diff( strtotime( $task->created_at ) )
						)
					);
					?>
				</time>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<!-- Agent Team Grid -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Agent Team', 'claw-agent' ); ?></h2>

		<?php if ( empty( $agents ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No agent data available. Check your Klawty connection.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
		<div class="wpc-kpi-grid">
			<?php foreach ( $agents as $agent ) : ?>
				<?php
				if ( ! is_array( $agent ) ) {
					continue;
				}
				$agent_name         = isset( $agent['name'] ) ? sanitize_text_field( (string) $agent['name'] ) : '';
				$agent_role         = isset( $agent['role'] ) ? sanitize_text_field( (string) $agent['role'] ) : '';
				$agent_emoji        = isset( $agent['emoji'] ) ? sanitize_text_field( (string) $agent['emoji'] ) : '';
				$agent_health       = isset( $agent['health'] ) ? sanitize_key( (string) $agent['health'] ) : 'unknown';
				$agent_current_task = isset( $agent['current_task'] ) ? sanitize_text_field( (string) $agent['current_task'] ) : '';
				?>
			<article class="wpc-kpi-card">
				<header>
					<?php if ( '' !== $agent_emoji ) : ?>
					<span aria-hidden="true"><?php echo esc_html( $agent_emoji ); ?></span>
					<?php endif; ?>
					<strong><?php echo esc_html( $agent_name ); ?></strong>
					<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( 'ok' === $agent_health || 'healthy' === $agent_health ? 'green' : ( 'degraded' === $agent_health ? 'yellow' : 'red' ) ); ?>"
						title="<?php echo esc_attr( ucfirst( $agent_health ) ); ?>"></span>
				</header>
				<?php if ( '' !== $agent_role ) : ?>
				<span class="wpc-kpi-label"><?php echo esc_html( $agent_role ); ?></span>
				<?php endif; ?>
				<p>
					<?php if ( '' !== $agent_current_task ) : ?>
						<?php echo esc_html( $agent_current_task ); ?>
					<?php else : ?>
						<em><?php esc_html_e( 'Idle', 'claw-agent' ); ?></em>
					<?php endif; ?>
				</p>
			</article>
			<?php endforeach; ?>
		</div>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-agents' ) ); ?>" class="wpc-btn wpc-btn--ghost">
				<?php esc_html_e( 'View full agent team', 'claw-agent' ); ?>
			</a>
		</p>
		<?php endif; ?>
	</section>

</div>
