<?php
/**
 * Social Media module.
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
 * Social Media module — automates social post generation and scheduling via the Scribe agent.
 *
 * Responsibilities:
 *  - Queue social post generation tasks when a post is published on WordPress.
 *  - Handle inbound agent actions: create, schedule, and list social posts.
 *  - Store social post tasks locally in the wp_claw_tasks table so the admin
 *    can review scheduled posts without querying the Klawty instance.
 *  - Report current state (scheduled/recent post counts) for the state sync cron.
 *
 * Supported platforms: facebook, instagram, linkedin, twitter, pinterest.
 *
 * @since 1.0.0
 */
class Module_Social extends Module_Base {

	/**
	 * Supported social media platforms — enforced as an enum on create.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private static array $allowed_platforms = array(
		'facebook',
		'instagram',
		'linkedin',
		'twitter',
		'pinterest',
	);

	/**
	 * Maximum length (characters) allowed for social post text.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const MAX_TEXT_LENGTH = 2000;

	// -------------------------------------------------------------------------
	// Module_Base contract
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'social';
	}

	/**
	 * Return the human-readable module name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Social Media', 'wp-claw' );
	}

	/**
	 * Return the Klawty agent responsible for social operations.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'scribe';
	}

	/**
	 * Return the actions this module exposes through the REST bridge.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'create_social_post',
			'schedule_post',
			'get_scheduled_posts',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * Routes to the appropriate internal handler. Returns a structured
	 * result array on success, or a WP_Error on failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action name (must be in get_allowed_actions()).
	 * @param array  $params Parameters supplied by the agent.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'create_social_post':
				return $this->handle_create_social_post( $params );

			case 'schedule_post':
				return $this->handle_schedule_post( $params );

			case 'get_scheduled_posts':
				return $this->handle_get_scheduled_posts( $params );

			default:
				return new \WP_Error(
					'wp_claw_unknown_action',
					sprintf(
						/* translators: %s: Action name. */
						__( 'Unknown social action: %s', 'wp-claw' ),
						esc_html( $action )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Register WordPress hooks for the social module.
	 *
	 * On post publish, a task is queued for the Scribe agent to generate
	 * social post drafts for the published content.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'publish_post', array( $this, 'on_publish_post' ), 10, 2 );
	}

	/**
	 * Return the current state of the social module for the sync cron.
	 *
	 * @since 1.0.0
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh state snapshot needed for sync; caching would give stale counts.

		$table = $wpdb->prefix . 'wp_claw_tasks';

		$scheduled_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE module = %s AND status = %s",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix is safe.
				'social',
				'pending'
			)
		);

		// Count tasks created in the past 7 days regardless of status.
		$recent_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE module = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix is safe.
				'social'
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'scheduled_posts' => $scheduled_count,
			'recent_posts'    => $recent_count,
		);
	}

	// -------------------------------------------------------------------------
	// WordPress hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Queue a social post generation task when a post is published.
	 *
	 * Passes the post title, excerpt, and URL to the Scribe agent so it can
	 * draft platform-appropriate social posts for review.
	 *
	 * Auto-saves and revisions are silently ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 *
	 * @return void
	 */
	public function on_publish_post( int $post_id, \WP_Post $post ): void {
		// Bail on auto-saves.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Bail on revisions.
		if ( 'revision' === $post->post_type ) {
			return;
		}

		$excerpt = has_excerpt( $post_id )
			? wp_trim_words( get_the_excerpt( $post ), 55, '...' )
			: wp_trim_words( $post->post_content, 55, '...' );

		$task = array(
			'agent'   => $this->get_agent(),
			'module'  => $this->get_slug(),
			'action'  => 'generate_social_posts',
			'source'  => 'hook',
			'hook'    => 'publish_post',
			'title'   => sprintf(
				/* translators: %s: Post title. */
				__( 'Generate social posts for: %s', 'wp-claw' ),
				$post->post_title
			),
			'details' => array(
				'post_id'    => $post_id,
				'post_title' => $post->post_title,
				'excerpt'    => wp_strip_all_tags( $excerpt ),
				'post_url'   => get_permalink( $post_id ),
			),
		);

		\WPClaw\Hooks::queue_task( $task );

		wp_claw_log(
			'Social: queued post generation task.',
			'info',
			array(
				'post_id'    => $post_id,
				'post_title' => $post->post_title,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle the create_social_post action.
	 *
	 * Validates the platform enum and truncates text to the 2000-character
	 * limit before storing the task record locally and confirming success.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type string $platform      Required. One of: facebook, instagram, linkedin, twitter, pinterest.
	 *   @type string $text          Required. Post copy (max 2000 characters).
	 *   @type string $scheduled_time Optional. ISO 8601 datetime for deferred publishing.
	 *   @type int    $post_id       Optional. Source WordPress post ID.
	 * }
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function handle_create_social_post( array $params ) {
		// --- Validate platform -----------------------------------------------
		$platform = isset( $params['platform'] ) ? sanitize_key( $params['platform'] ) : '';

		if ( ! in_array( $platform, self::$allowed_platforms, true ) ) {
			return new \WP_Error(
				'wp_claw_invalid_platform',
				sprintf(
					/* translators: 1: Supplied platform. 2: Comma-separated list of allowed platforms. */
					__( 'Invalid platform "%1$s". Allowed values: %2$s.', 'wp-claw' ),
					esc_html( $platform ),
					implode( ', ', self::$allowed_platforms )
				),
				array( 'status' => 400 )
			);
		}

		// --- Validate and truncate text --------------------------------------
		$text = isset( $params['text'] ) ? sanitize_textarea_field( (string) $params['text'] ) : '';

		if ( '' === $text ) {
			return new \WP_Error(
				'wp_claw_missing_text',
				__( 'Social post text is required.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
		}

		// --- Optional fields -------------------------------------------------
		$scheduled_time = isset( $params['scheduled_time'] )
			? sanitize_text_field( (string) $params['scheduled_time'] )
			: '';

		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

		// --- Persist locally -------------------------------------------------
		$task_id = $this->insert_task_record(
			'create_social_post',
			array(
				'platform'       => $platform,
				'text'           => $text,
				'scheduled_time' => $scheduled_time,
				'post_id'        => $post_id,
			),
			'pending'
		);

		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		wp_claw_log(
			'Social: created social post task.',
			'info',
			array(
				'task_id'  => $task_id,
				'platform' => $platform,
			)
		);

		return array(
			'success' => true,
			'task_id' => $task_id,
			'message' => sprintf(
				/* translators: %s: Platform name. */
				__( 'Social post queued for %s.', 'wp-claw' ),
				$platform
			),
		);
	}

	/**
	 * Handle the schedule_post action.
	 *
	 * Validates the platform, text, and scheduled_time (must be a valid future
	 * datetime) before persisting the scheduled post task.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type string $platform       Required. Platform enum value.
	 *   @type string $text           Required. Post copy (max 2000 characters).
	 *   @type string $scheduled_time Required. ISO 8601 datetime string.
	 *   @type int    $post_id        Optional. Source WordPress post ID.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function handle_schedule_post( array $params ) {
		// --- Validate platform -----------------------------------------------
		$platform = isset( $params['platform'] ) ? sanitize_key( $params['platform'] ) : '';

		if ( ! in_array( $platform, self::$allowed_platforms, true ) ) {
			return new \WP_Error(
				'wp_claw_invalid_platform',
				sprintf(
					/* translators: 1: Supplied platform. 2: Comma-separated list of allowed platforms. */
					__( 'Invalid platform "%1$s". Allowed values: %2$s.', 'wp-claw' ),
					esc_html( $platform ),
					implode( ', ', self::$allowed_platforms )
				),
				array( 'status' => 400 )
			);
		}

		// --- Validate text ---------------------------------------------------
		$text = isset( $params['text'] ) ? sanitize_textarea_field( (string) $params['text'] ) : '';

		if ( '' === $text ) {
			return new \WP_Error(
				'wp_claw_missing_text',
				__( 'Social post text is required.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $text ) > self::MAX_TEXT_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_LENGTH );
		}

		// --- Validate scheduled_time -----------------------------------------
		$scheduled_time = isset( $params['scheduled_time'] )
			? sanitize_text_field( (string) $params['scheduled_time'] )
			: '';

		if ( '' === $scheduled_time ) {
			return new \WP_Error(
				'wp_claw_missing_scheduled_time',
				__( 'scheduled_time is required for schedule_post.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		// Validate that scheduled_time is parseable as a date.
		$timestamp = strtotime( $scheduled_time );

		if ( false === $timestamp || $timestamp <= 0 ) {
			return new \WP_Error(
				'wp_claw_invalid_scheduled_time',
				__( 'scheduled_time must be a valid ISO 8601 datetime string.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		// Warn if the date is in the past, but do not block (agent may be
		// scheduling for an already-passed slot to trigger an immediate post).
		if ( $timestamp < time() ) {
			wp_claw_log(
				'Social: schedule_post received a past scheduled_time — proceeding.',
				'warning',
				array(
					'platform'       => $platform,
					'scheduled_time' => $scheduled_time,
				)
			);
		}

		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

		// --- Persist ---------------------------------------------------------
		$task_id = $this->insert_task_record(
			'schedule_post',
			array(
				'platform'       => $platform,
				'text'           => $text,
				'scheduled_time' => $scheduled_time,
				'post_id'        => $post_id,
			),
			'pending'
		);

		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		wp_claw_log(
			'Social: scheduled post task created.',
			'info',
			array(
				'task_id'        => $task_id,
				'platform'       => $platform,
				'scheduled_time' => $scheduled_time,
			)
		);

		return array(
			'success'        => true,
			'task_id'        => $task_id,
			'platform'       => $platform,
			'scheduled_time' => $scheduled_time,
			'message'        => sprintf(
				/* translators: 1: Platform name. 2: Scheduled time. */
				__( 'Post scheduled for %1$s at %2$s.', 'wp-claw' ),
				$platform,
				$scheduled_time
			),
		);
	}

	/**
	 * Handle the get_scheduled_posts action.
	 *
	 * Returns all pending social post tasks from the local wp_claw_tasks table.
	 * Accepts an optional platform filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params {
	 *   @type string $platform Optional. Filter results to a single platform.
	 *   @type int    $limit    Optional. Max results to return (1–100). Default 50.
	 * }
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function handle_get_scheduled_posts( array $params ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_tasks';

		$limit    = isset( $params['limit'] ) ? (int) $params['limit'] : 50;
		$limit    = max( 1, min( 100, $limit ) );
		$platform = isset( $params['platform'] ) ? sanitize_key( (string) $params['platform'] ) : '';

		if ( '' !== $platform && ! in_array( $platform, self::$allowed_platforms, true ) ) {
			return new \WP_Error(
				'wp_claw_invalid_platform',
				sprintf(
					/* translators: %s: Supplied platform value. */
					__( 'Invalid platform filter: %s', 'wp-claw' ),
					esc_html( $platform )
				),
				array( 'status' => 400 )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live query by design; agent needs fresh state.

		if ( '' !== $platform ) {
			// Filter by platform using JSON_EXTRACT on the details column.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT task_id, action, status, details, created_at, updated_at FROM {$table} WHERE module = %s AND status = %s AND JSON_EXTRACT(details, '$.platform') = %s ORDER BY created_at ASC LIMIT %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix is safe.
					'social',
					'pending',
					$platform,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT task_id, action, status, details, created_at, updated_at FROM {$table} WHERE module = %s AND status = %s ORDER BY created_at ASC LIMIT %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix is safe.
					'social',
					'pending',
					$limit
				),
				ARRAY_A
			);
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_db_error',
				__( 'Database error fetching scheduled posts.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		$posts = array();

		foreach ( $rows as $row ) {
			$details = ! empty( $row['details'] )
				? json_decode( $row['details'], true )
				: array();

			if ( ! is_array( $details ) ) {
				$details = array();
			}

			// Apply optional platform filter in PHP (details column is JSON).
			if ( '' !== $platform && ( ! isset( $details['platform'] ) || $details['platform'] !== $platform ) ) {
				continue;
			}

			$posts[] = array(
				'task_id'        => sanitize_text_field( $row['task_id'] ),
				'action'         => sanitize_text_field( $row['action'] ),
				'status'         => sanitize_text_field( $row['status'] ),
				'platform'       => isset( $details['platform'] ) ? sanitize_text_field( $details['platform'] ) : '',
				'text'           => isset( $details['text'] ) ? sanitize_textarea_field( $details['text'] ) : '',
				'scheduled_time' => isset( $details['scheduled_time'] ) ? sanitize_text_field( $details['scheduled_time'] ) : '',
				'post_id'        => isset( $details['post_id'] ) ? absint( $details['post_id'] ) : 0,
				'created_at'     => sanitize_text_field( $row['created_at'] ),
				'updated_at'     => sanitize_text_field( $row['updated_at'] ),
			);
		}

		return array(
			'success' => true,
			'count'   => count( $posts ),
			'posts'   => $posts,
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Insert a task record into the local wp_claw_tasks table.
	 *
	 * Generates a unique task ID, JSON-encodes the details payload, and
	 * inserts the row. Returns the generated task ID on success, or a
	 * WP_Error if the insert fails.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action  The action name stored in the task record.
	 * @param array  $details Details to JSON-encode into the details column.
	 * @param string $status  Initial task status (e.g. 'pending').
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return string|\WP_Error The task ID on success, WP_Error on failure.
	 */
	private function insert_task_record( string $action, array $details, string $status = 'pending' ) {
		global $wpdb;

		$task_id = 'social-' . uniqid( '', true );
		$table   = $wpdb->prefix . 'wp_claw_tasks';
		$now     = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- local task log insert; no caching required.
		$inserted = $wpdb->insert(
			$table,
			array(
				'task_id'    => $task_id,
				'agent'      => $this->get_agent(),
				'module'     => $this->get_slug(),
				'action'     => $action,
				'status'     => $status,
				'details'    => wp_json_encode( $details ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_claw_log(
				'Social: failed to insert task record.',
				'error',
				array(
					'action'   => $action,
					'db_error' => $wpdb->last_error,
				)
			);

			return new \WP_Error(
				'wp_claw_db_insert_failed',
				__( 'Failed to save social post task to the database.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		return $task_id;
	}
}
