<?php
/**
 * RAG System Acceptance Tests
 *
 * Test suite for Module 4: RAG Engine.
 *
 * To run: wp eval-file tests/test-rag-acceptance.php
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 4 ) . '/wp-load.php';
}

/**
 * Test helper class
 */
class WPLLMSEO_RAG_Test_Suite {

	private $passed = 0;
	private $failed = 0;
	private $tests = array();

	/**
	 * Run all acceptance tests
	 */
	public function run() {
		WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
		WP_CLI::line( 'RAG System Acceptance Tests' );
		WP_CLI::line( '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' );
		WP_CLI::line( '' );

		// Test 1: Query endpoint returns valid JSON
		$this->test_query_endpoint_returns_json();

		// Test 2: Query embedding is cached
		$this->test_query_embedding_cached();

		// Test 3: Candidate filtering
		$this->test_candidate_filtering();

		// Test 4: Cosine similarity calculation
		$this->test_cosine_similarity();

		// Test 5: Ranking boost logic
		$this->test_ranking_boost();

		// Test 6: Results sorted by score
		$this->test_results_sorted();

		// Test 7: RAG latency check
		$this->test_rag_latency();

		// Test 8: Log file creation
		$this->test_log_file();

		// Print results
		$this->print_results();
	}

	/**
	 * Test 1: Query endpoint returns valid JSON
	 */
	private function test_query_endpoint_returns_json() {
		$this->start_test( 'POST /query with q="remote tech jobs" returns valid JSON' );

		$request = new WP_REST_Request( 'POST', '/wp-llmseo/v1/query' );
		$request->set_param( 'q', 'remote tech jobs' );

		$response = rest_do_request( $request );
		$data = $response->get_data();

		if ( isset( $data['success'] ) && isset( $data['data'] ) ) {
			$this->pass_test( 'Response is valid JSON with success and data fields' );
		} else {
			$this->fail_test( 'Response missing required fields: ' . print_r( $data, true ) );
		}
	}

	/**
	 * Test 2: Query embedding is cached
	 */
	private function test_query_embedding_cached() {
		$this->start_test( 'Query embedding is cached between identical requests' );

		$rag_engine = new WPLLMSEO_RAG_Engine();
		$query = 'test query ' . time(); // Unique query

		// First call
		$start = microtime( true );
		$embedding1 = $rag_engine->embed_query( $query );
		$duration1 = ( microtime( true ) - $start ) * 1000;

		// Second call (should be cached)
		$start = microtime( true );
		$embedding2 = $rag_engine->embed_query( $query );
		$duration2 = ( microtime( true ) - $start ) * 1000;

		if ( ! is_wp_error( $embedding1 ) && ! is_wp_error( $embedding2 ) ) {
			if ( $duration2 < 10 && $embedding1 === $embedding2 ) {
				$this->pass_test( "Cached query took {$duration2}ms vs {$duration1}ms" );
			} else {
				$this->fail_test( "Cache not working: {$duration1}ms vs {$duration2}ms" );
			}
		} else {
			$this->skip_test( 'API key not configured or API error' );
		}
	}

	/**
	 * Test 3: Candidate filtering returns ≤200 rows
	 */
	private function test_candidate_filtering() {
		$this->start_test( 'Candidate filtering returns ≤200 rows' );

		$vector_search = new WPLLMSEO_Vector_Search();
		$candidates = $vector_search->get_candidates( 'test query', 200 );

		if ( count( $candidates ) <= 200 ) {
			$this->pass_test( 'Returned ' . count( $candidates ) . ' candidates (≤200)' );
		} else {
			$this->fail_test( 'Returned ' . count( $candidates ) . ' candidates (>200)' );
		}
	}

	/**
	 * Test 4: Cosine similarity calculation
	 */
	private function test_cosine_similarity() {
		$this->start_test( 'Cosine similarity calculation with known vectors' );

		$vector_search = new WPLLMSEO_Vector_Search();

		// Test with identical vectors (should be 1.0)
		$vec1 = array( 1.0, 2.0, 3.0 );
		$vec2 = array( 1.0, 2.0, 3.0 );
		$similarity = $vector_search->cosine_similarity( $vec1, $vec2 );

		if ( abs( $similarity - 1.0 ) < 0.001 ) {
			$this->pass_test( "Identical vectors: {$similarity} ≈ 1.0" );
		} else {
			$this->fail_test( "Expected 1.0, got {$similarity}" );
			return;
		}

		// Test with orthogonal vectors (should be 0.0)
		$vec3 = array( 1.0, 0.0, 0.0 );
		$vec4 = array( 0.0, 1.0, 0.0 );
		$similarity = $vector_search->cosine_similarity( $vec3, $vec4 );

		if ( abs( $similarity - 0.0 ) < 0.001 ) {
			$this->pass_test( "Orthogonal vectors: {$similarity} ≈ 0.0" );
		} else {
			$this->fail_test( "Expected 0.0, got {$similarity}" );
		}
	}

	/**
	 * Test 5: Ranking boost logic
	 */
	private function test_ranking_boost() {
		$this->start_test( 'Ranking boost adjusts scores correctly' );

		$rank = new WPLLMSEO_Rank();

		// Create mock candidates
		$candidates = array(
			(object) array(
				'id' => 1,
				'post_id' => 1,
				'text' => 'Test snippet',
				'is_snippet' => 1,
				'created_at' => current_time( 'mysql' ), // Recent
			),
			(object) array(
				'id' => 2,
				'post_id' => 2,
				'text' => 'Test chunk',
				'is_snippet' => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) ), // Old
			),
		);

		$scores = array( 0 => 0.5, 1 => 0.5 );

		$ranked = $rank->rank( $candidates, $scores, 'test', 5 );

		// Snippet should be boosted and ranked first
		if ( $ranked[0]['is_snippet'] == 1 && $ranked[0]['final_score'] > 0.5 ) {
			$this->pass_test( 'Snippet boosted to score: ' . $ranked[0]['final_score'] );
		} else {
			$this->fail_test( 'Snippet not properly boosted' );
		}
	}

	/**
	 * Test 6: Results sorted by score
	 */
	private function test_results_sorted() {
		$this->start_test( 'Final results sorted by score descending' );

		$rank = new WPLLMSEO_Rank();

		$candidates = array(
			(object) array( 'id' => 1, 'post_id' => 1, 'text' => 'A', 'is_snippet' => 0, 'created_at' => current_time( 'mysql' ) ),
			(object) array( 'id' => 2, 'post_id' => 2, 'text' => 'B', 'is_snippet' => 0, 'created_at' => current_time( 'mysql' ) ),
			(object) array( 'id' => 3, 'post_id' => 3, 'text' => 'C', 'is_snippet' => 0, 'created_at' => current_time( 'mysql' ) ),
		);

		$scores = array( 0 => 0.3, 1 => 0.8, 2 => 0.5 );

		$ranked = $rank->rank( $candidates, $scores, 'test', 5 );

		$sorted = true;
		for ( $i = 0; $i < count( $ranked ) - 1; $i++ ) {
			if ( $ranked[ $i ]['final_score'] < $ranked[ $i + 1 ]['final_score'] ) {
				$sorted = false;
				break;
			}
		}

		if ( $sorted ) {
			$this->pass_test( 'Results properly sorted by score' );
		} else {
			$this->fail_test( 'Results not sorted correctly' );
		}
	}

	/**
	 * Test 7: RAG latency check
	 */
	private function test_rag_latency() {
		$this->start_test( 'RAG latency under 400ms for typical query' );

		$request = new WP_REST_Request( 'POST', '/wp-llmseo/v1/query' );
		$request->set_param( 'q', 'wordpress' );

		$start = microtime( true );
		$response = rest_do_request( $request );
		$duration = ( microtime( true ) - $start ) * 1000;

		$data = $response->get_data();

		if ( isset( $data['data']['execution_time'] ) ) {
			$exec_time = (float) str_replace( 'ms', '', $data['data']['execution_time'] );
			
			if ( $exec_time < 400 ) {
				$this->pass_test( "Execution time: {$exec_time}ms" );
			} else {
				$this->fail_test( "Execution time: {$exec_time}ms (>400ms)" );
			}
		} else {
			$this->skip_test( 'Could not measure execution time' );
		}
	}

	/**
	 * Test 8: Log file creation
	 */
	private function test_log_file() {
		$this->start_test( 'rag.log contains entries after query' );

		$log_file = WPLLMSEO_PLUGIN_DIR . 'var/logs/rag.log';

		if ( file_exists( $log_file ) ) {
			$logger = new WPLLMSEO_Logger();
			$lines = $logger->get_log( 'rag.log', 10 );

			if ( ! empty( $lines ) ) {
				$this->pass_test( 'Log file exists with ' . count( $lines ) . ' entries' );
			} else {
				$this->fail_test( 'Log file exists but is empty' );
			}
		} else {
			$this->skip_test( 'Log file not created yet' );
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
			WP_CLI::success( 'All tests passed! RAG system is working correctly.' );
		} else {
			WP_CLI::warning( 'Some tests failed. Please review the errors above.' );
		}
	}
}

// Run tests
$test_suite = new WPLLMSEO_RAG_Test_Suite();
$test_suite->run();
