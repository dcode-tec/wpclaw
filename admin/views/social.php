<?php
/**
 * Social media management admin view — agent-first layout centred on Lina (The Scribe).
 *
 * Layout:
 *   1. Module disabled check
 *   2. Agent status bar — Lina, Scribe, scheduled count, Generate Posts Now button
 *   3. KPI grid (4 cards) — Scheduled, Recent (7d), Platforms, Last Posted
 *   4. Scheduled Posts card — PHP-rendered table with platform badges and expandable rows
 *   5. Posting History — JS-loaded timeline from /api/activity
 *   6. Platform Status card — 3-column grid (LinkedIn, X, Facebook)
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

// Get social module state.
$plugin        = \WPClaw\WP_Claw::get_instance();
$social_module = $plugin->get_module( 'social' );

// If module is disabled, show enable notice and bail.
if ( null === $social_module ) : ?>
<div class="wpc-admin-wrap">
	<div class="wpc-alert-banner wpc-alert-banner--warning">
		<?php
		printf(
			/* translators: %s: Link to settings page */
			esc_html__( 'The Social module is not enabled. %s to activate it.', 'claw-agent' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
		);
		?>
	</div>
</div>
	<?php
	return;
endif;

$social = $social_module->get_state();

// Extract values safely with defaults.
$scheduled_posts = isset( $social['scheduled_posts'] ) ? (int) $social['scheduled_posts'] : 0;
$recent_posts    = isset( $social['recent_posts'] ) ? (int) $social['recent_posts'] : 0;
$last_posted     = isset( $social['last_posted'] ) ? sanitize_text_field( (string) $social['last_posted'] ) : '';
$queue           = isset( $social['queue'] ) && is_array( $social['queue'] ) ? $social['queue'] : array();

// Platform connection status (booleans, defaulting to not connected).
$linkedin_connected = isset( $social['linkedin_connected'] ) ? (bool) $social['linkedin_connected'] : false;
$x_connected        = isset( $social['x_connected'] ) ? (bool) $social['x_connected'] : false;
$facebook_connected = isset( $social['facebook_connected'] ) ? (bool) $social['facebook_connected'] : false;

// -------------------------------------------------------------------------
// Helper: platform → badge CSS modifier.
// -------------------------------------------------------------------------
$platform_badge_map = array(
	'linkedin' => 'linkedin',
	'x'        => 'x',
	'twitter'  => 'x',
	'facebook' => 'facebook',
);
?>
<div class="wpc-admin-wrap">

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<!-- ================================================================
		1. AGENT STATUS BAR
		================================================================ -->
	<section class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<?php echo wp_claw_agent_avatar( 'Lina', 36 ); ?>
			<div>
				<strong><?php esc_html_e( 'Lina — The Scribe', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active" id="wpc-agent-health">
					<?php esc_html_e( 'Publishing', 'claw-agent' ); ?>
				</span>
				<br>
				<span class="wpc-kpi-label">
					<?php
					printf(
						/* translators: %d: number of posts scheduled */
						esc_html( _n( '%d post scheduled', '%d posts scheduled', $scheduled_posts, 'claw-agent' ) ),
						(int) $scheduled_posts
					);
					?>
				</span>
			</div>
		</div>
		<button
			class="wpc-btn wpc-btn--primary"
			type="button"
			data-agent-action="social_create_post"
			data-task-key="social-generate"
			data-agent="scribe"
			data-title="<?php esc_attr_e( 'Generate social posts', 'claw-agent' ); ?>"
			data-description="<?php esc_attr_e( 'Generate platform-specific social media posts from recent published content. Format for LinkedIn, X, and Facebook. Schedule for optimal engagement times.', 'claw-agent' ); ?>"
		>
			<?php esc_html_e( 'Generate Posts Now', 'claw-agent' ); ?>
		</button>
	</section>

	<!-- ================================================================
		2. KPI GRID — PHP-rendered from module get_state()
		================================================================ -->
	<section class="wpc-kpi-grid wpc-kpi-grid--4">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $scheduled_posts ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Scheduled', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $recent_posts ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Posted (7d)', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php esc_html_e( '3 active', 'claw-agent' ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Platforms', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<?php if ( $last_posted ) : ?>
				<span class="wpc-kpi-value" style="font-size:0.875rem;">
					<?php echo esc_html( wp_date( 'M j, H:i', strtotime( $last_posted ) ) ); ?>
				</span>
			<?php else : ?>
				<span class="wpc-kpi-value" style="color:#9ca3af;"><?php esc_html_e( '—', 'claw-agent' ); ?></span>
			<?php endif; ?>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Last Posted', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- ================================================================
		3. SCHEDULED POSTS — PHP-rendered table
		================================================================ -->
	<section class="wpc-card" style="margin-top:20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Scheduled Posts', 'claw-agent' ); ?></h2>
			<?php if ( ! empty( $queue ) ) : ?>
				<span class="wpc-badge wpc-badge--active">
					<?php
					printf(
						/* translators: %d: queue length */
						esc_html( _n( '%d queued', '%d queued', count( $queue ), 'claw-agent' ) ),
						(int) count( $queue )
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( empty( $queue ) ) : ?>
			<div style="text-align:center;padding:40px 24px;">
				<p class="wpc-empty-state"><?php esc_html_e( 'Lina auto-generates social posts when you publish content.', 'claw-agent' ); ?></p>
				<p style="font-size:0.8125rem;color:#9ca3af;margin-top:4px;">
					<?php esc_html_e( 'Publish a post or click "Generate Posts Now" above to get started.', 'claw-agent' ); ?>
				</p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date / Time', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Platform', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Content Preview', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source Post', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $queue as $item ) :
						$item_id       = isset( $item['id'] ) ? (int) $item['id'] : 0;
						$scheduled_at  = isset( $item['scheduled_at'] ) ? sanitize_text_field( (string) $item['scheduled_at'] ) : '';
						$platform      = isset( $item['platform'] ) ? sanitize_key( (string) $item['platform'] ) : 'unknown';
						$content       = isset( $item['content'] ) ? sanitize_textarea_field( (string) $item['content'] ) : '';
						$source_title  = isset( $item['source_title'] ) ? sanitize_text_field( (string) $item['source_title'] ) : '';
						$source_url    = isset( $item['source_url'] ) ? esc_url( (string) $item['source_url'] ) : '';
						$badge_mod     = isset( $platform_badge_map[ $platform ] ) ? $platform_badge_map[ $platform ] : 'linkedin';
						$preview       = mb_strlen( $content ) > 80 ? mb_substr( $content, 0, 80 ) . '…' : $content;
						$row_id        = 'wpc-social-row-' . $item_id;
						$expand_id     = 'wpc-social-expand-' . $item_id;
					?>
					<tr id="<?php echo esc_attr( $row_id ); ?>">
						<td style="white-space:nowrap;">
							<?php echo esc_html( $scheduled_at ? wp_date( 'M j, H:i', strtotime( $scheduled_at ) ) : "\u{2014}" ); ?>
						</td>
						<td>
							<span class="wpc-platform-badge wpc-platform-badge--<?php echo esc_attr( $badge_mod ); ?>">
								<?php echo esc_html( ucfirst( $platform ) ); ?>
							</span>
						</td>
						<td>
							<span class="wpc-social-preview"
								style="cursor:pointer;"
								aria-expanded="false"
								aria-controls="<?php echo esc_attr( $expand_id ); ?>"
								onclick="
									var el=document.getElementById('<?php echo esc_js( $expand_id ); ?>');
									var visible=el.style.display!=='none';
									el.style.display=visible?'none':'block';
									this.setAttribute('aria-expanded',String(!visible));
								">
								<?php echo esc_html( $preview ); ?>
								<?php if ( mb_strlen( $content ) > 80 ) : ?>
									<span class="wpc-expand-hint" style="font-size:0.75rem;color:#6b7280;margin-left:4px;">
										<?php esc_html_e( '(expand)', 'claw-agent' ); ?>
									</span>
								<?php endif; ?>
							</span>
							<div id="<?php echo esc_attr( $expand_id ); ?>"
								style="display:none;margin-top:8px;padding:10px;background:#f9fafb;border-radius:6px;font-size:0.8125rem;white-space:pre-wrap;">
								<?php echo esc_html( $content ); ?>
							</div>
						</td>
						<td>
							<?php if ( $source_url ) : ?>
								<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"
									style="font-size:0.8125rem;">
									<?php echo esc_html( $source_title ? $source_title : __( 'View Post', 'claw-agent' ) ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $source_title ? $source_title : "\u{2014}" ); ?>
							<?php endif; ?>
						</td>
						<td style="white-space:nowrap;">
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--ghost"
								style="color:#dc2626;font-size:0.75rem;"
								data-agent-action="social_cancel_post"
								data-task-key="social-cancel-<?php echo esc_attr( (string) $item_id ); ?>"
								data-agent="scribe"
								data-title="<?php esc_attr_e( 'Cancel scheduled post', 'claw-agent' ); ?>"
								data-description="<?php echo esc_attr( sprintf( /* translators: %d: post ID */ __( 'Cancel scheduled social post ID %d.', 'claw-agent' ), $item_id ) ); ?>"
							>
								<?php esc_html_e( 'Cancel', 'claw-agent' ); ?>
							</button>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--ghost"
								style="font-size:0.75rem;margin-left:4px;"
								data-agent-action="social_reschedule_post"
								data-task-key="social-reschedule-<?php echo esc_attr( (string) $item_id ); ?>"
								data-agent="scribe"
								data-title="<?php esc_attr_e( 'Reschedule social post', 'claw-agent' ); ?>"
								data-description="<?php echo esc_attr( sprintf( /* translators: %d: post ID */ __( 'Reschedule social post ID %d for optimal engagement time.', 'claw-agent' ), $item_id ) ); ?>"
							>
								<?php esc_html_e( 'Reschedule', 'claw-agent' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- ================================================================
		4. POSTING HISTORY — JS-loaded timeline from /api/activity
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:20px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Posting History', 'claw-agent' ); ?></h2>
		<div
			id="wpc-agent-actions"
			data-agent="lina"
			data-module="social"
			data-limit="20"
		>
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading posting history...', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- ================================================================
		5. PLATFORM STATUS — 3-column grid
		================================================================ -->
	<section class="wpc-card" style="margin-bottom:30px;">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Platform Status', 'claw-agent' ); ?></h2>

		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:12px;">

			<!-- LinkedIn -->
			<div class="wpc-kpi-card" style="text-align:center;padding:20px 16px;">
				<div style="font-size:1.5rem;margin-bottom:8px;" aria-hidden="true">in</div>
				<strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'LinkedIn', 'claw-agent' ); ?></strong>
				<?php if ( $linkedin_connected ) : ?>
					<span class="wpc-badge wpc-badge--active">
						<span class="wpc-status-dot wpc-status-dot--green"></span>
						<?php esc_html_e( 'Connected', 'claw-agent' ); ?>
					</span>
				<?php else : ?>
					<span class="wpc-badge wpc-badge--idle">
						<span class="wpc-status-dot wpc-status-dot--yellow"></span>
						<?php esc_html_e( 'Not Connected', 'claw-agent' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<!-- X (Twitter) -->
			<div class="wpc-kpi-card" style="text-align:center;padding:20px 16px;">
				<div style="font-size:1.5rem;margin-bottom:8px;" aria-hidden="true">𝕏</div>
				<strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'X (Twitter)', 'claw-agent' ); ?></strong>
				<?php if ( $x_connected ) : ?>
					<span class="wpc-badge wpc-badge--active">
						<span class="wpc-status-dot wpc-status-dot--green"></span>
						<?php esc_html_e( 'Connected', 'claw-agent' ); ?>
					</span>
				<?php else : ?>
					<span class="wpc-badge wpc-badge--idle">
						<span class="wpc-status-dot wpc-status-dot--yellow"></span>
						<?php esc_html_e( 'Not Connected', 'claw-agent' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<!-- Facebook -->
			<div class="wpc-kpi-card" style="text-align:center;padding:20px 16px;">
				<div style="font-size:1.5rem;margin-bottom:8px;" aria-hidden="true">f</div>
				<strong style="display:block;margin-bottom:6px;"><?php esc_html_e( 'Facebook', 'claw-agent' ); ?></strong>
				<?php if ( $facebook_connected ) : ?>
					<span class="wpc-badge wpc-badge--active">
						<span class="wpc-status-dot wpc-status-dot--green"></span>
						<?php esc_html_e( 'Connected', 'claw-agent' ); ?>
					</span>
				<?php else : ?>
					<span class="wpc-badge wpc-badge--idle">
						<span class="wpc-status-dot wpc-status-dot--yellow"></span>
						<?php esc_html_e( 'Not Connected', 'claw-agent' ); ?>
					</span>
				<?php endif; ?>
			</div>

		</div>

		<?php if ( ! $linkedin_connected && ! $x_connected && ! $facebook_connected ) : ?>
			<p class="wpc-empty-state" style="margin-top:16px;">
				<?php esc_html_e( 'No platforms connected yet. Configure platform credentials in Settings to enable social posting.', 'claw-agent' ); ?>
			</p>
		<?php endif; ?>
	</section>

</div>
