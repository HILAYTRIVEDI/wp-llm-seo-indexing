<?php
/**
 * Bulk Snippet Generator
 *
 * Settings-driven bulk AI snippet generation with skip logic and LLMs.txt support.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Bulk_Snippet_Generator
 *
 * Manages bulk snippet generation jobs.
 */
class WPLLMSEO_Bulk_Snippet_Generator {

	/**
	 * Meta key for AI snippet.
	 */
	const AI_SNIPPET_KEY = '_wpllmseo_ai_snippet';

	/**
	 * Meta key for snippet generated timestamp.
	 */
	const SNIPPET_TIMESTAMP_KEY = '_wpllmseo_snippet_generated';

	/**
	 * Option key for bulk job status.
	 */
	const JOB_STATUS_KEY = 'wpllmseo_bulk_snippet_job';

	/**
	 * Initialize bulk generator.
	 */
	public static function init() {
		add_action( 'wp_ajax_wpllmseo_start_bulk_snippets', array( __CLASS__, 'ajax_start_bulk_job' ) );
		add_action( 'wp_ajax_wpllmseo_bulk_snippet_status', array( __CLASS__, 'ajax_get_job_status' ) );
		add_action( 'wpllmseo_process_bulk_snippets', array( __CLASS__, 'process_bulk_batch' ) );
	}

	/**
	 * Start bulk snippet generation job (AJAX handler).
	 */
	public static function ajax_start_bulk_job() {
		check_ajax_referer( 'wp_rest', 'nonce' );

		if ( ! WPLLMSEO_Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$skip_existing   = isset( $_POST['skip_existing'] ) && '1' === $_POST['skip_existing'];
		$post_types      = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array( 'post', 'page' );
		$batch_size      = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10;
		$dry_run         = isset( $_POST['dry_run'] ) && '1' === $_POST['dry_run'];

		$job_id = self::start_bulk_job( $post_types, $skip_existing, $batch_size, $dry_run );

		if ( $job_id ) {
			wp_send_json_success(
				array(
					'job_id'  => $job_id,
					'message' => $dry_run ? __( 'Dry run completed.', 'wpllmseo' ) : __( 'Bulk snippet generation started.', 'wpllmseo' ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to start bulk job.', 'wpllmseo' ) ) );
		}
	}

	/**
	 * Get job status (AJAX handler).
	 */
	public static function ajax_get_job_status() {
		check_ajax_referer( 'wp_rest', 'nonce' );

		if ( ! WPLLMSEO_Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$status = self::get_job_status( $job_id );

		wp_send_json_success( $status );
	}

	/**
	 * Start a bulk snippet generation job.
	 *
	 * @param array $post_types     Post types to process.
	 * @param bool  $skip_existing  Skip posts with existing snippets.
	 * @param int   $batch_size     Number of posts per batch.
	 * @param bool  $dry_run        Preview mode.
	 * @return string|false Job ID or false on failure.
	 */
	public static function start_bulk_job( $post_types, $skip_existing = true, $batch_size = 10, $dry_run = false ) {
		// Get posts to process.
		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query    = new WP_Query( $query_args );
		$post_ids = $query->posts;

		// Apply LLMs.txt restrictions.
		$post_ids = self::apply_llmstxt_restrictions( $post_ids );

		// Filter posts based on skip_existing.
		if ( $skip_existing ) {
			$post_ids = self::filter_posts_with_snippets( $post_ids );
		}

		$total_posts = count( $post_ids );

		if ( 0 === $total_posts ) {
			return false;
		}

		$job_id = 'job_' . time() . '_' . wp_rand( 1000, 9999 );

		$job_data = array(
			'job_id'         => $job_id,
			'status'         => $dry_run ? 'dry_run_complete' : 'running',
			'total'          => $total_posts,
			'processed'      => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'post_ids'       => $post_ids,
			'skip_existing'  => $skip_existing,
			'batch_size'     => $batch_size,
			'started_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
			'dry_run'        => $dry_run,
			'error_messages' => array(),
		);

		// Save job status.
		update_option( self::JOB_STATUS_KEY . '_' . $job_id, $job_data );

		if ( ! $dry_run ) {
			// Schedule background processing.
			wp_schedule_single_event( time(), 'wpllmseo_process_bulk_snippets', array( $job_id ) );
		}

		return $job_id;
	}

	/**
	 * Process a batch of posts.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function process_bulk_batch( $job_id ) {
		$job_data = get_option( self::JOB_STATUS_KEY . '_' . $job_id );

		if ( ! $job_data || 'running' !== $job_data['status'] ) {
			return;
		}

		$batch_size = $job_data['batch_size'];
		$post_ids   = array_slice( $job_data['post_ids'], $job_data['processed'], $batch_size );

		foreach ( $post_ids as $post_id ) {
			$result = self::generate_snippet_for_post( $post_id );

			if ( is_wp_error( $result ) ) {
				++$job_data['errors'];
				$job_data['error_messages'][] = sprintf(
					'Post %d: %s',
					$post_id,
					$result->get_error_message()
				);
			} elseif ( 'skipped' === $result ) {
				++$job_data['skipped'];
			}

			++$job_data['processed'];
		}

		$job_data['updated_at'] = current_time( 'mysql' );

		// Check if job is complete.
		if ( $job_data['processed'] >= $job_data['total'] ) {
			$job_data['status']       = 'completed';
			$job_data['completed_at'] = current_time( 'mysql' );
		} else {
			// Schedule next batch.
			wp_schedule_single_event( time() + 2, 'wpllmseo_process_bulk_snippets', array( $job_id ) );
		}

		update_option( self::JOB_STATUS_KEY . '_' . $job_id, $job_data );
	}

	/**
	 * Generate snippet for a single post (public method).
	 *
	 * @param int $post_id Post ID.
	 * @param bool $force Force regeneration even if snippet exists.
	 * @return array Result array with success status and snippet data.
	 */
	public static function process_single_post( $post_id, $force = true ) {
		$post = get_post( $post_id );
		
		if ( ! $post ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid post ID.', 'wpllmseo' ),
			);
		}

		// Generate snippet
		$snippet_text = self::create_ai_snippet( $post );

		if ( is_wp_error( $snippet_text ) ) {
			return array(
				'success' => false,
				'error'   => $snippet_text->get_error_message(),
			);
		}

		// Save to snippets table
		$snippets = new WPLLMSEO_Snippets();
		$snippet_id = $snippets->create_snippet( $post_id, $snippet_text );

		if ( ! $snippet_id ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to save snippet to database.', 'wpllmseo' ),
			);
		}

		// Save to post meta
		update_post_meta( $post_id, self::AI_SNIPPET_KEY, $snippet_text );
		update_post_meta( $post_id, self::SNIPPET_TIMESTAMP_KEY, current_time( 'timestamp' ) );
		
		// Update content hash and last indexed timestamp
		WPLLMSEO_Change_Tracker::update_content_hash( $post_id );
		WPLLMSEO_Change_Tracker::update_last_indexed( $post_id );

		return array(
			'success'    => true,
			'snippet_id' => $snippet_id,
			'snippet'    => $snippet_text,
		);
	}

	/**
	 * Generate snippet for a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|WP_Error 'success', 'skipped', or WP_Error.
	 */
	private static function generate_snippet_for_post( $post_id ) {
		// Check if snippet already exists.
		$existing_snippet = get_post_meta( $post_id, self::AI_SNIPPET_KEY, true );
		
		if ( ! empty( $existing_snippet ) ) {
			return 'skipped';
		}

		// Get or generate embedding.
		global $wpdb;
		$snippet_table = $wpdb->prefix . 'llmseo_snippets';
		
		// Check if we already have a snippet in the database.
		$db_snippet = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT content FROM {$snippet_table} WHERE post_id = %d ORDER BY created_at DESC LIMIT 1",
				$post_id
			)
		);

		if ( $db_snippet ) {
			// Use existing snippet from database.
			$snippet_text = $db_snippet->content;
		} else {
			// Generate new snippet.
			$post = get_post( $post_id );
			$snippet_text = self::create_ai_snippet( $post );

			if ( is_wp_error( $snippet_text ) ) {
				return $snippet_text;
			}
		}

		// Save to post meta.
		update_post_meta( $post_id, self::AI_SNIPPET_KEY, $snippet_text );
		update_post_meta( $post_id, self::SNIPPET_TIMESTAMP_KEY, current_time( 'timestamp' ) );

		return 'success';
	}

	/**
	 * Create AI snippet from post content.
	 *
	 * @param WP_Post $post Post object.
	 * @return string|WP_Error Snippet text or error.
	 */
	private static function create_ai_snippet( $post ) {
		$content = wp_strip_all_tags( $post->post_content );
		$content = wp_trim_words( $content, 50 );

		// In a real implementation, this would call the Gemini API.
		// For now, create a simple snippet from the content.
		$snippet = sprintf(
			'%s - %s',
			$post->post_title,
			$content
		);

		return $snippet;
	}

	/**
	 * Filter posts that already have AI snippets.
	 *
	 * @param array $post_ids Array of post IDs.
	 * @return array Filtered post IDs.
	 */
	private static function filter_posts_with_snippets( $post_ids ) {
		$filtered = array();

		foreach ( $post_ids as $post_id ) {
			$existing = get_post_meta( $post_id, self::AI_SNIPPET_KEY, true );
			
			if ( empty( $existing ) ) {
				$filtered[] = $post_id;
			}
		}

		return $filtered;
	}

	/**
	 * Apply LLMs.txt restrictions.
	 *
	 * @param array $post_ids Array of post IDs.
	 * @return array Filtered post IDs.
	 */
	private static function apply_llmstxt_restrictions( $post_ids ) {
		$llmstxt_config = self::parse_llmstxt();

		if ( empty( $llmstxt_config['excluded_paths'] ) ) {
			return $post_ids;
		}

		$filtered = array();

		foreach ( $post_ids as $post_id ) {
			$permalink = get_permalink( $post_id );
			$path      = wp_parse_url( $permalink, PHP_URL_PATH );

			$excluded = false;
			foreach ( $llmstxt_config['excluded_paths'] as $excluded_path ) {
				if ( 0 === strpos( $path, $excluded_path ) ) {
					$excluded = true;
					break;
				}
			}

			if ( ! $excluded ) {
				$filtered[] = $post_id;
			}
		}

		return $filtered;
	}

	/**
	 * Parse LLMs.txt file from site root.
	 *
	 * @return array Configuration array.
	 */
	private static function parse_llmstxt() {
		$llmstxt_path = ABSPATH . 'LLMs.txt';

		if ( ! file_exists( $llmstxt_path ) ) {
			return array(
				'excluded_paths' => array(),
			);
		}

		$content = file_get_contents( $llmstxt_path );
		$lines   = explode( "\n", $content );

		$config = array(
			'excluded_paths' => array(),
		);

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip comments and empty lines.
			if ( empty( $line ) || 0 === strpos( $line, '#' ) ) {
				continue;
			}

			// Parse Disallow directives.
			if ( 0 === strpos( $line, 'Disallow:' ) ) {
				$path = trim( substr( $line, 9 ) );
				if ( ! empty( $path ) ) {
					$config['excluded_paths'][] = $path;
				}
			}
		}

		// Allow admin override via filter.
		return apply_filters( 'wpllmseo_llmstxt_config', $config );
	}

	/**
	 * Get job status.
	 *
	 * @param string $job_id Job ID.
	 * @return array Status data.
	 */
	public static function get_job_status( $job_id ) {
		$job_data = get_option( self::JOB_STATUS_KEY . '_' . $job_id );

		if ( ! $job_data ) {
			return array(
				'found'  => false,
				'status' => 'not_found',
			);
		}

		$eta_minutes = 0;
		if ( 'running' === $job_data['status'] && $job_data['processed'] > 0 ) {
			$elapsed  = strtotime( $job_data['updated_at'] ) - strtotime( $job_data['started_at'] );
			$rate     = $job_data['processed'] / max( 1, $elapsed );
			$remaining = $job_data['total'] - $job_data['processed'];
			$eta_minutes = (int) ( $remaining / max( 1, $rate ) / 60 );
		}

		return array(
			'found'          => true,
			'job_id'         => $job_data['job_id'],
			'status'         => $job_data['status'],
			'total'          => $job_data['total'],
			'processed'      => $job_data['processed'],
			'skipped'        => $job_data['skipped'],
			'errors'         => $job_data['errors'],
			'started_at'     => $job_data['started_at'],
			'updated_at'     => $job_data['updated_at'],
			'eta_minutes'    => $eta_minutes,
			'error_messages' => $job_data['error_messages'] ?? array(),
		);
	}
}
