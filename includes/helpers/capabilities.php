<?php
/**
 * Custom capabilities helper functions.
 *
 * Registers and removes WP-Claw capabilities on WordPress roles.
 * Provides a wrapper for current_user_can() with access-denied logging.
 *
 * Call wp_claw_add_capabilities() on plugin activation.
 * Call wp_claw_remove_capabilities() on plugin uninstall (not deactivation).
 *
 * @package    WPClaw
 * @subpackage WPClaw/helpers
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add WP-Claw capabilities to WordPress roles.
 *
 * Adds the following capabilities:
 *
 * Administrator:
 *   - wp_claw_manage_agents       (view + configure agent team)
 *   - wp_claw_approve_proposals   (approve or reject agent proposals)
 *   - wp_claw_view_dashboard      (view the main agent dashboard)
 *   - wp_claw_manage_settings     (change plugin settings + API key)
 *   - wp_claw_manage_modules      (enable/disable modules)
 *   - wp_claw_view_analytics      (view analytics and reports)
 *   - wp_claw_manage_chat         (configure the chat widget + knowledge base)
 *
 * Editor:
 *   - wp_claw_view_dashboard
 *   - wp_claw_view_analytics
 *
 * This function is idempotent — safe to call multiple times.
 *
 * @since 1.0.0
 *
 * @return void
 */
function wp_claw_add_capabilities(): void {
	$administrator_caps = [
		'wp_claw_manage_agents',
		'wp_claw_approve_proposals',
		'wp_claw_view_dashboard',
		'wp_claw_manage_settings',
		'wp_claw_manage_modules',
		'wp_claw_view_analytics',
		'wp_claw_manage_chat',
	];

	$editor_caps = [
		'wp_claw_view_dashboard',
		'wp_claw_view_analytics',
	];

	$administrator_role = get_role( 'administrator' );
	if ( $administrator_role instanceof WP_Role ) {
		foreach ( $administrator_caps as $cap ) {
			$administrator_role->add_cap( $cap );
		}
	} else {
		wp_claw_log_warning( 'wp_claw_add_capabilities: "administrator" role not found — capabilities not added.' );
	}

	$editor_role = get_role( 'editor' );
	if ( $editor_role instanceof WP_Role ) {
		foreach ( $editor_caps as $cap ) {
			$editor_role->add_cap( $cap );
		}
	} else {
		wp_claw_log_warning( 'wp_claw_add_capabilities: "editor" role not found — editor capabilities not added.' );
	}
}

/**
 * Remove all WP-Claw capabilities from WordPress roles.
 *
 * Removes every capability whose name starts with 'wp_claw_' from
 * the administrator and editor roles. Called on plugin uninstall only —
 * NOT on deactivation — to preserve capabilities during temporary deactivation.
 *
 * @since 1.0.0
 *
 * @return void
 */
function wp_claw_remove_capabilities(): void {
	$all_wp_claw_caps = [
		'wp_claw_manage_agents',
		'wp_claw_approve_proposals',
		'wp_claw_view_dashboard',
		'wp_claw_manage_settings',
		'wp_claw_manage_modules',
		'wp_claw_view_analytics',
		'wp_claw_manage_chat',
	];

	$roles_to_clean = [ 'administrator', 'editor' ];

	foreach ( $roles_to_clean as $role_name ) {
		$role = get_role( $role_name );
		if ( ! ( $role instanceof WP_Role ) ) {
			continue;
		}
		foreach ( $all_wp_claw_caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

/**
 * Check whether the current user has a given WP-Claw capability.
 *
 * Thin wrapper around current_user_can() that logs a warning when
 * access is denied. Useful for debugging permission issues in staging
 * and production environments.
 *
 * @since 1.0.0
 *
 * @param string $capability The capability to check, e.g. 'wp_claw_approve_proposals'.
 *
 * @return bool True if the current user has the capability, false otherwise.
 */
function wp_claw_current_user_can( string $capability ): bool {
	if ( current_user_can( $capability ) ) {
		return true;
	}

	$user_id = get_current_user_id();

	wp_claw_log_warning(
		'Access denied.',
		[
			'capability' => $capability,
			'user_id'    => $user_id,
			'user_login' => $user_id > 0 ? get_userdata( $user_id )->user_login ?? 'unknown' : 'not_logged_in',
		]
	);

	return false;
}
