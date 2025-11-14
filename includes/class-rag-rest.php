<?php
/**
 * RAG REST API
 *
 * REST endpoints for RAG query execution.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_RAG_REST
 *
 * REST API endpoints for RAG system.
 */
class WPLLMSEO_RAG_REST {

	/**
	 * RAG Engine instance
	 *
	 * @var WPLLMSEO_RAG_Engine
	 */
	private $rag_engine;

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Rate limit: requests per second
	 *
	 * @var int
	 */
	private $rate_limit = 1;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->rag_engine = new WPLLMSEO_RAG_Engine();
		$this->logger     = new WPLLMSEO_Logger();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public function register_routes() {
		register_rest_route(
			'wp-llmseo/v1',
			'/query',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_query' ),
				'permission_callback' => array( $this, 'check_query_permission' ),
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function( $param ) {
							return ! empty( $param );
						},
					),
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 5,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Check query permission
	 *
	 * Allow public access but enforce rate limiting.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_query_permission( $request ) {
		// Check rate limit for public requests.
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( ! $this->check_rate_limit() ) {
				return new WP_Error(
					'rate_limit_exceeded',
					'Rate limit exceeded. Please wait before making another request.',
					array( 'status' => 429 )
				);
			}
		}

		return true;
	}

	/**
	 * Check rate limit
	 *
	 * Simple IP-based rate limiting.
	 *
	 * @return bool True if within limit, false if exceeded.
	 */
	private function check_rate_limit() {
		$ip = $this->get_client_ip();
		$transient_key = 'wpllmseo_rl_' . md5( $ip );

		// Check if request exists in last second.
		$last_request = get_transient( $transient_key );
		if ( false !== $last_request ) {
			return false;
		}

		// Set transient for 1 second.
		set_transient( $transient_key, time(), 1 );
		return true;
	}

	/**
	 * Get client IP address
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// Get first IP if multiple (proxy chain).
		$ip_list = explode( ',', $ip );
		$ip = trim( $ip_list[0] );

		return $ip;
	}

	/**
	 * Handle query request
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function handle_query( $request ) {
		$query = $request->get_param( 'q' );
		$limit = $request->get_param( 'limit' );

		// Execute RAG query.
		$result = $this->rag_engine->execute_query( $query, $limit );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'RAG query failed',
				array(
					'query' => $query,
					'error' => $result->get_error_message(),
				),
				'rag.log'
			);

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
				),
				isset( $result->get_error_data()['status'] ) ? $result->get_error_data()['status'] : 400
			);
		}

		// Return successful response.
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}
}
