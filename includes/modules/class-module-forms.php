<?php
/**
 * Forms module.
 *
 * @package    WPClaw
 * @subpackage WPClaw/modules
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

namespace WPClaw\Modules;

use WPClaw\Module_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Forms module — Architect agent manages custom form creation and submissions.
 *
 * Forms are stored as serialized arrays in the `wp_claw_forms` option,
 * keyed by form ID. Submissions are recorded as tasks in the custom
 * wp_claw_tasks table with module='forms'. Destructive operations
 * (deleting submissions) require proposal approval — a 403 WP_Error is
 * returned so the REST bridge can route the request through the proposal
 * lifecycle instead of executing immediately.
 *
 * @since 1.0.0
 */
class Module_Forms extends Module_Base {

	/**
	 * Option key used to store all form definitions.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const FORMS_OPTION = 'wp_claw_forms';

	// -------------------------------------------------------------------------
	// Module contract implementation
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'forms';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Forms', 'claw-agent' );
	}

	/**
	 * Return the Klawty agent responsible for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'architect';
	}

	/**
	 * Return the allowlisted actions for this module.
	 *
	 * Only actions listed here can be triggered via the REST bridge.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'create_form',
			'get_submissions',
			'update_form',
			'delete_submission',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * Routes to the appropriate internal method based on $action.
	 * Returns a structured array on success or a WP_Error on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'create_form':
				return $this->action_create_form( $params );

			case 'get_submissions':
				return $this->action_get_submissions( $params );

			case 'update_form':
				return $this->action_update_form( $params );

			case 'delete_submission':
				return $this->action_delete_submission( $params );

			default:
				return new \WP_Error(
					'wp_claw_forms_unknown_action',
					/* translators: %s: action name */
					sprintf( esc_html__( 'Unknown forms action: %s', 'claw-agent' ), esc_html( $action ) ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return the current WordPress state for this module.
	 *
	 * Provides a lightweight snapshot: total form count and submission count.
	 * Consumed by the state sync cron to give agents fresh context.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		$forms      = get_option( self::FORMS_OPTION, array() );
		$form_count = is_array( $forms ) ? count( $forms ) : 0;

		// Count submissions stored as tasks with module='forms'.
		$table_name       = $wpdb->prefix . 'wp_claw_tasks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$submission_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE module = %s',
				$table_name,
				'forms'
			)
		);

		return array(
			'form_count'       => $form_count,
			'submission_count' => $submission_count,
		);
	}

	/**
	 * Register WordPress hooks for this module.
	 *
	 * The Forms module has no autonomous hooks — all interactions are
	 * agent-initiated via the REST bridge or triggered by cron.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// No hooks required for this module.
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Create a new form and store it in the wp_claw_forms option.
	 *
	 * Expects $params['form_id'] (string) and $params['definition'] (array).
	 * If form_id is omitted a UUID-style ID is generated from a hash.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_form( array $params ) {
		if ( empty( $params['definition'] ) || ! is_array( $params['definition'] ) ) {
			return new \WP_Error(
				'wp_claw_forms_missing_definition',
				esc_html__( 'Form definition array is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$forms   = get_option( self::FORMS_OPTION, array() );
		$form_id = ! empty( $params['form_id'] )
			? sanitize_key( $params['form_id'] )
			: 'form_' . substr( md5( uniqid( 'wp_claw_form_', true ) ), 0, 8 );

		if ( isset( $forms[ $form_id ] ) ) {
			return new \WP_Error(
				'wp_claw_forms_duplicate_id',
				/* translators: %s: form ID */
				sprintf( esc_html__( 'A form with ID "%s" already exists.', 'claw-agent' ), esc_html( $form_id ) ),
				array( 'status' => 409 )
			);
		}

		$definition               = $this->sanitize_form_definition( $params['definition'] );
		$definition['form_id']    = $form_id;
		$definition['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$definition['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$forms[ $form_id ] = $definition;

		update_option( self::FORMS_OPTION, $forms, false );

		return array(
			'success' => true,
			'form_id' => $form_id,
			'message' => __( 'Form created successfully.', 'claw-agent' ),
		);
	}

	/**
	 * Retrieve submissions for a given form from the tasks table.
	 *
	 * Accepts optional $params['form_id'] to filter by form. Without it,
	 * returns all submissions across all forms. Supports $params['limit']
	 * (int, default 50, max 200) and $params['offset'] (int, default 0).
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_submissions( array $params ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wp_claw_tasks';
		$limit      = min( 200, absint( $params['limit'] ?? 50 ) );
		$offset     = absint( $params['offset'] ?? 0 );

		if ( ! empty( $params['form_id'] ) ) {
			$form_id = sanitize_key( $params['form_id'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE module = %s AND JSON_EXTRACT(details, '$.form_id') = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$table_name,
					'forms',
					$form_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
					$table_name,
					'forms',
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_forms_db_error',
				esc_html__( 'Failed to retrieve submissions from database.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success'     => true,
			'submissions' => $rows,
			'count'       => count( $rows ),
		);
	}

	/**
	 * Update an existing form definition.
	 *
	 * Merges $params['definition'] into the existing form, preserving
	 * form_id and created_at. Returns a WP_Error if the form does not exist.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_form( array $params ) {
		if ( empty( $params['form_id'] ) ) {
			return new \WP_Error(
				'wp_claw_forms_missing_form_id',
				esc_html__( 'form_id parameter is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $params['definition'] ) || ! is_array( $params['definition'] ) ) {
			return new \WP_Error(
				'wp_claw_forms_missing_definition',
				esc_html__( 'Form definition array is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$form_id = sanitize_key( $params['form_id'] );
		$forms   = get_option( self::FORMS_OPTION, array() );

		if ( ! isset( $forms[ $form_id ] ) ) {
			return new \WP_Error(
				'wp_claw_forms_not_found',
				/* translators: %s: form ID */
				sprintf( esc_html__( 'Form "%s" not found.', 'claw-agent' ), esc_html( $form_id ) ),
				array( 'status' => 404 )
			);
		}

		$existing              = $forms[ $form_id ];
		$updated               = array_merge( $existing, $this->sanitize_form_definition( $params['definition'] ) );
		$updated['form_id']    = $form_id;
		$updated['created_at'] = $existing['created_at'] ?? gmdate( 'Y-m-d H:i:s' );
		$updated['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$forms[ $form_id ]     = $updated;

		update_option( self::FORMS_OPTION, $forms, false );

		return array(
			'success' => true,
			'form_id' => $form_id,
			'message' => __( 'Form updated successfully.', 'claw-agent' ),
		);
	}

	/**
	 * Delete a form submission — always blocked; requires proposal approval.
	 *
	 * This action is intentionally gated: the REST bridge will detect the
	 * 403 WP_Error and route the request through the proposal lifecycle
	 * rather than executing directly.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return \WP_Error Always returns a 403 requiring proposal.
	 */
	private function action_delete_submission( array $params ) {
		return new \WP_Error(
			'wp_claw_forms_propose_required',
			esc_html__( 'Deletion requires proposal approval', 'claw-agent' ),
			array( 'status' => 403 )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a form definition array.
	 *
	 * Recursively sanitizes string values. Non-string scalar values
	 * are cast appropriately. Arrays are processed recursively.
	 *
	 * @since 1.0.0
	 *
	 * @param array $definition Raw form definition from agent.
	 *
	 * @return array Sanitized definition.
	 */
	private function sanitize_form_definition( array $definition ): array {
		$sanitized = array();

		foreach ( $definition as $key => $value ) {
			$clean_key = sanitize_key( (string) $key );

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_form_definition( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $clean_key ] = (bool) $value;
			} elseif ( is_int( $value ) ) {
				$sanitized[ $clean_key ] = (int) $value;
			} elseif ( is_float( $value ) ) {
				$sanitized[ $clean_key ] = (float) $value;
			} else {
				$sanitized[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}
}
