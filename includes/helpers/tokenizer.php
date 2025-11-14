<?php
/**
 * Tokenizer Helper Functions
 *
 * Simple token extraction for two-stage candidate selection in vector search.
 * Uses TF-like approach to identify significant terms.
 *
 * @package WPLLMSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract significant tokens from text
 *
 * @param string $text  Text to tokenize.
 * @param int    $limit Maximum tokens to return.
 * @return array Array of tokens sorted by significance.
 */
function wpllmseo_extract_tokens( $text, $limit = 16 ) {
	// Strip HTML and normalize
	$text = wp_strip_all_tags( $text );
	$text = strtolower( $text );
	$text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
	
	// Split into words
	$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
	
	// Count frequencies
	$counts = array();
	foreach ( $words as $word ) {
		// Filter short and stop words
		if ( strlen( $word ) < 3 || wpllmseo_is_stop_word( $word ) ) {
			continue;
		}
		
		if ( ! isset( $counts[ $word ] ) ) {
			$counts[ $word ] = 0;
		}
		$counts[ $word ]++;
	}
	
	// Sort by frequency
	arsort( $counts );
	
	// Return top N tokens
	return array_slice( array_keys( $counts ), 0, $limit );
}

/**
 * Check if word is a stop word
 *
 * @param string $word Word to check.
 * @return bool True if stop word.
 */
function wpllmseo_is_stop_word( $word ) {
	static $stop_words = null;
	
	if ( null === $stop_words ) {
		$stop_words = array(
			'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her',
			'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'how',
			'man', 'new', 'now', 'old', 'see', 'two', 'way', 'who', 'boy', 'did',
			'its', 'let', 'put', 'say', 'she', 'too', 'use', 'from', 'have', 'that',
			'with', 'this', 'they', 'were', 'been', 'what', 'when', 'your', 'more',
			'will', 'there', 'their', 'would', 'about', 'which', 'these', 'could',
			'other', 'than', 'then', 'them', 'into', 'also', 'some', 'only', 'over',
			'such', 'very', 'well', 'even', 'most', 'much', 'should', 'where', 'being',
		);
		
		// Allow filtering
		$stop_words = apply_filters( 'wpllmseo_stop_words', $stop_words );
	}
	
	return in_array( $word, $stop_words, true );
}

/**
 * Write tokens for a post
 *
 * @param int    $post_id Post ID.
 * @param string $text    Text content.
 * @param bool   $clear   Clear existing tokens first.
 * @return int Number of tokens written.
 */
function wpllmseo_write_tokens_for_post( $post_id, $text, $clear = true ) {
	global $wpdb;
	
	$tokens_table = $wpdb->prefix . 'wpllmseo_tokens';
	
	if ( $clear ) {
		$wpdb->delete( $tokens_table, array( 'post_id' => $post_id ), array( '%d' ) );
	}
	
	$tokens = wpllmseo_extract_tokens( $text );
	$count  = 0;
	
	foreach ( $tokens as $token ) {
		$result = $wpdb->insert(
			$tokens_table,
			array(
				'post_id' => $post_id,
				'token'   => $token,
				'score'   => 1.0,
			),
			array( '%d', '%s', '%f' )
		);
		
		if ( $result ) {
			$count++;
		}
	}
	
	return $count;
}

/**
 * Get candidate post IDs matching query tokens
 *
 * @param string $query_text     Query text.
 * @param int    $candidate_limit Maximum candidates to return.
 * @return array Array of post IDs.
 */
function wpllmseo_get_candidate_post_ids( $query_text, $candidate_limit = 300 ) {
	global $wpdb;
	
	$tokens_table = $wpdb->prefix . 'wpllmseo_tokens';
	$tokens       = wpllmseo_extract_tokens( $query_text );
	
	if ( empty( $tokens ) ) {
		return array();
	}
	
	// Build placeholders for IN clause
	$placeholders = implode( ', ', array_fill( 0, count( $tokens ), '%s' ) );
	
	// Query: get post_ids matching any token, order by match count
	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT post_id, COUNT(*) as match_count
		FROM $tokens_table
		WHERE token IN ($placeholders)
		GROUP BY post_id
		ORDER BY match_count DESC, post_id DESC
		LIMIT %d",
		array_merge( $tokens, array( $candidate_limit ) )
	);
	
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$results = $wpdb->get_results( $sql, ARRAY_A );
	
	return array_column( $results, 'post_id' );
}

/**
 * Clear tokens for a post
 *
 * @param int $post_id Post ID.
 * @return int Number of tokens deleted.
 */
function wpllmseo_clear_tokens_for_post( $post_id ) {
	global $wpdb;
	
	$tokens_table = $wpdb->prefix . 'wpllmseo_tokens';
	
	return $wpdb->delete( $tokens_table, array( 'post_id' => $post_id ), array( '%d' ) );
}

/**
 * Bulk token cleanup for orphaned posts
 *
 * @return int Number of tokens deleted.
 */
function wpllmseo_cleanup_orphaned_tokens() {
	global $wpdb;
	
	$tokens_table = $wpdb->prefix . 'wpllmseo_tokens';
	$posts_table  = $wpdb->posts;
	
	// Delete tokens where post no longer exists
	$sql = "DELETE t FROM $tokens_table t
			LEFT JOIN $posts_table p ON t.post_id = p.ID
			WHERE p.ID IS NULL";
	
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->query( $sql );
}
