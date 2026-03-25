<?php
/**
 * Modules admin view.
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

// -------------------------------------------------------------------------
// Load active modules from the plugin singleton.
// -------------------------------------------------------------------------
$enabled_modules = \WPClaw\WP_Claw::get_instance()->get_enabled_modules();
$enabled_slugs   = (array) get_option( 'wp_claw_enabled_modules', array() );

// Determine the active tab — default to the first enabled module, or 'none'.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
$active_tab = isset( $_GET['module'] ) ? sanitize_key( $_GET['module'] ) : '';

if ( '' === $active_tab && ! empty( $enabled_slugs ) ) {
	$active_tab = sanitize_key( reset( $enabled_slugs ) );
}

// Build the ordered list of all known modules (enabled + available = in $enabled_modules;
// enabled + unavailable = in $enabled_slugs but not in $enabled_modules).
$all_known_modules = array(
	'seo'         => array(
		'label' => __( 'SEO', 'claw-agent' ),
		'agent' => 'Scribe',
	),
	'security'    => array(
		'label' => __( 'Security', 'claw-agent' ),
		'agent' => 'Sentinel',
	),
	'content'     => array(
		'label' => __( 'Content', 'claw-agent' ),
		'agent' => 'Scribe',
	),
	'crm'         => array(
		'label' => __( 'CRM & Leads', 'claw-agent' ),
		'agent' => 'Commerce',
	),
	'commerce'    => array(
		'label' => __( 'Commerce', 'claw-agent' ),
		'agent' => 'Commerce',
	),
	'performance' => array(
		'label' => __( 'Performance', 'claw-agent' ),
		'agent' => 'Analyst',
	),
	'forms'       => array(
		'label' => __( 'Forms', 'claw-agent' ),
		'agent' => 'Architect',
	),
	'analytics'   => array(
		'label' => __( 'Analytics', 'claw-agent' ),
		'agent' => 'Analyst',
	),
	'backup'      => array(
		'label' => __( 'Backup', 'claw-agent' ),
		'agent' => 'Sentinel',
	),
	'social'      => array(
		'label' => __( 'Social Media', 'claw-agent' ),
		'agent' => 'Scribe',
	),
	'chat'        => array(
		'label' => __( 'Chat Widget', 'claw-agent' ),
		'agent' => 'Concierge',
	),
);

// Only show tabs for modules that are enabled in settings (even if unavailable).
$display_modules = array();
foreach ( $enabled_slugs as $slug ) {
	$slug = sanitize_key( $slug );
	if ( isset( $all_known_modules[ $slug ] ) ) {
		$display_modules[ $slug ] = $all_known_modules[ $slug ];
	}
}

// -------------------------------------------------------------------------
// Field type renderer helper.
// -------------------------------------------------------------------------
/**
 * Render a single settings field definition as an HTML form control.
 *
 * Supported types: text, textarea, checkbox, select.
 * All output is escaped. The option value is read from wp_options.
 *
 * @param array $field Field definition array from Module_Base::get_settings_fields().
 * @return void
 */
$wp_claw_render_module_field = function ( array $field ) {
	$id      = isset( $field['id'] ) ? sanitize_key( (string) $field['id'] ) : '';
	$type    = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
	$title   = isset( $field['title'] ) ? (string) $field['title'] : '';
	$desc    = isset( $field['desc'] ) ? (string) $field['desc'] : ( isset( $field['description'] ) ? (string) $field['description'] : '' );
	$default = isset( $field['default'] ) ? $field['default'] : '';
	$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

	if ( '' === $id ) {
		return;
	}

	$current = get_option( $id, $default );
	?>
	<tr>
		<td>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></label>
		</td>
		<td>
			<?php if ( 'checkbox' === $type ) : ?>

				<label for="<?php echo esc_attr( $id ); ?>" class="wpc-toggle-switch">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $id ); ?>"
						value="1"
						<?php checked( (bool) $current ); ?>
					>
					<span class="wpc-toggle-switch__slider"></span>
				</label>
				<?php if ( '' !== $desc ) : ?>
				<span class="wpc-kpi-label"><?php echo esc_html( $desc ); ?></span>
				<?php endif; ?>

			<?php elseif ( 'textarea' === $type ) : ?>

				<textarea
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					rows="4"
				><?php echo esc_textarea( (string) $current ); ?></textarea>
				<?php if ( '' !== $desc ) : ?>
				<p class="wpc-kpi-label"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'select' === $type ) : ?>

				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>">
					<?php foreach ( $options as $val => $label ) : ?>
					<option
						value="<?php echo esc_attr( (string) $val ); ?>"
						<?php selected( (string) $current, (string) $val ); ?>
					>
						<?php echo esc_html( (string) $label ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $desc ) : ?>
				<p class="wpc-kpi-label"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>

			<?php else : ?>

				<input
					type="text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					value="<?php echo esc_attr( (string) $current ); ?>"
				>
				<?php if ( '' !== $desc ) : ?>
				<p class="wpc-kpi-label"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>

			<?php endif; ?>
		</td>
	</tr>
	<?php
};
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<?php if ( empty( $display_modules ) ) : ?>
	<!-- No modules enabled -->
	<div class="wpc-empty-state">
		<p>
			<?php
			printf(
				/* translators: %s: Link to settings page */
				esc_html__( 'No modules are enabled. %s to enable modules.', 'claw-agent' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '" class="wpc-btn wpc-btn--primary">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
			);
			?>
		</p>
	</div>

	<?php else : ?>

	<!-- Module Grid Overview -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Module Overview', 'claw-agent' ); ?></h2>

		<div class="wpc-module-grid">
			<?php foreach ( $display_modules as $slug => $mod_info ) : ?>
				<?php
				$module       = isset( $enabled_modules[ $slug ] ) ? $enabled_modules[ $slug ] : null;
				$is_available = ( null !== $module );
				$module_name  = $is_available ? $module->get_name() : $mod_info['label'];
				$module_agent = $is_available ? $module->get_agent() : $mod_info['agent'];
				$is_active    = ( $active_tab === $slug );
				?>
			<a
				href="<?php echo esc_url( add_query_arg( array( 'page' => 'wp-claw-modules', 'module' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
				class="wpc-module-card <?php echo esc_attr( $is_active ? 'wpc-module-card--active' : '' ); ?>"
			>
				<header>
					<strong><?php echo esc_html( $module_name ); ?></strong>
					<?php if ( $is_available ) : ?>
						<span class="wpc-status-dot wpc-status-dot--green" title="<?php esc_attr_e( 'Active', 'claw-agent' ); ?>"></span>
					<?php else : ?>
						<span class="wpc-status-dot wpc-status-dot--red" title="<?php esc_attr_e( 'Unavailable', 'claw-agent' ); ?>"></span>
					<?php endif; ?>
				</header>
				<p class="wpc-kpi-label">
					<?php
					printf(
						/* translators: %s: agent name */
						esc_html__( 'Managed by %s', 'claw-agent' ),
						esc_html( ucfirst( $module_agent ) )
					);
					?>
				</p>
			</a>
			<?php endforeach; ?>
		</div>
	</section>

	<!-- Active Module Detail -->
	<?php foreach ( $display_modules as $slug => $mod_info ) : ?>
		<?php if ( $active_tab !== $slug ) {
			continue;
		} ?>
		<?php
		$module          = isset( $enabled_modules[ $slug ] ) ? $enabled_modules[ $slug ] : null;
		$is_available    = ( null !== $module );
		$module_name     = $is_available ? $module->get_name() : $mod_info['label'];
		$module_agent    = $is_available ? $module->get_agent() : $mod_info['agent'];
		$settings_fields = $is_available ? $module->get_settings_fields() : array();
		?>

	<section class="wpc-card" id="wp-claw-module-panel-<?php echo esc_attr( $slug ); ?>">
		<h2 class="wpc-section-heading"><?php echo esc_html( $module_name ); ?></h2>

		<?php if ( ! $is_available ) : ?>
		<div class="wpc-connection-banner wpc-connection-banner--disconnected">
			<span class="wpc-status-dot wpc-status-dot--yellow"></span>
			<span>
				<?php
				esc_html_e( 'This module is enabled in settings but is not available on this WordPress installation.', 'claw-agent' );
				if ( 'commerce' === $slug ) {
					echo ' ';
					esc_html_e( 'The Commerce module requires WooCommerce to be installed and active.', 'claw-agent' );
				}
				?>
			</span>
		</div>
		<?php else : ?>

		<table class="wpc-agent-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Status', 'claw-agent' ); ?></td>
					<td>
						<span class="wpc-badge wpc-badge--active">
							<?php esc_html_e( 'Active', 'claw-agent' ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Managed by', 'claw-agent' ); ?></td>
					<td>
						<span class="wpc-badge wpc-badge--active">
							<?php echo esc_html( ucfirst( $module_agent ) ); ?>
						</span>
						<?php esc_html_e( 'agent', 'claw-agent' ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php endif; ?>

		<!-- Module-specific settings fields -->
		<?php if ( $is_available && ! empty( $settings_fields ) ) : ?>

		<h3 class="wpc-section-heading"><?php esc_html_e( 'Settings', 'claw-agent' ); ?></h3>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_claw_module_' . $slug . '_settings' ); ?>
			<?php wp_nonce_field( 'wp_claw_module_settings_' . $slug, 'wp_claw_module_nonce_' . $slug ); ?>

			<table class="wpc-agent-table">
				<tbody>
					<?php foreach ( $settings_fields as $field ) : ?>
						<?php
						if ( ! is_array( $field ) ) {
							continue;
						}
						$wp_claw_render_module_field( $field );
						?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Module Settings', 'claw-agent' ) ); ?>
		</form>

		<?php elseif ( $is_available && empty( $settings_fields ) ) : ?>

		<p class="wpc-kpi-label">
			<?php esc_html_e( 'This module has no configurable settings. It runs automatically according to its schedule.', 'claw-agent' ); ?>
		</p>

		<?php endif; ?>

		<!-- Allowed actions reference -->
		<?php if ( $is_available && null !== $module ) : ?>
			<?php $allowed_actions = $module->get_allowed_actions(); ?>
			<?php if ( ! empty( $allowed_actions ) ) : ?>
		<h3 class="wpc-section-heading"><?php esc_html_e( 'Allowed Agent Actions', 'claw-agent' ); ?></h3>
		<p class="wpc-kpi-label">
				<?php esc_html_e( 'These are the WordPress actions the agent is permitted to execute on your site. Agents cannot perform any action outside this list.', 'claw-agent' ); ?>
		</p>
		<div class="wpc-tier-badges">
				<?php foreach ( $allowed_actions as $action_name ) : ?>
			<span class="wpc-badge wpc-badge--idle"><code><?php echo esc_html( sanitize_text_field( (string) $action_name ) ); ?></code></span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<?php endif; ?>

	</section>

	<?php endforeach; ?>

	<?php endif; ?>

</div>
