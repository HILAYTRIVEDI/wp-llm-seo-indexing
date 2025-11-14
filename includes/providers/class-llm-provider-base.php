<?php
/**
 * Base LLM Provider Abstract Class
 *
 * Shared functionality for all LLM providers.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for LLM providers
 */
abstract class WPLLMSEO_LLM_Provider_Base implements WPLLMSEO_LLM_Provider_Interface {

	/**
	 * Provider ID
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Provider description
	 *
	 * @var string
	 */
	protected $description;

	/**
	 * Rate limiter for API calls
	 *
	 * @var array
	 */
	protected $rate_limits = array();

	/**
	 * Get provider ID
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get provider name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get provider description
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Make HTTP request with retry logic
	 *
	 * @param string $url     Request URL.
	 * @param array  $args    Request arguments.
	 * @param int    $retries Number of retries on failure.
	 * @return array|WP_Error Response array or error.
	 */
	protected function make_request( string $url, array $args = array(), int $retries = 3 ) {
		$attempt = 0;

		while ( $attempt < $retries ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$attempt++;
				if ( $attempt < $retries ) {
					// Exponential backoff.
					sleep( pow( 2, $attempt ) );
					continue;
				}
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Rate limit - wait and retry.
			if ( 429 === $code ) {
				$attempt++;
				if ( $attempt < $retries ) {
					sleep( pow( 2, $attempt ) );
					continue;
				}
				return new WP_Error( 'rate_limit', __( 'Rate limit exceeded', 'wpllmseo' ) );
			}

			// Success.
			if ( $code >= 200 && $code < 300 ) {
				return $response;
			}

			// Client or server error.
			return new WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP error: %d', 'wpllmseo' ),
					$code
				)
			);
		}

		return new WP_Error( 'max_retries', __( 'Maximum retries exceeded', 'wpllmseo' ) );
	}

	/**
	 * Parse JSON response
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error Parsed JSON or error.
	 */
	protected function parse_json_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'json_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse JSON: %s', 'wpllmseo' ),
					json_last_error_msg()
				)
			);
		}

		return $data;
	}

	/**
	 * Check rate limit for provider
	 *
	 * @param string $operation Operation name.
	 * @return bool True if allowed, false if rate limited.
	 */
	protected function check_rate_limit( string $operation ): bool {
		$key     = $this->id . '_' . $operation;
		$current = get_transient( 'wpllmseo_rate_limit_' . $key );

		if ( false === $current ) {
			set_transient( 'wpllmseo_rate_limit_' . $key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		$limit = $this->rate_limits[ $operation ] ?? 60;

		if ( $current >= $limit ) {
			return false;
		}

		set_transient( 'wpllmseo_rate_limit_' . $key, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Format model for UI display
	 *
	 * @param string $id          Model ID.
	 * @param string $name        Model display name.
	 * @param string $capability  Model capability (embedding, generation, chat).
	 * @param array  $metadata    Optional metadata (description, limits, etc.).
	 * @return array Formatted model array.
	 */
	protected function format_model( string $id, string $name, string $capability, array $metadata = array() ): array {
		return array(
			'id'          => $id,
			'name'        => $name,
			'capability'  => $capability,
			'label'       => sprintf( '%s · %s', $name, $capability ),
			'description' => $metadata['description'] ?? '',
			'context_window' => $metadata['context_window'] ?? null,
			'max_tokens'  => $metadata['max_tokens'] ?? null,
			'cost_per_1k' => $metadata['cost_per_1k'] ?? null,
			'provider'    => $this->id,
		);
	}

	/**
	 * Log provider activity
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (info, warning, error).
	 * @param array  $context Additional context data.
	 */
	protected function log( string $message, string $level = 'info', array $context = array() ): void {
		if ( function_exists( 'wpllmseo_log' ) ) {
			$context['provider'] = $this->id;
			wpllmseo_log(
				sprintf( '[%s] %s', strtoupper( $this->id ), $message ),
				$level
			);
		}
	}
}
