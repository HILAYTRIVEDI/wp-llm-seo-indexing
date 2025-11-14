<?php
/**
 * LLM Content API
 *
 * Secure API endpoints for LLM consumption with rate limiting.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_LLM_API
 *
 * Provides LLM-optimized content API with rate limiting.
 */
class WPLLMSEO_LLM_API {

	/**
	 * API namespace.
	 */
	const NAMESPACE = 'llm/v1';

	/**
	 * Rate limit window in seconds.
	 */
	const RATE_LIMIT_WINDOW = 3600; // 1 hour

	/**
	 * Default rate limit per window.
	 */
	const RATE_LIMIT = 100;

	/**
	 * Initialize API.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		// Get single post.
		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_post_endpoint' ),
				'permission_callback' => array( __CLASS__, 'check_api_permission' ),
				'args'                => array(
					'id'     => array(
						'required'          => true,
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					),
					'fields' => array(
						'required' => false,
						'default'  => 'raw_text,ai_snippet,metadata',
					),
					'token'  => array(
						'required' => false,
					),
				),
			)
		);

		// Search posts.
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search_posts_endpoint' ),
				'permission_callback' => array( __CLASS__, 'check_api_permission' ),
				'args'                => array(
					'q'      => array(
						'required' => true,
					),
					'limit'  => array(
						'default' => 10,
					),
					'fields' => array(
						'default' => 'raw_text,ai_snippet',
					),
					'token'  => array(
						'required' => false,
					),
				),
			)
		);

		// Batch posts.
		register_rest_route(
			self::NAMESPACE,
			'/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'batch_posts_endpoint' ),
				'permission_callback' => array( __CLASS__, 'check_api_permission' ),
				'args'                => array(
					'ids'    => array(
						'required' => true,
						'type'     => 'array',
					),
					'fields' => array(
						'default' => 'raw_text,ai_snippet',
					),
					'token'  => array(
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Get post endpoint handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_post_endpoint( $request ) {
		$post_id = $request->get_param( 'id' );
		$fields  = explode( ',', $request->get_param( 'fields' ) );

		// Check rate limit.
		$rate_check = self::check_rate_limit( $request );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'post_not_found',
				'Post not found or not published',
				array( 'status' => 404 )
			);
		}

		$data = self::build_post_response( $post_id, $fields );

		// Log API access.
		self::log_api_access( 'get_post', $post_id );

		return rest_ensure_response( $data );
	}

	/**
	 * Search posts endpoint handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search_posts_endpoint( $request ) {
		$query = $request->get_param( 'q' );
		$limit = min( absint( $request->get_param( 'limit' ) ), 50 );
		$fields = explode( ',', $request->get_param( 'fields' ) );

		// Check rate limit.
		$rate_check = self::check_rate_limit( $request );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Search posts.
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				's'              => $query,
				'orderby'        => 'relevance',
			)
		);

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = self::build_post_response( $post->ID, $fields );
		}

		self::log_api_access( 'search', null, array( 'query' => $query ) );

		return rest_ensure_response(
			array(
				'query'   => $query,
				'results' => $results,
				'total'   => count( $results ),
			)
		);
	}

	/**
	 * Batch posts endpoint handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function batch_posts_endpoint( $request ) {
		$ids    = $request->get_param( 'ids' );
		$fields = explode( ',', $request->get_param( 'fields' ) );

		// Check rate limit.
		$rate_check = self::check_rate_limit( $request );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Limit batch size.
		if ( count( $ids ) > 20 ) {
			return new WP_Error(
				'batch_too_large',
				'Maximum batch size is 20 posts',
				array( 'status' => 400 )
			);
		}

		$results = array();
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status ) {
				$results[] = self::build_post_response( $post_id, $fields );
			}
		}

		self::log_api_access( 'batch', null, array( 'count' => count( $ids ) ) );

		return rest_ensure_response(
			array(
				'results' => $results,
				'total'   => count( $results ),
			)
		);
	}

	/**
	 * Build post response data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Fields to include.
	 * @return array
	 */
	private static function build_post_response( $post_id, $fields ) {
		global $wpdb;

		$post = get_post( $post_id );
		$data = array( 'id' => $post_id );

		// Raw text.
		if ( in_array( 'raw_text', $fields, true ) ) {
			$content           = wp_strip_all_tags( $post->post_content );
			$content           = preg_replace( '/\s+/', ' ', $content );
			$data['raw_text']  = trim( $content );
		}

		// Cleaned text.
		if ( in_array( 'cleaned_text', $fields, true ) ) {
			$data['cleaned_text'] = self::get_cleaned_text( $post );
		}

		// AI snippet.
		if ( in_array( 'ai_snippet', $fields, true ) ) {
			$snippet           = get_post_meta( $post_id, '_wpllmseo_ai_snippet', true );
			$data['ai_snippet'] = $snippet ?: null;
		}

		// Embeddings.
		if ( in_array( 'embeddings', $fields, true ) || in_array( 'embedding_id', $fields, true ) ) {
			$embedding_hash        = get_post_meta( $post_id, '_wpllmseo_embedding_hash', true );
			$data['embedding_id']  = $embedding_hash ? 'emb_' . substr( $embedding_hash, 0, 16 ) : null;
		}

		// Semantic keywords.
		if ( in_array( 'semantic_keywords', $fields, true ) ) {
			$data['semantic_keywords'] = self::extract_semantic_keywords( $post_id );
		}

		// Metadata.
		if ( in_array( 'metadata', $fields, true ) ) {
			$seo_meta = WPLLMSEO_SEO_Compat::get_primary_seo_meta( $post_id );
			
			$data['metadata'] = array(
				'title'            => $seo_meta['title'] ?: get_the_title( $post_id ),
				'meta_description' => $seo_meta['meta_description'],
				'url'              => get_permalink( $post_id ),
				'author'           => get_the_author_meta( 'display_name', $post->post_author ),
				'published'        => get_the_date( 'c', $post_id ),
				'modified'         => get_the_modified_date( 'c', $post_id ),
				'type'             => $post->post_type,
			);
		}

		// Schema (JSON-LD).
		if ( in_array( 'schema', $fields, true ) ) {
			$data['schema'] = WPLLMSEO_LLM_JSONLD::generate_jsonld( $post_id );
		}

		// Outline.
		if ( in_array( 'outline', $fields, true ) ) {
			$data['outline'] = self::generate_outline( $post );
		}

		return $data;
	}

	/**
	 * Get cleaned text (remove boilerplate).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function get_cleaned_text( $post ) {
		$content = $post->post_content;

		// Remove shortcodes.
		$content = strip_shortcodes( $content );

		// Remove HTML.
		$content = wp_strip_all_tags( $content );

		// Normalize whitespace.
		$content = preg_replace( '/\s+/', ' ', $content );

		return trim( $content );
	}

	/**
	 * Extract semantic keywords from post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function extract_semantic_keywords( $post_id ) {
		// Get tags.
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

		// Get categories.
		$categories = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );

		return array_merge( $tags, $categories );
	}

	/**
	 * Generate content outline.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function generate_outline( $post ) {
		$content = $post->post_content;
		$outline = array();

		// Extract headings.
		preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$level = (int) $match[1];
			$text  = wp_strip_all_tags( $match[2] );

			$outline[] = array(
				'level' => $level,
				'text'  => $text,
			);
		}

		return $outline;
	}

	/**
	 * Check API permissions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function check_api_permission( $request ) {
		$settings = get_option( 'wpllmseo_settings', array() );

		// Public API enabled by default for LLMs (backward compatibility)
		$api_public = isset( $settings['llm_api_public'] ) ? $settings['llm_api_public'] : true;
		if ( $api_public ) {
			return true;
		}

		// Token-based authentication if public access disabled.
		$token = $request->get_param( 'token' );
		if ( empty( $token ) ) {
			$token = $request->get_header( 'X-API-Token' );
		}

		if ( empty( $token ) || empty( $settings['llm_api_token'] ) ) {
			return new WP_Error(
				'unauthorized',
				'API token required',
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( $settings['llm_api_token'], $token ) ) {
			return new WP_Error(
				'invalid_token',
				'Invalid API token',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check rate limit.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	private static function check_rate_limit( $request ) {
		$settings = get_option( 'wpllmseo_settings', array() );

		if ( empty( $settings['llm_api_rate_limit'] ) ) {
			return true;
		}

		$identifier = self::get_rate_limit_identifier( $request );
		$key        = 'wpllmseo_rate_limit_' . md5( $identifier );

		$current = get_transient( $key );
		if ( false === $current ) {
			$current = 0;
		}

		$limit = $settings['llm_api_rate_limit_value'] ?? self::RATE_LIMIT;

		if ( $current >= $limit ) {
			return new WP_Error(
				'rate_limit_exceeded',
				sprintf( 'Rate limit exceeded. Maximum %d requests per hour.', $limit ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $current + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Get rate limit identifier (IP or token).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string
	 */
	private static function get_rate_limit_identifier( $request ) {
		$token = $request->get_param( 'token' );
		if ( ! empty( $token ) ) {
			return 'token_' . $token;
		}

		// Use IP address.
		$ip = '';
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'ip_' . $ip;
	}

	/**
	 * Log API access.
	 *
	 * @param string $endpoint Endpoint name.
	 * @param int    $post_id  Post ID (if applicable).
	 * @param array  $metadata Additional metadata.
	 */
	private static function log_api_access( $endpoint, $post_id = null, $metadata = array() ) {
		$log_entry = array(
			'endpoint'   => $endpoint,
			'post_id'    => $post_id,
			'metadata'   => $metadata,
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'timestamp'  => current_time( 'mysql' ),
		);

		$logs   = get_option( 'wpllmseo_api_access_logs', array() );
		$logs[] = $log_entry;

		// Keep only last 1000 entries.
		if ( count( $logs ) > 1000 ) {
			$logs = array_slice( $logs, -1000 );
		}

		update_option( 'wpllmseo_api_access_logs', $logs );
	}
}
