<?php
/**
 * RAG Engine Core
 *
 * Handles query embedding and RAG orchestration.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_RAG_Engine
 *
 * Core RAG engine for query embedding and response packaging.
 */
class WPLLMSEO_RAG_Engine {

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Vector search instance
	 *
	 * @var WPLLMSEO_Vector_Search
	 */
	private $vector_search;

	/**
	 * Ranking instance
	 *
	 * @var WPLLMSEO_Rank
	 */
	private $rank;

	/**
	 * API key (legacy support)
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Embedding model (legacy support)
	 *
	 * @var string
	 */
	private $model = 'models/text-embedding-004';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger        = new WPLLMSEO_Logger();
		$this->vector_search = new WPLLMSEO_Vector_Search();
		$this->rank          = new WPLLMSEO_Rank();

		// Get API key from settings (legacy support).
		$settings = get_option( 'wpllmseo_settings', array() );
		$this->api_key = isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
	}

	/**
	 * Execute RAG query
	 *
	 * @param string $query User query string.
	 * @param int    $limit Number of results to return (default 5).
	 * @return array|WP_Error Results or error.
	 */
	public function execute_query( $query, $limit = 5 ) {
		$start_time = microtime( true );

		// Sanitize query.
		$query = sanitize_text_field( wp_strip_all_tags( $query ) );

		if ( empty( $query ) ) {
			return new WP_Error( 'empty_query', 'Query cannot be empty', array( 'status' => 400 ) );
		}

		// Step 1: Embed query.
		$query_embedding = $this->embed_query( $query );
		if ( is_wp_error( $query_embedding ) ) {
			$this->logger->error( 'Query embedding failed', array( 'error' => $query_embedding->get_error_message() ), 'rag.log' );
			return $query_embedding;
		}

		// Step 2: Get candidates.
		$candidates = $this->vector_search->get_candidates( $query, 200 );
		if ( empty( $candidates ) ) {
			$this->logger->info( 'No candidates found', array( 'query' => $query ), 'rag.log' );
			return array(
				'query'            => $query,
				'results'          => array(),
				'total_candidates' => 0,
				'execution_time'   => round( ( microtime( true ) - $start_time ) * 1000, 2 ) . 'ms',
			);
		}

		// Step 3: Calculate similarity scores.
		$scores = $this->vector_search->calculate_similarity_scores( $query_embedding, $candidates );

		// Step 4: Apply ranking logic.
		$ranked_results = $this->rank->rank( $candidates, $scores, $query, $limit );

		// Step 5: Package response.
		$results = $this->package_results( $ranked_results );

		$execution_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		// Log query execution.
		$top_post_id = ! empty( $results ) ? $results[0]['post_id'] : null;
		$this->logger->info(
			'RAG query executed',
			array(
				'query'           => $query,
				'candidates'      => count( $candidates ),
				'top_post_id'     => $top_post_id,
				'execution_time'  => $execution_time . 'ms',
			),
			'rag.log'
		);

		return array(
			'query'            => $query,
			'results'          => $results,
			'total_candidates' => count( $candidates ),
			'execution_time'   => $execution_time . 'ms',
			'embedding_dim'    => count( $query_embedding ),
		);
	}

	/**
	 * Embed query using configured provider (v1.2.0+).
	 *
	 * @param string $text Query text.
	 * @return array|WP_Error Embedding array or error.
	 */
	public function embed_query( $text ) {
		// Check cache first (60 second transient).
		$cache_key = 'wpllmseo_qe_' . md5( $text );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Try provider system first (v1.2.0+).
		$provider_id = WPLLMSEO_Provider_Manager::get_active_provider( 'embedding' );
		$model_id    = WPLLMSEO_Provider_Manager::get_active_model( 'embedding' );

		if ( $provider_id && $model_id ) {
			$provider = WPLLMSEO_Provider_Manager::get_provider( $provider_id );
			$config   = WPLLMSEO_Provider_Manager::get_provider_config( $provider_id );

			if ( $provider && ! empty( $config['api_key'] ) ) {
				$result = $provider->generate_embedding( $text, $model_id, $config['api_key'], $config );

				if ( ! is_wp_error( $result ) && isset( $result['embedding'] ) ) {
					set_transient( $cache_key, $result['embedding'], 60 );
					return $result['embedding'];
				}
			}
		}

		// Fallback to legacy Gemini implementation.
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_api_key', 'Gemini API key not configured', array( 'status' => 500 ) );
		}

		// Prepare request.
		$url = 'https://generativelanguage.googleapis.com/v1/' . $this->model . ':embedContent';
		$url = add_query_arg( 'key', $this->api_key, $url );

		$body = array(
			'model'   => $this->model,
			'content' => array(
				'parts' => array(
					array( 'text' => $text ),
				),
			),
		);

		// Make request with retries.
		$max_retries = 2;
		$attempt     = 0;
		$response    = null;

		while ( $attempt <= $max_retries ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				break;
			}

			$attempt++;
			if ( $attempt <= $max_retries ) {
				usleep( 500000 ); // 0.5 second delay.
			}
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', 'Failed to connect to Gemini API: ' . $response->get_error_message(), array( 'status' => 500 ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body_content = wp_remote_retrieve_body( $response );
			return new WP_Error( 'api_error', 'Gemini API returned status ' . $status_code . ': ' . $body_content, array( 'status' => 500 ) );
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $response_body['embedding']['values'] ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response from Gemini API', array( 'status' => 500 ) );
		}

		$embedding = $response_body['embedding']['values'];

		// Cache for 60 seconds.
		set_transient( $cache_key, $embedding, 60 );

		return $embedding;
	}

	/**
	 * Package results with post data
	 *
	 * @param array $ranked_results Ranked candidates with scores.
	 * @return array Packaged results.
	 */
	private function package_results( $ranked_results ) {
		$results = array();

		foreach ( $ranked_results as $item ) {
			$post_id   = absint( $item['post_id'] );
			$post      = get_post( $post_id );
			$post_url  = get_permalink( $post_id );
			$post_title = $post ? $post->post_title : '';

			$results[] = array(
				'post_id'               => $post_id,
				'post_url'              => $post_url,
				'title'                 => $post_title,
				'text'                  => $item['text'],
				'final_score'           => $item['final_score'],
				'is_snippet'            => $item['is_snippet'],
				'debug_score_breakdown' => $item['debug_scores'],
			);
		}

		return $results;
	}
}
