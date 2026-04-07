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

// Pending agent ideas.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$ideas_count = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE action = %s AND status = %s',
		$proposals_table, 'idea', 'pending'
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

<?php if ( get_option( 'wp_claw_demo_mode' ) ) : ?>
<div class="notice notice-info wp-claw-demo-banner">
	<p>
		<strong><?php esc_html_e( 'Demo Mode', 'claw-agent' ); ?></strong> —
		<?php esc_html_e( 'This is a demo environment with sample data.', 'claw-agent' ); ?>
		<a href="https://wp-claw.ai/pricing" target="_blank" rel="noopener"><?php esc_html_e( 'Connect to a real instance', 'claw-agent' ); ?> &rarr;</a>
	</p>
</div>
<?php endif; ?>


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

	<?php
	// Business Profile reminder — show if profile is empty.
	$_wpc_profile = get_option( 'wp_claw_business_profile', array() );
	$_wpc_profile_empty = empty( $_wpc_profile['business_name'] ) && empty( $_wpc_profile['description'] );
	if ( $_wpc_profile_empty ) : ?>
	<div class="wpc-alert-banner wpc-alert-banner--warning" style="display:flex;align-items:center;gap:12px;">
		<span style="font-size:1.5rem;">📋</span>
		<div>
			<strong><?php esc_html_e( 'Help your AI agents work better', 'claw-agent' ); ?></strong> &mdash;
			<?php
			printf(
				/* translators: %s: link to settings page */
				esc_html__( 'Fill in your %s so agents understand your business, goals, and rules. Reports will be more relevant and personalized.', 'claw-agent' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings#wpc-business-profile-form' ) ) . '" style="font-weight:600;">' . esc_html__( 'Business Profile', 'claw-agent' ) . '</a>'
			);
			?>
		</div>
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

	<!-- SMART KPI ROW -->
	<section class="wpc-kpi-grid wpc-kpi-grid--4" style="margin-bottom:20px;">

		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>" style="text-decoration:none;color:inherit;">
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $tasks_today ) ); ?> <small style="font-size:0.5em;color:#9ca3af;">/ <?php echo esc_html( number_format_i18n( $total_tasks ) ); ?> total</small></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Tasks Today', 'claw-agent' ); ?></span>
		</article>
		</a>

		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals' ) ); ?>" style="text-decoration:none;color:inherit;">
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( $completion_rate ); ?>%</span>
			<span class="wpc-kpi-label">
				<?php esc_html_e( 'Completion Rate', 'claw-agent' ); ?>
				<?php if ( $failed_count > 0 ) : ?>
					<span style="color:#dc2626;font-weight:600;"> &middot; <?php echo esc_html( $failed_count ); ?> <?php esc_html_e( 'failed', 'claw-agent' ); ?></span>
				<?php endif; ?>
			</span>
		</article>
		</a>

		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-security' ) ); ?>" style="text-decoration:none;color:inherit;">
		<article class="wpc-kpi-card">
			<?php
			$_kpi_score_color = $security_score >= 80 ? '#16a34a' : ( $security_score >= 50 ? '#d97706' : '#dc2626' );
			?>
			<span class="wpc-kpi-value" style="color:<?php echo esc_attr( $_kpi_score_color ); ?>;"><?php echo esc_html( $security_score ); ?></span>
			<span class="wpc-kpi-label">
				<?php esc_html_e( 'Security Score', 'claw-agent' ); ?>
				&middot; <?php esc_html_e( 'Details', 'claw-agent' ); ?> &rarr;
			</span>
		</article>
		</a>

		<a href="<?php echo esc_url( admin_url( $woo_available ? 'admin.php?page=wp-claw-commerce' : 'admin.php?page=wp-claw-analytics' ) ); ?>" style="text-decoration:none;color:inherit;">
		<article class="wpc-kpi-card">
			<?php if ( $woo_available ) : ?>
				<span class="wpc-kpi-value"><?php echo esc_html( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $daily_revenue ) ) : number_format( $daily_revenue, 2 ) . '&euro;' ); ?></span>
				<span class="wpc-kpi-label"><?php esc_html_e( 'Daily Revenue', 'claw-agent' ); ?> &middot; <?php echo esc_html( $daily_orders ); ?> <?php esc_html_e( 'orders', 'claw-agent' ); ?></span>
			<?php else : ?>
				<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $pageviews_today ) ); ?></span>
				<span class="wpc-kpi-label"><?php esc_html_e( 'Pageviews Today', 'claw-agent' ); ?></span>
			<?php endif; ?>
		</article>
		</a>

	</section>

	<!-- MODULE HEALTH GRID -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Module Health', 'claw-agent' ); ?></h2>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
			<?php
			$_wpc_module_health = array(
				'security' => array(
					'label'  => __( 'Security', 'claw-agent' ),
					'agent'  => __( 'Bastien', 'claw-agent' ),
					'page'   => 'wp-claw-security',
					'metric' => 'clean' === $file_integrity ? __( 'Clean', 'claw-agent' ) : __( 'Issues detected', 'claw-agent' ),
					'ok'     => 'clean' === $file_integrity,
				),
				'seo'      => array(
					'label'  => __( 'SEO & Content', 'claw-agent' ),
					'agent'  => __( 'Lina', 'claw-agent' ),
					'page'   => 'wp-claw-seo',
					/* translators: %d: meta coverage percentage */
					'metric' => sprintf( __( '%d%% meta coverage', 'claw-agent' ), $meta_coverage ),
					'ok'     => $meta_coverage >= 50,
				),
				'commerce' => array(
					'label'  => __( 'Commerce & CRM', 'claw-agent' ),
					'agent'  => __( 'Hugo', 'claw-agent' ),
					'page'   => 'wp-claw-commerce',
					/* translators: %s: formatted daily revenue amount */
					'metric' => $woo_available ? sprintf( __( '%s today', 'claw-agent' ), function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $daily_revenue ) ) : number_format( $daily_revenue, 2 ) . '&euro;' ) : __( 'WooCommerce inactive', 'claw-agent' ),
					'ok'     => $woo_available,
				),
				'analytics' => array(
					'label'  => __( 'Analytics', 'claw-agent' ),
					'agent'  => __( 'Selma', 'claw-agent' ),
					'page'   => 'wp-claw-analytics',
					/* translators: %d: number of pageviews today */
					'metric' => sprintf( __( '%d pageviews today', 'claw-agent' ), $pageviews_today ),
					'ok'     => true,
				),
				'backup'   => array(
					'label'  => __( 'Backup', 'claw-agent' ),
					'agent'  => __( 'Bastien', 'claw-agent' ),
					'page'   => 'wp-claw-security',
					'metric' => isset( $states['backup'] ) ? __( 'Active', 'claw-agent' ) : __( 'Not configured', 'claw-agent' ),
					'ok'     => isset( $states['backup'] ),
				),
				'chat'     => array(
					'label'  => __( 'Chat', 'claw-agent' ),
					'agent'  => __( 'Marc', 'claw-agent' ),
					'page'   => 'wp-claw-settings',
					/* translators: %d: number of chat sessions today */
					'metric' => isset( $states['chat'] ) ? sprintf( __( '%d sessions today', 'claw-agent' ), isset( $cht['sessions_today'] ) ? (int) $cht['sessions_today'] : 0 ) : __( 'Widget disabled', 'claw-agent' ),
					'ok'     => isset( $states['chat'] ),
				),
			);
			foreach ( $_wpc_module_health as $_wpc_mh_slug => $_wpc_mh ) :
			?>
			<div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid #e5e7eb;border-radius:8px;">
				<div>
					<strong style="font-size:0.875rem;"><?php echo esc_html( $_wpc_mh['label'] ); ?></strong>
					<br>
					<small style="color:#6b7280;"><?php echo esc_html( $_wpc_mh['agent'] ); ?> &middot; <?php echo esc_html( $_wpc_mh['metric'] ); ?></small>
				</div>
				<div style="display:flex;align-items:center;gap:8px;">
					<span data-inline-edit="toggle_module" data-target-id="0"
						data-current-value="<?php echo esc_attr( $_wpc_mh['ok'] ? 'on' : 'off' ); ?>"
						data-module="<?php echo esc_attr( $_wpc_mh_slug ); ?>"
						style="cursor:pointer;display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $_wpc_mh['ok'] ? '#16a34a' : '#d97706' ); ?>;"
						title="<?php esc_attr_e( 'Click to toggle module', 'claw-agent' ); ?>"></span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $_wpc_mh['page'] ) ); ?>" style="color:#4f46e5;font-size:0.75rem;text-decoration:none;">&rarr;</a>
				</div>
			</div>
			<?php endforeach; ?>
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

	<!-- AGENT IDEAS CARD -->
	<?php if ( $ideas_count > 0 ) : ?>
	<section class="wpc-card" style="margin-bottom:20px;background:linear-gradient(135deg,#fefce8,#fef9c3);border-color:#fde047;">
		<div style="display:flex;align-items:center;justify-content:space-between;">
			<div>
				<h2 class="wpc-section-heading" style="margin:0;">💡 <?php esc_html_e( 'Agent Ideas', 'claw-agent' ); ?></h2>
				<p style="color:#854d0e;margin:4px 0 0;font-size:0.875rem;">
					<?php echo esc_html( sprintf( __( '%d ideas waiting for your review', 'claw-agent' ), $ideas_count ) ); ?>
				</p>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-proposals&show=ideas' ) ); ?>"
			   class="wpc-btn wpc-btn--primary" style="padding:8px 16px;border-radius:8px;font-weight:600;">
				<?php esc_html_e( 'Review Ideas', 'claw-agent' ); ?>
			</a>
		</div>
	</section>
	<?php endif; ?>

	<!-- ACTIVE CHAINS CARD (v1.4.0) -->
	<?php
	$chains_table = $wpdb->prefix . 'wp_claw_task_chains';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$chain_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, chain_id, agent, title, step_order, status FROM %i WHERE status NOT IN (%s, %s) ORDER BY chain_id, step_order',
			$chains_table, 'cancelled', 'failed'
		)
	);

	// Group by chain_id.
	$chains_grouped = array();
	if ( $chain_rows ) {
		foreach ( $chain_rows as $cr ) {
			$cid = sanitize_text_field( (string) $cr->chain_id );
			if ( ! isset( $chains_grouped[ $cid ] ) ) {
				$chains_grouped[ $cid ] = array(
					'agent' => sanitize_key( (string) $cr->agent ),
					'title' => sanitize_text_field( (string) $cr->title ),
					'steps' => array(),
					'first_id' => (int) $cr->id,
				);
			}
			$chains_grouped[ $cid ]['steps'][] = $cr;
		}
	}

	if ( ! empty( $chains_grouped ) ) :
	?>
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Active Chains', 'claw-agent' ); ?></h2>
		<div style="display:flex;flex-direction:column;gap:12px;">
			<?php foreach ( $chains_grouped as $cid => $chain_data ) :
				$total_steps = count( $chain_data['steps'] );
				$done_steps  = 0;
				foreach ( $chain_data['steps'] as $cs ) {
					if ( 'done' === sanitize_key( (string) $cs->status ) ) {
						++$done_steps;
					}
				}
				$progress_pct = $total_steps > 0 ? round( ( $done_steps / $total_steps ) * 100 ) : 0;

				$chain_agent_slug = $chain_data['agent'];
				$chain_agent_name = isset( $wp_claw_agent_display_names[ $chain_agent_slug ] )
					? $wp_claw_agent_display_names[ $chain_agent_slug ]
					: ucfirst( $chain_agent_slug );

				$chain_row_id = $chain_data['first_id'];
			?>
			<div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;">
				<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
					<div style="display:flex;align-items:center;gap:8px;">
						<?php echo wp_claw_agent_avatar( $chain_agent_slug, 28 ); ?>
						<div>
							<strong style="font-size:0.875rem;"><?php echo esc_html( $chain_data['title'] ); ?></strong>
							<br>
							<small style="color:#6b7280;"><?php echo esc_html( $chain_agent_name ); ?></small>
						</div>
					</div>
					<div style="display:flex;gap:6px;">
						<button type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--ghost"
							data-chain-action="pause"
							data-chain-step-id="<?php echo esc_attr( $chain_row_id ); ?>"
							style="padding:4px 10px;font-size:0.75rem;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;background:#fff;">
							<?php esc_html_e( 'Pause', 'claw-agent' ); ?>
						</button>
						<button type="button"
							class="wpc-btn wpc-btn--sm wpc-btn--danger"
							data-chain-action="cancel"
							data-chain-step-id="<?php echo esc_attr( $chain_row_id ); ?>"
							style="padding:4px 10px;font-size:0.75rem;border-radius:6px;cursor:pointer;background:#dc2626;color:#fff;border:none;">
							<?php esc_html_e( 'Cancel', 'claw-agent' ); ?>
						</button>
					</div>
				</div>
				<!-- Progress bar -->
				<div style="background:#e5e7eb;border-radius:9999px;height:8px;overflow:hidden;margin-bottom:4px;">
					<div style="background:#16a34a;height:100%;width:<?php echo esc_attr( $progress_pct ); ?>%;border-radius:9999px;transition:width 0.3s;"></div>
				</div>
				<span style="font-size:0.75rem;color:#6b7280;">
					<?php
					printf(
						/* translators: 1: completed steps, 2: total steps */
						esc_html__( '%1$d of %2$d steps done', 'claw-agent' ),
						$done_steps,
						$total_steps
					);
					?>
				</span>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<!-- AGENT ACTIVITY TIMELINE -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Recent Agent Activity', 'claw-agent' ); ?></h2>
		<?php if ( empty( $recent_tasks ) ) : ?>
			<div style="text-align:center;padding:24px;color:#6b7280;">
				<p style="font-size:0.875rem;"><?php esc_html_e( 'No recent activity. Your agents will appear here once they complete tasks.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
		<div style="max-height:400px;overflow-y:auto;">
			<?php foreach ( $recent_tasks as $task ) :
				$_wpc_agent_slug  = sanitize_key( (string) $task->agent );
				$_wpc_agent_name  = isset( $wp_claw_agent_display_names[ $_wpc_agent_slug ] )
					? $wp_claw_agent_display_names[ $_wpc_agent_slug ]
					: ucfirst( $_wpc_agent_slug );
				$_wpc_task_status = sanitize_key( (string) $task->status );
				$_wpc_badge       = 'done' === $_wpc_task_status ? 'done' : ( 'failed' === $_wpc_task_status ? 'failed' : 'active' );
				$_wpc_time_ago    = human_time_diff( strtotime( $task->created_at ) );
			?>
			<div style="padding:10px 0;border-bottom:1px solid #f3f4f6;cursor:pointer;" onclick="var d=this.querySelector('.wpc-timeline-detail');if(d){d.style.display=d.style.display==='none'?'block':'none';}">
				<div style="display:flex;align-items:center;justify-content:space-between;">
					<div style="display:flex;align-items:center;gap:10px;">
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $_wpc_badge ); ?>" style="min-width:auto;"><?php echo esc_html( $_wpc_task_status ); ?></span>
						<div>
							<?php echo wp_claw_agent_avatar( $_wpc_agent_slug, 24 ); ?> <strong style="font-size:0.8125rem;"><?php echo esc_html( $_wpc_agent_name ); ?></strong>
							<span style="color:#6b7280;font-size:0.8125rem;"> &middot; <?php echo esc_html( sanitize_text_field( (string) $task->action ) ); ?></span>
						</div>
					</div>
					<span style="color:#9ca3af;font-size:0.75rem;white-space:nowrap;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time difference */
								__( '%s ago', 'claw-agent' ),
								$_wpc_time_ago
							)
						);
						?>
					</span>
				</div>
				<div class="wpc-timeline-detail" style="display:none;margin-top:8px;padding:8px;background:#f9fafb;border-radius:6px;font-size:0.8125rem;">
					<?php
					printf(
						/* translators: 1: task ID, 2: module name */
						esc_html__( 'Task #%1$s &middot; Module: %2$s', 'claw-agent' ),
						esc_html( $task->task_id ),
						esc_html( sanitize_text_field( (string) $task->module ) )
					);
					?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<!-- Tasks By Agent (detailed) — collapsible -->
	<?php if ( ! empty( $agent_stats_raw ) ) : ?>
	<details style="margin-bottom:20px;">
		<summary class="wpc-section-heading" style="cursor:pointer;user-select:none;list-style:none;display:flex;align-items:center;gap:6px;">
			&#9654; <?php esc_html_e( 'Tasks By Agent (detailed)', 'claw-agent' ); ?>
		</summary>
		<div class="wpc-card" style="margin-top:8px;">
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
		</div>
	</details>
	<?php endif; ?>

	<!-- Agent Team Grid — wired to local task data -->
	<?php
	// Build agent stats lookup from local wp_claw_tasks (already queried as $agent_stats_raw).
	$agent_local_stats = array();
	if ( $agent_stats_raw ) {
		foreach ( $agent_stats_raw as $stat ) {
			$agent_local_stats[ sanitize_key( (string) $stat->agent ) ] = array(
				'total'  => (int) $stat->total,
				'done'   => (int) $stat->done,
				'failed' => (int) $stat->failed,
			);
		}
	}
	$agent_team = array(
		array( 'slug' => 'architect', 'emoji' => '🏗️', 'name' => 'Karim', 'role' => __( 'Orchestration & Audit', 'claw-agent' ) ),
		array( 'slug' => 'scribe',    'emoji' => '✍️', 'name' => 'Lina',  'role' => __( 'SEO, Content & Social', 'claw-agent' ) ),
		array( 'slug' => 'sentinel',  'emoji' => '🛡️', 'name' => 'Bastien', 'role' => __( 'Security & Backup', 'claw-agent' ) ),
		array( 'slug' => 'commerce',  'emoji' => '💼', 'name' => 'Hugo',  'role' => __( 'WooCommerce & CRM', 'claw-agent' ) ),
		array( 'slug' => 'analyst',   'emoji' => '📊', 'name' => 'Selma', 'role' => __( 'Analytics & Performance', 'claw-agent' ) ),
		array( 'slug' => 'concierge', 'emoji' => '💬', 'name' => 'Marc',  'role' => __( 'Live Chat & Support', 'claw-agent' ) ),
	);
	?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Agent Team', 'claw-agent' ); ?></h2>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;">
			<?php foreach ( $agent_team as $at ) :
				$stats = isset( $agent_local_stats[ $at['slug'] ] ) ? $agent_local_stats[ $at['slug'] ] : null;
				$has_activity = $stats && $stats['total'] > 0;
				$has_failures = $stats && $stats['failed'] > 0;
			?>
			<div style="border:1px solid <?php echo esc_attr( $has_failures ? '#fca5a5' : '#e5e7eb' ); ?>;border-radius:10px;padding:16px;">
				<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
					<?php echo wp_claw_agent_avatar( $at['name'], 36 ); ?>
					<strong style="font-size:0.9375rem;"><?php echo esc_html( $at['name'] ); ?></strong>
					<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $has_activity ? '#16a34a' : '#9ca3af' ); ?>;"></span>
				</div>
				<div style="font-size:0.75rem;color:#6b7280;margin-bottom:8px;"><?php echo esc_html( $at['role'] ); ?></div>
				<?php if ( $stats ) : ?>
				<div style="display:flex;gap:12px;font-size:0.8125rem;">
					<span style="color:#16a34a;font-weight:600;"><?php echo esc_html( $stats['done'] ); ?> <?php esc_html_e( 'done', 'claw-agent' ); ?></span>
					<?php if ( $stats['failed'] > 0 ) : ?>
					<span style="color:#dc2626;font-weight:600;"><?php echo esc_html( $stats['failed'] ); ?> <?php esc_html_e( 'failed', 'claw-agent' ); ?></span>
					<?php endif; ?>
				</div>
				<?php else : ?>
				<span style="font-size:0.8125rem;color:#9ca3af;"><em><?php esc_html_e( 'No tasks yet', 'claw-agent' ); ?></em></span>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<p style="margin-top:12px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-claw-agents' ) ); ?>" class="wpc-btn wpc-btn--ghost">
				<?php esc_html_e( 'View full agent team', 'claw-agent' ); ?>
			</a>
		</p>
	</section>

	<!-- SITE TRIAGE CARD (v1.4.0) -->
	<?php
	// Compute triage signals inline — same logic as Cron::get_site_signals() / get_site_tooling().
	$_wpc_triage_signals = array(
		__( 'WooCommerce', 'claw-agent' )   => class_exists( 'WooCommerce' ),
		__( 'Block Theme', 'claw-agent' )   => wp_get_theme()->is_block_theme(),
		__( 'Multisite', 'claw-agent' )     => is_multisite(),
		__( 'Object Cache', 'claw-agent' )  => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		__( 'Page Cache', 'claw-agent' )    => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
		__( 'SSL Active', 'claw-agent' )    => is_ssl(),
		__( 'Debug Mode', 'claw-agent' )    => defined( 'WP_DEBUG' ) && WP_DEBUG,
		__( 'Cron Disabled', 'claw-agent' ) => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
	);
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field below.
	$_wpc_server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown';
	$_wpc_triage_tooling  = array(
		__( 'PHP', 'claw-agent' )       => PHP_VERSION,
		__( 'MySQL', 'claw-agent' )     => $wpdb->db_version(),
		__( 'Server', 'claw-agent' )    => $_wpc_server_software,
		__( 'Memory', 'claw-agent' )    => ini_get( 'memory_limit' ),
		/* translators: %s: seconds value from PHP ini */
		__( 'Max Exec', 'claw-agent' )  => sprintf( __( '%ss', 'claw-agent' ), ini_get( 'max_execution_time' ) ),
		__( 'Upload', 'claw-agent' )    => ini_get( 'upload_max_filesize' ),
	);
	?>
	<section class="wpc-card wpc-triage-card" style="margin-top:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Site Triage', 'claw-agent' ); ?></h2>
		<div class="wpc-triage-grid">
			<div>
				<strong class="wpc-triage-col-heading"><?php esc_html_e( 'Environment Signals', 'claw-agent' ); ?></strong>
				<ul class="wpc-triage-signal-list">
					<?php foreach ( $_wpc_triage_signals as $_wpc_sig_label => $_wpc_sig_val ) : ?>
					<li class="wpc-signal-row">
						<span class="wpc-signal-label"><?php echo esc_html( $_wpc_sig_label ); ?></span>
						<?php if ( $_wpc_sig_val ) : ?>
							<span class="wpc-signal-yes" aria-label="<?php esc_attr_e( 'Yes', 'claw-agent' ); ?>">&#10003;</span>
						<?php else : ?>
							<span class="wpc-signal-no" aria-label="<?php esc_attr_e( 'No', 'claw-agent' ); ?>">&#10007;</span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div>
				<strong class="wpc-triage-col-heading"><?php esc_html_e( 'Server Tooling', 'claw-agent' ); ?></strong>
				<ul class="wpc-triage-tooling-list">
					<?php foreach ( $_wpc_triage_tooling as $_wpc_tool_key => $_wpc_tool_val ) : ?>
					<li class="wpc-tooling-row">
						<span class="wpc-tooling-key"><?php echo esc_html( $_wpc_tool_key ); ?></span>
						<span class="wpc-tooling-val"><?php echo esc_html( (string) $_wpc_tool_val ); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</section>

	<!-- SITE HEALTH REPORT -->
	<?php
	$_perf_report = get_transient( 'wp_claw_perf_report' );
	if ( is_array( $_perf_report ) ) :
		$_perf_score = isset( $_perf_report['score'] ) ? (int) $_perf_report['score'] : 0;
		if ( $_perf_score >= 75 ) {
			$_perf_score_color = '#16a34a';
			$_perf_score_label = __( 'Good', 'claw-agent' );
		} elseif ( $_perf_score >= 50 ) {
			$_perf_score_color = '#d97706';
			$_perf_score_label = __( 'Needs Attention', 'claw-agent' );
		} else {
			$_perf_score_color = '#dc2626';
			$_perf_score_label = __( 'Poor', 'claw-agent' );
		}
		$_perf_checks          = isset( $_perf_report['checks'] ) && is_array( $_perf_report['checks'] ) ? $_perf_report['checks'] : array();
		$_perf_recommendations = isset( $_perf_report['recommendations'] ) && is_array( $_perf_report['recommendations'] ) ? $_perf_report['recommendations'] : array();
		$_perf_generated_at    = isset( $_perf_report['generated_at'] ) ? sanitize_text_field( $_perf_report['generated_at'] ) : '';

		$_perf_check_labels = array(
			'autoload_bloat' => __( 'Autoload Bloat', 'claw-agent' ),
			'object_cache'   => __( 'Object Cache', 'claw-agent' ),
			'page_cache'     => __( 'Page Cache', 'claw-agent' ),
			'cron_health'    => __( 'WP-Cron Health', 'claw-agent' ),
			'database_bloat' => __( 'Database Bloat', 'claw-agent' ),
			'self_audit'     => __( 'WP-Claw Self-Audit', 'claw-agent' ),
		);
	?>
	<section class="wpc-card" style="margin-top:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Site Health Report', 'claw-agent' ); ?></h2>
			<span style="font-size:1.5rem;font-weight:700;color:<?php echo esc_attr( $_perf_score_color ); ?>;">
				<?php echo esc_html( $_perf_score ); ?>/100
				<span style="font-size:0.875rem;font-weight:500;margin-left:6px;"><?php echo esc_html( $_perf_score_label ); ?></span>
			</span>
		</div>

		<?php if ( ! empty( $_perf_checks ) ) : ?>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:16px;">
			<?php foreach ( $_perf_checks as $_chk ) :
				$_chk_id     = isset( $_chk['id'] ) ? sanitize_key( $_chk['id'] ) : '';
				$_chk_status = isset( $_chk['status'] ) ? sanitize_key( $_chk['status'] ) : 'pass';
				$_chk_label  = isset( $_perf_check_labels[ $_chk_id ] ) ? $_perf_check_labels[ $_chk_id ] : esc_html( $_chk_id );
				if ( 'pass' === $_chk_status ) {
					$_chk_dot_color  = '#16a34a';
					$_chk_border     = '#bbf7d0';
					$_chk_status_txt = __( 'Pass', 'claw-agent' );
				} elseif ( 'warning' === $_chk_status ) {
					$_chk_dot_color  = '#d97706';
					$_chk_border     = '#fde68a';
					$_chk_status_txt = __( 'Warning', 'claw-agent' );
				} else {
					$_chk_dot_color  = '#dc2626';
					$_chk_border     = '#fca5a5';
					$_chk_status_txt = __( 'Fail', 'claw-agent' );
				}
			?>
			<div style="border:1px solid <?php echo esc_attr( $_chk_border ); ?>;border-radius:8px;padding:12px;">
				<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
					<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $_chk_dot_color ); ?>;flex-shrink:0;"></span>
					<strong style="font-size:0.8125rem;"><?php echo esc_html( $_chk_label ); ?></strong>
				</div>
				<span style="font-size:0.75rem;color:<?php echo esc_attr( $_chk_dot_color ); ?>;font-weight:600;"><?php echo esc_html( $_chk_status_txt ); ?></span>
				<?php if ( isset( $_chk['value'] ) ) : ?>
					<span style="font-size:0.75rem;color:#6b7280;margin-left:6px;"><?php echo esc_html( $_chk['value'] ); ?></span>
				<?php elseif ( isset( $_chk['detail'] ) ) : ?>
					<span style="font-size:0.75rem;color:#6b7280;display:block;margin-top:2px;"><?php echo esc_html( $_chk['detail'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $_perf_recommendations ) ) : ?>
		<div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:12px;margin-bottom:12px;">
			<strong style="font-size:0.875rem;display:block;margin-bottom:6px;"><?php esc_html_e( 'Recommendations', 'claw-agent' ); ?></strong>
			<ul style="margin:0;padding-left:18px;font-size:0.8125rem;color:#374151;">
				<?php foreach ( $_perf_recommendations as $_rec ) : ?>
				<li style="margin-bottom:4px;"><?php echo esc_html( $_rec ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( '' !== $_perf_generated_at ) : ?>
		<p style="font-size:0.75rem;color:#9ca3af;margin:0;">
			<?php
			/* translators: %s: ISO 8601 timestamp. */
			printf( esc_html__( 'Last checked: %s', 'claw-agent' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $_perf_generated_at ) ) ) );
			?>
		</p>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<!-- AGENT SKILLS CARD (v1.4.0) -->
	<?php
	$_wpc_shared_skills = array( 'wordpress-router', 'wp-project-triage', 'wp-plugin-development' );
	$_wpc_agent_skills  = array(
		array(
			'slug'   => 'architect',
			'name'   => __( 'Karim', 'claw-agent' ),
			'role'   => __( 'Architect', 'claw-agent' ),
			'skills' => array( 'wp-rest-api', 'wp-wpcli-and-ops', 'wp-abilities-api', 'wp-phpstan' ),
		),
		array(
			'slug'   => 'scribe',
			'name'   => __( 'Lina', 'claw-agent' ),
			'role'   => __( 'Scribe', 'claw-agent' ),
			'skills' => array( 'wp-block-development', 'wp-block-themes', 'wp-interactivity-api', 'wpds' ),
		),
		array(
			'slug'   => 'sentinel',
			'name'   => __( 'Bastien', 'claw-agent' ),
			'role'   => __( 'Sentinel', 'claw-agent' ),
			'skills' => array( 'wp-performance', 'wp-wpcli-and-ops' ),
		),
		array(
			'slug'   => 'commerce',
			'name'   => __( 'Hugo', 'claw-agent' ),
			'role'   => __( 'Commerce', 'claw-agent' ),
			'skills' => array( 'wp-rest-api' ),
		),
		array(
			'slug'   => 'analyst',
			'name'   => __( 'Selma', 'claw-agent' ),
			'role'   => __( 'Analyst', 'claw-agent' ),
			'skills' => array( 'wp-performance', 'wp-phpstan' ),
		),
		array(
			'slug'   => 'concierge',
			'name'   => __( 'Marc', 'claw-agent' ),
			'role'   => __( 'Concierge', 'claw-agent' ),
			'skills' => array( 'wp-interactivity-api', 'wp-block-development' ),
		),
	);
	?>
	<section class="wpc-card wpc-skills-card" style="margin-top:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Agent Skills', 'claw-agent' ); ?></h2>

		<!-- Shared skills row -->
		<div class="wpc-agent-skills-row wpc-agent-skills-row--shared">
			<span class="wpc-agent-skills-name"><?php esc_html_e( 'All Agents', 'claw-agent' ); ?></span>
			<span class="wpc-agent-skills-role">(<?php esc_html_e( 'shared', 'claw-agent' ); ?>)</span>
			<div class="wpc-agent-skills-badges">
				<?php foreach ( $_wpc_shared_skills as $_wpc_sk ) : ?>
				<span class="wpc-skill-badge wpc-skill-badge--shared"><?php echo esc_html( $_wpc_sk ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Per-agent rows -->
		<?php foreach ( $_wpc_agent_skills as $_wpc_ag ) : ?>
		<div class="wpc-agent-skills-row">
			<span class="wpc-agent-skills-name"><?php echo esc_html( $_wpc_ag['name'] ); ?></span>
			<span class="wpc-agent-skills-role">(<?php echo esc_html( $_wpc_ag['role'] ); ?>)</span>
			<div class="wpc-agent-skills-badges">
				<?php foreach ( $_wpc_ag['skills'] as $_wpc_sk ) : ?>
				<span class="wpc-skill-badge"><?php echo esc_html( $_wpc_sk ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</section>

<!-- Infrastructure Health (Audit) -->
<?php
$audit_module = $plugin->get_module( 'audit' );
$audit = array();
if ( null !== $audit_module && method_exists( $audit_module, 'get_state' ) ) {
	$audit = $audit_module->get_state();
}
$wp_version    = isset( $audit['wp_version'] ) ? sanitize_text_field( (string) $audit['wp_version'] ) : ( function_exists( 'wp_get_wp_version' ) ? wp_get_wp_version() : get_bloginfo( 'version' ) );
$php_version   = isset( $audit['php_version'] ) ? sanitize_text_field( (string) $audit['php_version'] ) : PHP_VERSION;
$ssl_days      = isset( $audit['ssl_days_remaining'] ) ? (int) $audit['ssl_days_remaining'] : null;
$disk_used     = isset( $audit['disk_used_bytes'] ) ? (int) $audit['disk_used_bytes'] : 0;
$disk_total    = isset( $audit['disk_total_bytes'] ) ? (int) $audit['disk_total_bytes'] : 0;
$db_size       = isset( $audit['db_size_bytes'] ) ? (int) $audit['db_size_bytes'] : 0;
$plugin_count  = isset( $audit['active_plugin_count'] ) ? (int) $audit['active_plugin_count'] : count( get_option( 'active_plugins', array() ) );
$updates_avail = isset( $audit['plugins_needing_update'] ) ? (int) $audit['plugins_needing_update'] : 0;

// SSL dot CSS class: green > 30d, yellow 14-30d, red < 14d.
if ( null === $ssl_days ) {
	$ssl_dot_class = 'wpc-status-dot--idle';
	$ssl_label     = esc_html__( 'Unknown', 'claw-agent' );
} elseif ( $ssl_days > 30 ) {
	$ssl_dot_class = 'wpc-status-dot--active';
	/* translators: %d: number of days remaining on SSL certificate */
	$ssl_label = sprintf( esc_html__( '%d days remaining', 'claw-agent' ), $ssl_days );
} elseif ( $ssl_days >= 14 ) {
	$ssl_dot_class = 'wpc-status-dot--idle';
	/* translators: %d: number of days remaining on SSL certificate */
	$ssl_label = sprintf( esc_html__( '%d days remaining', 'claw-agent' ), $ssl_days );
} else {
	$ssl_dot_class = 'wpc-status-dot--error';
	/* translators: %d: number of days remaining on SSL certificate */
	$ssl_label = sprintf( esc_html__( '%d days — renew now', 'claw-agent' ), $ssl_days );
}

// Disk usage percentage — avoids division by zero.
$disk_pct = $disk_total > 0 ? min( 100, round( ( $disk_used / max( 1, $disk_total ) ) * 100 ) ) : 0;
?>
<div class="wpc-card" style="margin-top: 24px;">
	<div class="wpc-card__header">
		<h3 class="wpc-card__title"><?php esc_html_e( 'Infrastructure Health', 'claw-agent' ); ?></h3>
		<button class="button wpc-scan-button" data-agent-action="audit_run_site_audit" data-task-key="audit-full">
			<?php esc_html_e( 'Run Full Audit', 'claw-agent' ); ?>
			<span class="wpc-spinner"></span>
		</button>
	</div>
	<?php if ( empty( $audit ) ) : ?>
	<p class="wpc-empty-state">
		<?php esc_html_e( 'Thomas will run the first audit within 24 hours.', 'claw-agent' ); ?>
	</p>
	<?php else : ?>
	<table class="wpc-detail-table" style="width: 100%;">
		<tbody>
			<!-- WordPress version -->
			<tr>
				<th><?php esc_html_e( 'WordPress', 'claw-agent' ); ?></th>
				<td><?php echo esc_html( $wp_version ); ?></td>
			</tr>
			<!-- PHP version -->
			<tr>
				<th><?php esc_html_e( 'PHP', 'claw-agent' ); ?></th>
				<td><?php echo esc_html( $php_version ); ?></td>
			</tr>
			<!-- SSL certificate -->
			<tr>
				<th><?php esc_html_e( 'SSL Certificate', 'claw-agent' ); ?></th>
				<td>
					<span class="wpc-status-dot <?php echo esc_attr( $ssl_dot_class ); ?>"></span>
					<?php echo esc_html( $ssl_label ); ?>
				</td>
			</tr>
			<!-- Disk usage -->
			<tr>
				<th><?php esc_html_e( 'Disk Usage', 'claw-agent' ); ?></th>
				<td>
					<?php echo esc_html( size_format( $disk_used ) ); ?>
					<?php if ( $disk_total > 0 ) : ?>
					/ <?php echo esc_html( size_format( $disk_total ) ); ?>
					<div class="wpc-coverage-bar" style="margin-top: 4px;">
						<div class="wpc-coverage-bar__fill" style="width: <?php echo esc_attr( $disk_pct ); ?>%;"></div>
					</div>
					<?php endif; ?>
				</td>
			</tr>
			<!-- Database size -->
			<tr>
				<th><?php esc_html_e( 'Database Size', 'claw-agent' ); ?></th>
				<td><?php echo esc_html( size_format( $db_size ) ); ?></td>
			</tr>
			<!-- Active plugins -->
			<tr>
				<th><?php esc_html_e( 'Active Plugins', 'claw-agent' ); ?></th>
				<td>
					<?php echo esc_html( $plugin_count ); ?>
					<?php if ( $updates_avail > 0 ) : ?>
					<span class="wpc-badge wpc-badge--warning" style="margin-left: 8px;">
						<?php
						/* translators: %d: number of plugins with available updates */
						echo esc_html( sprintf( _n( '%d update available', '%d updates available', $updates_avail, 'claw-agent' ), $updates_avail ) );
						?>
					</span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
	<?php endif; ?>
</div>

</div><!-- .wpc-admin-wrap -->

</div>
