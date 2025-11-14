<?php
/**
 * Uninstall Script
 *
 * Handles plugin uninstallation and data cleanup.
 * 
 * This file is called when the plugin is uninstalled via WordPress admin.
 * It will only run if the user has defined WPLLMSEO_DELETE_ALL_DATA constant.
 *
 * @package WP_LLM_SEO_Indexing
 */

// Exit if accessed directly or not in uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Uninstall process
 */
function wpllmseo_uninstall() {
	global $wpdb;

	// Delete all database tables (no conditional - always clean up on uninstall)
	$tables = array(
		'wpllmseo_snippets',
		'wpllmseo_chunks',
		'wpllmseo_jobs',
		'wpllmseo_jobs_dead_letter',
		'wpllmseo_tokens',
		'wpllmseo_crawler_logs',
		'wpllmseo_mcp_audit',
		'wpllmseo_mcp_tokens',
	);
	
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
	}

	// Delete log files
	wpllmseo_delete_directory( plugin_dir_path( __FILE__ ) . 'var/logs' );

	// Delete cache files
	wpllmseo_delete_directory( plugin_dir_path( __FILE__ ) . 'var/cache' );

	// Delete transients
	wpllmseo_delete_transients();

	// Delete plugin settings
	delete_option( 'wpllmseo_settings' );
	delete_option( 'wpllmseo_db_version' );

	// Clear scheduled cron events
	wp_clear_scheduled_hook( 'wpllmseo_worker_event' );
	wp_clear_scheduled_hook( 'wpllmseo_generate_ai_sitemap_daily' );
	wp_clear_scheduled_hook( 'wpllmseo_cleanup_expired_tokens' );

	// Remove capabilities
	wpllmseo_remove_capabilities();

	// Flush rewrite rules
	flush_rewrite_rules();
}

/**
 * Delete directory recursively
 *
 * @param string $dir Directory path.
 */
function wpllmseo_delete_directory( $dir ) {
	if ( ! file_exists( $dir ) ) {
		return;
	}

	$files = array_diff( scandir( $dir ), array( '.', '..' ) );
	
	foreach ( $files as $file ) {
		$path = $dir . '/' . $file;
		
		if ( is_dir( $path ) ) {
			wpllmseo_delete_directory( $path );
		} else {
			unlink( $path );
		}
	}
	
	rmdir( $dir );
}

/**
 * Delete all plugin transients
 */
function wpllmseo_delete_transients() {
	global $wpdb;

	// Delete transients with wpllmseo prefix
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_wpllmseo_%',
			'_transient_timeout_wpllmseo_%'
		)
	);
}

/**
 * Remove plugin capabilities
 */
function wpllmseo_remove_capabilities() {
	$role = get_role( 'administrator' );

	if ( $role && $role->has_cap( 'manage_wpllmseo' ) ) {
		$role->remove_cap( 'manage_wpllmseo' );
	}
}

// Run uninstall
wpllmseo_uninstall();
