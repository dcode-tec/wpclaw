<?php
/**
 * SEO & Content dashboard admin view — agent-first layout centred on Lina (Scribe).
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.2.0
 * @since      1.2.4 Rewritten — agent-first layout: Lina status bar, latest report hero,
 *                   KPI row, audit history, A/B tests, Sitemap & Robots.
 *                   Stale Content and Broken Links tables removed (now part of Lina's reports).
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

$plugin      = \WPClaw\WP_Claw::get_instance();
$seo_module  = $plugin->get_module( 'seo' );
$content_mod = $plugin->get_module( 'content' );

// If both modules are disabled, show enable notice and bail.
if ( null === $seo_module && null === $content_mod ) :
	?>
	<div class="wpc-admin-wrap">
		<div class="wpc-empty-state">
			<p>
				<?php
				printf(
					/* translators: %s: Link to modules settings page */
					esc_html__( 'The SEO and Content modules are not enabled. %s to activate them.', 'claw-agent' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-modules' ) ) . '">' . esc_html__( 'Go to Modules', 'claw-agent' ) . '</a>'
				);
				?>
			</p>
		</div>
	</div>
	<?php
	return;
endif;

// -------------------------------------------------------------------------
// Data gathering — KPI values from SEO module state.
// -------------------------------------------------------------------------
global $wpdb;
$seo = array();
if ( null !== $seo_module && method_exists( $seo_module, 'get_state' ) ) {
	$seo = $seo_module->get_state();
}

// Coverage calculations.
$total      = isset( $seo['total_published_posts'] ) ? (int) $seo['total_published_posts'] : 0;
$with_title = isset( $seo['posts_with_meta_title'] ) ? (int) $seo['posts_with_meta_title'] : 0;
$title_pct  = $total > 0 ? round( ( $with_title / $total ) * 100 ) : 0;

$with_desc = isset( $seo['posts_with_meta_desc'] ) ? (int) $seo['posts_with_meta_desc'] : 0;
$desc_pct  = $total > 0 ? round( ( $with_desc / $total ) * 100 ) : 0;

$active_ab_tests   = isset( $seo['active_ab_tests'] ) ? (int) $seo['active_ab_tests'] : 0;
$stale_count       = isset( $seo['stale_content_count'] ) ? (int) $seo['stale_content_count'] : 0;
$broken_link_count = isset( $seo['broken_link_count'] ) ? (int) $seo['broken_link_count'] : 0;
$content_issues    = $stale_count + $broken_link_count;
$last_sitemap_flush = isset( $seo['last_sitemap_flush'] ) ? sanitize_text_field( (string) $seo['last_sitemap_flush'] ) : '';
$has_custom_robots  = ! empty( $seo['robots_txt_custom_rules'] );

// -------------------------------------------------------------------------
// A/B Tests — filter.
// -------------------------------------------------------------------------
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter parameter.
$ab_filter  = isset( $_GET['ab_filter'] ) ? sanitize_key( $_GET['ab_filter'] ) : 'running';
$ab_table   = $wpdb->prefix . 'wp_claw_ab_tests';
$valid_tabs = array( 'running', 'completed', 'all' );
if ( ! in_array( $ab_filter, $valid_tabs, true ) ) {
	$ab_filter = 'running';
}

if ( 'all' === $ab_filter ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$ab_tests = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, post_id, variant_a_title, variant_a_desc, variant_b_title, variant_b_desc, impressions_a, impressions_b, clicks_a, clicks_b, status, winner, started_at, ended_at FROM %i ORDER BY started_at DESC LIMIT %d',
			$ab_table,
			50
		)
	);
} else {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$ab_tests = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, post_id, variant_a_title, variant_a_desc, variant_b_title, variant_b_desc, impressions_a, impressions_b, clicks_a, clicks_b, status, winner, started_at, ended_at FROM %i WHERE status = %s ORDER BY started_at DESC LIMIT %d',
			$ab_table,
			$ab_filter,
			50
		)
	);
}

// -------------------------------------------------------------------------
// Helpers.
// -------------------------------------------------------------------------
$wp_claw_ctr = function ( $clicks, $impressions ) {
	$clicks      = (int) $clicks;
	$impressions = (int) $impressions;
	return $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : 0;
};
$wp_claw_ab_badge = function ( $status ) {
	$map = array(
		'running'   => 'active',
		'completed' => 'done',
		'paused'    => 'pending',
	);
	$key = strtolower( sanitize_key( (string) $status ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : 'idle';
};

$current_page_url = admin_url( 'admin.php?page=wp-claw-seo-content' );
?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- ===== 1. Agent Status Bar — Lina (Scribe) ===== -->
	<section class="wpc-agent-status-bar" id="wpc-seo-agent-bar">
		<div class="wpc-agent-status-bar__identity">
			<span class="wpc-agent-status-bar__emoji" aria-hidden="true">✍️</span>
			<div>
				<strong class="wpc-agent-status-bar__name"><?php esc_html_e( 'Lina — The Scribe', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active wpc-agent-status-bar__badge" id="wpc-seo-agent-badge">
					<?php esc_html_e( 'Analyzing', 'claw-agent' ); ?>
				</span>
			</div>
		</div>
		<div class="wpc-agent-status-bar__meta">
			<span class="wpc-kpi-label" id="wpc-seo-agent-last-audit">
				<?php esc_html_e( 'Loading status…', 'claw-agent' ); ?>
			</span>
		</div>
		<div class="wpc-agent-status-bar__actions">
			<button type="button"
				class="wpc-btn wpc-btn--primary"
				id="wpc-seo-request-audit"
				aria-label="<?php esc_attr_e( 'Request an SEO audit from Lina', 'claw-agent' ); ?>">
				<?php esc_html_e( 'Request SEO Audit', 'claw-agent' ); ?>
				<span class="wpc-spinner" id="wpc-seo-audit-spinner" style="display:none;"></span>
			</button>
		</div>
	</section>

	<!-- ===== 2. Latest SEO Report — hero ===== -->
	<section class="wpc-card">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Latest SEO &amp; Content Report', 'claw-agent' ); ?></h3>
		<div id="wpc-seo-report">
			<p class="wpc-empty-state"><?php esc_html_e( "Loading Lina's latest analysis…", 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ===== 3. Quick Metrics — KPI row (SEO module state) ===== -->
	<section class="wpc-kpi-grid">
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-label"><?php esc_html_e( 'Meta Title Coverage', 'claw-agent' ); ?></span>
			<span class="wpc-kpi-value"><?php echo esc_html( $title_pct ); ?>%</span>
			<div class="wpc-coverage-bar">
				<div class="wpc-coverage-bar__track">
					<div class="wpc-coverage-bar__fill" style="width:<?php echo esc_attr( $title_pct ); ?>%"></div>
				</div>
				<span class="wpc-coverage-bar__label">
					<?php
					printf(
						/* translators: 1: posts with meta title, 2: total posts */
						esc_html__( '%1$s / %2$s', 'claw-agent' ),
						esc_html( number_format_i18n( $with_title ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			</div>
		</article>
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-label"><?php esc_html_e( 'Meta Description Coverage', 'claw-agent' ); ?></span>
			<span class="wpc-kpi-value"><?php echo esc_html( $desc_pct ); ?>%</span>
			<div class="wpc-coverage-bar">
				<div class="wpc-coverage-bar__track">
					<div class="wpc-coverage-bar__fill" style="width:<?php echo esc_attr( $desc_pct ); ?>%"></div>
				</div>
				<span class="wpc-coverage-bar__label">
					<?php
					printf(
						/* translators: 1: posts with meta description, 2: total posts */
						esc_html__( '%1$s / %2$s', 'claw-agent' ),
						esc_html( number_format_i18n( $with_desc ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			</div>
		</article>
		<article class="wpc-kpi-card">
			<span class="wpc-kpi-label"><?php esc_html_e( 'Active A/B Tests', 'claw-agent' ); ?></span>
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $active_ab_tests ) ); ?></span>
			<span class="wpc-badge wpc-badge--<?php echo esc_attr( $active_ab_tests > 0 ? 'active' : 'idle' ); ?>">
				<?php echo esc_html( $active_ab_tests > 0 ? __( 'Running', 'claw-agent' ) : __( 'None', 'claw-agent' ) ); ?>
			</span>
		</article>
		<article class="wpc-kpi-card <?php echo esc_attr( $content_issues > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-label"><?php esc_html_e( 'Content Issues', 'claw-agent' ); ?></span>
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $content_issues ) ); ?></span>
			<?php if ( $content_issues > 0 ) : ?>
				<span class="wpc-badge wpc-badge--error">
					<?php
					printf(
						/* translators: 1: stale content count, 2: broken link count */
						esc_html__( '%1$s stale, %2$s broken links', 'claw-agent' ),
						esc_html( number_format_i18n( $stale_count ) ),
						esc_html( number_format_i18n( $broken_link_count ) )
					);
					?>
				</span>
			<?php else : ?>
				<span class="wpc-badge wpc-badge--done"><?php esc_html_e( 'All clear', 'claw-agent' ); ?></span>
			<?php endif; ?>
		</article>
	</section>

	<!-- ===== 4. Audit History ===== -->
	<section class="wpc-card">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'SEO Audit History', 'claw-agent' ); ?></h3>
		<div id="wpc-seo-audit-history">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading audit history…', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ===== 5. A/B Tests ===== -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'A/B Tests', 'claw-agent' ); ?></h2>
		<nav class="wpc-nav-tabs">
			<?php foreach ( $valid_tabs as $tab ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'ab_filter', $tab, $current_page_url ) ); ?>"
					class="wpc-nav-tabs__item <?php echo esc_attr( $ab_filter === $tab ? 'wpc-nav-tabs__item--active' : '' ); ?>">
					<?php echo esc_html( ucfirst( $tab ) ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( empty( $ab_tests ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No A/B tests recorded.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Post', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Variant A', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Variant B', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Impressions', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Winner', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Started', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ab_tests as $test ) : ?>
						<?php
						$post_title  = get_the_title( (int) $test->post_id );
						$edit_link   = get_edit_post_link( (int) $test->post_id );
						$imp_a       = (int) $test->impressions_a;
						$imp_b       = (int) $test->impressions_b;
						$clicks_a    = (int) $test->clicks_a;
						$clicks_b    = (int) $test->clicks_b;
						$ctr_a       = $wp_claw_ctr( $clicks_a, $imp_a );
						$ctr_b       = $wp_claw_ctr( $clicks_b, $imp_b );
						$total_imp   = $imp_a + $imp_b;
						$test_status = sanitize_key( (string) $test->status );
						$test_winner = sanitize_key( (string) $test->winner );
						?>
						<tr>
							<td>
								<?php if ( $edit_link ) : ?>
									<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $post_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $post_title ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span class="wpc-badge wpc-badge--<?php echo esc_attr( $wp_claw_ab_badge( $test_status ) ); ?>">
									<?php echo esc_html( ucfirst( $test_status ) ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( sanitize_text_field( (string) $test->variant_a_title ) ); ?>
								<br><small><?php echo esc_html( sprintf( '%s%% CTR', $ctr_a ) ); ?></small>
							</td>
							<td>
								<?php echo esc_html( sanitize_text_field( (string) $test->variant_b_title ) ); ?>
								<br><small><?php echo esc_html( sprintf( '%s%% CTR', $ctr_b ) ); ?></small>
							</td>
							<td><?php echo esc_html( number_format_i18n( $total_imp ) ); ?></td>
							<td>
								<?php if ( '' !== $test_winner && '-' !== $test_winner ) : ?>
									<span class="wpc-badge wpc-badge--done"><?php echo esc_html( strtoupper( $test_winner ) ); ?></span>
								<?php else : ?>
									<span class="wpc-badge wpc-badge--idle">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $test->started_at ) ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference */
											__( '%s ago', 'claw-agent' ),
											human_time_diff( strtotime( $test->started_at ) )
										)
									);
									?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- ===== 6. Sitemap & Robots ===== -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Sitemap &amp; Robots', 'claw-agent' ); ?></h2>
		<table class="wpc-detail-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last Sitemap Flush', 'claw-agent' ); ?></th>
					<td>
						<?php if ( '' !== $last_sitemap_flush ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human-readable time difference */
									__( '%s ago', 'claw-agent' ),
									human_time_diff( strtotime( $last_sitemap_flush ) )
								)
							);
							?>
							<br><small><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_sitemap_flush ) ) ); ?></small>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'Never', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Custom Robots.txt Rules', 'claw-agent' ); ?></th>
					<td>
						<?php if ( $has_custom_robots ) : ?>
							<span class="wpc-badge wpc-badge--active"><?php esc_html_e( 'Yes', 'claw-agent' ); ?></span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'No', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	</section>

</div><!-- .wpc-admin-wrap -->

<?php
/**
 * Inline script — SEO page only.
 *
 * Loads agent status, latest report (hero), and audit history from the Klawty
 * gateway REST proxy. Handles "Request SEO Audit" button.
 *
 * Uses only safe DOM methods (createElement / textContent / appendChild).
 * No innerHTML. All user-supplied data treated as text.
 *
 * @since 1.2.4
 */
?>
<script>
( function () {
	'use strict';

	if ( typeof wpClaw === 'undefined' || ! wpClaw.restUrl ) {
		return;
	}

	/* -------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Format an ISO timestamp as "X ago".
	 *
	 * @param {string} iso
	 * @return {string}
	 */
	function timeAgo( iso ) {
		var diff = Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 1000 );
		if ( diff < 60 )    { return diff + 's ago'; }
		if ( diff < 3600 )  { return Math.floor( diff / 60 ) + 'm ago'; }
		if ( diff < 86400 ) { return Math.floor( diff / 3600 ) + 'h ago'; }
		return Math.floor( diff / 86400 ) + 'd ago';
	}

	/**
	 * Build fetch options matching the main IIFE pattern.
	 *
	 * @param {string} method
	 * @param {*}      [body]
	 * @return {RequestInit}
	 */
	function fetchOpts( method, body ) {
		var opts = {
			method  : method,
			headers : {
				'Content-Type' : 'application/json',
				'X-WP-Nonce'   : wpClaw.nonce,
			},
			credentials : 'same-origin',
		};
		if ( body !== undefined ) {
			opts.body = JSON.stringify( body );
		}
		return opts;
	}

	/**
	 * Replace all children of a container with a single text paragraph.
	 *
	 * @param {HTMLElement} el
	 * @param {string}      text
	 * @param {string}      [cls]
	 */
	function showMessage( el, text, cls ) {
		while ( el.firstChild ) { el.removeChild( el.firstChild ); }
		var p = document.createElement( 'p' );
		p.className = cls || 'wpc-empty-state';
		p.textContent = text;
		el.appendChild( p );
	}

	/* -------------------------------------------------------------------
	 * 1. Agent status bar — /api/agents → find scribe
	 * ----------------------------------------------------------------- */
	function loadAgentStatus() {
		var badge    = document.getElementById( 'wpc-seo-agent-badge' );
		var lastAudit = document.getElementById( 'wpc-seo-agent-last-audit' );
		if ( ! badge || ! lastAudit ) { return; }

		fetch( wpClaw.restUrl + 'agents', fetchOpts( 'GET' ) )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				var agents = Array.isArray( data.agents ) ? data.agents : [];
				var lina   = null;
				for ( var i = 0; i < agents.length; i++ ) {
					if ( 'scribe' === agents[ i ].agent || 'lina' === ( agents[ i ].name || '' ).toLowerCase() ) {
						lina = agents[ i ];
						break;
					}
				}
				if ( ! lina ) { return; }

				// Update badge.
				badge.textContent = '';
				var health = lina.health || 'idle';
				var badgeMap = {
					ok       : { cls: 'done',    label: <?php echo wp_json_encode( __( 'Active', 'claw-agent' ) ); ?> },
					degraded : { cls: 'pending', label: <?php echo wp_json_encode( __( 'Degraded', 'claw-agent' ) ); ?> },
					idle     : { cls: 'idle',    label: <?php echo wp_json_encode( __( 'Idle', 'claw-agent' ) ); ?> },
					error    : { cls: 'failed',  label: <?php echo wp_json_encode( __( 'Error', 'claw-agent' ) ); ?> },
				};
				var bInfo = badgeMap[ health ] || badgeMap.idle;
				badge.className = 'wpc-badge wpc-badge--' + bInfo.cls + ' wpc-agent-status-bar__badge';
				badge.textContent = bInfo.label;

				// Update last audit time.
				var heartbeat = lina.last_heartbeat || lina.latest_report_time || '';
				if ( heartbeat ) {
					lastAudit.textContent = <?php echo wp_json_encode( __( 'Last audit: ', 'claw-agent' ) ); ?> + timeAgo( heartbeat );
				} else {
					lastAudit.textContent = <?php echo wp_json_encode( __( 'No audit yet', 'claw-agent' ) ); ?>;
				}
			} )
			.catch( function () {
				/* Silently fail — status bar is non-critical. */
			} );
	}

	/* -------------------------------------------------------------------
	 * 2. Latest SEO report — hero section
	 * ----------------------------------------------------------------- */
	function loadLatestReport() {
		var container = document.getElementById( 'wpc-seo-report' );
		if ( ! container ) { return; }

		fetch(
			wpClaw.restUrl + 'reports?agent=scribe&limit=1',
			fetchOpts( 'GET' )
		)
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				while ( container.firstChild ) { container.removeChild( container.firstChild ); }

				var reports = ( data && Array.isArray( data.reports ) ) ? data.reports : [];
				if ( ! reports.length ) {
					showMessage(
						container,
						<?php echo wp_json_encode( __( "No report yet. Lina will produce her first SEO analysis within 15 minutes.", 'claw-agent' ) ); ?>
					);
					return;
				}

				var r = reports[ 0 ];

				// Header row: title + timestamp.
				var header = document.createElement( 'div' );
				header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;';

				var titleEl = document.createElement( 'strong' );
				titleEl.style.fontSize = '1rem';
				titleEl.textContent = r.title || '';
				header.appendChild( titleEl );

				var timeEl = document.createElement( 'span' );
				timeEl.className = 'wpc-kpi-label';
				timeEl.textContent = r.completed_at ? timeAgo( r.completed_at ) : '';
				header.appendChild( timeEl );
				container.appendChild( header );

				// Priority badge.
				if ( r.priority ) {
					var priorityMap = { high: 'failed', normal: 'active', low: 'idle' };
					var badgeCls = priorityMap[ r.priority ] || 'idle';
					var pb = document.createElement( 'span' );
					pb.className = 'wpc-badge wpc-badge--' + badgeCls;
					pb.style.marginBottom = '10px';
					pb.style.display = 'inline-block';
					pb.textContent = r.priority.charAt( 0 ).toUpperCase() + r.priority.slice( 1 );
					container.appendChild( pb );
				}

				// Evidence body.
				if ( r.evidence ) {
					var evidenceEl = document.createElement( 'pre' );
					evidenceEl.style.cssText = 'white-space:pre-wrap;word-break:break-word;font-size:0.875rem;line-height:1.6;color:var(--wpc-text,#374151);background:var(--wpc-surface,#f9fafb);padding:16px;border-radius:6px;max-height:400px;overflow:auto;';
					evidenceEl.textContent = r.evidence;
					container.appendChild( evidenceEl );
				}

				// Cost indicator.
				if ( r.cost_usd && parseFloat( r.cost_usd ) > 0 ) {
					var costEl = document.createElement( 'p' );
					costEl.className = 'wpc-kpi-label';
					costEl.style.marginTop = '8px';
					costEl.textContent = <?php echo wp_json_encode( __( 'LLM cost: ', 'claw-agent' ) ); ?> + '$' + parseFloat( r.cost_usd ).toFixed( 4 );
					container.appendChild( costEl );
				}
			} )
			.catch( function () {
				showMessage(
					container,
					<?php echo wp_json_encode( __( 'Could not load latest report.', 'claw-agent' ) ); ?>
				);
			} );
	}

	/* -------------------------------------------------------------------
	 * 3. Audit history — /api/reports?agent=scribe&since=30d&limit=10
	 * ----------------------------------------------------------------- */
	function loadAuditHistory() {
		var container = document.getElementById( 'wpc-seo-audit-history' );
		if ( ! container ) { return; }

		fetch(
			wpClaw.restUrl + 'reports?agent=scribe&since=30d&limit=10',
			fetchOpts( 'GET' )
		)
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				while ( container.firstChild ) { container.removeChild( container.firstChild ); }

				var reports = ( data && Array.isArray( data.reports ) ) ? data.reports : [];
				if ( ! reports.length ) {
					showMessage(
						container,
						<?php echo wp_json_encode( __( 'No audits in the last 30 days.', 'claw-agent' ) ); ?>
					);
					return;
				}

				var list = document.createElement( 'div' );

				reports.forEach( function ( r ) {
					var row = document.createElement( 'div' );
					row.style.cssText = 'padding:10px 0;border-bottom:1px solid var(--wpc-border,#e5e7eb);display:flex;justify-content:space-between;align-items:flex-start;gap:12px;';

					var left = document.createElement( 'div' );
					left.style.flex = '1';

					var titleEl = document.createElement( 'strong' );
					titleEl.style.display = 'block';
					titleEl.textContent = r.title || '';
					left.appendChild( titleEl );

					if ( r.evidence ) {
						var previewLines = r.evidence.split( '\n' )
							.filter( function ( l ) { return l.trim() && ! /^#+\s/.test( l.trim() ); } )
							.slice( 0, 2 );
						if ( previewLines.length ) {
							var preview = document.createElement( 'span' );
							preview.className = 'wpc-kpi-label';
							preview.style.display = 'block';
							preview.style.marginTop = '2px';
							preview.textContent = previewLines.join( ' · ' );
							left.appendChild( preview );
						}
					}

					row.appendChild( left );

					var right = document.createElement( 'div' );
					right.style.cssText = 'white-space:nowrap;text-align:right;';

					var timeEl = document.createElement( 'span' );
					timeEl.className = 'wpc-kpi-label';
					timeEl.textContent = r.completed_at ? timeAgo( r.completed_at ) : '';
					right.appendChild( timeEl );

					row.appendChild( right );
					list.appendChild( row );
				} );

				container.appendChild( list );
			} )
			.catch( function () {
				showMessage(
					container,
					<?php echo wp_json_encode( __( 'Could not load audit history.', 'claw-agent' ) ); ?>
				);
			} );
	}

	/* -------------------------------------------------------------------
	 * 4. "Request SEO Audit" button
	 * ----------------------------------------------------------------- */
	function initRequestAuditButton() {
		var btn     = document.getElementById( 'wpc-seo-request-audit' );
		var spinner = document.getElementById( 'wpc-seo-audit-spinner' );
		if ( ! btn ) { return; }

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			if ( spinner ) { spinner.style.display = 'inline-block'; }

			fetch(
				wpClaw.restUrl + 'create-task',
				fetchOpts( 'POST', {
					agent       : 'scribe',
					title       : <?php echo wp_json_encode( __( 'Manual SEO audit', 'claw-agent' ) ); ?>,
					priority    : 'high',
					description : <?php echo wp_json_encode( __( 'Admin requested an SEO audit. Check meta title/description coverage, detect stale content, find broken links, check sitemap freshness, analyze top pages. Report all findings.', 'claw-agent' ) ); ?>,
				} )
			)
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					btn.disabled = false;
					if ( spinner ) { spinner.style.display = 'none'; }

					if ( data && ( data.id || data.task_id || data.ok ) ) {
						btn.textContent = <?php echo wp_json_encode( __( 'Audit Requested', 'claw-agent' ) ); ?>;
						btn.className   = 'wpc-btn wpc-btn--secondary';
						setTimeout( function () {
							btn.textContent = <?php echo wp_json_encode( __( 'Request SEO Audit', 'claw-agent' ) ); ?>;
							btn.className   = 'wpc-btn wpc-btn--primary';
						}, 5000 );
					} else {
						var errMsg = ( data && data.message ) ? data.message : <?php echo wp_json_encode( __( 'Failed to queue audit.', 'claw-agent' ) ); ?>;
						/* Safe — textContent only. */
						var notice = document.createElement( 'p' );
						notice.className = 'wpc-empty-state';
						notice.style.color = 'var(--wpc-error,#ef4444)';
						notice.textContent = errMsg;
						var bar = document.getElementById( 'wpc-seo-agent-bar' );
						if ( bar ) { bar.appendChild( notice ); }
					}
				} )
				.catch( function () {
					btn.disabled = false;
					if ( spinner ) { spinner.style.display = 'none'; }
					btn.textContent = <?php echo wp_json_encode( __( 'Request failed — retry', 'claw-agent' ) ); ?>;
					setTimeout( function () {
						btn.textContent = <?php echo wp_json_encode( __( 'Request SEO Audit', 'claw-agent' ) ); ?>;
					}, 4000 );
				} );
		} );
	}

	/* -------------------------------------------------------------------
	 * Boot — after DOM is ready
	 * ----------------------------------------------------------------- */
	function boot() {
		loadAgentStatus();
		loadLatestReport();
		loadAuditHistory();
		initRequestAuditButton();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
</script>
