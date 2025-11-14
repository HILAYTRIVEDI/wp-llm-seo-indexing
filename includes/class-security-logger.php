<?php
/**
 * Security Logger Class
 *
 * Handles secure logging with automatic redaction of sensitive fields.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Security_Logger
 */
class WPLLMSEO_Security_Logger {

	/**
	 * Sensitive field names to redact
	 *
	 * @var array
	 */
	private static $sensitive_fields = array(
		'api_key',
		'apiKey',
		'api-key',
		'token',
		'access_token',
		'accessToken',
		'password',
		'client_secret',
		'clientSecret',
		'client-secret',
		'secret',
		'authorization',
		'auth',
		'bearer',
		'private_key',
		'privateKey',
		'private-key',
		'x-api-key',
	);

	/**
	 * Redact sensitive fields from data
	 *
	 * @param mixed $data Data to redact.
	 * @return mixed Redacted data.
	 */
	public static function redact_sensitive_fields( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				// Check if key matches any sensitive field (case-insensitive).
				if ( self::is_sensitive_field( $key ) ) {
					$data[ $key ] = '[REDACTED]';
				} elseif ( is_array( $value ) || is_object( $value ) ) {
					// Recursively redact nested structures.
					$data[ $key ] = self::redact_sensitive_fields( $value );
				}
			}
		} elseif ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				if ( self::is_sensitive_field( $key ) ) {
					$data->$key = '[REDACTED]';
				} elseif ( is_array( $value ) || is_object( $value ) ) {
					$data->$key = self::redact_sensitive_fields( $value );
				}
			}
		}

		return $data;
	}

	/**
	 * Check if field name is sensitive
	 *
	 * @param string $field_name Field name to check.
	 * @return bool True if sensitive.
	 */
	private static function is_sensitive_field( $field_name ) {
		$field_lower = strtolower( $field_name );
		
		foreach ( self::$sensitive_fields as $sensitive ) {
			if ( strpos( $field_lower, strtolower( $sensitive ) ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Log message with automatic redaction
	 *
	 * @param string $message Log message.
	 * @param mixed  $data    Optional data to log (will be redacted).
	 * @param string $level   Log level (info, warning, error).
	 */
	public static function log( $message, $data = null, $level = 'info' ) {
		$settings = get_option( 'wpllmseo_settings', array() );
		
		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		// Redact data before logging.
		if ( $data !== null ) {
			$data = self::redact_sensitive_fields( $data );
		}

		$log_file  = WPLLMSEO_PLUGIN_DIR . 'var/logs/plugin.log';
		$timestamp = current_time( 'Y-m-d H:i:s' );
		
		$log_entry = sprintf(
			"[%s] [%s] %s",
			$timestamp,
			strtoupper( $level ),
			$message
		);

		if ( $data !== null ) {
			$log_entry .= ' | Data: ' . wp_json_encode( $data, JSON_PRETTY_PRINT );
		}

		$log_entry .= "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $log_file, $log_entry, FILE_APPEND );
	}

	/**
	 * Log error
	 *
	 * @param string $message Error message.
	 * @param mixed  $data    Optional data.
	 */
	public static function error( $message, $data = null ) {
		self::log( $message, $data, 'error' );
	}

	/**
	 * Log warning
	 *
	 * @param string $message Warning message.
	 * @param mixed  $data    Optional data.
	 */
	public static function warning( $message, $data = null ) {
		self::log( $message, $data, 'warning' );
	}

	/**
	 * Log info
	 *
	 * @param string $message Info message.
	 * @param mixed  $data    Optional data.
	 */
	public static function info( $message, $data = null ) {
		self::log( $message, $data, 'info' );
	}
}
