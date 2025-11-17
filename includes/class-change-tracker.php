<?php
/**
 * Content Change Tracker
 *
 * Tracks content changes and determines if re-indexing is needed.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Change_Tracker
 *
 * Monitors content changes to avoid unnecessary re-indexing.
 */
class WPLLMSEO_Change_Tracker {

	/**
	 * Meta key for content hash.
	 */
	const CONTENT_HASH_KEY = '_wpllmseo_content_hash';

	/**
	 * Meta key for embedding hash.
	 */
	const EMBEDDING_HASH_KEY = '_wpllmseo_embedding_hash';

	/**
	 * Meta key for last indexed timestamp.
	 */
	const LAST_INDEXED_KEY = '_wpllmseo_last_indexed';

	/**
	 * Similarity threshold (0-1). Above this means content is similar enough.
	 */
	const SIMILARITY_THRESHOLD = 0.95;

	/**
	 * Initialize change tracker.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'track_content_change' ), 20, 2 );
	}

	/**
	 * Track content changes on post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function track_content_change( $post_id, $post ) {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only track published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Check if auto-indexing is enabled
		$settings = get_option( 'wpllmseo_settings', array() );
		if ( empty( $settings['auto_index'] ) ) {
			return;
		}

		$old_hash = get_post_meta( $post_id, self::CONTENT_HASH_KEY, true );
		$new_hash = self::generate_content_hash( $post );

		// If hashes are different, content has changed meaningfully.
		if ( $old_hash !== $new_hash ) {
			$should_reindex = true;

			// Check similarity threshold if settings allow.
			$settings = get_option( 'wpllmseo_settings', array() );
			$use_similarity = $settings['use_similarity_threshold'] ?? true;

			if ( $use_similarity && ! empty( $old_hash ) ) {
				$similarity = self::calculate_similarity( $old_hash, $new_hash );
				
				if ( $similarity >= self::SIMILARITY_THRESHOLD ) {
					$should_reindex = false;
				}
			}

			if ( $should_reindex ) {
				// Update hash.
				update_post_meta( $post_id, self::CONTENT_HASH_KEY, $new_hash );

				// Queue for re-indexing.
				self::queue_reindex( $post_id, 'content_changed' );

				// Log the change.
				$logger = new WPLLMSEO_Logger();
				$logger->info( 
					sprintf( 
						'Content changed for post %d (%s). Queued for re-indexing.', 
						$post_id, 
						$post->post_title 
					) 
				);
			} else {
				// Content changed but not enough to warrant re-indexing.
				$logger = new WPLLMSEO_Logger();
				$logger->debug( 
					sprintf( 
						'Content change for post %d (%s) below similarity threshold. Skipping re-index.', 
						$post_id, 
						$post->post_title 
					) 
				);
			}
		}
	}

	/**
	 * Generate content hash from post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Hash string.
	 */
	public static function generate_content_hash( $post ) {
		// Combine title, content, and excerpt for hash.
		$content_string = $post->post_title . "\n" . 
		                  strip_shortcodes( $post->post_content ) . "\n" . 
		                  $post->post_excerpt;

		// Normalize whitespace.
		$content_string = preg_replace( '/\s+/', ' ', trim( $content_string ) );

		// Generate hash.
		return hash( 'sha256', $content_string );
	}

	/**
	 * Calculate similarity between two hash strings.
	 *
	 * @param string $hash1 First hash.
	 * @param string $hash2 Second hash.
	 * @return float Similarity score (0-1).
	 */
	private static function calculate_similarity( $hash1, $hash2 ) {
		// Simple Hamming distance for hashes.
		$distance = 0;
		$length   = min( strlen( $hash1 ), strlen( $hash2 ) );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( $hash1[ $i ] !== $hash2[ $i ] ) {
				++$distance;
			}
		}

		// Convert to similarity (1 = identical, 0 = completely different).
		$similarity = 1 - ( $distance / $length );

		return $similarity;
	}

	/**
	 * Queue post for re-indexing.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Reason for re-indexing.
	 */
	private static function queue_reindex( $post_id, $reason ) {
		$queue = new WPLLMSEO_Queue();

		// Add chunking job.
		$queue->add(
			'chunk_post',
			array(
				'post_id' => $post_id,
				'reason'  => $reason,
			)
		);
	}

	/**
	 * Update embedding hash after successful embedding.
	 *
	 * @param int    $post_id        Post ID.
	 * @param string $embedding_hash Hash of the embedding.
	 */
	public static function update_embedding_hash( $post_id, $embedding_hash ) {
		update_post_meta( $post_id, self::EMBEDDING_HASH_KEY, $embedding_hash );
		update_post_meta( $post_id, self::LAST_INDEXED_KEY, current_time( 'timestamp' ) );
	}

	/**
	 * Get indexing status for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Status information.
	 */
	public static function get_indexing_status( $post_id ) {
		$content_hash   = get_post_meta( $post_id, self::CONTENT_HASH_KEY, true );
		$embedding_hash = get_post_meta( $post_id, self::EMBEDDING_HASH_KEY, true );
		$last_indexed   = get_post_meta( $post_id, self::LAST_INDEXED_KEY, true );

		$status = array(
			'has_content_hash'   => ! empty( $content_hash ),
			'has_embedding'      => ! empty( $embedding_hash ),
			'last_indexed'       => $last_indexed ? (int) $last_indexed : null,
			'needs_reindex'      => false,
			'status_label'       => '',
		);

		if ( empty( $embedding_hash ) ) {
			$status['needs_reindex'] = true;
			$status['status_label']  = __( 'Never indexed', 'wpllmseo' );
		} elseif ( empty( $content_hash ) ) {
			$status['needs_reindex'] = true;
			$status['status_label']  = __( 'Needs re-index (hash missing)', 'wpllmseo' );
		} else {
			$post        = get_post( $post_id );
			$current_hash = self::generate_content_hash( $post );

			if ( $current_hash !== $content_hash ) {
				$status['needs_reindex'] = true;
				$status['status_label']  = __( 'Content changed', 'wpllmseo' );
			} else {
				$status['status_label'] = __( 'Up to date', 'wpllmseo' );
			}
		}

		return $status;
	}

	/**
	 * Get posts that need re-indexing.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of post IDs.
	 */
	public static function get_posts_needing_reindex( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'post_type'   => array( 'post', 'page' ),
			'post_status' => 'publish',
			'limit'       => 100,
		);

		$args = wp_parse_args( $args, $defaults );

		// Get all published posts.
		$query = new WP_Query(
			array(
				'post_type'      => $args['post_type'],
				'post_status'    => $args['post_status'],
				'posts_per_page' => $args['limit'],
				'fields'         => 'ids',
			)
		);

		$post_ids      = $query->posts;
		$needs_reindex = array();

		foreach ( $post_ids as $post_id ) {
			$status = self::get_indexing_status( $post_id );
			
			if ( $status['needs_reindex'] ) {
				$needs_reindex[] = $post_id;
			}
		}

		return $needs_reindex;
	}
}
