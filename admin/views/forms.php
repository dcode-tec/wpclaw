<?php
/**
 * Forms management admin view — agent-first layout centred on Thomas (The Architect).
 *
 * Layout:
 *   1. Module disabled check
 *   2. Agent status bar — Thomas, Architect, form count, submissions today, Create New Form
 *   3. KPI cards — Active Forms, Total Submissions, Top Form
 *   4. Forms list — PHP-rendered table with expandable submission rows
 *   5. Recent submissions — JS-loaded from /api/activity
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

// Get forms module state.
$plugin       = \WPClaw\WP_Claw::get_instance();
$forms_module = $plugin->get_module( 'forms' );

// If module is disabled, show enable notice and bail.
if ( null === $forms_module ) : ?>
<div class="wpc-admin-wrap">
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<?php
		printf(
			/* translators: %s: Link to settings page */
			esc_html__( 'The Forms module is not enabled. %s to activate it.', 'claw-agent' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
		);
		?>
	</div>
</div>
	<?php
	return;
endif;

$forms_state = $forms_module->get_state();

// Extract values safely with defaults.
$form_count        = isset( $forms_state['form_count'] ) ? (int) $forms_state['form_count'] : 0;
$submission_count  = isset( $forms_state['submission_count'] ) ? (int) $forms_state['submission_count'] : 0;
$forms_list        = isset( $forms_state['forms'] ) && is_array( $forms_state['forms'] ) ? $forms_state['forms'] : array();

// Submissions today — derive from state if available, default to 0.
$submissions_today = isset( $forms_state['submissions_today'] ) ? (int) $forms_state['submissions_today'] : 0;

// Count active forms.
$active_form_count = 0;
foreach ( $forms_list as $form ) {
	if ( 'active' === ( $form['status'] ?? '' ) ) {
		$active_form_count++;
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
			<?php echo wp_claw_agent_avatar( 'Thomas', 36 ); ?>
			<div>
				<strong><?php esc_html_e( 'Thomas — The Architect', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active" id="wpc-agent-health">
					<?php esc_html_e( 'Building', 'claw-agent' ); ?>
				</span>
				<br>
				<span class="wpc-kpi-label">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of forms */
							_n( '%d form', '%d forms', $form_count, 'claw-agent' ),
							$form_count
						)
					);
					?>
				</span>
				<span class="wpc-kpi-label" style="margin-left:12px;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of submissions today */
							_n( '%d submission today', '%d submissions today', $submissions_today, 'claw-agent' ),
							$submissions_today
						)
					);
					?>
				</span>
			</div>
		</div>
		<button
			class="wpc-btn wpc-btn--primary"
			type="button"
			data-agent-action="forms_create_form"
			data-task-key="forms-create"
		>
			<?php esc_html_e( 'Create New Form', 'claw-agent' ); ?>
		</button>
	</section>

	<!-- ================================================================
		2. KPI CARDS — PHP-rendered from module get_state()
		================================================================ -->
	<section class="wpc-kpi-grid" style="grid-template-columns: repeat(3, 1fr);margin-bottom:20px;">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $active_form_count ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Active Forms', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $submission_count ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Total Submissions', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value" style="font-size:1rem;font-weight:600;">
				<?php esc_html_e( 'Contact Us', 'claw-agent' ); ?>
			</span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Top Form', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- ================================================================
		3. FORMS LIST
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Forms', 'claw-agent' ); ?></h2>
		</div>

		<?php if ( empty( $forms_list ) ) : ?>
			<p class="wpc-empty-state">
				<?php esc_html_e( 'Thomas can create custom forms. Ask via Command Center.', 'claw-agent' ); ?>
			</p>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Form Name', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Submissions', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last Submission', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $forms_list as $form ) :
						$form_id          = isset( $form['id'] ) ? (int) $form['id'] : 0;
						$form_name        = isset( $form['name'] ) ? sanitize_text_field( (string) $form['name'] ) : '';
						$form_submissions = isset( $form['submission_count'] ) ? (int) $form['submission_count'] : 0;
						$form_last_sub    = isset( $form['last_submission'] ) ? sanitize_text_field( (string) $form['last_submission'] ) : '';
						$form_status      = isset( $form['status'] ) ? sanitize_key( (string) $form['status'] ) : 'idle';
						$is_active        = 'active' === $form_status;
					?>
					<tr>
						<td><?php echo esc_html( $form_name ?: "\u{2014}" ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $form_submissions ) ); ?></td>
						<td>
							<?php
							if ( $form_last_sub ) {
								echo esc_html( wp_date( 'M j, H:i', strtotime( $form_last_sub ) ) );
							} else {
								echo esc_html( "\u{2014}" );
							}
							?>
						</td>
						<td>
							<span class="wpc-badge wpc-badge--<?php echo esc_attr( $is_active ? 'active' : 'idle' ); ?>">
								<?php echo esc_html( $is_active ? __( 'Active', 'claw-agent' ) : __( 'Idle', 'claw-agent' ) ); ?>
							</span>
						</td>
						<td>
							<?php if ( $form_id > 0 ) : ?>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--ghost wpc-expand-toggle"
							>
								<?php esc_html_e( 'View Submissions', 'claw-agent' ); ?>
							</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $form_id > 0 ) : ?>
					<tr class="wpc-expandable-row">
						<td colspan="5">
							<div class="wpc-expandable-row__content">
								<?php
								$sub_list = isset( $form['recent_submissions'] ) && is_array( $form['recent_submissions'] )
									? $form['recent_submissions']
									: array();
								if ( empty( $sub_list ) ) :
								?>
									<p style="margin:0;color:#6b7280;font-size:0.8125rem;">
										<?php esc_html_e( 'No submissions for this form yet.', 'claw-agent' ); ?>
									</p>
								<?php else : ?>
									<?php foreach ( $sub_list as $sub ) :
										$sub_fields = isset( $sub['fields'] ) && is_array( $sub['fields'] ) ? $sub['fields'] : array();
										$sub_time   = isset( $sub['submitted_at'] ) ? sanitize_text_field( (string) $sub['submitted_at'] ) : '';
									?>
									<div style="border-bottom:1px solid #e5e7eb;padding:8px 0;margin-bottom:8px;">
										<?php if ( $sub_time ) : ?>
										<p style="margin:0 0 6px;font-size:0.75rem;color:#9ca3af;">
											<?php echo esc_html( wp_date( 'M j, Y H:i', strtotime( $sub_time ) ) ); ?>
										</p>
										<?php endif; ?>
										<?php if ( ! empty( $sub_fields ) ) : ?>
										<dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:2px 12px;">
											<?php foreach ( $sub_fields as $field_key => $field_value ) : ?>
											<dt style="font-weight:600;color:#374151;font-size:0.8125rem;padding:2px 0;">
												<?php echo esc_html( $field_key ); ?>
											</dt>
											<dd style="margin:0;color:#4b5563;font-size:0.8125rem;padding:2px 0;">
												<?php echo esc_html( is_array( $field_value ) ? implode( ', ', $field_value ) : (string) $field_value ); ?>
											</dd>
											<?php endforeach; ?>
										</dl>
										<?php endif; ?>
									</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- ================================================================
		4. RECENT SUBMISSIONS — JS-loaded from /api/activity
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:30px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Recent Submissions', 'claw-agent' ); ?></h2>
		<div id="wpc-recent-submissions" data-agent="karim" data-module="forms">
			<p class="wpc-empty-state"><?php esc_html_e( 'Form submissions will appear here.', 'claw-agent' ); ?></p>
		</div>
	</section>

</div><!-- .wpc-admin-wrap -->

<script>
( function() {
	'use strict';

	/**
	 * Build a text node safely.
	 *
	 * @param {string} str
	 * @return {Text}
	 */
	function t( str ) {
		return document.createTextNode( String( str ) );
	}

	/**
	 * Create an element with optional className and optional textContent.
	 *
	 * @param {string} tag
	 * @param {string} [cls]
	 * @param {string} [text]
	 * @return {HTMLElement}
	 */
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined ) {
			node.appendChild( t( text ) );
		}
		return node;
	}

	/**
	 * Passthrough i18n stub — real translations provided via wp_localize_script.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function i18n( str ) {
		if ( typeof wpClawData !== 'undefined' && wpClawData.i18n && wpClawData.i18n[ str ] ) {
			return wpClawData.i18n[ str ];
		}
		return str;
	}

	/**
	 * Format an ISO / Unix timestamp for display.
	 *
	 * @param {string|number} ts
	 * @return {string}
	 */
	function formatDate( ts ) {
		var d = new Date( isNaN( ts ) ? ts : parseInt( ts, 10 ) * 1000 );
		if ( isNaN( d.getTime() ) ) {
			return String( ts );
		}
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } )
			+ ', ' + d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
	}

	/**
	 * Build a safe <dl> element from a plain-object of field key-value pairs.
	 *
	 * @param {Object} fields
	 * @return {HTMLElement}
	 */
	function buildFieldList( fields ) {
		var dl = document.createElement( 'dl' );
		dl.style.cssText = 'margin:0;display:grid;grid-template-columns:auto 1fr;gap:2px 12px;';
		Object.keys( fields ).forEach( function( key ) {
			var val = fields[ key ];
			if ( Array.isArray( val ) ) {
				val = val.join( ', ' );
			}
			var dt = el( 'dt' );
			dt.style.cssText = 'font-weight:600;color:#374151;font-size:0.8125rem;padding:2px 0;';
			dt.appendChild( t( key ) );

			var dd = el( 'dd' );
			dd.style.cssText = 'margin:0;color:#4b5563;font-size:0.8125rem;padding:2px 0;';
			dd.appendChild( t( String( val ) ) );

			dl.appendChild( dt );
			dl.appendChild( dd );
		} );
		return dl;
	}

	/**
	 * Load recent form submissions via the activity API and render them
	 * using safe DOM APIs (no innerHTML on dynamic content).
	 */
	function loadRecentSubmissions() {
		var container = document.getElementById( 'wpc-recent-submissions' );
		if ( ! container ) {
			return;
		}

		var apiBase = ( typeof wpClawData !== 'undefined' && wpClawData.apiBase )
			? wpClawData.apiBase
			: '/api';

		fetch( apiBase + '/activity?agent=karim&module=forms' )
			.then( function( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Network response was not ok' );
				}
				return response.json();
			} )
			.then( function( data ) {
				var items = Array.isArray( data ) ? data : ( data.items || [] );
				if ( ! items.length ) {
					return; // Keep the empty-state message.
				}

				var frag  = document.createDocumentFragment();
				var table = document.createElement( 'table' );
				table.className = 'wpc-detail-table';

				// Table head.
				var thead = document.createElement( 'thead' );
				var hrow  = document.createElement( 'tr' );
				[ i18n( 'Time' ), i18n( 'Form' ), i18n( 'Details' ) ].forEach( function( label ) {
					var th = el( 'th', '', label );
					th.setAttribute( 'scope', 'col' );
					hrow.appendChild( th );
				} );
				thead.appendChild( hrow );
				table.appendChild( thead );

				// Table body.
				var tbody = document.createElement( 'tbody' );

				items.forEach( function( item ) {
					var subTime  = item.time || item.timestamp || item.created_at || '';
					var formName = item.form_name || item.form || '';
					var fields   = ( item.fields && typeof item.fields === 'object' ) ? item.fields
						: ( item.payload && typeof item.payload === 'object' ) ? item.payload : {};

					// Data row.
					var row = document.createElement( 'tr' );
					var tdTime = el( 'td', '', subTime ? formatDate( subTime ) : '\u2014' );
					var tdForm = el( 'td', '', formName || '\u2014' );
					var tdDetail = el( 'td' );

					var fieldKeys = Object.keys( fields );
					if ( fieldKeys.length ) {
						var toggleBtn = el( 'button', 'wpc-btn wpc-btn--sm wpc-btn--ghost wpc-expand-toggle',
							i18n( 'View Submissions' ) );
						toggleBtn.setAttribute( 'type', 'button' );
						tdDetail.appendChild( toggleBtn );
					} else {
						tdDetail.appendChild( t( '\u2014' ) );
					}

					row.appendChild( tdTime );
					row.appendChild( tdForm );
					row.appendChild( tdDetail );
					tbody.appendChild( row );

					// Expandable detail row.
					if ( fieldKeys.length ) {
						var expandRow = document.createElement( 'tr' );
						expandRow.className = 'wpc-expandable-row';

						var expandCell = document.createElement( 'td' );
						expandCell.setAttribute( 'colspan', '3' );

						var contentWrap = el( 'div', 'wpc-expandable-row__content' );
						contentWrap.appendChild( buildFieldList( fields ) );
						expandCell.appendChild( contentWrap );
						expandRow.appendChild( expandCell );
						tbody.appendChild( expandRow );
					}
				} );

				table.appendChild( tbody );
				frag.appendChild( table );

				// Replace the loading placeholder with the built table.
				while ( container.firstChild ) {
					container.removeChild( container.firstChild );
				}
				container.appendChild( frag );
			} )
			.catch( function() {
				// On network/parse error, keep the empty-state placeholder.
			} );
	}

	// Boot on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', loadRecentSubmissions );
	} else {
		loadRecentSubmissions();
	}
}() );
</script>
