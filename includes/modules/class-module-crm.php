<?php
/**
 * CRM & Leads module.
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
 * CRM & Leads module.
 *
 * Captures leads from WordPress form plugins, stores them in the local
 * task log, and exposes scoring and retrieval actions for the Commerce
 * agent. Integrates with WPForms, Gravity Forms, and Contact Form 7.
 *
 * @since 1.0.0
 */
class Module_CRM extends Module_Base {

	// -------------------------------------------------------------------------
	// Module contract
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'crm';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'CRM & Leads', 'claw-agent' );
	}

	/**
	 * Return the Klawty agent responsible for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'commerce';
	}

	/**
	 * Return the allowlisted agent actions for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'capture_lead',
			'update_lead_status',
			'score_lead',
			'create_followup_task',
			'get_leads',
		);
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Dispatch an inbound agent action to the appropriate handler.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error Result array on success, WP_Error on failure.
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'capture_lead':
				return $this->handle_capture_lead( $params );

			case 'update_lead_status':
				return $this->handle_update_lead_status( $params );

			case 'score_lead':
				return $this->handle_score_lead( $params );

			case 'create_followup_task':
				return $this->handle_create_followup_task( $params );

			case 'get_leads':
				return $this->handle_get_leads( $params );

			default:
				return new \WP_Error(
					'wp_claw_unknown_action',
					/* translators: %s: action name */
					sprintf( __( 'Unknown CRM action: %s', 'claw-agent' ), esc_html( $action ) ),
					array( 'status' => 400 )
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Capture and store a new lead in the task log.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Lead parameters.
	 *
	 *     @type string $name   Lead full name.
	 *     @type string $email  Lead email address.
	 *     @type string $source Lead origin (e.g. 'contact-form', 'woocommerce').
	 *     @type string $notes  Additional notes about the lead.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_capture_lead( array $params ) {
		global $wpdb;

		$name   = sanitize_text_field( $params['name'] ?? '' );
		$email  = sanitize_email( $params['email'] ?? '' );
		$source = sanitize_text_field( $params['source'] ?? 'unknown' );
		$notes  = sanitize_textarea_field( $params['notes'] ?? '' );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error(
				'wp_claw_invalid_email',
				__( 'A valid email address is required to capture a lead.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$task_id = 'crm-lead-' . wp_generate_uuid4();
		$details = wp_json_encode(
			array(
				'name'   => $name,
				'email'  => $email,
				'source' => $source,
				'notes'  => $notes,
				'score'  => 0,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			array(
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'capture_lead',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Failed to store lead in the database.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'task_id' => $task_id,
			'message' => __( 'Lead captured successfully.', 'claw-agent' ),
		);
	}

	/**
	 * Update the status of an existing lead task.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Status update parameters.
	 *
	 *     @type string $task_id The task ID of the lead to update.
	 *     @type string $status  New status value.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_update_lead_status( array $params ) {
		global $wpdb;

		$task_id = sanitize_text_field( $params['task_id'] ?? '' );
		$status  = sanitize_text_field( $params['status'] ?? '' );

		if ( empty( $task_id ) ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'task_id is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$allowed_statuses = array( 'pending', 'contacted', 'qualified', 'proposal', 'won', 'lost' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return new \WP_Error(
				'wp_claw_invalid_status',
				/* translators: %s: comma-separated list of valid statuses */
				sprintf( __( 'Invalid status. Allowed values: %s', 'claw-agent' ), implode( ', ', $allowed_statuses ) ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional UPDATE into WP-Claw custom table; no caching needed for write.
		$updated = $wpdb->update(
			$wpdb->prefix . 'wp_claw_tasks',
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'task_id' => $task_id,
				'module'  => $this->get_slug(),
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Failed to update lead status.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'task_id' => $task_id,
			'status'  => $status,
		);
	}

	/**
	 * Update the lead score for a lead task.
	 *
	 * Score is clamped to the range 1–100.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Score parameters.
	 *
	 *     @type string $task_id The task ID of the lead to score.
	 *     @type int    $score   Numeric score between 1 and 100.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_score_lead( array $params ) {
		global $wpdb;

		$task_id = sanitize_text_field( $params['task_id'] ?? '' );
		$score   = min( 100, max( 1, absint( $params['score'] ?? 0 ) ) );

		if ( empty( $task_id ) ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'task_id is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		// Retrieve existing details to merge the score in.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional single-row SELECT from WP-Claw custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT details FROM {$wpdb->prefix}wp_claw_tasks WHERE task_id = %s AND module = %s",
				$task_id,
				$this->get_slug()
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return new \WP_Error(
				'wp_claw_not_found',
				__( 'Lead not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		$details          = json_decode( $row['details'], true );
		$details          = is_array( $details ) ? $details : array();
		$details['score'] = $score;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional UPDATE into WP-Claw custom table; no caching needed for write.
		$updated = $wpdb->update(
			$wpdb->prefix . 'wp_claw_tasks',
			array(
				'details'    => wp_json_encode( $details ),
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'task_id' => $task_id,
				'module'  => $this->get_slug(),
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Failed to update lead score.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'task_id' => $task_id,
			'score'   => $score,
		);
	}

	/**
	 * Create a follow-up task for a lead.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Follow-up task parameters.
	 *
	 *     @type string $lead_task_id Task ID of the original lead.
	 *     @type string $due_date     ISO-8601 date for follow-up (e.g. '2026-04-01').
	 *     @type string $notes        Follow-up instructions or message.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_create_followup_task( array $params ) {
		global $wpdb;

		$lead_task_id = sanitize_text_field( $params['lead_task_id'] ?? '' );
		$due_date     = sanitize_text_field( $params['due_date'] ?? '' );
		$notes        = sanitize_textarea_field( $params['notes'] ?? '' );

		if ( empty( $lead_task_id ) ) {
			return new \WP_Error(
				'wp_claw_missing_param',
				__( 'lead_task_id is required.', 'claw-agent' ),
				array( 'status' => 400 )
			);
		}

		$task_id = 'crm-followup-' . wp_generate_uuid4();
		$details = wp_json_encode(
			array(
				'lead_task_id' => $lead_task_id,
				'due_date'     => $due_date,
				'notes'        => $notes,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wp_claw_tasks',
			array(
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => 'create_followup_task',
				'status'     => 'pending',
				'details'    => $details,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Failed to create follow-up task.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'success' => true,
			'task_id' => $task_id,
			'message' => __( 'Follow-up task created.', 'claw-agent' ),
		);
	}

	/**
	 * Retrieve leads from the task log.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param array $params {
	 *     Query parameters.
	 *
	 *     @type string $status Filter by status (optional).
	 *     @type int    $limit  Maximum number of results (default 20, max 100).
	 * }
	 *
	 * @return array
	 */
	private function handle_get_leads( array $params ) {
		global $wpdb;

		$status = sanitize_text_field( $params['status'] ?? '' );
		$limit  = min( 100, absint( $params['limit'] ?? 20 ) );
		$limit  = max( 1, $limit );

		if ( ! empty( $status ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional filtered SELECT from WP-Claw custom table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT task_id, agent, action, status, details, created_at, updated_at
					 FROM {$wpdb->prefix}wp_claw_tasks
					 WHERE module = %s AND status = %s AND action = 'capture_lead'
					 ORDER BY created_at DESC
					 LIMIT %d",
					$this->get_slug(),
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional SELECT from WP-Claw custom table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT task_id, agent, action, status, details, created_at, updated_at
					 FROM {$wpdb->prefix}wp_claw_tasks
					 WHERE module = %s AND action = 'capture_lead'
					 ORDER BY created_at DESC
					 LIMIT %d",
					$this->get_slug(),
					$limit
				),
				ARRAY_A
			);
		}

		$leads = array();
		foreach ( $rows as $row ) {
			$details        = json_decode( $row['details'], true );
			$row['details'] = is_array( $details ) ? $details : array();
			$leads[]        = $row;
		}

		return array(
			'success' => true,
			'leads'   => $leads,
			'count'   => count( $leads ),
		);
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register WordPress hooks for form plugin integrations.
	 *
	 * Listens for submission events from WPForms, Gravity Forms, and
	 * Contact Form 7, then queues a lead capture task for each.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// WPForms — fires after a form is processed successfully.
		add_action( 'wpforms_process_complete', array( $this, 'handle_wpforms_submission' ), 10, 4 );

		// Gravity Forms — fires after a successful submission.
		add_action( 'gform_after_submission', array( $this, 'handle_gform_submission' ), 10, 2 );

		// Contact Form 7 — fires after the mail is successfully sent.
		add_action( 'wpcf7_mail_sent', array( $this, 'handle_cf7_submission' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Form integration callbacks
	// -------------------------------------------------------------------------

	/**
	 * Handle a WPForms submission and queue a lead capture task.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $fields     Processed form fields.
	 * @param array  $entry      Entry data.
	 * @param array  $form_data  Form configuration.
	 * @param int    $entry_id   Entry ID.
	 *
	 * @return void
	 */
	public function handle_wpforms_submission( $fields, $entry, $form_data, $entry_id ): void {
		$name  = '';
		$email = '';

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '';
				$value = isset( $field['value'] ) ? sanitize_text_field( $field['value'] ) : '';

				if ( 'email' === $type ) {
					$email = sanitize_email( $value );
				} elseif ( in_array( $type, array( 'name', 'text' ), true ) && empty( $name ) ) {
					$name = $value;
				}
			}
		}

		if ( empty( $email ) ) {
			return;
		}

		$form_title = isset( $form_data['settings']['form_title'] )
			? sanitize_text_field( $form_data['settings']['form_title'] )
			: 'WPForms';

		$this->handle_capture_lead(
			array(
				'name'   => $name,
				'email'  => $email,
				'source' => 'wpforms:' . $form_title,
				'notes'  => 'Submitted via WPForms entry #' . absint( $entry_id ),
			)
		);
	}

	/**
	 * Handle a Gravity Forms submission and queue a lead capture task.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Entry data from Gravity Forms.
	 * @param array $form  Form configuration from Gravity Forms.
	 *
	 * @return void
	 */
	public function handle_gform_submission( $entry, $form ): void {
		$name  = '';
		$email = '';

		if ( is_array( $form ) && isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				if ( ! is_object( $field ) && ! is_array( $field ) ) {
					continue;
				}

				$field_id   = is_object( $field ) ? $field->id : ( $field['id'] ?? '' );
				$field_type = is_object( $field ) ? $field->type : ( $field['type'] ?? '' );

				$value = isset( $entry[ $field_id ] ) ? sanitize_text_field( $entry[ $field_id ] ) : '';

				if ( 'email' === $field_type ) {
					$email = sanitize_email( $value );
				} elseif ( in_array( $field_type, array( 'name', 'text' ), true ) && empty( $name ) ) {
					$name = $value;
				}
			}
		}

		if ( empty( $email ) ) {
			return;
		}

		$form_title = is_array( $form ) && isset( $form['title'] )
			? sanitize_text_field( $form['title'] )
			: 'Gravity Forms';

		$this->handle_capture_lead(
			array(
				'name'   => $name,
				'email'  => $email,
				'source' => 'gravityforms:' . $form_title,
				'notes'  => 'Submitted via Gravity Forms entry #' . absint( $entry['id'] ?? 0 ),
			)
		);
	}

	/**
	 * Handle a Contact Form 7 submission and queue a lead capture task.
	 *
	 * @since 1.0.0
	 *
	 * @param \WPCF7_ContactForm $contact_form The CF7 form object.
	 *
	 * @return void
	 */
	public function handle_cf7_submission( $contact_form ): void {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'get_posted_data' ) ) {
			return;
		}

		$posted = $contact_form->get_posted_data();

		if ( ! is_array( $posted ) ) {
			return;
		}

		$email = sanitize_email( $posted['your-email'] ?? '' );
		$name  = sanitize_text_field( $posted['your-name'] ?? '' );

		if ( empty( $email ) ) {
			return;
		}

		$form_title = method_exists( $contact_form, 'title' )
			? sanitize_text_field( $contact_form->title() )
			: 'Contact Form 7';

		$this->handle_capture_lead(
			array(
				'name'   => $name,
				'email'  => $email,
				'source' => 'cf7:' . $form_title,
				'notes'  => 'Submitted via Contact Form 7',
			)
		);
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/**
	 * Return the current CRM state for state sync.
	 *
	 * Provides lead counts grouped by status so the Commerce agent
	 * has an up-to-date picture of the pipeline.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional aggregate SELECT from WP-Claw custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as count
				 FROM {$wpdb->prefix}wp_claw_tasks
				 WHERE module = %s AND action = 'capture_lead'
				 GROUP BY status",
				$this->get_slug()
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ sanitize_key( $row['status'] ) ] = absint( $row['count'] );
		}

		return array(
			'module'       => $this->get_slug(),
			'lead_counts'  => $counts,
			'total_leads'  => array_sum( $counts ),
			'generated_at' => current_time( 'c' ),
		);
	}
}
