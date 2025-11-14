<?php
/**
 * Enterprise Worker Engine - Atomic Job Claiming with Retries
 *
 * This file contains enterprise-grade worker improvements:
 * - Atomic job claiming using UPDATE JOIN
 * - Stale lock detection and unlock
 * - Exponential backoff with jitter
 * - Dead-letter queue for failed jobs
 * - Transactional job processing
 *
 * To integrate: Replace methods in class-worker.php and class-queue.php
 *
 * @package WPLLMSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic job claim - Returns claimed job or null
 *
 * This uses UPDATE JOIN to atomically claim a single job.
 * Prevents race conditions when multiple workers run concurrently.
 *
 * @param string $worker_id Worker identifier (hostname:pid).
 * @return object|null Claimed job object or null.
 */
function wpllmseo_claim_job_atomic( $worker_id ) {
	global $wpdb;
	
	$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
	$now        = current_time( 'mysql' );
	
	// Atomic claim using UPDATE JOIN pattern
	$claim_sql = "
		UPDATE {$jobs_table} j
		JOIN (
			SELECT id FROM {$jobs_table}
			WHERE status = 'queued' 
			  AND locked = 0 
			  AND (run_after IS NULL OR run_after <= %s)
			ORDER BY created_at ASC
			LIMIT 1
		) AS candidate ON j.id = candidate.id
		SET j.status = 'running',
		    j.locked = 1,
		    j.locked_at = %s,
		    j.runner = %s
	";
	
	$wpdb->query( $wpdb->prepare( $claim_sql, $now, $now, $worker_id ) );
	
	if ( $wpdb->rows_affected === 0 ) {
		return null; // No job claimed
	}
	
	// Fetch the claimed job
	$job = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$jobs_table} 
			WHERE status = 'running' 
			  AND locked = 1 
			  AND runner = %s 
			ORDER BY locked_at DESC 
			LIMIT 1",
			$worker_id
		)
	);
	
	return $job;
}

/**
 * Unlock stale jobs
 *
 * Jobs locked longer than threshold are unlocked and reset to queued status.
 *
 * @param int $stale_seconds Age in seconds to consider stale (default 1800 = 30 min).
 * @return int Number of jobs unlocked.
 */
function wpllmseo_unlock_stale_jobs( $stale_seconds = 1800 ) {
	global $wpdb;
	
	$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
	
	$sql = $wpdb->prepare(
		"UPDATE {$jobs_table}
		SET locked = 0, locked_at = NULL, status = 'queued'
		WHERE locked = 1 
		  AND locked_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
		$stale_seconds
	);
	
	$wpdb->query( $sql );
	
	$count = $wpdb->rows_affected;
	
	if ( $count > 0 ) {
		wpllmseo_log( "Unlocked $count stale job(s)", 'warning' );
	}
	
	return $count;
}

/**
 * Handle job failure with retry logic
 *
 * Increments attempts, applies exponential backoff, or moves to dead-letter.
 *
 * @param int    $job_id       Job ID.
 * @param string $error_message Error message.
 * @return bool True on success.
 */
function wpllmseo_handle_job_failure( $job_id, $error_message ) {
	global $wpdb;
	
	$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
	$dead_table = $wpdb->prefix . 'wpllmseo_jobs_dead_letter';
	
	// Fetch current job state
	$job = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$jobs_table} WHERE id = %d", $job_id ),
		ARRAY_A
	);
	
	if ( ! $job ) {
		wpllmseo_log( "Cannot handle failure for non-existent job $job_id", 'error' );
		return false;
	}
	
	$attempts     = intval( $job['attempts'] ) + 1;
	$max_attempts = intval( $job['max_attempts'] );
	
	if ( $attempts >= $max_attempts ) {
		// Move to dead-letter queue
		$wpdb->insert(
			$dead_table,
			array(
				'original_job_id' => $job_id,
				'payload'         => $job['payload'],
				'reason'          => substr( $error_message, 0, 1000 ),
				'failed_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		
		// Mark job as failed
		$wpdb->update(
			$jobs_table,
			array(
				'status'     => 'failed',
				'locked'     => 0,
				'last_error' => substr( $error_message, 0, 1000 ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		
		wpllmseo_log( "Job $job_id failed permanently after $attempts attempts: $error_message", 'error' );
		
	} else {
		// Exponential backoff with jitter
		$base_delay = pow( 2, $attempts ); // 2, 4, 8, 16, 32 seconds...
		$jitter     = rand( 0, 1000 ) / 1000; // 0-1 second
		$delay      = $base_delay + $jitter;
		
		$next_run = gmdate( 'Y-m-d H:i:s', time() + (int) $delay );
		
		// Update job for retry
		$wpdb->update(
			$jobs_table,
			array(
				'attempts'   => $attempts,
				'status'     => 'queued',
				'locked'     => 0,
				'locked_at'  => null,
				'last_error' => substr( $error_message, 0, 1000 ),
				'run_after'  => $next_run,
			),
			array( 'id' => $job_id ),
			array( '%d', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		
		wpllmseo_log( "Job $job_id will retry in $delay seconds (attempt $attempts/$max_attempts)", 'warning' );
	}
	
	return true;
}

/**
 * Mark job as complete
 *
 * @param int $job_id Job ID.
 * @return bool True on success.
 */
function wpllmseo_complete_job( $job_id ) {
	global $wpdb;
	
	$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
	
	$result = $wpdb->update(
		$jobs_table,
		array(
			'status'    => 'completed',
			'locked'    => 0,
			'locked_at' => null,
		),
		array( 'id' => $job_id ),
		array( '%s', '%d', '%s' ),
		array( '%d' )
	);
	
	return $result !== false;
}

/**
 * Process job with transactional safety
 *
 * Wraps job execution in DB transaction to ensure atomicity.
 *
 * @param object $job          Job object.
 * @param callable $processor  Job processing function.
 * @return bool True on success, throws exception on failure.
 * @throws Exception On processing error.
 */
function wpllmseo_process_job_transactional( $job, $processor ) {
	global $wpdb;
	
	$wpdb->query( 'START TRANSACTION' );
	
	try {
		// Execute job processor
		$result = call_user_func( $processor, $job );
		
		if ( ! $result ) {
			throw new Exception( 'Job processor returned false' );
		}
		
		// Mark job complete
		wpllmseo_complete_job( $job->id );
		
		$wpdb->query( 'COMMIT' );
		
		return true;
		
	} catch ( Exception $e ) {
		$wpdb->query( 'ROLLBACK' );
		
		// Handle failure
		wpllmseo_handle_job_failure( $job->id, $e->getMessage() );
		
		throw $e; // Re-throw for worker awareness
	}
}

/**
 * Enterprise worker run loop
 *
 * Example integration for class-worker.php run() method.
 *
 * @param int $limit Maximum jobs to process.
 * @return array Processing results.
 */
function wpllmseo_worker_run_enterprise( $limit = 10 ) {
	$worker_id = gethostname() . ':' . getmypid();
	$processed = 0;
	$failed    = 0;
	
	wpllmseo_log( "Enterprise worker started (worker_id: $worker_id, limit: $limit)", 'info' );
	
	// Unlock stale jobs before starting
	wpllmseo_unlock_stale_jobs();
	
	while ( $processed < $limit ) {
		// Atomic claim
		$job = wpllmseo_claim_job_atomic( $worker_id );
		
		if ( ! $job ) {
			// No jobs available
			break;
		}
		
		wpllmseo_log( "Processing job {$job->id} (type: {$job->job_type})", 'info' );
		
		try {
			// Process with transaction (example - integrate with actual job runner)
			wpllmseo_process_job_transactional( $job, function( $job ) {
				// This would call your actual job processing logic
				$runner = new WPLLMSEO_Job_Runner();
				return $runner->execute( $job );
			});
			
			$processed++;
			wpllmseo_log( "Job {$job->id} completed successfully", 'info' );
			
		} catch ( Exception $e ) {
			$failed++;
			wpllmseo_log( "Job {$job->id} failed: " . $e->getMessage(), 'error' );
			// Failure already handled by transactional wrapper
		}
		
		// Small delay between jobs to avoid hammering
		usleep( 50000 ); // 50ms
	}
	
	wpllmseo_log( "Enterprise worker finished (processed: $processed, failed: $failed)", 'info' );
	
	return array(
		'success'   => true,
		'processed' => $processed,
		'failed'    => $failed,
	);
}

/**
 * Add job with deduplication
 *
 * Uses dedupe_key to prevent duplicate jobs.
 *
 * @param string $job_type   Job type.
 * @param array  $payload    Job payload.
 * @param string $dedupe_key Optional deduplication key.
 * @return int|false Job ID or false if duplicate exists.
 */
function wpllmseo_add_job_idempotent( $job_type, $payload = array(), $dedupe_key = null ) {
	global $wpdb;
	
	$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
	
	// Check for existing job with same dedupe_key
	if ( $dedupe_key ) {
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$jobs_table} 
				WHERE dedupe_key = %s 
				  AND status IN ('queued', 'running') 
				LIMIT 1",
				$dedupe_key
			)
		);
		
		if ( $existing ) {
			wpllmseo_log( "Skipping duplicate job (dedupe_key: $dedupe_key)", 'info' );
			return false;
		}
	}
	
	// Insert new job
	$post_id    = isset( $payload['post_id'] ) ? absint( $payload['post_id'] ) : null;
	$snippet_id = isset( $payload['snippet_id'] ) ? absint( $payload['snippet_id'] ) : null;
	
	$result = $wpdb->insert(
		$jobs_table,
		array(
			'job_type'     => sanitize_text_field( $job_type ),
			'post_id'      => $post_id,
			'snippet_id'   => $snippet_id,
			'payload'      => wp_json_encode( $payload ),
			'status'       => 'queued',
			'attempts'     => 0,
			'max_attempts' => 5,
			'locked'       => 0,
			'dedupe_key'   => $dedupe_key,
		),
		array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
	);
	
	return $result ? $wpdb->insert_id : false;
}
