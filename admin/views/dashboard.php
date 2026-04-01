<?php
/**
 * Dashboard admin view — v1.2.0 rewrite.
 *
 * Module-aware status cards, constitutional banners, 6-column KPI row,
 * agent performance table, and activity feed.
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

// -------------------------------------------------------------------------
// Agent display names — maps slug to "Name — The Role" format.
// -------------------------------------------------------------------------
$wp_claw_agent_display_names = array(
	'architect' => __( 'Karim — The Architect', 'claw-agent' ),
	'scribe'    => __( 'Lina — The Scribe', 'claw-agent' ),
	'sentinel'  => __( 'Bastien — The Sentinel', 'claw-agent' ),
	'commerce'  => __( 'Hugo — Commerce Lead', 'claw-agent' ),
	'analyst'   => __( 'Selma — The Analyst', 'claw-agent' ),
	'concierge' => __( 'Marc — The Concierge', 'claw-agent' ),
);

// -------------------------------------------------------------------------
// Data gathering
// -------------------------------------------------------------------------

$plugin       = \WPClaw\WP_Claw::get_instance();
$tasks_table  = $wpdb->prefix . 'wp_claw_tasks';
$today        = current_time( 'Y-m-d' );

// Module states — loop through all known modules, collect state for enabled ones.
$module_slugs = array( 'seo', 'security', 'content', 'crm', 'commerce', 'performance', 'forms', 'analytics', 'backup', 'social', 'chat', 'audit' );
$states       = array();
foreach ( $module_slugs as $ms ) {
	$mod = $plugin->get_module( $ms );
	if ( null !== $mod ) {
		$states[ $ms ] = $mod->get_state();
	}
}

// Constitutional constraints.
$t3_count  = (int) get_transient( 'wp_claw_t3_daily_count' );
$is_halted = (bool) get_option( 'wp_claw_operations_halted' );

// Tasks today.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$tasks_today = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM %i WHERE DATE(created_at) = %s",
		$tasks_table,
		$today
	)
);

// Total tasks.
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
$done_count      = isset( $status_counts['done'] ) ? $status_counts['done'] : 0;
$failed_count    = isset( $status_counts['failed'] ) ? $status_counts['failed'] : 0;
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

// Tasks by agent.
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

// Pending proposals.
$proposals_table = $wpdb->prefix . 'wp_claw_proposals';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_proposals = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE status = %s',
		$proposals_table,
		'pending'
	)
);

// Agent data — transient cache first, then API fallback.
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

// Recent activity — last 20 rows.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$recent_tasks = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT task_id, agent, module, action, status, created_at FROM %i ORDER BY created_at DESC LIMIT %d',
		$tasks_table,
		20
	)
);

// -------------------------------------------------------------------------
// Module-specific data extraction (safe access).
// -------------------------------------------------------------------------
$sec = isset( $states['security'] ) ? $states['security'] : array();
$seo = isset( $states['seo'] ) ? $states['seo'] : array();
$com = isset( $states['commerce'] ) ? $states['commerce'] : array();
$ana = isset( $states['analytics'] ) ? $states['analytics'] : array();
$crm = isset( $states['crm'] ) ? $states['crm'] : array();
$aud = isset( $states['audit'] ) ? $states['audit'] : array();
$cht = isset( $states['chat'] ) ? $states['chat'] : array();

// Security.
$failed_logins     = isset( $sec['failed_logins_24h'] ) ? (int) $sec['failed_logins_24h'] : 0;
$blocked_ips       = isset( $sec['blocked_ips_count'] ) ? (int) $sec['blocked_ips_count'] : 0;
$file_integrity    = isset( $sec['file_integrity_status'] ) ? sanitize_key( $sec['file_integrity_status'] ) : 'scan_pending';
$quarantined       = isset( $sec['quarantined_file_count'] ) ? (int) $sec['quarantined_file_count'] : 0;
$ssl_valid         = isset( $sec['ssl_valid'] ) ? (bool) $sec['ssl_valid'] : false;
$ssl_days          = isset( $sec['ssl_days_remaining'] ) ? (int) $sec['ssl_days_remaining'] : 0;
$sec_headers       = isset( $sec['security_headers_active'] ) ? (int) $sec['security_headers_active'] : 0;

// SEO.
$total_published   = isset( $seo['total_published_posts'] ) ? (int) $seo['total_published_posts'] : 0;
$with_meta_title   = isset( $seo['posts_with_meta_title'] ) ? (int) $seo['posts_with_meta_title'] : 0;
$meta_coverage     = $total_published > 0 ? round( ( $with_meta_title / $total_published ) * 100 ) : 0;
$active_ab_tests   = isset( $seo['active_ab_tests'] ) ? (int) $seo['active_ab_tests'] : 0;
$broken_links      = isset( $seo['broken_link_count'] ) ? (int) $seo['broken_link_count'] : 0;
$stale_content     = isset( $seo['stale_content_count'] ) ? (int) $seo['stale_content_count'] : 0;

// Commerce.
$woo_available     = isset( $com['available'] ) ? (bool) $com['available'] : false;
$daily_revenue     = isset( $com['daily_revenue'] ) ? (float) $com['daily_revenue'] : 0.0;
$daily_orders      = isset( $com['daily_orders'] ) ? (int) $com['daily_orders'] : 0;
$abandoned_carts   = isset( $com['abandoned_carts_count'] ) ? (int) $com['abandoned_carts_count'] : 0;
$pending_drafts    = isset( $crm['pending_email_drafts'] ) ? (int) $crm['pending_email_drafts'] : 0;

// Analytics.
$pageviews_today   = isset( $ana['pageviews_today'] ) ? (int) $ana['pageviews_today'] : 0;
$cwv_good          = isset( $ana['cwv_pages_good'] ) ? (int) $ana['cwv_pages_good'] : 0;
$cwv_poor          = isset( $ana['cwv_pages_poor'] ) ? (int) $ana['cwv_pages_poor'] : 0;
$anomaly_detected  = isset( $ana['anomaly_detected'] ) ? (bool) $ana['anomaly_detected'] : false;
$anomaly_type      = isset( $ana['anomaly_type'] ) ? sanitize_text_field( $ana['anomaly_type'] ) : '';

// Security score (simple heuristic: start at 100, deduct for issues).
$security_score = 100;
if ( 'issues_detected' === $file_integrity ) {
	$security_score -= 30;
}
if ( $quarantined > 0 ) {
	$security_score -= 20;
}
if ( ! $ssl_valid ) {
	$security_score -= 25;
}
if ( $failed_logins > 50 ) {
	$security_score -= 15;
} elseif ( $failed_logins > 10 ) {
	$security_score -= 5;
}
$security_score = max( 0, $security_score );

// -------------------------------------------------------------------------
// Helper closures.
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
	$key = strtolower( sanitize_key( (string) $status ) );
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

	<!-- Constitutional Banners -->
	<?php if ( $is_halted ) : ?>
	<div class="wpc-alert-banner wpc-alert-banner--danger">
		<strong><?php esc_html_e( 'Operations Halted', 'claw-agent' ); ?></strong> &mdash;
		<?php esc_html_e( 'Two consecutive health check failures detected. All T2/T3 agent operations are suspended until the connection is restored.', 'claw-agent' ); ?>
		<button type="button" class="wpc-btn wpc-btn--sm wpc-btn--danger wpc-admin-resume-ops">
			<?php esc_html_e( 'Resume Operations', 'claw-agent' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<?php if ( $t3_count >= 4 && ! $is_halted ) : ?>
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<strong><?php esc_html_e( 'T3 Limit Warning', 'claw-agent' ); ?></strong> &mdash;
		<?php
		printf(
			/* translators: %d: number of T3 actions used today */
			esc_html__( '%d of 5 daily structural changes used. Remaining T3 actions may be blocked.', 'claw-agent' ),
			$t3_count
		);
		?>
	</div>
	<?php endif; ?>

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

	<!-- 6-Column KPI Row -->
	<section class="wpc-kpi-grid wpc-kpi-grid--6">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $tasks_today ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Tasks Today', 'claw-agent' ); ?></span>
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>" class="wpc-btn wpc-btn--ghost wpc-btn--sm">
					<?php esc_html_e( 'Review', 'claw-agent' ); ?>
				</a>
			<?php endif; ?>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( $security_score ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Security Score', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value">
				<?php
				if ( $woo_available ) {
					echo esc_html( number_format_i18n( $daily_revenue, 2 ) );
				} else {
					echo esc_html( '—' );
				}
				?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Daily Revenue', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $pageviews_today ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Pageviews Today', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- Module Status Grid -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Module Status', 'claw-agent' ); ?></h2>
		<div class="wpc-metric-grid">

			<!-- Security (Bastien) -->
			<div class="wpc-metric-card">
				<div class="wpc-metric-card__header">
					<span class="wpc-metric-card__title"><?php esc_html_e( 'Security', 'claw-agent' ); ?></span>
					<span class="wpc-metric-card__agent"><?php esc_html_e( 'Bastien', 'claw-agent' ); ?></span>
				</div>
				<div class="wpc-metric-card__metrics">
					<div class="wpc-metric-card__row">
						<?php
						$integrity_dot = 'green';
						if ( 'issues_detected' === $file_integrity ) {
							$integrity_dot = 'red';
						} elseif ( 'scan_pending' === $file_integrity ) {
							$integrity_dot = 'yellow';
						}
						?>
						<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $integrity_dot ); ?>"></span>
						<?php
						printf(
							/* translators: %s: file integrity status */
							esc_html__( 'Integrity: %s', 'claw-agent' ),
							esc_html( ucfirst( str_replace( '_', ' ', $file_integrity ) ) )
						);
						?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: number of failed login attempts */
							esc_html__( 'Failed logins (24h): %s', 'claw-agent' ),
							esc_html( number_format_i18n( $failed_logins ) )
						);
						?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: number of blocked IPs */
							esc_html__( 'Blocked IPs: %s', 'claw-agent' ),
							esc_html( number_format_i18n( $blocked_ips ) )
						);
						?>
					</div>
					<?php if ( $quarantined > 0 ) : ?>
					<div class="wpc-metric-card__row">
						<span class="wpc-badge wpc-badge--error">
							<?php
							printf(
								/* translators: %s: number of quarantined files */
								esc_html__( '%s quarantined', 'claw-agent' ),
								esc_html( number_format_i18n( $quarantined ) )
							);
							?>
						</span>
					</div>
					<?php endif; ?>
					<div class="wpc-metric-card__row">
						<?php if ( $ssl_valid ) : ?>
							<span class="wpc-badge wpc-badge--active">
								<?php
								printf(
									/* translators: %d: days until SSL expiry */
									esc_html__( 'SSL: %d days', 'claw-agent' ),
									$ssl_days
								);
								?>
							</span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--error"><?php esc_html_e( 'SSL invalid', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-security' ) ); ?>" class="wpc-metric-card__link">
					<?php esc_html_e( 'Security Dashboard', 'claw-agent' ); ?> &rarr;
				</a>
			</div>

			<!-- SEO & Content (Lina) -->
			<div class="wpc-metric-card">
				<div class="wpc-metric-card__header">
					<span class="wpc-metric-card__title"><?php esc_html_e( 'SEO & Content', 'claw-agent' ); ?></span>
					<span class="wpc-metric-card__agent"><?php esc_html_e( 'Lina', 'claw-agent' ); ?></span>
				</div>
				<div class="wpc-metric-card__metrics">
					<div class="wpc-metric-card__row">
						<span><?php esc_html_e( 'Meta title coverage', 'claw-agent' ); ?></span>
					</div>
					<div class="wpc-coverage-bar">
						<div class="wpc-coverage-bar__track">
							<div class="wpc-coverage-bar__fill" style="width:<?php echo esc_attr( $meta_coverage ); ?>%"></div>
						</div>
						<span class="wpc-coverage-bar__label"><?php echo esc_html( $meta_coverage ); ?>%</span>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: number of active A/B tests */
							esc_html__( 'A/B tests active: %s', 'claw-agent' ),
							esc_html( number_format_i18n( $active_ab_tests ) )
						);
						?>
					</div>
					<?php if ( $broken_links > 0 ) : ?>
					<div class="wpc-metric-card__row">
						<span class="wpc-badge wpc-badge--error">
							<?php
							printf(
								/* translators: %s: number of broken links */
								esc_html__( '%s broken links', 'claw-agent' ),
								esc_html( number_format_i18n( $broken_links ) )
							);
							?>
						</span>
					</div>
					<?php endif; ?>
					<?php if ( $stale_content > 0 ) : ?>
					<div class="wpc-metric-card__row">
						<span class="wpc-badge wpc-badge--pending">
							<?php
							printf(
								/* translators: %s: number of stale content items */
								esc_html__( '%s stale pages', 'claw-agent' ),
								esc_html( number_format_i18n( $stale_content ) )
							);
							?>
						</span>
					</div>
					<?php endif; ?>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-seo' ) ); ?>" class="wpc-metric-card__link">
					<?php esc_html_e( 'SEO Dashboard', 'claw-agent' ); ?> &rarr;
				</a>
			</div>

			<!-- Commerce & CRM (Hugo) -->
			<div class="wpc-metric-card">
				<div class="wpc-metric-card__header">
					<span class="wpc-metric-card__title"><?php esc_html_e( 'Commerce & CRM', 'claw-agent' ); ?></span>
					<span class="wpc-metric-card__agent"><?php esc_html_e( 'Hugo', 'claw-agent' ); ?></span>
				</div>
				<div class="wpc-metric-card__metrics">
					<?php if ( $woo_available ) : ?>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: number of daily orders */
							esc_html__( 'Orders today: %s', 'claw-agent' ),
							esc_html( number_format_i18n( $daily_orders ) )
						);
						?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: daily revenue amount */
							esc_html__( 'Revenue: %s', 'claw-agent' ),
							esc_html( number_format_i18n( $daily_revenue, 2 ) )
						);
						?>
					</div>
					<?php if ( $abandoned_carts > 0 ) : ?>
					<div class="wpc-metric-card__row">
						<span class="wpc-badge wpc-badge--pending">
							<?php
							printf(
								/* translators: %s: number of abandoned carts */
								esc_html__( '%s abandoned carts', 'claw-agent' ),
								esc_html( number_format_i18n( $abandoned_carts ) )
							);
							?>
						</span>
					</div>
					<?php endif; ?>
					<?php else : ?>
					<div class="wpc-metric-card__row">
						<em><?php esc_html_e( 'WooCommerce not active', 'claw-agent' ); ?></em>
					</div>
					<?php endif; ?>
					<?php if ( $pending_drafts > 0 ) : ?>
					<div class="wpc-metric-card__row">
						<span class="wpc-badge wpc-badge--pending">
							<?php
							printf(
								/* translators: %s: number of pending email drafts */
								esc_html__( '%s email drafts pending', 'claw-agent' ),
								esc_html( number_format_i18n( $pending_drafts ) )
							);
							?>
						</span>
					</div>
					<?php endif; ?>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-commerce' ) ); ?>" class="wpc-metric-card__link">
					<?php esc_html_e( 'Commerce Dashboard', 'claw-agent' ); ?> &rarr;
				</a>
			</div>

			<!-- Analytics (Selma) -->
			<div class="wpc-metric-card">
				<div class="wpc-metric-card__header">
					<span class="wpc-metric-card__title"><?php esc_html_e( 'Analytics', 'claw-agent' ); ?></span>
					<span class="wpc-metric-card__agent"><?php esc_html_e( 'Selma', 'claw-agent' ); ?></span>
				</div>
				<div class="wpc-metric-card__metrics">
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: number of pageviews */
							esc_html__( 'Pageviews today: %s', 'claw-agent' ),
							esc_html( number_format_i18n( $pageviews_today ) )
						);
						?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: 1: good CWV pages, 2: poor CWV pages */
							esc_html__( 'CWV: %1$s good / %2$s poor', 'claw-agent' ),
							esc_html( number_format_i18n( $cwv_good ) ),
							esc_html( number_format_i18n( $cwv_poor ) )
						);
						?>
					</div>
					<?php if ( $anomaly_detected ) : ?>
					<div class="wpc-metric-card__row">
						<span class="wpc-badge wpc-badge--error">
							<?php
							printf(
								/* translators: %s: type of anomaly detected */
								esc_html__( 'Anomaly: %s', 'claw-agent' ),
								esc_html( $anomaly_type )
							);
							?>
						</span>
					</div>
					<?php endif; ?>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=claw-agent' ) ); ?>" class="wpc-metric-card__link">
					<?php esc_html_e( 'Analytics Dashboard', 'claw-agent' ); ?> &rarr;
				</a>
			</div>

			<!-- Infrastructure (Karim) -->
			<div class="wpc-metric-card">
				<div class="wpc-metric-card__header">
					<span class="wpc-metric-card__title"><?php esc_html_e( 'Infrastructure', 'claw-agent' ); ?></span>
					<span class="wpc-metric-card__agent"><?php esc_html_e( 'Karim', 'claw-agent' ); ?></span>
				</div>
				<div class="wpc-metric-card__metrics">
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: WordPress version */
							esc_html__( 'WordPress: %s', 'claw-agent' ),
							esc_html( get_bloginfo( 'version' ) )
						);
						?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						$update_plugins = get_site_transient( 'update_plugins' );
						$plugin_updates = ( is_object( $update_plugins ) && isset( $update_plugins->response ) )
							? count( $update_plugins->response )
							: 0;
						if ( $plugin_updates > 0 ) :
							?>
							<span class="wpc-badge wpc-badge--pending">
								<?php
								printf(
									/* translators: %s: number of plugin updates available */
									esc_html__( '%s plugin updates', 'claw-agent' ),
									esc_html( number_format_i18n( $plugin_updates ) )
								);
								?>
							</span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--active"><?php esc_html_e( 'All plugins up to date', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						printf(
							/* translators: %s: PHP version */
							esc_html__( 'PHP: %s', 'claw-agent' ),
							esc_html( PHP_VERSION )
						);
						?>
					</div>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=claw-agent' ) ); ?>" class="wpc-metric-card__link">
					<?php esc_html_e( 'Site Audit', 'claw-agent' ); ?> &rarr;
				</a>
			</div>

			<!-- Chat (Marc) -->
			<div class="wpc-metric-card">
				<div class="wpc-metric-card__header">
					<span class="wpc-metric-card__title"><?php esc_html_e( 'Chat', 'claw-agent' ); ?></span>
					<span class="wpc-metric-card__agent"><?php esc_html_e( 'Marc', 'claw-agent' ); ?></span>
				</div>
				<div class="wpc-metric-card__metrics">
					<div class="wpc-metric-card__row">
						<?php
						$sessions_today = isset( $cht['sessions_today'] ) ? (int) $cht['sessions_today'] : 0;
						printf(
							/* translators: %s: number of chat sessions today */
							esc_html__( 'Sessions today: %s', 'claw-agent' ),
							esc_html( number_format_i18n( $sessions_today ) )
						);
						?>
					</div>
					<div class="wpc-metric-card__row">
						<?php
						$chat_enabled = $plugin->is_module_enabled( 'chat' );
						if ( $chat_enabled ) :
							?>
							<span class="wpc-badge wpc-badge--active"><?php esc_html_e( 'Widget active', 'claw-agent' ); ?></span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'Widget disabled', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=claw-agent' ) ); ?>" class="wpc-metric-card__link">
					<?php esc_html_e( 'Chat Settings', 'claw-agent' ); ?> &rarr;
				</a>
			</div>

		</div>
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
					$agent_total   = (int) $stat->total;
					$agent_done    = (int) $stat->done;
					$agent_failed  = (int) $stat->failed;
					$agent_active  = (int) $stat->active;
					$agent_backlog = (int) $stat->backlog;
					$agent_pct     = $agent_total > 0 ? round( ( $agent_done / $agent_total ) * 100 ) : 0;
					$stat_slug     = sanitize_key( (string) $stat->agent );
					$stat_name     = isset( $wp_claw_agent_display_names[ $stat_slug ] )
						? $wp_claw_agent_display_names[ $stat_slug ]
						: ucfirst( $stat_slug );
					?>
				<tr>
					<td><strong><?php echo esc_html( $stat_name ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( $agent_total ) ); ?></td>
					<td><span class="wpc-badge wpc-badge--done"><?php echo esc_html( number_format_i18n( $agent_done ) ); ?></span></td>
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
						<div class="wpc-agent-bar">
							<div class="wpc-agent-bar__segment--done" style="width:<?php echo esc_attr( $agent_pct ); ?>%"></div>
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
					<?php
					$task_slug = sanitize_key( (string) $task->agent );
					$task_name = isset( $wp_claw_agent_display_names[ $task_slug ] )
						? $wp_claw_agent_display_names[ $task_slug ]
						: ucfirst( $task_slug );
					echo esc_html( $task_name );
					?>
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
