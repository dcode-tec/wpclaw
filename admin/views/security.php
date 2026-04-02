<?php
/**
 * Security dashboard admin view — agent-first layout centred on Bastien (The Sentinel).
 *
 * Layout:
 *   1. Agent status bar — health badge, last/next scan, request scan button
 *   2. Latest security report (hero) — JS-loaded from /api/reports
 *   3. KPI cards — PHP-rendered from module get_state() (live WP data)
 *   4. Scan history timeline — JS-loaded from /api/reports
 *   5. Recent actions — JS-loaded from /api/activity
 *   6. Detailed scan data — PHP-rendered tables (file hashes, quarantine, SSL, headers)
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
$failed_logins     = isset( $sec['failed_logins_24h'] ) ? (int) $sec['failed_logins_24h'] : 0;
$blocked_ips       = isset( $sec['blocked_ips_count'] ) ? (int) $sec['blocked_ips_count'] : 0;
$last_scan_time    = isset( $sec['last_scan_time'] ) ? sanitize_text_field( (string) $sec['last_scan_time'] ) : '';
$integrity_status  = isset( $sec['file_integrity_status'] ) ? sanitize_key( (string) $sec['file_integrity_status'] ) : 'scan_pending';
$quarantined_count = isset( $sec['quarantined_file_count'] ) ? (int) $sec['quarantined_file_count'] : 0;
$ssl_valid         = isset( $sec['ssl_valid'] ) ? (bool) $sec['ssl_valid'] : false;
$ssl_days          = isset( $sec['ssl_days_remaining'] ) ? (int) $sec['ssl_days_remaining'] : null;
$last_malware_scan = isset( $sec['last_malware_scan'] ) ? sanitize_text_field( (string) $sec['last_malware_scan'] ) : '';
$headers_active    = isset( $sec['security_headers_active'] ) ? (bool) $sec['security_headers_active'] : false;

// -------------------------------------------------------------------------
// Database queries (KPI + detail tables).
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

// Malware scan results from transient (used for KPI count only).
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
// Helper: integrity status → badge mapping.
// -------------------------------------------------------------------------
$integrity_badge_map = array(
	'clean'           => array( 'class' => 'done',    'dot' => 'green',  'label' => __( 'Clean', 'claw-agent' ) ),
	'issues_detected' => array( 'class' => 'failed',  'dot' => 'red',    'label' => __( 'Issues Detected', 'claw-agent' ) ),
	'scan_pending'    => array( 'class' => 'pending', 'dot' => 'yellow', 'label' => __( 'Scan Pending', 'claw-agent' ) ),
);
$integrity_badge = isset( $integrity_badge_map[ $integrity_status ] )
	? $integrity_badge_map[ $integrity_status ]
	: $integrity_badge_map['scan_pending'];

// SSL days colour.
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

	<!-- ================================================================
		1. AGENT STATUS BAR
		================================================================ -->
	<section class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<span style="font-size:1.5rem;" aria-hidden="true">🛡️</span>
			<div>
				<strong><?php esc_html_e( 'Bastien — The Sentinel', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active" id="wpc-agent-health">
					<?php esc_html_e( 'Monitoring', 'claw-agent' ); ?>
				</span>
				<br>
				<span class="wpc-kpi-label" id="wpc-last-scan"><?php esc_html_e( 'Last scan: checking\xe2\x80\xa6', 'claw-agent' ); ?></span>
				<span class="wpc-kpi-label" id="wpc-next-scan" style="margin-left:12px;"></span>
			</div>
		</div>
		<button
			class="wpc-btn wpc-btn--primary wpc-request-scan"
			type="button"
			data-agent="sentinel"
			data-title="Manual security scan"
			data-description="Admin requested full security scan. Run security_run_file_integrity_check, security_get_login_attempts, security_check_ssl_certificate, security_scan_malware_patterns. Write a detailed report with severity levels for each finding."
		>
			<?php esc_html_e( 'Request Security Scan', 'claw-agent' ); ?>
		</button>
	</section>

	<!-- ================================================================
		2. LATEST SECURITY REPORT (hero)
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Latest Security Report', 'claw-agent' ); ?></h2>
		<div id="wpc-latest-report" data-agent="sentinel">
			<p class="wpc-empty-state"><?php esc_html_e( "Loading Bastien\xe2\x80\x99s latest security report\xe2\x80\xa6", 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ================================================================
		3. KPI CARDS — PHP-rendered from module get_state() (live WP data)
		================================================================ -->
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

	<!-- ================================================================
		4. SCAN HISTORY — JS-loaded timeline
		================================================================ -->
	<section class="wpc-card" style="margin-top:20px;margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Scan History', 'claw-agent' ); ?></h2>
		<div id="wpc-scan-history" data-agent="sentinel" data-limit="10">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading scan history\xe2\x80\xa6', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ================================================================
		5. RECENT ACTIONS — JS-loaded activity from Bastien
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:30px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Recent Actions', 'claw-agent' ); ?></h2>
		<div id="wpc-agent-actions" data-agent="sentinel">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading recent actions\xe2\x80\xa6', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ================================================================
		6. DETAILED SCAN DATA — PHP-rendered tables (de-emphasised)
		================================================================ -->
	<h2 class="wpc-section-heading" style="margin-top:10px;"><?php esc_html_e( 'Detailed Scan Data', 'claw-agent' ); ?></h2>

	<!-- File integrity details -->
	<?php if ( ! empty( $flagged_files ) ) : ?>
	<section class="wpc-card" style="margin-bottom:16px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'File Integrity Issues', 'claw-agent' ); ?></h3>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Scope', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Checked', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $flagged_files as $file ) : ?>
					<tr>
						<td><code><?php echo esc_html( $file->file_path ); ?></code></td>
						<td><?php echo esc_html( $file->scope ); ?></td>
						<td>
							<span class="wpc-badge wpc-badge--<?php echo esc_attr( 'quarantined' === $file->status ? 'failed' : 'pending' ); ?>">
								<?php echo esc_html( $file->status ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $file->checked_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>

	<!-- Quarantined files -->
	<?php if ( ! empty( $quarantined_files ) ) : ?>
	<section class="wpc-card" style="margin-bottom:16px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Quarantined Files', 'claw-agent' ); ?></h3>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Hash', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quarantined At', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $quarantined_files as $qfile ) : ?>
					<tr>
						<td><code><?php echo esc_html( $qfile->file_path ); ?></code></td>
						<td><code><?php echo esc_html( substr( $qfile->file_hash, 0, 16 ) . '…' ); ?></code></td>
						<td><?php echo esc_html( $qfile->checked_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>

	<!-- SSL Certificate -->
	<section class="wpc-card" style="margin-bottom:16px;">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'SSL Certificate', 'claw-agent' ); ?></h3>
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
						<?php if ( $ssl_days < 14 ) : ?>
							<span class="wpc-badge wpc-badge--failed"><?php esc_html_e( 'Expiring soon', 'claw-agent' ); ?></span>
						<?php elseif ( $ssl_days <= 30 ) : ?>
							<span class="wpc-badge wpc-badge--pending"><?php esc_html_e( 'Renew soon', 'claw-agent' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</section>

	<!-- Security Headers -->
	<section class="wpc-card">
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Security Headers', 'claw-agent' ); ?></h3>
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

</div>

<script>
( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Return a human-readable "X ago" string from an ISO / RFC date string.
	 *
	 * @param {string} dateStr
	 * @return {string}
	 */
	function timeAgo( dateStr ) {
		if ( ! dateStr ) { return ''; }
		var diff = Math.floor( ( Date.now() - new Date( dateStr ).getTime() ) / 1000 );
		if ( diff < 60 )    { return diff + 's ago'; }
		if ( diff < 3600 )  { return Math.floor( diff / 60 ) + 'm ago'; }
		if ( diff < 86400 ) { return Math.floor( diff / 3600 ) + 'h ago'; }
		return Math.floor( diff / 86400 ) + 'd ago';
	}

	/**
	 * Safely create an element with optional text content and class name.
	 *
	 * @param {string} tag
	 * @param {string} [text]
	 * @param {string} [className]
	 * @return {HTMLElement}
	 */
	function el( tag, text, className ) {
		var node = document.createElement( tag );
		if ( text )      { node.textContent = text; }
		if ( className ) { node.className = className; }
		return node;
	}

	/**
	 * Empty a container element.
	 *
	 * @param {Element} container
	 */
	function empty( container ) {
		while ( container.firstChild ) { container.removeChild( container.firstChild ); }
	}

	/**
	 * Fetch wrapper — resolves with {ok, data} and never rejects to the caller.
	 *
	 * @param {string} url
	 * @param {Object} [options]
	 * @return {Promise<{ok: boolean, data: *}>}
	 */
	function apiFetch( url, options ) {
		var headers = {
			'Content-Type': 'application/json',
			'X-WP-Nonce': ( window.wpClaw && window.wpClaw.nonce ) || ( window.wpApiSettings && window.wpApiSettings.nonce ) || ''
		};
		return fetch( url, Object.assign( { headers: headers, credentials: 'same-origin' }, options || {} ) )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.catch( function () {
				return { ok: false, data: null };
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Config                                                               */
	/* ------------------------------------------------------------------ */

	var restUrl = ( window.wpClaw && window.wpClaw.restUrl ) ? window.wpClaw.restUrl : '';

	/* ------------------------------------------------------------------ */
	/* 1. Agent status bar — health badge + last active time               */
	/* ------------------------------------------------------------------ */

	function loadAgentStatus() {
		if ( ! restUrl ) { return; }

		apiFetch( restUrl + 'agents' ).then( function ( res ) {
			if ( ! res.ok || ! res.data ) { return; }

			var agents   = Array.isArray( res.data ) ? res.data : ( res.data.agents || [] );
			var sentinel = null;

			for ( var i = 0; i < agents.length; i++ ) {
				var a    = agents[ i ];
				var name = ( a.name || '' ).toLowerCase();
				if ( name.indexOf( 'sentinel' ) !== -1 || name.indexOf( 'bastien' ) !== -1 ) {
					sentinel = a;
					break;
				}
			}

			if ( ! sentinel ) { return; }

			var healthEl = document.getElementById( 'wpc-agent-health' );
			if ( healthEl ) {
				var status = sentinel.status || 'monitoring';
				healthEl.textContent = status.charAt( 0 ).toUpperCase() + status.slice( 1 );
				// Swap badge colour based on status.
				healthEl.className = 'wpc-badge wpc-badge--' + ( 'in_progress' === status ? 'active' : ( 'failed' === status ? 'failed' : 'done' ) );
			}

			var lastEl = document.getElementById( 'wpc-last-scan' );
			if ( lastEl ) {
				var lastActive = sentinel.last_active || sentinel.updated_at || '';
				lastEl.textContent = lastActive
					? 'Last active: ' + timeAgo( lastActive )
					: 'No recent activity';
			}

			var nextEl = document.getElementById( 'wpc-next-scan' );
			if ( nextEl && sentinel.next_run ) {
				var secsUntil = Math.floor( ( new Date( sentinel.next_run ).getTime() - Date.now() ) / 1000 );
				if ( secsUntil > 0 ) {
					nextEl.textContent = 'Next run: in ' + ( secsUntil < 60 ? secsUntil + 's' : Math.ceil( secsUntil / 60 ) + 'm' );
				}
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* 2. Latest security report (hero)                                    */
	/* ------------------------------------------------------------------ */

	function renderLatestReport( container, report ) {
		empty( container );

		if ( ! report ) {
			container.appendChild( el( 'p', 'No security reports yet \u2014 Bastien will post findings after his next scan.', 'wpc-empty-state' ) );
			return;
		}

		// Meta row: timestamp + status badge.
		var meta           = document.createElement( 'div' );
		meta.style.cssText = 'display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;';

		if ( report.created_at || report.completed_at ) {
			meta.appendChild( el( 'span', timeAgo( report.completed_at || report.created_at ), 'wpc-kpi-label' ) );
		}
		if ( report.status ) {
			var statusStr = report.status;
			meta.appendChild( el( 'span', statusStr, 'wpc-badge wpc-badge--' + ( 'done' === statusStr ? 'done' : 'pending' ) ) );
		}
		container.appendChild( meta );

		// Title.
		if ( report.title ) {
			container.appendChild( el( 'h4', report.title ) );
		}

		// Summary.
		if ( report.summary ) {
			container.appendChild( el( 'p', report.summary ) );
		}

		// Full evidence / result.
		var content = report.evidence || report.result || report.content || report.description || '';
		if ( content ) {
			var pre            = document.createElement( 'pre' );
			pre.textContent    = content;
			pre.style.cssText  = 'background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:12px;overflow:auto;font-size:0.8125rem;white-space:pre-wrap;word-break:break-word;max-height:360px;margin-top:8px;';
			container.appendChild( pre );
		}
	}

	function loadLatestReport() {
		if ( ! restUrl ) { return; }
		var container = document.getElementById( 'wpc-latest-report' );
		if ( ! container ) { return; }

		apiFetch( restUrl + 'reports?agent=sentinel&limit=1' ).then( function ( res ) {
			var list = ( res.ok && res.data )
				? ( Array.isArray( res.data ) ? res.data : ( res.data.reports || [] ) )
				: [];
			renderLatestReport( container, list[0] || null );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* 4. Scan history timeline                                            */
	/* ------------------------------------------------------------------ */

	function loadScanHistory() {
		if ( ! restUrl ) { return; }
		var container = document.getElementById( 'wpc-scan-history' );
		if ( ! container ) { return; }

		var agent = container.getAttribute( 'data-agent' ) || 'sentinel';
		var limit = container.getAttribute( 'data-limit' ) || '10';

		apiFetch( restUrl + 'reports?agent=' + encodeURIComponent( agent ) + '&since=30d&limit=' + limit )
			.then( function ( res ) {
				var reports = ( res.ok && res.data )
					? ( Array.isArray( res.data ) ? res.data : ( res.data.reports || [] ) )
					: [];

				empty( container );

				if ( ! reports.length ) {
					container.appendChild( el( 'p', 'No scans in the last 30 days.', 'wpc-empty-state' ) );
					return;
				}

				var table       = document.createElement( 'table' );
				table.className = 'wpc-detail-table';

				// Header.
				var thead = document.createElement( 'thead' );
				var hrow  = document.createElement( 'tr' );
				[ 'When', 'Title', 'Status' ].forEach( function ( h ) {
					var th   = document.createElement( 'th' );
					th.scope = 'col';
					th.textContent = h;
					hrow.appendChild( th );
				} );
				thead.appendChild( hrow );
				table.appendChild( thead );

				// Rows.
				var tbody = document.createElement( 'tbody' );
				reports.forEach( function ( r ) {
					var row     = document.createElement( 'tr' );
					var tdWhen  = el( 'td', r.completed_at ? timeAgo( r.completed_at ) : ( r.created_at ? timeAgo( r.created_at ) : '\u2014' ) );
					var tdTitle = el( 'td', r.title || '(untitled)' );

					var tdStatus   = document.createElement( 'td' );
					var statusStr  = r.status || 'done';
					tdStatus.appendChild( el( 'span', statusStr, 'wpc-badge wpc-badge--' + ( 'done' === statusStr ? 'done' : 'pending' ) ) );

					row.appendChild( tdWhen );
					row.appendChild( tdTitle );
					row.appendChild( tdStatus );
					tbody.appendChild( row );
				} );
				table.appendChild( tbody );
				container.appendChild( table );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* 5. Recent actions — Bastien's activity feed                         */
	/* ------------------------------------------------------------------ */

	function loadRecentActions() {
		if ( ! restUrl ) { return; }
		var container = document.getElementById( 'wpc-agent-actions' );
		if ( ! container ) { return; }

		var agent = container.getAttribute( 'data-agent' ) || 'sentinel';

		apiFetch( restUrl + 'activity?agent=' + encodeURIComponent( agent ) + '&since=24h&limit=15' )
			.then( function ( res ) {
				var items = ( res.ok && res.data )
					? ( Array.isArray( res.data ) ? res.data : ( res.data.activity || [] ) )
					: [];

				empty( container );

				if ( ! items.length ) {
					container.appendChild( el( 'p', 'No actions in the last 24 hours.', 'wpc-empty-state' ) );
					return;
				}

				var feed           = document.createElement( 'div' );
				feed.className     = 'wpc-activity-feed';

				items.forEach( function ( item ) {
					var row            = document.createElement( 'div' );
					row.style.cssText  = 'display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--wpc-border,#e5e7eb);';

					var left  = document.createElement( 'div' );
					left.style.display = 'flex';
					left.style.alignItems = 'center';
					left.style.gap = '8px';

					var type      = item.type || 'task_completed';
					var badgeMod  = 'task_completed' === type ? 'done' : ( 'task_failed' === type ? 'failed' : 'active' );
					var badgeText = ( item.agent_emoji || '\ud83d\udee1\ufe0f' ) + ' ' + ( item.agent_name || item.agent || 'Bastien' );
					left.appendChild( el( 'span', badgeText, 'wpc-badge wpc-badge--' + badgeMod ) );
					left.appendChild( el( 'span', item.title || '' ) );
					row.appendChild( left );

					row.appendChild( el( 'span', item.timestamp ? timeAgo( item.timestamp ) : '', 'wpc-kpi-label' ) );
					feed.appendChild( row );
				} );

				container.appendChild( feed );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Request Security Scan button (.wpc-request-scan)                   */
	/* ------------------------------------------------------------------ */

	function initRequestScanButton() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.wpc-request-scan' );
			if ( ! btn || ! restUrl ) { return; }

			if ( btn.disabled ) { return; }
			btn.disabled = true;
			btn.textContent = 'Requesting\u2026';

			var agent       = btn.getAttribute( 'data-agent' )       || 'sentinel';
			var title       = btn.getAttribute( 'data-title' )       || 'Manual security scan';
			var description = btn.getAttribute( 'data-description' ) || '';

			apiFetch( restUrl + 'create-task', {
				method: 'POST',
				body: JSON.stringify( {
					agent:       agent,
					title:       title,
					priority:    'high',
					description: description
				} )
			} ).then( function ( res ) {
				if ( res.ok ) {
					btn.textContent = 'Scan requested \u2014 Bastien will process it shortly.';
					// Refresh data after short delay.
					setTimeout( function () {
						loadLatestReport();
						loadScanHistory();
						loadRecentActions();
					}, 3000 );
				} else {
					btn.textContent = 'Request failed \u2014 check agent connection.';
					btn.disabled    = false;
				}
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                 */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		loadAgentStatus();
		loadLatestReport();
		loadScanHistory();
		loadRecentActions();
		initRequestScanButton();
	} );
}() );
</script>
