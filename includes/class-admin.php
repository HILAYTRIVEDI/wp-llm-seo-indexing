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
		// Export and clear exec logs via admin actions for robustness
		add_action( 'admin_post_wpllmseo_export_exec_logs', array( __CLASS__, 'handle_exec_logs_export' ) );
		add_action( 'admin_post_wpllmseo_clear_exec_logs', array( __CLASS__, 'handle_exec_logs_clear' ) );
		add_action( 'admin_post_wpllmseo_run_prune_exec_logs', array( __CLASS__, 'handle_run_prune_exec_logs' ) );
		add_action( 'admin_post_wpllmseo_delete_exec_log', array( __CLASS__, 'handle_exec_log_delete' ) );
		
		// AJAX handlers
		add_action( 'wp_ajax_wpllmseo_clear_completed', array( __CLASS__, 'ajax_clear_completed_jobs' ) );

		// Post-migration cleanup control handlers
		add_action( 'admin_post_wpllmseo_cleanup_start', array( __CLASS__, 'handle_cleanup_start' ) );
		add_action( 'admin_post_wpllmseo_cleanup_stop', array( __CLASS__, 'handle_cleanup_stop' ) );
		add_action( 'admin_post_wpllmseo_cleanup_resume', array( __CLASS__, 'handle_cleanup_resume' ) );

		// Cron hook for running cleanup batches
		add_action( 'wpllmseo_cleanup_postmeta_job', array( __CLASS__, 'run_cleanup_cron' ) );
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

		// Exec Guard Logs (detailed)
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Exec Guard Logs', 'wpllmseo' ),
			__( 'Exec Guard Logs', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_exec_logs',
			array( __CLASS__, 'render_exec_logs' )
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
		
		// Test Installation submenu.
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Test Installation', 'wpllmseo' ),
			__( 'Test Installation', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_test_installation',
			array( __CLASS__, 'render_test_installation' )
		);

		// Migration & Cleanup submenu.
		add_submenu_page(
			'wpllmseo_dashboard',
			__( 'Migrations', 'wpllmseo' ),
			__( 'Migrations', 'wpllmseo' ),
			'manage_options',
			'wpllmseo_migration',
			array( __CLASS__, 'render_migration' )
		);

	// Health Check submenu.
	add_submenu_page(
		'wpllmseo_dashboard',
		__( 'Health Check', 'wpllmseo' ),
		__( 'Health Check', 'wpllmseo' ),
		'manage_options',
		'wpllmseo_health',
		array( __CLASS__, 'render_health_check' )
	);

	// AI Index submenu.
	add_submenu_page(
		'wpllmseo_dashboard',
		__( 'AI Index', 'wpllmseo' ),
		__( 'AI Index', 'wpllmseo' ),
		'manage_options',
		'wpllmseo_ai_index',
		array( __CLASS__, 'render_ai_index' )
	);
}	/**
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

		// Enqueue migration.js when on migration page
		if ( isset( $_GET['page'] ) && 'wpllmseo_migration' === $_GET['page'] ) {
			$mig_js = WPLLMSEO_PLUGIN_DIR . 'admin/assets/js/migration.js';
			$mig_url = WPLLMSEO_PLUGIN_URL . 'admin/assets/js/migration.js';
			if ( file_exists( $mig_js ) ) {
				wp_enqueue_script( 'wpllmseo-migration', $mig_url, array(), filemtime( $mig_js ), true );
				wp_localize_script( 'wpllmseo-migration', 'wpllmseo_admin', array( 'rest_url' => rest_url(), 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
			}
		}
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

		// Merge sanitized values into existing settings to avoid overwriting unrelated keys.
		$existing_settings = get_option( 'wpllmseo_settings', array() );

		$settings = $existing_settings;

		// Update explicit settings from POST (sanitize each input)
		$settings['api_key'] = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : ( $settings['api_key'] ?? '' );
		$settings['model'] = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : ( $settings['model'] ?? WPLLMSEO_GEMINI_MODEL );
		$settings['auto_index'] = isset( $_POST['auto_index'] ) ? (bool) $_POST['auto_index'] : ( $settings['auto_index'] ?? false );
		$settings['batch_size'] = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : ( $settings['batch_size'] ?? 10 );
		$settings['daily_token_limit'] = isset( $_POST['daily_token_limit'] ) ? absint( $_POST['daily_token_limit'] ) : ( $settings['daily_token_limit'] ?? 100000 );
		$settings['enable_logging'] = isset( $_POST['enable_logging'] ) ? (bool) $_POST['enable_logging'] : ( $settings['enable_logging'] ?? false );
		$settings['theme_mode'] = isset( $_POST['theme_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_mode'] ) ) : ( $settings['theme_mode'] ?? 'auto' );
		$settings['enable_ai_sitemap'] = isset( $_POST['enable_ai_sitemap'] ) ? (bool) $_POST['enable_ai_sitemap'] : ( $settings['enable_ai_sitemap'] ?? false );
		$settings['content_license'] = isset( $_POST['content_license'] ) ? sanitize_text_field( wp_unslash( $_POST['content_license'] ) ) : ( $settings['content_license'] ?? 'GPL' );

		// High Priority Features
		$settings['prefer_external_seo'] = isset( $_POST['prefer_external_seo'] ) ? (bool) $_POST['prefer_external_seo'] : ( $settings['prefer_external_seo'] ?? true );
		$settings['use_similarity_threshold'] = isset( $_POST['use_similarity_threshold'] ) ? (bool) $_POST['use_similarity_threshold'] : ( $settings['use_similarity_threshold'] ?? true );
		$settings['enable_llm_jsonld'] = isset( $_POST['enable_llm_jsonld'] ) ? (bool) $_POST['enable_llm_jsonld'] : ( $settings['enable_llm_jsonld'] ?? false );

		// Medium Priority Features
		$settings['sitemap_hub_public'] = isset( $_POST['sitemap_hub_public'] ) ? (bool) $_POST['sitemap_hub_public'] : ( $settings['sitemap_hub_public'] ?? false );
		$settings['sitemap_hub_token'] = isset( $_POST['sitemap_hub_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_hub_token'] ) ) : ( $settings['sitemap_hub_token'] ?? wp_generate_password( 32, false ) );
		$settings['llm_api_public'] = isset( $_POST['llm_api_public'] ) ? (bool) $_POST['llm_api_public'] : ( $settings['llm_api_public'] ?? false );
		$settings['llm_api_token'] = isset( $_POST['llm_api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['llm_api_token'] ) ) : ( $settings['llm_api_token'] ?? wp_generate_password( 32, false ) );
		$settings['llm_api_rate_limit'] = isset( $_POST['llm_api_rate_limit'] ) ? (bool) $_POST['llm_api_rate_limit'] : ( $settings['llm_api_rate_limit'] ?? true );
		$settings['llm_api_rate_limit_value'] = isset( $_POST['llm_api_rate_limit_value'] ) ? absint( $_POST['llm_api_rate_limit_value'] ) : ( $settings['llm_api_rate_limit_value'] ?? 100 );
		$settings['enable_semantic_linking'] = isset( $_POST['enable_semantic_linking'] ) ? (bool) $_POST['enable_semantic_linking'] : ( $settings['enable_semantic_linking'] ?? false );
		$settings['semantic_linking_threshold'] = isset( $_POST['semantic_linking_threshold'] ) ? floatval( $_POST['semantic_linking_threshold'] ) : ( $settings['semantic_linking_threshold'] ?? 0.75 );
		$settings['semantic_linking_max_suggestions'] = isset( $_POST['semantic_linking_max_suggestions'] ) ? absint( $_POST['semantic_linking_max_suggestions'] ) : ( $settings['semantic_linking_max_suggestions'] ?? 5 );

		// Lower Priority Features
		$settings['enable_media_embeddings'] = isset( $_POST['enable_media_embeddings'] ) ? (bool) $_POST['enable_media_embeddings'] : ( $settings['enable_media_embeddings'] ?? false );
		$settings['exec_guard_enabled'] = isset( $_POST['exec_guard_enabled'] ) ? (bool) $_POST['exec_guard_enabled'] : ( $settings['exec_guard_enabled'] ?? false );
		$settings['enable_crawler_logs'] = isset( $_POST['enable_crawler_logs'] ) ? (bool) $_POST['enable_crawler_logs'] : ( $settings['enable_crawler_logs'] ?? false );
		$settings['enable_html_renderer'] = isset( $_POST['enable_html_renderer'] ) ? (bool) $_POST['enable_html_renderer'] : ( $settings['enable_html_renderer'] ?? false );

		// MCP Integration (v1.1.0+)
		$settings['wpllmseo_enable_mcp'] = isset( $_POST['wpllmseo_enable_mcp'] ) ? (bool) $_POST['wpllmseo_enable_mcp'] : ( $settings['wpllmseo_enable_mcp'] ?? false );
		$settings['wpllmseo_mcp_respect_llms_txt'] = isset( $_POST['wpllmseo_mcp_respect_llms_txt'] ) ? (bool) $_POST['wpllmseo_mcp_respect_llms_txt'] : ( $settings['wpllmseo_mcp_respect_llms_txt'] ?? true );

		// Persist merged settings
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
		// Check if this is a provider save request.
		if ( ! isset( $_POST['wpllmseo_save_providers'] ) ) {
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['wpllmseo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpllmseo_nonce'] ) ), 'wpllmseo_admin_action' ) ) {
			// Log nonce failure
			wpllmseo_log( 'Provider save: nonce validation failed', 'security' );
			wp_die( esc_html__( 'Security check failed. Please try again.', 'wpllmseo' ) );
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wpllmseo_log( 'Provider save: permission denied', 'security' );
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		wpllmseo_log( 'Starting provider settings save process', 'info' );

		$settings = get_option( 'wpllmseo_settings', array() );
		$has_errors = false;
		$error_messages = array();

		// Initialize providers array if it doesn't exist
		if ( ! isset( $settings['providers'] ) ) {
			$settings['providers'] = array();
		}

		// Save provider configurations.
		if ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) {
			foreach ( $_POST['providers'] as $provider_id => $config ) {
				wpllmseo_log( 'Processing provider: ' . $provider_id, 'debug' );
				$provider = WPLLMSEO_Provider_Manager::get_provider( $provider_id );
				if ( $provider ) {
					// Sanitize config.
					$config = array_map( 'sanitize_text_field', $config );
					
					// Only validate if an API key was provided
					if ( ! empty( $config['api_key'] ) ) {
						// Validate and save.
						$validation = $provider->validate_config( $config );
						
						if ( ! is_wp_error( $validation ) ) {
							wpllmseo_log( 'Validation passed for provider: ' . $provider_id, 'info' );
							// Save to settings array (don't call save_provider_config separately)
							$settings['providers'][ $provider_id ] = $config;
							
							wpllmseo_log( 'Will trigger model discovery after save for: ' . $provider_id, 'debug' );
						} else {
							wpllmseo_log( 'Validation error for provider: ' . $provider_id . ' - ' . $validation->get_error_message(), 'error' );
							$has_errors = true;
							$error_messages[] = sprintf(
								'%s: %s',
								$provider->get_name(),
								$validation->get_error_message()
							);
						}
					} else {
						// No API key provided - remove from settings if it exists
						wpllmseo_log( 'No API key provided for provider: ' . $provider_id . ', skipping', 'debug' );
						if ( isset( $settings['providers'][ $provider_id ] ) ) {
							unset( $settings['providers'][ $provider_id ] );
						}
					}
				}
			}
		}

		// Save active providers and models.
		if ( isset( $_POST['active_providers'] ) && is_array( $_POST['active_providers'] ) ) {
			wpllmseo_log( 'Saving active providers', 'debug' );
			$settings['active_providers'] = array_map( 'sanitize_text_field', $_POST['active_providers'] );
		}
		if ( isset( $_POST['active_models'] ) && is_array( $_POST['active_models'] ) ) {
			wpllmseo_log( 'Saving active models (raw post)', 'debug', array( 'post_active_models' => $_POST['active_models'] ) );
			$settings['active_models'] = array_map( 'sanitize_text_field', $_POST['active_models'] );
			wpllmseo_log( 'Saving active models (sanitized)', 'debug', array( 'settings_active_models' => $settings['active_models'] ) );
		}

		$update_result = update_option( 'wpllmseo_settings', $settings );
		wpllmseo_log( 'Updated wpllmseo_settings option', 'info' );

		// Now trigger model discovery for providers with API keys (after save is complete)
		if ( isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ) {
			foreach ( $_POST['providers'] as $provider_id => $config ) {
				if ( ! empty( $config['api_key'] ) ) {
					$provider = WPLLMSEO_Provider_Manager::get_provider( $provider_id );
					if ( $provider && ! is_wp_error( $provider->validate_config( $config ) ) ) {
						wpllmseo_log( 'Triggering model discovery for ' . $provider_id, 'info' );
						WPLLMSEO_Provider_Manager::discover_models( $provider_id, true );
					}
				}
			}
		}

		// Redirect with success or error message.
		$redirect_args = array(
			'page' => 'wpllmseo_providers',
		);
		
		if ( $has_errors ) {
			set_transient( 'wpllmseo_provider_errors', $error_messages, 30 );
			$redirect_args['errors'] = '1';
		} else {
			$redirect_args['updated'] = 'true';
		}
		
		wp_safe_redirect(
			add_query_arg(
				$redirect_args,
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
	 * Render Exec Guard logs screen.
	 */
	public static function render_exec_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		// Handle export action (GET)
		if ( isset( $_GET['action'] ) && 'export' === $_GET['action'] ) {
			self::handle_exec_logs_export();
		}

		// Handle clear logs (POST)
		if ( isset( $_POST['wpllmseo_clear_exec_logs'] ) ) {
			self::handle_exec_logs_clear();
		}

		// If viewing detail
		if ( isset( $_GET['view'] ) && 'detail' === $_GET['view'] && isset( $_GET['id'] ) ) {
			require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/exec-log-detail.php';
			return;
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/exec-logs.php';
		?>
		<form method="get" action="">
			<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'wpllmseo_export_exec_logs' ) ) ); ?>" class="page-title-action">Export CSV</a>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<?php $run_url = wp_nonce_url( admin_url( 'admin-post.php?action=wpllmseo_run_prune_exec_logs' ), 'wpllmseo_run_prune' ); ?>
				<a href="<?php echo esc_url( $run_url ); ?>" class="page-title-action">Run Prune Now</a>
			<?php endif; ?>
		</form>
		<?php
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
	 * Render test installation screen.
	 */
	public static function render_test_installation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}
		
		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/test-installation.php';
	}

	/**
	 * Render migration and cleanup screen.
	 */
	public static function render_migration() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/migration.php';
	}

	/**
	 * Render health check screen
	 */
	public static function render_health_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/health-check.php';
	}

	/**
	 * Render AI Index screen
	 */
	public static function render_ai_index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'admin/screens/ai-index-settings.php';
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

	/**
	 * Export exec logs as CSV. POST action protected by nonce.
	 */
	public static function handle_exec_logs_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export logs.', 'wpllmseo' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpllmseo_export_exec_logs' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpllmseo' ) );
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';

		// Support filters passed via GET
		$filters = array(
			'user_id' => isset( $_GET['filter_user'] ) ? intval( $_GET['filter_user'] ) : null,
			'result' => isset( $_GET['filter_result'] ) ? $_GET['filter_result'] : null,
			'date_from' => isset( $_GET['filter_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_from'] ) ) : null,
			'date_to' => isset( $_GET['filter_to'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_to'] ) ) : null,
			'limit' => 10000,
			'offset' => 0,
		);

		$logs = WPLLMSEO_Exec_Logger::query_logs( $filters );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wpllmseo-exec-logs-' . date( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'user_id', 'command', 'stdout', 'stderr', 'result', 'created_at' ) );
		foreach ( $logs as $log ) {
			fputcsv( $out, array( $log->id, $log->user_id, $log->command, $log->stdout, $log->stderr, $log->result, $log->created_at ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Handle single exec log deletion via admin-post.
	 */
	public static function handle_exec_log_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete logs.', 'wpllmseo' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wpllmseo_delete_exec_log' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpllmseo' ) );
		}

		$id = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		if ( ! $id ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'wpllmseo_exec_logs', 'error' => 'invalid_id' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';
		WPLLMSEO_Exec_Logger::delete_log( $id );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpllmseo_exec_logs', 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Run prune now via admin-post handler.
	 */
	public static function handle_run_prune_exec_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wpllmseo' ) );
		}
		check_admin_referer( 'wpllmseo_run_prune' );

		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';

		$settings = get_option( 'wpllmseo_settings', array() );
		$days = isset( $settings['exec_logs_retention_days'] ) ? intval( $settings['exec_logs_retention_days'] ) : 30;

		$deleted = WPLLMSEO_Exec_Logger::prune_older_than_days( $days );

		$redirect = add_query_arg( array( 'page' => 'wpllmseo_exec_logs', 'wpllmseo_prune_result' => $deleted ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Start post-migration cleanup: initialize progress and schedule cron.
	 */
		public static function handle_cleanup_start() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'wpllmseo' ) );
			}

			check_admin_referer( 'wpllmseo_cleanup_control' );

			// Safety: require a recent dry-run preview (24h) unless force flag provided
			$force = isset( $_POST['force_start'] ) && isset( $_POST['_wpnonce_force'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_force'] ) ), 'wpllmseo_cleanup_force' );

			if ( ! $force ) {
				$last_preview = get_option( 'wpllmseo_cleanup_last_preview', null );
				if ( empty( $last_preview ) || ( time() - intval( $last_preview['time'] ) ) > DAY_IN_SECONDS ) {
					wp_die( esc_html__( 'A recent cleanup preview is required before starting automatic deletion. Please run the preview and confirm.', 'wpllmseo' ) );
				}
			}

			// Initialize progress option
			$progress = array(
				'running' => true,
				'offset' => 0,
				'batch' => isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 50,
				'last_run' => null,
				'total_processed' => 0,
				'max_total' => isset( $_POST['max_total'] ) ? absint( $_POST['max_total'] ) : 0,
			);
			update_option( 'wpllmseo_cleanup_progress', $progress );

			if ( ! wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
				wp_schedule_event( time(), 'hourly', 'wpllmseo_cleanup_postmeta_job' );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'wpllmseo_migration', 'cleanup_started' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * Stop post-migration cleanup: mark running=false and unschedule cron.
		 */
		public static function handle_cleanup_stop() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'wpllmseo' ) );
			}

			check_admin_referer( 'wpllmseo_cleanup_control' );

			$progress = get_option( 'wpllmseo_cleanup_progress', array() );
			$progress['running'] = false;
			update_option( 'wpllmseo_cleanup_progress', $progress );

			// Unschedule the cron
			if ( wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
				wp_clear_scheduled_hook( 'wpllmseo_cleanup_postmeta_job' );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'wpllmseo_migration', 'cleanup_stopped' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * Resume post-migration cleanup: mark running=true and schedule cron.
		 */
		public static function handle_cleanup_resume() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'wpllmseo' ) );
			}

			check_admin_referer( 'wpllmseo_cleanup_control' );

			$progress = get_option( 'wpllmseo_cleanup_progress', array() );
			$progress['running'] = true;
			if ( ! isset( $progress['batch'] ) ) {
				$progress['batch'] = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 50;
			}
			if ( isset( $_POST['max_total'] ) ) {
				$progress['max_total'] = absint( $_POST['max_total'] );
			}
			update_option( 'wpllmseo_cleanup_progress', $progress );

			if ( ! wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
				wp_schedule_event( time(), 'hourly', 'wpllmseo_cleanup_postmeta_job' );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'wpllmseo_migration', 'cleanup_resumed' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * Cron runner that executes a single cleanup batch and updates progress.
		 */
		public static function run_cleanup_cron() {
			$progress = get_option( 'wpllmseo_cleanup_progress', array() );
			if ( empty( $progress ) || empty( $progress['running'] ) ) {
				// nothing to do
				return;
			}

			$batch = isset( $progress['batch'] ) ? absint( $progress['batch'] ) : 50;
			$offset = isset( $progress['offset'] ) ? absint( $progress['offset'] ) : 0;

			require_once WPLLMSEO_PLUGIN_DIR . 'includes/migrations/class-cleanup-postmeta.php';

			$res = WPLLMSEO_Cleanup_Postmeta::run( $batch, $offset, true );

			// Update progress
			$progress['offset'] = $offset + $res['processed'];
			$progress['last_run'] = current_time( 'mysql' );
			$progress['total_processed'] = isset( $progress['total_processed'] ) ? intval( $progress['total_processed'] ) + $res['processed'] : $res['processed'];

			// Enforce max_total if set (>0)
			if ( ! empty( $progress['max_total'] ) && $progress['total_processed'] >= intval( $progress['max_total'] ) ) {
				$progress['running'] = false;
				// record last result: hit max
				$last = array(
					'reason' => 'max_total_reached',
					'total_processed' => $progress['total_processed'],
					'max_total' => intval( $progress['max_total'] ),
					'time' => current_time( 'mysql' ),
				);
				update_option( 'wpllmseo_cleanup_last_result', $last );
				// Audit log
				require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';
				WPLLMSEO_Exec_Logger::log( json_encode( array( 'action' => 'cleanup', 'event' => 'max_total_reached', 'total_processed' => $progress['total_processed'], 'max_total' => intval( $progress['max_total'] ) ) ), '', '', 0 );
				// ensure cron cleared
				if ( wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
					wp_clear_scheduled_hook( 'wpllmseo_cleanup_postmeta_job' );
				}
			}

			// Stop if nothing processed (end of attachments)
			if ( $res['processed'] === 0 ) {
				$progress['running'] = false;
				// record last result: completed
				$last = array(
					'reason' => 'completed',
					'total_processed' => $progress['total_processed'],
					'time' => current_time( 'mysql' ),
				);
				update_option( 'wpllmseo_cleanup_last_result', $last );
				// Audit log entry for completion
				require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';
				WPLLMSEO_Exec_Logger::log( json_encode( array( 'action' => 'cleanup', 'event' => 'completed', 'total_processed' => $progress['total_processed'] ) ), '', '', 0 );
				if ( wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
					wp_clear_scheduled_hook( 'wpllmseo_cleanup_postmeta_job' );
				}
			}

			update_option( 'wpllmseo_cleanup_progress', $progress );
		}

	/**
	 * Clear exec logs (truncate). POST action protected by nonce and capability.
	 */
	public static function handle_exec_logs_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear logs.', 'wpllmseo' ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wpllmseo_clear_exec_logs' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wpllmseo' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpllmseo_exec_logs';
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wpllmseo_exec_logs', 'cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
