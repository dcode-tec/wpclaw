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

global $wpdb;

// -------------------------------------------------------------------------
// Data gathering
// -------------------------------------------------------------------------

// Recent tasks — last 10 rows from the local task log.
$tasks_table = $wpdb->prefix . 'wp_claw_tasks';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_tasks = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT task_id, agent, module, action, status, created_at
		 FROM {$tasks_table}
		 ORDER BY created_at DESC
		 LIMIT %d",
		10
	)
);

// Pending proposal count.
$proposals_table   = $wpdb->prefix . 'wp_claw_proposals';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_proposals = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$proposals_table} WHERE status = %s",
		'pending'
	)
);

// Total tasks count.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$total_tasks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tasks_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
$is_connected   = $api_client->is_connected();
$health_data    = get_transient( 'wp_claw_health_data' );
$health_status  = ( is_array( $health_data ) && ! empty( $health_data['status'] ) )
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
		'pending'    => 'pending',
		'running'    => 'running',
		'done'       => 'done',
		'failed'     => 'failed',
		'ok'         => 'ok',
		'degraded'   => 'degraded',
		'disconnected' => 'disconnected',
		'healthy'    => 'ok',
		'idle'       => 'idle',
		'unknown'    => 'unknown',
	);
	$key = strtolower( sanitize_key( (string) $status ) );
	return isset( $allowed[ $key ] ) ? $allowed[ $key ] : 'unknown';
};
?>
<div class="wrap wp-claw-admin-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- KPI Row                                                              -->
	<!-- ------------------------------------------------------------------ -->
	<div class="wp-claw-admin-kpi-grid">

		<div class="wp-claw-admin-kpi-card">
			<span class="wp-claw-admin-kpi-value"><?php echo esc_html( number_format_i18n( $total_tasks ) ); ?></span>
			<span class="wp-claw-admin-kpi-label"><?php esc_html_e( 'Total Tasks', 'wp-claw' ); ?></span>
		</div>

		<div class="wp-claw-admin-kpi-card <?php echo esc_attr( $pending_proposals > 0 ? 'wp-claw-admin-kpi-card--alert' : '' ); ?>">
			<span class="wp-claw-admin-kpi-value"><?php echo esc_html( number_format_i18n( $pending_proposals ) ); ?></span>
			<span class="wp-claw-admin-kpi-label">
				<?php esc_html_e( 'Pending Proposals', 'wp-claw' ); ?>
			</span>
			<?php if ( $pending_proposals > 0 ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>" class="wp-claw-admin-kpi-action">
					<?php esc_html_e( 'Review', 'wp-claw' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="wp-claw-admin-kpi-card">
			<span class="wp-claw-admin-kpi-value"><?php echo esc_html( number_format_i18n( count( $agents ) ) ); ?></span>
			<span class="wp-claw-admin-kpi-label"><?php esc_html_e( 'Active Agents', 'wp-claw' ); ?></span>
		</div>

		<div class="wp-claw-admin-kpi-card wp-claw-admin-kpi-card--health">
			<span class="wp-claw-admin-status-dot wp-claw-admin-status-<?php echo esc_attr( $wp_claw_safe_status_class( $health_status ) ); ?>"></span>
			<span class="wp-claw-admin-kpi-value wp-claw-admin-kpi-value--status">
				<?php
				if ( 'ok' === $health_status ) {
					esc_html_e( 'Healthy', 'wp-claw' );
				} elseif ( 'degraded' === $health_status ) {
					esc_html_e( 'Degraded', 'wp-claw' );
				} else {
					esc_html_e( 'Disconnected', 'wp-claw' );
				}
				?>
			</span>
			<span class="wp-claw-admin-kpi-label"><?php esc_html_e( 'Klawty Status', 'wp-claw' ); ?></span>
		</div>

	</div><!-- /.wp-claw-admin-kpi-grid -->

	<!-- ------------------------------------------------------------------ -->
	<!-- Connection notice (shown when disconnected)                         -->
	<!-- ------------------------------------------------------------------ -->
	<?php if ( ! $is_connected ) : ?>
	<div class="notice notice-warning wp-claw-admin-notice">
		<p>
			<?php
			printf(
				/* translators: %s: Link to settings page */
				esc_html__( 'WP-Claw is not connected to a Klawty instance. %s to configure the connection.', 'wp-claw' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'wp-claw' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php endif; ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- Recent Tasks                                                         -->
	<!-- ------------------------------------------------------------------ -->
	<div class="wp-claw-admin-section">

		<h2><?php esc_html_e( 'Recent Tasks', 'wp-claw' ); ?></h2>

		<?php if ( empty( $recent_tasks ) ) : ?>
			<p class="wp-claw-admin-empty"><?php esc_html_e( 'No tasks recorded yet.', 'wp-claw' ); ?></p>
		<?php else : ?>
		<ul class="wp-claw-admin-task-list">
			<?php foreach ( $recent_tasks as $task ) : ?>
			<li class="wp-claw-admin-task-item">

				<span class="wp-claw-admin-agent-badge wp-claw-admin-agent-badge--<?php echo esc_attr( sanitize_key( (string) $task->agent ) ); ?>">
					<?php echo esc_html( ucfirst( (string) $task->agent ) ); ?>
				</span>

				<span class="wp-claw-admin-task-title">
					<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $task->action ) ) ); ?>
					<?php if ( ! empty( $task->module ) ) : ?>
						<span class="wp-claw-admin-task-module">&mdash; <?php echo esc_html( ucfirst( (string) $task->module ) ); ?></span>
					<?php endif; ?>
				</span>

				<span class="wp-claw-admin-status-pill wp-claw-admin-status-<?php echo esc_attr( $wp_claw_safe_status_class( (string) $task->status ) ); ?>">
					<?php echo esc_html( ucfirst( (string) $task->status ) ); ?>
				</span>

				<?php if ( ! empty( $task->created_at ) ) : ?>
				<span class="wp-claw-admin-task-age">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: human-readable time difference */
							__( '%s ago', 'wp-claw' ),
							human_time_diff( strtotime( $task->created_at ) )
						)
					);
					?>
				</span>
				<?php endif; ?>

			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>

		<p class="wp-claw-admin-section-footer">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-agents' ) ); ?>">
				<?php esc_html_e( 'View agent team &rarr;', 'wp-claw' ); ?>
			</a>
		</p>

	</div><!-- /.wp-claw-admin-section -->

	<!-- ------------------------------------------------------------------ -->
	<!-- Agent Team                                                           -->
	<!-- ------------------------------------------------------------------ -->
	<div class="wp-claw-admin-section wp-claw-admin-dashboard">

		<h2><?php esc_html_e( 'Agent Team', 'wp-claw' ); ?></h2>

		<?php if ( empty( $agents ) ) : ?>
			<p class="wp-claw-admin-empty">
				<?php esc_html_e( 'No agent data available. Check your Klawty connection.', 'wp-claw' ); ?>
			</p>
		<?php else : ?>
		<div class="wp-claw-admin-agent-grid">
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
			$agent_cost         = isset( $agent['llm_cost_today'] ) ? (float) $agent['llm_cost_today'] : 0.0;
			$agent_task_count   = isset( $agent['task_count'] ) ? (int) $agent['task_count'] : 0;
			?>
			<div class="wp-claw-admin-agent-card">
				<div class="wp-claw-admin-agent-card-header">
					<?php if ( '' !== $agent_emoji ) : ?>
					<span class="wp-claw-admin-agent-emoji" aria-hidden="true"><?php echo esc_html( $agent_emoji ); ?></span>
					<?php endif; ?>
					<div class="wp-claw-admin-agent-identity">
						<strong class="wp-claw-admin-agent-name"><?php echo esc_html( $agent_name ); ?></strong>
						<?php if ( '' !== $agent_role ) : ?>
						<span class="wp-claw-admin-agent-role"><?php echo esc_html( $agent_role ); ?></span>
						<?php endif; ?>
					</div>
					<span class="wp-claw-admin-status-dot wp-claw-admin-status-<?php echo esc_attr( $wp_claw_safe_status_class( $agent_health ) ); ?>"
						  title="<?php echo esc_attr( ucfirst( $agent_health ) ); ?>"></span>
				</div>
				<div class="wp-claw-admin-agent-card-body">
					<p class="wp-claw-admin-agent-task">
						<?php if ( '' !== $agent_current_task ) : ?>
							<?php echo esc_html( $agent_current_task ); ?>
						<?php else : ?>
							<em><?php esc_html_e( 'Idle', 'wp-claw' ); ?></em>
						<?php endif; ?>
					</p>
					<dl class="wp-claw-admin-agent-meta">
						<dt><?php esc_html_e( 'Tasks today', 'wp-claw' ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( $agent_task_count ) ); ?></dd>
						<dt><?php esc_html_e( 'AI cost today', 'wp-claw' ); ?></dt>
						<dd><?php echo esc_html( '€' . number_format( $agent_cost, 4 ) ); ?></dd>
					</dl>
				</div>
			</div>
			<?php endforeach; ?>
		</div><!-- /.wp-claw-admin-agent-grid -->
		<?php endif; ?>

	</div><!-- /.wp-claw-admin-section -->

</div><!-- /.wrap -->
