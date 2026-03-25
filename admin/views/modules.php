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
	'seo'         => __( 'SEO', 'claw-agent' ),
	'security'    => __( 'Security', 'claw-agent' ),
	'content'     => __( 'Content', 'claw-agent' ),
	'crm'         => __( 'CRM & Leads', 'claw-agent' ),
	'commerce'    => __( 'Commerce', 'claw-agent' ),
	'performance' => __( 'Performance', 'claw-agent' ),
	'forms'       => __( 'Forms', 'claw-agent' ),
	'analytics'   => __( 'Analytics', 'claw-agent' ),
	'backup'      => __( 'Backup', 'claw-agent' ),
	'social'      => __( 'Social Media', 'claw-agent' ),
	'chat'        => __( 'Chat Widget', 'claw-agent' ),
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
		<th scope="row">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></label>
		</th>
		<td>
			<?php if ( 'checkbox' === $type ) : ?>

				<label for="<?php echo esc_attr( $id ); ?>">
					<input
						type="checkbox"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $id ); ?>"
						value="1"
						<?php checked( (bool) $current ); ?>
					>
					<?php echo esc_html( $desc ); ?>
				</label>

			<?php elseif ( 'textarea' === $type ) : ?>

				<textarea
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					rows="5"
					class="large-text"
				><?php echo esc_textarea( (string) $current ); ?></textarea>
				<?php if ( '' !== $desc && 'checkbox' !== $type ) : ?>
				<p class="description"><?php echo esc_html( $desc ); ?></p>
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
				<p class="description"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>

			<?php else : /* text (default) */ ?>

				<input
					type="text"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					value="<?php echo esc_attr( (string) $current ); ?>"
					class="regular-text"
				>
				<?php if ( '' !== $desc ) : ?>
				<p class="description"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>

			<?php endif; ?>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap wp-claw-admin-wrap">

	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<?php if ( empty( $display_modules ) ) : ?>
	<!-- ------------------------------------------------------------------ -->
	<!-- No modules enabled yet                                              -->
	<!-- ------------------------------------------------------------------ -->
	<div class="notice notice-info wp-claw-admin-notice">
		<p>
			<?php
			printf(
				/* translators: %s: Link to settings page */
				esc_html__( 'No modules are enabled. %s to enable modules.', 'claw-agent' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-settings' ) ) . '">' . esc_html__( 'Go to Settings', 'claw-agent' ) . '</a>'
			);
			?>
		</p>
	</div>

	<?php else : ?>

	<!-- ------------------------------------------------------------------ -->
	<!-- Tab navigation                                                       -->
	<!-- ------------------------------------------------------------------ -->
	<nav class="nav-tab-wrapper wp-claw-admin-tabs" aria-label="<?php esc_attr_e( 'Modules', 'claw-agent' ); ?>">
		<?php foreach ( $display_modules as $slug => $label ) : ?>
		<a
			href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page'   => 'wp-claw-modules',
						'module' => $slug,
					),
					admin_url( 'admin.php' )
				)
			);
			?>
					"
			class="nav-tab <?php echo esc_attr( $active_tab === $slug ? 'nav-tab-active' : '' ); ?>"
			<?php echo $active_tab === $slug ? 'aria-current="page"' : ''; ?>
		>
			<?php echo esc_html( $label ); ?>
			<?php if ( ! isset( $enabled_modules[ $slug ] ) ) : ?>
				<span class="wp-claw-admin-tab-badge wp-claw-admin-tab-badge--unavailable" title="<?php esc_attr_e( 'Module is enabled but unavailable on this install', 'claw-agent' ); ?>">
					<?php esc_html_e( '!', 'claw-agent' ); ?>
				</span>
			<?php endif; ?>
		</a>
		<?php endforeach; ?>
	</nav><!-- /.wp-claw-admin-tabs -->

	<!-- ------------------------------------------------------------------ -->
	<!-- Tab panels                                                           -->
	<!-- ------------------------------------------------------------------ -->
	<div class="wp-claw-admin-tab-panels">

		<?php foreach ( $display_modules as $slug => $label ) : ?>

		<div
			id="wp-claw-module-panel-<?php echo esc_attr( $slug ); ?>"
			class="wp-claw-admin-tab-panel <?php echo esc_attr( $active_tab === $slug ? 'wp-claw-admin-tab-panel--active' : '' ); ?>"
			<?php echo $active_tab !== $slug ? 'hidden' : ''; ?>
			role="tabpanel"
		>

			<?php
			// Retrieve the live module instance (null when unavailable).
			$module          = isset( $enabled_modules[ $slug ] ) ? $enabled_modules[ $slug ] : null;
			$module_name     = ( null !== $module ) ? $module->get_name() : $label;
			$module_agent    = ( null !== $module ) ? $module->get_agent() : '';
			$is_available    = ( null !== $module );
			$settings_fields = ( null !== $module ) ? $module->get_settings_fields() : array();
			?>

			<h2><?php echo esc_html( $module_name ); ?></h2>

			<?php if ( ! $is_available ) : ?>
			<div class="notice notice-warning inline wp-claw-admin-notice">
				<p>
					<?php
					esc_html_e( 'This module is enabled in settings but is not available on this WordPress installation.', 'claw-agent' );
					if ( 'commerce' === $slug ) {
						echo ' ';
						esc_html_e( 'The Commerce module requires WooCommerce to be installed and active.', 'claw-agent' );
					}
					?>
				</p>
			</div>
			<?php else : ?>

			<table class="form-table wp-claw-admin-module-info" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'claw-agent' ); ?></th>
						<td>
							<span class="wp-claw-admin-status-pill wp-claw-admin-status-ok">
								<?php esc_html_e( 'Active', 'claw-agent' ); ?>
							</span>
						</td>
					</tr>
					<?php if ( '' !== $module_agent ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Managed by', 'claw-agent' ); ?></th>
						<td>
							<span class="wp-claw-admin-agent-badge wp-claw-admin-agent-badge--<?php echo esc_attr( sanitize_key( $module_agent ) ); ?>">
								<?php echo esc_html( ucfirst( $module_agent ) ); ?>
							</span>
							<?php esc_html_e( 'agent', 'claw-agent' ); ?>
						</td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php endif; // is_available ?>

			<!-- Module-specific settings fields -->
			<?php if ( $is_available && ! empty( $settings_fields ) ) : ?>

			<h3><?php esc_html_e( 'Settings', 'claw-agent' ); ?></h3>

			<form method="post" action="options.php">
				<?php settings_fields( 'wp_claw_module_' . $slug . '_settings' ); ?>
				<?php wp_nonce_field( 'wp_claw_module_settings_' . $slug, 'wp_claw_module_nonce_' . $slug ); ?>

				<table class="form-table" role="presentation">
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

			<p class="wp-claw-admin-muted">
				<?php esc_html_e( 'This module has no configurable settings. It runs automatically according to its schedule.', 'claw-agent' ); ?>
			</p>

			<?php endif; ?>

			<!-- Allowed actions reference -->
			<?php if ( $is_available && null !== $module ) : ?>
				<?php $allowed_actions = $module->get_allowed_actions(); ?>
				<?php if ( ! empty( $allowed_actions ) ) : ?>
			<h3><?php esc_html_e( 'Allowed Agent Actions', 'claw-agent' ); ?></h3>
			<p class="description">
					<?php esc_html_e( 'These are the WordPress actions the agent is permitted to execute on your site. Agents cannot perform any action outside this list.', 'claw-agent' ); ?>
			</p>
			<ul class="wp-claw-admin-action-list">
					<?php foreach ( $allowed_actions as $action_name ) : ?>
				<li><code><?php echo esc_html( sanitize_text_field( (string) $action_name ) ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<?php endif; ?>

		</div><!-- /.wp-claw-admin-tab-panel -->

		<?php endforeach; // foreach display_modules ?>

	</div><!-- /.wp-claw-admin-tab-panels -->

	<?php endif; // empty display_modules ?>

</div><!-- /.wrap -->
