<?php
/**
 * Force Installation Script
 * 
 * Run this file directly via browser to force database table creation
 * URL: http://llm-indexing.local/wp-content/plugins/wp-llm-seo-indexing-master/force-install.php
 * 
 * This bypasses the normal activation hook and forces table creation
 */

// Load WordPress
define( 'WP_USE_THEMES', false );
require_once '../../../wp-load.php';

// Security check - must be logged in as admin
if ( ! is_user_logged_in() || ! current_user_can( 'activate_plugins' ) ) {
	wp_die( 'Unauthorized access. You must be logged in as an administrator.' );
}

echo '<html><head><title>Force Install WP LLM SEO</title>';
echo '<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin: 40px; background: #f0f0f1; }
.container { background: white; padding: 30px; max-width: 900px; margin: 0 auto; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
h1 { color: #1d2327; border-bottom: 2px solid #2271b1; padding-bottom: 10px; }
pre { background: #f6f7f7; padding: 15px; border-left: 4px solid #2271b1; overflow-x: auto; }
.success { color: #00a32a; }
.error { color: #d63638; }
.info { color: #2271b1; }
.button { background: #2271b1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-top: 20px; }
.button:hover { background: #135e96; }
</style>';
echo '</head><body><div class="container">';
echo '<h1>WP LLM SEO - Force Installation</h1>';
echo '<pre>';

// Manually load required classes since autoloader might not be working
require_once __DIR__ . '/includes/class-security.php';
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-crawler-logs.php';
require_once __DIR__ . '/includes/mcp/class-mcp-audit.php';
require_once __DIR__ . '/includes/mcp/class-mcp-auth.php';
require_once __DIR__ . '/includes/class-installer-upgrader.php';
// DB helpers for safe table name validation
require_once __DIR__ . '/includes/helpers/class-db-helpers.php';

echo "<span class='info'>Starting installation...</span>\n\n";

// Run the installation
try {
	echo "<span class='info'>1. Creating directories...</span>\n";
	WPLLMSEO_Installer_Upgrader::create_directories();
	echo "<span class='success'>   ✓ Directories created</span>\n\n";
	
	echo "<span class='info'>2. Creating database tables...</span>\n";
	WPLLMSEO_Installer_Upgrader::create_tables();
	echo "<span class='success'>   ✓ Tables created</span>\n\n";
	
	echo "<span class='info'>3. Checking tables...</span>\n";
	global $wpdb;
	$tables = array(
		'wpllmseo_snippets',
		'wpllmseo_chunks',
		'wpllmseo_jobs',
		'wpllmseo_jobs_dead_letter',
		'wpllmseo_tokens',
	);

	$tables_ok = true;
	foreach ( $tables as $table ) {
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( $table );
		if ( is_wp_error( $validated ) ) {
			echo "<span class='error'>   ✗ Table name not allowed: $table</span>\n";
			$tables_ok = false;
			continue;
		}

		$full_table = $validated;
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table ) );
		if ( $exists ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
			echo "<span class='success'>   ✓ Table exists: $full_table (rows: $count)</span>\n";
		} else {
			echo "<span class='error'>   ✗ Table missing: $full_table</span>\n";
			$tables_ok = false;
		}
	}
	echo "\n";
	
	echo "<span class='info'>4. Initializing default settings...</span>\n";
	WPLLMSEO_Installer_Upgrader::initialize_default_settings();
	$settings = get_option( 'wpllmseo_settings', array() );
	echo "<span class='success'>   ✓ Settings initialized (" . count( $settings ) . " options)</span>\n\n";
	
	echo "<span class='info'>5. Adding capabilities...</span>\n";
	WPLLMSEO_Capabilities::add_capabilities();
	echo "<span class='success'>   ✓ Capabilities added</span>\n\n";
	
	echo "<span class='info'>6. Scheduling cron events...</span>\n";
	WPLLMSEO_Installer_Upgrader::schedule_cron_events();
	echo "<span class='success'>   ✓ Cron events scheduled</span>\n\n";
	
	echo "<span class='info'>7. Updating database version...</span>\n";
	update_option( WPLLMSEO_Installer_Upgrader::DB_VERSION_KEY, WPLLMSEO_Installer_Upgrader::DB_VERSION );
	$version = get_option( WPLLMSEO_Installer_Upgrader::DB_VERSION_KEY );
	echo "<span class='success'>   ✓ Database version: $version</span>\n\n";
	
	if ( $tables_ok ) {
		echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
		echo "<span class='success'>✓ INSTALLATION COMPLETE!</span>\n";
		echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
	} else {
		echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
		echo "<span class='error'>⚠ INSTALLATION COMPLETED WITH WARNINGS</span>\n";
		echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
	}
	
	echo "Next steps:\n";
	echo "1. Go to WP Admin > LLM SEO > API Providers\n";
	echo "2. Add your Gemini, OpenAI, or Claude API key\n";
	echo "3. Click 'Save Provider Settings'\n";
	echo "4. Click 'Discover Models' to test the connection\n\n";
	
	echo '</pre>';
	echo '<a href="' . admin_url( 'admin.php?page=wpllmseo_providers' ) . '" class="button">→ Go to API Providers Settings</a>';
	echo ' <a href="' . admin_url( 'plugins.php' ) . '" class="button" style="background:#50575e;">← Back to Plugins</a>';
	
} catch ( Exception $e ) {
	echo "\n\n<span class='error'>✗ ERROR: " . esc_html( $e->getMessage() ) . "</span>\n";
	echo "Stack trace:\n" . esc_html( $e->getTraceAsString() );
}

echo '</div></body></html>';

