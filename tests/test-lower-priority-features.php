<?php
/**
 * Tests for Lower Priority Features
 *
 * Test suite covering:
 * - Media Embeddings (PDF/DOCX)
 * - Semantic Dashboard
 * - Model Manager
 * - Crawler Logs
 * - HTML Renderer
 *
 * @package WP_LLM_SEO_Indexing
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Lower_Priority_Features
 */
class Test_Lower_Priority_Features extends WP_UnitTestCase {

	/**
	 * Test post ID
	 *
	 * @var int
	 */
	protected $post_id;

	/**
	 * Test attachment ID
	 *
	 * @var int
	 */
	protected $attachment_id;

	/**
	 * Set up test environment
	 */
	public function setUp() {
		parent::setUp();

		// Create test post
		$this->post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post for Lower Priority Features',
				'post_content' => 'This is test content with embeddings.',
				'post_status'  => 'publish',
			)
		);

		// Enable all lower priority features
		update_option(
			'wpllmseo_settings',
			array(
				'enable_media_embeddings' => true,
				'enable_crawler_logs'     => true,
				'enable_html_renderer'    => true,
			)
		);

		// Initialize classes
		WPLLMSEO_Media_Embeddings::init();
		WPLLMSEO_Semantic_Dashboard::init();
		WPLLMSEO_Model_Manager::init();
		WPLLMSEO_Crawler_Logs::init();
		WPLLMSEO_HTML_Renderer::init();
	}

	/**
	 * Tear down test environment
	 */
	public function tearDown() {
		wp_delete_post( $this->post_id, true );
		if ( $this->attachment_id ) {
			wp_delete_attachment( $this->attachment_id, true );
		}
		parent::tearDown();
	}

	// ==============================================
	// MEDIA EMBEDDINGS TESTS
	// ==============================================

	/**
	 * Test: PDF text extraction (regex method)
	 */
	public function test_pdf_text_extraction_regex() {
		$sample_pdf_content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n2 0 obj\n<< /Length 44 >>\nstream\nBT\n/F1 12 Tf\n50 750 Td\n(Hello World PDF) Tj\nET\nendstream\nendobj\n%%EOF";
		
		$temp_file = tempnam( sys_get_temp_dir(), 'test_pdf_' );
		file_put_contents( $temp_file, $sample_pdf_content );

		// Create attachment
		$this->attachment_id = $this->factory->attachment->create_upload_object( $temp_file );
		update_post_meta( $this->attachment_id, '_wp_attached_file', basename( $temp_file ) );
		
		// Extract text
		$text = WPLLMSEO_Media_Embeddings::extract_pdf_text( $this->attachment_id );

		$this->assertNotEmpty( $text, 'PDF text extraction should return content' );
		$this->assertStringContainsString( 'Hello World PDF', $text, 'Should extract text from PDF stream' );

		unlink( $temp_file );
	}

	/**
	 * Test: Plain text file indexing
	 */
	public function test_text_file_indexing() {
		$temp_file = tempnam( sys_get_temp_dir(), 'test_txt_' );
		file_put_contents( $temp_file, "This is a plain text file.\nIt has multiple lines.\nFor testing purposes." );

		// Create attachment
		$this->attachment_id = $this->factory->attachment->create_upload_object( $temp_file );
		wp_update_post(
			array(
				'ID'        => $this->attachment_id,
				'post_mime_type' => 'text/plain',
			)
		);

		// Index the file
		$result = WPLLMSEO_Media_Embeddings::index_attachment( $this->attachment_id );

		$this->assertTrue( $result['success'], 'Text file indexing should succeed' );
		$this->assertGreaterThan( 0, $result['chunks'], 'Should create at least 1 chunk' );

		// Verify metadata
		$indexed = get_post_meta( $this->attachment_id, '_wpllmseo_indexed', true );
		$this->assertEquals( '1', $indexed, 'Should mark attachment as indexed' );

		unlink( $temp_file );
	}

	/**
	 * Test: Chunk text splitting
	 */
	public function test_chunk_text_splitting() {
		$long_text = str_repeat( 'This is a sentence. ', 200 ); // ~4000 characters

		$chunks = WPLLMSEO_Media_Embeddings::chunk_text( $long_text, 1000 );

		$this->assertIsArray( $chunks, 'Should return array of chunks' );
		$this->assertGreaterThan( 1, count( $chunks ), 'Should split long text into multiple chunks' );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 1200, strlen( $chunk ), 'Each chunk should be under max size' );
		}
	}

	/**
	 * Test: Media library column display
	 */
	public function test_media_library_column() {
		$columns = WPLLMSEO_Media_Embeddings::add_media_column( array() );

		$this->assertArrayHasKey( 'wpllmseo_indexed', $columns, 'Should add indexed column' );
		$this->assertEquals( 'LLM Indexed', $columns['wpllmseo_indexed'], 'Column should have correct label' );
	}

	// ==============================================
	// SEMANTIC DASHBOARD TESTS
	// ==============================================

	/**
	 * Test: Dashboard stats calculation
	 */
	public function test_dashboard_stats() {
		global $wpdb;

		// Insert test embedding
		$wpdb->insert(
			$wpdb->prefix . 'wpllmseo_chunks',
			array(
				'post_id'    => $this->post_id,
				'chunk_text' => 'Test chunk for dashboard',
				'chunk_hash' => hash( 'sha256', 'test' ),
				'embedding'  => serialize( array_fill( 0, 768, 0.5 ) ),
			)
		);

		$stats = WPLLMSEO_Semantic_Dashboard::get_dashboard_stats();

		$this->assertIsArray( $stats, 'Should return stats array' );
		$this->assertArrayHasKey( 'total_embeddings', $stats, 'Should include total embeddings' );
		$this->assertGreaterThanOrEqual( 1, $stats['total_embeddings'], 'Should count test embedding' );
		$this->assertArrayHasKey( 'avg_quality_score', $stats, 'Should include average quality score' );
		$this->assertArrayHasKey( 'semantic_clusters', $stats, 'Should include cluster count' );
	}

	/**
	 * Test: Content quality analysis
	 */
	public function test_content_quality_analysis() {
		global $wpdb;

		// Add snippet for quality score
		$wpdb->insert(
			$wpdb->prefix . 'wpllmseo_snippets',
			array(
				'post_id'      => $this->post_id,
				'snippet_text' => 'AI-optimized snippet',
				'is_preferred' => 1,
			)
		);

		$wpdb->insert(
			$wpdb->prefix . 'wpllmseo_chunks',
			array(
				'post_id'    => $this->post_id,
				'chunk_text' => 'Test chunk',
				'chunk_hash' => hash( 'sha256', 'chunk1' ),
			)
		);

		$quality = WPLLMSEO_Semantic_Dashboard::get_content_quality();

		$this->assertIsArray( $quality, 'Should return quality array' );
		$this->assertNotEmpty( $quality, 'Should have quality data' );

		$post_quality = array_values( array_filter( $quality, fn( $q ) => $q['post_id'] === $this->post_id ) );
		if ( ! empty( $post_quality ) ) {
			$this->assertGreaterThan( 0, $post_quality[0]['quality_score'], 'Should calculate quality score' );
		}
	}

	/**
	 * Test: Semantic gap detection
	 */
	public function test_semantic_gap_detection() {
		// Assign category to test post
		wp_set_post_categories( $this->post_id, array( 1 ) ); // Uncategorized

		$gaps = WPLLMSEO_Semantic_Dashboard::get_semantic_gaps();

		$this->assertIsArray( $gaps, 'Should return gaps array' );
		// Gaps may be empty if post has embeddings, which is fine
	}

	// ==============================================
	// MODEL MANAGER TESTS
	// ==============================================

	/**
	 * Test: Available models list
	 */
	public function test_available_models() {
		$models = WPLLMSEO_Model_Manager::get_available_models();

		$this->assertIsArray( $models, 'Should return models array' );
		$this->assertArrayHasKey( 'text-embedding-004', $models, 'Should include Gemini model' );
		$this->assertArrayHasKey( 'text-embedding-3-small', $models, 'Should include OpenAI small model' );
		$this->assertArrayHasKey( 'text-embedding-3-large', $models, 'Should include OpenAI large model' );

		$gemini_model = $models['text-embedding-004'];
		$this->assertEquals( 768, $gemini_model['dimensions'], 'Gemini should be 768 dimensions' );
		$this->assertEquals( 'Google', $gemini_model['provider'], 'Should be Google provider' );
	}

	/**
	 * Test: Current model detection
	 */
	public function test_current_model_detection() {
		update_option( 'wpllmseo_settings', array( 'model' => 'text-embedding-004' ) );

		$current = WPLLMSEO_Model_Manager::get_current_model();

		$this->assertEquals( 'text-embedding-004', $current, 'Should return current model' );
	}

	/**
	 * Test: Model distribution calculation
	 */
	public function test_model_distribution() {
		global $wpdb;

		// Add embedding with model metadata
		$wpdb->insert(
			$wpdb->prefix . 'wpllmseo_chunks',
			array(
				'post_id'    => $this->post_id,
				'chunk_text' => 'Test chunk',
				'chunk_hash' => hash( 'sha256', 'test' ),
				'embedding'  => serialize( array_fill( 0, 768, 0.5 ) ),
			)
		);

		$distribution = WPLLMSEO_Model_Manager::get_model_distribution();

		$this->assertIsArray( $distribution, 'Should return distribution array' );
	}

	/**
	 * Test: Migration job creation
	 */
	public function test_migration_job_creation() {
		$job_id = WPLLMSEO_Model_Manager::create_migration_job( 'text-embedding-004', 'text-embedding-3-small' );

		$this->assertGreaterThan( 0, $job_id, 'Should create migration job' );

		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpllmseo_migration_jobs WHERE id = %d", $job_id ) );

		$this->assertEquals( 'text-embedding-004', $job->from_model, 'Should set from_model' );
		$this->assertEquals( 'text-embedding-3-small', $job->to_model, 'Should set to_model' );
		$this->assertEquals( 'pending', $job->status, 'Should start as pending' );
	}

	// ==============================================
	// CRAWLER LOGS TESTS
	// ==============================================

	/**
	 * Test: Crawler detection
	 */
	public function test_crawler_detection() {
		$user_agents = array(
			'ChatGPT-User'       => 'ChatGPT',
			'GPTBot/1.0'         => 'GPTBot',
			'ClaudeBot/1.0'      => 'ClaudeBot',
			'Google-Extended/1.0' => 'Google-Extended',
			'PerplexityBot/1.0'  => 'PerplexityBot',
			'Applebot/1.0'       => 'Applebot',
			'Bytespider/1.0'     => 'Bytespider',
			'Mozilla/5.0'        => 'Unknown',
		);

		foreach ( $user_agents as $ua => $expected ) {
			$detected = WPLLMSEO_Crawler_Logs::detect_crawler_type( $ua );
			$this->assertEquals( $expected, $detected, "Should detect $expected from UA: $ua" );
		}
	}

	/**
	 * Test: Endpoint detection
	 */
	public function test_endpoint_detection() {
		$endpoints = array(
			'/ai-sitemap.jsonl'           => 'ai_sitemap',
			'/llm-sitemap.json'           => 'llm_sitemap',
			'/wp-json/llm/v1/posts'       => 'llm_api',
			'/wp-json/wp-llmseo/v1/query' => 'rag_api',
			'/post-123?llm_render=1'      => 'html_renderer',
			'/robots.txt'                 => 'robots_txt',
			'/other-page'                 => 'other',
		);

		foreach ( $endpoints as $uri => $expected ) {
			$detected = WPLLMSEO_Crawler_Logs::detect_endpoint_type( $uri );
			$this->assertEquals( $expected, $detected, "Should detect $expected from URI: $uri" );
		}
	}

	/**
	 * Test: Log entry creation
	 */
	public function test_log_entry_creation() {
		$log_id = WPLLMSEO_Crawler_Logs::log_request(
			'1.2.3.4',
			'ChatGPT-User',
			'/ai-sitemap.jsonl',
			200,
			150
		);

		$this->assertGreaterThan( 0, $log_id, 'Should create log entry' );

		global $wpdb;
		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpllmseo_crawler_logs WHERE id = %d", $log_id ) );

		$this->assertEquals( '1.2.3.4', $log->ip_address, 'Should save IP address' );
		$this->assertEquals( 'ChatGPT', $log->crawler_type, 'Should detect crawler type' );
		$this->assertEquals( 'ai_sitemap', $log->endpoint_type, 'Should detect endpoint type' );
		$this->assertEquals( 200, $log->response_code, 'Should save response code' );
		$this->assertEquals( 150, $log->response_time, 'Should save response time' );
	}

	/**
	 * Test: Stats aggregation
	 */
	public function test_crawler_stats() {
		// Create multiple log entries
		WPLLMSEO_Crawler_Logs::log_request( '1.2.3.4', 'ChatGPT-User', '/ai-sitemap.jsonl', 200, 100 );
		WPLLMSEO_Crawler_Logs::log_request( '1.2.3.5', 'GPTBot/1.0', '/llm-sitemap.json', 200, 120 );
		WPLLMSEO_Crawler_Logs::log_request( '1.2.3.6', 'ChatGPT-User', '/ai-sitemap.jsonl', 200, 110 );

		$stats = WPLLMSEO_Crawler_Logs::get_stats();

		$this->assertIsArray( $stats, 'Should return stats array' );
		$this->assertArrayHasKey( 'total_requests', $stats, 'Should include total requests' );
		$this->assertGreaterThanOrEqual( 3, $stats['total_requests'], 'Should count all requests' );
		$this->assertArrayHasKey( 'by_crawler', $stats, 'Should include crawler breakdown' );
		$this->assertArrayHasKey( 'by_endpoint', $stats, 'Should include endpoint breakdown' );
	}

	// ==============================================
	// HTML RENDERER TESTS
	// ==============================================

	/**
	 * Test: Query var registration
	 */
	public function test_query_var_registration() {
		global $wp;

		$this->assertContains( 'llm_render', $wp->public_query_vars, 'Should register llm_render query var' );
	}

	/**
	 * Test: HTML rendering
	 */
	public function test_html_rendering() {
		// Set query var
		set_query_var( 'llm_render', '1' );
		global $post;
		$post = get_post( $this->post_id );

		// Capture output
		ob_start();
		WPLLMSEO_HTML_Renderer::maybe_render_html();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output, 'Should generate HTML output' );
		$this->assertStringContainsString( '<!DOCTYPE html>', $output, 'Should be valid HTML document' );
		$this->assertStringContainsString( '<title>', $output, 'Should include title tag' );
		$this->assertStringContainsString( get_the_title( $this->post_id ), $output, 'Should include post title' );
	}

	/**
	 * Test: Clean content processing
	 */
	public function test_clean_content() {
		$dirty_content = '<p>This is <strong>content</strong> with [shortcode] and <!-- comment --></p>';

		$clean = WPLLMSEO_HTML_Renderer::clean_content( $dirty_content );

		$this->assertStringNotContainsString( '[shortcode]', $clean, 'Should remove shortcodes' );
		$this->assertStringNotContainsString( '<!--', $clean, 'Should remove comments' );
		$this->assertStringContainsString( '<strong>', $clean, 'Should preserve basic HTML' );
	}

	/**
	 * Test: Absolute URL conversion
	 */
	public function test_absolute_urls() {
		$html = '<a href="/page">Link</a> <img src="/image.jpg">';

		$absolute = WPLLMSEO_HTML_Renderer::make_links_absolute( $html );

		$this->assertStringContainsString( home_url( '/page' ), $absolute, 'Should convert relative links' );
		$this->assertStringContainsString( home_url( '/image.jpg' ), $absolute, 'Should convert relative images' );
	}

	/**
	 * Test: Table of contents extraction
	 */
	public function test_toc_extraction() {
		$content = '<h2>Section 1</h2><p>Content</p><h2>Section 2</h2><p>More content</p>';

		$toc = WPLLMSEO_HTML_Renderer::extract_toc( $content );

		$this->assertIsArray( $toc, 'Should return TOC array' );
		$this->assertCount( 2, $toc, 'Should extract 2 headings' );
		$this->assertEquals( 'Section 1', $toc[0]['text'], 'Should extract first heading text' );
		$this->assertEquals( 'Section 2', $toc[1]['text'], 'Should extract second heading text' );
	}

	/**
	 * Test: REST API endpoint
	 */
	public function test_rest_render_endpoint() {
		$request = new WP_REST_Request( 'GET', "/wp-json/wp-llmseo/v1/render/{$this->post_id}" );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'Should return 200 status' );
		
		$html = $response->get_data();
		$this->assertNotEmpty( $html, 'Should return HTML content' );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html, 'Should be valid HTML' );
	}

	// ==============================================
	// INTEGRATION TESTS
	// ==============================================

	/**
	 * Test: All features initialized
	 */
	public function test_all_features_initialized() {
		$this->assertTrue( class_exists( 'WPLLMSEO_Media_Embeddings' ), 'Media Embeddings class should exist' );
		$this->assertTrue( class_exists( 'WPLLMSEO_Semantic_Dashboard' ), 'Semantic Dashboard class should exist' );
		$this->assertTrue( class_exists( 'WPLLMSEO_Model_Manager' ), 'Model Manager class should exist' );
		$this->assertTrue( class_exists( 'WPLLMSEO_Crawler_Logs' ), 'Crawler Logs class should exist' );
		$this->assertTrue( class_exists( 'WPLLMSEO_HTML_Renderer' ), 'HTML Renderer class should exist' );
	}

	/**
	 * Test: Settings defaults
	 */
	public function test_settings_defaults() {
		delete_option( 'wpllmseo_settings' );
		WPLLMSEO_Installer_Upgrader::initialize_default_settings();

		$settings = get_option( 'wpllmseo_settings' );

		$this->assertArrayHasKey( 'enable_media_embeddings', $settings, 'Should have media embeddings setting' );
		$this->assertArrayHasKey( 'enable_crawler_logs', $settings, 'Should have crawler logs setting' );
		$this->assertArrayHasKey( 'enable_html_renderer', $settings, 'Should have HTML renderer setting' );

		// Lower priority features disabled by default
		$this->assertFalse( $settings['enable_media_embeddings'], 'Media embeddings should be disabled by default' );
		$this->assertFalse( $settings['enable_crawler_logs'], 'Crawler logs should be disabled by default' );
		$this->assertFalse( $settings['enable_html_renderer'], 'HTML renderer should be disabled by default' );
	}

	/**
	 * Test: Database tables created
	 */
	public function test_database_tables() {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'wpllmseo_crawler_logs',
		);

		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertEquals( $table, $exists, "Table $table should exist" );
		}
	}

	/**
	 * Test: REST API endpoints registered
	 */
	public function test_rest_endpoints_registered() {
		$routes = rest_get_server()->get_routes();

		$expected_routes = array(
			'/wp-llmseo/v1/media/(?P<id>\d+)/index',
			'/wp-llmseo/v1/dashboard/stats',
			'/wp-llmseo/v1/dashboard/content-quality',
			'/wp-llmseo/v1/models',
			'/wp-llmseo/v1/crawler-logs',
			'/wp-llmseo/v1/render/(?P<id>\d+)',
		);

		foreach ( $expected_routes as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Route $route should be registered" );
		}
	}
}
