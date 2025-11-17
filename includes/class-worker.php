<?php
/**
 * Worker Engine Class
 *
 * Processes background jobs from the queue.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Worker
 */
class WPLLMSEO_Worker {

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
	 * Job runner instance
	 *
	 * @var WPLLMSEO_Job_Runner
	 */
	private $job_runner;

	/**
	 * Token tracker instance
	 *
	 * @var WPLLMSEO_Token_Tracker
	 */
	private $token_tracker;

	/**
	 * Worker lock option name
	 *
	 * @var string
	 */
	private $lock_option = 'wpllmseo_worker_lock';

	/**
	 * Lock timeout in seconds
	 *
	 * @var int
	 */
	private $lock_timeout = 120; // 2 minutes

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->logger = new WPLLMSEO_Logger();
		$this->queue = new WPLLMSEO_Queue();
		$this->job_runner = new WPLLMSEO_Job_Runner();
		$this->token_tracker = new WPLLMSEO_Token_Tracker();

		// Register cron callback
		add_action( 'wpllmseo_worker_event', array( $this, 'run_cron_worker' ) );
	}

	/**
	 * Run worker (CLI or manual trigger)
	 *
	 * @param int $limit Maximum number of jobs to process (default: 10).
	 * @param bool $bypass_cooldown Whether to bypass the cooldown check (default: false).
	 * @return array Processing results.
	 */
	public function run( $limit = 10, $bypass_cooldown = false ) {
		// Cooldown check removed for manual activation
		if ( ! $this->acquire_lock() ) {
			$this->logger->warning(
				'Worker already running, cannot acquire lock',
				array(),
				'worker.log'
			);

			return array(
				'success' => false,
				'message' => 'Worker already running',
				'processed' => 0,
			);
		}

		$limit = absint( $limit );
		$processed = 0;
		$failed = 0;

		$this->logger->info(
			sprintf( 'Worker started (limit: %d, token usage: %.1f%%)', 
				$limit, 
				$this->token_tracker->get_usage_percentage() 
			),
			array( 
				'limit' => $limit,
				'remaining_tokens' => $this->token_tracker->get_remaining_tokens(),
			),
			'worker.log'
		);

		try {
			while ( $processed < $limit ) {
				// Check token usage before processing each job
				if ( ! $this->token_tracker->can_use_tokens( 1000 ) ) {
					$this->logger->warning(
						'Daily token limit reached or nearly exceeded. Stopping worker.',
						array(
							'tokens_used' => $this->token_tracker->get_daily_usage()['tokens_used'],
							'limit' => $this->token_tracker->get_remaining_tokens(),
						),
						'worker.log'
					);
					break;
				}

				// First unlock any stale jobs
				$this->queue->unlock_stale_jobs();

				// Find next job
				$job = $this->queue->find_next();

				if ( ! $job ) {
					$this->logger->info(
						'No more jobs in queue',
						array(),
						'worker.log'
					);
					break;
				}

				// Lock the job
				if ( ! $this->queue->lock_job( $job->id ) ) {
					$this->logger->warning(
						sprintf( 'Failed to lock job: %d', $job->id ),
						array( 'job_id' => $job->id ),
						'worker.log'
					);
					continue;
				}

				// Increment attempts
				$this->queue->increment_attempts( $job->id );

				// Execute the job
				$result = $this->job_runner->execute( $job );

				if ( $result ) {
					// Mark as completed
					$this->queue->complete_job( $job->id );
					$processed++;
				} else {
					// Check if we should retry or fail
					$updated_job = $this->queue->get_job( $job->id );
					
					if ( $this->queue->has_exceeded_attempts( $updated_job ) ) {
						// Max attempts exceeded, mark as failed
						$this->queue->fail_job( $job->id, 'Max retry attempts exceeded' );
						$failed++;
					} else {
						// Unlock for retry
						$this->queue->unlock_job( $job->id );
					}
				}
			}

		} finally {
			$this->release_lock();
		}

		$this->logger->info(
			sprintf( 'Worker finished (processed: %d, failed: %d)', $processed, $failed ),
			array( 'processed' => $processed, 'failed' => $failed ),
			'worker.log'
		);

		// Update manual run timestamp for cooldown
		if ( ! defined( 'DOING_CRON' ) && $processed > 0 ) {
			update_option( 'wpllmseo_worker_last_manual_run', time(), false );
		}

		return array(
			'success' => true,
			'message' => sprintf( 'Processed %d jobs, %d failed', $processed, $failed ),
			'processed' => $processed,
			'failed' => $failed,
		);
	}

	/**
	 * Run worker in cron mode (limited to 5 jobs with 24-hour cooldown)
	 */
	public function run_cron_worker() {
		// Check cooldown - only run if 24 hours have passed
		$last_run = get_option( 'wpllmseo_worker_last_run', 0 );
		$cooldown_period = 24 * HOUR_IN_SECONDS; // 24 hours
		
		if ( time() - $last_run < $cooldown_period ) {
			$this->logger->info(
				sprintf( 'Worker cooldown active. Last run: %s. Next run in: %s', 
					date( 'Y-m-d H:i:s', $last_run ),
					human_time_diff( time(), $last_run + $cooldown_period )
				),
				array(),
				'worker.log'
			);
			return;
		}
		
		// Cron processes max 5 jobs to optimize token usage
		$result = $this->run( 5, true );
		
		// Update last run timestamp only if jobs were processed
		if ( $result['processed'] > 0 ) {
			update_option( 'wpllmseo_worker_last_run', time(), false );
		}
	}

	/**
	 * Acquire worker lock
	 *
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_lock() {
		$lock_data = get_option( $this->lock_option );

		// Check if lock exists and is still valid
		if ( $lock_data ) {
			$lock_time = isset( $lock_data['time'] ) ? $lock_data['time'] : 0;
			
			// If lock is older than timeout, it's stale
			if ( time() - $lock_time < $this->lock_timeout ) {
				return false; // Lock is active
			}
		}

		// Acquire lock
		$lock_data = array(
			'time' => time(),
			'pid'  => getmypid(),
		);

		update_option( $this->lock_option, $lock_data, false );

		return true;
	}

	/**
	 * Release worker lock
	 *
	 * @return bool True on success, false on failure.
	 */
	private function release_lock() {
		return delete_option( $this->lock_option );
	}

	/**
	 * Check if worker is currently locked
	 *
	 * @return bool True if locked, false otherwise.
	 */
	public function is_locked() {
		$lock_data = get_option( $this->lock_option );

		if ( ! $lock_data ) {
			return false;
		}

		$lock_time = isset( $lock_data['time'] ) ? $lock_data['time'] : 0;

		// Check if lock is still valid
		if ( time() - $lock_time >= $this->lock_timeout ) {
			// Lock is stale, remove it
			$this->release_lock();
			return false;
		}

		return true;
	}

	/**
	 * Force release lock (for admin use)
	 *
	 * @return bool True on success, false on failure.
	 */
	public function force_release_lock() {
		$this->logger->warning(
			'Worker lock forcefully released',
			array(),
			'worker.log'
		);

		return $this->release_lock();
	}

	/**
	 * Get worker status
	 *
	 * @return array Worker status information.
	 */
	public function get_status() {
		$queue_stats = $this->queue->get_stats();
		$is_locked = $this->is_locked();

		return array(
			'is_running' => $is_locked,
			'queue_stats' => $queue_stats,
			'lock_info' => $is_locked ? get_option( $this->lock_option ) : null,
		);
	}
}
