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

		return array(
			'total_published_posts'    => $total_posts,
			'posts_with_meta_title'    => $posts_with_title,
			'posts_without_meta_title' => max( 0, $total_posts - $posts_with_title ),
			'posts_with_meta_desc'     => $posts_with_desc,
			'posts_without_meta_desc'  => max( 0, $total_posts - $posts_with_desc ),
			'last_sitemap_flush'       => get_option( 'wp_claw_seo_last_sitemap_flush', '' ),
			'robots_txt_custom_rules'  => (bool) get_option( 'wp_claw_seo_robots_txt_rules', '' ),
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
			$query = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 5,
					's'              => $keyword,
					'post__not_in'   => array( $post_id ),
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
}
