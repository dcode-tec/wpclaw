<?php
/**
 * Analytics dashboard admin view — agent-first layout centred on Selma (Analyst).
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.3.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables.

global $wpdb;

$plugin         = \WPClaw\WP_Claw::get_instance();
$analytics_mod  = $plugin->get_module( 'analytics' );
$performance_mod = $plugin->get_module( 'performance' );

if ( null === $analytics_mod && null === $performance_mod ) :
?>
<div class="wpc-admin-wrap">
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<?php
		printf(
			esc_html__( 'The Analytics and Performance modules are not enabled. %s to activate them.', 'claw-agent' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
		);
		?>
	</div>
</div>
<?php
return;
endif;

$analytics = null !== $analytics_mod ? $analytics_mod->get_state() : array();
$perf      = null !== $performance_mod ? $performance_mod->get_state() : array();

// Pageview data (last 7 days from wp_claw_analytics).
$analytics_table = $wpdb->prefix . 'wp_claw_analytics';
$seven_days_ago  = gmdate( 'Y-m-d', strtotime( '-7 days' ) );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$daily_pageviews = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT DATE(created_at) as day, COUNT(*) as views FROM %i WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC",
		$analytics_table,
		$seven_days_ago
	),
	ARRAY_A
);

$total_pageviews_7d = 0;
$chart_labels = array();
$chart_data   = array();
foreach ( $daily_pageviews as $row ) {
	$total_pageviews_7d += (int) $row['views'];
	$chart_labels[] = wp_date( 'M j', strtotime( $row['day'] ) );
	$chart_data[]   = (int) $row['views'];
}

// Top pages.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$top_pages = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT page_url, COUNT(*) as views FROM %i WHERE created_at >= %s GROUP BY page_url ORDER BY views DESC LIMIT %d",
		$analytics_table,
		$seven_days_ago,
		10
	),
	ARRAY_A
);

// Referrer breakdown.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$referrers = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT CASE WHEN referrer IS NULL OR referrer = '' THEN 'Direct' ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '/', 3), '/', -1) END as source, COUNT(*) as visits FROM %i WHERE created_at >= %s GROUP BY source ORDER BY visits DESC LIMIT %d",
		$analytics_table,
		$seven_days_ago,
		10
	),
	ARRAY_A
);

// CWV data.
$cwv_table = $wpdb->prefix . 'wp_claw_cwv_history';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$latest_cwv = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT lcp, inp, cls, rating, measured_at FROM %i ORDER BY measured_at DESC LIMIT 1",
		$cwv_table
	),
	ARRAY_A
);

$lcp = $latest_cwv ? (float) ( $latest_cwv['lcp'] ?? 0 ) : null;
$inp = $latest_cwv ? (float) ( $latest_cwv['inp'] ?? 0 ) : null;
$cls = $latest_cwv ? (float) ( $latest_cwv['cls'] ?? 0 ) : null;

// CWV color helpers.
$cwv_color = function( $metric, $value ) {
	if ( null === $value ) return '#9ca3af';
	if ( 'lcp' === $metric ) return $value <= 2.5 ? '#16a34a' : ( $value <= 4.0 ? '#d97706' : '#dc2626' );
	if ( 'inp' === $metric ) return $value <= 200 ? '#16a34a' : ( $value <= 500 ? '#d97706' : '#dc2626' );
	if ( 'cls' === $metric ) return $value <= 0.1 ? '#16a34a' : ( $value <= 0.25 ? '#d97706' : '#dc2626' );
	return '#9ca3af';
};

$cwv_label = function( $metric, $value ) {
	if ( null === $value ) return __( 'No data', 'claw-agent' );
	if ( 'lcp' === $metric ) return $value <= 2.5 ? __( 'Good', 'claw-agent' ) : ( $value <= 4.0 ? __( 'Needs Improvement', 'claw-agent' ) : __( 'Poor', 'claw-agent' ) );
	if ( 'inp' === $metric ) return $value <= 200 ? __( 'Good', 'claw-agent' ) : ( $value <= 500 ? __( 'Needs Improvement', 'claw-agent' ) : __( 'Poor', 'claw-agent' ) );
	if ( 'cls' === $metric ) return $value <= 0.1 ? __( 'Good', 'claw-agent' ) : ( $value <= 0.25 ? __( 'Needs Improvement', 'claw-agent' ) : __( 'Poor', 'claw-agent' ) );
	return __( 'Unknown', 'claw-agent' );
};

$pageviews_today = isset( $analytics['pageviews_today'] ) ? (int) $analytics['pageviews_today'] : 0;

// Count total analytics rows to detect whether data collection has started.
$_wpc_analytics_table = $wpdb->prefix . 'wp_claw_analytics';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$_wpc_analytics_count = (int) $wpdb->get_var(
	$wpdb->prepare( 'SELECT COUNT(*) FROM %i LIMIT 1', $_wpc_analytics_table )
);
?>

<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<?php if ( 0 === $_wpc_analytics_count ) : ?>
	<div class="wpc-alert-banner wpc-alert-banner--warning" style="margin-bottom:16px;">
		<strong><?php esc_html_e( 'Analytics not collecting data yet', 'claw-agent' ); ?></strong> &mdash;
		<?php esc_html_e( 'The privacy-first analytics pixel needs to be enabled. Check that the Analytics module is active in Settings and that the tracking script is loading on your frontend.', 'claw-agent' ); ?>
	</div>
	<?php endif; ?>

	<!-- 1. Agent Status Bar -->
	<section class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<?php echo wp_claw_agent_avatar( 'Selma', 36 ); ?>
			<div>
				<strong><?php esc_html_e( 'Selma — The Analyst', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active"><?php esc_html_e( 'Monitoring', 'claw-agent' ); ?></span>
			</div>
		</div>
		<button type="button" class="wpc-btn wpc-btn--primary wpc-request-scan"
			data-agent="analyst"
			data-title="Full analytics review"
			data-description="Run a comprehensive analytics review: check pageview trends, identify top and bottom content, detect traffic anomalies, check Core Web Vitals, analyze referrer sources. Write a detailed report."
			data-task-key="analytics_analyst_review">
			<?php esc_html_e( 'Request Analytics Review', 'claw-agent' ); ?>
		</button>
	</section>

	<!-- 2. Latest Report -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Latest Analytics Report', 'claw-agent' ); ?></h2>
		<div id="wpc-latest-report" data-agent="analyst">
			<p class="wpc-empty-state"><?php esc_html_e( "Loading Selma's latest analysis...", 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- 3. KPI Cards -->
	<section class="wpc-kpi-grid wpc-kpi-grid--4" style="margin-bottom:20px;">
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $pageviews_today ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Pageviews Today', 'claw-agent' ); ?></span>
		</article>
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $total_pageviews_7d ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Pageviews (7 days)', 'claw-agent' ); ?></span>
		</article>
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( count( $top_pages ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Active Pages', 'claw-agent' ); ?></span>
		</article>
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( count( $referrers ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Traffic Sources', 'claw-agent' ); ?></span>
		</article>
	</section>

	<!-- 4. Core Web Vitals -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Core Web Vitals', 'claw-agent' ); ?></h2>
		<?php if ( ! $latest_cwv ) : ?>
			<p class="wpc-empty-state"><?php esc_html_e( 'No Core Web Vitals data yet. Selma measures CWV on a weekly schedule.', 'claw-agent' ); ?></p>
		<?php else : ?>
		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
			<!-- LCP -->
			<div class="wpc-kpi-card" style="text-align:center;">
				<span style="font-size:2rem;font-weight:700;color:<?php echo esc_attr( $cwv_color( 'lcp', $lcp ) ); ?>;">
					<?php echo esc_html( null !== $lcp ? number_format( $lcp, 1 ) . 's' : '—' ); ?>
				</span>
				<span class="wpc-kpi-label"><?php esc_html_e( 'LCP (Largest Contentful Paint)', 'claw-agent' ); ?></span>
				<span class="wpc-badge" style="color:<?php echo esc_attr( $cwv_color( 'lcp', $lcp ) ); ?>;">
					<?php echo esc_html( $cwv_label( 'lcp', $lcp ) ); ?>
				</span>
				<small style="color:#9ca3af;"><?php esc_html_e( 'Target: < 2.5s', 'claw-agent' ); ?></small>
			</div>
			<!-- INP -->
			<div class="wpc-kpi-card" style="text-align:center;">
				<span style="font-size:2rem;font-weight:700;color:<?php echo esc_attr( $cwv_color( 'inp', $inp ) ); ?>;">
					<?php echo esc_html( null !== $inp ? number_format( $inp, 0 ) . 'ms' : '—' ); ?>
				</span>
				<span class="wpc-kpi-label"><?php esc_html_e( 'INP (Interaction to Next Paint)', 'claw-agent' ); ?></span>
				<span class="wpc-badge" style="color:<?php echo esc_attr( $cwv_color( 'inp', $inp ) ); ?>;">
					<?php echo esc_html( $cwv_label( 'inp', $inp ) ); ?>
				</span>
				<small style="color:#9ca3af;"><?php esc_html_e( 'Target: < 200ms', 'claw-agent' ); ?></small>
			</div>
			<!-- CLS -->
			<div class="wpc-kpi-card" style="text-align:center;">
				<span style="font-size:2rem;font-weight:700;color:<?php echo esc_attr( $cwv_color( 'cls', $cls ) ); ?>;">
					<?php echo esc_html( null !== $cls ? number_format( $cls, 3 ) : '—' ); ?>
				</span>
				<span class="wpc-kpi-label"><?php esc_html_e( 'CLS (Cumulative Layout Shift)', 'claw-agent' ); ?></span>
				<span class="wpc-badge" style="color:<?php echo esc_attr( $cwv_color( 'cls', $cls ) ); ?>;">
					<?php echo esc_html( $cwv_label( 'cls', $cls ) ); ?>
				</span>
				<small style="color:#9ca3af;"><?php esc_html_e( 'Target: < 0.1', 'claw-agent' ); ?></small>
			</div>
		</div>
		<?php if ( ! empty( $latest_cwv['measured_at'] ) ) : ?>
		<p style="text-align:center;margin-top:12px;color:#9ca3af;font-size:0.8125rem;">
			<?php echo esc_html( sprintf( __( 'Measured: %s', 'claw-agent' ), wp_date( 'M j, Y H:i', strtotime( $latest_cwv['measured_at'] ) ) ) ); ?>
		</p>
		<?php endif; ?>
		<?php endif; ?>
	</section>

	<!-- 5. Top Pages -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Top Pages (7 days)', 'claw-agent' ); ?></h2>
		<?php if ( empty( $top_pages ) ) : ?>
			<p class="wpc-empty-state"><?php esc_html_e( 'No pageview data yet. The analytics module tracks visits using a privacy-first pixel (no cookies, respects DNT).', 'claw-agent' ); ?></p>
		<?php else : ?>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'claw-agent' ); ?></th>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Views', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $top_pages as $page ) : ?>
				<tr>
					<td><code><?php echo esc_html( $page['page_url'] ); ?></code></td>
					<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $page['views'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>

	<!-- 6. Referrer Breakdown -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Traffic Sources (7 days)', 'claw-agent' ); ?></h2>
		<?php if ( empty( $referrers ) ) : ?>
			<p class="wpc-empty-state"><?php esc_html_e( 'No referrer data yet. Sources will appear as your site receives traffic.', 'claw-agent' ); ?></p>
		<?php else : ?>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Source', 'claw-agent' ); ?></th>
					<th scope="col" style="text-align:right;"><?php esc_html_e( 'Visits', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $referrers as $ref ) : ?>
				<tr>
					<td><?php echo esc_html( $ref['source'] ); ?></td>
					<td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $ref['visits'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>

	<!-- 7. Selma's Report History -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Analytics Report History', 'claw-agent' ); ?></h2>
		<div id="wpc-scan-history" data-agent="analyst" data-limit="10">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading report history...', 'claw-agent' ); ?></p>
		</div>
	</section>

</div>

<script>
( function () {
	'use strict';

	function timeAgo( dateStr ) {
		if ( ! dateStr ) { return ''; }
		var diff = Math.floor( ( Date.now() - new Date( dateStr ).getTime() ) / 1000 );
		if ( diff < 60 )    { return diff + 's ago'; }
		if ( diff < 3600 )  { return Math.floor( diff / 60 ) + 'm ago'; }
		if ( diff < 86400 ) { return Math.floor( diff / 3600 ) + 'h ago'; }
		return Math.floor( diff / 86400 ) + 'd ago';
	}

	function el( tag, text, className ) {
		var node = document.createElement( tag );
		if ( text )      { node.textContent = text; }
		if ( className ) { node.className = className; }
		return node;
	}

	function empty( container ) {
		while ( container.firstChild ) { container.removeChild( container.firstChild ); }
	}

	function apiFetch( url, options ) {
		var headers = {
			'Content-Type': 'application/json',
			'X-WP-Nonce': ( window.wpClaw && window.wpClaw.nonce ) || ( window.wpApiSettings && window.wpApiSettings.nonce ) || ''
		};
		return fetch( url, Object.assign( { headers: headers, credentials: 'same-origin' }, options || {} ) )
			.then( function ( res ) {
				return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } );
			} )
			.catch( function () { return { ok: false, data: null }; } );
	}

	function getRestUrl() {
		return ( window.wpClaw && window.wpClaw.restUrl ) || '';
	}

	/* Load latest report */
	function loadLatestReport() {
		var restUrl = getRestUrl();
		if ( ! restUrl ) { return; }
		var container = document.getElementById( 'wpc-latest-report' );
		if ( ! container ) { return; }

		apiFetch( restUrl + 'reports?agent=analyst&limit=1' ).then( function ( res ) {
			var list = ( res.ok && res.data ) ? ( Array.isArray( res.data ) ? res.data : ( res.data.reports || [] ) ) : [];
			empty( container );
			container.dataset.loaded = 'true';
			var report = list[0];
			if ( ! report ) {
				container.appendChild( el( 'p', 'No analytics reports yet — Selma will post findings after her next review.', 'wpc-empty-state' ) );
				return;
			}
			var meta = document.createElement( 'div' );
			meta.style.cssText = 'display:flex;align-items:center;gap:12px;margin-bottom:12px;';
			if ( report.created_at || report.completed_at ) {
				meta.appendChild( el( 'span', timeAgo( report.completed_at || report.created_at ), 'wpc-kpi-label' ) );
			}
			if ( report.status ) {
				meta.appendChild( el( 'span', report.status, 'wpc-badge wpc-badge--' + ( 'done' === report.status ? 'done' : 'pending' ) ) );
			}
			container.appendChild( meta );
			if ( report.title ) { container.appendChild( el( 'h4', report.title ) ); }
			var content = report.evidence || report.result || report.content || report.description || '';
			if ( content ) {
				var pre = document.createElement( 'pre' );
				pre.textContent = content;
				pre.style.cssText = 'background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:12px;overflow:auto;font-size:0.8125rem;white-space:pre-wrap;word-break:break-word;max-height:360px;margin-top:8px;';
				container.appendChild( pre );
			}
		} );
	}

	/* Load report history */
	function loadHistory() {
		var restUrl = getRestUrl();
		if ( ! restUrl ) { return; }
		var container = document.getElementById( 'wpc-scan-history' );
		if ( ! container ) { return; }
		var limit = container.getAttribute( 'data-limit' ) || '10';

		apiFetch( restUrl + 'reports?agent=analyst&since=30d&limit=' + limit ).then( function ( res ) {
			var reports = ( res.ok && res.data ) ? ( Array.isArray( res.data ) ? res.data : ( res.data.reports || [] ) ) : [];
			empty( container );
			container.dataset.loaded = 'true';
			if ( ! reports.length ) {
				container.appendChild( el( 'p', 'No reports in the last 30 days.', 'wpc-empty-state' ) );
				return;
			}
			var table = document.createElement( 'table' );
			table.className = 'wpc-detail-table';
			var thead = document.createElement( 'thead' );
			var hrow = document.createElement( 'tr' );
			[ 'When', 'Title', 'Status' ].forEach( function ( h ) {
				var th = document.createElement( 'th' );
				th.scope = 'col';
				th.textContent = h;
				hrow.appendChild( th );
			} );
			thead.appendChild( hrow );
			table.appendChild( thead );
			var tbody = document.createElement( 'tbody' );
			reports.forEach( function ( r ) {
				var row = document.createElement( 'tr' );
				row.appendChild( el( 'td', r.completed_at ? timeAgo( r.completed_at ) : ( r.created_at ? timeAgo( r.created_at ) : '—' ) ) );
				row.appendChild( el( 'td', r.title || '(untitled)' ) );
				var tdS = document.createElement( 'td' );
				tdS.appendChild( el( 'span', r.status || 'done', 'wpc-badge wpc-badge--' + ( 'done' === ( r.status || 'done' ) ? 'done' : 'pending' ) ) );
				row.appendChild( tdS );
				tbody.appendChild( row );
			} );
			table.appendChild( tbody );
			container.appendChild( table );
		} );
	}

	/* Request scan button handler */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.wpc-request-scan' );
		var restUrl = getRestUrl();
		if ( ! btn || ! restUrl ) { return; }
		// NEW: skip if task manager handles this button.
		if ( btn.hasAttribute( 'data-task-key' ) ) { return; }
		if ( btn.disabled ) { return; }
		btn.disabled = true;
		btn.textContent = 'Requesting\u2026';
		apiFetch( restUrl + 'create-task', {
			method: 'POST',
			body: JSON.stringify( {
				agent: btn.getAttribute( 'data-agent' ) || 'analyst',
				title: btn.getAttribute( 'data-title' ) || 'Analytics review',
				priority: 'high',
				description: btn.getAttribute( 'data-description' ) || ''
			} )
		} ).then( function ( res ) {
			btn.textContent = res.ok ? 'Requested \u2014 Selma will process it shortly.' : 'Request failed.';
			if ( ! res.ok ) { btn.disabled = false; }
		} );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		loadLatestReport();
		loadHistory();
	} );
}() );
</script>
