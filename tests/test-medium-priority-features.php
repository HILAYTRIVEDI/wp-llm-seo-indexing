<?php
/**
 * Test Medium Priority Features
 *
 * Run this file to test all medium priority features.
 * Access via: yoursite.com/wp-content/plugins/wp-llm-seo-indexing/tests/test-medium-priority-features.php
 * Or via WP-CLI: wp eval-file test-medium-priority-features.php
 *
 * @package WP_LLM_SEO_Indexing
 */

// Load WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	require_once __DIR__ . '/../../../../wp-load.php';
}

// Output header.
header( 'Content-Type: text/html; charset=utf-8' );
echo '<html><head><title>Medium Priority Features Test</title></head><body>';
echo '<h1>WP LLM SEO - Medium Priority Features Test</h1>';
echo '<p>Testing LLM Sitemap Hub, LLM Content API, and Semantic Internal Linking...</p>';
echo '<hr>';

/**
 * Test 1: LLM Sitemap Hub
 */
function test_sitemap_hub() {
	echo '<h2>Test 1: LLM Sitemap Hub</h2>';

	// Check class exists.
	if ( ! class_exists( 'WPLLMSEO_Sitemap_Hub' ) ) {
		echo '<p style="color:red;">❌ WPLLMSEO_Sitemap_Hub class not found!</p>';
		return;
	}

	echo '<p style="color:green;">✓ WPLLMSEO_Sitemap_Hub class loaded</p>';

	// Test rewrite rules registered.
	global $wp_rewrite;
	$rules = get_option( 'rewrite_rules' );
	
	$expected_endpoints = array(
		'llm-sitemap.jsonl',
		'llm-snippets.jsonl',
		'llm-semantic-map.json',
		'llm-context-graph.jsonl',
	);

	foreach ( $expected_endpoints as $endpoint ) {
		if ( isset( $rules[ $endpoint . '/?$' ] ) ) {
			echo '<p style="color:green;">✓ Endpoint registered: ' . esc_html( $endpoint ) . '</p>';
			echo '<p style="margin-left:20px;"><code>' . esc_html( home_url( '/' . $endpoint ) ) . '</code></p>';
		} else {
			echo '<p style="color:orange;">⚠ Endpoint not found in rewrite rules: ' . esc_html( $endpoint ) . '</p>';
			echo '<p style="margin-left:20px;">Try visiting <a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">Permalinks</a> to flush rewrite rules.</p>';
		}
	}

	// Test access verification.
	$settings = get_option( 'wpllmseo_settings', array() );
	if ( isset( $settings['sitemap_hub_token'] ) && ! empty( $settings['sitemap_hub_token'] ) ) {
		echo '<p style="color:green;">✓ Sitemap hub token generated: <code>' . esc_html( substr( $settings['sitemap_hub_token'], 0, 10 ) ) . '...</code></p>';
	}

	if ( ! empty( $settings['sitemap_hub_public'] ) ) {
		echo '<p style="color:blue;">ℹ Public access enabled for sitemap hub</p>';
	} else {
		echo '<p style="color:blue;">ℹ Token authentication required for sitemap hub</p>';
	}
}

/**
 * Test 2: LLM Content API
 */
function test_llm_api() {
	echo '<h2>Test 2: LLM Content API</h2>';

	// Check class exists.
	if ( ! class_exists( 'WPLLMSEO_LLM_API' ) ) {
		echo '<p style="color:red;">❌ WPLLMSEO_LLM_API class not found!</p>';
		return;
	}

	echo '<p style="color:green;">✓ WPLLMSEO_LLM_API class loaded</p>';

	// Check REST routes.
	$routes = rest_get_server()->get_routes();
	
	$expected_routes = array(
		'/llm/v1/post/(?P<id>\d+)',
		'/llm/v1/search',
		'/llm/v1/batch',
	);

	foreach ( $expected_routes as $route ) {
		$found = false;
		foreach ( $routes as $registered_route => $handlers ) {
			if ( strpos( $registered_route, $route ) !== false ) {
				$found = true;
				break;
			}
		}

		if ( $found ) {
			echo '<p style="color:green;">✓ REST route registered: ' . esc_html( $route ) . '</p>';
		} else {
			echo '<p style="color:red;">❌ REST route not found: ' . esc_html( $route ) . '</p>';
		}
	}

	// Test API settings.
	$settings = get_option( 'wpllmseo_settings', array() );
	
	if ( isset( $settings['llm_api_token'] ) && ! empty( $settings['llm_api_token'] ) ) {
		echo '<p style="color:green;">✓ API token generated: <code>' . esc_html( substr( $settings['llm_api_token'], 0, 10 ) ) . '...</code></p>';
	}

	if ( ! empty( $settings['llm_api_public'] ) ) {
		echo '<p style="color:blue;">ℹ Public API access enabled</p>';
	} else {
		echo '<p style="color:blue;">ℹ Token authentication required</p>';
	}

	if ( ! empty( $settings['llm_api_rate_limit'] ) ) {
		$limit = $settings['llm_api_rate_limit_value'] ?? 100;
		echo '<p style="color:green;">✓ Rate limiting enabled: ' . esc_html( $limit ) . ' requests/hour</p>';
	} else {
		echo '<p style="color:blue;">ℹ Rate limiting disabled</p>';
	}

	// Test with a real post.
	$posts = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'publish' ) );
	if ( ! empty( $posts ) ) {
		$post_id = $posts[0]->ID;
		echo '<p style="color:green;">✓ Sample API endpoint: <code>' . esc_html( rest_url( 'llm/v1/post/' . $post_id ) ) . '</code></p>';
		
		// Try to get post data.
		$data = WPLLMSEO_LLM_API::build_post_response( $post_id, array( 'raw_text', 'metadata' ) );
		if ( ! empty( $data ) ) {
			echo '<p style="color:green;">✓ API can generate post response</p>';
			echo '<pre style="background:#f5f5f5;padding:10px;border-left:3px solid #3f51b5;">';
			echo esc_html( json_encode( $data, JSON_PRETTY_PRINT ) );
			echo '</pre>';
		}
	} else {
		echo '<p style="color:orange;">⚠ No published posts found to test API</p>';
	}
}

/**
 * Test 3: Semantic Internal Linking
 */
function test_semantic_linking() {
	echo '<h2>Test 3: Semantic Internal Linking</h2>';

	// Check class exists.
	if ( ! class_exists( 'WPLLMSEO_Semantic_Linking' ) ) {
		echo '<p style="color:red;">❌ WPLLMSEO_Semantic_Linking class not found!</p>';
		return;
	}

	echo '<p style="color:green;">✓ WPLLMSEO_Semantic_Linking class loaded</p>';

	// Check settings.
	$settings = get_option( 'wpllmseo_settings', array() );

	if ( ! empty( $settings['enable_semantic_linking'] ) ) {
		echo '<p style="color:green;">✓ Semantic linking enabled</p>';
	} else {
		echo '<p style="color:orange;">⚠ Semantic linking disabled in settings</p>';
	}

	$threshold = $settings['semantic_linking_threshold'] ?? 0.75;
	echo '<p style="color:blue;">ℹ Similarity threshold: ' . esc_html( $threshold * 100 ) . '%</p>';

	$max_suggestions = $settings['semantic_linking_max_suggestions'] ?? 5;
	echo '<p style="color:blue;">ℹ Max suggestions: ' . esc_html( $max_suggestions ) . '</p>';

	// Test with a post that has embeddings.
	global $wpdb;
	$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

	$post_with_embedding = $wpdb->get_var(
		"SELECT DISTINCT post_id FROM {$chunks_table} 
		WHERE embedding IS NOT NULL 
		LIMIT 1"
	);

	if ( $post_with_embedding ) {
		echo '<p style="color:green;">✓ Found post with embeddings: ID ' . esc_html( $post_with_embedding ) . '</p>';

		// Try to get suggestions.
		$suggestions = WPLLMSEO_Semantic_Linking::get_suggestions( $post_with_embedding );

		if ( ! empty( $suggestions ) ) {
			echo '<p style="color:green;">✓ Generated ' . count( $suggestions ) . ' link suggestion(s)</p>';
			echo '<div style="background:#f5f5f5;padding:10px;border-left:3px solid #3f51b5;">';
			foreach ( $suggestions as $suggestion ) {
				$score = round( $suggestion['similarity'] * 100 );
				echo '<p><strong>' . esc_html( $suggestion['title'] ) . '</strong> (' . esc_html( $score ) . '% match)</p>';
			}
			echo '</div>';
		} else {
			echo '<p style="color:orange;">⚠ No similar posts found (threshold may be too high)</p>';
		}

		// Check meta box.
		echo '<p style="color:blue;">ℹ Meta box "Semantic Internal Links" should appear in post editor sidebar</p>';
	} else {
		echo '<p style="color:orange;">⚠ No posts with embeddings found. Index some posts first.</p>';
	}

	// Check AJAX handlers registered.
	if ( has_action( 'wp_ajax_wpllmseo_get_link_suggestions' ) ) {
		echo '<p style="color:green;">✓ AJAX handler registered: wpllmseo_get_link_suggestions</p>';
	}

	if ( has_action( 'wp_ajax_wpllmseo_insert_links' ) ) {
		echo '<p style="color:green;">✓ AJAX handler registered: wpllmseo_insert_links</p>';
	}

	if ( has_action( 'wp_ajax_wpllmseo_dismiss_suggestion' ) ) {
		echo '<p style="color:green;">✓ AJAX handler registered: wpllmseo_dismiss_suggestion</p>';
	}
}

/**
 * Test 4: Settings Integration
 */
function test_settings() {
	echo '<h2>Test 4: Settings Integration</h2>';

	$settings = get_option( 'wpllmseo_settings', array() );

	// Check all medium priority settings exist.
	$required_settings = array(
		'sitemap_hub_public',
		'sitemap_hub_token',
		'llm_api_public',
		'llm_api_token',
		'llm_api_rate_limit',
		'llm_api_rate_limit_value',
		'enable_semantic_linking',
		'semantic_linking_threshold',
		'semantic_linking_max_suggestions',
	);

	$all_present = true;
	foreach ( $required_settings as $key ) {
		if ( isset( $settings[ $key ] ) ) {
			echo '<p style="color:green;">✓ Setting exists: ' . esc_html( $key ) . '</p>';
		} else {
			echo '<p style="color:red;">❌ Missing setting: ' . esc_html( $key ) . '</p>';
			$all_present = false;
		}
	}

	if ( $all_present ) {
		echo '<p style="color:green;font-weight:bold;">✓ All medium priority settings initialized</p>';
	}

	echo '<p style="color:blue;">ℹ Settings page: <a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_settings' ) ) . '">' . esc_url( admin_url( 'admin.php?page=wpllmseo_settings' ) ) . '</a></p>';
}

// Run all tests.
test_sitemap_hub();
echo '<hr>';
test_llm_api();
echo '<hr>';
test_semantic_linking();
echo '<hr>';
test_settings();

echo '<hr>';
echo '<h2>Summary</h2>';
echo '<p>All medium priority features have been tested. Check above for any errors or warnings.</p>';
echo '<p><strong>Next Steps:</strong></p>';
echo '<ul>';
echo '<li>Enable features in <a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_settings' ) ) . '">Settings</a></li>';
echo '<li>Flush rewrite rules by visiting <a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">Settings > Permalinks</a></li>';
echo '<li>Test sitemap endpoints with your access token</li>';
echo '<li>Try the Semantic Links meta box in post editor</li>';
echo '<li>Test API endpoints with curl or Postman</li>';
echo '</ul>';

echo '</body></html>';
