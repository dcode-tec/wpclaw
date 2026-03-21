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
		return [
			'create_draft_post',
			'update_post_content',
			'create_page',
			'translate_post',
			'generate_excerpt',
		];
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

			default:
				return new \WP_Error(
					'wp_claw_content_unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown Content action: %s', 'wp-claw' ),
						esc_html( $action )
					),
					[ 'status' => 400 ]
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

		$post_counts = [
			'publish' => (int) ( $counts->publish ?? 0 ),
			'draft'   => (int) ( $counts->draft ?? 0 ),
			'pending' => (int) ( $counts->pending ?? 0 ),
			'future'  => (int) ( $counts->future ?? 0 ),
			'trash'   => (int) ( $counts->trash ?? 0 ),
		];

		// Posts modified in the last 7 days.
		$recent = new \WP_Query( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'date_query'     => [
				[
					'column' => 'post_modified_gmt',
					'after'  => '7 days ago',
				],
			],
			'fields'         => 'ids',
		] );

		$recently_modified = [];
		if ( $recent->have_posts() ) {
			foreach ( $recent->posts as $post_id ) {
				$post_id = absint( $post_id );
				$post    = get_post( $post_id );
				if ( $post ) {
					$recently_modified[] = [
						'post_id'      => $post_id,
						'title'        => esc_html( get_the_title( $post_id ) ),
						'status'       => esc_html( $post->post_status ),
						'post_type'    => esc_html( $post->post_type ),
						'modified'     => esc_html( $post->post_modified_gmt ),
					];
				}
			}
		}

		wp_reset_postdata();

		return [
			'post_counts'       => $post_counts,
			'recently_modified' => $recently_modified,
		];
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
				__( 'title parameter is required.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$content = isset( $params['content'] ) ? wp_kses_post( wp_unslash( $params['content'] ) ) : '';

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'post',
		];

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
			wp_claw_log( 'Failed to create draft post.', 'error', [ 'error' => $post_id->get_error_message() ] );
			return $post_id;
		}

		wp_claw_log( 'Draft post created.', 'info', [ 'post_id' => $post_id ] );

		return [
			'success' => true,
			'data'    => [
				'post_id'   => $post_id,
				'title'     => esc_html( $title ),
				'edit_url'  => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
			],
		];
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
				__( 'Invalid or missing post_id.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'wp_claw_content_post_not_found',
				__( 'Post not found.', 'wp-claw' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! isset( $params['content'] ) ) {
			return new \WP_Error(
				'wp_claw_content_missing_content',
				__( 'content parameter is required.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$content = wp_kses_post( wp_unslash( $params['content'] ) );

		$result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $content,
			],
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_claw_log(
				'Failed to update post content.',
				'error',
				[ 'post_id' => $post_id, 'error' => $result->get_error_message() ]
			);
			return $result;
		}

		wp_claw_log( 'Post content updated.', 'info', [ 'post_id' => $post_id ] );

		return [
			'success' => true,
			'data'    => [
				'post_id'    => $post_id,
				'post_type'  => esc_html( $post->post_type ),
				'post_status' => esc_html( $post->post_status ),
			],
		];
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
				__( 'title parameter is required.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$content   = isset( $params['content'] ) ? wp_kses_post( wp_unslash( $params['content'] ) ) : '';
		$parent_id = isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : 0;

		// Validate parent page if provided.
		if ( $parent_id && ! get_post( $parent_id ) ) {
			return new \WP_Error(
				'wp_claw_content_invalid_parent',
				__( 'parent_id does not refer to an existing post.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_parent'  => $parent_id,
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			wp_claw_log( 'Failed to create page.', 'error', [ 'error' => $post_id->get_error_message() ] );
			return $post_id;
		}

		wp_claw_log( 'Draft page created.', 'info', [ 'post_id' => $post_id ] );

		return [
			'success' => true,
			'data'    => [
				'post_id'   => $post_id,
				'title'     => esc_html( $title ),
				'post_type' => 'page',
				'edit_url'  => esc_url( get_edit_post_link( $post_id, 'raw' ) ),
			],
		];
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
				__( 'Invalid or missing source_post_id.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$locale = isset( $params['locale'] ) ? sanitize_text_field( wp_unslash( $params['locale'] ) ) : '';
		if ( '' === $locale ) {
			return new \WP_Error(
				'wp_claw_content_missing_locale',
				__( 'locale parameter is required (e.g. fr_FR).', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( wp_unslash( $params['title'] ) ) : '';
		if ( '' === $title ) {
			return new \WP_Error(
				'wp_claw_content_missing_title',
				__( 'title parameter is required.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$content = isset( $params['content'] ) ? wp_kses_post( wp_unslash( $params['content'] ) ) : '';

		$source_post = get_post( $source_post_id );

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => $source_post ? $source_post->post_type : 'post',
		];

		$new_post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $new_post_id ) ) {
			wp_claw_log(
				'Failed to create translated post.',
				'error',
				[ 'error' => $new_post_id->get_error_message(), 'source_post_id' => $source_post_id ]
			);
			return $new_post_id;
		}

		update_post_meta( $new_post_id, '_wp_claw_translation_source', $source_post_id );
		update_post_meta( $new_post_id, '_wp_claw_translation_locale', $locale );

		wp_claw_log(
			'Translated post draft created.',
			'info',
			[ 'post_id' => $new_post_id, 'source_post_id' => $source_post_id, 'locale' => $locale ]
		);

		return [
			'success' => true,
			'data'    => [
				'post_id'        => $new_post_id,
				'source_post_id' => $source_post_id,
				'locale'         => esc_html( $locale ),
				'title'          => esc_html( $title ),
				'edit_url'       => esc_url( get_edit_post_link( $new_post_id, 'raw' ) ),
			],
		];
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
					__( 'Post not found.', 'wp-claw' ),
					[ 'status' => 404 ]
				);
			}
			$raw_text = wp_strip_all_tags( $post->post_content );
		} else {
			return new \WP_Error(
				'wp_claw_content_missing_source',
				__( 'Either post_id or text parameter is required.', 'wp-claw' ),
				[ 'status' => 422 ]
			);
		}

		$excerpt = wp_trim_words( $raw_text, $length, $more );

		// If a post_id was provided, optionally persist the excerpt.
		if ( ! empty( $params['post_id'] ) && ! empty( $params['save'] ) ) {
			$post_id = absint( $params['post_id'] );
			wp_update_post( [
				'ID'           => $post_id,
				'post_excerpt' => $excerpt,
			] );
			wp_claw_log( 'Post excerpt saved.', 'info', [ 'post_id' => $post_id ] );
		}

		return [
			'success' => true,
			'data'    => [
				'excerpt'    => $excerpt,
				'word_count' => $length,
			],
		];
	}
}
