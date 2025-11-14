<?php
/**
 * Plugin Name: WP LLM SEO & Indexing
 * Plugin URI: https://theworldtechs.com/wp-llm-seo-indexing
 * Description: AI-powered SEO optimization and indexing using LLM embeddings for WordPress content.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://theworldtechs.com
 * Text Domain: wpllmseo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WPLLMSEO_VERSION', '1.0.0' );
define( 'WPLLMSEO_MIN_WP_VERSION', '6.0' );
define( 'WPLLMSEO_MIN_PHP_VERSION', '8.1' );
define( 'WPLLMSEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPLLMSEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPLLMSEO_PLUGIN_FILE', __FILE__ );
define( 'WPLLMSEO_TEXT_DOMAIN', 'wpllmseo' );
define( 'WPLLMSEO_REST_NAMESPACE', 'wp-llmseo/v1' );
define( 'WPLLMSEO_GEMINI_MODEL', 'models/text-embedding-004' );

/**
 * Autoload plugin classes.
 *
 * @param string $class_name The class name to load.
 */
function wpllmseo_autoloader( $class_name ) {
	// Check if the class uses our prefix.
	if ( strpos( $class_name, 'WPLLMSEO_' ) !== 0 ) {
		return;
	}

	// Check if this is an MCP class.
	if ( strpos( $class_name, 'WPLLMSEO_MCP_' ) === 0 ) {
		// Extract MCP class suffix (e.g., WPLLMSEO_MCP_Adapter -> Adapter -> adapter).
		$suffix = substr( $class_name, 13 ); // Remove 'WPLLMSEO_MCP_'.
		$class_file = strtolower( str_replace( '_', '-', $suffix ) );
		$file_path = WPLLMSEO_PLUGIN_DIR . 'includes/mcp/class-mcp-' . $class_file . '.php';
	} elseif ( strpos( $class_name, 'WPLLMSEO_LLM_Provider_' ) === 0 ) {
		// Provider classes (e.g., WPLLMSEO_LLM_Provider_Gemini -> gemini).
		$suffix = substr( $class_name, 22 ); // Remove 'WPLLMSEO_LLM_Provider_'.
		$class_file = strtolower( str_replace( '_', '-', $suffix ) );
		$file_path = WPLLMSEO_PLUGIN_DIR . 'includes/providers/class-llm-provider-' . $class_file . '.php';
	} else {
		// Regular classes (e.g., WPLLMSEO_Admin -> admin).
		$suffix = substr( $class_name, 9 ); // Remove 'WPLLMSEO_'.
		$class_file = strtolower( str_replace( '_', '-', $suffix ) );
		$file_path = WPLLMSEO_PLUGIN_DIR . 'includes/class-' . $class_file . '.php';
	}

	// Include the file if it exists.
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
	
	// Check for interface files.
	if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
		$interface_file = WPLLMSEO_PLUGIN_DIR . 'includes/providers/interface-llm-provider.php';
		if ( file_exists( $interface_file ) ) {
			require_once $interface_file;
		}
	}
}
spl_autoload_register( 'wpllmseo_autoloader' );

/**
 * Initialize the plugin.
 */
function wpllmseo_init() {
	// Load text domain for translations.
	load_plugin_textdomain(
		WPLLMSEO_TEXT_DOMAIN,
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);

	// Initialize admin interface if in admin area.
	if ( is_admin() ) {
		WPLLMSEO_Admin::init();
	}

	// Hook for plugin activation.
	register_activation_hook( __FILE__, 'wpllmseo_activate' );

	// Hook for plugin deactivation.
	register_deactivation_hook( __FILE__, 'wpllmseo_deactivate' );
}
add_action( 'plugins_loaded', 'wpllmseo_init' );

/**
 * Activation hook - create necessary directories and database tables
 */
function wpllmseo_activate() {
	// Run installer
	WPLLMSEO_Installer_Upgrader::install();
}

/**
 * Deactivation hook
 */
function wpllmseo_deactivate() {
	// Clear scheduled cron events (but don't delete data)
	$worker_timestamp = wp_next_scheduled( 'wpllmseo_worker_event' );
	if ( $worker_timestamp ) {
		wp_unschedule_event( $worker_timestamp, 'wpllmseo_worker_event' );
	}

	$sitemap_timestamp = wp_next_scheduled( 'wpllmseo_generate_ai_sitemap_daily' );
	if ( $sitemap_timestamp ) {
		wp_unschedule_event( $sitemap_timestamp, 'wpllmseo_generate_ai_sitemap_daily' );
	}
	
	// Flush rewrite rules.
	flush_rewrite_rules();
}

/**
 * Add custom cron schedule for every minute.
 */
add_filter( 'cron_schedules', function( $schedules ) {
	$schedules['wpllmseo_every_minute'] = array(
		'interval' => 60,
		'display'  => __( 'Every Minute', 'wpllmseo' ),
	);
	return $schedules;
} );

/**
 * Hook AI Sitemap daily regeneration to cron
 */
add_action( 'wpllmseo_generate_ai_sitemap_daily', function() {
	$sitemap = new WPLLMSEO_AI_Sitemap();
	$sitemap->daily_regenerate();
} );

/**
 * Hook MCP token cleanup to cron (v1.1.0+)
 */
add_action( 'wpllmseo_cleanup_expired_tokens', function() {
	WPLLMSEO_MCP_Auth::cleanup_expired_tokens();
} );

/**
 * Log errors to file for debugging.
 *
 * @param string $message The error message to log.
 * @param string $level   The log level (error, warning, info).
 */
function wpllmseo_log( $message, $level = 'info' ) {
	$settings = get_option( 'wpllmseo_settings', array() );
	
	if ( empty( $settings['enable_logging'] ) ) {
		return;
	}

	$log_file = WPLLMSEO_PLUGIN_DIR . 'var/logs/plugin.log';
	$timestamp = current_time( 'Y-m-d H:i:s' );
	$log_entry = sprintf( "[%s] [%s] %s\n", $timestamp, strtoupper( $level ), $message );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $log_file, $log_entry, FILE_APPEND );
}

/**
 * Initialize plugin components.
 */
add_action( 'plugins_loaded', function() {
	// Check environment requirements
	$errors = WPLLMSEO_Installer_Upgrader::check_requirements();
	if ( ! empty( $errors ) ) {
		add_action( 'admin_notices', array( 'WPLLMSEO_Installer_Upgrader', 'display_requirement_notices' ) );
		return;
	}

	// Initialize capabilities
	WPLLMSEO_Capabilities::init();

	// Check if upgrade needed
	$installed_version = get_option( 'wpllmseo_db_version', '0.0.0' );
	if ( version_compare( $installed_version, WPLLMSEO_VERSION, '<' ) ) {
		WPLLMSEO_Installer_Upgrader::install();
	}

	// Initialize admin interface.
	if ( is_admin() ) {
		new WPLLMSEO_Admin();
	}
	
	// Initialize Module 2: Snippet System
	new WPLLMSEO_Snippets();
	new WPLLMSEO_Snippet_REST();
	new WPLLMSEO_Snippet_Indexer();
	
	// Initialize Module 3: Queue System
	new WPLLMSEO_Worker();
	new WPLLMSEO_Worker_REST();
	
	// Initialize Module 4: RAG System
	new WPLLMSEO_RAG_REST();
	
	// Initialize Module 5: AI Sitemap
	new WPLLMSEO_AI_Sitemap_REST();
	
	// Initialize Module 6: Dashboard Analytics
	new WPLLMSEO_Dashboard_REST();
	
	// Initialize Multi-Provider System (v1.2.0+)
	require_once WPLLMSEO_PLUGIN_DIR . 'includes/providers/interface-llm-provider.php';
	require_once WPLLMSEO_PLUGIN_DIR . 'includes/providers/class-llm-provider-base.php';
	WPLLMSEO_Provider_Manager::init();
	
	// Initialize High Priority Features
	WPLLMSEO_SEO_Compat::init();
	WPLLMSEO_Change_Tracker::init();
	WPLLMSEO_LLM_JSONLD::init();
	WPLLMSEO_Bulk_Snippet_Generator::init();
	WPLLMSEO_Post_Panel::init();
	
	// Initialize Medium Priority Features
	WPLLMSEO_Sitemap_Hub::init();
	WPLLMSEO_LLM_API::init();
	WPLLMSEO_Semantic_Linking::init();
	
	// Initialize Lower Priority Features
	WPLLMSEO_Media_Embeddings::init();
	WPLLMSEO_Semantic_Dashboard::init();
	WPLLMSEO_Model_Manager::init();
	WPLLMSEO_Crawler_Logs::init();
	WPLLMSEO_HTML_Renderer::init();
	
	// Initialize MCP Integration (v1.1.0+)
	WPLLMSEO_MCP_Adapter::init();
} );

/**
 * Load WP-CLI commands if WP-CLI is running
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPLLMSEO_PLUGIN_DIR . 'wp-cli/class-cli-worker.php';
}
