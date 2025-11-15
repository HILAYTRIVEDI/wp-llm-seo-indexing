<?php
/**
 * Queue Manager Class
 *
 * Manages background job queue operations.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Queue
 */
class WPLLMSEO_Queue {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Maximum retry attempts
	 *
	 * @var int
	 */
	private $max_attempts = 3;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		// Validate table name to avoid accidental or injected table operations
		require_once __DIR__ . '/helpers/class-db-helpers.php';
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
		if ( is_wp_error( $validated ) ) {
			// Fallback to prefixed name but mark as unsafe (operations should be guarded)
			$this->table_name = $wpdb->prefix . 'wpllmseo_jobs';
		} else {
			$this->table_name = $validated;
		}
		$this->logger = new WPLLMSEO_Logger();
	}

	/**
	 * Add a job to the queue
	 *
	 * @param string $job_type Job type (embed_post, embed_chunk, embed_snippet, etc).
	 * @param array  $payload  Job payload data.
	 * @return int|false Job ID or false on failure.
	 */
	public function add( $job_type, $payload = array() ) {
		global $wpdb;

		$post_id = isset( $payload['post_id'] ) ? absint( $payload['post_id'] ) : null;
		$snippet_id = isset( $payload['snippet_id'] ) ? absint( $payload['snippet_id'] ) : null;
		$payload_json = wp_json_encode( $payload );

		// Check for existing duplicate job (same type, post_id, snippet_id, and status queued/processing)
		$existing_job = $this->find_duplicate_job( $job_type, $post_id, $snippet_id );
		
		if ( $existing_job ) {
			$this->logger->debug(
				sprintf( 'Duplicate job skipped: %s (Existing ID: %d)', $job_type, $existing_job->id ),
				$payload,
				'queue.log'
			);
			return $existing_job->id; // Return existing job ID instead of creating duplicate
		}

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'job_type'   => sanitize_text_field( $job_type ),
				'post_id'    => $post_id,
				'snippet_id' => $snippet_id,
				'payload'    => $payload_json,
				'status'     => 'queued',
				'attempts'   => 0,
				'locked'     => 0,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%d' )
		);

		if ( $result ) {
			$job_id = $wpdb->insert_id;
			$this->logger->info(
				sprintf( 'Job added: %s (ID: %d)', $job_type, $job_id ),
				$payload,
				'queue.log'
			);
			
			// Don't trigger worker immediately - let scheduled cron handle it
			// This prevents excessive API calls and token usage
			
			return $job_id;
		}

		$this->logger->error(
			sprintf( 'Failed to add job: %s', $job_type ),
			$payload,
			'errors.log'
		);

		return false;
	}

	/**
	 * Find duplicate job in the queue
	 *
	 * Checks for existing job with same type, post_id, snippet_id that is queued or processing.
	 *
	 * @param string   $job_type    Job type.
	 * @param int|null $post_id     Post ID (optional).
	 * @param int|null $snippet_id  Snippet ID (optional).
	 * @return object|null Existing job object or null if no duplicate found.
	 */
	private function find_duplicate_job( $job_type, $post_id = null, $snippet_id = null ) {
		global $wpdb;

		// Build WHERE conditions
		$where_conditions = array();
		$where_values = array();

		// Job type (required)
		$where_conditions[] = 'job_type = %s';
		$where_values[] = $job_type;

		// Post ID (if provided)
		if ( null !== $post_id ) {
			$where_conditions[] = 'post_id = %d';
			$where_values[] = $post_id;
		} else {
			$where_conditions[] = 'post_id IS NULL';
		}

		// Snippet ID (if provided)
		if ( null !== $snippet_id ) {
			$where_conditions[] = 'snippet_id = %d';
			$where_values[] = $snippet_id;
		} else {
			$where_conditions[] = 'snippet_id IS NULL';
		}

		// Only check queued or processing jobs (not completed or failed)
		$where_conditions[] = "status IN ('queued', 'processing')";

		$where_clause = implode( ' AND ', $where_conditions );

		// If we have values to prepare, use prepare; otherwise use direct query
		if ( ! empty( $where_values ) ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$where_clause} LIMIT 1",
				...$where_values
			);
		} else {
			$query = "SELECT * FROM {$this->table_name} WHERE {$where_clause} LIMIT 1";
		}

		return $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Find next available job
	 *
	 * @return object|null Job object or null if none available.
	 */
	public function find_next() {
		global $wpdb;

		// Log current queue status for debugging
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
				'queued'
			)
		);
		
		$this->logger->info(
			sprintf( 'Finding next job (total queued: %d)', $count ),
			array( 'queued_count' => $count ),
			'queue.log'
		);

		// Find first unlocked, queued job
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} 
				WHERE status = %s AND locked = %d 
				ORDER BY id ASC 
				LIMIT 1",
				'queued',
				0
			)
		);
		
		if ( $job ) {
			$this->logger->info(
				sprintf( 'Found job: %d (type: %s)', $job->id, $job->job_type ),
				array( 'job_id' => $job->id, 'job_type' => $job->job_type ),
				'queue.log'
			);
		}

		return $job;
	}

	/**
	 * Lock a job for processing
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function lock_job( $job_id ) {
		global $wpdb;

		$job_id = absint( $job_id );

		$result = $wpdb->update(
			$this->table_name,
			array(
				'locked'     => 1,
				'status'     => 'processing',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->logger->debug(
				sprintf( 'Job locked: %d', $job_id ),
				array(),
				'worker.log'
			);
			return true;
		}

		return false;
	}

	/**
	 * Unlock a job
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function unlock_job( $job_id ) {
		global $wpdb;

		$job_id = absint( $job_id );

		$result = $wpdb->update(
			$this->table_name,
			array(
				'locked'     => 0,
				'status'     => 'queued',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->logger->debug(
				sprintf( 'Job unlocked: %d', $job_id ),
				array(),
				'worker.log'
			);
			return true;
		}

		return false;
	}

	/**
	 * Mark job as failed
	 *
	 * @param int    $job_id Job ID.
	 * @param string $message Error message.
	 * @return bool True on success, false on failure.
	 */
	public function fail_job( $job_id, $message = '' ) {
		global $wpdb;

		$job_id = absint( $job_id );

		$result = $wpdb->update(
			$this->table_name,
			array(
				'status'     => 'failed',
				'locked'     => 0,
				'last_error' => sanitize_text_field( $message ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->logger->error(
				sprintf( 'Job failed: %d - %s', $job_id, $message ),
				array(),
				'errors.log'
			);
			return true;
		}

		return false;
	}

	/**
	 * Mark job as completed
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function complete_job( $job_id ) {
		global $wpdb;

		$job_id = absint( $job_id );

		$result = $wpdb->update(
			$this->table_name,
			array(
				'status'     => 'completed',
				'locked'     => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->logger->info(
				sprintf( 'Job completed: %d', $job_id ),
				array(),
				'worker.log'
			);
			return true;
		}

		return false;
	}

	/**
	 * Increment job attempts
	 *
	 * @param int $job_id Job ID.
	 * @return bool True on success, false on failure.
	 */
	public function increment_attempts( $job_id ) {
		global $wpdb;

		$job_id = absint( $job_id );

		// Get current attempts
		$job = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT attempts FROM {$this->table_name} WHERE id = %d",
				$job_id
			)
		);

		if ( ! $job ) {
			return false;
		}

		$new_attempts = $job->attempts + 1;

		$result = $wpdb->update(
			$this->table_name,
			array(
				'attempts'   => $new_attempts,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->logger->debug(
				sprintf( 'Job attempts incremented: %d (attempts: %d)', $job_id, $new_attempts ),
				array(),
				'worker.log'
			);
			return true;
		}

		return false;
	}

	/**
	 * Check if job has exceeded max attempts
	 *
	 * @param object $job Job object.
	 * @return bool True if exceeded, false otherwise.
	 */
	public function has_exceeded_attempts( $job ) {
		return $job->attempts >= $this->max_attempts;
	}

	/**
	 * Get job by ID
	 *
	 * @param int $job_id Job ID.
	 * @return object|null Job object or null.
	 */
	public function get_job( $job_id ) {
		global $wpdb;

		$job_id = absint( $job_id );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE id = %d",
				$job_id
			)
		);
	}

	/**
	 * Get queue length (queued jobs)
	 *
	 * @return int Number of queued jobs.
	 */
	public function get_queue_length() {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE status = %s",
				'queued'
			)
		);

		return absint( $count );
	}

	/**
	 * Get queue stats
	 *
	 * @return array Queue statistics.
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array(
			'queued'     => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
			'total'      => 0,
		);

		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) as count FROM %s GROUP BY status", $this->table_name )
		);

		foreach ( $results as $row ) {
			$stats[ $row->status ] = absint( $row->count );
			$stats['total'] += absint( $row->count );
		}

		return $stats;
	}

	/**
	 * Clear completed jobs older than X days
	 *
	 * @param int $days Number of days (default: 7).
	 * @return int Number of jobs deleted.
	 */
	public function cleanup_old_jobs( $days = 7 ) {
		global $wpdb;

		$days = absint( $days );
		$date = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %s WHERE status IN ('completed', 'failed') AND updated_at < %s",
				$this->table_name,
				$date
			)
		);

		if ( $deleted ) {
			$this->logger->info(
				sprintf( 'Cleaned up %d old jobs (older than %d days)', $deleted, $days ),
				array(),
				'queue.log'
			);
		}

		return $deleted;
	}

	/**
	 * Unlock stale jobs (locked for more than 5 minutes)
	 *
	 * @return int Number of jobs unlocked.
	 */
	public function unlock_stale_jobs() {
		global $wpdb;

		$stale_time = date( 'Y-m-d H:i:s', strtotime( '-5 minutes' ) );

		$unlocked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %s SET locked = 0, status = 'queued' WHERE locked = 1 AND updated_at < %s",
				$this->table_name,
				$stale_time
			)
		);

		if ( $unlocked ) {
			$this->logger->warning(
				sprintf( 'Unlocked %d stale jobs', $unlocked ),
				array(),
				'worker.log'
			);
		}

		return $unlocked;
	}

	/**
	 * Delete all jobs (for testing/cleanup)
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_all_jobs() {
		global $wpdb;

		// Use DELETE with caution; TRUNCATE is more destructive and may require explicit privileges.
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM %s", $this->table_name ) );

		if ( $result ) {
			$this->logger->warning(
				'All jobs cleared from queue',
				array(),
				'queue.log'
			);
			return true;
		}

		return false;
	}

	/**
	 * Remove duplicate jobs from the queue
	 *
	 * Keeps the oldest job for each unique combination of job_type, post_id, snippet_id
	 * and removes newer duplicates with queued status.
	 *
	 * @return int Number of duplicate jobs removed.
	 */
	public function remove_duplicate_jobs() {
		global $wpdb;

		// Find duplicates by identifying jobs with same job_type, post_id, snippet_id
		// Keep the oldest (min id), delete the rest
		$duplicates_removed = $wpdb->query(
			"DELETE t1 FROM {$this->table_name} t1
			INNER JOIN {$this->table_name} t2 
			WHERE t1.id > t2.id
			AND t1.job_type = t2.job_type
			AND (
				(t1.post_id = t2.post_id OR (t1.post_id IS NULL AND t2.post_id IS NULL))
			)
			AND (
				(t1.snippet_id = t2.snippet_id OR (t1.snippet_id IS NULL AND t2.snippet_id IS NULL))
			)
			AND t1.status IN ('queued', 'processing')
			AND t2.status IN ('queued', 'processing')"
		);

		if ( $duplicates_removed ) {
			$this->logger->info(
				sprintf( 'Removed %d duplicate jobs from queue', $duplicates_removed ),
				array( 'count' => $duplicates_removed ),
				'queue.log'
			);
		}

		return (int) $duplicates_removed;
	}

	/**
	 * Get all queue items with pagination
	 *
	 * @param int    $limit  Number of items to return.
	 * @param int    $offset Offset for pagination.
	 * @param string $status Filter by status (optional).
	 * @return array Queue items.
	 */
	public function get_all_items( $limit = 50, $offset = 0, $status = '' ) {
		global $wpdb;

		$limit  = absint( $limit );
		$offset = absint( $offset );

		if ( ! empty( $status ) ) {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_name} 
					WHERE status = %s 
					ORDER BY id DESC 
					LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				)
			);
		} else {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table_name} 
					ORDER BY id DESC 
					LIMIT %d OFFSET %d",
					$limit,
					$offset
				)
			);
		}

		return $items ? $items : array();
	}
}
