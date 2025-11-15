<?php
/**
 * LLM Provider Manager
 *
 * Manages multiple LLM providers, model discovery, and caching.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider manager class
 */
class WPLLMSEO_Provider_Manager {

	/**
	 * Registered providers
	 *
	 * @var array
	 */
	private static $providers = array();

	/**
	 * Model cache TTL in seconds
	 *
	 * @var int
	 */
	const CACHE_TTL = 86400; // 24 hours

	/**
	 * Initialize manager
	 */
	public static function init() {
		// Register built-in providers.
		self::register_provider( new WPLLMSEO_LLM_Provider_Gemini() );
		self::register_provider( new WPLLMSEO_LLM_Provider_OpenAI() );
		self::register_provider( new WPLLMSEO_LLM_Provider_Claude() );

		// Allow custom providers via filter.
		$custom_providers = apply_filters( 'wpllmseo_custom_providers', array() );
		foreach ( $custom_providers as $provider ) {
			if ( $provider instanceof WPLLMSEO_LLM_Provider_Interface ) {
				self::register_provider( $provider );
			}
		}

		// Register AJAX handlers.
		add_action( 'wp_ajax_wpllmseo_discover_models', array( __CLASS__, 'ajax_discover_models' ) );
		add_action( 'wp_ajax_wpllmseo_test_model', array( __CLASS__, 'ajax_test_model' ) );
		add_action( 'wp_ajax_wpllmseo_clear_model_cache', array( __CLASS__, 'ajax_clear_cache' ) );
	}

	/**
	 * Register a provider
	 *
	 * @param WPLLMSEO_LLM_Provider_Interface $provider Provider instance.
	 */
	public static function register_provider( WPLLMSEO_LLM_Provider_Interface $provider ) {
		self::$providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get all registered providers
	 *
	 * @return array Array of provider instances.
	 */
	public static function get_providers(): array {
		return self::$providers;
	}

	/**
	 * Get provider by ID
	 *
	 * @param string $provider_id Provider ID.
	 * @return WPLLMSEO_LLM_Provider_Interface|null Provider instance or null.
	 */
	public static function get_provider( string $provider_id ) {
		return self::$providers[ $provider_id ] ?? null;
	}

	/**
	 * Get provider configuration from settings
	 *
	 * @param string $provider_id Provider ID.
	 * @return array Provider configuration.
	 */
	public static function get_provider_config( string $provider_id ): array {
		$settings = get_option( 'wpllmseo_settings', array() );
		return $settings['providers'][ $provider_id ] ?? array();
	}

	/**
	 * Save provider configuration
	 *
	 * @param string $provider_id Provider ID.
	 * @param array  $config      Configuration data.
	 * @return bool True on success.
	 */
	public static function save_provider_config( string $provider_id, array $config ): bool {
		$settings = get_option( 'wpllmseo_settings', array() );

		if ( ! isset( $settings['providers'] ) ) {
			$settings['providers'] = array();
		}

		$settings['providers'][ $provider_id ] = $config;

		$result = update_option( 'wpllmseo_settings', $settings );
		if ( $result ) {
			wpllmseo_log( 'Provider config saved', 'info', array( 'provider' => $provider_id, 'config_keys' => array_keys( $config ) ) );
		} else {
			wpllmseo_log( 'Provider config NOT saved', 'error', array( 'provider' => $provider_id ) );
		}

		return $result;
	}

	/**
	 * Get cached models for provider
	 *
	 * @param string $provider_id Provider ID.
	 * @return array|false Cached models or false.
	 */
	public static function get_cached_models( string $provider_id ) {
		return get_transient( 'wpllmseo_models_' . $provider_id );
	}

	/**
	 * Cache models for provider
	 *
	 * @param string $provider_id Provider ID.
	 * @param array  $models      Models array.
	 * @param int    $ttl         Cache TTL in seconds.
	 * @return bool True on success.
	 */
	public static function cache_models( string $provider_id, array $models, int $ttl = null ): bool {
		if ( null === $ttl ) {
			$ttl = self::CACHE_TTL;
		}

		// Allow TTL customization via filter.
		$ttl = apply_filters( 'wpllmseo_model_cache_ttl', $ttl, $provider_id );

		return set_transient( 'wpllmseo_models_' . $provider_id, $models, $ttl );
	}

	/**
	 * Clear model cache for provider
	 *
	 * @param string $provider_id Provider ID.
	 * @return bool True on success.
	 */
	public static function clear_cache( string $provider_id ): bool {
		return delete_transient( 'wpllmseo_models_' . $provider_id );
	}

	/**
	 * Discover models for provider
	 *
	 * @param string $provider_id Provider ID.
	 * @param bool   $force       Force discovery even if cached.
	 * @return array|WP_Error Array of models or error.
	 */
	public static function discover_models( string $provider_id, bool $force = false ) {
		// Check cache first unless forcing.
		if ( ! $force ) {
			$cached = self::get_cached_models( $provider_id );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$provider = self::get_provider( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'invalid_provider', __( 'Invalid provider ID', 'wpllmseo' ) );
		}

		$config = self::get_provider_config( $provider_id );
		if ( empty( $config['api_key'] ) ) {
			return new WP_Error( 'missing_api_key', __( 'API key not configured', 'wpllmseo' ) );
		}

		// Discover models.
		$models = $provider->discover_models( $config['api_key'], $config );

		if ( is_wp_error( $models ) ) {
			// On error, use default models.
			$models = $provider->get_default_models();
		}

		// Cache the result.
		self::cache_models( $provider_id, $models );

		return $models;
	}

	/**
	 * Get models grouped by capability
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $capability  Optional capability filter (embedding, generation, chat).
	 * @return array|WP_Error Array of models grouped by capability.
	 */
	public static function get_models_by_capability( string $provider_id, string $capability = '' ) {
		$models = self::discover_models( $provider_id );

		if ( is_wp_error( $models ) ) {
			return $models;
		}

		if ( empty( $capability ) ) {
			// Group all models by capability.
			$grouped = array();
			foreach ( $models as $model ) {
				$cap = $model['capability'] ?? 'other';
				if ( ! isset( $grouped[ $cap ] ) ) {
					$grouped[ $cap ] = array();
				}
				$grouped[ $cap ][] = $model;
			}
			return $grouped;
		}

		// Filter by specific capability.
		return array_filter(
			$models,
			function ( $model ) use ( $capability ) {
				return ( $model['capability'] ?? '' ) === $capability;
			}
		);
	}

	/**
	 * Get active provider for a feature
	 *
	 * @param string $feature Feature name (embedding, generation).
	 * @return string|null Provider ID or null.
	 */
	public static function get_active_provider( string $feature ): ?string {
		$settings = get_option( 'wpllmseo_settings', array() );
		return $settings['active_providers'][ $feature ] ?? null;
	}

	/**
	 * Get active model for a feature
	 *
	 * @param string $feature Feature name (embedding, generation).
	 * @return string|null Model ID or null.
	 */
	public static function get_active_model( string $feature ): ?string {
		$settings = get_option( 'wpllmseo_settings', array() );
		$model = $settings['active_models'][ $feature ] ?? null;
		wpllmseo_log( sprintf( 'get_active_model called for feature=%s -> %s', $feature, $model ), 'debug' );
		return $model;
	}

	/**
	 * AJAX handler for model discovery
	 */
	public static function ajax_discover_models() {
		check_ajax_referer( 'wpllmseo_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wpllmseo' ) ) );
		}

		$provider_id = isset( $_POST['provider_id'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_id'] ) ) : '';
		$force       = isset( $_POST['force'] ) && $_POST['force'];

		if ( empty( $provider_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider ID required', 'wpllmseo' ) ) );
		}

		$models = self::discover_models( $provider_id, $force );

		if ( is_wp_error( $models ) ) {
			wp_send_json_error(
				array(
					'message' => $models->get_error_message(),
					'code'    => $models->get_error_code(),
				)
			);
		}

		wp_send_json_success(
			array(
				'models' => $models,
				'count'  => count( $models ),
			)
		);
	}

	/**
	 * AJAX handler for model testing
	 */
	public static function ajax_test_model() {
		check_ajax_referer( 'wpllmseo_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wpllmseo' ) ) );
		}

		$provider_id = isset( $_POST['provider_id'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_id'] ) ) : '';
		$model_id    = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';

		if ( empty( $provider_id ) || empty( $model_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider and model IDs required', 'wpllmseo' ) ) );
		}

		$provider = self::get_provider( $provider_id );
		if ( ! $provider ) {
			wp_send_json_error( array( 'message' => __( 'Invalid provider', 'wpllmseo' ) ) );
		}

		$config = self::get_provider_config( $provider_id );
		if ( empty( $config['api_key'] ) ) {
			wp_send_json_error( array( 'message' => __( 'API key not configured', 'wpllmseo' ) ) );
		}

		$result = $provider->test_model( $model_id, $config['api_key'], $config );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for clearing cache
	 */
	public static function ajax_clear_cache() {
		check_ajax_referer( 'wpllmseo_admin_action', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'wpllmseo' ) ) );
		}

		$provider_id = isset( $_POST['provider_id'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_id'] ) ) : '';

		if ( empty( $provider_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Provider ID required', 'wpllmseo' ) ) );
		}

		$cleared = self::clear_cache( $provider_id );

		if ( $cleared ) {
			wp_send_json_success( array( 'message' => __( 'Cache cleared successfully', 'wpllmseo' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to clear cache', 'wpllmseo' ) ) );
		}
	}
}
