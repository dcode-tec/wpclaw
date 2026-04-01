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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

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

/**
 * Map health status to a status dot color.
 *
 * @param string $health Raw health string.
 * @return string Dot color class suffix.
 */
$wp_claw_health_dot = function ( $health ) {
	$key = strtolower( sanitize_key( (string) $health ) );
	if ( in_array( $key, array( 'ok', 'healthy' ), true ) ) {
		return 'green';
	}
	if ( in_array( $key, array( 'degraded', 'idle', 'busy', 'running' ), true ) ) {
		return 'yellow';
	}
	return 'red';
};

/**
 * Map health status to a badge modifier.
 *
 * @param string $health Raw health string.
 * @return string Badge modifier.
 */
$wp_claw_health_badge = function ( $health ) {
	$key = strtolower( sanitize_key( (string) $health ) );
	if ( in_array( $key, array( 'ok', 'healthy' ), true ) ) {
		return 'active';
	}
	if ( in_array( $key, array( 'idle' ), true ) ) {
		return 'idle';
	}
	if ( in_array( $key, array( 'error', 'failed', 'disconnected' ), true ) ) {
		return 'error';
	}
	return 'pending';
};

// Agent-to-module mapping (v1.2.0).
$wp_claw_agent_modules = array(
	'architect' => array( 'audit', 'forms' ),
	'scribe'    => array( 'seo', 'content', 'social' ),
	'sentinel'  => array( 'security', 'backup' ),
	'commerce'  => array( 'commerce', 'crm' ),
	'analyst'   => array( 'analytics', 'performance' ),
	'concierge' => array( 'chat' ),
);

$wp_claw_agent_display_names = array(
	'architect' => __( 'Karim — The Architect', 'claw-agent' ),
	'scribe'    => __( 'Lina — The Scribe', 'claw-agent' ),
	'sentinel'  => __( 'Bastien — The Sentinel', 'claw-agent' ),
	'commerce'  => __( 'Hugo — Commerce Lead', 'claw-agent' ),
	'analyst'   => __( 'Selma — The Analyst', 'claw-agent' ),
	'concierge' => __( 'Marc — The Concierge', 'claw-agent' ),
);

$wp_claw_agent_dashboard = array(
	'architect' => 'claw-agent',
	'scribe'    => 'wp-claw-seo',
	'sentinel'  => 'wp-claw-security',
	'commerce'  => 'wp-claw-commerce',
	'analyst'   => 'claw-agent',
	'concierge' => 'claw-agent',
);
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( $api_error ) : ?>
	<div class="wpc-connection-banner wpc-connection-banner--disconnected">
		<span class="wpc-status-dot wpc-status-dot--red"></span>
		<span>
			<?php
			printf(
				/* translators: %s: error message from the API */
				esc_html__( 'Cannot connect to Klawty instance: %s', 'claw-agent' ),
				'<strong>' . esc_html( $api_error ) . '</strong>'
			);
			?>
			&mdash;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ); ?>">
				<?php esc_html_e( 'Check connection settings', 'claw-agent' ); ?>
			</a>
		</span>
	</div>
	<?php endif; ?>

	<?php if ( empty( $agents ) && ! $api_error ) : ?>
	<div class="wpc-empty-state">
		<p><?php esc_html_e( 'No agents are currently reporting status. The Klawty instance may still be starting up.', 'claw-agent' ); ?></p>
	</div>
	<?php endif; ?>

	<?php if ( empty( $agents ) && $api_error ) : ?>
		<!-- Local module state fallback (v1.2.0) -->
		<div class="wpc-connection-banner wpc-connection-banner--disconnected" style="margin-bottom: 16px;">
			<span class="wpc-status-dot wpc-status-dot--yellow"></span>
			<span><?php esc_html_e( 'Live agent status unavailable — showing local module data.', 'claw-agent' ); ?></span>
		</div>

		<div class="wpc-module-grid">
			<?php
			$plugin = \WPClaw\WP_Claw::get_instance();
			foreach ( $wp_claw_agent_modules as $agent_slug => $module_slugs ) :
				$agent_name = isset( $wp_claw_agent_display_names[ $agent_slug ] ) ? $wp_claw_agent_display_names[ $agent_slug ] : ucfirst( $agent_slug );
				$has_data = false;
				$module_states = array();
				foreach ( $module_slugs as $mod_slug ) {
					$mod = $plugin->get_module( $mod_slug );
					if ( null !== $mod ) {
						$module_states[ $mod_slug ] = $mod->get_state();
						$has_data = true;
					}
				}
				?>
				<article class="wpc-module-card">
					<header>
						<div><strong><?php echo esc_html( $agent_name ); ?></strong></div>
						<span class="wpc-badge wpc-badge--idle">
							<span class="wpc-status-dot wpc-status-dot--yellow"></span>
							<?php esc_html_e( 'Offline', 'claw-agent' ); ?>
						</span>
					</header>
					<div>
						<p class="wpc-kpi-label">
							<?php
							$enabled_modules = (array) get_option( 'wp_claw_enabled_modules', array() );
							$module_labels   = array();
							foreach ( $module_slugs as $mod_slug ) {
								$is_on = in_array( $mod_slug, $enabled_modules, true );
								$module_labels[] = $is_on
									? '<span class="wpc-badge wpc-badge--active" style="font-size:0.6875rem">' . esc_html( ucfirst( $mod_slug ) ) . '</span>'
									: '<span class="wpc-badge wpc-badge--idle" style="font-size:0.6875rem">' . esc_html( ucfirst( $mod_slug ) ) . '</span>';
							}
							echo wp_kses_post( implode( ' ', $module_labels ) );
							?>
						</p>
						<?php if ( ! $has_data ) : ?>
							<p><small><?php esc_html_e( 'Enable modules in Settings to activate this agent.', 'claw-agent' ); ?></small></p>
						<?php endif; ?>
					</div>
					<?php
					$dash_page = isset( $wp_claw_agent_dashboard[ $agent_slug ] ) ? $wp_claw_agent_dashboard[ $agent_slug ] : 'claw-agent';
					?>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $dash_page ) ); ?>" class="wpc-btn wpc-btn--ghost wpc-btn--sm">
							<?php esc_html_e( 'View Dashboard', 'claw-agent' ); ?>
						</a>
					</p>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $agents ) ) : ?>
	<div class="wpc-module-grid">

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
			?>
		<article class="wpc-module-card">

			<header>
				<div>
					<?php if ( '' !== $agent_emoji ) : ?>
					<span aria-hidden="true"><?php echo esc_html( $agent_emoji ); ?></span>
					<?php endif; ?>
					<strong><?php echo esc_html( '' !== $agent_name ? $agent_name : __( 'Unknown Agent', 'claw-agent' ) ); ?></strong>
				</div>
				<span class="wpc-badge wpc-badge--<?php echo esc_attr( $wp_claw_health_badge( $agent_health ) ); ?>">
					<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $wp_claw_health_dot( $agent_health ) ); ?>"></span>
					<?php echo esc_html( ucfirst( $agent_health ) ); ?>
				</span>
			</header>

			<?php if ( '' !== $agent_role ) : ?>
			<p class="wpc-kpi-label"><?php echo esc_html( $agent_role ); ?></p>
			<?php endif; ?>

			<div>
				<p>
					<?php if ( '' !== $agent_current_task ) : ?>
						<strong><?php esc_html_e( 'Current:', 'claw-agent' ); ?></strong>
						<?php echo esc_html( $agent_current_task ); ?>
					<?php else : ?>
						<em><?php esc_html_e( 'Idle — no active task', 'claw-agent' ); ?></em>
					<?php endif; ?>
				</p>
			</div>

			<table class="wpc-agent-table">
				<tbody>
					<?php if ( '' !== $agent_uptime ) : ?>
					<tr>
						<td><?php esc_html_e( 'Uptime', 'claw-agent' ); ?></td>
						<td><?php echo esc_html( $agent_uptime ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<td><?php esc_html_e( 'Tasks today', 'claw-agent' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $agent_task_count ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'AI cost today', 'claw-agent' ); ?></td>
						<td><?php echo esc_html( number_format( $agent_cost_today, 4 ) . ' EUR' ); ?></td>
					</tr>
					<?php if ( '' !== $agent_model ) : ?>
					<tr>
						<td><?php esc_html_e( 'Model', 'claw-agent' ); ?></td>
						<td><code><?php echo esc_html( $agent_model ); ?></code></td>
					</tr>
					<?php endif; ?>
					<?php if ( '' !== $agent_last_seen ) : ?>
					<tr>
						<td><?php esc_html_e( 'Last seen', 'claw-agent' ); ?></td>
						<td>
							<?php
							$ts = strtotime( $agent_last_seen );
							if ( $ts ) {
								echo esc_html(
									sprintf(
										/* translators: %s: human-readable time difference */
										__( '%s ago', 'claw-agent' ),
										human_time_diff( $ts )
									)
								);
							} else {
								echo esc_html( $agent_last_seen );
							}
							?>
						</td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php
			// Module badges (v1.2.0).
			$card_agent_slug = sanitize_key( (string) ( isset( $agent['name'] ) ? strtolower( str_replace( ' ', '', $agent['name'] ) ) : '' ) );
			// Try to find matching slug from display names.
			foreach ( $wp_claw_agent_display_names as $slug => $display ) {
				if ( stripos( $display, $agent_name ) !== false || $slug === $card_agent_slug ) {
					$card_agent_slug = $slug;
					break;
				}
			}
			$card_modules = isset( $wp_claw_agent_modules[ $card_agent_slug ] ) ? $wp_claw_agent_modules[ $card_agent_slug ] : array();
			if ( ! empty( $card_modules ) ) :
			?>
			<div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 4px;">
				<?php foreach ( $card_modules as $mod_slug ) : ?>
					<span class="wpc-badge wpc-badge--idle"><?php echo esc_html( ucfirst( $mod_slug ) ); ?></span>
				<?php endforeach; ?>
			</div>
			<?php
				$card_dash = isset( $wp_claw_agent_dashboard[ $card_agent_slug ] ) ? $wp_claw_agent_dashboard[ $card_agent_slug ] : 'claw-agent';
			?>
			<p style="margin-top: 8px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $card_dash ) ); ?>" class="wpc-btn wpc-btn--ghost wpc-btn--sm">
					<?php esc_html_e( 'View Dashboard', 'claw-agent' ); ?>
				</a>
			</p>
			<?php endif; ?>

		</article>
		<?php endforeach; ?>

	</div>

	<p class="wpc-kpi-label">
		<?php esc_html_e( 'Agent data is cached for 5 minutes.', 'claw-agent' ); ?>
		<a href="<?php echo esc_url( add_query_arg( 'page', 'wp-claw-agents', admin_url( 'admin.php' ) ) ); ?>" class="wpc-btn wpc-btn--ghost">
			<?php esc_html_e( 'Refresh', 'claw-agent' ); ?>
		</a>
	</p>

	<?php endif; ?>

</div>
