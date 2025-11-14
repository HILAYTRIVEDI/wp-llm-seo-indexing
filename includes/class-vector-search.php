<?php
/**
 * Vector Search Engine
 *
 * Handles candidate retrieval and vector similarity calculations.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Vector_Search
 *
 * Candidate retrieval and similarity scoring.
 */
class WPLLMSEO_Vector_Search {

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Queue instance
	 *
	 * @var WPLLMSEO_Queue
	 */
	private $queue;

	/**
	 * Common English stopwords
	 *
	 * @var array
	 */
	private $stopwords = array(
		'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from',
		'has', 'he', 'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the',
		'to', 'was', 'will', 'with', 'what', 'where', 'when', 'who', 'how',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger = new WPLLMSEO_Logger();
		$this->queue  = new WPLLMSEO_Queue();
	}

	/**
	 * Get candidates from chunks and snippets
	 *
	 * @param string $query      Query text for keyword extraction.
	 * @param int    $max_limit  Maximum candidates to return.
	 * @return array Candidate array.
	 */
	public function get_candidates( $query, $max_limit = 200 ) {
		global $wpdb;

		// Extract keywords from query.
		$keywords = $this->extract_keywords( $query );

		if ( empty( $keywords ) ) {
			return array();
		}

		// Build SQL for keyword matching.
		$keyword_conditions = array();
		foreach ( $keywords as $keyword ) {
			$keyword_conditions[] = $wpdb->prepare( 'chunk_text LIKE %s', '%' . $wpdb->esc_like( $keyword ) . '%' );
		}
		$keyword_sql = implode( ' OR ', $keyword_conditions );

		// Query chunks table.
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';
		$chunks_query = "SELECT 
			id,
			post_id,
			chunk_text as text,
			embedding,
			0 as is_snippet,
			created_at
		FROM {$chunks_table}
		WHERE ({$keyword_sql})
		  AND embedding IS NOT NULL
		  AND embedding != ''
		LIMIT %d";

		$chunks = $wpdb->get_results( $wpdb->prepare( $chunks_query, $max_limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Get unique post IDs from chunks.
		$post_ids = array_unique( array_map( function( $chunk ) {
			return absint( $chunk->post_id );
		}, $chunks ) );

		$snippets = array();

		// Query snippets table for these posts.
		if ( ! empty( $post_ids ) ) {
			$snippets_table = $wpdb->prefix . 'wpllmseo_snippets';
			$post_ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

			$snippets_query = "SELECT 
				id,
				post_id,
				snippet_text as text,
				embedding,
				1 as is_snippet,
				created_at
			FROM {$snippets_table}
			WHERE post_id IN ({$post_ids_placeholder})
			  AND is_preferred = 1
			  AND embedding IS NOT NULL
			  AND embedding != ''";

			$snippets = $wpdb->get_results( $wpdb->prepare( $snippets_query, ...$post_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Merge chunks and snippets.
		$candidates = array_merge( $snippets, $chunks );

		// Limit to max_limit.
		if ( count( $candidates ) > $max_limit ) {
			$candidates = array_slice( $candidates, 0, $max_limit );
		}

		// Check for missing embeddings and queue jobs.
		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate->embedding ) || 'null' === $candidate->embedding ) {
				$this->logger->warning(
					'Candidate has no embedding',
					array(
						'id'         => $candidate->id,
						'is_snippet' => $candidate->is_snippet,
					),
					'rag.log'
				);

				// Queue embedding job for snippets.
				if ( 1 === (int) $candidate->is_snippet ) {
					$this->queue->add(
						'embed_snippet',
						array(
							'snippet_id' => $candidate->id,
							'post_id'    => $candidate->post_id,
						)
					);
				}
			}
		}

		// Filter out candidates without valid embeddings.
		$candidates = array_filter( $candidates, function( $candidate ) {
			return ! empty( $candidate->embedding ) && 'null' !== $candidate->embedding;
		} );

		return array_values( $candidates );
	}

	/**
	 * Extract keywords from query
	 *
	 * Simple bag-of-words approach with stopword removal.
	 *
	 * @param string $query Query text.
	 * @return array Top keywords.
	 */
	private function extract_keywords( $query ) {
		// Lowercase and split.
		$query = strtolower( $query );
		$words = preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY );

		// Remove stopwords.
		$words = array_filter( $words, function( $word ) {
			return ! in_array( $word, $this->stopwords, true ) && strlen( $word ) > 2;
		} );

		// Count word frequency.
		$word_counts = array_count_values( $words );

		// Sort by frequency.
		arsort( $word_counts );

		// Return top 5 keywords.
		return array_slice( array_keys( $word_counts ), 0, 5 );
	}

	/**
	 * Calculate similarity scores for all candidates
	 *
	 * @param array $query_embedding Query embedding vector.
	 * @param array $candidates      Array of candidate objects.
	 * @return array Map of candidate index => similarity score.
	 */
	public function calculate_similarity_scores( $query_embedding, $candidates ) {
		$scores = array();

		foreach ( $candidates as $index => $candidate ) {
			// Decode embedding JSON.
			$candidate_embedding = json_decode( $candidate->embedding, true );

			if ( ! is_array( $candidate_embedding ) ) {
				$scores[ $index ] = 0.0;
				continue;
			}

			// Calculate cosine similarity.
			$similarity = $this->cosine_similarity( $query_embedding, $candidate_embedding );
			$scores[ $index ] = $similarity;
		}

		return $scores;
	}

	/**
	 * Calculate cosine similarity between two vectors
	 *
	 * Pure PHP implementation.
	 *
	 * @param array $a First vector.
	 * @param array $b Second vector.
	 * @return float Similarity score (0-1).
	 */
	public function cosine_similarity( $a, $b ) {
		if ( count( $a ) !== count( $b ) ) {
			return 0.0;
		}

		$dot_product = 0.0;
		$magnitude_a = 0.0;
		$magnitude_b = 0.0;

		$length = count( $a );
		for ( $i = 0; $i < $length; $i++ ) {
			$dot_product += $a[ $i ] * $b[ $i ];
			$magnitude_a += $a[ $i ] * $a[ $i ];
			$magnitude_b += $b[ $i ] * $b[ $i ];
		}

		$magnitude_a = sqrt( $magnitude_a );
		$magnitude_b = sqrt( $magnitude_b );

		if ( 0.0 === $magnitude_a || 0.0 === $magnitude_b ) {
			return 0.0;
		}

		$similarity = $dot_product / ( $magnitude_a * $magnitude_b );

		// Clamp to [0, 1] range.
		return max( 0.0, min( 1.0, $similarity ) );
	}
}
