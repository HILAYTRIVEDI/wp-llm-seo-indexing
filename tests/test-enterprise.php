<?php
/**
 * Unit Tests for Enterprise Features
 *
 * Tests for cosine similarity, job claiming, retries, and embedding validation.
 *
 * Run with: vendor/bin/phpunit tests/test-enterprise.php
 *
 * @package WPLLMSEO
 */

class WPLLMSEOEnterpriseTest extends WP_UnitTestCase {

	/**
	 * Test cosine similarity calculation
	 */
	public function test_cosine_similarity() {
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/embedding.php';
		
		// Test identical vectors
		$vec1 = array( 1.0, 0.0, 0.0 );
		$vec2 = array( 1.0, 0.0, 0.0 );
		$similarity = wpllmseo_cosine_similarity( $vec1, $vec2 );
		$this->assertEquals( 1.0, $similarity, 'Identical vectors should have similarity of 1.0' );
		
		// Test orthogonal vectors
		$vec1 = array( 1.0, 0.0, 0.0 );
		$vec2 = array( 0.0, 1.0, 0.0 );
		$similarity = wpllmseo_cosine_similarity( $vec1, $vec2 );
		$this->assertEquals( 0.0, $similarity, 'Orthogonal vectors should have similarity of 0.0' );
		
		// Test opposite vectors
		$vec1 = array( 1.0, 0.0, 0.0 );
		$vec2 = array( -1.0, 0.0, 0.0 );
		$similarity = wpllmseo_cosine_similarity( $vec1, $vec2 );
		$this->assertEquals( -1.0, $similarity, 'Opposite vectors should have similarity of -1.0' );
		
		// Test dimension mismatch
		$vec1 = array( 1.0, 0.0 );
		$vec2 = array( 1.0, 0.0, 0.0 );
		$similarity = wpllmseo_cosine_similarity( $vec1, $vec2 );
		$this->assertNull( $similarity, 'Dimension mismatch should return null' );
	}

	/**
	 * Test embedding dimension validation
	 */
	public function test_embedding_validation() {
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/embedding.php';
		
		$row = array(
			'id'            => 1,
			'embedding_dim' => 3,
		);
		
		// Valid dimension
		$vector = array( 1.0, 2.0, 3.0 );
		$this->assertTrue( wpllmseo_validate_embedding_dim( $row, $vector ) );
		
		// Invalid dimension
		$vector = array( 1.0, 2.0 );
		$this->assertFalse( wpllmseo_validate_embedding_dim( $row, $vector ) );
		
		// No stored dimension (should pass)
		$row = array( 'id' => 1 );
		$vector = array( 1.0, 2.0 );
		$this->assertTrue( wpllmseo_validate_embedding_dim( $row, $vector ) );
	}

	/**
	 * Test embedding storage and retrieval
	 */
	public function test_embedding_storage() {
		global $wpdb;
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/embedding.php';
		
		$table = $wpdb->prefix . 'wpllmseo_chunks';
		
		// Create test chunk
		$wpdb->insert(
			$table,
			array(
				'post_id'    => 1,
				'chunk_text' => 'Test chunk',
				'chunk_hash' => hash( 'sha256', 'test' ),
			)
		);
		$chunk_id = $wpdb->insert_id;
		
		// Store embedding
		$vector = array( 0.1, 0.2, 0.3, 0.4, 0.5 );
		$result = wpllmseo_store_embedding( $table, $chunk_id, $vector );
		$this->assertTrue( $result );
		
		// Retrieve and validate
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $chunk_id ), ARRAY_A );
		
		$this->assertEquals( 5, $row['embedding_dim'] );
		$this->assertEquals( 'json_float_array_v1', $row['embedding_format'] );
		$this->assertNotEmpty( $row['embedding_checksum'] );
		
		$retrieved = wpllmseo_get_embedding( $row );
		$this->assertEquals( $vector, $retrieved );
		
		// Cleanup
		$wpdb->delete( $table, array( 'id' => $chunk_id ) );
	}

	/**
	 * Test atomic job claiming
	 */
	public function test_atomic_job_claim() {
		global $wpdb;
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/enterprise-worker.php';
		
		$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
		
		// Add test job
		$wpdb->insert(
			$jobs_table,
			array(
				'job_type' => 'test_job',
				'payload'  => '{}',
				'status'   => 'queued',
				'locked'   => 0,
			)
		);
		$job_id = $wpdb->insert_id;
		
		// Claim job
		$worker_id = 'test-worker:123';
		$job = wpllmseo_claim_job_atomic( $worker_id );
		
		$this->assertNotNull( $job );
		$this->assertEquals( $job_id, $job->id );
		$this->assertEquals( 'running', $job->status );
		$this->assertEquals( 1, $job->locked );
		$this->assertEquals( $worker_id, $job->runner );
		
		// Try to claim again (should fail)
		$job2 = wpllmseo_claim_job_atomic( 'another-worker:456' );
		$this->assertNull( $job2, 'Job should not be claimed twice' );
		
		// Cleanup
		$wpdb->delete( $jobs_table, array( 'id' => $job_id ) );
	}

	/**
	 * Test job retry logic
	 */
	public function test_job_retry_logic() {
		global $wpdb;
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/enterprise-worker.php';
		
		$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
		
		// Add test job
		$wpdb->insert(
			$jobs_table,
			array(
				'job_type'     => 'test_job',
				'payload'      => '{}',
				'status'       => 'running',
				'attempts'     => 2,
				'max_attempts' => 5,
				'locked'       => 1,
			)
		);
		$job_id = $wpdb->insert_id;
		
		// Handle failure (should retry)
		wpllmseo_handle_job_failure( $job_id, 'Test error' );
		
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $jobs_table WHERE id = %d", $job_id ) );
		
		$this->assertEquals( 3, $job->attempts );
		$this->assertEquals( 'queued', $job->status );
		$this->assertEquals( 0, $job->locked );
		$this->assertEquals( 'Test error', $job->last_error );
		$this->assertNotNull( $job->run_after );
		
		// Cleanup
		$wpdb->delete( $jobs_table, array( 'id' => $job_id ) );
	}

	/**
	 * Test dead-letter queue
	 */
	public function test_dead_letter_queue() {
		global $wpdb;
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/enterprise-worker.php';
		
		$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
		$dead_table = $wpdb->prefix . 'wpllmseo_jobs_dead_letter';
		
		// Add test job at max attempts
		$wpdb->insert(
			$jobs_table,
			array(
				'job_type'     => 'test_job',
				'payload'      => '{"test":"data"}',
				'status'       => 'running',
				'attempts'     => 4,
				'max_attempts' => 5,
				'locked'       => 1,
			)
		);
		$job_id = $wpdb->insert_id;
		
		// Handle failure (should move to dead-letter)
		wpllmseo_handle_job_failure( $job_id, 'Final error' );
		
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $jobs_table WHERE id = %d", $job_id ) );
		$this->assertEquals( 'failed', $job->status );
		
		$dead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $dead_table WHERE original_job_id = %d", $job_id ) );
		$this->assertNotNull( $dead );
		$this->assertEquals( '{"test":"data"}', $dead->payload );
		$this->assertEquals( 'Final error', $dead->reason );
		
		// Cleanup
		$wpdb->delete( $jobs_table, array( 'id' => $job_id ) );
		$wpdb->delete( $dead_table, array( 'original_job_id' => $job_id ) );
	}

	/**
	 * Test token extraction
	 */
	public function test_token_extraction() {
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/tokenizer.php';
		
		$text = 'The quick brown fox jumps over the lazy dog. The fox is very quick and clever.';
		$tokens = wpllmseo_extract_tokens( $text, 5 );
		
		$this->assertCount( 5, $tokens );
		$this->assertContains( 'fox', $tokens );
		$this->assertContains( 'quick', $tokens );
		
		// Should filter out stop words
		$this->assertNotContains( 'the', $tokens );
		$this->assertNotContains( 'and', $tokens );
		$this->assertNotContains( 'is', $tokens );
	}

	/**
	 * Test stale job unlock
	 */
	public function test_stale_job_unlock() {
		global $wpdb;
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/enterprise-worker.php';
		
		$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
		
		// Add stale job (locked 2 hours ago)
		$stale_time = gmdate( 'Y-m-d H:i:s', time() - 7200 );
		$wpdb->insert(
			$jobs_table,
			array(
				'job_type'  => 'test_job',
				'payload'   => '{}',
				'status'    => 'running',
				'locked'    => 1,
				'locked_at' => $stale_time,
			)
		);
		$job_id = $wpdb->insert_id;
		
		// Unlock stale jobs
		$count = wpllmseo_unlock_stale_jobs( 1800 ); // 30 min threshold
		
		$this->assertEquals( 1, $count );
		
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $jobs_table WHERE id = %d", $job_id ) );
		$this->assertEquals( 0, $job->locked );
		$this->assertEquals( 'queued', $job->status );
		
		// Cleanup
		$wpdb->delete( $jobs_table, array( 'id' => $job_id ) );
	}
}
