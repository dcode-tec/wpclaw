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

$pages_seo = array();
if ( null !== $seo_module && method_exists( $seo_module, 'get_pages_seo_status' ) ) {
	$pages_seo = $seo_module->get_pages_seo_status( 30 );
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
// Stale content.
// -------------------------------------------------------------------------
$stale_posts = get_transient( 'wp_claw_stale_content' );
if ( ! is_array( $stale_posts ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$stale_posts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title, post_modified FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s AND post_modified < %s ORDER BY post_modified ASC LIMIT %d",
			'publish',
			'post',
			gmdate( 'Y-m-d H:i:s', strtotime( '-12 months' ) ),
			20
		)
	);
}

// -------------------------------------------------------------------------
// Broken links.
// -------------------------------------------------------------------------
$broken_links = get_transient( 'wp_claw_broken_links' );

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
	<section class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<?php echo wp_claw_agent_avatar( 'Lina', 40 ); ?>
			<div>
				<strong style="font-size:1rem;"><?php esc_html_e( 'Lina — The Scribe', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active" id="wpc-seo-agent-badge" style="margin-left:8px;">
					<?php esc_html_e( 'Analyzing', 'claw-agent' ); ?>
				</span>
				<br>
				<span style="font-size:0.75rem;color:#9ca3af;" id="wpc-seo-agent-last-audit"></span>
			</div>
		</div>
		<button type="button"
			class="wpc-btn wpc-btn--primary wpc-request-scan"
			id="wpc-seo-request-audit"
			data-agent="scribe"
			data-title="<?php esc_attr_e( 'Manual SEO audit', 'claw-agent' ); ?>"
			data-description="<?php esc_attr_e( 'Admin requested full SEO audit. Run seo_detect_stale_content, seo_find_broken_links, seo_analyze_content, content_check_content_freshness. Report issues ranked by SEO impact.', 'claw-agent' ); ?>"
			data-task-key="seo_scribe_audit"
			aria-label="<?php esc_attr_e( 'Request an SEO audit from Lina', 'claw-agent' ); ?>"
			style="padding:10px 20px;border-radius:8px;font-weight:600;">
			<?php esc_html_e( 'Request SEO Audit', 'claw-agent' ); ?>
				<span class="wpc-spinner" id="wpc-seo-audit-spinner" style="display:none;"></span>
			</button>
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

	<!-- ===== 4. Page-Level SEO Status ===== -->
	<?php if ( ! empty( $pages_seo ) ) : ?>
	<section class="wpc-card" style="margin-top:20px;margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Page-Level SEO Status', 'claw-agent' ); ?></h2>
		<div style="margin-bottom:12px;display:flex;gap:8px;">
			<button data-agent-action="fix_all_meta" data-agent="scribe"
				data-context="<?php echo esc_attr__( 'Write meta titles and descriptions for all pages that are missing them. Write in the site language. Include site name at end of titles.', 'claw-agent' ); ?>"
				data-task-key="seo_scribe_fix_all_meta"
				class="wpc-btn wpc-btn--primary" style="font-size:0.8125rem;">
				<?php esc_html_e( 'Auto-fix All Missing Meta', 'claw-agent' ); ?>
			</button>
		</div>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meta Title', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meta Desc', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Schema', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Words', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Modified', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pages_seo as $page ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $page['id'] ) ); ?>" style="color:#4f46e5;">
							<?php echo esc_html( mb_strimwidth( $page['title'], 0, 50, '...' ) ); ?>
						</a>
					</td>
					<td><?php echo esc_html( $page['type'] ); ?></td>
					<td data-inline-edit="meta_title"
						data-target-id="<?php echo esc_attr( $page['id'] ); ?>"
						data-current-value="<?php echo esc_attr( $page['meta_title'] ?? '' ); ?>"
						style="cursor:pointer;" title="<?php esc_attr_e( 'Click to edit meta title', 'claw-agent' ); ?>">
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $page['has_title'] ? 'done' : 'failed' ); ?>">
							<?php echo esc_html( $page['has_title'] ? '✓' : '✗' ); ?>
						</span>
					</td>
					<td data-inline-edit="meta_desc"
						data-target-id="<?php echo esc_attr( $page['id'] ); ?>"
						data-current-value="<?php echo esc_attr( $page['meta_desc'] ?? '' ); ?>"
						style="cursor:pointer;" title="<?php esc_attr_e( 'Click to edit meta description', 'claw-agent' ); ?>">
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $page['has_desc'] ? 'done' : 'failed' ); ?>">
							<?php echo esc_html( $page['has_desc'] ? '✓' : '✗' ); ?>
						</span>
					</td>
					<td>
						<?php if ( ! empty( $page['schema'] ) ) : ?>
							<span class="wpc-badge wpc-badge--done"><?php echo esc_html( $page['schema'] ); ?></span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'None', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $page['words'] ) ); ?></td>
					<td><?php echo esc_html( wp_date( 'M j', strtotime( $page['modified'] ) ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>

	<!-- ===== 5. Sitemap Status ===== -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Sitemap', 'claw-agent' ); ?></h2>
			<button type="button" class="wpc-btn wpc-btn--primary wpc-request-scan"
				data-agent="scribe"
				data-title="Regenerate XML sitemap"
				data-description="Flush and regenerate the WordPress XML sitemap. Verify it includes all published posts and pages."
				data-task-key="seo_scribe_regenerate_sitemap">
				<?php esc_html_e( 'Regenerate Sitemap', 'claw-agent' ); ?>
			</button>
		</div>
		<table class="wpc-detail-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sitemap URL', 'claw-agent' ); ?></th>
					<td>
						<a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener" style="color:#4f46e5;">
							<?php echo esc_html( home_url( '/wp-sitemap.xml' ) ); ?> &#8599;
						</a>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Published Posts', 'claw-agent' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
				</tr>
				<?php if ( ! empty( $last_sitemap_flush ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last Regenerated', 'claw-agent' ); ?></th>
					<td><?php echo esc_html( wp_date( 'M j, Y H:i', strtotime( $last_sitemap_flush ) ) ); ?></td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</section>

	<!-- ===== 6. Stale Content (new, with action buttons) ===== -->
	<?php if ( $stale_count > 0 ) : ?>
	<?php
	// Query stale posts (not modified in 12+ months).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$stale_posts_new = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_title, post_modified FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type = 'post'
			 AND post_modified < DATE_SUB( NOW(), INTERVAL 12 MONTH )
			 ORDER BY post_modified ASC LIMIT %d",
			20
		)
	);
	?>
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Stale Content', 'claw-agent' ); ?></h2>
		<p style="margin-bottom:12px;color:#6b7280;font-size:0.875rem;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of stale pages */
					__( '%d pages have not been updated in over 12 months.', 'claw-agent' ),
					$stale_count
				)
			);
			?>
		</p>
		<?php if ( ! empty( $stale_posts_new ) ) : ?>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last Modified', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Days Stale', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $stale_posts_new as $stale_new ) :
					$stale_new_days = (int) ( ( time() - strtotime( $stale_new->post_modified ) ) / DAY_IN_SECONDS );
				?>
				<tr>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $stale_new->ID ) ); ?>" style="color:#4f46e5;">
							<?php echo esc_html( mb_strimwidth( $stale_new->post_title, 0, 50, '...' ) ); ?>
						</a>
					</td>
					<td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $stale_new->post_modified ) ) ); ?></td>
					<td>
						<span style="color:<?php echo esc_attr( $stale_new_days > 365 ? '#dc2626' : '#d97706' ); ?>;">
							<?php echo esc_html( number_format_i18n( $stale_new_days ) ); ?>d
						</span>
					</td>
					<td>
						<button type="button" class="wpc-btn wpc-btn--small wpc-request-scan"
							data-agent="scribe"
							data-title="<?php echo esc_attr( 'Refresh stale content: ' . $stale_new->post_title ); ?>"
							data-description="<?php echo esc_attr( 'Review and refresh the content of post ID ' . $stale_new->ID . ' (\'' . $stale_new->post_title . '\'). Update outdated information, improve SEO meta tags, and update the modified date.' ); ?>"
							data-task-key="<?php echo esc_attr( 'seo_scribe_refresh_' . $stale_new->ID ); ?>">
							<?php esc_html_e( 'Request Refresh', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<!-- ===== 7. Audit History ===== -->
	<section class="wpc-card">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'SEO Audit History', 'claw-agent' ); ?></h3>
		<div id="wpc-seo-audit-history">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading audit history…', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ===== 5. Recent Actions — last 15 in 24h ===== -->
	<section class="wpc-card">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Recent Actions', 'claw-agent' ); ?></h3>
		<div id="wpc-seo-recent-actions">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading recent actions…', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ===== 6. Detailed Data (de-emphasised) ===== -->

	<!-- A/B Tests -->
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
				<div style="font-size:1.5rem;margin-bottom:8px;">🧪</div>
				<p style="font-size:0.875rem;"><?php esc_html_e( 'No A/B tests running. Lina can create one from the SEO table above.', 'claw-agent' ); ?></p>
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

	<!-- Stale Content -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Stale Content', 'claw-agent' ); ?></h2>
		<?php if ( empty( $stale_posts ) ) : ?>
			<div class="wpc-empty-state">
				<p style="font-size:0.875rem;"><?php esc_html_e( 'No stale content detected. All published content has been updated within 12 months.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Post Title', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last Modified', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Age', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Word Count', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stale_posts as $stale ) : ?>
						<?php
						$stale_id    = isset( $stale->ID ) ? (int) $stale->ID : ( isset( $stale->id ) ? (int) $stale->id : 0 );
						$stale_title = $stale_id > 0 ? get_the_title( $stale_id ) : sanitize_text_field( (string) ( $stale->post_title ?? '' ) );
						$stale_edit  = $stale_id > 0 ? get_edit_post_link( $stale_id ) : '';
						$stale_mod   = isset( $stale->post_modified ) ? sanitize_text_field( (string) $stale->post_modified ) : '';
						$word_count  = $stale_id > 0 ? (int) get_post_meta( $stale_id, '_wp_claw_word_count', true ) : 0;
						?>
						<tr>
							<td>
								<?php if ( $stale_edit ) : ?>
									<a href="<?php echo esc_url( $stale_edit ); ?>"><?php echo esc_html( $stale_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $stale_title ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $stale_mod ) : ?>
									<?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $stale_mod ) ) ); ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $stale_mod ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference */
											__( '%s ago', 'claw-agent' ),
											human_time_diff( strtotime( $stale_mod ) )
										)
									);
									?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $word_count > 0 ? number_format_i18n( $word_count ) : '—' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- Broken Links -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Broken Links', 'claw-agent' ); ?></h2>
		<?php if ( ! is_array( $broken_links ) || empty( $broken_links ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'Run an SEO audit to detect broken links.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Source Post', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Target URL', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'HTTP Status', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $broken_links as $link ) : ?>
						<?php
						if ( ! is_array( $link ) ) {
							continue;
						}
						$link_post_id  = isset( $link['post_id'] ) ? (int) $link['post_id'] : 0;
						$link_title    = $link_post_id > 0 ? get_the_title( $link_post_id ) : __( 'Unknown', 'claw-agent' );
						$link_edit     = $link_post_id > 0 ? get_edit_post_link( $link_post_id ) : '';
						$link_url      = isset( $link['url'] ) ? esc_url( $link['url'] ) : '';
						$link_url_disp = mb_strlen( $link_url ) > 60 ? mb_substr( $link_url, 0, 57 ) . '...' : $link_url;
						$link_status   = isset( $link['http_status'] ) ? sanitize_text_field( (string) $link['http_status'] ) : '';
						$status_lower  = strtolower( $link_status );

						if ( '404' === $link_status || 'timeout' === $status_lower ) {
							$link_badge = 'error';
						} elseif ( '301' === $link_status || '302' === $link_status ) {
							$link_badge = 'pending';
						} else {
							$link_badge = 'idle';
						}
						?>
						<tr>
							<td>
								<?php if ( $link_edit ) : ?>
									<a href="<?php echo esc_url( $link_edit ); ?>"><?php echo esc_html( $link_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $link_title ); ?>
								<?php endif; ?>
							</td>
							<td title="<?php echo esc_attr( $link_url ); ?>">
								<code><?php echo esc_html( $link_url_disp ); ?></code>
							</td>
							<td>
								<span class="wpc-badge wpc-badge--<?php echo esc_attr( $link_badge ); ?>">
									<?php echo esc_html( $link_status ); ?>
								</span>
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

	// wpClaw may not exist yet — defer check to DOMContentLoaded.
	var _wpClawReady = false;

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
				container.dataset.loaded = 'true';

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
				container.dataset.loaded = 'true';

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
	 * 4. Recent actions — /api/activity?agent=scribe&since=24h&limit=15
	 * ----------------------------------------------------------------- */
	function loadRecentActions() {
		var container = document.getElementById( 'wpc-seo-recent-actions' );
		if ( ! container ) { return; }

		fetch(
			wpClaw.restUrl + 'activity?agent=scribe&since=24h&limit=15',
			fetchOpts( 'GET' )
		)
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				while ( container.firstChild ) { container.removeChild( container.firstChild ); }
				container.dataset.loaded = 'true';

				var actions = ( data && Array.isArray( data.activity ) ) ? data.activity
					: ( data && Array.isArray( data.actions ) ) ? data.actions : [];

				if ( ! actions.length ) {
					showMessage(
						container,
						<?php echo wp_json_encode( __( 'No actions in the last 24 hours.', 'claw-agent' ) ); ?>
					);
					return;
				}

				var list = document.createElement( 'div' );

				actions.forEach( function ( a ) {
					var row = document.createElement( 'div' );
					row.style.cssText = 'padding:8px 0;border-bottom:1px solid var(--wpc-border,#e5e7eb);display:flex;justify-content:space-between;align-items:center;gap:12px;';

					var left = document.createElement( 'div' );
					left.style.flex = '1';

					var action = document.createElement( 'span' );
					action.style.display = 'block';
					action.textContent = a.action || a.title || a.description || '';
					left.appendChild( action );

					if ( a.result ) {
						var resultEl = document.createElement( 'span' );
						resultEl.className = 'wpc-kpi-label';
						resultEl.style.display = 'block';
						resultEl.style.marginTop = '2px';
						resultEl.textContent = a.result;
						left.appendChild( resultEl );
					}

					row.appendChild( left );

					var right = document.createElement( 'div' );
					right.style.cssText = 'white-space:nowrap;text-align:right;';

					var statusBadge = document.createElement( 'span' );
					var statusMap = { done: 'done', failed: 'failed', error: 'failed', ok: 'done', success: 'done' };
					var statusKey  = ( a.status || '' ).toLowerCase();
					var statusCls  = statusMap[ statusKey ] || 'idle';
					statusBadge.className = 'wpc-badge wpc-badge--' + statusCls;
					statusBadge.textContent = a.status || '';
					right.appendChild( statusBadge );

					var timeEl = document.createElement( 'span' );
					timeEl.className = 'wpc-kpi-label';
					timeEl.style.display = 'block';
					timeEl.style.marginTop = '4px';
					timeEl.textContent = a.created_at ? timeAgo( a.created_at ) : '';
					right.appendChild( timeEl );

					row.appendChild( right );
					list.appendChild( row );
				} );

				container.appendChild( list );
			} )
			.catch( function () {
				showMessage(
					container,
					<?php echo wp_json_encode( __( 'Could not load recent actions.', 'claw-agent' ) ); ?>
				);
			} );
	}

	/* -------------------------------------------------------------------
	 * 5. "Request SEO Audit" button
	 * ----------------------------------------------------------------- */
	function initRequestAuditButton() {
		var btn     = document.getElementById( 'wpc-seo-request-audit' );
		var spinner = document.getElementById( 'wpc-seo-audit-spinner' );
		if ( ! btn ) { return; }

		btn.addEventListener( 'click', function () {
			// NEW: skip if task manager handles this button.
			if ( btn.hasAttribute( 'data-task-key' ) ) { return; }
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
		if ( typeof wpClaw === 'undefined' || ! wpClaw.restUrl ) {
			console.warn( '[WP-Claw SEO] wpClaw.restUrl not available yet.' );
			return;
		}
		loadAgentStatus();
		loadLatestReport();
		loadAuditHistory();
		loadRecentActions();
		initRequestAuditButton();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
</script>
