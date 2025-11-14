<?php
/**
 * Tests for MCP Integration
 *
 * Run with: vendor/bin/phpunit tests/test-mcp-integration.php
 *
 * @package WPLLMSEO
 */

/**
 * Test MCP Integration functionality
 */
class WPLLMSEO_MCP_Integration_Test extends WP_UnitTestCase {

	/**
	 * Test user ID
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Test token
	 *
	 * @var string
	 */
	private $test_token;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable MCP.
		update_option(
			'wpllmseo_settings',
			array(
				'wpllmseo_enable_mcp'           => true,
				'wpllmseo_mcp_respect_llms_txt' => true,
			)
		);

		// Create test user with admin capabilities.
		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		// Generate test token.
		$this->test_token = WPLLMSEO_MCP_Auth::generate_token(
			$this->user_id,
			'Test Token',
			array( 'read', 'write', 'bulk_operations' ),
			30
		);

		// Create tables.
		WPLLMSEO_MCP_Audit::create_table();
		WPLLMSEO_MCP_Auth::create_table();
	}

	/**
	 * Clean up after tests
	 */
	public function tearDown(): void {
		// Revoke test token.
		if ( $this->test_token ) {
			WPLLMSEO_MCP_Auth::revoke_token( $this->test_token );
		}

		// Delete test user.
		if ( $this->user_id ) {
			wp_delete_user( $this->user_id );
		}

		parent::tearDown();
	}

	/**
	 * Test MCP adapter detection
	 */
	public function test_adapter_detection() {
		$status = WPLLMSEO_MCP_Adapter::get_status();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'enabled', $status );
		$this->assertArrayHasKey( 'adapter_available', $status );
		$this->assertArrayHasKey( 'registered_abilities', $status );
	}

	/**
	 * Test ability registration
	 */
	public function test_ability_registration() {
		$abilities = WPLLMSEO_MCP_Adapter::get_ability_definitions();

		$this->assertIsArray( $abilities );
		$this->assertCount( 5, $abilities );

		$expected_abilities = array(
			'llmseo.fetch_post_summary',
			'llmseo.generate_snippet_preview',
			'llmseo.start_bulk_snippet_job',
			'llmseo.save_snippet',
			'llmseo.query_job_status',
		);

		foreach ( $abilities as $ability ) {
			$this->assertContains( $ability['name'], $expected_abilities );
			$this->assertArrayHasKey( 'description', $ability );
			$this->assertArrayHasKey( 'input_schema', $ability );
			$this->assertArrayHasKey( 'output_schema', $ability );
		}
	}

	/**
	 * Test token generation
	 */
	public function test_token_generation() {
		$this->assertNotEmpty( $this->test_token );
		$this->assertEquals( 64, strlen( $this->test_token ) );
	}

	/**
	 * Test token authentication
	 */
	public function test_token_authentication() {
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$this->assertNotFalse( $context );
		$this->assertIsArray( $context );
		$this->assertEquals( $this->user_id, $context['user_id'] );
		$this->assertArrayHasKey( 'capabilities', $context );
		$this->assertContains( 'read', $context['capabilities'] );
		$this->assertContains( 'write', $context['capabilities'] );
		$this->assertContains( 'bulk_operations', $context['capabilities'] );
	}

	/**
	 * Test invalid token authentication
	 */
	public function test_invalid_token_authentication() {
		$context = WPLLMSEO_MCP_Auth::authenticate_token( 'invalid_token_1234567890' );

		$this->assertFalse( $context );
	}

	/**
	 * Test capability check
	 */
	public function test_capability_check() {
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$this->assertTrue( WPLLMSEO_MCP_Auth::has_capability( $context, 'read' ) );
		$this->assertTrue( WPLLMSEO_MCP_Auth::has_capability( $context, 'write' ) );
		$this->assertTrue( WPLLMSEO_MCP_Auth::has_capability( $context, 'bulk_operations' ) );
		$this->assertFalse( WPLLMSEO_MCP_Auth::has_capability( $context, 'admin' ) );
	}

	/**
	 * Test token revocation
	 */
	public function test_token_revocation() {
		$token = WPLLMSEO_MCP_Auth::generate_token(
			$this->user_id,
			'Revoke Test',
			array( 'read' ),
			7
		);

		// Token should work before revocation.
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $token );
		$this->assertNotFalse( $context );

		// Revoke token.
		WPLLMSEO_MCP_Auth::revoke_token( $token );

		// Token should fail after revocation.
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $token );
		$this->assertFalse( $context );
	}

	/**
	 * Test LLMs.txt pattern matching
	 */
	public function test_llmstxt_pattern_matching() {
		// Test exact match.
		$this->assertTrue(
			WPLLMSEO_MCP_LLMsTxt::matches_pattern( '/test/', '/test/' )
		);

		// Test wildcard.
		$this->assertTrue(
			WPLLMSEO_MCP_LLMsTxt::matches_pattern( '*', '/anything/' )
		);

		// Test prefix.
		$this->assertTrue(
			WPLLMSEO_MCP_LLMsTxt::matches_pattern( '/blog/', '/blog/post-1/' )
		);

		// Test suffix.
		$this->assertTrue(
			WPLLMSEO_MCP_LLMsTxt::matches_pattern( '*.pdf', '/file.pdf' )
		);

		// Test substring.
		$this->assertTrue(
			WPLLMSEO_MCP_LLMsTxt::matches_pattern( '*admin*', '/wp-admin/page' )
		);
	}

	/**
	 * Test audit logging
	 */
	public function test_audit_logging() {
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$log_id = WPLLMSEO_MCP_Audit::log_request(
			'llmseo.fetch_post_summary',
			$context,
			array( 'post_id' => 123 ),
			'success'
		);

		$this->assertNotFalse( $log_id );
		$this->assertIsInt( $log_id );

		// Get logs.
		$logs = WPLLMSEO_MCP_Audit::get_logs();
		$this->assertNotEmpty( $logs );

		$latest_log = $logs[0];
		$this->assertEquals( 'llmseo.fetch_post_summary', $latest_log->ability_name );
		$this->assertEquals( 'success', $latest_log->status );
		$this->assertEquals( $this->user_id, $latest_log->user_id );
	}

	/**
	 * Test audit stats
	 */
	public function test_audit_stats() {
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		// Log some requests.
		WPLLMSEO_MCP_Audit::log_request(
			'llmseo.fetch_post_summary',
			$context,
			array( 'post_id' => 1 ),
			'success'
		);
		WPLLMSEO_MCP_Audit::log_request(
			'llmseo.generate_snippet_preview',
			$context,
			array( 'post_id' => 2 ),
			'success'
		);
		WPLLMSEO_MCP_Audit::log_request(
			'llmseo.save_snippet',
			$context,
			array( 'post_id' => 3 ),
			'error',
			'Test error'
		);

		$stats = WPLLMSEO_MCP_Audit::get_stats( 'hour' );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_requests', $stats );
		$this->assertArrayHasKey( 'success_count', $stats );
		$this->assertArrayHasKey( 'error_count', $stats );
		$this->assertArrayHasKey( 'by_ability', $stats );
		$this->assertArrayHasKey( 'by_status', $stats );

		$this->assertGreaterThanOrEqual( 3, $stats['total_requests'] );
		$this->assertGreaterThanOrEqual( 2, $stats['success_count'] );
		$this->assertGreaterThanOrEqual( 1, $stats['error_count'] );
	}

	/**
	 * Test fetch post summary handler
	 */
	public function test_fetch_post_summary_handler() {
		// Create test post.
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'This is test content for the post summary test.',
				'post_status'  => 'publish',
			)
		);

		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$result = WPLLMSEO_MCP_Handlers::handle_fetch_post_summary(
			array( 'post_id' => $post_id ),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertEquals( $post_id, $result['post_id'] );
		$this->assertEquals( 'Test Post', $result['title'] );
		$this->assertEquals( 'publish', $result['status'] );
		$this->assertArrayHasKey( 'content_sample', $result );
		$this->assertArrayHasKey( 'seo_meta', $result );
		$this->assertArrayHasKey( 'allowed_by_llms_txt', $result );
	}

	/**
	 * Test generate snippet preview handler (dry run)
	 */
	public function test_generate_snippet_preview_dry_run() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Preview Test',
				'post_content' => 'Content for preview test.',
			)
		);

		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$result = WPLLMSEO_MCP_Handlers::handle_generate_snippet_preview(
			array(
				'post_id' => $post_id,
				'dry_run' => true,
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'preview_only', $result );
		$this->assertTrue( $result['preview_only'] );
		$this->assertArrayHasKey( 'post_id', $result );
		$this->assertArrayHasKey( 'would_generate', $result );
	}

	/**
	 * Test save snippet handler
	 */
	public function test_save_snippet_handler() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Save Snippet Test',
				'post_content' => 'Content for save test.',
				'post_author'  => $this->user_id,
			)
		);

		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$result = WPLLMSEO_MCP_Handlers::handle_save_snippet(
			array(
				'post_id' => $post_id,
				'snippet' => 'This is a test AI-generated snippet.',
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( $post_id, $result['post_id'] );

		// Verify snippet was saved.
		$saved_snippet = get_post_meta( $post_id, '_wpllmseo_ai_snippet', true );
		$this->assertEquals( 'This is a test AI-generated snippet.', $saved_snippet );
	}

	/**
	 * Test unauthorized save attempt
	 */
	public function test_unauthorized_save_snippet() {
		// Create post owned by different user.
		$other_user_id = $this->factory->user->create();
		$post_id       = $this->factory->post->create(
			array(
				'post_title'  => 'Unauthorized Test',
				'post_author' => $other_user_id,
			)
		);

		// Create token with limited user.
		$limited_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		$limited_token   = WPLLMSEO_MCP_Auth::generate_token(
			$limited_user_id,
			'Limited Token',
			array( 'write' ),
			7
		);

		$context = WPLLMSEO_MCP_Auth::authenticate_token( $limited_token );

		// Should fail because user can't edit this post.
		$result = WPLLMSEO_MCP_Handlers::handle_save_snippet(
			array(
				'post_id' => $post_id,
				'snippet' => 'Unauthorized snippet',
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );

		// Clean up.
		WPLLMSEO_MCP_Auth::revoke_token( $limited_token );
		wp_delete_user( $limited_user_id );
		wp_delete_user( $other_user_id );
	}

	/**
	 * Test args hashing (no secrets logged)
	 */
	public function test_args_hashing_redacts_secrets() {
		$context = WPLLMSEO_MCP_Auth::authenticate_token( $this->test_token );

		$args = array(
			'post_id' => 123,
			'api_key' => 'secret_key_12345',
			'token'   => 'secret_token_67890',
		);

		// Log request with sensitive args.
		$log_id = WPLLMSEO_MCP_Audit::log_request(
			'test.ability',
			$context,
			$args,
			'success'
		);

		// Get log entry.
		global $wpdb;
		$table = $wpdb->prefix . 'wpllmseo_mcp_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $log_id ) );

		// Args should be hashed, not stored as JSON.
		$this->assertNotContains( 'secret_key_12345', $log->args_hash );
		$this->assertNotContains( 'secret_token_67890', $log->args_hash );
	}

	/**
	 * Test LLMs.txt file creation
	 */
	public function test_llmstxt_file_creation() {
		$file_path = ABSPATH . 'llms-test.txt';

		// Clean up if exists.
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}

		// Create example file.
		$created = WPLLMSEO_MCP_LLMsTxt::create_example_file( $file_path );
		$this->assertTrue( $created );
		$this->assertFileExists( $file_path );

		// Validate syntax.
		$valid = WPLLMSEO_MCP_LLMsTxt::validate_syntax( $file_path );
		$this->assertTrue( $valid['valid'] );

		// Clean up.
		unlink( $file_path );
	}

	/**
	 * Test cache flushing
	 */
	public function test_llmstxt_cache_flush() {
		WPLLMSEO_MCP_LLMsTxt::flush_cache();

		// Cache should be empty after flush.
		$cached = get_transient( 'wpllmseo_llmstxt_rules' );
		$this->assertFalse( $cached );
	}
}
