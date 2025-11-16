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
	const DB_VERSION = '1.2.0';

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
			embedding_json LONGTEXT DEFAULT NULL,
			embedding_format VARCHAR(64) DEFAULT 'json_float_array_v1',
			embedding_dim INT DEFAULT NULL,
			embedding_checksum CHAR(64) DEFAULT NULL,
			embedding_version VARCHAR(32) DEFAULT 'v1',
			is_preferred TINYINT(1) DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_post_id (post_id),
			INDEX idx_preferred (is_preferred),
			INDEX idx_created (created_at),
			UNIQUE INDEX uniq_snippet_hash (snippet_hash)
		) $charset_collate;";

		// Chunks table
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';
		$chunks_sql   = "CREATE TABLE IF NOT EXISTS $chunks_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			chunk_text LONGTEXT NOT NULL,
			chunk_hash CHAR(64) DEFAULT '',
			embedding MEDIUMBLOB DEFAULT NULL,
			embedding_json LONGTEXT DEFAULT NULL,
			embedding_format VARCHAR(64) DEFAULT 'json_float_array_v1',
			embedding_dim INT DEFAULT NULL,
			embedding_checksum CHAR(64) DEFAULT NULL,
			embedding_version VARCHAR(32) DEFAULT 'v1',
			chunk_index INT DEFAULT 0,
			token_count INT DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_post_id (post_id),
			INDEX idx_post_id_chunk_index (post_id, chunk_index),
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
			max_attempts INT DEFAULT 5,
			last_error TEXT DEFAULT NULL,
			locked TINYINT(1) DEFAULT 0,
			locked_at DATETIME DEFAULT NULL,
			runner VARCHAR(128) DEFAULT NULL,
			dedupe_key VARCHAR(191) DEFAULT NULL,
			run_after DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			INDEX idx_status (status),
			INDEX idx_locked (locked),
			INDEX idx_post_id (post_id),
			INDEX idx_snippet_id (snippet_id),
			INDEX idx_created (created_at),
			INDEX idx_status_locked_created (status, locked, created_at),
			INDEX idx_dedupe_key (dedupe_key)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $snippets_sql );
		dbDelta( $chunks_sql );
		dbDelta( $jobs_sql );
		
		// Dead-letter table for failed jobs
		$dead_letter_table = $wpdb->prefix . 'wpllmseo_jobs_dead_letter';
		$dead_letter_sql   = "CREATE TABLE IF NOT EXISTS $dead_letter_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			original_job_id BIGINT(20) UNSIGNED DEFAULT NULL,
			payload LONGTEXT DEFAULT NULL,
			reason TEXT DEFAULT NULL,
			failed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_job_id (original_job_id),
			INDEX idx_failed_at (failed_at)
		) $charset_collate;";
		dbDelta( $dead_letter_sql );

		// Exec guard logs
		$exec_logs_table = $wpdb->prefix . 'wpllmseo_exec_logs';
		$exec_logs_sql   = "CREATE TABLE IF NOT EXISTS $exec_logs_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			command TEXT DEFAULT NULL,
			stdout LONGTEXT DEFAULT NULL,
			stderr LONGTEXT DEFAULT NULL,
			result TINYINT(1) DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_user_id (user_id),
			INDEX idx_created (created_at)
		) $charset_collate;";
		dbDelta( $exec_logs_sql );
		
		// Token table for two-stage candidate selection
		$tokens_table = $wpdb->prefix . 'wpllmseo_tokens';
		$tokens_sql   = "CREATE TABLE IF NOT EXISTS $tokens_table (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			token VARCHAR(191) NOT NULL,
			score FLOAT DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			INDEX idx_token (token),
			INDEX idx_post_id (post_id),
			INDEX idx_token_post_id (token, post_id)
		) $charset_collate;";
		dbDelta( $tokens_sql );
		
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

		// === v1.2.0 Migrations for Enterprise Features ===
		
		// Add embedding metadata columns to snippets table
		$embedding_columns = array(
			'embedding_json' => 'LONGTEXT DEFAULT NULL',
			'embedding_format' => "VARCHAR(64) DEFAULT 'json_float_array_v1'",
			'embedding_dim' => 'INT DEFAULT NULL',
			'embedding_checksum' => 'CHAR(64) DEFAULT NULL',
			'embedding_version' => "VARCHAR(32) DEFAULT 'v1'",
		);
		
		require_once __DIR__ . '/helpers/class-db-helpers.php';

		foreach ( $embedding_columns as $col => $definition ) {
			$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_snippets' );
			if ( is_wp_error( $validated ) ) {
				continue;
			}
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, $col ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_snippets', "ADD COLUMN $col $definition" );
			}
		}
		
		// Add embedding metadata columns to chunks table
		foreach ( $embedding_columns as $col => $definition ) {
			$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_chunks' );
			if ( is_wp_error( $validated ) ) {
				continue;
			}
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, $col ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_chunks', "ADD COLUMN $col $definition" );
			}
		}
		
		// Add token_count to chunks table
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_chunks' );
		if ( ! is_wp_error( $validated ) ) {
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'token_count' ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_chunks', 'ADD COLUMN token_count INT DEFAULT 0' );
			}
		}
		
		// Add job retry columns
		$job_columns = array(
			'max_attempts' => 'INT DEFAULT 5',
			'last_error' => 'TEXT DEFAULT NULL',
			'runner' => 'VARCHAR(128) DEFAULT NULL',
			'dedupe_key' => 'VARCHAR(191) DEFAULT NULL',
			'run_after' => 'DATETIME DEFAULT NULL',
		);
		
		foreach ( $job_columns as $col => $definition ) {
			$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
			if ( is_wp_error( $validated ) ) {
				continue;
			}
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, $col ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', "ADD COLUMN $col $definition" );
			}
		}
		
		// Add missing indexes
		// Create indexes via safe_alter_table wrapper where supported
		WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', 'ADD INDEX idx_status_locked_created (status, locked, created_at)' );
		WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', 'ADD INDEX idx_dedupe_key (dedupe_key)' );
		WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_chunks', 'ADD INDEX idx_post_id_chunk_index (post_id, chunk_index)' );
		WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_snippets', 'ADD UNIQUE INDEX uniq_snippet_hash (snippet_hash)' );

		// === Legacy Migrations ===

		// Check if is_preferred column exists in snippets
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_snippets' );
		if ( ! is_wp_error( $validated ) ) {
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'is_preferred' ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_snippets', 'ADD COLUMN is_preferred TINYINT(1) DEFAULT 0 AFTER embedding' );
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_snippets', 'ADD INDEX idx_preferred (is_preferred)' );
			}
		}

		// Check if chunk_index exists in chunks
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_chunks' );
		if ( ! is_wp_error( $validated ) ) {
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'chunk_index' ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_chunks', 'ADD COLUMN chunk_index INT DEFAULT 0 AFTER embedding' );
			}
		}

		// Add AI index columns to chunks table (v1.3.0+)
		$ai_index_columns = array(
			'chunk_id'        => 'VARCHAR(64) DEFAULT NULL',
			'text'            => 'LONGTEXT DEFAULT NULL',
			'start_word'      => 'INT DEFAULT 0',
			'end_word'        => 'INT DEFAULT 0',
			'word_count'      => 'INT DEFAULT 0',
			'char_count'      => 'INT DEFAULT 0',
			'token_estimate'  => 'INT DEFAULT 0',
			'embedding_model' => 'VARCHAR(128) DEFAULT NULL',
		);
		
		foreach ( $ai_index_columns as $col => $definition ) {
			$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_chunks' );
			if ( is_wp_error( $validated ) ) {
				continue;
			}
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, $col ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_chunks', "ADD COLUMN $col $definition" );
			}
		}
		
		// Add unique index on chunk_id if it doesn't exist
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_chunks' );
		if ( ! is_wp_error( $validated ) ) {
			WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_chunks', 'ADD UNIQUE INDEX idx_chunk_id (chunk_id)' );
		}

		// Check if post_id column exists in jobs table (v1.1.1+)
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
		if ( ! is_wp_error( $validated ) ) {
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'post_id' ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', 'ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER job_type' );
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', 'ADD INDEX idx_post_id (post_id)' );
			}
		}

		// Check if snippet_id column exists in jobs table (v1.1.1+)
		if ( ! is_wp_error( $validated ) ) {
			$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'snippet_id' ) );
			if ( empty( $r ) ) {
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', 'ADD COLUMN snippet_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id' );
				WPLLMSEO_DB_Helpers::safe_alter_table( 'wpllmseo_jobs', 'ADD INDEX idx_snippet_id (snippet_id)' );
			}
		}
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
				'daily_token_limit'               => 100000,
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
				'exec_guard_enabled'              => false,
				'enable_crawler_logs'             => false,
				'enable_html_renderer'            => false,
				// MCP Integration (v1.1.0+)
				'wpllmseo_enable_mcp'             => false,
				'wpllmseo_mcp_respect_llms_txt'   => true,
			);

			update_option( 'wpllmseo_settings', $default_settings );
		} else {
			$updated = false;
			
			// Add daily token limit if missing
			if ( ! isset( $existing_settings['daily_token_limit'] ) ) {
				$existing_settings['daily_token_limit'] = 100000;
				$updated = true;
			}
			
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
		// Worker cron (daily at 2 AM) - optimized to run once per day
		if ( ! wp_next_scheduled( 'wpllmseo_worker_event' ) ) {
			wp_schedule_event( strtotime( '02:00:00' ), 'daily', 'wpllmseo_worker_event' );
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

		// Don't check for API key here - it's now handled by the multi-provider system
		// Users can configure API keys in the API Providers page

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
