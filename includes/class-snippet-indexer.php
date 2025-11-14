<?php
/**
 * Snippet Indexer Class
 *
 * Handles embedding generation for snippets.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Snippet_Indexer
 */
class WPLLMSEO_Snippet_Indexer {

	/**
	 * Snippets table name
	 *
	 * @var string
	 */
	private $snippets_table;

	/**
	 * Queue instance
	 *
	 * @var WPLLMSEO_Queue
	 */
	private $queue;

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->snippets_table = $wpdb->prefix . 'wpllmseo_snippets';
		$this->queue = new WPLLMSEO_Queue();
		$this->logger = new WPLLMSEO_Logger();

		// Hook into snippet creation/update
		add_action( 'wpllmseo_snippet_created', array( $this, 'enqueue_snippet' ), 10, 2 );
		add_action( 'wpllmseo_snippet_updated', array( $this, 'enqueue_snippet' ), 10, 2 );
	}

	/**
	 * Enqueue snippet for embedding
	 *
	 * @param int $snippet_id Snippet ID.
	 * @param int $post_id    Post ID.
	 */
	public function enqueue_snippet( $snippet_id, $post_id ) {
		$this->queue->add(
			'embed_snippet',
			array(
				'snippet_id' => $snippet_id,
				'post_id'    => $post_id,
			)
		);

		$this->logger->info(
			sprintf( 'Snippet queued for embedding: %d', $snippet_id ),
			array( 'snippet_id' => $snippet_id, 'post_id' => $post_id ),
			'snippet.log'
		);
	}

	/**
	 * Process snippet embedding
	 *
	 * @param int $snippet_id Snippet ID.
	 * @return bool True on success, false on failure.
	 */
	public function process_snippet_embedding( $snippet_id ) {
		global $wpdb;

		// Get snippet
		$snippet = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->snippets_table} WHERE id = %d",
				$snippet_id
			)
		);

		if ( ! $snippet ) {
			$this->logger->error(
				sprintf( 'Snippet %d not found', $snippet_id ),
				array( 'snippet_id' => $snippet_id ),
				'snippet.log'
			);
			return false;
		}

		// Get API settings
		$settings = get_option( 'wpllmseo_settings', array() );
		$api_key  = $settings['api_key'] ?? '';
		$model    = $settings['model'] ?? WPLLMSEO_GEMINI_MODEL;

		if ( empty( $api_key ) ) {
			$this->logger->error( 'Gemini API key not configured', array(), 'snippet.log' );
			return false;
		}

		// Generate embedding via Gemini API
		$embedding = $this->generate_embedding( $snippet->snippet_text, $api_key, $model );

		if ( ! $embedding ) {
			$this->logger->error(
				sprintf( 'Failed to generate embedding for snippet %d', $snippet_id ),
				array( 'snippet_id' => $snippet_id ),
				'snippet.log'
			);
			return false;
		}

		// Store embedding in database
		$embedding_json = wp_json_encode( $embedding );
		
		$result = $wpdb->update(
			$this->snippets_table,
			array( 'embedding' => $embedding_json ),
			array( 'id' => $snippet_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			// Update embedding hash meta for semantic linking
			$embedding_hash = md5( $embedding_json );
			WPLLMSEO_Change_Tracker::update_embedding_hash( $snippet->post_id, $embedding_hash );
			
			$this->logger->info(
				sprintf( 'Embedding stored for snippet %d (post %d)', $snippet_id, $snippet->post_id ),
				array( 'snippet_id' => $snippet_id, 'post_id' => $snippet->post_id ),
				'snippet.log'
			);
			return true;
		}

		$this->logger->error(
			sprintf( 'Failed to store embedding for snippet %d', $snippet_id ),
			array( 'snippet_id' => $snippet_id ),
			'snippet.log'
		);
		return false;
	}

	/**
	 * Generate embedding via Gemini API
	 *
	 * @param string $text    Text to embed.
	 * @param string $api_key API key.
	 * @param string $model   Model name.
	 * @return array|false Embedding array or false on failure.
	 */
	private function generate_embedding( $text, $api_key, $model ) {
		// Remove 'models/' prefix if present to avoid duplication in URL
		$model_path = str_replace( 'models/', '', $model );
		
		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent?key=%s',
			$model_path,
			$api_key
		);

		$body = wp_json_encode(
			array(
				'content' => array(
					'parts' => array(
						array( 'text' => $text ),
					),
				),
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'Gemini API error: ' . $response->get_error_message(),
				array(),
				'snippet.log'
			);
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->logger->error(
				sprintf( 'Gemini API returned status %d', $status_code ),
				array( 'status_code' => $status_code ),
				'snippet.log'
			);
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $data['embedding']['values'] ) ) {
			$this->logger->error( 'Invalid Gemini API response format', array(), 'snippet.log' );
			return false;
		}

		return $data['embedding']['values'];
	}

	/**
	 * Get snippet with embedding
	 *
	 * @param int $snippet_id Snippet ID.
	 * @return array|null Snippet data with embedding or null.
	 */
	public function get_snippet_with_embedding( $snippet_id ) {
		global $wpdb;

		$snippet = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->snippets_table} WHERE id = %d",
				$snippet_id
			),
			ARRAY_A
		);

		if ( ! $snippet ) {
			return null;
		}

		// Decode embedding
		if ( ! empty( $snippet['embedding'] ) ) {
			$snippet['embedding'] = json_decode( $snippet['embedding'], true );
		}

		return $snippet;
	}

	/**
	 * Search snippets by vector similarity
	 *
	 * @param array $query_embedding Query embedding vector.
	 * @param int   $limit           Number of results.
	 * @return array Array of snippet results.
	 */
	public function search_snippets( $query_embedding, $limit = 5 ) {
		global $wpdb;

		// Get all snippets with embeddings
		$snippets = $wpdb->get_results(
			"SELECT id, post_id, snippet_text, snippet_hash, embedding 
			FROM {$this->snippets_table} 
			WHERE embedding IS NOT NULL",
			ARRAY_A
		);

		if ( empty( $snippets ) ) {
			return array();
		}

		$results = array();

		foreach ( $snippets as $snippet ) {
			$embedding = json_decode( $snippet['embedding'], true );

			if ( ! $embedding ) {
				continue;
			}

			// Calculate cosine similarity
			$similarity = $this->cosine_similarity( $query_embedding, $embedding );

			$results[] = array(
				'snippet_id'   => $snippet['id'],
				'post_id'      => $snippet['post_id'],
				'snippet_text' => $snippet['snippet_text'],
				'snippet_hash' => $snippet['snippet_hash'],
				'similarity'   => $similarity,
				'is_snippet'   => 1,
			);
		}

		// Sort by similarity (descending)
		usort( $results, function( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		// Return top N results
		return array_slice( $results, 0, $limit );
	}

	/**
	 * Calculate cosine similarity between two vectors
	 *
	 * @param array $vec1 Vector 1.
	 * @param array $vec2 Vector 2.
	 * @return float Similarity score (0-1).
	 */
	private function cosine_similarity( $vec1, $vec2 ) {
		$dot_product = 0;
		$magnitude1  = 0;
		$magnitude2  = 0;

		$length = min( count( $vec1 ), count( $vec2 ) );

		for ( $i = 0; $i < $length; $i++ ) {
			$dot_product += $vec1[ $i ] * $vec2[ $i ];
			$magnitude1  += $vec1[ $i ] * $vec1[ $i ];
			$magnitude2  += $vec2[ $i ] * $vec2[ $i ];
		}

		$magnitude1 = sqrt( $magnitude1 );
		$magnitude2 = sqrt( $magnitude2 );

		if ( $magnitude1 == 0 || $magnitude2 == 0 ) {
			return 0;
		}

		return $dot_product / ( $magnitude1 * $magnitude2 );
	}
}
