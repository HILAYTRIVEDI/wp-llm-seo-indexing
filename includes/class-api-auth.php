<?php
/**
 * API Authentication Middleware
 *
 * Handles API key authentication for gated endpoints.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_API_Auth
 *
 * Manages API key generation, validation, and gated access control.
 */
class WPLLMSEO_API_Auth {

	/**
	 * Option name for API keys.
	 */
	const OPTION_NAME = 'wpllmseo_api_keys';

	/**
	 * Initialize API auth.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes for API key management.
	 */
	public static function register_routes() {
		register_rest_route(
			'wpllmseo/v1',
			'/api-keys',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_api_keys' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			'wpllmseo/v1',
			'/api-keys',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_api_key' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				'args'                => array(
					'name' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'wpllmseo/v1',
			'/api-keys/(?P<id>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_api_key' ),
				'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Check if user has admin permission.
	 *
	 * @return bool
	 */
	public static function check_admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Generate a new API key.
	 *
	 * @param string $name Key name.
	 * @return array {id, key_hash, name, created, last_used}
	 */
	public static function generate_key( $name ) {
		$key_id   = 'wpllm_' . wp_generate_password( 12, false );
		$key      = wp_generate_password( 32, false );
		$key_hash = wp_hash_password( $key );

		$key_data = array(
			'id'         => $key_id,
			'key_hash'   => $key_hash,
			'name'       => sanitize_text_field( $name ),
			'created'    => current_time( 'mysql' ),
			'last_used'  => null,
		);

		$keys   = get_option( self::OPTION_NAME, array() );
		$keys[ $key_id ] = $key_data;
		update_option( self::OPTION_NAME, $keys );

		// Return plain key only once.
		$key_data['key'] = $key;
		return $key_data;
	}

	/**
	 * Validate API key.
	 *
	 * @param string $key API key to validate.
	 * @return bool|string False if invalid, key_id if valid.
	 */
	public static function validate_key( $key ) {
		if ( empty( $key ) ) {
			return false;
		}

		$keys = get_option( self::OPTION_NAME, array() );

		foreach ( $keys as $key_id => $key_data ) {
			if ( wp_check_password( $key, $key_data['key_hash'] ) ) {
				// Update last used timestamp.
				$keys[ $key_id ]['last_used'] = current_time( 'mysql' );
				update_option( self::OPTION_NAME, $keys );
				return $key_id;
			}
		}

		return false;
	}

	/**
	 * Check if request has valid API key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|string False if invalid, key_id if valid.
	 */
	public static function check_api_key( $request ) {
		$key = $request->get_header( 'X-API-Key' );
		
		if ( ! $key ) {
			$key = $request->get_param( 'api_key' );
		}

		return self::validate_key( $key );
	}

	/**
	 * Get all API keys (without hashes).
	 *
	 * @return array
	 */
	public static function get_keys() {
		$keys = get_option( self::OPTION_NAME, array() );
		
		// Remove sensitive hash data.
		foreach ( $keys as $key_id => &$key_data ) {
			unset( $key_data['key_hash'] );
		}

		return array_values( $keys );
	}

	/**
	 * Delete API key.
	 *
	 * @param string $key_id Key ID to delete.
	 * @return bool
	 */
	public static function delete_key( $key_id ) {
		$keys = get_option( self::OPTION_NAME, array() );
		
		if ( isset( $keys[ $key_id ] ) ) {
			unset( $keys[ $key_id ] );
			update_option( self::OPTION_NAME, $keys );
			return true;
		}

		return false;
	}

	/**
	 * REST: List API keys.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function list_api_keys( $request ) {
		return new WP_REST_Response( self::get_keys(), 200 );
	}

	/**
	 * REST: Create API key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function create_api_key( $request ) {
		$name     = $request->get_param( 'name' );
		$key_data = self::generate_key( $name );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'API key created successfully. Copy it now, it will not be shown again.',
				'key'     => $key_data['key'],
				'id'      => $key_data['id'],
				'name'    => $key_data['name'],
				'created' => $key_data['created'],
			),
			201
		);
	}

	/**
	 * REST: Delete API key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function delete_api_key( $request ) {
		$key_id = $request->get_param( 'id' );
		$result = self::delete_key( $key_id );

		if ( $result ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => 'API key deleted successfully.',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => 'API key not found.',
			),
			404
		);
	}

	/**
	 * Check if embeddings are gated.
	 *
	 * @return bool
	 */
	public static function are_embeddings_gated() {
		$settings = get_option( 'wpllmseo_settings', array() );
		return ! empty( $settings['gate_embeddings'] );
	}

	/**
	 * Permission callback for gated embeddings endpoints.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function check_embeddings_permission( $request ) {
		if ( ! self::are_embeddings_gated() ) {
			return true;
		}

		$key_id = self::check_api_key( $request );
		
		if ( $key_id ) {
			return true;
		}

		return new WP_Error(
			'unauthorized',
			'Valid API key required for embeddings access.',
			array( 'status' => 403 )
		);
	}
}
