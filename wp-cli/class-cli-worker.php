<?php
/**
 * WP-CLI Worker Command
 *
 * Provides WP-CLI commands for running the background worker.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

// Only load if WP-CLI is running
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Class WPLLMSEO_CLI_Worker
 */
class WPLLMSEO_CLI_Worker {

	/**
	 * Worker instance
	 *
	 * @var WPLLMSEO_Worker
	 */
	private $worker;

	/**
	 * Queue instance
	 *
	 * @var WPLLMSEO_Queue
	 */
	private $queue;

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->worker = new WPLLMSEO_Worker();
		$this->queue = new WPLLMSEO_Queue();
		$this->logger = new WPLLMSEO_Logger();
	}

	/**
	 * Run the background worker to process jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of jobs to process in this run. Default: 10
	 *
	 * [--verbose]
	 * : Show detailed output for each job.
	 *
	 * ## EXAMPLES
	 *
	 *     # Process up to 10 jobs
	 *     wp wpllmseo worker run
	 *
	 *     # Process up to 50 jobs with verbose output
	 *     wp wpllmseo worker run --limit=50 --verbose
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 10;
		$verbose = isset( $assoc_args['verbose'] );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%G━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%GWPLLMSEO Background Worker%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%G━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%n' ) );
		WP_CLI::line( '' );

		// Check if worker is already running
		if ( $this->worker->is_locked() ) {
			WP_CLI::error( 'Worker is already running. Use --force-unlock to release the lock.' );
		}

		// Get initial queue stats
		$initial_stats = $this->queue->get_stats();
		WP_CLI::line( sprintf( 'Queue status: %d queued, %d processing, %d completed, %d failed',
			$initial_stats['queued'],
			$initial_stats['processing'],
			$initial_stats['completed'],
			$initial_stats['failed']
		) );
		WP_CLI::line( sprintf( 'Processing up to %d jobs...', $limit ) );
		WP_CLI::line( '' );

		$start_time = time();
		$processed = 0;
		$failed = 0;

		// Process jobs until limit reached or queue empty
		while ( $processed < $limit ) {
			// Unlock stale jobs first
			$this->queue->unlock_stale_jobs();

			$job = $this->queue->find_next();

			if ( ! $job ) {
				WP_CLI::success( 'No more jobs in queue.' );
				break;
			}

			if ( $verbose ) {
				WP_CLI::line( WP_CLI::colorize( sprintf(
					'%Y[%d]%n Processing: %s (ID: %d)',
					$processed + 1,
					$job->job_type,
					$job->id
				) ) );
			}

			// Lock and process job
			if ( ! $this->queue->lock_job( $job->id ) ) {
				WP_CLI::warning( sprintf( 'Failed to lock job %d', $job->id ) );
				continue;
			}

			$this->queue->increment_attempts( $job->id );

			$job_runner = new WPLLMSEO_Job_Runner();
			$result = $job_runner->execute( $job );

			if ( $result ) {
				$this->queue->complete_job( $job->id );
				$processed++;
				
				if ( $verbose ) {
					WP_CLI::line( WP_CLI::colorize( '%G✓ Completed%n' ) );
				} else {
					WP_CLI::line( WP_CLI::colorize( sprintf( '%G.%n' ) ), false );
				}
			} else {
				$updated_job = $this->queue->get_job( $job->id );
				
				if ( $this->queue->has_exceeded_attempts( $updated_job ) ) {
					$this->queue->fail_job( $job->id, 'Max retry attempts exceeded' );
					$failed++;
					
					if ( $verbose ) {
						WP_CLI::line( WP_CLI::colorize( '%R✗ Failed%n' ) );
					} else {
						WP_CLI::line( WP_CLI::colorize( sprintf( '%R!%n' ) ), false );
					}
				} else {
					$this->queue->unlock_job( $job->id );
					
					if ( $verbose ) {
						WP_CLI::line( WP_CLI::colorize( '%Y⟳ Retrying%n' ) );
					} else {
						WP_CLI::line( WP_CLI::colorize( sprintf( '%Y~%n' ) ), false );
					}
				}
			}

			if ( $verbose ) {
				WP_CLI::line( '' );
			}
		}

		if ( ! $verbose && $processed > 0 ) {
			WP_CLI::line( '' );
		}

		$duration = time() - $start_time;
		$final_stats = $this->queue->get_stats();

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%G━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%GResults%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%G━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%n' ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Jobs processed: %s%d%s', WP_CLI::colorize( '%G' ), $processed, WP_CLI::colorize( '%n' ) ) );
		WP_CLI::line( sprintf( 'Jobs failed: %s%d%s', $failed > 0 ? WP_CLI::colorize( '%R' ) : '', $failed, $failed > 0 ? WP_CLI::colorize( '%n' ) : '' ) );
		WP_CLI::line( sprintf( 'Duration: %d seconds', $duration ) );
		WP_CLI::line( sprintf( 'Remaining in queue: %d', $final_stats['queued'] ) );
		WP_CLI::line( '' );

		WP_CLI::success( sprintf( 'Worker run completed: %d processed, %d failed', $processed, $failed ) );
	}

	/**
	 * Show queue status and statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpllmseo worker status
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$stats = $this->queue->get_stats();
		$worker_status = $this->worker->get_status();

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%G━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%GQueue Status%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%G━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━%n' ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Queued: %s%d%s', WP_CLI::colorize( '%Y' ), $stats['queued'], WP_CLI::colorize( '%n' ) ) );
		WP_CLI::line( sprintf( 'Processing: %d', $stats['processing'] ) );
		WP_CLI::line( sprintf( 'Completed: %s%d%s', WP_CLI::colorize( '%G' ), $stats['completed'], WP_CLI::colorize( '%n' ) ) );
		WP_CLI::line( sprintf( 'Failed: %s%d%s', $stats['failed'] > 0 ? WP_CLI::colorize( '%R' ) : '', $stats['failed'], $stats['failed'] > 0 ? WP_CLI::colorize( '%n' ) : '' ) );
		WP_CLI::line( sprintf( 'Total: %d', $stats['total'] ) );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Worker status: %s', $worker_status['is_running'] ? WP_CLI::colorize( '%YRunning%n' ) : WP_CLI::colorize( '%GIdle%n' ) ) );
		WP_CLI::line( '' );
	}

	/**
	 * Clear all jobs from the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpllmseo worker clear --yes
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to clear all jobs from the queue?', $assoc_args );

		$this->queue->clear_all_jobs();
		WP_CLI::success( 'Queue cleared.' );
	}

	/**
	 * Clean up old completed/failed jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Delete jobs older than this many days. Default: 7
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpllmseo worker cleanup --days=30
	 *
	 * @when after_wp_load
	 */
	public function cleanup( $args, $assoc_args ) {
		$days = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 7;

		$deleted = $this->queue->cleanup_old_jobs( $days );
		$unlocked = $this->queue->unlock_stale_jobs();

		WP_CLI::success( sprintf( 'Cleanup completed: %d jobs deleted, %d stale jobs unlocked', $deleted, $unlocked ) );
	}

	/**
	 * Force release the worker lock.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpllmseo worker unlock
	 *
	 * @when after_wp_load
	 */
	public function unlock( $args, $assoc_args ) {
		if ( ! $this->worker->is_locked() ) {
			WP_CLI::warning( 'Worker is not currently locked.' );
			return;
		}

		$this->worker->force_release_lock();
		WP_CLI::success( 'Worker lock released.' );
	}

	/**
	 * Upgrade database schema
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpllmseo upgrade
	 *
	 * @when after_wp_load
	 */
	public function upgrade( $args, $assoc_args ) {
		WP_CLI::log( 'Running database upgrade...' );
		WPLLMSEO_Installer_Upgrader::upgrade();
	}
}

// Register WP-CLI command
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'wpllmseo worker', 'WPLLMSEO_CLI_Worker' );
	WP_CLI::add_command( 'wpllmseo upgrade', array( 'WPLLMSEO_CLI_Worker', 'upgrade' ) );
}
