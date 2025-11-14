<?php
/**
 * MCP Ability Handlers
 *
 * Implements MCP ability handlers for safe automation of snippet generation,
 * reindexing, job status queries, and post summary previews.
 *
 * @package WP_LLM_SEO_Indexing
 * @since 1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_MCP_Handlers
 *
 * Static handlers for MCP abilities.
 */
class WPLLMSEO_MCP_Handlers {

	/**
	 * Handle fetch_post_summary ability
	 *
	 * Read-only ability to fetch post summary with SEO meta.
	 *
	 * @param array $args    Ability arguments.
	 * @param array $context MCP context with auth info.
	 * @return array
	 */
	public static function handle_fetch_post_summary( $args, $context ) {
		// Log the request
		WPLLMSEO_MCP_Audit::log_request( 'llmseo.fetch_post_summary', $args, $context );

		// Validate input
		$post_id = intval( $args['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return array( 'error' => 'invalid_post_id' );
		}

		// Check if post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}

		// Check LLMs.txt restrictions
		if ( WPLLMSEO_MCP_LLMsTxt::should_respect() ) {
			$permalink = get_permalink( $post_id );
			if ( WPLLMSEO_MCP_LLMsTxt::is_disallowed( $permalink ) ) {
				return array( 'error' => 'disallowed_by_llms_txt' );
			}
		}

		// Get SEO meta from Yoast/Rank Math
		$seo_meta = self::get_primary_seo_meta( $post_id );

		// Get existing AI snippet
		$existing_snippet = get_post_meta( $post_id, '_wpllmseo_ai_snippet', true );

		// Get embedding hash
		$embedding_hash = get_post_meta( $post_id, '_wpllmseo_content_hash', true );

		// Get cleaned text sample
		$cleaned_text = self::extract_clean_text_sample( $post_id, 800 );

		return array(
			'post_id'              => $post_id,
			'title'                => get_the_title( $post_id ),
			'yoast_meta'           => $seo_meta['yoast'] ?? null,
			'rankmath_meta'        => $seo_meta['rankmath'] ?? null,
			'existing_ai_snippet'  => $existing_snippet ?: null,
			'last_embedding_hash'  => $embedding_hash ?: null,
			'cleaned_text_sample'  => $cleaned_text,
		);
	}

	/**
	 * Handle generate_snippet_preview ability
	 *
	 * Generate AI snippet preview with optional dry-run mode.
	 *
	 * @param array $args    Ability arguments.
	 * @param array $context MCP context with auth info.
	 * @return array
	 */
	public static function handle_generate_snippet_preview( $args, $context ) {
		// Log the request
		WPLLMSEO_MCP_Audit::log_request( 'llmseo.generate_snippet_preview', $args, $context );

		// Validate input
		$post_id    = intval( $args['post_id'] ?? 0 );
		$model      = sanitize_text_field( $args['model'] ?? '' );
		$max_tokens = intval( $args['max_tokens'] ?? 150 );
		$dry_run    = (bool) ( $args['dry_run'] ?? false );

		if ( ! $post_id ) {
			return array( 'error' => 'invalid_post_id' );
		}

		// Check post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}

		// Check authorization
		if ( ! WPLLMSEO_MCP_Auth::can_edit_post( $context, $post_id ) ) {
			return array( 'error' => 'unauthorized' );
		}

		// Dry run mode - generate preview without persisting
		if ( $dry_run ) {
			try {
				$snippet = self::generate_snippet_sync( $post_id, $model, $max_tokens );

				return array(
					'job_id'          => null,
					'preview_snippet' => $snippet,
					'queued'          => false,
				);
			} catch ( Exception $e ) {
				return array( 'error' => 'generation_failed', 'message' => $e->getMessage() );
			}
		}

		// Queue async job
		$job_id = self::queue_snippet_generation( $post_id, $model, $max_tokens );

		return array(
			'job_id'          => $job_id,
			'preview_snippet' => null,
			'queued'          => true,
		);
	}

	/**
	 * Handle start_bulk_snippet_job ability
	 *
	 * Start bulk snippet generation job with filters and settings.
	 *
	 * @param array $args    Ability arguments.
	 * @param array $context MCP context with auth info.
	 * @return array
	 */
	public static function handle_start_bulk_snippet_job( $args, $context ) {
		// Log the request
		WPLLMSEO_MCP_Audit::log_request( 'llmseo.start_bulk_snippet_job', $args, $context );

		// Check authorization (requires manage_options)
		if ( ! WPLLMSEO_MCP_Auth::has_capability( $context, 'manage_options' ) ) {
			return array( 'error' => 'unauthorized' );
		}

		// Parse arguments
		$filters              = $args['filters'] ?? array();
		$skip_existing        = (bool) ( $args['skip_existing'] ?? true );
		$batch_size           = intval( $args['batch_size'] ?? 10 );
		$rate_limit_per_minute = intval( $args['rate_limit_per_minute'] ?? 10 );
		$respect_llms_txt     = (bool) ( $args['respect_llms_txt'] ?? true );

		// Validate batch size
		if ( $batch_size < 1 || $batch_size > 100 ) {
			return array( 'error' => 'invalid_batch_size', 'message' => 'Batch size must be between 1 and 100' );
		}

		// Get candidate posts
		$candidate_posts = self::get_candidate_posts( $filters );

		// Apply skip_existing filter
		$skipped_count = 0;
		if ( $skip_existing ) {
			$filtered_posts = array();
			foreach ( $candidate_posts as $post_id ) {
				if ( ! get_post_meta( $post_id, '_wpllmseo_ai_snippet', true ) ) {
					$filtered_posts[] = $post_id;
				} else {
					$skipped_count++;
				}
			}
			$candidate_posts = $filtered_posts;
		}

		// Apply LLMs.txt filter
		if ( $respect_llms_txt && WPLLMSEO_MCP_LLMsTxt::should_respect() ) {
			$filtered_posts = array();
			foreach ( $candidate_posts as $post_id ) {
				$permalink = get_permalink( $post_id );
				if ( ! WPLLMSEO_MCP_LLMsTxt::is_disallowed( $permalink ) ) {
					$filtered_posts[] = $post_id;
				} else {
					$skipped_count++;
				}
			}
			$candidate_posts = $filtered_posts;
		}

		// Create bulk job
		$job_id = WPLLMSEO_Bulk_Snippet_Generator::create_bulk_job( $candidate_posts, array(
			'batch_size'            => $batch_size,
			'rate_limit_per_minute' => $rate_limit_per_minute,
		) );

		// Log job creation
		WPLLMSEO_MCP_Audit::log_job_created( $job_id, 'bulk_snippet', count( $candidate_posts ), $context );

		return array(
			'job_id'                 => $job_id,
			'queued_count'           => count( $candidate_posts ),
			'skipped_count_estimate' => $skipped_count,
		);
	}

	/**
	 * Handle save_snippet ability
	 *
	 * Save AI snippet to post meta.
	 *
	 * @param array $args    Ability arguments.
	 * @param array $context MCP context with auth info.
	 * @return array
	 */
	public static function handle_save_snippet( $args, $context ) {
		// Log the request
		WPLLMSEO_MCP_Audit::log_request( 'llmseo.save_snippet', $args, $context );

		// Validate input
		$post_id      = intval( $args['post_id'] ?? 0 );
		$snippet_text = wp_kses_post( $args['snippet_text'] ?? '' );
		$save_as      = sanitize_key( $args['save_as'] ?? '_wpllmseo_ai_snippet' );

		if ( ! $post_id ) {
			return array( 'error' => 'invalid_post_id' );
		}

		if ( empty( $snippet_text ) ) {
			return array( 'error' => 'empty_snippet' );
		}

		// Check post exists
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'post_not_found' );
		}

		// Check authorization
		if ( ! WPLLMSEO_MCP_Auth::can_edit_post( $context, $post_id ) ) {
			return array( 'error' => 'unauthorized' );
		}

		// Validate meta key
		if ( ! str_starts_with( $save_as, '_wpllmseo_' ) ) {
			$save_as = '_wpllmseo_' . $save_as;
		}

		// Save snippet
		update_post_meta( $post_id, $save_as, $snippet_text );

		// Update timestamp
		update_post_meta( $post_id, '_wpllmseo_snippet_updated', current_time( 'mysql' ) );

		// Fire action hook
		do_action( 'wpllmseo_after_save_snippet', $post_id, $snippet_text, $save_as, $context );

		return array(
			'success'        => true,
			'post_id'        => $post_id,
			'saved_meta_key' => $save_as,
		);
	}

	/**
	 * Handle query_job_status ability
	 *
	 * Query background job status and progress.
	 *
	 * @param array $args    Ability arguments.
	 * @param array $context MCP context with auth info.
	 * @return array
	 */
	public static function handle_query_job_status( $args, $context ) {
		// Log the request
		WPLLMSEO_MCP_Audit::log_request( 'llmseo.query_job_status', $args, $context );

		// Validate input
		$job_id = sanitize_text_field( $args['job_id'] ?? '' );

		if ( empty( $job_id ) ) {
			return array( 'error' => 'invalid_job_id' );
		}

		// Get job from queue
		global $wpdb;
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpllmseo_jobs WHERE id = %s",
				$job_id
			)
		);

		if ( ! $job ) {
			return array( 'error' => 'job_not_found' );
		}

		// Parse job payload
		$payload = json_decode( $job->payload, true );

		// Get sample outputs (max 5)
		$sample_outputs = array();
		if ( isset( $payload['processed_posts'] ) && is_array( $payload['processed_posts'] ) ) {
			$sample_posts = array_slice( $payload['processed_posts'], 0, 5 );
			foreach ( $sample_posts as $post_id ) {
				$snippet = get_post_meta( $post_id, '_wpllmseo_ai_snippet', true );
				if ( $snippet ) {
					$sample_outputs[] = array(
						'post_id' => $post_id,
						'title'   => get_the_title( $post_id ),
						'snippet' => mb_substr( $snippet, 0, 100 ) . '...',
					);
				}
			}
		}

		// Get errors
		$errors = isset( $payload['errors'] ) && is_array( $payload['errors'] ) ? $payload['errors'] : array();

		return array(
			'job_id'         => $job_id,
			'status'         => $job->status,
			'processed'      => intval( $payload['processed'] ?? 0 ),
			'skipped'        => intval( $payload['skipped'] ?? 0 ),
			'errors'         => array_slice( $errors, 0, 10 ), // Max 10 errors
			'sample_outputs' => $sample_outputs,
		);
	}

	/**
	 * Get primary SEO meta from Yoast/Rank Math
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function get_primary_seo_meta( $post_id ) {
		$meta = array(
			'yoast'    => null,
			'rankmath' => null,
		);

		// Get Yoast meta if available
		if ( WPLLMSEO_SEO_Compat::get_active_seo_plugin() === 'yoast' ) {
			$meta['yoast'] = array(
				'title'       => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
				'description' => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
				'focus_kw'    => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			);
		}

		// Get Rank Math meta if available
		if ( WPLLMSEO_SEO_Compat::get_active_seo_plugin() === 'rankmath' ) {
			$meta['rankmath'] = array(
				'title'       => get_post_meta( $post_id, 'rank_math_title', true ),
				'description' => get_post_meta( $post_id, 'rank_math_description', true ),
				'focus_kw'    => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			);
		}

		return $meta;
	}

	/**
	 * Extract clean text sample from post
	 *
	 * @param int $post_id     Post ID.
	 * @param int $max_length  Maximum length.
	 * @return string
	 */
	private static function extract_clean_text_sample( $post_id, $max_length = 800 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		// Get content and strip tags
		$content = wp_strip_all_tags( $post->post_content );

		// Remove shortcodes
		$content = strip_shortcodes( $content );

		// Normalize whitespace
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// Truncate to max length
		if ( mb_strlen( $content ) > $max_length ) {
			$content = mb_substr( $content, 0, $max_length ) . '...';
		}

		return $content;
	}

	/**
	 * Generate snippet synchronously (for dry-run)
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $model       Model name.
	 * @param int    $max_tokens  Max tokens.
	 * @return string
	 * @throws Exception If generation fails.
	 */
	private static function generate_snippet_sync( $post_id, $model = '', $max_tokens = 150 ) {
		// Use existing snippet generator
		$generator = new WPLLMSEO_Snippet_Generator();

		// Get post content
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( 'Post not found' );
		}

		// Generate snippet (this may call external API)
		$snippet = $generator->generate( $post_id, array(
			'model'      => $model ?: WPLLMSEO_GEMINI_MODEL,
			'max_tokens' => $max_tokens,
		) );

		if ( empty( $snippet ) ) {
			throw new Exception( 'Failed to generate snippet' );
		}

		return $snippet;
	}

	/**
	 * Queue snippet generation job
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $model       Model name.
	 * @param int    $max_tokens  Max tokens.
	 * @return string Job ID.
	 */
	private static function queue_snippet_generation( $post_id, $model = '', $max_tokens = 150 ) {
		$queue = new WPLLMSEO_Queue();

		// Create job
		$job_id = $queue->add_job(
			'generate_snippet',
			array(
				'post_id'    => $post_id,
				'model'      => $model ?: WPLLMSEO_GEMINI_MODEL,
				'max_tokens' => $max_tokens,
			)
		);

		return (string) $job_id;
	}

	/**
	 * Get candidate posts for bulk job
	 *
	 * @param array $filters Filters array.
	 * @return array Post IDs.
	 */
	private static function get_candidate_posts( $filters ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		// Apply post_type filter
		if ( isset( $filters['post_type'] ) ) {
			$args['post_type'] = $filters['post_type'];
		}

		// Apply taxonomy filters
		if ( isset( $filters['taxonomies'] ) && is_array( $filters['taxonomies'] ) ) {
			$tax_query = array();
			foreach ( $filters['taxonomies'] as $taxonomy => $terms ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
				);
			}
			if ( ! empty( $tax_query ) ) {
				$args['tax_query'] = $tax_query;
			}
		}

		// Apply date range filter
		if ( isset( $filters['date_range'] ) ) {
			$args['date_query'] = array(
				array(
					'after'     => $filters['date_range']['after'] ?? '1 year ago',
					'before'    => $filters['date_range']['before'] ?? 'now',
					'inclusive' => true,
				),
			);
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}
}
