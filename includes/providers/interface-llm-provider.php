<?php
/**
 * LLM Provider Interface
 *
 * Base interface for all LLM API providers.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for LLM API providers
 */
interface WPLLMSEO_LLM_Provider_Interface {

	/**
	 * Get provider ID
	 *
	 * @return string Unique provider identifier (e.g., 'openai', 'gemini', 'claude')
	 */
	public function get_id(): string;

	/**
	 * Get provider display name
	 *
	 * @return string Human-readable provider name
	 */
	public function get_name(): string;

	/**
	 * Get provider description
	 *
	 * @return string Short description of the provider
	 */
	public function get_description(): string;

	/**
	 * Discover available models from provider API
	 *
	 * @param string $api_key API key for authentication.
	 * @param array  $config  Optional provider-specific configuration.
	 * @return array|WP_Error Array of models or error on failure.
	 */
	public function discover_models( string $api_key, array $config = array() );

	/**
	 * Get default models when discovery fails
	 *
	 * @return array Array of default model configurations.
	 */
	public function get_default_models(): array;

	/**
	 * Generate embeddings for text
	 *
	 * @param string $text      Text to embed.
	 * @param string $model     Model ID to use.
	 * @param string $api_key   API key.
	 * @param array  $config    Optional configuration.
	 * @return array|WP_Error Array with 'embedding' key or error.
	 */
	public function generate_embedding( string $text, string $model, string $api_key, array $config = array() );

	/**
	 * Generate text completion
	 *
	 * @param string $prompt    Prompt text.
	 * @param string $model     Model ID to use.
	 * @param string $api_key   API key.
	 * @param array  $config    Optional configuration.
	 * @return array|WP_Error Array with 'text' key or error.
	 */
	public function generate_text( string $prompt, string $model, string $api_key, array $config = array() );

	/**
	 * Test connection with quick call
	 *
	 * @param string $model     Model ID to test.
	 * @param string $api_key   API key.
	 * @param array  $config    Optional configuration.
	 * @return array|WP_Error Array with test results (latency, cost, sample) or error.
	 */
	public function test_model( string $model, string $api_key, array $config = array() );

	/**
	 * Get required configuration fields
	 *
	 * @return array Array of field definitions for provider config UI.
	 */
	public function get_config_fields(): array;

	/**
	 * Validate configuration
	 *
	 * @param array $config Configuration to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_config( array $config );
}
