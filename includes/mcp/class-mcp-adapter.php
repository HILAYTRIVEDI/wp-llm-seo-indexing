<?php
/**
 * MCP Adapter Integration
 *
 * Detects and integrates with WordPress MCP adapter to expose
 * controlled abilities for automation and editorial workflows.
 *
 * @package WP_LLM_SEO_Indexing
 * @since 1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_MCP_Adapter
 *
 * Handles MCP adapter detection, ability registration, and lifecycle management.
 */
class WPLLMSEO_MCP_Adapter {

	/**
	 * MCP adapter class name
	 *
	 * @var string
	 */
	const ADAPTER_CLASS = 'WordPress\MCP\Adapter';

	/**
	 * Feature flag option key
	 *
	 * @var string
	 */
	const FEATURE_FLAG = 'wpllmseo_enable_mcp';

	/**
	 * Registered abilities
	 *
	 * @var array
	 */
	private static $abilities = array();

	/**
	 * Initialize MCP integration
	 */
	public static function init() {
		// Hook into plugins_loaded with late priority to ensure MCP adapter loads first
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_register_abilities' ), 20 );
		
		// Admin notices
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Check if MCP is enabled
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) get_option( self::FEATURE_FLAG, false );
	}

	/**
	 * Check if MCP adapter is available
	 *
	 * @return bool
	 */
	public static function is_adapter_available() {
		return class_exists( self::ADAPTER_CLASS );
	}

	/**
	 * Maybe register MCP abilities
	 */
	public static function maybe_register_abilities() {
		// Check feature flag first
		if ( ! self::is_enabled() ) {
			return;
		}

		// Check if MCP adapter is available
		if ( ! self::is_adapter_available() ) {
			wpllmseo_log( 'MCP adapter not found. Skipping LLM SEO MCP registration.', 'warning' );
			return;
		}

		// Register abilities
		self::register_abilities();

		wpllmseo_log( 'MCP abilities registered successfully', 'info' );
	}

	/**
	 * Register all MCP abilities
	 */
	private static function register_abilities() {
		$abilities = self::get_ability_definitions();

		foreach ( $abilities as $ability_name => $ability_config ) {
			// Check if ability is enabled in settings
			if ( ! self::is_ability_enabled( $ability_name ) ) {
				continue;
			}

			// Register with MCP adapter
			try {
				call_user_func(
					array( self::ADAPTER_CLASS, 'register_ability' ),
					$ability_name,
					array( 'WPLLMSEO_MCP_Handlers', $ability_config['handler'] ),
					$ability_config['schema']
				);

				self::$abilities[ $ability_name ] = $ability_config;

				wpllmseo_log( "Registered MCP ability: {$ability_name}", 'debug' );
			} catch ( Exception $e ) {
				wpllmseo_log( "Failed to register MCP ability {$ability_name}: " . $e->getMessage(), 'error' );
			}
		}
	}

	/**
	 * Get ability definitions
	 *
	 * @return array
	 */
	private static function get_ability_definitions() {
		return array(
			'llmseo.fetch_post_summary'       => array(
				'handler'     => 'handle_fetch_post_summary',
				'description' => 'Fetch post summary with SEO meta and existing AI snippet',
				'requires'    => 'read',
				'schema'      => array(
					'input'  => array(
						'post_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
					'output' => array(
						'post_id'              => 'integer',
						'title'                => 'string',
						'yoast_meta'           => 'object|null',
						'rankmath_meta'        => 'object|null',
						'existing_ai_snippet'  => 'string|null',
						'last_embedding_hash'  => 'string|null',
						'cleaned_text_sample'  => 'string',
					),
				),
			),
			'llmseo.generate_snippet_preview' => array(
				'handler'     => 'handle_generate_snippet_preview',
				'description' => 'Generate AI snippet preview with optional dry-run mode',
				'requires'    => 'edit_posts',
				'schema'      => array(
					'input'  => array(
						'post_id'    => array(
							'type'     => 'integer',
							'required' => true,
						),
						'model'      => array(
							'type'     => 'string',
							'required' => false,
						),
						'max_tokens' => array(
							'type'     => 'integer',
							'required' => false,
						),
						'dry_run'    => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
					),
					'output' => array(
						'job_id'          => 'string',
						'preview_snippet' => 'string|null',
						'queued'          => 'boolean',
					),
				),
			),
			'llmseo.start_bulk_snippet_job'   => array(
				'handler'     => 'handle_start_bulk_snippet_job',
				'description' => 'Start bulk snippet generation job',
				'requires'    => 'manage_options',
				'schema'      => array(
					'input'  => array(
						'filters'              => array(
							'type'     => 'object',
							'required' => false,
						),
						'skip_existing'        => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
						'batch_size'           => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => 10,
						),
						'rate_limit_per_minute' => array(
							'type'     => 'integer',
							'required' => false,
							'default'  => 10,
						),
						'respect_llms_txt'     => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
					),
					'output' => array(
						'job_id'               => 'string',
						'queued_count'         => 'integer',
						'skipped_count_estimate' => 'integer',
					),
				),
			),
			'llmseo.save_snippet'             => array(
				'handler'     => 'handle_save_snippet',
				'description' => 'Save AI snippet to post meta',
				'requires'    => 'edit_posts',
				'schema'      => array(
					'input'  => array(
						'post_id'      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'snippet_text' => array(
							'type'     => 'string',
							'required' => true,
						),
						'save_as'      => array(
							'type'     => 'string',
							'required' => false,
						),
					),
					'output' => array(
						'success'        => 'boolean',
						'post_id'        => 'integer',
						'saved_meta_key' => 'string',
					),
				),
			),
			'llmseo.query_job_status'         => array(
				'handler'     => 'handle_query_job_status',
				'description' => 'Query background job status',
				'requires'    => 'read',
				'schema'      => array(
					'input'  => array(
						'job_id' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
					'output' => array(
						'job_id'         => 'string',
						'status'         => 'string',
						'processed'      => 'integer',
						'skipped'        => 'integer',
						'errors'         => 'array',
						'sample_outputs' => 'array',
					),
				),
			),
		);
	}

	/**
	 * Check if specific ability is enabled
	 *
	 * @param string $ability_name Ability name.
	 * @return bool
	 */
	private static function is_ability_enabled( $ability_name ) {
		$enabled_abilities = get_option( 'wpllmseo_mcp_enabled_abilities', array() );

		// If array is empty, enable all abilities by default
		if ( empty( $enabled_abilities ) ) {
			return true;
		}

		return in_array( $ability_name, $enabled_abilities, true );
	}

	/**
	 * Get registered abilities
	 *
	 * @return array
	 */
	public static function get_registered_abilities() {
		return self::$abilities;
	}

	/**
	 * Admin notices
	 */
	public static function admin_notices() {
		// Only show on plugin settings page
		if ( ! isset( $_GET['page'] ) || 'wpllmseo_settings' !== $_GET['page'] ) {
			return;
		}

		// Show notice if MCP is enabled but adapter not available
		if ( self::is_enabled() && ! self::is_adapter_available() ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'LLM SEO MCP Integration:', 'wpllmseo' ); ?></strong>
					<?php esc_html_e( 'MCP is enabled but the WordPress MCP adapter plugin is not active. Please install and activate the MCP adapter to use this feature.', 'wpllmseo' ); ?>
				</p>
			</div>
			<?php
		}

		// Show success notice if MCP is active
		if ( self::is_enabled() && self::is_adapter_available() && ! empty( self::$abilities ) ) {
			$count = count( self::$abilities );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<strong><?php esc_html_e( 'LLM SEO MCP Integration:', 'wpllmseo' ); ?></strong>
					<?php
					printf(
						/* translators: %d: number of abilities */
						esc_html( _n( '%d MCP ability registered successfully.', '%d MCP abilities registered successfully.', $count, 'wpllmseo' ) ),
						(int) $count
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Get MCP status for debugging
	 *
	 * @return array
	 */
	public static function get_status() {
		return array(
			'enabled'           => self::is_enabled(),
			'adapter_available' => self::is_adapter_available(),
			'adapter_class'     => self::ADAPTER_CLASS,
			'abilities_count'   => count( self::$abilities ),
			'abilities'         => array_keys( self::$abilities ),
		);
	}
}
