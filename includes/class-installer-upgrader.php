<?php
/**
 * Installer and Upgrader
 *
 * Handles plugin installation, database migrations, and version upgrades.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Installer_Upgrader
 *
 * Manages plugin installation and upgrades.
 */
class WPLLMSEO_Installer_Upgrader {

	/**
	 * Current database version
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1.1';

	/**
	 * Database version option key
	 *
	 * @var string
	 */
	const DB_VERSION_KEY = 'wpllmseo_db_version';

	/**
	 * Run installer
	 */
	public static function install() {
		global $wpdb;

		$installed_version = get_option( self::DB_VERSION_KEY, '0.0.0' );

		// Create directories
		self::create_directories();

		// Create database tables
		self::create_tables();

		// Run migrations if needed
		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			self::migrate_database( $installed_version );
		}

		// Update database version
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );

		// Initialize default settings if not exist
		self::initialize_default_settings();

		// Add capabilities
		WPLLMSEO_Capabilities::add_capabilities();

		// Schedule cron events
		self::schedule_cron_events();

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Create necessary directories
	 */
	public static function create_directories() {
		$directories = array(
			WPLLMSEO_PLUGIN_DIR . 'var',
			WPLLMSEO_PLUGIN_DIR . 'var/logs',
			WPLLMSEO_PLUGIN_DIR . 'var/cache',
		);

		foreach ( $directories as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// Protect directories
			WPLLMSEO_Security::prevent_directory_listing( $dir );
			WPLLMSEO_Security::create_index_file( $dir );
		}
	}

	/**
	 * Create database tables
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Snippets table
		$snippets_table = $wpdb->prefix . 'wpllmseo_snippets';
		$snippets_sql   = "CREATE TABLE IF NOT EXISTS $snippets_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			snippet_text LONGTEXT NOT NULL,
			snippet_hash CHAR(64) DEFAULT '',
			embedding MEDIUMBLOB DEFAULT NULL,
			is_preferred TINYINT(1) DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_post_id (post_id),
			INDEX idx_preferred (is_preferred),
			INDEX idx_created (created_at)
		) $charset_collate;";

		// Chunks table
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';
		$chunks_sql   = "CREATE TABLE IF NOT EXISTS $chunks_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			chunk_text LONGTEXT NOT NULL,
			chunk_hash CHAR(64) DEFAULT '',
			embedding MEDIUMBLOB DEFAULT NULL,
			chunk_index INT DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_post_id (post_id),
			INDEX idx_created (created_at)
		) $charset_collate;";

		// Jobs table
		$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
		$jobs_sql   = "CREATE TABLE IF NOT EXISTS $jobs_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			job_type VARCHAR(50) NOT NULL,
			post_id BIGINT(20) UNSIGNED DEFAULT NULL,
			snippet_id BIGINT(20) UNSIGNED DEFAULT NULL,
			payload LONGTEXT DEFAULT NULL,
			status VARCHAR(20) DEFAULT 'queued',
			attempts INT DEFAULT 0,
			locked TINYINT(1) DEFAULT 0,
			locked_at DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_status (status),
			INDEX idx_locked (locked),
			INDEX idx_post_id (post_id),
			INDEX idx_snippet_id (snippet_id),
			INDEX idx_created (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $snippets_sql );
		dbDelta( $chunks_sql );
		dbDelta( $jobs_sql );
		
		// Create crawler logs table (lower priority feature)
		WPLLMSEO_Crawler_Logs::create_table();
		
		// Create MCP tables (v1.1.0+)
		WPLLMSEO_MCP_Audit::create_table();
		WPLLMSEO_MCP_Auth::create_table();
	}

	/**
	 * Migrate database schema
	 *
	 * @param string $from_version Previous version.
	 */
	public static function migrate_database( $from_version ) {
		global $wpdb;

		// Add missing columns if upgrading from older versions
		$snippets_table = $wpdb->prefix . 'wpllmseo_snippets';
		$chunks_table   = $wpdb->prefix . 'wpllmseo_chunks';
		$jobs_table     = $wpdb->prefix . 'wpllmseo_jobs';

		// Check if is_preferred column exists in snippets
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $snippets_table LIKE 'is_preferred'" );
		
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $snippets_table ADD COLUMN is_preferred TINYINT(1) DEFAULT 0 AFTER embedding" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $snippets_table ADD INDEX idx_preferred (is_preferred)" );
		}

		// Check if chunk_index exists in chunks
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $chunks_table LIKE 'chunk_index'" );
		
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $chunks_table ADD COLUMN chunk_index INT DEFAULT 0 AFTER embedding" );
		}

		// Check if post_id column exists in jobs table (v1.1.1+)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $jobs_table LIKE 'post_id'" );
		
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $jobs_table ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER job_type" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $jobs_table ADD INDEX idx_post_id (post_id)" );
		}

		// Check if snippet_id column exists in jobs table (v1.1.1+)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM $jobs_table LIKE 'snippet_id'" );
		
		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $jobs_table ADD COLUMN snippet_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "ALTER TABLE $jobs_table ADD INDEX idx_snippet_id (snippet_id)" );
		}

		// Add any future migrations here based on version comparisons
	}

	/**
	 * Initialize default settings
	 */
	public static function initialize_default_settings() {
		$existing_settings = get_option( 'wpllmseo_settings', array() );

		// Only set defaults if option doesn't exist or is empty
		if ( empty( $existing_settings ) ) {
			$default_settings = array(
				'api_key'                         => '',
				'model'                           => WPLLMSEO_GEMINI_MODEL,
				'auto_index'                      => true,
				'batch_size'                      => 10,
				'enable_logging'                  => true,
				'theme_mode'                      => 'auto',
				'enable_ai_sitemap'               => true,
				'content_license'                 => 'GPL',
				// High Priority Features
				'prefer_external_seo'             => true,
				'use_similarity_threshold'        => true,
				'enable_llm_jsonld'               => false,
				// Medium Priority Features
				'sitemap_hub_public'              => false,
				'sitemap_hub_token'               => wp_generate_password( 32, false ),
				'llm_api_public'                  => false,
				'llm_api_token'                   => wp_generate_password( 32, false ),
				'llm_api_rate_limit'              => true,
				'llm_api_rate_limit_value'        => 100,
				'enable_semantic_linking'         => false,
				'semantic_linking_threshold'      => 0.75,
				'semantic_linking_max_suggestions' => 5,
				// Lower Priority Features
				'enable_media_embeddings'         => false,
				'enable_crawler_logs'             => false,
				'enable_html_renderer'            => false,
				// MCP Integration (v1.1.0+)
				'wpllmseo_enable_mcp'             => false,
				'wpllmseo_mcp_respect_llms_txt'   => true,
			);

			update_option( 'wpllmseo_settings', $default_settings );
		} else {
			$updated = false;
			
			// If settings exist but enable_ai_sitemap is not set, add it as true
			if ( ! isset( $existing_settings['enable_ai_sitemap'] ) ) {
				$existing_settings['enable_ai_sitemap'] = true;
				$updated = true;
			}
			
			// Add new high priority compatibility settings if missing
			if ( ! isset( $existing_settings['prefer_external_seo'] ) ) {
				$existing_settings['prefer_external_seo'] = true;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['use_similarity_threshold'] ) ) {
				$existing_settings['use_similarity_threshold'] = true;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['enable_llm_jsonld'] ) ) {
				$existing_settings['enable_llm_jsonld'] = false;
				$updated = true;
			}
			
			// Add new medium priority settings if missing
			if ( ! isset( $existing_settings['sitemap_hub_public'] ) ) {
				$existing_settings['sitemap_hub_public'] = false;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['sitemap_hub_token'] ) ) {
				$existing_settings['sitemap_hub_token'] = wp_generate_password( 32, false );
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['llm_api_public'] ) ) {
				$existing_settings['llm_api_public'] = false;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['llm_api_token'] ) ) {
				$existing_settings['llm_api_token'] = wp_generate_password( 32, false );
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['llm_api_rate_limit'] ) ) {
				$existing_settings['llm_api_rate_limit'] = true;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['llm_api_rate_limit_value'] ) ) {
				$existing_settings['llm_api_rate_limit_value'] = 100;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['enable_semantic_linking'] ) ) {
				$existing_settings['enable_semantic_linking'] = false;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['semantic_linking_threshold'] ) ) {
				$existing_settings['semantic_linking_threshold'] = 0.75;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['semantic_linking_max_suggestions'] ) ) {
				$existing_settings['semantic_linking_max_suggestions'] = 5;
				$updated = true;
			}
			
			// Add new lower priority settings if missing
			if ( ! isset( $existing_settings['enable_media_embeddings'] ) ) {
				$existing_settings['enable_media_embeddings'] = false;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['enable_crawler_logs'] ) ) {
				$existing_settings['enable_crawler_logs'] = false;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['enable_html_renderer'] ) ) {
				$existing_settings['enable_html_renderer'] = false;
				$updated = true;
			}
			
			// Add MCP integration settings if missing (v1.1.0+)
			if ( ! isset( $existing_settings['wpllmseo_enable_mcp'] ) ) {
				$existing_settings['wpllmseo_enable_mcp'] = false;
				$updated = true;
			}
			
			if ( ! isset( $existing_settings['wpllmseo_mcp_respect_llms_txt'] ) ) {
				$existing_settings['wpllmseo_mcp_respect_llms_txt'] = true;
				$updated = true;
			}
			
			if ( $updated ) {
				update_option( 'wpllmseo_settings', $existing_settings );
			}
		}
		
		// Flush rewrite rules to register sitemap hub endpoints
		flush_rewrite_rules();
	}

	/**
	 * Schedule cron events
	 */
	public static function schedule_cron_events() {
		// Worker cron (every minute)
		if ( ! wp_next_scheduled( 'wpllmseo_worker_event' ) ) {
			wp_schedule_event( time(), 'wpllmseo_every_minute', 'wpllmseo_worker_event' );
		}

		// AI Sitemap daily regeneration
		if ( ! wp_next_scheduled( 'wpllmseo_generate_ai_sitemap_daily' ) ) {
			wp_schedule_event( strtotime( '03:00:00' ), 'daily', 'wpllmseo_generate_ai_sitemap_daily' );
		}
		
		// MCP token cleanup (daily)
		if ( ! wp_next_scheduled( 'wpllmseo_cleanup_expired_tokens' ) ) {
			wp_schedule_event( strtotime( '04:00:00' ), 'daily', 'wpllmseo_cleanup_expired_tokens' );
		}
	}

	/**
	 * Run upgrade routine
	 *
	 * Can be called via WP-CLI: wp wpllmseo upgrade
	 */
	public static function upgrade() {
		self::install();
		
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::success( 'Database upgraded to version ' . self::DB_VERSION );
		}
	}

	/**
	 * Check environment requirements
	 *
	 * @return array Errors array (empty if all good).
	 */
	public static function check_requirements() {
		$errors = array();

		// Check PHP version
		if ( version_compare( PHP_VERSION, WPLLMSEO_MIN_PHP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %1$s: Required PHP version, %2$s: Current PHP version */
				__( 'WP LLM SEO requires PHP %1$s or higher. You are running PHP %2$s.', 'wpllmseo' ),
				WPLLMSEO_MIN_PHP_VERSION,
				PHP_VERSION
			);
		}

		// Check WordPress version
		global $wp_version;
		if ( version_compare( $wp_version, WPLLMSEO_MIN_WP_VERSION, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %1$s: Required WP version, %2$s: Current WP version */
				__( 'WP LLM SEO requires WordPress %1$s or higher. You are running WordPress %2$s.', 'wpllmseo' ),
				WPLLMSEO_MIN_WP_VERSION,
				$wp_version
			);
		}

		// Check if logs directory is writable
		$log_dir = WPLLMSEO_PLUGIN_DIR . 'var/logs';
		if ( file_exists( $log_dir ) && ! is_writable( $log_dir ) ) {
			$errors[] = sprintf(
				/* translators: %s: Log directory path */
				__( 'Log directory is not writable: %s', 'wpllmseo' ),
				$log_dir
			);
		}

		// Check if cache directory is writable
		$cache_dir = WPLLMSEO_PLUGIN_DIR . 'var/cache';
		if ( file_exists( $cache_dir ) && ! is_writable( $cache_dir ) ) {
			$errors[] = sprintf(
				/* translators: %s: Cache directory path */
				__( 'Cache directory is not writable: %s', 'wpllmseo' ),
				$cache_dir
			);
		}

		// Check if API key is set
		$settings = get_option( 'wpllmseo_settings', array() );
		if ( empty( $settings['api_key'] ) ) {
			$errors[] = __( 'Gemini API key is not configured. Please add your API key in Settings.', 'wpllmseo' );
		}

		return $errors;
	}

	/**
	 * Display admin notices for requirements
	 */
	public static function display_requirement_notices() {
		$errors = self::check_requirements();

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				echo '<div class="notice notice-error"><p>';
				echo esc_html( $error );
				echo '</p></div>';
			}
		}
	}
}
