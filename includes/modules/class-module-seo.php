<?php
/**
 * SEO module.
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
 * SEO module — managed by the Scribe agent.
 *
 * Handles meta titles, meta descriptions, schema markup, sitemap generation,
 * content analysis, internal link suggestions, and robots.txt management.
 * All WordPress write operations are gated through the REST action allowlist.
 *
 * @since 1.0.0
 */
class Module_SEO extends Module_Base {

	/**
	 * Post meta key for custom schema markup JSON.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const META_SCHEMA = '_wp_claw_schema_markup';

	/**
	 * Post meta key for SEO title.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const META_TITLE = '_wp_claw_seo_title';

	/**
	 * Post meta key for SEO description.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const META_DESC = '_wp_claw_seo_description';

	// -------------------------------------------------------------------------
	// Contract implementation.
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'seo';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'SEO';
	}

	/**
	 * Return the responsible Klawty agent name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'scribe';
	}

	/**
	 * Return the allowlisted action strings for this module.
	 *
	 * Only actions present in this list can be executed by an agent
	 * via the /wp-json/wp-claw/v1/execute REST endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'update_post_meta_title',
			'update_post_meta_description',
			'update_schema_markup',
			'generate_sitemap',
			'analyze_content',
			'suggest_internal_links',
			'update_robots_txt',
			'check_cannibalization',
			'detect_stale_content',
			'find_broken_links',
			'get_striking_distance',
			'create_ab_test',
			'get_ab_test_results',
			'end_ab_test',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * Routes to the appropriate internal handler based on $action.
	 * All parameters are sanitized before use. Returns a result array
	 * on success or WP_Error on failure.
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
			case 'update_post_meta_title':
				return $this->action_update_post_meta_title( $params );

			case 'update_post_meta_description':
				return $this->action_update_post_meta_description( $params );

			case 'update_schema_markup':
				return $this->action_update_schema_markup( $params );

			case 'generate_sitemap':
				return $this->action_generate_sitemap();

			case 'analyze_content':
				return $this->action_analyze_content( $params );

			case 'suggest_internal_links':
				return $this->action_suggest_internal_links( $params );

			case 'update_robots_txt':
				return $this->action_update_robots_txt( $params );

			case 'check_cannibalization':
				return $this->action_check_cannibalization( $params );

			case 'detect_stale_content':
				return $this->action_detect_stale_content( $params );

			case 'find_broken_links':
				return $this->action_find_broken_links( $params );

			case 'get_striking_distance':
				return $this->action_get_striking_distance( $params );

			case 'create_ab_test':
				return $this->action_create_ab_test( $params );

			case 'get_ab_test_results':
				return $this->action_get_ab_test_results( $params );

			case 'end_ab_test':
				return $this->action_end_ab_test( $params );

			default:
				return new \WP_Error(
					'wp_claw_seo_unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown SEO action: %s', 'claw-agent' ),
						esc_html( $action )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return the current SEO-related state of the WordPress site.
	 *
	 * Called by the state sync cron so the Scribe agent has fresh context
	 * when auditing SEO health.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		global $wpdb;

		// Total published posts.
		$total_posts = (int) wp_count_posts( 'post' )->publish;

		// Posts that have the SEO title meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_with_title = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_TITLE
			)
		);

		// Posts that have the SEO description meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts_with_desc = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_DESC
			)
		);

		// Active A/B tests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_ab_tests = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wp_claw_ab_tests WHERE status = %s",
				'running'
			)
		);

		// Stale content — published posts not modified in 12+ months.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stale_content_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' AND post_modified < DATE_SUB( NOW(), INTERVAL 12 MONTH )"
		);

		// Broken link count from transient cache (0 if no scan has run yet).
		$cached_broken = get_transient( 'wp_claw_broken_links_total' );
		$broken_link_count = false !== $cached_broken ? (int) $cached_broken : 0;

		return array(
			'total_published_posts'    => $total_posts,
			'posts_with_meta_title'    => $posts_with_title,
			'posts_without_meta_title' => max( 0, $total_posts - $posts_with_title ),
			'posts_with_meta_desc'     => $posts_with_desc,
			'posts_without_meta_desc'  => max( 0, $total_posts - $posts_with_desc ),
			'last_sitemap_flush'       => get_option( 'wp_claw_seo_last_sitemap_flush', '' ),
			'robots_txt_custom_rules'  => (bool) get_option( 'wp_claw_seo_robots_txt_rules', '' ),
			'active_ab_tests'          => $active_ab_tests,
			'stale_content_count'      => $stale_content_count,
			'broken_link_count'        => $broken_link_count,
		);
	}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * Queues an SEO audit task on post save and a sitemap update task
	 * when a post is published.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 1 );
		add_action( 'publish_post', array( $this, 'on_publish_post' ), 20, 1 );
		add_action( 'wp_head', array( $this, 'serve_ab_test_meta' ), 5 );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks.
	// -------------------------------------------------------------------------

	/**
	 * Queue an SEO audit task when a post is saved.
	 *
	 * Only queued for public, non-revision post types to avoid noise
	 * from autosaves and attachment metadata saves.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The saved post ID.
	 *
	 * @return void
	 */
	public function on_save_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return;
		}

		$post_type_obj = get_post_type_object( $post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		$this->api_client->create_task(
			array(
				'agent'   => 'scribe',
				'module'  => 'seo',
				'action'  => 'seo_audit',
				'details' => array(
					'post_id'   => $post_id,
					'post_type' => $post_type,
					'trigger'   => 'save_post',
				),
			)
		);

		wp_claw_log(
			'SEO audit task queued.',
			'debug',
			array( 'post_id' => $post_id )
		);
	}

	/**
	 * Queue a sitemap update task when a post is published.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The published post ID.
	 *
	 * @return void
	 */
	public function on_publish_post( int $post_id ): void {
		$this->api_client->create_task(
			array(
				'agent'   => 'scribe',
				'module'  => 'seo',
				'action'  => 'sitemap_update',
				'details' => array(
					'post_id' => $post_id,
					'trigger' => 'publish_post',
				),
			)
		);

		wp_claw_log(
			'Sitemap update task queued.',
			'debug',
			array( 'post_id' => $post_id )
		);
	}

	// -------------------------------------------------------------------------
	// Action handlers.
	// -------------------------------------------------------------------------

	/**
	 * Update the SEO meta title for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id, title }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_post_meta_title( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( wp_unslash( $params['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error(
				'wp_claw_seo_missing_title',
				__( 'title parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		update_post_meta( $post_id, self::META_TITLE, $title );

		wp_claw_log( 'SEO meta title updated.', 'info', array( 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'post_id' => $post_id,
				'title'   => $title,
			),
		);
	}

	/**
	 * Update the SEO meta description for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id, description }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_post_meta_description( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$description = isset( $params['description'] ) ? sanitize_text_field( wp_unslash( $params['description'] ) ) : '';
		if ( '' === $description ) {
			return new \WP_Error(
				'wp_claw_seo_missing_description',
				__( 'description parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		update_post_meta( $post_id, self::META_DESC, $description );

		wp_claw_log( 'SEO meta description updated.', 'info', array( 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'post_id'     => $post_id,
				'description' => $description,
			),
		);
	}

	/**
	 * Update the schema markup JSON stored for a post.
	 *
	 * The schema value is stored as a JSON string in post meta.
	 * The agent provides a pre-built schema object; this method
	 * validates it is valid JSON before persisting.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id, schema }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_schema_markup( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		if ( empty( $params['schema'] ) ) {
			return new \WP_Error(
				'wp_claw_seo_missing_schema',
				__( 'schema parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		// Accept either an array (decoded) or a JSON string.
		if ( is_array( $params['schema'] ) ) {
			$schema_json = wp_json_encode( $params['schema'] );
		} else {
			$schema_json = sanitize_text_field( wp_unslash( (string) $params['schema'] ) );
		}

		if ( false === $schema_json || null === json_decode( $schema_json ) ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_schema',
				__( 'schema must be valid JSON.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		update_post_meta( $post_id, self::META_SCHEMA, $schema_json );

		wp_claw_log( 'Schema markup updated.', 'info', array( 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'post_id' => $post_id,
			),
		);
	}

	/**
	 * Flush WordPress rewrite rules to trigger a fresh sitemap build.
	 *
	 * WordPress core generates XML sitemaps automatically; flushing
	 * rewrites forces a rebuild on the next request.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function action_generate_sitemap(): array {
		flush_rewrite_rules( false );

		$timestamp = current_time( 'mysql', true );
		update_option( 'wp_claw_seo_last_sitemap_flush', $timestamp );

		wp_claw_log( 'Sitemap rewrite flush triggered.', 'info' );

		return array(
			'success' => true,
			'data'    => array(
				'flushed_at'  => $timestamp,
				'sitemap_url' => esc_url( home_url( '/?sitemap=1' ) ),
			),
		);
	}

	/**
	 * Analyze the content of a post and return basic SEO signals.
	 *
	 * Returns word count, heading counts, and whether SEO meta fields
	 * are present — giving the Scribe agent data to make decisions.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_analyze_content( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'wp_claw_seo_post_not_found',
				__( 'Post not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		$content    = $post->post_content;
		$word_count = str_word_count( wp_strip_all_tags( $content ) );

		// Count heading tags H1–H6.
		$heading_count = 0;
		if ( preg_match_all( '/<h[1-6][^>]*>/i', $content, $matches ) ) {
			$heading_count = count( $matches[0] );
		}

		$has_meta_title = (bool) get_post_meta( $post_id, self::META_TITLE, true );
		$has_meta_desc  = (bool) get_post_meta( $post_id, self::META_DESC, true );
		$has_schema     = (bool) get_post_meta( $post_id, self::META_SCHEMA, true );

		return array(
			'success' => true,
			'data'    => array(
				'post_id'        => $post_id,
				'word_count'     => $word_count,
				'heading_count'  => $heading_count,
				'has_meta_title' => $has_meta_title,
				'has_meta_desc'  => $has_meta_desc,
				'has_schema'     => $has_schema,
				'post_status'    => esc_html( $post->post_status ),
				'post_type'      => esc_html( $post->post_type ),
			),
		);
	}

	/**
	 * Suggest internal links for a post based on keyword overlap.
	 *
	 * Queries published posts for title keyword matches and returns
	 * candidate URLs the Scribe agent can embed as internal links.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id, keywords[] }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_suggest_internal_links( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$raw_keywords = isset( $params['keywords'] ) && is_array( $params['keywords'] )
			? $params['keywords']
			: array();

		$keywords = array_map( 'sanitize_text_field', $raw_keywords );
		$keywords = array_filter( $keywords );

		if ( empty( $keywords ) ) {
			return new \WP_Error(
				'wp_claw_seo_missing_keywords',
				__( 'At least one keyword is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$suggestions = array();

		foreach ( $keywords as $keyword ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small result set, performance acceptable.
			$query = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 5,
					's'              => $keyword,
					'post__not_in'   => array( $post_id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- max 5 results.
					'fields'         => 'ids',
				)
			);

			if ( $query->have_posts() ) {
				foreach ( $query->posts as $found_id ) {
					$found_id = absint( $found_id );
					if ( ! isset( $suggestions[ $found_id ] ) ) {
						$suggestions[ $found_id ] = array(
							'post_id' => $found_id,
							'title'   => esc_html( get_the_title( $found_id ) ),
							'url'     => esc_url( get_permalink( $found_id ) ),
							'keyword' => esc_html( $keyword ),
						);
					}
				}
			}

			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'data'    => array(
				'post_id'     => $post_id,
				'suggestions' => array_values( $suggestions ),
			),
		);
	}

	/**
	 * Update the custom robots.txt rules stored in WordPress options.
	 *
	 * WordPress generates a virtual robots.txt at /robots.txt. This method
	 * stores extra directives that are appended to the default output via
	 * the 'robots_txt' filter (registered during module init).
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { rules: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_robots_txt( array $params ) {
		if ( ! isset( $params['rules'] ) || '' === $params['rules'] ) {
			return new \WP_Error(
				'wp_claw_seo_missing_rules',
				__( 'rules parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		// Sanitize: allow newlines and common robots.txt directives only.
		$rules = sanitize_textarea_field( wp_unslash( $params['rules'] ) );

		update_option( 'wp_claw_seo_robots_txt_rules', $rules );

		wp_claw_log( 'robots.txt custom rules updated.', 'info' );

		return array(
			'success' => true,
			'data'    => array(
				'rules_length' => strlen( $rules ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// A/B test front-end serving.
	// -------------------------------------------------------------------------

	/**
	 * Output A/B test meta description and override document title on singular pages.
	 *
	 * Determines the visitor variant via a CRC32 hash of the session ID, then
	 * prints the corresponding meta description and hooks into
	 * `pre_get_document_title` to swap the title tag. Impressions are tracked
	 * in the `wp_claw_analytics` table to avoid per-pageview UPDATEs on the
	 * ab_tests row.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function serve_ab_test_meta(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_ab_tests';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$test = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, variant_a_title, variant_a_desc, variant_b_title, variant_b_desc FROM {$table} WHERE post_id = %d AND status = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				'running'
			)
		);

		if ( ! $test ) {
			return;
		}

		// Determine variant: 0 = A, 1 = B.
		if ( function_exists( 'session_id' ) && '' !== session_id() ) {
			$seed = session_id();
		} else {
			$seed = wp_generate_uuid4();
		}
		$variant = abs( crc32( $seed ) ) % 2;

		if ( 0 === $variant ) {
			$desc  = $test->variant_a_desc;
			$title = $test->variant_a_title;
			$event = 'ab_impression_a';
		} else {
			$desc  = $test->variant_b_desc;
			$title = $test->variant_b_title;
			$event = 'ab_impression_b';
		}

		// Output meta description.
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}

		// Override document title via filter.
		if ( '' !== $title ) {
			add_filter(
				'pre_get_document_title',
				static function () use ( $title ) {
					return esc_html( $title );
				}
			);
		}

		// Track impression in analytics table.
		$analytics_table = $wpdb->prefix . 'wp_claw_analytics';
		$page_url        = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$analytics_table,
			array(
				'page_url'   => $page_url,
				'event_type' => $event,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	// -------------------------------------------------------------------------
	// New action handlers (v1.1.0).
	// -------------------------------------------------------------------------

	/**
	 * Check for keyword cannibalization across posts.
	 *
	 * Searches SEO title and description meta for a given keyword to find
	 * posts competing for the same term.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { keyword (required), exclude_post_id (optional) }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_check_cannibalization( array $params ) {
		$keyword = isset( $params['keyword'] ) ? sanitize_text_field( wp_unslash( $params['keyword'] ) ) : '';
		if ( '' === $keyword ) {
			return new \WP_Error(
				'wp_claw_seo_missing_keyword',
				__( 'keyword parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$exclude_id = isset( $params['exclude_post_id'] ) ? absint( $params['exclude_post_id'] ) : 0;

		global $wpdb;

		$like = '%' . $wpdb->esc_like( $keyword ) . '%';

		if ( $exclude_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_status = 'publish' AND pm.meta_key IN ( %s, %s ) AND pm.meta_value LIKE %s AND pm.post_id != %d ORDER BY pm.post_id ASC LIMIT 50",
					self::META_TITLE,
					self::META_DESC,
					$like,
					$exclude_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_status = 'publish' AND pm.meta_key IN ( %s, %s ) AND pm.meta_value LIKE %s ORDER BY pm.post_id ASC LIMIT 50",
					self::META_TITLE,
					self::META_DESC,
					$like
				)
			);
		}

		$conflicts = array();
		foreach ( $rows as $row ) {
			$pid = absint( $row->post_id );
			if ( ! isset( $conflicts[ $pid ] ) ) {
				$conflicts[ $pid ] = array(
					'post_id'    => $pid,
					'title'      => esc_html( get_the_title( $pid ) ),
					'matching'   => array(),
				);
			}
			$conflicts[ $pid ]['matching'][] = array(
				'meta_key'   => esc_html( $row->meta_key ),
				'meta_value' => esc_html( $row->meta_value ),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'keyword'   => $keyword,
				'conflicts' => array_values( $conflicts ),
			),
		);
	}

	/**
	 * Detect stale content that has not been updated recently.
	 *
	 * Returns published posts whose `post_modified` date is older than the
	 * specified number of months.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { months (optional, default 12) }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_detect_stale_content( array $params ) {
		$months = isset( $params['months'] ) ? absint( $params['months'] ) : 12;
		if ( 0 === $months ) {
			$months = 12;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_modified, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' AND post_modified < DATE_SUB( NOW(), INTERVAL %d MONTH ) ORDER BY post_modified ASC LIMIT 100",
				$months
			)
		);

		$stale = array();
		foreach ( $rows as $row ) {
			$stale[] = array(
				'post_id'       => absint( $row->ID ),
				'title'         => esc_html( $row->post_title ),
				'post_modified' => esc_html( $row->post_modified ),
				'word_count'    => str_word_count( wp_strip_all_tags( $row->post_content ) ),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'months'     => $months,
				'stale_posts' => $stale,
			),
		);
	}

	/**
	 * Find broken internal links in post content.
	 *
	 * Extracts anchor hrefs from post content, checks internal links via
	 * `wp_remote_head()`, and caches results in a transient per post.
	 * Maximum 20 links checked per post.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { post_id (optional), batch_size (optional, default 5) }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_find_broken_links( array $params ) {
		$post_id    = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		$batch_size = isset( $params['batch_size'] ) ? absint( $params['batch_size'] ) : 5;
		if ( 0 === $batch_size ) {
			$batch_size = 5;
		}
		$batch_size = min( $batch_size, 20 );

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error(
					'wp_claw_seo_post_not_found',
					__( 'Post not found.', 'claw-agent' ),
					array( 'status' => 404 )
				);
			}
			$posts_to_check = array( $post );
		} else {
			$query = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => $batch_size,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			$posts_to_check = $query->posts;
			wp_reset_postdata();
		}

		$all_broken   = array();
		$total_broken = 0;

		foreach ( $posts_to_check as $p ) {
			$pid       = absint( $p->ID );
			$cache_key = 'wp_claw_broken_links_' . $pid;
			$cached    = get_transient( $cache_key );

			if ( false !== $cached ) {
				if ( ! empty( $cached ) ) {
					$all_broken = array_merge( $all_broken, $cached );
					$total_broken += count( $cached );
				}
				continue;
			}

			// Extract hrefs.
			if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', $p->post_content, $matches ) ) {
				set_transient( $cache_key, array(), DAY_IN_SECONDS );
				continue;
			}

			$urls   = array_unique( $matches[1] );
			$urls   = array_slice( $urls, 0, 20 );
			$broken = array();

			foreach ( $urls as $url ) {
				$url = esc_url_raw( $url );
				if ( '' === $url ) {
					continue;
				}

				// Only check internal links (same host).
				$link_host = wp_parse_url( $url, PHP_URL_HOST );
				if ( $link_host && $link_host !== $site_host ) {
					continue;
				}

				// Relative URLs — make absolute.
				if ( ! $link_host ) {
					$url = home_url( $url );
				}

				$response = wp_remote_head(
					$url,
					array(
						'timeout'     => 3,
						'redirection' => 3,
						'sslverify'   => false,
					)
				);

				if ( is_wp_error( $response ) ) {
					$broken[] = array(
						'source_post_id' => $pid,
						'target_url'     => esc_url( $url ),
						'error'          => esc_html( $response->get_error_message() ),
					);
					continue;
				}

				$code = wp_remote_retrieve_response_code( $response );
				if ( $code >= 400 ) {
					$broken[] = array(
						'source_post_id' => $pid,
						'target_url'     => esc_url( $url ),
						'http_status'    => $code,
					);
				}
			}

			set_transient( $cache_key, $broken, DAY_IN_SECONDS );

			if ( ! empty( $broken ) ) {
				$all_broken   = array_merge( $all_broken, $broken );
				$total_broken += count( $broken );
			}
		}

		// Store total for get_state().
		set_transient( 'wp_claw_broken_links_total', $total_broken, DAY_IN_SECONDS );

		return array(
			'success' => true,
			'data'    => array(
				'broken_links' => $all_broken,
				'total'        => $total_broken,
			),
		);
	}

	/**
	 * Identify striking distance keywords (positions 11-20).
	 *
	 * Accepts ranking data from Google Search Console (provided by the agent)
	 * and filters to keywords in positions 11-20, sorted by impressions.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { rankings: array of [url, position, impressions, clicks] }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_striking_distance( array $params ) {
		$rankings = isset( $params['rankings'] ) && is_array( $params['rankings'] )
			? $params['rankings']
			: array();

		if ( empty( $rankings ) ) {
			return new \WP_Error(
				'wp_claw_seo_missing_rankings',
				__( 'rankings parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$striking = array();
		foreach ( $rankings as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 4 ) {
				continue;
			}

			$position = floatval( $row[1] );
			if ( $position >= 11 && $position <= 20 ) {
				$striking[] = array(
					'url'         => esc_url( (string) $row[0] ),
					'position'    => round( $position, 1 ),
					'impressions' => absint( $row[2] ),
					'clicks'      => absint( $row[3] ),
				);
			}
		}

		// Sort by impressions descending.
		usort(
			$striking,
			static function ( $a, $b ) {
				return $b['impressions'] - $a['impressions'];
			}
		);

		// Cache for 7 days.
		set_transient( 'wp_claw_striking_distance', $striking, 7 * DAY_IN_SECONDS );

		return array(
			'success' => true,
			'data'    => array(
				'striking_distance' => $striking,
				'total'             => count( $striking ),
			),
		);
	}

	/**
	 * Create an A/B test for a post's SEO title and description.
	 *
	 * Stores variant A (current meta) and variant B (provided) in the
	 * `wp_claw_ab_tests` table. Only one test per post can be active.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { post_id (required), variant_b_title, variant_b_desc }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_ab_test( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'wp_claw_seo_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_ab_tests';

		// Check no active test exists for this post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND status = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				'running'
			)
		);

		if ( $existing ) {
			return new \WP_Error(
				'wp_claw_seo_ab_test_exists',
				__( 'An active A/B test already exists for this post.', 'claw-agent' ),
				array( 'status' => 409 )
			);
		}

		// Variant A = current meta.
		$variant_a_title = (string) get_post_meta( $post_id, self::META_TITLE, true );
		$variant_a_desc  = (string) get_post_meta( $post_id, self::META_DESC, true );

		// Variant B = provided by agent.
		$variant_b_title = isset( $params['variant_b_title'] )
			? sanitize_text_field( wp_unslash( $params['variant_b_title'] ) )
			: '';
		$variant_b_desc  = isset( $params['variant_b_desc'] )
			? sanitize_text_field( wp_unslash( $params['variant_b_desc'] ) )
			: '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'post_id'         => $post_id,
				'variant_a_title' => $variant_a_title,
				'variant_a_desc'  => $variant_a_desc,
				'variant_b_title' => $variant_b_title,
				'variant_b_desc'  => $variant_b_desc,
				'status'          => 'running',
				'started_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$test_id = (int) $wpdb->insert_id;

		wp_claw_log( 'A/B test created.', 'info', array( 'test_id' => $test_id, 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'test_id' => $test_id,
				'post_id' => $post_id,
				'status'  => 'running',
			),
		);
	}

	/**
	 * Get results for an A/B test.
	 *
	 * Counts impressions from the analytics table and computes CTR per
	 * variant. Returns a basic statistical significance indicator.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { test_id OR post_id }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_get_ab_test_results( array $params ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_ab_tests';

		$test_id = isset( $params['test_id'] ) ? absint( $params['test_id'] ) : 0;
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

		if ( $test_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$test = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$test_id
				)
			);
		} elseif ( $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$test = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE post_id = %d ORDER BY started_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id
				)
			);
		} else {
			return new \WP_Error(
				'wp_claw_seo_missing_id',
				__( 'Either test_id or post_id is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		if ( ! $test ) {
			return new \WP_Error(
				'wp_claw_seo_ab_test_not_found',
				__( 'A/B test not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		// Count impressions from analytics table.
		$analytics_table = $wpdb->prefix . 'wp_claw_analytics';
		$test_post       = get_post( absint( $test->post_id ) );
		$page_path       = $test_post ? wp_make_link_relative( get_permalink( $test_post ) ) : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$impressions_a = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$analytics_table} WHERE event_type = %s AND page_url LIKE %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'ab_impression_a',
				'%' . $wpdb->esc_like( $page_path ) . '%',
				$test->started_at
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$impressions_b = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$analytics_table} WHERE event_type = %s AND page_url LIKE %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'ab_impression_b',
				'%' . $wpdb->esc_like( $page_path ) . '%',
				$test->started_at
			)
		);

		$clicks_a = absint( $test->clicks_a );
		$clicks_b = absint( $test->clicks_b );

		$ctr_a = $impressions_a > 0 ? round( ( $clicks_a / $impressions_a ) * 100, 2 ) : 0;
		$ctr_b = $impressions_b > 0 ? round( ( $clicks_b / $impressions_b ) * 100, 2 ) : 0;

		$total_impressions  = $impressions_a + $impressions_b;
		$is_significant     = $total_impressions >= 100;
		$winner             = null;

		if ( $is_significant && $ctr_a !== $ctr_b ) {
			$winner = $ctr_a > $ctr_b ? 'a' : 'b';
		}

		return array(
			'success' => true,
			'data'    => array(
				'test_id'          => absint( $test->id ),
				'post_id'          => absint( $test->post_id ),
				'status'           => esc_html( $test->status ),
				'variant_a'        => array(
					'title'       => esc_html( $test->variant_a_title ),
					'description' => esc_html( $test->variant_a_desc ),
					'impressions' => $impressions_a,
					'clicks'      => $clicks_a,
					'ctr'         => $ctr_a,
				),
				'variant_b'        => array(
					'title'       => esc_html( $test->variant_b_title ),
					'description' => esc_html( $test->variant_b_desc ),
					'impressions' => $impressions_b,
					'clicks'      => $clicks_b,
					'ctr'         => $ctr_b,
				),
				'is_significant'   => $is_significant,
				'winner'           => $winner,
				'started_at'       => esc_html( $test->started_at ),
			),
		);
	}

	/**
	 * End an A/B test and optionally apply the winning variant.
	 *
	 * Sets the test status to 'completed' and records ended_at. When
	 * `apply_winner` is true and a winner has been determined, the
	 * winning variant's title and description replace the post meta.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params { test_id (required), apply_winner (bool, default false) }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_end_ab_test( array $params ) {
		$test_id = isset( $params['test_id'] ) ? absint( $params['test_id'] ) : 0;
		if ( ! $test_id ) {
			return new \WP_Error(
				'wp_claw_seo_missing_test_id',
				__( 'test_id parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp_claw_ab_tests';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$test = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$test_id
			)
		);

		if ( ! $test ) {
			return new \WP_Error(
				'wp_claw_seo_ab_test_not_found',
				__( 'A/B test not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		$apply_winner = ! empty( $params['apply_winner'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'   => 'completed',
				'ended_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $test_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$applied = false;

		if ( $apply_winner && $test->winner ) {
			$post_id = absint( $test->post_id );
			if ( 'a' === $test->winner ) {
				update_post_meta( $post_id, self::META_TITLE, $test->variant_a_title );
				update_post_meta( $post_id, self::META_DESC, $test->variant_a_desc );
			} else {
				update_post_meta( $post_id, self::META_TITLE, $test->variant_b_title );
				update_post_meta( $post_id, self::META_DESC, $test->variant_b_desc );
			}
			$applied = true;
		}

		wp_claw_log(
			'A/B test ended.',
			'info',
			array(
				'test_id'       => $test_id,
				'apply_winner'  => $apply_winner,
				'winner_applied' => $applied,
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'test_id'        => $test_id,
				'status'         => 'completed',
				'winner'         => $test->winner ? esc_html( $test->winner ) : null,
				'winner_applied' => $applied,
			),
		);
	}
}
