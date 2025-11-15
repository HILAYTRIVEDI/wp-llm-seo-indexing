<?php
/**
 * Migration Script: Update Cron Schedule
 *
 * This script migrates the worker cron from every-minute to daily schedule.
 * Run this once to update existing installations.
 *
 * Usage: wp eval-file migrate-cron-schedule.php
 *
 * @package WP_LLM_SEO_Indexing
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	// Allow WP-CLI execution
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		exit;
	}
}

echo "=== WP LLM SEO: Cron Schedule Migration ===\n\n";

// Clear old every-minute schedule
$worker_timestamp = wp_next_scheduled( 'wpllmseo_worker_event' );
if ( $worker_timestamp ) {
	echo "✓ Found existing worker cron scheduled for: " . date( 'Y-m-d H:i:s', $worker_timestamp ) . "\n";
	wp_clear_scheduled_hook( 'wpllmseo_worker_event' );
	echo "✓ Cleared old worker cron schedule\n";
} else {
	echo "ℹ No existing worker cron found\n";
}

// Clear other cron hooks to reset them
$sitemap_timestamp = wp_next_scheduled( 'wpllmseo_generate_ai_sitemap_daily' );
if ( $sitemap_timestamp ) {
	wp_clear_scheduled_hook( 'wpllmseo_generate_ai_sitemap_daily' );
	echo "✓ Cleared sitemap cron schedule\n";
}

$token_timestamp = wp_next_scheduled( 'wpllmseo_cleanup_expired_tokens' );
if ( $token_timestamp ) {
	wp_clear_scheduled_hook( 'wpllmseo_cleanup_expired_tokens' );
	echo "✓ Cleared token cleanup cron schedule\n";
}

// Re-schedule with new daily intervals
echo "\n--- Scheduling new cron events ---\n";

// Worker cron (daily at 2 AM)
if ( ! wp_next_scheduled( 'wpllmseo_worker_event' ) ) {
	$next_2am = strtotime( 'tomorrow 02:00:00' );
	wp_schedule_event( $next_2am, 'daily', 'wpllmseo_worker_event' );
	echo "✓ Scheduled worker cron daily at 2:00 AM (Next run: " . date( 'Y-m-d H:i:s', $next_2am ) . ")\n";
}

// AI Sitemap daily regeneration (3 AM)
if ( ! wp_next_scheduled( 'wpllmseo_generate_ai_sitemap_daily' ) ) {
	$next_3am = strtotime( 'tomorrow 03:00:00' );
	wp_schedule_event( $next_3am, 'daily', 'wpllmseo_generate_ai_sitemap_daily' );
	echo "✓ Scheduled sitemap regeneration daily at 3:00 AM (Next run: " . date( 'Y-m-d H:i:s', $next_3am ) . ")\n";
}

// MCP token cleanup (4 AM)
if ( ! wp_next_scheduled( 'wpllmseo_cleanup_expired_tokens' ) ) {
	$next_4am = strtotime( 'tomorrow 04:00:00' );
	wp_schedule_event( $next_4am, 'daily', 'wpllmseo_cleanup_expired_tokens' );
	echo "✓ Scheduled token cleanup daily at 4:00 AM (Next run: " . date( 'Y-m-d H:i:s', $next_4am ) . ")\n";
}

// Reset cooldown timestamps
delete_option( 'wpllmseo_worker_last_run' );
delete_option( 'wpllmseo_worker_last_manual_run' );
echo "✓ Reset cooldown timestamps\n";

// Initialize token tracker settings
$settings = get_option( 'wpllmseo_settings', array() );
if ( ! isset( $settings['daily_token_limit'] ) ) {
	$settings['daily_token_limit'] = 100000;
	update_option( 'wpllmseo_settings', $settings );
	echo "✓ Added daily token limit setting (100,000 tokens)\n";
}

echo "\n=== Migration Complete ===\n";
echo "Summary:\n";
echo "  - Worker now runs daily at 2:00 AM (was: every minute)\n";
echo "  - 24-hour cooldown enforced for manual runs\n";
echo "  - Daily token limit set to 100,000\n";
echo "  - Batch size optimized to 5 items per run\n";
echo "\nNext Steps:\n";
echo "  1. Check Settings page to adjust daily token limit if needed\n";
echo "  2. Manual worker runs now have 24-hour cooldown\n";
echo "  3. All processes optimized to minimize token usage\n\n";

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::success( 'Cron schedule migration completed successfully!' );
}
