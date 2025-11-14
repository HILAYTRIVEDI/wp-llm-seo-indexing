<?php
/**
 * Job Runner Class
 *
 * Executes background jobs based on job type.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Job_Runner
 */
class WPLLMSEO_Job_Runner {

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
	 * Constructor
	 */
	public function __construct() {
		$this->logger = new WPLLMSEO_Logger();
		$this->queue = new WPLLMSEO_Queue();
	}

	/**
	 * Execute a job
	 *
	 * @param object $job Job object.
	 * @return bool True on success, false on failure.
	 */
	public function execute( $job ) {
		$this->logger->info(
			sprintf( 'Executing job: %s (ID: %d)', $job->job_type, $job->id ),
			array( 'job_id' => $job->id ),
			'worker.log'
		);

		try {
			$payload = json_decode( $job->payload, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				throw new Exception( 'Invalid job payload JSON' );
			}

			$result = false;

			switch ( $job->job_type ) {
				case 'embed_post':
					$result = $this->execute_embed_post( $payload );
					break;

				case 'embed_chunk':
					$result = $this->execute_embed_chunk( $payload );
					break;

				case 'embed_snippet':
					$result = $this->execute_embed_snippet( $payload );
					break;

				case 'reindex_post':
					$result = $this->execute_reindex_post( $payload );
					break;

				case 'reindex_all':
					$result = $this->execute_reindex_all( $payload );
					break;

				case 'cleanup':
					$result = $this->execute_cleanup( $payload );
					break;

				default:
					throw new Exception( sprintf( 'Unknown job type: %s', $job->job_type ) );
			}

			if ( $result ) {
				$this->logger->info(
					sprintf( 'Job executed successfully: %s (ID: %d)', $job->job_type, $job->id ),
					array( 'job_id' => $job->id ),
					'worker.log'
				);
			}

			return $result;

		} catch ( Exception $e ) {
			$this->logger->error(
				sprintf( 'Job execution failed: %s (ID: %d) - %s', $job->job_type, $job->id, $e->getMessage() ),
				array( 'job_id' => $job->id, 'error' => $e->getMessage() ),
				'errors.log'
			);

			return false;
		}
	}

	/**
	 * Execute embed_post job
	 *
	 * @param array $payload Job payload.
	 * @return bool True on success, false on failure.
	 */
	private function execute_embed_post( $payload ) {
		if ( empty( $payload['post_id'] ) ) {
			throw new Exception( 'Missing post_id in payload' );
		}

		$post_id = absint( $payload['post_id'] );
		$post = get_post( $post_id );

		if ( ! $post ) {
			throw new Exception( sprintf( 'Post not found: %d', $post_id ) );
		}

		// TODO: Implement chunking and embedding logic
		// This will be implemented when we build the chunk indexer
		// For now, log that we would process this
		$this->logger->info(
			sprintf( 'Would chunk and embed post: %d (%s)', $post_id, $post->post_title ),
			array( 'post_id' => $post_id ),
			'worker.log'
		);

		return true;
	}

	/**
	 * Execute embed_chunk job
	 *
	 * @param array $payload Job payload.
	 * @return bool True on success, false on failure.
	 */
	private function execute_embed_chunk( $payload ) {
		if ( empty( $payload['chunk_id'] ) ) {
			throw new Exception( 'Missing chunk_id in payload' );
		}

		$chunk_id = absint( $payload['chunk_id'] );

		// TODO: Implement single chunk embedding
		// This will be implemented when we build the chunk indexer
		$this->logger->info(
			sprintf( 'Would embed chunk: %d', $chunk_id ),
			array( 'chunk_id' => $chunk_id ),
			'worker.log'
		);

		return true;
	}

	/**
	 * Execute embed_snippet job
	 *
	 * @param array $payload Job payload.
	 * @return bool True on success, false on failure.
	 */
	private function execute_embed_snippet( $payload ) {
		if ( empty( $payload['snippet_id'] ) ) {
			throw new Exception( 'Missing snippet_id in payload' );
		}

		$snippet_id = absint( $payload['snippet_id'] );

		// Use the snippet indexer from Module 2
		if ( ! class_exists( 'WPLLMSEO_Snippet_Indexer' ) ) {
			throw new Exception( 'Snippet indexer not available' );
		}

		$indexer = new WPLLMSEO_Snippet_Indexer();
		$result = $indexer->process_snippet_embedding( $snippet_id );

		if ( ! $result ) {
			throw new Exception( sprintf( 'Failed to embed snippet: %d', $snippet_id ) );
		}

		return true;
	}

	/**
	 * Execute reindex_post job
	 *
	 * @param array $payload Job payload.
	 * @return bool True on success, false on failure.
	 */
	private function execute_reindex_post( $payload ) {
		if ( empty( $payload['post_id'] ) ) {
			throw new Exception( 'Missing post_id in payload' );
		}

		$post_id = absint( $payload['post_id'] );
		$post = get_post( $post_id );

		if ( ! $post ) {
			throw new Exception( sprintf( 'Post not found: %d', $post_id ) );
		}

		// TODO: Delete existing chunks and re-chunk/re-embed
		// This will be implemented when we build the chunk indexer
		$this->logger->info(
			sprintf( 'Would reindex post: %d (%s)', $post_id, $post->post_title ),
			array( 'post_id' => $post_id ),
			'worker.log'
		);

		return true;
	}

	/**
	 * Execute reindex_all job
	 *
	 * @param array $payload Job payload.
	 * @return bool True on success, false on failure.
	 */
	private function execute_reindex_all( $payload ) {
		$post_types = isset( $payload['post_types'] ) ? $payload['post_types'] : array( 'post', 'page' );
		$batch_size = isset( $payload['batch_size'] ) ? absint( $payload['batch_size'] ) : 50;
		$offset = isset( $payload['offset'] ) ? absint( $payload['offset'] ) : 0;

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			$this->logger->info(
				sprintf( 'Reindex all completed (no more posts at offset %d)', $offset ),
				array( 'offset' => $offset ),
				'worker.log'
			);
			return true;
		}

		// Enqueue reindex jobs for each post
		foreach ( $posts as $post_id ) {
			$this->queue->add(
				'reindex_post',
				array( 'post_id' => $post_id )
			);
		}

		// Enqueue next batch
		$next_offset = $offset + $batch_size;
		$this->queue->add(
			'reindex_all',
			array(
				'post_types' => $post_types,
				'batch_size' => $batch_size,
				'offset'     => $next_offset,
			)
		);

		$this->logger->info(
			sprintf( 'Reindex batch processed: %d posts (offset: %d, next: %d)', count( $posts ), $offset, $next_offset ),
			array( 'count' => count( $posts ), 'offset' => $offset ),
			'worker.log'
		);

		return true;
	}

	/**
	 * Execute cleanup job
	 *
	 * @param array $payload Job payload.
	 * @return bool True on success, false on failure.
	 */
	private function execute_cleanup( $payload ) {
		$days = isset( $payload['days'] ) ? absint( $payload['days'] ) : 7;

		// Cleanup old completed/failed jobs
		$deleted = $this->queue->cleanup_old_jobs( $days );

		// Unlock stale jobs
		$unlocked = $this->queue->unlock_stale_jobs();

		$this->logger->info(
			sprintf( 'Cleanup completed: %d jobs deleted, %d stale jobs unlocked', $deleted, $unlocked ),
			array( 'deleted' => $deleted, 'unlocked' => $unlocked ),
			'worker.log'
		);

		return true;
	}
}
