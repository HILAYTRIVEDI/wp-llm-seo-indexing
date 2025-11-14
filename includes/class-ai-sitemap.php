<?php
/**
 * AI Sitemap JSONL Generator
 *
 * Generates JSONL sitemap for LLM crawlers.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_AI_Sitemap
 *
 * JSONL sitemap generator with caching.
 */
class WPLLMSEO_AI_Sitemap {

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Queue instance
	 *
	 * @var WPLLMSEO_Queue
	 */
	private $queue;

	/**
	 * Cache file path
	 *
	 * @var string
	 */
	private $cache_file;

	/**
	 * Cache directory
	 *
	 * @var string
	 */
	private $cache_dir;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger     = new WPLLMSEO_Logger();
		$this->queue      = new WPLLMSEO_Queue();
		$this->cache_dir  = WPLLMSEO_PLUGIN_DIR . 'var/cache';
		$this->cache_file = $this->cache_dir . '/ai-sitemap.jsonl';

		// Ensure cache directory exists.
		$this->ensure_cache_directory();

		// Hook into worker completion to invalidate cache.
		add_action( 'wpllmseo_job_completed', array( $this, 'maybe_invalidate_cache' ), 10, 2 );
	}

	/**
	 * Ensure cache directory exists and is protected
	 */
	private function ensure_cache_directory() {
		if ( ! file_exists( $this->cache_dir ) ) {
			wp_mkdir_p( $this->cache_dir );
		}

		// Create .htaccess to prevent directory listing.
		$htaccess_file = $this->cache_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_file, "Options -Indexes\nDeny from all\n" );
		}

		// Create index.php.
		$index_file = $this->cache_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Generate JSONL sitemap
	 *
	 * @param bool $stream Whether to stream output or return string.
	 * @return string|bool JSONL content or true if streamed.
	 */
	public function generate_jsonl( $stream = false ) {
		$start_time = microtime( true );

		$this->logger->info( 'AI Sitemap generation started', array( 'stream' => $stream ), 'ai-sitemap.log' );

		// Get settings.
		$settings = get_option( 'wpllmseo_settings', array() );
		$license  = isset( $settings['content_license'] ) ? $settings['content_license'] : 'GPL';

		// Query posts.
		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => 'wpllmseo_indexable',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'wpllmseo_indexable',
					'value'   => 'no',
					'compare' => '!=',
				),
			),
		);

		$posts = get_posts( $args );

		if ( $stream ) {
			// Stream mode: echo each line.
			foreach ( $posts as $post ) {
				$entry = $this->build_entry( $post, $license );
				echo wp_json_encode( $entry ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				flush();
			}

			$execution_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );
			$this->logger->info(
				'AI Sitemap generation completed (streamed)',
				array(
					'posts'          => count( $posts ),
					'execution_time' => $execution_time . 'ms',
				),
				'ai-sitemap.log'
			);

			return true;
		} else {
			// Build mode: return string.
			$jsonl = '';
			foreach ( $posts as $post ) {
				$entry  = $this->build_entry( $post, $license );
				$jsonl .= wp_json_encode( $entry ) . "\n";
			}

			$execution_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );
			$this->logger->info(
				'AI Sitemap generation completed (string)',
				array(
					'posts'          => count( $posts ),
					'size'           => strlen( $jsonl ),
					'execution_time' => $execution_time . 'ms',
				),
				'ai-sitemap.log'
			);

			return $jsonl;
		}
	}

	/**
	 * Build single JSONL entry
	 *
	 * @param WP_Post $post    Post object.
	 * @param string  $license Content license.
	 * @return array Entry data.
	 */
	private function build_entry( $post, $license ) {
		global $wpdb;

		$post_id = $post->ID;

		// Get snippet data.
		$snippet_table = $wpdb->prefix . 'wpllmseo_snippets';
		$snippet       = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT snippet_text, snippet_hash, embedding FROM {$snippet_table} WHERE post_id = %d AND is_preferred = 1 LIMIT 1",
				$post_id
			)
		);

		// Get chunk data.
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';
		$chunk_count  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$chunks_table} WHERE post_id = %d",
				$post_id
			)
		);

		$chunk_preview = null;
		if ( $chunk_count > 0 ) {
			$first_chunk = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT chunk_text FROM {$chunks_table} WHERE post_id = %d ORDER BY id ASC LIMIT 1",
					$post_id
				)
			);

			if ( $first_chunk ) {
				$chunk_preview = wp_strip_all_tags( $first_chunk );
				$chunk_preview = substr( $chunk_preview, 0, 250 );
			}
		} else {
			// No chunks - enqueue embed_post job.
			$this->queue->add(
				'embed_post',
				array( 'post_id' => $post_id )
			);
		}

		// Calculate snippet embedding dimension.
		$snippet_embedding_dim = null;
		if ( $snippet && ! empty( $snippet->embedding ) ) {
			$embedding_array       = json_decode( $snippet->embedding, true );
			$snippet_embedding_dim = is_array( $embedding_array ) ? count( $embedding_array ) : null;
		}

		// Get post metadata.
		$author       = get_the_author_meta( 'display_name', $post->post_author );
		$canonical    = get_permalink( $post_id );
		$word_count   = str_word_count( wp_strip_all_tags( $post->post_content ) );
		$categories   = get_the_category( $post_id );
		$category     = ! empty( $categories ) ? $categories[0]->name : null;

		// Build entry.
		$entry = array(
			'url'                   => $canonical,
			'post_id'               => $post_id,
			'title'                 => $post->post_title,
			'snippet_text'          => $snippet ? wp_strip_all_tags( $snippet->snippet_text ) : null,
			'snippet_hash'          => $snippet ? $snippet->snippet_hash : null,
			'snippet_embedding_dim' => $snippet_embedding_dim,
			'chunk_preview'         => $chunk_preview,
			'chunk_count'           => (int) $chunk_count,
			'last_modified'         => $post->post_modified_gmt . 'Z',
			'license'               => $license,
			'author'                => $author,
			'canonical'             => $canonical,
			'word_count'            => $word_count,
			'category'              => $category,
			'metadata'              => array(
				'hash_integrity' => hash( 'sha256', $post->post_content . $post->post_modified_gmt ),
			),
		);

		return $entry;
	}

	/**
	 * Regenerate and cache sitemap
	 *
	 * @return bool Success status.
	 */
	public function regenerate_cache() {
		$this->logger->info( 'Cache regeneration requested', array(), 'ai-sitemap.log' );

		try {
			$jsonl = $this->generate_jsonl( false );

			if ( false === $jsonl ) {
				throw new Exception( 'JSONL generation returned false' );
			}

			// Write to cache file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$result = file_put_contents( $this->cache_file, $jsonl );

			if ( false === $result ) {
				throw new Exception( 'Failed to write cache file' );
			}

			// Update last generated time.
			update_option( 'wpllmseo_ai_sitemap_last_generated', current_time( 'timestamp' ) );

			$this->logger->info(
				'Cache regenerated successfully',
				array(
					'file_size' => filesize( $this->cache_file ),
					'path'      => $this->cache_file,
				),
				'ai-sitemap.log'
			);

			return true;

		} catch ( Exception $e ) {
			$this->logger->error(
				'Cache regeneration failed',
				array( 'error' => $e->getMessage() ),
				'ai-sitemap.log'
			);
			return false;
		}
	}

	/**
	 * Get cached sitemap content
	 *
	 * @return string|false Cached content or false.
	 */
	public function get_cached_sitemap() {
		if ( ! file_exists( $this->cache_file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $this->cache_file );
	}

	/**
	 * Stream cached sitemap
	 */
	public function stream_cached_sitemap() {
		if ( ! file_exists( $this->cache_file ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $this->cache_file, 'r' );
		if ( ! $handle ) {
			return false;
		}

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			echo fread( $handle, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return true;
	}

	/**
	 * Get cache file size
	 *
	 * @return int|false File size in bytes or false.
	 */
	public function get_cache_size() {
		if ( ! file_exists( $this->cache_file ) ) {
			return false;
		}

		return filesize( $this->cache_file );
	}

	/**
	 * Get last generated timestamp
	 *
	 * @return int|false Timestamp or false.
	 */
	public function get_last_generated() {
		return get_option( 'wpllmseo_ai_sitemap_last_generated', false );
	}

	/**
	 * Check if cache is stale (older than 24 hours)
	 *
	 * @return bool True if stale.
	 */
	public function is_cache_stale() {
		$last_generated = $this->get_last_generated();

		if ( false === $last_generated ) {
			return true;
		}

		$age = current_time( 'timestamp' ) - $last_generated;
		return $age > DAY_IN_SECONDS;
	}

	/**
	 * Maybe invalidate cache on job completion
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $job_type Job type.
	 */
	public function maybe_invalidate_cache( $job_id, $job_type ) {
		// Invalidate on embed jobs.
		if ( in_array( $job_type, array( 'embed_post', 'embed_chunk', 'embed_snippet' ), true ) ) {
			$this->logger->info(
				'Cache invalidated due to job completion',
				array(
					'job_id'   => $job_id,
					'job_type' => $job_type,
				),
				'ai-sitemap.log'
			);

			// Mark as stale by clearing last generated time.
			delete_option( 'wpllmseo_ai_sitemap_last_generated' );
		}
	}

	/**
	 * Daily cron callback
	 */
	public function daily_regenerate() {
		$this->logger->info( 'Daily cron regeneration started', array(), 'ai-sitemap.log' );
		$this->regenerate_cache();
	}
}
