<?php
/**
 * AI Index REST API
 *
 * Public REST endpoints for AI indexer discovery and RAG ingestion.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_AI_Index_REST
 *
 * Provides public discovery manifest, NDJSON chunk feeds, and chunk/embedding endpoints.
 */
class WPLLMSEO_AI_Index_REST {

	/**
	 * Initialize REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		// Manifest endpoint.
		register_rest_route(
			'ai-index/v1',
			'/manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_manifest' ),
				'permission_callback' => '__return_true',
			)
		);

		// Chunk metadata endpoint.
		register_rest_route(
			'ai-index/v1',
			'/chunk/(?P<chunk_id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_chunk' ),
				'permission_callback' => array( __CLASS__, 'check_chunk_permission' ),
			)
		);

		// Embeddings endpoint (gated).
		register_rest_route(
			'ai-index/v1',
			'/embeddings/(?P<chunk_id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_embedding' ),
				'permission_callback' => array( 'WPLLMSEO_API_Auth', 'check_embeddings_permission' ),
			)
		);

		// Stats endpoint.
		register_rest_route(
			'ai-index/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_stats' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Add rewrite rules for static NDJSON files.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^ai-index/manifest\.json$',
			'index.php?wpllmseo_ai_manifest=1',
			'top'
		);
		
		add_rewrite_rule(
			'^ai-index/(ai-chunks|ai-embeddings|ai-chunks-delta)\.ndjson\.gz$',
			'index.php?wpllmseo_ai_file=$matches[1]',
			'top'
		);

		add_rewrite_tag( '%wpllmseo_ai_manifest%', '([^&]+)' );
		add_rewrite_tag( '%wpllmseo_ai_file%', '([^&]+)' );
	}

	/**
	 * Check if user can access chunk.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public static function check_chunk_permission( $request ) {
		$chunk_id = $request->get_param( 'chunk_id' );
		$chunk    = self::get_chunk_data( $chunk_id );

		if ( ! $chunk ) {
			return false;
		}

		// Check if post is published.
		$post = get_post( $chunk['post_id'] );
		return $post && 'publish' === $post->post_status;
	}

	/**
	 * Get manifest data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_manifest( $request ) {
		global $wpdb;

		$site_url     = home_url();
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';
		$count        = $wpdb->get_var( "SELECT COUNT(*) FROM `{$chunks_table}`" );

		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'] . '/ai-index';

		$embeddings_url = null;
		if ( ! WPLLMSEO_API_Auth::are_embeddings_gated() ) {
			$embeddings_url = $site_url . '/ai-index/ai-embeddings.ndjson.gz';
		}

		$manifest = array(
			'version'               => WPLLMSEO_VERSION,
			'site'                  => get_bloginfo( 'name' ),
			'site_url'              => $site_url,
			'generated'             => current_time( 'c' ),
			'content_count'         => (int) $count,
			'chunk_index_url'       => $site_url . '/ai-index/ai-chunks.ndjson.gz',
			'embeddings_index_url'  => $embeddings_url,
			'delta_url'             => $site_url . '/ai-index/ai-chunks-delta.ndjson.gz',
			'contact'               => admin_url( 'admin.php?page=wpllmseo_settings' ),
			'endpoints'             => array(
				'chunk'      => rest_url( 'ai-index/v1/chunk/{chunk_id}' ),
				'embeddings' => rest_url( 'ai-index/v1/embeddings/{chunk_id}' ),
				'stats'      => rest_url( 'ai-index/v1/stats' ),
			),
		);

		$response = new WP_REST_Response( $manifest, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Get chunk metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_chunk( $request ) {
		$chunk_id = $request->get_param( 'chunk_id' );
		$chunk    = self::get_chunk_data( $chunk_id );

		if ( ! $chunk ) {
			return new WP_Error(
				'chunk_not_found',
				'Chunk not found.',
				array( 'status' => 404 )
			);
		}

		$response = new WP_REST_Response( $chunk, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Get embedding data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_embedding( $request ) {
		global $wpdb;

		$chunk_id = $request->get_param( 'chunk_id' );
		$table    = $wpdb->prefix . 'wpllmseo_chunks';

		$chunk = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT chunk_id, chunk_index, embedding, embedding_model 
				FROM `{$table}` 
				WHERE chunk_id = %s",
				$chunk_id
			),
			ARRAY_A
		);

		if ( ! $chunk ) {
			return new WP_Error(
				'chunk_not_found',
				'Chunk not found.',
				array( 'status' => 404 )
			);
		}

		if ( empty( $chunk['embedding'] ) ) {
			return new WP_Error(
				'no_embedding',
				'No embedding available for this chunk.',
				array( 'status' => 404 )
			);
		}

		$embedding = json_decode( $chunk['embedding'], true );
		
		$response_data = array(
			'chunk_id'       => $chunk['chunk_id'],
			'model'          => $chunk['embedding_model'] ?? 'unknown',
			'dim'            => is_array( $embedding ) ? count( $embedding ) : 0,
			'vec'            => $embedding,
			'last_modified'  => current_time( 'c' ),
		);

		$response = new WP_REST_Response( $response_data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=86400' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Get stats.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function get_stats( $request ) {
		global $wpdb;

		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		$stats = array(
			'total_chunks'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$chunks_table}`" ),
			'chunks_with_embeddings' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$chunks_table}` WHERE embedding IS NOT NULL" ),
			'last_updated'      => $wpdb->get_var( "SELECT MAX(updated_at) FROM `{$chunks_table}`" ),
		);

		$response = new WP_REST_Response( $stats, 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Get chunk data by ID.
	 *
	 * @param string $chunk_id Chunk ID.
	 * @return array|null
	 */
	private static function get_chunk_data( $chunk_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpllmseo_chunks';
		$chunk = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
					chunk_id,
					post_id,
					chunk_index,
					start_word,
					end_word,
					word_count,
					char_count,
					token_estimate,
					text,
					chunk_hash,
					updated_at
				FROM `{$table}` 
				WHERE chunk_id = %s",
				$chunk_id
			),
			ARRAY_A
		);

		if ( ! $chunk ) {
			return null;
		}

		// Get post hash.
		$post = get_post( $chunk['post_id'] );
		$source_post_hash = $post ? md5( $post->post_modified . $post->post_content ) : '';

		// Create text preview (first 200 chars).
		$text_preview = $chunk['text'] ? mb_substr( $chunk['text'], 0, 200 ) : '';

		return array(
			'chunk_id'         => $chunk['chunk_id'],
			'post_id'          => (int) $chunk['post_id'],
			'start_word'       => (int) $chunk['start_word'],
			'end_word'         => (int) $chunk['end_word'],
			'word_count'       => (int) $chunk['word_count'],
			'char_count'       => (int) $chunk['char_count'],
			'token_estimate'   => (int) $chunk['token_estimate'],
			'text_preview'     => $text_preview,
			'chunk_url'        => rest_url( 'ai-index/v1/chunk/' . $chunk['chunk_id'] ),
			'last_modified'    => $chunk['updated_at'],
			'chunk_hash'       => $chunk['chunk_hash'],
			'source_post_hash' => $source_post_hash,
		);
	}

	/**
	 * Export chunks to NDJSON.
	 *
	 * @param string $output_file Output file path.
	 * @param array  $args Export arguments.
	 * @return bool Success.
	 */
	public static function export_chunks_ndjson( $output_file, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'since'    => null,
			'limit'    => null,
			'compress' => true,
		);

		$args = wp_parse_args( $args, $defaults );

		$table = $wpdb->prefix . 'wpllmseo_chunks';
		$where = '1=1';
		$params = array();

		if ( $args['since'] ) {
			$where .= ' AND updated_at >= %s';
			$params[] = $args['since'];
		}

		$limit_clause = '';
		if ( $args['limit'] ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d', $args['limit'] );
		}

		$query = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY chunk_id" . $limit_clause;
		
		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, ...$params );
		}

		$chunks = $wpdb->get_results( $query, ARRAY_A );

		// Write to temp file.
		$temp_file = $output_file . '.tmp';
		
		if ( $args['compress'] ) {
			$handle = gzopen( $temp_file, 'wb9' );
		} else {
			$handle = fopen( $temp_file, 'wb' );
		}

		if ( ! $handle ) {
			return false;
		}

		foreach ( $chunks as $chunk ) {
			$post = get_post( $chunk['post_id'] );
			$source_post_hash = $post ? md5( $post->post_modified . $post->post_content ) : '';

			$line = array(
				'chunk_id'         => $chunk['chunk_id'],
				'post_id'          => (int) $chunk['post_id'],
				'start_word'       => (int) $chunk['start_word'],
				'end_word'         => (int) $chunk['end_word'],
				'word_count'       => (int) $chunk['word_count'],
				'char_count'       => (int) $chunk['char_count'],
				'token_estimate'   => (int) $chunk['token_estimate'],
				'text_preview'     => mb_substr( $chunk['text'], 0, 200 ),
				'chunk_url'        => rest_url( 'ai-index/v1/chunk/' . $chunk['chunk_id'] ),
				'last_modified'    => $chunk['updated_at'],
				'chunk_hash'       => $chunk['chunk_hash'],
				'source_post_hash' => $source_post_hash,
			);

			$json = wp_json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n";
			
			if ( $args['compress'] ) {
				gzwrite( $handle, $json );
			} else {
				fwrite( $handle, $json );
			}
		}

		if ( $args['compress'] ) {
			gzclose( $handle );
		} else {
			fclose( $handle );
		}

		// Atomic rename.
		rename( $temp_file, $output_file );

		return true;
	}

	/**
	 * Export embeddings to NDJSON.
	 *
	 * @param string $output_file Output file path.
	 * @param array  $args Export arguments.
	 * @return bool Success.
	 */
	public static function export_embeddings_ndjson( $output_file, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'since'    => null,
			'limit'    => null,
			'compress' => true,
		);

		$args = wp_parse_args( $args, $defaults );

		$table = $wpdb->prefix . 'wpllmseo_chunks';
		$where = 'embedding IS NOT NULL';
		$params = array();

		if ( $args['since'] ) {
			$where .= ' AND updated_at >= %s';
			$params[] = $args['since'];
		}

		$limit_clause = '';
		if ( $args['limit'] ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d', $args['limit'] );
		}

		$query = "SELECT chunk_id, embedding, embedding_model, updated_at FROM `{$table}` WHERE {$where} ORDER BY chunk_id" . $limit_clause;
		
		if ( ! empty( $params ) ) {
			$query = $wpdb->prepare( $query, ...$params );
		}

		$chunks = $wpdb->get_results( $query, ARRAY_A );

		// Write to temp file.
		$temp_file = $output_file . '.tmp';
		
		if ( $args['compress'] ) {
			$handle = gzopen( $temp_file, 'wb9' );
		} else {
			$handle = fopen( $temp_file, 'wb' );
		}

		if ( ! $handle ) {
			return false;
		}

		foreach ( $chunks as $chunk ) {
			$embedding = json_decode( $chunk['embedding'], true );

			$line = array(
				'chunk_id'      => $chunk['chunk_id'],
				'model'         => $chunk['embedding_model'] ?? 'unknown',
				'dim'           => is_array( $embedding ) ? count( $embedding ) : 0,
				'vec'           => $embedding,
				'last_modified' => $chunk['updated_at'],
			);

			$json = wp_json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n";
			
			if ( $args['compress'] ) {
				gzwrite( $handle, $json );
			} else {
				fwrite( $handle, $json );
			}
		}

		if ( $args['compress'] ) {
			gzclose( $handle );
		} else {
			fclose( $handle );
		}

		// Atomic rename.
		rename( $temp_file, $output_file );

		return true;
	}
}
