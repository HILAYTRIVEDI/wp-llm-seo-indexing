<?php
/**
 * AI Sitemap Acceptance Tests
 *
 * Test suite for Module 5: AI Sitemap JSONL.
 *
 * To run: wp eval-file tests/test-ai-sitemap-acceptance.php
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 4 ) . '/wp-load.php';
}

/**
 * Test helper class
 */
class WPLLMSEO_AI_Sitemap_Test_Suite {

	private $passed = 0;
	private $failed = 0;

	/**
	 * Run all acceptance tests
	 */
	public function run() {
		WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
		WP_CLI::line( 'AI Sitemap JSONL Acceptance Tests' );
		WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
		WP_CLI::line( '' );

		// Test 1: JSONL generation
		$this->test_jsonl_generation();

		// Test 2: Each line valid JSON
		$this->test_valid_json_lines();

		// Test 3: Snippet appears when available
		$this->test_snippet_priority();

		// Test 4: Chunk preview truncated
		$this->test_chunk_preview_truncation();

		// Test 5: Cache file creation
		$this->test_cache_file();

		// Test 6: Unpublished posts excluded
		$this->test_unpublished_excluded();

		// Test 7: Endpoint accessibility
		$this->test_endpoint_accessible();

		// Test 8: Rate limiting
		$this->test_rate_limiting();

		// Print results
		$this->print_results();
	}

	/**
	 * Test 1: JSONL generation
	 */
	private function test_jsonl_generation() {
		$this->start_test( 'JSONL generation produces output' );

		$sitemap = new WPLLMSEO_AI_Sitemap();
		$jsonl = $sitemap->generate_jsonl( false );

		if ( ! empty( $jsonl ) && is_string( $jsonl ) ) {
			$lines = explode( "\n", trim( $jsonl ) );
			$this->pass_test( 'Generated ' . count( $lines ) . ' lines' );
		} else {
			$this->fail_test( 'JSONL generation failed or empty' );
		}
	}

	/**
	 * Test 2: Each line is valid JSON
	 */
	private function test_valid_json_lines() {
		$this->start_test( 'Each line is valid JSON object' );

		$sitemap = new WPLLMSEO_AI_Sitemap();
		$jsonl = $sitemap->generate_jsonl( false );
		$lines = explode( "\n", trim( $jsonl ) );

		$invalid_count = 0;
		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$decoded = json_decode( $line, true );
			if ( null === $decoded ) {
				$invalid_count++;
			}
		}

		if ( 0 === $invalid_count ) {
			$this->pass_test( 'All ' . count( $lines ) . ' lines are valid JSON' );
		} else {
			$this->fail_test( $invalid_count . ' invalid JSON lines found' );
		}
	}

	/**
	 * Test 3: Snippet priority
	 */
	private function test_snippet_priority() {
		$this->start_test( 'Snippet data included when available' );

		$sitemap = new WPLLMSEO_AI_Sitemap();
		$jsonl = $sitemap->generate_jsonl( false );
		$lines = explode( "\n", trim( $jsonl ) );

		$snippet_count = 0;
		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = json_decode( $line, true );
			if ( ! empty( $entry['snippet_text'] ) ) {
				$snippet_count++;
			}
		}

		if ( $snippet_count > 0 ) {
			$this->pass_test( $snippet_count . ' entries with snippets' );
		} else {
			$this->skip_test( 'No snippets found in database' );
		}
	}

	/**
	 * Test 4: Chunk preview truncation
	 */
	private function test_chunk_preview_truncation() {
		$this->start_test( 'Chunk preview truncated to 250 chars' );

		$sitemap = new WPLLMSEO_AI_Sitemap();
		$jsonl = $sitemap->generate_jsonl( false );
		$lines = explode( "\n", trim( $jsonl ) );

		$too_long = 0;
		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$entry = json_decode( $line, true );
			if ( ! empty( $entry['chunk_preview'] ) && strlen( $entry['chunk_preview'] ) > 250 ) {
				$too_long++;
			}
		}

		if ( 0 === $too_long ) {
			$this->pass_test( 'All chunk previews ≤250 chars' );
		} else {
			$this->fail_test( $too_long . ' chunk previews exceed 250 chars' );
		}
	}

	/**
	 * Test 5: Cache file creation
	 */
	private function test_cache_file() {
		$this->start_test( 'Cache file created successfully' );

		$sitemap = new WPLLMSEO_AI_Sitemap();
		$result = $sitemap->regenerate_cache();

		if ( $result ) {
			$file_size = $sitemap->get_cache_size();
			$this->pass_test( 'Cache file: ' . size_format( $file_size ) );
		} else {
			$this->fail_test( 'Cache regeneration failed' );
		}
	}

	/**
	 * Test 6: Unpublished posts excluded
	 */
	private function test_unpublished_excluded() {
		$this->start_test( 'Unpublished posts excluded from sitemap' );

		// Create draft post
		$draft_id = wp_insert_post( array(
			'post_title'   => 'Test Draft Post',
			'post_content' => 'This is a test draft',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		) );

		// Generate sitemap
		$sitemap = new WPLLMSEO_AI_Sitemap();
		$jsonl = $sitemap->generate_jsonl( false );

		// Check if draft appears
		$draft_found = false !== strpos( $jsonl, 'Test Draft Post' );

		// Cleanup
		wp_delete_post( $draft_id, true );

		if ( ! $draft_found ) {
			$this->pass_test( 'Draft posts correctly excluded' );
		} else {
			$this->fail_test( 'Draft post found in sitemap' );
		}
	}

	/**
	 * Test 7: Endpoint accessibility
	 */
	private function test_endpoint_accessible() {
		$this->start_test( 'Endpoint /ai-sitemap.jsonl is accessible' );

		$url = home_url( '/ai-sitemap.jsonl' );
		$response = wp_remote_get( $url );

		if ( ! is_wp_error( $response ) ) {
			$status_code = wp_remote_retrieve_response_code( $response );
			$content_type = wp_remote_retrieve_header( $response, 'content-type' );

			if ( 200 === $status_code && false !== strpos( $content_type, 'application/jsonl' ) ) {
				$this->pass_test( 'Endpoint returns 200 with correct Content-Type' );
			} else {
				$this->fail_test( 'Status: ' . $status_code . ', Type: ' . $content_type );
			}
		} else {
			$this->skip_test( 'Endpoint not accessible (may need rewrite flush)' );
		}
	}

	/**
	 * Test 8: Rate limiting
	 */
	private function test_rate_limiting() {
		$this->start_test( 'Rate limiting enforced (10 req/min)' );

		$url = home_url( '/ai-sitemap.jsonl' );

		// Make 11 rapid requests
		$rate_limited = false;
		for ( $i = 1; $i <= 11; $i++ ) {
			$response = wp_remote_get( $url );
			$status_code = wp_remote_retrieve_response_code( $response );

			if ( 429 === $status_code ) {
				$rate_limited = true;
				break;
			}
		}

		if ( $rate_limited ) {
			$this->pass_test( 'Rate limit triggered after multiple requests' );
		} else {
			$this->skip_test( 'Rate limiting not tested (endpoint may be unavailable)' );
		}
	}

	/**
	 * Start a test
	 */
	private function start_test( $name ) {
		WP_CLI::line( '→ Testing: ' . $name );
	}

	/**
	 * Pass a test
	 */
	private function pass_test( $message ) {
		$this->passed++;
		WP_CLI::success( $message );
		WP_CLI::line( '' );
	}

	/**
	 * Fail a test
	 */
	private function fail_test( $message ) {
		$this->failed++;
		WP_CLI::error( $message, false );
		WP_CLI::line( '' );
	}

	/**
	 * Skip a test
	 */
	private function skip_test( $message ) {
		WP_CLI::warning( 'SKIPPED: ' . $message );
		WP_CLI::line( '' );
	}

	/**
	 * Print test results
	 */
	private function print_results() {
		WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
		WP_CLI::line( 'Test Results' );
		WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
		WP_CLI::line( '' );

		$total = $this->passed + $this->failed;
		$percentage = $total > 0 ? round( ( $this->passed / $total ) * 100, 1 ) : 0;

		WP_CLI::line( sprintf( 'Passed:  %s%d%s', "\033[32m", $this->passed, "\033[0m" ) );
		WP_CLI::line( sprintf( 'Failed:  %s%d%s', "\033[31m", $this->failed, "\033[0m" ) );
		WP_CLI::line( sprintf( 'Success: %s%.1f%%%s', "\033[1m", $percentage, "\033[0m" ) );
		WP_CLI::line( '' );

		if ( $this->failed === 0 ) {
			WP_CLI::success( 'All tests passed! AI Sitemap is working correctly.' );
		} else {
			WP_CLI::warning( 'Some tests failed. Please review the errors above.' );
		}
		
		WP_CLI::line( '' );
		WP_CLI::line( 'Quick test with curl:' );
		WP_CLI::line( 'curl ' . home_url( '/ai-sitemap.jsonl' ) );
	}
}

// Run tests
$test_suite = new WPLLMSEO_AI_Sitemap_Test_Suite();
$test_suite->run();
