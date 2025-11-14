<?php
/**
 * Admin interface class.
 *
 * Handles WordPress admin menu, asset enqueuing, and screen rendering.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Admin
 */
class WPLLMSEO_Admin {

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_providers_save' ) );
		add_action( 'admin_post_wpllmseo_download_log', array( __CLASS__, 'handle_log_download' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_wpllmseo_clear_completed', array( __CLASS__, 'ajax_clear_completed_jobs' ) );
	}

	/**
	 * AJAX handler to clear completed jobs
	 */
	public static function ajax_clear_completed_jobs() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wp_rest' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		// Clear completed jobs older than 1 day
		$queue = new WPLLMSEO_Queue();
		$deleted = $queue->cleanup_old_jobs( 1 );

		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/**
	 * Register admin menu and submenus.
	 */
	public static function register_menu() {
		// Main menu page - Dashboard.
		add_menu_page(
			__( 'AI SEO & Indexing', 'wpllmseo' ),
			__( 'AI SEO & Indexing', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_dashboard',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-bar',
			58
		);

		// Index Queue submenu.
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Index Queue', 'wpllmseo' ),
			__( 'Index Queue', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_queue',
			array( __CLASS__, 'render_queue' )
		);

		// Snippets submenu.
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Snippets', 'wpllmseo' ),
			__( 'Snippets', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_snippets',
			array( __CLASS__, 'render_snippets' )
		);

		// Logs submenu.
		$logs_reader = new WPLLMSEO_Logs();
		$has_errors  = $logs_reader->has_recent_errors();
		$logs_label  = __( 'Logs', 'wpllmseo' );
		
		if ( $has_errors ) {
			$logs_label .= ' <span class="awaiting-mod count-1"><span class="pending-count">!</span></span>';
		}

		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Logs', 'wpllmseo' ),
			$logs_label,
			'manage_options',
			'wpllmseo_logs',
			array( __CLASS__, 'render_logs' )
		);

		// API Providers submenu (v1.2.0+).
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'API Providers', 'wpllmseo' ),
			__( 'API Providers', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_providers',
			array( __CLASS__, 'render_providers' )
		);

		// MCP Integration submenu (v1.1.0+).
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'MCP Integration', 'wpllmseo' ),
			__( 'MCP Integration', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_mcp',
			array( __CLASS__, 'render_mcp' )
		);

		// Settings submenu.
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Settings', 'wpllmseo' ),
			__( 'Settings', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin assets only on plugin pages.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our plugin pages.
		if ( strpos( $hook, 'wpllmseo' ) === false ) {
			return;
		}

		$asset_url = WPLLMSEO_PLUGIN_URL . 'admin/assets/';
		$asset_dir = WPLLMSEO_PLUGIN_DIR . 'admin/assets/';

		// Check if CSS file exists.
		$css_file = $asset_dir . 'css/admin.css';
		if ( ! file_exists( $css_file ) ) {
			wpllmseo_log( 'Admin CSS file not found: ' . $css_file, 'error' );
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'WP LLM SEO: Admin CSS file is missing. Please reinstall the plugin.', 'wpllmseo' );
				echo '</p></div>';
			});
			return;
		}

		// Enqueue admin CSS.
		wp_enqueue_style(
			'wpllmseo-admin',
			$asset_url . 'css/admin.css',
			array(),
			filemtime( $css_file )
		);

		// Check if Chart.js exists.
		$chart_file = $asset_dir . 'js/chart.min.js';
		if ( ! file_exists( $chart_file ) ) {
			wpllmseo_log( 'Chart.js file not found: ' . $chart_file, 'error' );
		} else {
			// Enqueue Chart.js.
			wp_enqueue_script(
				'wpllmseo-chart',
				$asset_url . 'js/chart.min.js',
				array(),
				filemtime( $chart_file ),
				true
			);
		}

		// Check if admin JS exists.
		$js_file = $asset_dir . 'js/admin.js';
		if ( ! file_exists( $js_file ) ) {
			wpllmseo_log( 'Admin JS file not found: ' . $js_file, 'error' );
			return;
		}

		// Enqueue admin JS.
		wp_enqueue_script(
			'wpllmseo-admin',
			$asset_url . 'js/admin.js',
			array( 'wp-element', 'wpllmseo-chart' ),
			filemtime( $js_file ),
			true
		);

		// Localize script with data.
		wp_localize_script(
			'wpllmseo-admin',
			'wpllmseo_admin',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'rest_url'  => rest_url(),
				'i18n'      => array(
					'error'   => __( 'An error occurred', 'wpllmseo' ),
					'success' => __( 'Success', 'wpllmseo' ),
					'loading' => __( 'Loading...', 'wpllmseo' ),
				),
			)
		);
	}

	/**
	 * Handle settings form submission.
	 */
	public static function handle_settings_save() {
		// Check if this is a settings save request.
		if ( ! isset( $_POST['wpllmseo_save_settings'] ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['wpllmseo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpllmseo_nonce'] ) ), 'wpllmseo_admin_action' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'wpllmseo' ) );
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		// Sanitize and save settings.
		$settings = array(
			'api_key'                         => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
			'model'                           => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : WPLLMSEO_GEMINI_MODEL,
			'auto_index'                      => isset( $_POST['auto_index'] ) ? (bool) $_POST['auto_index'] : false,
			'batch_size'                      => isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10,
			'enable_logging'                  => isset( $_POST['enable_logging'] ) ? (bool) $_POST['enable_logging'] : false,
			'theme_mode'                      => isset( $_POST['theme_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_mode'] ) ) : 'auto',
			'enable_ai_sitemap'               => isset( $_POST['enable_ai_sitemap'] ) ? (bool) $_POST['enable_ai_sitemap'] : false,
			'content_license'                 => isset( $_POST['content_license'] ) ? sanitize_text_field( wp_unslash( $_POST['content_license'] ) ) : 'GPL',
			// High Priority Features
			'prefer_external_seo'             => isset( $_POST['prefer_external_seo'] ) ? (bool) $_POST['prefer_external_seo'] : false,
			'use_similarity_threshold'        => isset( $_POST['use_similarity_threshold'] ) ? (bool) $_POST['use_similarity_threshold'] : false,
			'enable_llm_jsonld'               => isset( $_POST['enable_llm_jsonld'] ) ? (bool) $_POST['enable_llm_jsonld'] : false,
			// Medium Priority Features
			'sitemap_hub_public'              => isset( $_POST['sitemap_hub_public'] ) ? (bool) $_POST['sitemap_hub_public'] : false,
			'sitemap_hub_token'               => isset( $_POST['sitemap_hub_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_hub_token'] ) ) : wp_generate_password( 32, false ),
			'llm_api_public'                  => isset( $_POST['llm_api_public'] ) ? (bool) $_POST['llm_api_public'] : false,
			'llm_api_token'                   => isset( $_POST['llm_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['llm_api_token'] ) ) : wp_generate_password( 32, false ),
			'llm_api_rate_limit'              => isset( $_POST['llm_api_rate_limit'] ) ? (bool) $_POST['llm_api_rate_limit'] : false,
			'llm_api_rate_limit_value'        => isset( $_POST['llm_api_rate_limit_value'] ) ? absint( $_POST['llm_api_rate_limit_value'] ) : 100,
			'enable_semantic_linking'         => isset( $_POST['enable_semantic_linking'] ) ? (bool) $_POST['enable_semantic_linking'] : false,
			'semantic_linking_threshold'      => isset( $_POST['semantic_linking_threshold'] ) ? floatval( $_POST['semantic_linking_threshold'] ) : 0.75,
			'semantic_linking_max_suggestions' => isset( $_POST['semantic_linking_max_suggestions'] ) ? absint( $_POST['semantic_linking_max_suggestions'] ) : 5,
			// Lower Priority Features
			'enable_media_embeddings'         => isset( $_POST['enable_media_embeddings'] ) ? (bool) $_POST['enable_media_embeddings'] : false,
			'enable_crawler_logs'             => isset( $_POST['enable_crawler_logs'] ) ? (bool) $_POST['enable_crawler_logs'] : false,
			'enable_html_renderer'            => isset( $_POST['enable_html_renderer'] ) ? (bool) $_POST['enable_html_renderer'] : false,
			// MCP Integration (v1.1.0+)
			'wpllmseo_enable_mcp'             => isset( $_POST['wpllmseo_enable_mcp'] ) ? (bool) $_POST['wpllmseo_enable_mcp'] : false,
			'wpllmseo_mcp_respect_llms_txt'   => isset( $_POST['wpllmseo_mcp_respect_llms_txt'] ) ? (bool) $_POST['wpllmseo_mcp_respect_llms_txt'] : false,
		);

		update_option( 'wpllmseo_settings', $settings );
		
		// Flush LLMs.txt cache if settings changed
		if ( class_exists( 'WPLLMSEO_MCP_LLMsTxt' ) ) {
			WPLLMSEO_MCP_LLMsTxt::flush_cache();
		}
		
		// Regenerate AI sitemap if settings changed
		$sitemap = new WPLLMSEO_AI_Sitemap();
		$sitemap->regenerate_cache();

		// Redirect with success message.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpllmseo_settings',
					'updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle provider settings form submission.
	 */
	public static function handle_providers_save() {
		// Debug logging
		error_log('WPLLMSEO: handle_providers_save called');
		error_log('WPLLMSEO: POST data: ' . print_r($_POST, true));
		
		// Check if this is a provider save request.
		if ( ! isset( $_POST['wpllmseo_save_providers'] ) ) {
			error_log('WPLLMSEO: wpllmseo_save_providers not set, returning');
			return;
		}

		error_log('WPLLMSEO: Processing provider save');

		// Verify nonce.
		if ( ! isset( $_POST['wpllmseo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpllmseo_nonce'] ) ), 'wpllmseo_admin_action' ) ) {
			error_log('WPLLMSEO: Nonce verification failed');
			wp_die( esc_html__( 'Security check failed. Please try again.', 'wpllmseo' ) );
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			error_log('WPLLMSEO: Permission check failed');
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		$settings = get_option( 'wpllmseo_settings', array() );

		// Save provider configurations.
		if ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) {
			error_log('WPLLMSEO: Saving provider configs: ' . print_r($_POST['providers'], true));
			foreach ( $_POST['providers'] as $provider_id => $config ) {
				$provider = WPLLMSEO_Provider_Manager::get_provider( $provider_id );
				if ( $provider ) {
					// Sanitize config.
					$config = array_map( 'sanitize_text_field', $config );
					
					// Validate and save.
					$validation = $provider->validate_config( $config );
					if ( ! is_wp_error( $validation ) ) {
						error_log('WPLLMSEO: Saving config for ' . $provider_id);
						WPLLMSEO_Provider_Manager::save_provider_config( $provider_id, $config );
						
						// Trigger discovery if API key provided.
						if ( ! empty( $config['api_key'] ) ) {
							error_log('WPLLMSEO: Triggering model discovery for ' . $provider_id);
							WPLLMSEO_Provider_Manager::discover_models( $provider_id, true );
						}
					} else {
						error_log('WPLLMSEO: Validation failed for ' . $provider_id . ': ' . $validation->get_error_message());
					}
				}
			}
		}

		// Save active providers and models.
		if ( isset( $_POST['active_providers'] ) && is_array( $_POST['active_providers'] ) ) {
			error_log('WPLLMSEO: Saving active providers');
			$settings['active_providers'] = array_map( 'sanitize_text_field', $_POST['active_providers'] );
		}
		if ( isset( $_POST['active_models'] ) && is_array( $_POST['active_models'] ) ) {
			error_log('WPLLMSEO: Saving active models');
			$settings['active_models'] = array_map( 'sanitize_text_field', $_POST['active_models'] );
		}

		update_option( 'wpllmseo_settings', $settings );
		error_log('WPLLMSEO: Settings updated, redirecting');

		// Redirect with success message.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpllmseo_providers',
					'updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render dashboard screen.
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		WPLLMSEO_Router::render( 'dashboard' );
	}

	/**
	 * Render queue screen.
	 */
	public static function render_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		WPLLMSEO_Router::render( 'queue' );
	}

	/**
	 * Render snippets screen.
	 */
	public static function render_snippets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		WPLLMSEO_Router::render( 'snippets' );
	}

	/**
	 * Render logs screen.
	 */
	public static function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		WPLLMSEO_Router::render( 'logs' );
	}

	/**
	 * Render MCP integration screen.
	 */
	public static function render_mcp() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/mcp-settings.php';
	}

	/**
	 * Render API providers screen (v1.2.0+).
	 */
	public static function render_providers() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/providers.php';
	}

	/**
	 * Render settings screen.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		WPLLMSEO_Router::render( 'settings' );
	}

	/**
	 * Handle log file download
	 */
	public static function handle_log_download() {
		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download logs.', 'wpllmseo' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpllmseo_download_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpllmseo' ) );
		}

		// Get log name
		$log_name = isset( $_GET['log'] ) ? sanitize_file_name( wp_unslash( $_GET['log'] ) ) : '';
		
		if ( empty( $log_name ) ) {
			wp_die( esc_html__( 'No log file specified.', 'wpllmseo' ) );
		}

		// Download log
		$logs_reader = new WPLLMSEO_Logs();
		$logs_reader->download_log( $log_name );
	}
}
