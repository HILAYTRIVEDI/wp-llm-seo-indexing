<?php
/**
 * Google Gemini LLM Provider
 *
 * Implementation for Google Gemini API.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Gemini provider class
 */
class WPLLMSEO_LLM_Provider_Gemini extends WPLLMSEO_LLM_Provider_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id          = 'gemini';
		$this->name        = 'Google Gemini';
		$this->description = __( 'Google\'s Gemini models for embeddings and text generation', 'wpllmseo' );

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

		// Gemini API models endpoint.
		$url = 'https://generativelanguage.googleapis.com/v1beta/models';

		$response = $this->make_request(
			add_query_arg( 'key', $api_key, $url ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Model discovery failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['models'] ) ) {
			return $this->get_default_models();
		}

		$models = array();

		foreach ( $data['models'] as $model ) {
			$model_id   = $model['name'] ?? '';
			$model_name = str_replace( 'models/', '', $model_id );

			// Categorize by supported generation methods.
			$methods = $model['supportedGenerationMethods'] ?? array();

			if ( in_array( 'embedContent', $methods, true ) || in_array( 'batchEmbedContents', $methods, true ) ) {
				$models[] = $this->format_model(
					$model_id,
					$model_name,
					'embedding',
					array(
						'description'    => $model['description'] ?? '',
						'context_window' => $model['inputTokenLimit'] ?? null,
						'max_tokens'     => $model['outputTokenLimit'] ?? null,
					)
				);
			}

			if ( in_array( 'generateContent', $methods, true ) ) {
				$capability = in_array( 'countTokens', $methods, true ) ? 'chat' : 'generation';

				$models[] = $this->format_model(
					$model_id,
					$model_name,
					$capability,
					array(
						'description'    => $model['description'] ?? '',
						'context_window' => $model['inputTokenLimit'] ?? null,
						'max_tokens'     => $model['outputTokenLimit'] ?? null,
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
				'models/text-embedding-004',
				'text-embedding-004',
				'embedding',
				array(
					'description'    => __( 'Latest Gemini embedding model with 768 dimensions', 'wpllmseo' ),
					'context_window' => 2048,
					'recommended'    => true,
				)
			),
			$this->format_model(
				'models/embedding-001',
				'embedding-001',
				'embedding',
				array(
					'description'    => __( 'Previous generation embedding model', 'wpllmseo' ),
					'context_window' => 2048,
				)
			),
			$this->format_model(
				'models/gemini-pro',
				'gemini-pro',
				'generation',
				array(
					'description'    => __( 'Gemini Pro for text generation', 'wpllmseo' ),
					'context_window' => 30720,
					'max_tokens'     => 2048,
					'recommended'    => true,
				)
			),
			$this->format_model(
				'models/gemini-1.5-pro',
				'gemini-1.5-pro',
				'generation',
				array(
					'description'    => __( 'Gemini 1.5 Pro with extended context window', 'wpllmseo' ),
					'context_window' => 1048576,
					'max_tokens'     => 8192,
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

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s:embedContent',
			$model
		);

		$body = array(
			'content' => array(
				'parts' => array(
					array( 'text' => $text ),
				),
			),
		);

		$response = $this->make_request(
			add_query_arg( 'key', $api_key, $url ),
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Embedding generation failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['embedding']['values'] ) ) {
			return new WP_Error( 'no_embedding', __( 'No embedding returned from API', 'wpllmseo' ) );
		}

		return array(
			'embedding' => $data['embedding']['values'],
			'model'     => $model,
			'provider'  => $this->id,
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

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/%s:generateContent',
			$model
		);

		$body = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
		);

		if ( ! empty( $config['temperature'] ) ) {
			$body['generationConfig']['temperature'] = (float) $config['temperature'];
		}

		if ( ! empty( $config['max_tokens'] ) ) {
			$body['generationConfig']['maxOutputTokens'] = (int) $config['max_tokens'];
		}

		$response = $this->make_request(
			add_query_arg( 'key', $api_key, $url ),
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$data = $this->parse_json_response( $response );

		if ( is_wp_error( $data ) ) {
			$this->log( 'Text generation failed: ' . $data->get_error_message(), 'error' );
			return $data;
		}

		if ( empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'no_text', __( 'No text returned from API', 'wpllmseo' ) );
		}

		return array(
			'text'     => $data['candidates'][0]['content']['parts'][0]['text'],
			'model'    => $model,
			'provider' => $this->id,
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

		// Determine test based on model capability.
		$is_embedding = strpos( $model, 'embedding' ) !== false;

		if ( $is_embedding ) {
			$result = $this->generate_embedding( 'Test embedding', $model, $api_key, $config );
		} else {
			$result = $this->generate_text( 'Say "Hello"', $model, $api_key, $config );
		}

		$latency = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'latency' => $latency,
			'sample'  => $is_embedding ? count( $result['embedding'] ) . ' dimensions' : $result['text'],
			'model'   => $model,
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
				'description' => __( 'Get your API key from Google AI Studio', 'wpllmseo' ),
				'link'        => 'https://makersuite.google.com/app/apikey',
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

		// Test API key with a simple models list call.
		$result = $this->discover_models( $config['api_key'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
