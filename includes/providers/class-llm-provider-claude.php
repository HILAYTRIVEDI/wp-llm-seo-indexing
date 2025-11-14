<?php
/**
 * Anthropic Claude LLM Provider
 *
 * Implementation for Anthropic Claude API.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic Claude provider class
 */
class WPLLMSEO_LLM_Provider_Claude extends WPLLMSEO_LLM_Provider_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id          = 'claude';
		$this->name        = 'Anthropic Claude';
		$this->description = __( 'Anthropic\'s Claude models for text generation', 'wpllmseo' );

		$this->rate_limits = array(
			'discover' => 10,
			'generate' => 30,
		);
	}

	/**
	 * Discover available models
	 *
	 * Note: Anthropic doesn't currently provide a public models list endpoint,
	 * so we return the default known models.
	 *
	 * @param string $api_key API key.
	 * @param array  $config  Optional configuration.
	 * @return array|WP_Error Array of models or error.
	 */
	public function discover_models( string $api_key, array $config = array() ) {
		// Validate API key by attempting a minimal request.
		$test_result = $this->test_model( 'claude-3-haiku-20240307', $api_key, $config );

		if ( is_wp_error( $test_result ) ) {
			$this->log( 'Model discovery validation failed: ' . $test_result->get_error_message(), 'error' );
			return $test_result;
		}

		return $this->get_default_models();
	}

	/**
	 * Get default models
	 *
	 * @return array Array of default models.
	 */
	public function get_default_models(): array {
		return array(
			$this->format_model(
				'claude-3-5-sonnet-20241022',
				'claude-3.5-sonnet',
				'chat',
				array(
					'description'    => __( 'Latest Claude 3.5 Sonnet - most intelligent model', 'wpllmseo' ),
					'context_window' => 200000,
					'max_tokens'     => 8192,
					'cost_per_1k'    => 3.0,
					'recommended'    => true,
				)
			),
			$this->format_model(
				'claude-3-opus-20240229',
				'claude-3-opus',
				'chat',
				array(
					'description'    => __( 'Claude 3 Opus - powerful performance', 'wpllmseo' ),
					'context_window' => 200000,
					'max_tokens'     => 4096,
					'cost_per_1k'    => 15.0,
				)
			),
			$this->format_model(
				'claude-3-sonnet-20240229',
				'claude-3-sonnet',
				'chat',
				array(
					'description'    => __( 'Claude 3 Sonnet - balanced performance', 'wpllmseo' ),
					'context_window' => 200000,
					'max_tokens'     => 4096,
					'cost_per_1k'    => 3.0,
				)
			),
			$this->format_model(
				'claude-3-haiku-20240307',
				'claude-3-haiku',
				'chat',
				array(
					'description'    => __( 'Claude 3 Haiku - fastest and most compact', 'wpllmseo' ),
					'context_window' => 200000,
					'max_tokens'     => 4096,
					'cost_per_1k'    => 0.25,
				)
			),
		);
	}

	/**
	 * Generate embeddings
	 *
	 * Note: Claude doesn't currently provide embedding models.
	 *
	 * @param string $text    Text to embed.
	 * @param string $model   Model ID.
	 * @param string $api_key API key.
	 * @param array  $config  Optional configuration.
	 * @return WP_Error Error indicating embeddings not supported.
	 */
	public function generate_embedding( string $text, string $model, string $api_key, array $config = array() ) {
		return new WP_Error(
			'not_supported',
			__( 'Claude does not currently support embedding generation', 'wpllmseo' )
		);
	}

	/**
	 * Generate text
	 *
	 * @param string $prompt  Prompt text.
	 * @param string $model   Model ID.
	 * @param string $api_key API key.
	 * @param array  $config  Optional configuration.
	 * @return array|WP_Error Array with 'text' or error.
	 */
	public function generate_text( string $prompt, string $model, string $api_key, array $config = array() ) {
		if ( ! $this->check_rate_limit( 'generate' ) ) {
			return new WP_Error( 'rate_limited', __( 'Generation rate limit exceeded', 'wpllmseo' ) );
		}

		$url = 'https://api.anthropic.com/v1/messages';

		$body = array(
			'model'      => $model,
			'max_tokens' => $config['max_tokens'] ?? 1024,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		if ( ! empty( $config['temperature'] ) ) {
			$body['temperature'] = (float) $config['temperature'];
		}

		if ( ! empty( $config['system'] ) ) {
			$body['system'] = $config['system'];
		}

		$response = $this->make_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Text generation failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['content'][0]['text'] ) ) {
			return new WP_Error( 'no_text', __( 'No text returned from API', 'wpllmseo' ) );
		}

		return array(
			'text'     => $data['content'][0]['text'],
			'model'    => $model,
			'provider' => $this->id,
			'usage'    => $data['usage'] ?? array(),
		);
	}

	/**
	 * Test model with quick call
	 *
	 * @param string $model   Model ID.
	 * @param string $api_key API key.
	 * @param array  $config  Optional configuration.
	 * @return array|WP_Error Test results or error.
	 */
	public function test_model( string $model, string $api_key, array $config = array() ) {
		$start_time = microtime( true );

		$config['max_tokens'] = 50; // Keep test small.

		$result = $this->generate_text( 'Say "Hello"', $model, $api_key, $config );

		$latency = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$cost_estimate = null;
		if ( ! empty( $result['usage']['input_tokens'] ) && ! empty( $result['usage']['output_tokens'] ) ) {
			$default_models = $this->get_default_models();
			foreach ( $default_models as $default_model ) {
				if ( $default_model['id'] === $model && ! empty( $default_model['cost_per_1k'] ) ) {
					$total_tokens  = $result['usage']['input_tokens'] + $result['usage']['output_tokens'];
					$cost_estimate = ( $total_tokens / 1000 ) * $default_model['cost_per_1k'];
					break;
				}
			}
		}

		return array(
			'success'       => true,
			'latency'       => $latency,
			'sample'        => $result['text'],
			'model'         => $model,
			'usage'         => $result['usage'] ?? array(),
			'cost_estimate' => $cost_estimate,
		);
	}

	/**
	 * Get configuration fields
	 *
	 * @return array Configuration field definitions.
	 */
	public function get_config_fields(): array {
		return array(
			array(
				'id'          => 'api_key',
				'label'       => __( 'API Key', 'wpllmseo' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Get your API key from Anthropic Console', 'wpllmseo' ),
				'link'        => 'https://console.anthropic.com/settings/keys',
			),
		);
	}

	/**
	 * Validate configuration
	 *
	 * @param array $config Configuration to validate.
	 * @return true|WP_Error True if valid, error otherwise.
	 */
	public function validate_config( array $config ) {
		if ( empty( $config['api_key'] ) ) {
			return new WP_Error( 'missing_api_key', __( 'API key is required', 'wpllmseo' ) );
		}

		// Test API key with a minimal request.
		$result = $this->test_model( 'claude-3-haiku-20240307', $config['api_key'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
