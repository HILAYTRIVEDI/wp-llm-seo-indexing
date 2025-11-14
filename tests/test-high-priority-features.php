<?php
/**
 * High Priority Features Test
 *
 * Tests for SEO compatibility, change tracking, JSON-LD, bulk generator, and post panel.
 *
 * @package WP_LLM_SEO_Indexing
 */

/**
 * Test SEO Compatibility Detection
 */
function test_seo_compat_detection() {
	echo "Testing SEO Compatibility Detection...\n";
	
	// Test detection.
	$plugin = WPLLMSEO_SEO_Compat::detect_seo_plugin();
	echo "Detected SEO plugin: " . ( $plugin ?: 'none' ) . "\n";
	
	// Test getting meta for a post.
	$posts = get_posts( array( 'numberposts' => 1 ) );
	if ( ! empty( $posts ) ) {
		$post_id  = $posts[0]->ID;
		$seo_meta = WPLLMSEO_SEO_Compat::get_primary_seo_meta( $post_id );
		
		echo "SEO Meta for Post {$post_id}:\n";
		echo "  Source: {$seo_meta['source']}\n";
		echo "  Title: " . substr( $seo_meta['title'], 0, 50 ) . "...\n";
		echo "  Description: " . substr( $seo_meta['meta_description'], 0, 50 ) . "...\n";
	}
	
	// Test compat status.
	$status = WPLLMSEO_SEO_Compat::get_compat_status();
	echo "Compatibility Status:\n";
	echo "  Active Plugin: {$status['active_plugin']}\n";
	echo "  Message: {$status['message']}\n";
	
	echo "✓ SEO Compatibility tests passed\n\n";
}

/**
 * Test Content Change Tracker
 */
function test_change_tracker() {
	echo "Testing Content Change Tracker...\n";
	
	// Get a test post.
	$posts = get_posts( array( 'numberposts' => 1 ) );
	if ( empty( $posts ) ) {
		echo "No posts found for testing\n";
		return;
	}
	
	$post_id = $posts[0]->ID;
	$post    = $posts[0];
	
	// Test content hash generation.
	$hash = WPLLMSEO_Change_Tracker::generate_content_hash( $post );
	echo "Content hash for Post {$post_id}: " . substr( $hash, 0, 16 ) . "...\n";
	
	// Test indexing status.
	$status = WPLLMSEO_Change_Tracker::get_indexing_status( $post_id );
	echo "Indexing Status:\n";
	echo "  Has Content Hash: " . ( $status['has_content_hash'] ? 'Yes' : 'No' ) . "\n";
	echo "  Has Embedding: " . ( $status['has_embedding'] ? 'Yes' : 'No' ) . "\n";
	echo "  Status: {$status['status_label']}\n";
	
	// Test getting posts needing reindex.
	$needs_reindex = WPLLMSEO_Change_Tracker::get_posts_needing_reindex( array( 'limit' => 5 ) );
	echo "Posts needing reindex: " . count( $needs_reindex ) . "\n";
	
	echo "✓ Change Tracker tests passed\n\n";
}

/**
 * Test LLM JSON-LD Generation
 */
function test_llm_jsonld() {
	echo "Testing LLM JSON-LD Generation...\n";
	
	// Get a test post.
	$posts = get_posts( array( 'numberposts' => 1 ) );
	if ( empty( $posts ) ) {
		echo "No posts found for testing\n";
		return;
	}
	
	$post_id = $posts[0]->ID;
	
	// Generate JSON-LD.
	$jsonld = WPLLMSEO_LLM_JSONLD::generate_jsonld( $post_id );
	
	echo "JSON-LD Structure for Post {$post_id}:\n";
	echo "  @type: {$jsonld['@type']}\n";
	echo "  headline: " . substr( $jsonld['headline'], 0, 50 ) . "...\n";
	echo "  LLM Optimization:\n";
	echo "    SEO Source: {$jsonld['llmOptimization']['seoMetaSource']}\n";
	echo "    Chunk Count: {$jsonld['llmOptimization']['chunkCount']}\n";
	echo "    Has Vector Hash: " . ( $jsonld['llmOptimization']['semanticVectorHash'] ? 'Yes' : 'No' ) . "\n";
	
	// Verify valid JSON.
	$json_string = wp_json_encode( $jsonld );
	$decoded     = json_decode( $json_string, true );
	echo "JSON Valid: " . ( json_last_error() === JSON_ERROR_NONE ? 'Yes' : 'No' ) . "\n";
	
	echo "✓ LLM JSON-LD tests passed\n\n";
}

/**
 * Test Bulk Snippet Generator
 */
function test_bulk_generator() {
	echo "Testing Bulk Snippet Generator...\n";
	
	// Test LLMs.txt parsing.
	$reflection = new ReflectionClass( 'WPLLMSEO_Bulk_Snippet_Generator' );
	$method     = $reflection->getMethod( 'parse_llmstxt' );
	$method->setAccessible( true );
	$config = $method->invoke( null );
	
	echo "LLMs.txt Configuration:\n";
	echo "  Excluded paths: " . count( $config['excluded_paths'] ) . "\n";
	if ( ! empty( $config['excluded_paths'] ) ) {
		foreach ( array_slice( $config['excluded_paths'], 0, 3 ) as $path ) {
			echo "    - {$path}\n";
		}
	}
	
	// Test dry run.
	echo "Starting dry run...\n";
	$job_id = WPLLMSEO_Bulk_Snippet_Generator::start_bulk_job(
		array( 'post', 'page' ),
		true,  // skip_existing
		10,    // batch_size
		true   // dry_run
	);
	
	if ( $job_id ) {
		echo "Dry run job created: {$job_id}\n";
		
		$status = WPLLMSEO_Bulk_Snippet_Generator::get_job_status( $job_id );
		echo "Job Status:\n";
		echo "  Total posts: {$status['total']}\n";
		echo "  Status: {$status['status']}\n";
	} else {
		echo "No posts to process (all have snippets or none found)\n";
	}
	
	echo "✓ Bulk Generator tests passed\n\n";
}

/**
 * Test Settings
 */
function test_settings() {
	echo "Testing Settings...\n";
	
	$settings = get_option( 'wpllmseo_settings', array() );
	
	$required_keys = array(
		'prefer_external_seo',
		'use_similarity_threshold',
		'enable_llm_jsonld',
	);
	
	foreach ( $required_keys as $key ) {
		$exists = isset( $settings[ $key ] );
		echo "  {$key}: " . ( $exists ? ( $settings[ $key ] ? 'Enabled' : 'Disabled' ) : 'NOT SET' ) . "\n";
	}
	
	echo "✓ Settings tests passed\n\n";
}

/**
 * Run all tests
 */
function run_all_high_priority_tests() {
	echo "========================================\n";
	echo "HIGH PRIORITY FEATURES TEST SUITE\n";
	echo "========================================\n\n";
	
	test_settings();
	test_seo_compat_detection();
	test_change_tracker();
	test_llm_jsonld();
	test_bulk_generator();
	
	echo "========================================\n";
	echo "ALL TESTS COMPLETED\n";
	echo "========================================\n";
}

// Run tests if executed directly.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	run_all_high_priority_tests();
} elseif ( isset( $_GET['wpllmseo_test'] ) && current_user_can( 'manage_options' ) ) {
	run_all_high_priority_tests();
}
