<?php
/**
 * Agents admin view.
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;

// -------------------------------------------------------------------------
// Fetch agent data — transient cache (5 min) then live API.
// -------------------------------------------------------------------------
$agents    = get_transient( 'wp_claw_agents_cache' );
$api_error = false;

if ( false === $agents ) {
	$api_response = $api_client->get_agents();
	if ( is_wp_error( $api_response ) ) {
		$api_error = $api_response->get_error_message();
		$agents    = array();
	} elseif ( isset( $api_response['agents'] ) && is_array( $api_response['agents'] ) ) {
		$agents = $api_response['agents'];
		set_transient( 'wp_claw_agents_cache', $agents, 5 * MINUTE_IN_SECONDS );
	} else {
		$agents = array();
	}
}

/**
 * Map a raw health/status string to a safe CSS class suffix.
 *
 * @param string $status Raw status value.
 * @return string Sanitized CSS class suffix.
 */
$wp_claw_agent_status_class = function ( $status ) {
	$map = array(
		'ok'           => 'ok',
		'healthy'      => 'ok',
		'degraded'     => 'degraded',
		'idle'         => 'idle',
		'busy'         => 'running',
		'running'      => 'running',
		'error'        => 'failed',
		'failed'       => 'failed',
		'disconnected' => 'disconnected',
		'unknown'      => 'unknown',
	);
	$key = strtolower( sanitize_key( (string) $status ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : 'unknown';
};
?>
<div class="wrap wp-claw-admin-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $api_error ) : ?>
	<div class="notice notice-error wp-claw-admin-notice">
		<p>
			<?php
			printf(
				/* translators: %s: error message from the API */
				esc_html__( 'Cannot connect to Klawty instance: %s', 'wp-claw' ),
				'<strong>' . esc_html( $api_error ) . '</strong>'
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ); ?>">
				<?php esc_html_e( 'Check connection settings &rarr;', 'wp-claw' ); ?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( empty( $agents ) && ! $api_error ) : ?>
	<div class="notice notice-info wp-claw-admin-notice">
		<p><?php esc_html_e( 'No agents are currently reporting status. The Klawty instance may still be starting up.', 'wp-claw' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $agents ) ) : ?>
	<!-- ------------------------------------------------------------------ -->
	<!-- Agent grid                                                           -->
	<!-- ------------------------------------------------------------------ -->
	<div class="wp-claw-admin-agent-grid">

		<?php foreach ( $agents as $agent ) : ?>
			<?php
			if ( ! is_array( $agent ) ) {
				continue;
			}

			$agent_name         = sanitize_text_field( (string) ( isset( $agent['name'] ) ? $agent['name'] : '' ) );
			$agent_role         = sanitize_text_field( (string) ( isset( $agent['role'] ) ? $agent['role'] : '' ) );
			$agent_emoji        = sanitize_text_field( (string) ( isset( $agent['emoji'] ) ? $agent['emoji'] : '' ) );
			$agent_health       = sanitize_key( (string) ( isset( $agent['health'] ) ? $agent['health'] : 'unknown' ) );
			$agent_current_task = sanitize_text_field( (string) ( isset( $agent['current_task'] ) ? $agent['current_task'] : '' ) );
			$agent_uptime       = isset( $agent['uptime'] ) ? sanitize_text_field( (string) $agent['uptime'] ) : '';
			$agent_task_count   = isset( $agent['task_count'] ) ? (int) $agent['task_count'] : 0;
			$agent_cost_today   = isset( $agent['llm_cost_today'] ) ? (float) $agent['llm_cost_today'] : 0.0;
			$agent_last_seen    = isset( $agent['last_heartbeat'] ) ? sanitize_text_field( (string) $agent['last_heartbeat'] ) : '';
			$agent_model        = isset( $agent['model'] ) ? sanitize_text_field( (string) $agent['model'] ) : '';

			$status_class = $wp_claw_agent_status_class( $agent_health );
			?>
		<div class="wp-claw-admin-agent-card wp-claw-admin-agent-card--<?php echo esc_attr( sanitize_key( $agent_name ) ); ?>">

			<div class="wp-claw-admin-agent-card-header">

				<?php if ( '' !== $agent_emoji ) : ?>
				<span class="wp-claw-admin-agent-emoji" aria-hidden="true">
					<?php echo esc_html( $agent_emoji ); ?>
				</span>
				<?php endif; ?>

				<div class="wp-claw-admin-agent-identity">
					<strong class="wp-claw-admin-agent-name">
						<?php echo esc_html( '' !== $agent_name ? $agent_name : __( 'Unknown Agent', 'wp-claw' ) ); ?>
					</strong>
					<?php if ( '' !== $agent_role ) : ?>
					<span class="wp-claw-admin-agent-role">
						<?php echo esc_html( $agent_role ); ?>
					</span>
					<?php endif; ?>
				</div>

				<span
					class="wp-claw-admin-status-dot wp-claw-admin-status-<?php echo esc_attr( $status_class ); ?>"
					title="<?php echo esc_attr( ucfirst( $agent_health ) ); ?>"
				></span>

			</div><!-- /.wp-claw-admin-agent-card-header -->

			<div class="wp-claw-admin-agent-card-body">

				<p class="wp-claw-admin-agent-current-task">
					<?php if ( '' !== $agent_current_task ) : ?>
						<?php echo esc_html( $agent_current_task ); ?>
					<?php else : ?>
						<em class="wp-claw-admin-muted"><?php esc_html_e( 'Idle', 'wp-claw' ); ?></em>
					<?php endif; ?>
				</p>

				<dl class="wp-claw-admin-agent-meta">

					<dt><?php esc_html_e( 'Status', 'wp-claw' ); ?></dt>
					<dd>
						<span class="wp-claw-admin-status-pill wp-claw-admin-status-<?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( ucfirst( $agent_health ) ); ?>
						</span>
					</dd>

					<?php if ( $agent_task_count > 0 ) : ?>
					<dt><?php esc_html_e( 'Tasks today', 'wp-claw' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $agent_task_count ) ); ?></dd>
					<?php endif; ?>

					<?php if ( $agent_cost_today > 0 ) : ?>
					<dt><?php esc_html_e( 'AI cost today', 'wp-claw' ); ?></dt>
					<dd><?php echo esc_html( '€' . number_format( $agent_cost_today, 4 ) ); ?></dd>
					<?php endif; ?>

					<?php if ( '' !== $agent_uptime ) : ?>
					<dt><?php esc_html_e( 'Uptime', 'wp-claw' ); ?></dt>
					<dd><?php echo esc_html( $agent_uptime ); ?></dd>
					<?php endif; ?>

					<?php if ( '' !== $agent_model ) : ?>
					<dt><?php esc_html_e( 'Model', 'wp-claw' ); ?></dt>
					<dd><?php echo esc_html( $agent_model ); ?></dd>
					<?php endif; ?>

					<?php if ( '' !== $agent_last_seen ) : ?>
					<dt><?php esc_html_e( 'Last seen', 'wp-claw' ); ?></dt>
					<dd>
						<?php
						$ts = strtotime( $agent_last_seen );
						if ( $ts ) {
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time difference */
									__( '%s ago', 'wp-claw' ),
									human_time_diff( $ts )
								)
							);
						} else {
							echo esc_html( $agent_last_seen );
						}
						?>
					</dd>
					<?php endif; ?>

				</dl>

			</div><!-- /.wp-claw-admin-agent-card-body -->

		</div><!-- /.wp-claw-admin-agent-card -->
		<?php endforeach; ?>

	</div><!-- /.wp-claw-admin-agent-grid -->

	<p class="wp-claw-admin-section-footer">
		<em class="wp-claw-admin-muted">
			<?php esc_html_e( 'Agent data is cached for 5 minutes.', 'wp-claw' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'page', 'wp-claw-agents', admin_url( 'admin.php' ) ) ); ?>">
				<?php esc_html_e( 'Refresh', 'wp-claw' ); ?>
			</a>
		</em>
	</p>

	<?php endif; ?>

</div><!-- /.wrap -->
