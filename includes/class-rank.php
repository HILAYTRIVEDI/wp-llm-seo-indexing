<?php
/**
 * Ranking Engine
 *
 * Applies ranking boosts and filters to candidates.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Rank
 *
 * Ranking logic with boosts and diversity filters.
 */
class WPLLMSEO_Rank {

	/**
	 * Snippet boost value
	 *
	 * @var float
	 */
	private $snippet_boost = 0.15;

	/**
	 * Recent content boost (< 7 days)
	 *
	 * @var float
	 */
	private $recent_boost_high = 0.05;

	/**
	 * Recent content boost (< 30 days)
	 *
	 * @var float
	 */
	private $recent_boost_medium = 0.03;

	/**
	 * Title match boost
	 *
	 * @var float
	 */
	private $title_match_boost = 0.10;

	/**
	 * Max chunks per post
	 *
	 * @var int
	 */
	private $max_chunks_per_post = 2;

	/**
	 * Rank candidates
	 *
	 * @param array  $candidates Array of candidate objects.
	 * @param array  $scores     Map of candidate index => base similarity score.
	 * @param string $query      Original query text.
	 * @param int    $limit      Number of results to return.
	 * @return array Ranked and filtered results.
	 */
	public function rank( $candidates, $scores, $query, $limit = 5 ) {
		$ranked = array();

		foreach ( $candidates as $index => $candidate ) {
			$base_score = isset( $scores[ $index ] ) ? $scores[ $index ] : 0.0;

			// Initialize debug scores.
			$debug_scores = array(
				'base_similarity' => round( $base_score, 4 ),
				'snippet_boost'   => 0.0,
				'recency_boost'   => 0.0,
				'title_boost'     => 0.0,
			);

			$final_score = $base_score;

			// A) Snippet boost.
			if ( 1 === (int) $candidate->is_snippet ) {
				$final_score += $this->snippet_boost;
				$debug_scores['snippet_boost'] = $this->snippet_boost;
			}

			// B) Recency boost.
			$recency_boost = $this->calculate_recency_boost( $candidate->created_at );
			$final_score += $recency_boost;
			$debug_scores['recency_boost'] = $recency_boost;

			// C) Title match boost.
			$title_boost = $this->calculate_title_match_boost( $query, $candidate->post_id );
			$final_score += $title_boost;
			$debug_scores['title_boost'] = $title_boost;

			$ranked[] = array(
				'index'        => $index,
				'post_id'      => $candidate->post_id,
				'text'         => $candidate->text,
				'is_snippet'   => $candidate->is_snippet,
				'created_at'   => $candidate->created_at,
				'final_score'  => $final_score,
				'debug_scores' => $debug_scores,
			);
		}

		// Sort by final score descending.
		usort( $ranked, function( $a, $b ) {
			return $b['final_score'] <=> $a['final_score'];
		} );

		// D) Diversity filter: limit chunks per post.
		$ranked = $this->apply_diversity_filter( $ranked );

		// Return top N results.
		return array_slice( $ranked, 0, $limit );
	}

	/**
	 * Calculate recency boost
	 *
	 * @param string $created_at Timestamp string.
	 * @return float Boost value.
	 */
	private function calculate_recency_boost( $created_at ) {
		$created_timestamp = strtotime( $created_at );
		$current_timestamp = current_time( 'timestamp' );
		$age_days          = ( $current_timestamp - $created_timestamp ) / DAY_IN_SECONDS;

		if ( $age_days < 7 ) {
			return $this->recent_boost_high;
		} elseif ( $age_days < 30 ) {
			return $this->recent_boost_medium;
		}

		return 0.0;
	}

	/**
	 * Calculate title match boost
	 *
	 * @param string $query   Query text.
	 * @param int    $post_id Post ID.
	 * @return float Boost value.
	 */
	private function calculate_title_match_boost( $query, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return 0.0;
		}

		$title = strtolower( $post->post_title );
		$query = strtolower( $query );

		// Split query into words.
		$query_words = preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY );

		// Check if any query word appears in title.
		foreach ( $query_words as $word ) {
			if ( strlen( $word ) > 2 && false !== strpos( $title, $word ) ) {
				return $this->title_match_boost;
			}
		}

		return 0.0;
	}

	/**
	 * Apply diversity filter
	 *
	 * Limit chunks per post to avoid duplicate content.
	 *
	 * @param array $ranked Ranked results.
	 * @return array Filtered results.
	 */
	private function apply_diversity_filter( $ranked ) {
		$post_counts = array();
		$filtered    = array();

		foreach ( $ranked as $item ) {
			$post_id    = $item['post_id'];
			$is_snippet = $item['is_snippet'];

			// Always include snippets (they're already preferred).
			if ( 1 === (int) $is_snippet ) {
				$filtered[] = $item;
				continue;
			}

			// Track chunk count per post.
			if ( ! isset( $post_counts[ $post_id ] ) ) {
				$post_counts[ $post_id ] = 0;
			}

			// Include chunk if under limit.
			if ( $post_counts[ $post_id ] < $this->max_chunks_per_post ) {
				$filtered[] = $item;
				$post_counts[ $post_id ]++;
			}
		}

		return $filtered;
	}
}
