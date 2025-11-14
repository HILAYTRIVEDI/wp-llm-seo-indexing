<?php
/**
 * Security and Nonce Management
 *
 * Centralized security utilities for CSRF protection.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Security
 *
 * Manages nonces and security validation.
 */
class WPLLMSEO_Security {

	/**
	 * Nonce key prefix
	 *
	 * @var string
	 */
	const NONCE_PREFIX = 'wpllmseo_nonce_';

	/**
	 * Create nonce for action
	 *
	 * @param string $action Action name.
	 * @return string Nonce value.
	 */
	public static function create_nonce( $action ) {
		return wp_create_nonce( self::NONCE_PREFIX . $action );
	}

	/**
	 * Verify nonce from request
	 *
	 * @param array  $request Request data ($_POST, $_GET, $_REQUEST).
	 * @param string $action  Action name.
	 * @param string $key     Nonce key in request (default: '_wpnonce').
	 * @return bool True if valid.
	 */
	public static function verify_nonce( $request, $action, $key = '_wpnonce' ) {
		if ( ! isset( $request[ $key ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $request[ $key ] ) );
		return wp_verify_nonce( $nonce, self::NONCE_PREFIX . $action );
	}

	/**
	 * Verify nonce or die
	 *
	 * @param array  $request Request data.
	 * @param string $action  Action name.
	 * @param string $key     Nonce key.
	 */
	public static function verify_nonce_or_die( $request, $action, $key = '_wpnonce' ) {
		if ( ! self::verify_nonce( $request, $action, $key ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh and try again.', 'wpllmseo' ) );
		}
	}

	/**
	 * Create nonce URL
	 *
	 * @param string $url    Base URL.
	 * @param string $action Action name.
	 * @return string URL with nonce.
	 */
	public static function nonce_url( $url, $action ) {
		return wp_nonce_url( $url, self::NONCE_PREFIX . $action );
	}

	/**
	 * Verify REST nonce
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool True if valid.
	 */
	public static function verify_rest_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		
		if ( ! $nonce ) {
			return false;
		}

		return wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Sanitize array recursively
	 *
	 * @param array $array Input array.
	 * @return array Sanitized array.
	 */
	public static function sanitize_array( $array ) {
		if ( ! is_array( $array ) ) {
			return array();
		}

		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$array[ $key ] = self::sanitize_array( $value );
			} else {
				$array[ $key ] = sanitize_text_field( $value );
			}
		}

		return $array;
	}

	/**
	 * Validate email
	 *
	 * @param string $email Email address.
	 * @return string|false Sanitized email or false.
	 */
	public static function sanitize_email( $email ) {
		return sanitize_email( $email );
	}

	/**
	 * Sanitize URL
	 *
	 * @param string $url URL.
	 * @return string Sanitized URL.
	 */
	public static function sanitize_url( $url ) {
		return esc_url_raw( $url );
	}

	/**
	 * Validate API key format
	 *
	 * @param string $api_key API key.
	 * @return bool True if valid format.
	 */
	public static function validate_api_key( $api_key ) {
		// Gemini API keys start with AIza and are alphanumeric + _ -
		return (bool) preg_match( '/^AIza[a-zA-Z0-9_-]{35}$/', $api_key );
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool True if debug enabled.
	 */
	public static function is_debug_enabled() {
		return ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' && current_user_can( 'manage_options' ) );
	}

	/**
	 * Prevent directory listing
	 *
	 * Creates .htaccess file to block directory browsing.
	 *
	 * @param string $directory Directory path.
	 */
	public static function prevent_directory_listing( $directory ) {
		$htaccess_file = trailingslashit( $directory ) . '.htaccess';

		if ( file_exists( $htaccess_file ) ) {
			return;
		}

		$content = "Options -Indexes\n";
		$content .= "<FilesMatch \"\\.(log|txt|jsonl)$\">\n";
		$content .= "  Order Allow,Deny\n";
		$content .= "  Deny from all\n";
		$content .= "</FilesMatch>\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess_file, $content );
	}

	/**
	 * Create index.php to prevent directory listing
	 *
	 * @param string $directory Directory path.
	 */
	public static function create_index_file( $directory ) {
		$index_file = trailingslashit( $directory ) . 'index.php';

		if ( file_exists( $index_file ) ) {
			return;
		}

		$content = "<?php\n// Silence is golden.\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index_file, $content );
	}

	/**
	 * Validate file permissions
	 *
	 * @param string $path File or directory path.
	 * @return bool True if writable.
	 */
	public static function is_writable( $path ) {
		return is_writable( $path );
	}

	/**
	 * Get sanitized POST data
	 *
	 * @param string $key     POST key.
	 * @param mixed  $default Default value.
	 * @return mixed Sanitized value.
	 */
	public static function get_post( $key, $default = '' ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Get sanitized GET data
	 *
	 * @param string $key     GET key.
	 * @param mixed  $default Default value.
	 * @return mixed Sanitized value.
	 */
	public static function get_request( $key, $default = '' ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}
}
