<?php
/**
 * Security dashboard admin view.
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

// Get security module state.
$plugin          = \WPClaw\WP_Claw::get_instance();
$security_module = $plugin->get_module( 'security' );

// If module is disabled, show enable notice and bail.
if ( null === $security_module ) : ?>
<div class="wpc-admin-wrap">
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<?php
		printf(
			/* translators: %s: Link to settings page */
			esc_html__( 'The Security module is not enabled. %s to activate it.', 'claw-agent' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
		);
		?>
	</div>
</div>
	<?php
	return;
endif;

$sec = $security_module->get_state();

// Extract values safely with defaults.
$failed_logins      = isset( $sec['failed_logins_24h'] ) ? (int) $sec['failed_logins_24h'] : 0;
$blocked_ips        = isset( $sec['blocked_ips_count'] ) ? (int) $sec['blocked_ips_count'] : 0;
$last_scan_time     = isset( $sec['last_scan_time'] ) ? sanitize_text_field( (string) $sec['last_scan_time'] ) : '';
$integrity_status   = isset( $sec['file_integrity_status'] ) ? sanitize_key( (string) $sec['file_integrity_status'] ) : 'scan_pending';
$quarantined_count  = isset( $sec['quarantined_file_count'] ) ? (int) $sec['quarantined_file_count'] : 0;
$ssl_valid          = isset( $sec['ssl_valid'] ) ? (bool) $sec['ssl_valid'] : false;
$ssl_days           = isset( $sec['ssl_days_remaining'] ) ? (int) $sec['ssl_days_remaining'] : null;
$last_malware_scan  = isset( $sec['last_malware_scan'] ) ? sanitize_text_field( (string) $sec['last_malware_scan'] ) : '';
$headers_active     = isset( $sec['security_headers_active'] ) ? (bool) $sec['security_headers_active'] : false;

// -------------------------------------------------------------------------
// Database queries.
// -------------------------------------------------------------------------

$file_hashes_table = $wpdb->prefix . 'wp_claw_file_hashes';

// Non-clean files for the integrity monitor (limit 50).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$flagged_files = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT file_path, scope, status, checked_at FROM %i WHERE status != %s ORDER BY checked_at DESC LIMIT %d",
		$file_hashes_table,
		'clean',
		50
	)
);

// Quarantined files.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$quarantined_files = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT file_path, file_hash, checked_at FROM %i WHERE status = %s ORDER BY checked_at DESC LIMIT %d",
		$file_hashes_table,
		'quarantined',
		50
	)
);

// Malware scan results from transient.
$malware_results = get_transient( 'wp_claw_malware_results' );
if ( ! is_array( $malware_results ) ) {
	$malware_results = array();
}

// Deployed security headers.
$deployed_headers = get_option( 'wp_claw_security_headers_deployed', array() );
if ( ! is_array( $deployed_headers ) ) {
	$deployed_headers = array();
}

// Standard headers to check.
$standard_headers = array(
	'X-Content-Type-Options',
	'X-Frame-Options',
	'X-XSS-Protection',
	'Strict-Transport-Security',
	'Content-Security-Policy',
	'Referrer-Policy',
	'Permissions-Policy',
);

// -------------------------------------------------------------------------
// Helper: integrity status to badge mapping.
// -------------------------------------------------------------------------
$integrity_badge_map = array(
	'clean'           => array( 'class' => 'done',    'dot' => 'green',  'label' => __( 'Clean', 'claw-agent' ) ),
	'issues_detected' => array( 'class' => 'failed',  'dot' => 'red',    'label' => __( 'Issues Detected', 'claw-agent' ) ),
	'scan_pending'    => array( 'class' => 'pending', 'dot' => 'yellow', 'label' => __( 'Scan Pending', 'claw-agent' ) ),
);
$integrity_badge = isset( $integrity_badge_map[ $integrity_status ] )
	? $integrity_badge_map[ $integrity_status ]
	: $integrity_badge_map['scan_pending'];

// SSL days color.
$ssl_dot_color = 'red';
if ( $ssl_valid && null !== $ssl_days ) {
	if ( $ssl_days > 30 ) {
		$ssl_dot_color = 'green';
	} elseif ( $ssl_days >= 14 ) {
		$ssl_dot_color = 'yellow';
	}
}
?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- KPI Cards -->
	<section class="wpc-kpi-grid wpc-kpi-grid--6">

		<article class="wpc-kpi-card">
			<span class="wpc-badge wpc-badge--<?php echo esc_attr( $integrity_badge['class'] ); ?>">
				<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $integrity_badge['dot'] ); ?>"></span>
				<?php echo esc_html( $integrity_badge['label'] ); ?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'File Integrity', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $quarantined_count > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $quarantined_count ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Quarantined Files', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $blocked_ips ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Blocked IPs', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $failed_logins > 10 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $failed_logins ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Failed Logins (24h)', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-badge wpc-badge--<?php echo esc_attr( $ssl_valid ? 'done' : 'failed' ); ?>">
				<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $ssl_valid ? $ssl_dot_color : 'red' ); ?>"></span>
				<?php echo esc_html( $ssl_valid ? __( 'Valid', 'claw-agent' ) : __( 'Invalid', 'claw-agent' ) ); ?>
			</span>
			<?php if ( $ssl_valid && null !== $ssl_days ) : ?>
				<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $ssl_days ) ); ?></span>
				<span class="wpc-kpi-label"><?php esc_html_e( 'SSL Days Remaining', 'claw-agent' ); ?></span>
			<?php else : ?>
				<span class="wpc-kpi-label"><?php esc_html_e( 'SSL Certificate', 'claw-agent' ); ?></span>
			<?php endif; ?>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-badge wpc-badge--<?php echo esc_attr( $headers_active ? 'active' : 'error' ); ?>">
				<?php echo esc_html( $headers_active ? __( 'Active', 'claw-agent' ) : __( 'Inactive', 'claw-agent' ) ); ?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Security Headers', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- File Integrity Monitor -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading">
			<?php esc_html_e( 'File Integrity Monitor', 'claw-agent' ); ?>
			<span class="wpc-badge wpc-badge--<?php echo esc_attr( $integrity_badge['class'] ); ?>">
				<?php echo esc_html( $integrity_badge['label'] ); ?>
			</span>
		</h2>

		<?php if ( '' !== $last_scan_time ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Last scan: %s ago', 'claw-agent' ),
					esc_html( human_time_diff( strtotime( $last_scan_time ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<p>
			<button type="button" class="wpc-btn wpc-btn--primary wpc-scan-button wpc-admin-run-scan" data-scan-type="file_integrity">
				<?php esc_html_e( 'Run Scan', 'claw-agent' ); ?>
				<span class="wpc-spinner"></span>
			</button>
		</p>

		<?php if ( empty( $flagged_files ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'All monitored files are clean.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'File Path', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Scope', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last Checked', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $flagged_files as $file ) : ?>
						<?php
						$file_status     = sanitize_key( (string) $file->status );
						$status_badge    = 'pending';
						if ( 'quarantined' === $file_status || 'suspicious' === $file_status ) {
							$status_badge = 'failed';
						} elseif ( 'modified' === $file_status ) {
							$status_badge = 'pending';
						}
						?>
						<tr>
							<td><code><?php echo esc_html( sanitize_text_field( (string) $file->file_path ) ); ?></code></td>
							<td><?php echo esc_html( ucfirst( sanitize_text_field( (string) $file->scope ) ) ); ?></td>
							<td>
								<span class="wpc-badge wpc-badge--<?php echo esc_attr( $status_badge ); ?>">
									<?php echo esc_html( ucfirst( $file_status ) ); ?>
								</span>
							</td>
							<td>
								<?php if ( ! empty( $file->checked_at ) ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference */
											__( '%s ago', 'claw-agent' ),
											human_time_diff( strtotime( $file->checked_at ) )
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

	<!-- Quarantined Files -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Quarantined Files', 'claw-agent' ); ?></h2>

		<?php if ( empty( $quarantined_files ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No quarantined files.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'File Path', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Original Hash', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Quarantined At', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $quarantined_files as $qfile ) : ?>
						<tr>
							<td><code><?php echo esc_html( sanitize_text_field( (string) $qfile->file_path ) ); ?></code></td>
							<td><code><?php echo esc_html( substr( sanitize_text_field( (string) $qfile->file_hash ), 0, 12 ) ); ?></code></td>
							<td>
								<?php if ( ! empty( $qfile->checked_at ) ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference */
											__( '%s ago', 'claw-agent' ),
											human_time_diff( strtotime( $qfile->checked_at ) )
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

	<!-- Malware Scan Results -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Malware Scan Results', 'claw-agent' ); ?></h2>

		<?php if ( '' !== $last_malware_scan ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( 'Last malware scan: %s ago', 'claw-agent' ),
					esc_html( human_time_diff( strtotime( $last_malware_scan ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<p>
			<button type="button" class="wpc-btn wpc-btn--primary wpc-scan-button wpc-admin-run-scan" data-scan-type="malware">
				<?php esc_html_e( 'Run Malware Scan', 'claw-agent' ); ?>
				<span class="wpc-spinner"></span>
			</button>
		</p>

		<?php if ( empty( $malware_results ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No threats detected in last scan.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'File', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pattern Matched', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Severity', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $malware_results as $threat ) : ?>
						<?php
						if ( ! is_array( $threat ) ) {
							continue;
						}
						$threat_file     = isset( $threat['file'] ) ? sanitize_text_field( (string) $threat['file'] ) : '';
						$threat_pattern  = isset( $threat['pattern'] ) ? sanitize_text_field( (string) $threat['pattern'] ) : '';
						$threat_severity = isset( $threat['severity'] ) ? sanitize_key( (string) $threat['severity'] ) : 'medium';
						$severity_class  = in_array( $threat_severity, array( 'critical', 'high', 'medium' ), true )
							? $threat_severity
							: 'medium';
						?>
						<tr>
							<td><code><?php echo esc_html( $threat_file ); ?></code></td>
							<td><?php echo esc_html( $threat_pattern ); ?></td>
							<td>
								<span class="wpc-severity--<?php echo esc_attr( $severity_class ); ?>">
									<?php echo esc_html( ucfirst( $threat_severity ) ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- SSL Certificate -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'SSL Certificate', 'claw-agent' ); ?></h2>

		<table class="wpc-detail-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
					<td>
						<span class="wpc-badge wpc-badge--<?php echo esc_attr( $ssl_valid ? 'done' : 'failed' ); ?>">
							<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $ssl_valid ? 'green' : 'red' ); ?>"></span>
							<?php echo esc_html( $ssl_valid ? __( 'Valid', 'claw-agent' ) : __( 'Invalid', 'claw-agent' ) ); ?>
						</span>
					</td>
				</tr>
				<?php if ( null !== $ssl_days ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Days Remaining', 'claw-agent' ); ?></th>
					<td>
						<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $ssl_dot_color ); ?>"></span>
						<?php echo esc_html( number_format_i18n( $ssl_days ) ); ?>
						<?php
						if ( $ssl_days < 14 ) :
							?>
							<span class="wpc-badge wpc-badge--failed"><?php esc_html_e( 'Expiring soon', 'claw-agent' ); ?></span>
							<?php
						elseif ( $ssl_days <= 30 ) :
							?>
							<span class="wpc-badge wpc-badge--pending"><?php esc_html_e( 'Renew soon', 'claw-agent' ); ?></span>
							<?php
						endif;
						?>
					</td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</section>

	<!-- Security Headers -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Security Headers', 'claw-agent' ); ?></h2>

		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Header', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $standard_headers as $header_name ) : ?>
					<?php $is_deployed = in_array( $header_name, $deployed_headers, true ); ?>
					<tr>
						<td><code><?php echo esc_html( $header_name ); ?></code></td>
						<td>
							<?php if ( $is_deployed ) : ?>
								<span class="wpc-badge wpc-badge--done"><?php esc_html_e( 'Deployed', 'claw-agent' ); ?></span>
							<?php else : ?>
								<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'Not set', 'claw-agent' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<!-- Agent Reports -->
	<section class="wpc-card" style="margin-top: 20px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( "Bastien's Security Reports", 'claw-agent' ); ?></h3>
		<div id="wpc-module-reports" data-agent="bastien" data-limit="5">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading reports...', 'claw-agent' ); ?></p>
		</div>
	</section>

</div>
