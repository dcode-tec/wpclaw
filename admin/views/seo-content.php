<?php
/**
 * SEO & Content dashboard admin view.
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

// Data gathering.
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

$active_ab_tests    = isset( $seo['active_ab_tests'] ) ? (int) $seo['active_ab_tests'] : 0;
$stale_count        = isset( $seo['stale_content_count'] ) ? (int) $seo['stale_content_count'] : 0;
$broken_link_count  = isset( $seo['broken_link_count'] ) ? (int) $seo['broken_link_count'] : 0;
$content_issues     = $stale_count + $broken_link_count;
$last_sitemap_flush = isset( $seo['last_sitemap_flush'] ) ? sanitize_text_field( (string) $seo['last_sitemap_flush'] ) : '';
$has_custom_robots  = ! empty( $seo['robots_txt_custom_rules'] );

// A/B Tests — filter.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter parameter.
$ab_filter   = isset( $_GET['ab_filter'] ) ? sanitize_key( $_GET['ab_filter'] ) : 'running';
$ab_table    = $wpdb->prefix . 'wp_claw_ab_tests';
$valid_tabs  = array( 'running', 'completed', 'all' );
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

// Stale content.
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

// Broken links.
$broken_links = get_transient( 'wp_claw_broken_links' );

// Helpers.
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

	<!-- SEO Coverage KPI Row -->
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
						$post_title   = get_the_title( (int) $test->post_id );
						$edit_link    = get_edit_post_link( (int) $test->post_id );
						$imp_a        = (int) $test->impressions_a;
						$imp_b        = (int) $test->impressions_b;
						$clicks_a     = (int) $test->clicks_a;
						$clicks_b     = (int) $test->clicks_b;
						$ctr_a        = $wp_claw_ctr( $clicks_a, $imp_a );
						$ctr_b        = $wp_claw_ctr( $clicks_b, $imp_b );
						$total_imp    = $imp_a + $imp_b;
						$test_status  = sanitize_key( (string) $test->status );
						$test_winner  = sanitize_key( (string) $test->winner );
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
				<p><?php esc_html_e( 'No stale content detected. All posts updated within 12 months.', 'claw-agent' ); ?></p>
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

	<!-- Sitemap & Robots -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Sitemap & Robots', 'claw-agent' ); ?></h2>
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

	<section class="wpc-card" style="margin-top: 20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( "Lina's SEO &amp; Content Reports", 'claw-agent' ); ?></h3>
		<div id="wpc-module-reports" data-agent="lina" data-limit="5">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading reports...', 'claw-agent' ); ?></p>
		</div>
	</section>

</div>
