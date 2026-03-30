<?php
/**
 * Content module.
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
 * Content module — managed by the Scribe agent.
 *
 * Handles draft post creation, post content updates, page creation,
 * post translations, and excerpt generation. All write operations
 * are restricted to the allowlisted actions exposed via the REST bridge.
 *
 * @since 1.0.0
 */
class Module_Content extends Module_Base {

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
		return 'content';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Content';
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
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'create_draft_post',
			'update_post_content',
			'create_page',
			'translate_post',
			'generate_excerpt',
			'check_content_freshness',
			'update_stale_dates',
			'expand_thin_content',
		);
	}

	/**
	 * Handle an inbound agent action.
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
			case 'create_draft_post':
				return $this->action_create_draft_post( $params );

			case 'update_post_content':
				return $this->action_update_post_content( $params );

			case 'create_page':
				return $this->action_create_page( $params );

			case 'translate_post':
				return $this->action_translate_post( $params );

			case 'generate_excerpt':
				return $this->action_generate_excerpt( $params );

			case 'check_content_freshness':
				return $this->action_check_content_freshness( $params );

			case 'update_stale_dates':
				return $this->action_update_stale_dates( $params );

			case 'expand_thin_content':
				return $this->action_expand_thin_content( $params );

			default:
				return new \WP_Error(
					'wp_claw_content_unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown Content action: %s', 'claw-agent' ),
						esc_html( $action )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return the current content state of the WordPress site.
	 *
	 * Provides the Scribe agent with post counts by status and
	 * a list of recently modified posts (last 7 days) for context.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		$counts = wp_count_posts( 'post' );

		$post_counts = array(
			'publish' => (int) ( $counts->publish ?? 0 ),
			'draft'   => (int) ( $counts->draft ?? 0 ),
			'pending' => (int) ( $counts->pending ?? 0 ),
			'future'  => (int) ( $counts->future ?? 0 ),
			'trash'   => (int) ( $counts->trash ?? 0 ),
		);

		// Posts modified in the last 7 days.
		$recent = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 10,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => '7 days ago',
					),
				),
				'fields'         => 'ids',
			)
		);

		$recently_modified = array();
		if ( $recent->have_posts() ) {
			foreach ( $recent->posts as $post_id ) {
				$post_id = absint( $post_id );
				$post    = get_post( $post_id );
				if ( $post ) {
					$recently_modified[] = array(
						'post_id'   => $post_id,
						'title'     => esc_html( get_the_title( $post_id ) ),
						'status'    => esc_html( $post->post_status ),
						'post_type' => esc_html( $post->post_type ),
						'modified'  => esc_html( $post->post_modified_gmt ),
					);
				}
			}
		}

		wp_reset_postdata();

		return array(
			'post_counts'       => $post_counts,
			'recently_modified' => $recently_modified,
		);
	}

	/**
	 * Register WordPress hooks.
	 *
	 * The Content module has no event-driven hooks — all actions are
	 * triggered on demand by the Scribe agent.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// No hooks — Content module is fully agent-triggered.
	}

	// -------------------------------------------------------------------------
	// Action handlers.
	// -------------------------------------------------------------------------

	/**
	 * Create a new draft post.
	 *
	 * Inserts a post with status 'draft'. Title is sanitized with
	 * sanitize_text_field(); content is sanitized with wp_kses_post()
	 * to allow post-safe HTML while blocking scripts and iframes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { title: string, content?: string, tags?: string[], categories?: int[] }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_draft_post( array $params ) {
		$title = isset( $params['title'] ) ? sanitize_text_field( wp_unslash( $params['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error(
				'wp_claw_content_missing_title',
				__( 'title parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$content = isset( $params['content'] ) ? wp_kses_post( wp_unslash( $params['content'] ) ) : '';

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'post',
		);

		// Optional: tags (comma-separated string or array of tag names).
		if ( ! empty( $params['tags'] ) ) {
			if ( is_array( $params['tags'] ) ) {
				$tags = array_map( 'sanitize_text_field', $params['tags'] );
			} else {
				$tags = array_map( 'sanitize_text_field', explode( ',', wp_unslash( (string) $params['tags'] ) ) );
			}
			$post_data['tags_input'] = array_filter( $tags );
		}

		// Optional: category IDs.
		if ( ! empty( $params['categories'] ) && is_array( $params['categories'] ) ) {
			$post_data['post_category'] = array_map( 'absint', $params['categories'] );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			wp_claw_log( 'Failed to create draft post.', 'error', array( 'error' => $post_id->get_error_message() ) );
			return $post_id;
		}

		wp_claw_log( 'Draft post created.', 'info', array( 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'post_id'  => $post_id,
				'title'    => esc_html( $title ),
				'edit_url' => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
			),
		);
	}

	/**
	 * Update the content of an existing post.
	 *
	 * Accepts post_id and new content. Content is filtered with
	 * wp_kses_post() before the update. Does not change post status.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id: int, content: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_post_content( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new \WP_Error(
				'wp_claw_content_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'wp_claw_content_post_not_found',
				__( 'Post not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		if ( ! isset( $params['content'] ) ) {
			return new \WP_Error(
				'wp_claw_content_missing_content',
				__( 'content parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$content = wp_kses_post( wp_unslash( $params['content'] ) );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log(
				'Failed to update post content.',
				'error',
				array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		wp_claw_log( 'Post content updated.', 'info', array( 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'post_id'     => $post_id,
				'post_type'   => esc_html( $post->post_type ),
				'post_status' => esc_html( $post->post_status ),
			),
		);
	}

	/**
	 * Create a new draft page.
	 *
	 * Identical to create_draft_post but uses post_type 'page'.
	 * Supports an optional parent page ID for nested page hierarchies.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { title: string, content?: string, parent_id?: int }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_page( array $params ) {
		$title = isset( $params['title'] ) ? sanitize_text_field( wp_unslash( $params['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error(
				'wp_claw_content_missing_title',
				__( 'title parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$content   = isset( $params['content'] ) ? wp_kses_post( wp_unslash( $params['content'] ) ) : '';
		$parent_id = isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : 0;

		// Validate parent page if provided.
		if ( $parent_id && ! get_post( $parent_id ) ) {
			return new \WP_Error(
				'wp_claw_content_invalid_parent',
				__( 'parent_id does not refer to an existing post.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_parent'  => $parent_id,
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			wp_claw_log( 'Failed to create page.', 'error', array( 'error' => $post_id->get_error_message() ) );
			return $post_id;
		}

		wp_claw_log( 'Draft page created.', 'info', array( 'post_id' => $post_id ) );

		return array(
			'success' => true,
			'data'    => array(
				'post_id'   => $post_id,
				'title'     => esc_html( $title ),
				'post_type' => 'page',
				'edit_url'  => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
			),
		);
	}

	/**
	 * Store a translated version of a post as a new draft post.
	 *
	 * Creates a new draft post with the translated title and content.
	 * Links the translation back to the original via post meta for
	 * traceability. Actual translation work is performed by the
	 * Scribe agent — this method persists the result.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { source_post_id: int, locale: string, title: string, content: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_translate_post( array $params ) {
		$source_post_id = isset( $params['source_post_id'] ) ? absint( $params['source_post_id'] ) : 0;
		if ( ! $source_post_id || ! get_post( $source_post_id ) ) {
			return new \WP_Error(
				'wp_claw_content_invalid_source',
				__( 'Invalid or missing source_post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$locale = isset( $params['locale'] ) ? sanitize_text_field( wp_unslash( $params['locale'] ) ) : '';
		if ( '' === $locale ) {
			return new \WP_Error(
				'wp_claw_content_missing_locale',
				__( 'locale parameter is required (e.g. fr_FR).', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( wp_unslash( $params['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error(
				'wp_claw_content_missing_title',
				__( 'title parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$content = isset( $params['content'] ) ? wp_kses_post( wp_unslash( $params['content'] ) ) : '';

		$source_post = get_post( $source_post_id );

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => $source_post ? $source_post->post_type : 'post',
		);

		$new_post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $new_post_id ) ) {
			wp_claw_log(
				'Failed to create translated post.',
				'error',
				array(
					'error'          => $new_post_id->get_error_message(),
					'source_post_id' => $source_post_id,
				)
			);
			return $new_post_id;
		}

		update_post_meta( $new_post_id, '_wp_claw_translation_source', $source_post_id );
		update_post_meta( $new_post_id, '_wp_claw_translation_locale', $locale );

		wp_claw_log(
			'Translated post draft created.',
			'info',
			array(
				'post_id'        => $new_post_id,
				'source_post_id' => $source_post_id,
				'locale'         => $locale,
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'post_id'        => $new_post_id,
				'source_post_id' => $source_post_id,
				'locale'         => esc_html( $locale ),
				'title'          => esc_html( $title ),
				'edit_url'       => esc_url( get_edit_post_link( $new_post_id, 'raw' ) ),
			),
		);
	}

	/**
	 * Generate a trimmed excerpt from a post's content or a supplied text.
	 *
	 * Uses wp_trim_words() to produce a clean excerpt. The Scribe agent
	 * can request excerpts for use in meta descriptions, social snippets,
	 * or explicit post excerpts.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params { post_id?: int, text?: string, length?: int, more?: string }.
	 *
	 * @return array|\WP_Error
	 */
	private function action_generate_excerpt( array $params ) {
		$length = isset( $params['length'] ) ? absint( $params['length'] ) : 55;
		$length = max( 1, min( $length, 500 ) );

		$more = isset( $params['more'] ) ? sanitize_text_field( wp_unslash( $params['more'] ) ) : '&hellip;';

		// Source: explicit text OR post content.
		if ( ! empty( $params['text'] ) ) {
			$raw_text = sanitize_textarea_field( wp_unslash( $params['text'] ) );
		} elseif ( ! empty( $params['post_id'] ) ) {
			$post_id = absint( $params['post_id'] );
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error(
					'wp_claw_content_post_not_found',
					__( 'Post not found.', 'claw-agent' ),
					array( 'status' => 404 )
				);
			}
			$raw_text = wp_strip_all_tags( $post->post_content );
		} else {
			return new \WP_Error(
				'wp_claw_content_missing_source',
				__( 'Either post_id or text parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$excerpt = wp_trim_words( $raw_text, $length, $more );

		// If a post_id was provided, optionally persist the excerpt.
		if ( ! empty( $params['post_id'] ) && ! empty( $params['save'] ) ) {
			$post_id = absint( $params['post_id'] );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => $excerpt,
				)
			);
			wp_claw_log( 'Post excerpt saved.', 'info', array( 'post_id' => $post_id ) );
		}

		return array(
			'success' => true,
			'data'    => array(
				'excerpt'    => $excerpt,
				'word_count' => $length,
			),
		);
	}

	/**
	 * Check content freshness across all published posts.
	 *
	 * Returns posts that are stale (modified > 12 months ago), thin
	 * (word count < 300), or contain outdated year references older
	 * than the current year.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters. Currently unused.
	 *
	 * @return array
	 */
	private function action_check_content_freshness( array $params ): array {
		$current_year   = (int) gmdate( 'Y' );
		$twelve_months  = gmdate( 'Y-m-d H:i:s', strtotime( '-12 months' ) );

		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);

		$stale_posts = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				if ( ! ( $post instanceof \WP_Post ) ) {
					continue;
				}

				$plain_content = wp_strip_all_tags( $post->post_content );
				$word_count    = str_word_count( $plain_content );
				$is_stale      = ( $post->post_modified_gmt < $twelve_months );
				$is_thin       = ( $word_count < 300 );

				// Check for outdated year references.
				$outdated_years = array();
				if ( preg_match_all( '/\b(20[0-2][0-9])\b/', $plain_content, $matches ) ) {
					foreach ( $matches[1] as $year ) {
						$year_int = (int) $year;
						if ( $year_int < $current_year && ! in_array( $year_int, $outdated_years, true ) ) {
							$outdated_years[] = $year_int;
						}
					}
				}

				if ( $is_stale || $is_thin || ! empty( $outdated_years ) ) {
					$stale_posts[] = array(
						'post_id'        => $post->ID,
						'title'          => esc_html( get_the_title( $post->ID ) ),
						'post_type'      => esc_html( $post->post_type ),
						'modified'       => esc_html( $post->post_modified_gmt ),
						'word_count'     => $word_count,
						'is_stale'       => $is_stale,
						'is_thin'        => $is_thin,
						'outdated_years' => $outdated_years,
						'edit_url'       => esc_url( get_edit_post_link( $post->ID, 'raw' ) ),
					);
				}
			}
		}

		wp_reset_postdata();

		return array(
			'success' => true,
			'data'    => array(
				'posts_checked' => $query->found_posts,
				'issues_found'  => count( $stale_posts ),
				'posts'         => $stale_posts,
			),
		);
	}

	/**
	 * Update standalone year references in a post's content.
	 *
	 * Uses a regex that matches standalone year numbers while avoiding
	 * matches inside URLs, HTML attributes, or code blocks. Triggers a
	 * revision via wp_update_post().
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *   @type int    $post_id  Required. The post to update.
	 *   @type string $old_year Required. The year to find (e.g. '2024').
	 *   @type string $new_year Required. The replacement year (e.g. '2026').
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function action_update_stale_dates( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new \WP_Error(
				'wp_claw_content_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'wp_claw_content_post_not_found',
				__( 'Post not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		$old_year = isset( $params['old_year'] ) ? sanitize_text_field( wp_unslash( $params['old_year'] ) ) : '';
		$new_year = isset( $params['new_year'] ) ? sanitize_text_field( wp_unslash( $params['new_year'] ) ) : '';

		if ( '' === $old_year || '' === $new_year ) {
			return new \WP_Error(
				'wp_claw_content_missing_years',
				__( 'old_year and new_year parameters are required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		// Only match standalone year references — not inside URLs, attributes, or code.
		$pattern     = '/(?<!["\x27\/=\d])' . preg_quote( $old_year, '/' ) . '(?!["\x27\/=\d])/';
		$new_content = preg_replace( $pattern, $new_year, $post->post_content, -1, $count );

		if ( 0 === $count ) {
			return array(
				'success'           => true,
				'data'              => array(
					'post_id'           => $post_id,
					'replacements_made' => 0,
					'message'           => __( 'No standalone year references found to replace.', 'claw-agent' ),
				),
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log(
				'Failed to update stale dates.',
				'error',
				array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		wp_claw_log(
			'Content: updated stale year references.',
			'info',
			array(
				'post_id'  => $post_id,
				'old_year' => $old_year,
				'new_year' => $new_year,
				'count'    => $count,
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'post_id'           => $post_id,
				'old_year'          => esc_html( $old_year ),
				'new_year'          => esc_html( $new_year ),
				'replacements_made' => $count,
			),
		);
	}

	/**
	 * Expand thin content by replacing a heading section.
	 *
	 * Locates a heading in the post content by text match and replaces
	 * the section (heading + content until next heading or end) with
	 * the provided new content. Creates a revision via wp_update_post().
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *   @type int    $post_id     Required. The post to update.
	 *   @type string $heading     Required. Text of the heading to find.
	 *   @type string $new_content Required. Replacement content for the section.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function action_expand_thin_content( array $params ) {
		$post_id = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new \WP_Error(
				'wp_claw_content_invalid_post',
				__( 'Invalid or missing post_id.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'wp_claw_content_post_not_found',
				__( 'Post not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		$heading = isset( $params['heading'] ) ? sanitize_text_field( wp_unslash( $params['heading'] ) ) : '';
		if ( '' === $heading ) {
			return new \WP_Error(
				'wp_claw_content_missing_heading',
				__( 'heading parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		if ( ! isset( $params['new_content'] ) ) {
			return new \WP_Error(
				'wp_claw_content_missing_content',
				__( 'new_content parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$new_section = wp_kses_post( wp_unslash( $params['new_content'] ) );
		$content     = $post->post_content;

		// Match the heading (h1-h6) and everything until the next heading of same or higher level, or end.
		$escaped_heading = preg_quote( $heading, '/' );
		$pattern         = '/(<h[1-6][^>]*>)\s*' . $escaped_heading . '\s*(<\/h[1-6]>)(.*?)(?=<h[1-6]|$)/is';

		$replaced = preg_replace( $pattern, '$1' . $heading . '$2' . "\n" . $new_section, $content, 1, $count );

		if ( 0 === $count || null === $replaced ) {
			return new \WP_Error(
				'wp_claw_content_heading_not_found',
				/* translators: %s: heading text */
				sprintf( __( 'Heading "%s" not found in post content.', 'claw-agent' ), esc_html( $heading ) ),
				array( 'status' => 404 )
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $replaced,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log(
				'Failed to expand thin content.',
				'error',
				array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				)
			);
			return $result;
		}

		wp_claw_log(
			'Content: expanded thin content section.',
			'info',
			array(
				'post_id' => $post_id,
				'heading' => $heading,
			)
		);

		return array(
			'success' => true,
			'data'    => array(
				'post_id' => $post_id,
				'heading' => esc_html( $heading ),
				'message' => __( 'Content section expanded successfully.', 'claw-agent' ),
			),
		);
	}
}
