<?php
/**
 * OpenAI LLM Provider
 *
 * Implementation for OpenAI API.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI provider class
 */
class WPLLMSEO_LLM_Provider_OpenAI extends WPLLMSEO_LLM_Provider_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id          = 'openai';
		$this->name        = 'OpenAI';
		$this->description = __( 'OpenAI GPT models for embeddings and text generation', 'wpllmseo' );

		$this->rate_limits = array(
			'discover' => 10,
			'embed'    => 60,
			'generate' => 30,
		);
	}

	/**
	 * Discover available models
	 *
	 * @param string $api_key API key.
	 * @param array  $config  Optional configuration.
	 * @return array|WP_Error Array of models or error.
	 */
	public function discover_models( string $api_key, array $config = array() ) {
		if ( ! $this->check_rate_limit( 'discover' ) ) {
			return new WP_Error( 'rate_limited', __( 'Model discovery rate limit exceeded', 'wpllmseo' ) );
		}

		$url = 'https://api.openai.com/v1/models';

		$response = $this->make_request(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Model discovery failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['data'] ) ) {
			return $this->get_default_models();
		}

		$models = array();

		foreach ( $data['data'] as $model ) {
			$model_id = $model['id'] ?? '';

			// Categorize embedding models.
			if ( strpos( $model_id, 'embedding' ) !== false || strpos( $model_id, 'ada' ) !== false ) {
				$models[] = $this->format_model(
					$model_id,
					$model_id,
					'embedding',
					array(
						'description' => __( 'OpenAI embedding model', 'wpllmseo' ),
					)
				);
			}

			// Categorize GPT models.
			if ( strpos( $model_id, 'gpt-' ) === 0 ) {
				$capability = 'generation';
				if ( strpos( $model_id, 'gpt-4' ) === 0 || strpos( $model_id, 'gpt-3.5-turbo' ) === 0 ) {
					$capability = 'chat';
				}

				$models[] = $this->format_model(
					$model_id,
					$model_id,
					$capability,
					array(
						'description' => sprintf(
							/* translators: %s: model id */
							__( 'OpenAI %s model', 'wpllmseo' ),
							$model_id
						),
					)
				);
			}
		}

		$this->log( sprintf( 'Discovered %d models', count( $models ) ), 'info' );

		return $models;
	}

	/**
	 * Get default models
	 *
	 * @return array Array of default models.
	 */
	public function get_default_models(): array {
		return array(
			$this->format_model(
				'text-embedding-3-small',
				'text-embedding-3-small',
				'embedding',
				array(
					'description'    => __( 'Latest small embedding model with 1536 dimensions', 'wpllmseo' ),
					'context_window' => 8191,
					'cost_per_1k'    => 0.00002,
					'recommended'    => true,
				)
			),
			$this->format_model(
				'text-embedding-3-large',
				'text-embedding-3-large',
				'embedding',
				array(
					'description'    => __( 'Latest large embedding model with 3072 dimensions', 'wpllmseo' ),
					'context_window' => 8191,
					'cost_per_1k'    => 0.00013,
				)
			),
			$this->format_model(
				'text-embedding-ada-002',
				'text-embedding-ada-002',
				'embedding',
				array(
					'description'    => __( 'Previous generation embedding model', 'wpllmseo' ),
					'context_window' => 8191,
					'cost_per_1k'    => 0.0001,
				)
			),
			$this->format_model(
				'gpt-4o-mini',
				'gpt-4o-mini',
				'chat',
				array(
					'description'    => __( 'Fast and affordable GPT-4 model', 'wpllmseo' ),
					'context_window' => 128000,
					'max_tokens'     => 16384,
					'cost_per_1k'    => 0.15,
					'recommended'    => true,
				)
			),
			$this->format_model(
				'gpt-4-turbo',
				'gpt-4-turbo',
				'chat',
				array(
					'description'    => __( 'Latest GPT-4 Turbo model', 'wpllmseo' ),
					'context_window' => 128000,
					'max_tokens'     => 4096,
					'cost_per_1k'    => 10.0,
				)
			),
			$this->format_model(
				'gpt-3.5-turbo',
				'gpt-3.5-turbo',
				'chat',
				array(
					'description'    => __( 'Fast GPT-3.5 model', 'wpllmseo' ),
					'context_window' => 16385,
					'max_tokens'     => 4096,
					'cost_per_1k'    => 0.5,
				)
			),
		);
	}

	/**
	 * Generate embeddings
	 *
	 * @param string $text    Text to embed.
	 * @param string $model   Model ID.
	 * @param string $api_key API key.
	 * @param array  $config  Optional configuration.
	 * @return array|WP_Error Array with 'embedding' or error.
	 */
	public function generate_embedding( string $text, string $model, string $api_key, array $config = array() ) {
		if ( ! $this->check_rate_limit( 'embed' ) ) {
			return new WP_Error( 'rate_limited', __( 'Embedding rate limit exceeded', 'wpllmseo' ) );
		}

		$url = 'https://api.openai.com/v1/embeddings';

		$body = array(
			'input' => $text,
			'model' => $model,
		);

		$response = $this->make_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Embedding generation failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['data'][0]['embedding'] ) ) {
			return new WP_Error( 'no_embedding', __( 'No embedding returned from API', 'wpllmseo' ) );
		}

		return array(
			'embedding' => $data['data'][0]['embedding'],
			'model'     => $model,
			'provider'  => $this->id,
			'usage'     => $data['usage'] ?? array(),
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

		$url = 'https://api.openai.com/v1/chat/completions';

		$body = array(
			'model'    => $model,
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		if ( ! empty( $config['temperature'] ) ) {
			$body['temperature'] = (float) $config['temperature'];
		}

		if ( ! empty( $config['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $config['max_tokens'];
		}

		$response = $this->make_request(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Text generation failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'no_text', __( 'No text returned from API', 'wpllmseo' ) );
		}

		return array(
			'text'     => $data['choices'][0]['message']['content'],
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

		$is_embedding = strpos( $model, 'embedding' ) !== false || strpos( $model, 'ada' ) !== false;

		if ( $is_embedding ) {
			$result = $this->generate_embedding( 'Test embedding', $model, $api_key, $config );
		} else {
			$result = $this->generate_text( 'Say "Hello"', $model, $api_key, $config );
		}

		$latency = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$cost_estimate = null;
		if ( ! empty( $result['usage']['total_tokens'] ) ) {
			$default_models = $this->get_default_models();
			foreach ( $default_models as $default_model ) {
				if ( $default_model['id'] === $model && ! empty( $default_model['cost_per_1k'] ) ) {
					$cost_estimate = ( $result['usage']['total_tokens'] / 1000 ) * $default_model['cost_per_1k'];
					break;
				}
			}
		}

		return array(
			'success'       => true,
			'latency'       => $latency,
			'sample'        => $is_embedding ? count( $result['embedding'] ) . ' dimensions' : $result['text'],
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
				'required'    => false,
				'description' => __( 'Get your API key from OpenAI platform', 'wpllmseo' ),
				'link'        => 'https://platform.openai.com/api-keys',
			),
			array(
				'id'          => 'organization',
				'label'       => __( 'Organization ID', 'wpllmseo' ),
				'type'        => 'text',
				'required'    => false,
				'description' => __( 'Optional organization ID for team accounts', 'wpllmseo' ),
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

		// Test API key with models list call.
		$result = $this->discover_models( $config['api_key'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
