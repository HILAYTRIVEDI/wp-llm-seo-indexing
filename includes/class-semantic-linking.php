<?php
/**
 * Semantic Internal Linking
 *
 * Suggests relevant internal links based on embedding similarity.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Semantic_Linking
 *
 * Provides semantic-based internal linking suggestions.
 */
class WPLLMSEO_Semantic_Linking {

	/**
	 * Similarity threshold for recommendations.
	 */
	const SIMILARITY_THRESHOLD = 0.75;

	/**
	 * Cache key prefix.
	 */
	const CACHE_PREFIX = 'wpllmseo_linking_';

	/**
	 * Cache TTL (1 hour).
	 */
	const CACHE_TTL = 3600;

	/**
	 * Initialize semantic linking.
	 */
	public static function init() {
		// Add meta box to post editor.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wpllmseo_get_link_suggestions', array( __CLASS__, 'ajax_get_suggestions' ) );
		add_action( 'wp_ajax_wpllmseo_insert_links', array( __CLASS__, 'ajax_insert_links' ) );
		add_action( 'wp_ajax_wpllmseo_dismiss_suggestion', array( __CLASS__, 'ajax_dismiss_suggestion' ) );

		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Add semantic linking meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'wpllmseo_semantic_linking',
			'Semantic Internal Links',
			array( __CLASS__, 'render_meta_box' ),
			array( 'post', 'page' ),
			'side',
			'default'
		);
	}

	/**
	 * Render semantic linking meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_meta_box( $post ) {
		$settings = get_option( 'wpllmseo_settings', array() );

		if ( empty( $settings['enable_semantic_linking'] ) ) {
			echo '<p style="color:#666;">Semantic linking is disabled. Enable it in <a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo-settings' ) ) . '">settings</a>.</p>';
			return;
		}

		// Check if post has embeddings.
		$embedding_hash = get_post_meta( $post->ID, '_wpllmseo_embedding_hash', true );
		if ( empty( $embedding_hash ) ) {
			echo '<div id="wpllmseo-linking-panel" data-post-id="' . esc_attr( $post->ID ) . '">';
			echo '<p style="color:#666;">No embeddings found. <a href="#" class="wpllmseo-trigger-indexing" data-post-id="' . esc_attr( $post->ID ) . '">Index this post</a> to get suggestions.</p>';
			echo '</div>';
			return;
		}

		?>
		<div id="wpllmseo-linking-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p style="margin-bottom:10px;">
				<button type="button" class="button button-primary wpllmseo-get-suggestions" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<span class="dashicons dashicons-search" style="line-height:1.5;"></span> Find Similar Posts
				</button>
			</p>

			<div id="wpllmseo-suggestions-container" style="display:none;">
				<p style="font-weight:600;margin-bottom:8px;">Suggested Links:</p>
				<div id="wpllmseo-suggestions-list"></div>
				<p style="margin-top:10px;">
					<button type="button" class="button wpllmseo-insert-all" style="display:none;">
						Insert All Links
					</button>
				</p>
			</div>

			<div id="wpllmseo-linking-spinner" style="display:none;text-align:center;padding:20px;">
				<span class="spinner is-active" style="float:none;margin:0;"></span>
			</div>
		</div>

		<?php
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$css_file = WPLLMSEO_PLUGIN_DIR . 'admin/assets/css/semantic-linking.css';
		$js_file  = WPLLMSEO_PLUGIN_DIR . 'admin/assets/js/semantic-linking.js';

		// Enqueue CSS
		wp_enqueue_style(
			'wpllmseo-semantic-linking',
			WPLLMSEO_PLUGIN_URL . 'admin/assets/css/semantic-linking.css',
			array(),
			file_exists( $css_file ) ? filemtime( $css_file ) : '1.0.0'
		);

		// Enqueue JS
		wp_enqueue_script(
			'wpllmseo-semantic-linking',
			WPLLMSEO_PLUGIN_URL . 'admin/assets/js/semantic-linking.js',
			array( 'jquery' ),
			file_exists( $js_file ) ? filemtime( $js_file ) : '1.0.0',
			true
		);

		// Localize script
		wp_localize_script(
			'wpllmseo-semantic-linking',
			'wpllmseoSemanticLinking',
			array(
				'nonce'           => wp_create_nonce( 'wpllmseo_semantic_linking' ),
				'regenerateNonce' => wp_create_nonce( 'wpllmseo_regenerate' ),
			)
		);
	}

	/**
	 * AJAX handler: Get link suggestions.
	 */
	public static function ajax_get_suggestions() {
		check_ajax_referer( 'wpllmseo_semantic_linking', 'nonce' );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post ID or permissions.' ) );
		}

		$suggestions = self::get_suggestions( $post_id );

		if ( empty( $suggestions ) ) {
			wp_send_json_error( array( 'message' => 'No similar posts found.' ) );
		}

		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	/**
	 * Get link suggestions for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_suggestions( $post_id ) {
		// Check cache.
		$cache_key = self::CACHE_PREFIX . $post_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$snippets_table = $wpdb->prefix . 'llmseo_snippets';

		// Get current post's snippet embedding.
		$current_embedding = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT embedding FROM {$snippets_table} WHERE post_id = %d AND embedding IS NOT NULL ORDER BY created_at DESC LIMIT 1",
				$post_id
			)
		);

		if ( empty( $current_embedding ) ) {
			return array();
		}

		// Parse current embedding.
		$current_vector = json_decode( $current_embedding, true );
		if ( ! is_array( $current_vector ) ) {
			return array();
		}

		// Get all other posts with snippet embeddings.
		$dismissed = get_post_meta( $post_id, '_wpllmseo_dismissed_links', true ) ?: array();

		$exclude_ids   = array_merge( array( $post_id ), $dismissed );
		$exclude_ids   = array_map( 'absint', $exclude_ids );
		$exclude_list  = implode( ',', $exclude_ids );

		$other_posts = $wpdb->get_results(
			"SELECT DISTINCT post_id, embedding FROM {$snippets_table} 
			WHERE post_id NOT IN ({$exclude_list}) 
			AND embedding IS NOT NULL
			ORDER BY created_at DESC"
		);

		if ( empty( $other_posts ) ) {
			return array();
		}

		// Calculate similarities.
		$similarities = array();
		foreach ( $other_posts as $other_post ) {
			$other_vector = json_decode( $other_post->embedding, true );
			if ( ! is_array( $other_vector ) ) {
				continue;
			}

			$similarity = self::cosine_similarity( $current_vector, $other_vector );

			if ( $similarity >= self::SIMILARITY_THRESHOLD ) {
				$similarities[ $other_post->post_id ] = $similarity;
			}
		}

		// Sort by similarity.
		arsort( $similarities );

		// Build suggestions.
		$suggestions = array();
		$count       = 0;
		foreach ( $similarities as $suggested_post_id => $similarity ) {
			if ( ++$count > 5 ) {
				break;
			}

			$post = get_post( $suggested_post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$suggestions[] = array(
				'id'         => $suggested_post_id,
				'title'      => get_the_title( $suggested_post_id ),
				'url'        => get_permalink( $suggested_post_id ),
				'type'       => ucfirst( $post->post_type ),
				'similarity' => $similarity,
			);
		}

		// Cache results.
		set_transient( $cache_key, $suggestions, self::CACHE_TTL );

		return $suggestions;
	}

	/**
	 * Calculate similarity between two sets of embeddings.
	 *
	 * @param array $embeddings_a First set of embeddings.
	 * @param array $embeddings_b Second set of embeddings.
	 * @return float
	 */
	private static function calculate_similarity( $embeddings_a, $embeddings_b ) {
		if ( empty( $embeddings_a ) || empty( $embeddings_b ) ) {
			return 0.0;
		}

		$max_similarity = 0.0;

		foreach ( $embeddings_a as $embedding_a ) {
			$vector_a = json_decode( $embedding_a->embedding, true );
			if ( ! $vector_a ) {
				continue;
			}

			foreach ( $embeddings_b as $embedding_b ) {
				$vector_b = json_decode( $embedding_b->embedding, true );
				if ( ! $vector_b ) {
					continue;
				}

				$similarity     = self::cosine_similarity( $vector_a, $vector_b );
				$max_similarity = max( $max_similarity, $similarity );
			}
		}

		return $max_similarity;
	}

	/**
	 * Calculate cosine similarity between two vectors.
	 *
	 * @param array $vector_a First vector.
	 * @param array $vector_b Second vector.
	 * @return float
	 */
	private static function cosine_similarity( $vector_a, $vector_b ) {
		if ( count( $vector_a ) !== count( $vector_b ) ) {
			return 0.0;
		}

		$dot_product = 0.0;
		$magnitude_a = 0.0;
		$magnitude_b = 0.0;

		for ( $i = 0; $i < count( $vector_a ); $i++ ) {
			$dot_product += $vector_a[ $i ] * $vector_b[ $i ];
			$magnitude_a += $vector_a[ $i ] ** 2;
			$magnitude_b += $vector_b[ $i ] ** 2;
		}

		$magnitude_a = sqrt( $magnitude_a );
		$magnitude_b = sqrt( $magnitude_b );

		if ( 0.0 === $magnitude_a || 0.0 === $magnitude_b ) {
			return 0.0;
		}

		return $dot_product / ( $magnitude_a * $magnitude_b );
	}

	/**
	 * AJAX handler: Insert links.
	 */
	public static function ajax_insert_links() {
		check_ajax_referer( 'wpllmseo_semantic_linking', 'nonce' );

		$current_post_id = absint( $_POST['current_post_id'] ?? 0 );
		$link_post_ids   = array_map( 'absint', $_POST['link_post_ids'] ?? array() );

		if ( ! $current_post_id || ! current_user_can( 'edit_post', $current_post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid permissions.' ) );
		}

		$post = get_post( $current_post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found.' ) );
		}

		$content       = $post->post_content;
		$inserted      = 0;
		$max_per_post  = 3;

		foreach ( $link_post_ids as $link_post_id ) {
			if ( $inserted >= $max_per_post ) {
				break;
			}

			$link_post = get_post( $link_post_id );
			if ( ! $link_post ) {
				continue;
			}

			// Find anchor text (use first sentence or title).
			$anchor_text = self::find_anchor_text( $content, $link_post );
			if ( ! $anchor_text ) {
				continue;
			}

			// Insert link.
			$link_url = get_permalink( $link_post_id );
			$link_tag = '<a href="' . esc_url( $link_url ) . '">' . esc_html( $anchor_text ) . '</a>';

			// Replace first occurrence.
			$content = preg_replace(
				'/\b' . preg_quote( $anchor_text, '/' ) . '\b/',
				$link_tag,
				$content,
				1,
				$count
			);

			if ( $count > 0 ) {
				$inserted++;
			}
		}

		if ( $inserted > 0 ) {
			// Update post content.
			wp_update_post(
				array(
					'ID'           => $current_post_id,
					'post_content' => $content,
				)
			);

			wp_send_json_success(
				array(
					'message' => sprintf( 'Inserted %d internal link(s).', $inserted ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => 'No suitable anchor text found.' ) );
		}
	}

	/**
	 * Find suitable anchor text.
	 *
	 * @param string  $content    Post content.
	 * @param WP_Post $link_post  Post to link to.
	 * @return string|false
	 */
	private static function find_anchor_text( $content, $link_post ) {
		$title = $link_post->post_title;

		// Check if title exists in content (case-insensitive).
		if ( stripos( $content, $title ) !== false ) {
			return $title;
		}

		// Try significant words from title.
		$words = explode( ' ', $title );
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( strlen( $word ) > 4 && stripos( $content, $word ) !== false ) {
				return $word;
			}
		}

		return false;
	}

	/**
	 * AJAX handler: Dismiss suggestion.
	 */
	public static function ajax_dismiss_suggestion() {
		check_ajax_referer( 'wpllmseo_semantic_linking', 'nonce' );

		$current_post_id   = absint( $_POST['current_post_id'] ?? 0 );
		$dismissed_post_id = absint( $_POST['dismissed_post_id'] ?? 0 );

		if ( ! $current_post_id || ! current_user_can( 'edit_post', $current_post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid permissions.' ) );
		}

		$dismissed   = get_post_meta( $current_post_id, '_wpllmseo_dismissed_links', true ) ?: array();
		$dismissed[] = $dismissed_post_id;
		$dismissed   = array_unique( $dismissed );

		update_post_meta( $current_post_id, '_wpllmseo_dismissed_links', $dismissed );

		// Clear cache.
		delete_transient( self::CACHE_PREFIX . $current_post_id );

		wp_send_json_success();
	}
}
