<?php
/**
 * Test Installation Script
 * 
 * Run this file to verify the plugin installation is complete and working
 * URL: http://llm-indexing.local/wp-content/plugins/wp-llm-seo-indexing-master/test-installation.php
 */

// Load WordPress
define( 'WP_USE_THEMES', false );
require_once '../../../wp-load.php';

// Security check
if ( ! is_user_logged_in() || ! current_user_can( 'activate_plugins' ) ) {
	wp_die( 'Unauthorized access. You must be logged in as an administrator.' );
}

?>
<!DOCTYPE html>
<html>
<head>
	<title>WP LLM SEO - Installation Test</title>
	<style>
		body { 
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; 
			margin: 40px; 
			background: #f0f0f1; 
		}
		.container { 
			background: white; 
			padding: 30px; 
			max-width: 1200px; 
			margin: 0 auto; 
			border-radius: 8px; 
			box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
		}
		h1 { 
			color: #1d2327; 
			border-bottom: 2px solid #2271b1; 
			padding-bottom: 10px; 
		}
		h2 {
			color: #1d2327;
			margin-top: 30px;
			border-bottom: 1px solid #dcdcde;
			padding-bottom: 8px;
		}
		.test-group {
			margin: 20px 0;
			padding: 15px;
			background: #f6f7f7;
			border-radius: 4px;
		}
		.success { 
			color: #00a32a; 
			font-weight: 600;
		}
		.error { 
			color: #d63638; 
			font-weight: 600;
		}
		.warning {
			color: #dba617;
			font-weight: 600;
		}
		.info { 
			color: #2271b1; 
		}
		table {
			width: 100%;
			border-collapse: collapse;
			margin: 15px 0;
		}
		th, td {
			padding: 10px;
			text-align: left;
			border-bottom: 1px solid #dcdcde;
		}
		th {
			background: #f6f7f7;
			font-weight: 600;
		}
		.button { 
			background: #2271b1; 
			color: white; 
			padding: 10px 20px; 
			text-decoration: none; 
			border-radius: 4px; 
			display: inline-block; 
			margin: 10px 10px 10px 0; 
		}
		.button:hover { 
			background: #135e96; 
			color: white;
		}
		.button-secondary {
			background: #50575e;
		}
		.button-secondary:hover {
			background: #3c434a;
		}
		code {
			background: #f0f0f1;
			padding: 2px 6px;
			border-radius: 3px;
			font-family: 'Courier New', monospace;
		}
	</style>
</head>
<body>
<div class="container">
	<h1>🧪 WP LLM SEO - Installation Test</h1>
	
	<?php
	global $wpdb;
	$all_passed = true;
	
	// Test 1: Database Tables
	echo '<h2>1. Database Tables</h2>';
	echo '<div class="test-group">';
	$required_tables = array(
		'wpllmseo_snippets' => 'Snippets storage',
		'wpllmseo_chunks' => 'Content chunks',
		'wpllmseo_jobs' => 'Queue jobs',
		'wpllmseo_jobs_dead_letter' => 'Failed jobs',
		'wpllmseo_tokens' => 'Token index',
	);
	
	echo '<table>';
	echo '<tr><th>Table Name</th><th>Description</th><th>Status</th><th>Row Count</th></tr>';
	foreach ( $required_tables as $table => $description ) {
		$full_table = $wpdb->prefix . $table;
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table ) );
		if ( $exists ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `$full_table`" );
			echo "<tr><td><code>$full_table</code></td><td>$description</td><td><span class='success'>✓ Exists</span></td><td>$count</td></tr>";
		} else {
			echo "<tr><td><code>$full_table</code></td><td>$description</td><td><span class='error'>✗ Missing</span></td><td>-</td></tr>";
			$all_passed = false;
		}
	}
	echo '</table>';
	echo '</div>';
	
	// Test 2: Settings
	echo '<h2>2. Plugin Settings</h2>';
	echo '<div class="test-group">';
	$settings = get_option( 'wpllmseo_settings', array() );
	$db_version = get_option( 'wpllmseo_db_version', 'Not set' );
	
	if ( ! empty( $settings ) ) {
		echo "<p><span class='success'>✓ Settings initialized</span> - " . count( $settings ) . " options configured</p>";
		echo "<p><strong>DB Version:</strong> <code>$db_version</code></p>";
		
		// Show provider configs
		if ( isset( $settings['providers'] ) && ! empty( $settings['providers'] ) ) {
			echo "<p><span class='success'>✓ Provider configs exist</span></p>";
			echo '<table>';
			echo '<tr><th>Provider</th><th>API Key Set</th></tr>';
			foreach ( $settings['providers'] as $provider_id => $config ) {
				$has_key = ! empty( $config['api_key'] );
				$status = $has_key ? "<span class='success'>✓ Yes</span>" : "<span class='warning'>⚠ Not configured</span>";
				echo "<tr><td><code>$provider_id</code></td><td>$status</td></tr>";
			}
			echo '</table>';
		} else {
			echo "<p><span class='warning'>⚠ No providers configured yet</span></p>";
		}
	} else {
		echo "<p><span class='error'>✗ Settings not initialized</span></p>";
		$all_passed = false;
	}
	echo '</div>';
	
	// Test 3: Classes
	echo '<h2>3. Required Classes</h2>';
	echo '<div class="test-group">';
	$required_classes = array(
		'WPLLMSEO_Admin' => 'Admin interface',
		'WPLLMSEO_Provider_Manager' => 'Provider management',
		'WPLLMSEO_Installer_Upgrader' => 'Installation system',
		'WPLLMSEO_Queue' => 'Job queue',
		'WPLLMSEO_Snippets' => 'Snippet system',
	);
	
	echo '<table>';
	echo '<tr><th>Class Name</th><th>Description</th><th>Status</th></tr>';
	foreach ( $required_classes as $class => $description ) {
		$exists = class_exists( $class );
		if ( $exists ) {
			echo "<tr><td><code>$class</code></td><td>$description</td><td><span class='success'>✓ Loaded</span></td></tr>";
		} else {
			echo "<tr><td><code>$class</code></td><td>$description</td><td><span class='error'>✗ Not found</span></td></tr>";
			$all_passed = false;
		}
	}
	echo '</table>';
	echo '</div>';
	
	// Test 4: Directories
	echo '<h2>4. File System</h2>';
	echo '<div class="test-group">';
	$required_dirs = array(
		'var' => 'Variable data',
		'var/logs' => 'Log files',
		'var/cache' => 'Cache files',
	);
	
	echo '<table>';
	echo '<tr><th>Directory</th><th>Description</th><th>Exists</th><th>Writable</th></tr>';
	foreach ( $required_dirs as $dir => $description ) {
		$full_path = WPLLMSEO_PLUGIN_DIR . $dir;
		$exists = file_exists( $full_path );
		$writable = is_writable( $full_path );
		
		$exists_status = $exists ? "<span class='success'>✓</span>" : "<span class='error'>✗</span>";
		$writable_status = $writable ? "<span class='success'>✓</span>" : "<span class='error'>✗</span>";
		
		echo "<tr><td><code>$dir</code></td><td>$description</td><td>$exists_status</td><td>$writable_status</td></tr>";
		
		if ( ! $exists || ! $writable ) {
			$all_passed = false;
		}
	}
	echo '</table>';
	echo '</div>';
	
	// Test 5: Admin Menu
	echo '<h2>5. Admin Menu</h2>';
	echo '<div class="test-group">';
	$menu_url = admin_url( 'admin.php?page=wpllmseo_dashboard' );
	$providers_url = admin_url( 'admin.php?page=wpllmseo_providers' );
	echo "<p>If the admin menu is registered correctly, these links should work:</p>";
	echo "<p><a href='$menu_url' class='button'>Dashboard</a>";
	echo "<a href='$providers_url' class='button'>API Providers</a></p>";
	echo '</div>';
	
	// Test 6: Cron Jobs
	echo '<h2>6. Scheduled Tasks</h2>';
	echo '<div class="test-group">';
	$cron_jobs = array(
		'wpllmseo_worker_event' => 'Worker (every minute)',
		'wpllmseo_generate_ai_sitemap_daily' => 'Sitemap generation (daily)',
		'wpllmseo_cleanup_expired_tokens' => 'Token cleanup (daily)',
	);
	
	echo '<table>';
	echo '<tr><th>Cron Hook</th><th>Description</th><th>Status</th><th>Next Run</th></tr>';
	foreach ( $cron_jobs as $hook => $description ) {
		$next_run = wp_next_scheduled( $hook );
		if ( $next_run ) {
			$time_str = date( 'Y-m-d H:i:s', $next_run );
			echo "<tr><td><code>$hook</code></td><td>$description</td><td><span class='success'>✓ Scheduled</span></td><td>$time_str</td></tr>";
		} else {
			echo "<tr><td><code>$hook</code></td><td>$description</td><td><span class='error'>✗ Not scheduled</span></td><td>-</td></tr>";
		}
	}
	echo '</table>';
	echo '</div>';
	
	// Final Result
	echo '<hr style="margin: 30px 0; border: none; border-top: 2px solid #dcdcde;">';
	if ( $all_passed ) {
		echo '<h2 style="color: #00a32a;">✓ All Tests Passed!</h2>';
		echo '<p>The plugin is properly installed and ready to use.</p>';
		echo '<p><strong>Next steps:</strong></p>';
		echo '<ol>';
		echo '<li>Go to <strong>LLM SEO > API Providers</strong> and add your API key</li>';
		echo '<li>Click <strong>Save Provider Settings</strong></li>';
		echo '<li>Click <strong>Discover Models</strong> to test the connection</li>';
		echo '<li>Create or edit posts - they will be automatically indexed</li>';
		echo '</ol>';
	} else {
		echo '<h2 style="color: #d63638;">⚠ Some Tests Failed</h2>';
		echo '<p>Please run the force install script to fix missing components:</p>';
		echo '<p><a href="' . plugins_url( 'force-install.php', __FILE__ ) . '" class="button">Run Force Install</a></p>';
	}
	
	echo '<hr style="margin: 30px 0; border: none; border-top: 1px solid #dcdcde;">';
	echo '<p>';
	echo '<a href="' . admin_url( 'admin.php?page=wpllmseo_providers' ) . '" class="button">→ Configure API Providers</a>';
	echo '<a href="' . admin_url( 'admin.php?page=wpllmseo_dashboard' ) . '" class="button">→ View Dashboard</a>';
	echo '<a href="' . admin_url( 'plugins.php' ) . '" class="button button-secondary">← Back to Plugins</a>';
	echo '</p>';
	?>
	
</div>
</body>
</html>
