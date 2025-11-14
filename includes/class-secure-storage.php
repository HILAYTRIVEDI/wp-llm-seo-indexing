<?php
/**
 * Secure Storage Class
 *
 * Handles secure storage of API keys and sensitive data with autoload disabled.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Secure_Storage
 */
class WPLLMSEO_Secure_Storage {

	/**
	 * Option prefix for secure keys
	 */
	const SECURE_PREFIX = 'wpllmseo_secure_';

	/**
	 * Store a secure value (API key, token, etc.)
	 *
	 * @param string $key   Key name (without prefix).
	 * @param string $value Value to store.
	 * @return bool True on success, false on failure.
	 */
	public static function store( $key, $value ) {
		$option_name = self::SECURE_PREFIX . $key;
		
		// Delete existing option first to ensure autoload is set correctly
		delete_option( $option_name );
		
		// Add with autoload disabled
		return add_option( $option_name, $value, '', 'no' );
	}

	/**
	 * Retrieve a secure value
	 *
	 * @param string $key     Key name (without prefix).
	 * @param mixed  $default Default value if key doesn't exist.
	 * @return mixed Stored value or default.
	 */
	public static function get( $key, $default = '' ) {
		$option_name = self::SECURE_PREFIX . $key;
		return get_option( $option_name, $default );
	}

	/**
	 * Delete a secure value
	 *
	 * @param string $key Key name (without prefix).
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $key ) {
		$option_name = self::SECURE_PREFIX . $key;
		return delete_option( $option_name );
	}

	/**
	 * Mask an API key for display
	 *
	 * @param string $key API key to mask.
	 * @return string Masked key showing only last 4 characters.
	 */
	public static function mask_key( $key ) {
		if ( empty( $key ) || strlen( $key ) < 8 ) {
			return '';
		}

		$visible_length = 4;
		$masked_length = strlen( $key ) - $visible_length;
		
		return str_repeat( '•', $masked_length ) . substr( $key, -$visible_length );
	}

	/**
	 * Check if a key is already masked
	 *
	 * @param string $key Key to check.
	 * @return bool True if masked, false otherwise.
	 */
	public static function is_masked( $key ) {
		return strpos( $key, '•' ) !== false;
	}

	/**
	 * Migrate existing settings to secure storage
	 *
	 * @return array Migration results.
	 */
	public static function migrate_legacy_keys() {
		$settings = get_option( 'wpllmseo_settings', array() );
		$migrated = array();

		// List of sensitive keys to migrate
		$sensitive_keys = array(
			'api_key',
			'sitemap_hub_token',
			'llm_api_token',
		);

		foreach ( $sensitive_keys as $key ) {
			if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
				// Store in secure location
				self::store( $key, $settings[ $key ] );
				
				// Remove from main settings
				unset( $settings[ $key ] );
				
				$migrated[] = $key;
			}
		}

		// Update settings without sensitive keys
		if ( ! empty( $migrated ) ) {
			update_option( 'wpllmseo_settings', $settings, 'no' );
		}

		return array(
			'migrated' => $migrated,
			'count'    => count( $migrated ),
		);
	}

	/**
	 * Get all provider API keys securely
	 *
	 * @return array Array of provider => key pairs (unmasked).
	 */
	public static function get_provider_keys() {
		return array(
			'gemini'  => self::get( 'provider_key_gemini', '' ),
			'openai'  => self::get( 'provider_key_openai', '' ),
			'claude'  => self::get( 'provider_key_claude', '' ),
		);
	}

	/**
	 * Store provider API key securely
	 *
	 * @param string $provider Provider name (gemini, openai, claude).
	 * @param string $key      API key.
	 * @return bool True on success.
	 */
	public static function store_provider_key( $provider, $key ) {
		$provider = sanitize_key( $provider );
		return self::store( 'provider_key_' . $provider, $key );
	}

	/**
	 * Get masked provider keys for display
	 *
	 * @return array Array of provider => masked_key pairs.
	 */
	public static function get_provider_keys_masked() {
		$keys = self::get_provider_keys();
		
		foreach ( $keys as $provider => $key ) {
			$keys[ $provider ] = self::mask_key( $key );
		}
		
		return $keys;
	}

	/**
	 * Validate that sensitive data is not in autoloaded options
	 *
	 * @return array Validation results.
	 */
	public static function validate_security() {
		global $wpdb;
		
		$issues = array();
		
		// Check if wpllmseo_settings is autoloaded with sensitive data
		$settings = get_option( 'wpllmseo_settings', array() );
		$sensitive_in_settings = array();
		
		foreach ( array( 'api_key', 'sitemap_hub_token', 'llm_api_token' ) as $key ) {
			if ( isset( $settings[ $key ] ) && ! empty( $settings[ $key ] ) ) {
				$sensitive_in_settings[] = $key;
			}
		}
		
		if ( ! empty( $sensitive_in_settings ) ) {
			$issues[] = array(
				'type'    => 'sensitive_in_settings',
				'message' => 'Sensitive keys found in main settings: ' . implode( ', ', $sensitive_in_settings ),
				'fix'     => 'Run migration: WPLLMSEO_Secure_Storage::migrate_legacy_keys()',
			);
		}
		
		// Check autoload status
		$autoload_check = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wpllmseo_settings'
			)
		);
		
		if ( 'yes' === $autoload_check ) {
			$issues[] = array(
				'type'    => 'settings_autoloaded',
				'message' => 'Main settings option is autoloaded',
				'fix'     => 'Set autoload to no for wpllmseo_settings',
			);
		}
		
		return array(
			'secure'  => empty( $issues ),
			'issues'  => $issues,
			'checked' => current_time( 'mysql' ),
		);
	}
}
