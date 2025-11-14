<?php
/**
 * MCP Audit Logging
 *
 * Logs all MCP invocations for security auditing and debugging.
 * Stores minimal, non-sensitive metadata.
 *
 * @package WP_LLM_SEO_Indexing
 * @since 1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_MCP_Audit
 *
 * Handles MCP audit logging.
 */
class WPLLMSEO_MCP_Audit {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private static $table_name = null;

	/**
	 * Initialize
	 */
	public static function init() {
		global $wpdb;
		self::$table_name = $wpdb->prefix . 'wpllmseo_mcp_logs';

		// Create table on activation
		self::maybe_create_table();
	}

	/**
	 * Create audit log table
	 */
	public static function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS " . self::$table_name . " (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
			ability_name VARCHAR(100) NOT NULL,
			caller_id VARCHAR(100),
			user_id BIGINT(20),
			args_hash CHAR(64),
			job_id VARCHAR(100),
			status VARCHAR(20) DEFAULT 'success',
			error_message TEXT,
			ip_address VARCHAR(45),
			user_agent VARCHAR(255),
			execution_time INT,
			INDEX idx_timestamp (timestamp),
			INDEX idx_ability (ability_name),
			INDEX idx_caller (caller_id),
			INDEX idx_user (user_id),
			INDEX idx_status (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		wpllmseo_log( 'MCP audit log table created', 'info' );
	}

	/**
	 * Maybe create table if it doesn't exist
	 */
	private static function maybe_create_table() {
		global $wpdb;

		// Check if table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				self::$table_name
			)
		);

		if ( $table_exists !== self::$table_name ) {
			self::create_table();
		}
	}

	/**
	 * Log MCP request
	 *
	 * @param string $ability_name Ability name.
	 * @param array  $args         Request arguments (will be hashed).
	 * @param array  $context      MCP context with auth info.
	 * @return int|false Log ID or false on failure.
	 */
	public static function log_request( $ability_name, $args, $context ) {
		global $wpdb;

		// Extract caller info from context
		$caller_id = $context['caller_id'] ?? 'unknown';
		$user_id   = $context['user_id'] ?? 0;

		// Hash args (don't store actual args to avoid leaking sensitive data)
		$args_hash = self::hash_args( $args );

		// Get IP address
		$ip_address = self::get_client_ip();

		// Get user agent
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

		// Insert log entry
		$result = $wpdb->insert(
			self::$table_name,
			array(
				'ability_name' => $ability_name,
				'caller_id'    => $caller_id,
				'user_id'      => $user_id,
				'args_hash'    => $args_hash,
				'ip_address'   => $ip_address,
				'user_agent'   => substr( $user_agent, 0, 255 ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $result === false ) {
			wpllmseo_log( 'Failed to insert MCP audit log', 'error' );
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Log job created event
	 *
	 * @param string $job_id       Job ID.
	 * @param string $job_type     Job type.
	 * @param int    $queued_count Number of items queued.
	 * @param array  $context      MCP context.
	 */
	public static function log_job_created( $job_id, $job_type, $queued_count, $context ) {
		global $wpdb;

		$caller_id = $context['caller_id'] ?? 'unknown';
		$user_id   = $context['user_id'] ?? 0;

		$wpdb->insert(
			self::$table_name,
			array(
				'ability_name' => 'job.created',
				'caller_id'    => $caller_id,
				'user_id'      => $user_id,
				'job_id'       => $job_id,
				'args_hash'    => hash( 'sha256', $job_type . ':' . $queued_count ),
				'ip_address'   => self::get_client_ip(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Update log entry with result
	 *
	 * @param int    $log_id         Log entry ID.
	 * @param string $status         Status (success/error).
	 * @param string $error_message  Error message if failed.
	 * @param int    $execution_time Execution time in ms.
	 */
	public static function update_log( $log_id, $status, $error_message = null, $execution_time = null ) {
		global $wpdb;

		$data = array(
			'status' => $status,
		);

		$format = array( '%s' );

		if ( $error_message !== null ) {
			$data['error_message'] = $error_message;
			$format[]              = '%s';
		}

		if ( $execution_time !== null ) {
			$data['execution_time'] = $execution_time;
			$format[]               = '%d';
		}

		$wpdb->update(
			self::$table_name,
			$data,
			array( 'id' => $log_id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Get recent logs
	 *
	 * @param int $limit     Number of logs to retrieve.
	 * @param int $offset    Offset for pagination.
	 * @param array $filters Filters (ability_name, caller_id, status).
	 * @return array
	 */
	public static function get_logs( $limit = 50, $offset = 0, $filters = array() ) {
		global $wpdb;

		$where = array( '1=1' );
		$params = array();

		// Apply filters
		if ( ! empty( $filters['ability_name'] ) ) {
			$where[]  = 'ability_name = %s';
			$params[] = $filters['ability_name'];
		}

		if ( ! empty( $filters['caller_id'] ) ) {
			$where[]  = 'caller_id = %s';
			$params[] = $filters['caller_id'];
		}

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = $filters['user_id'];
		}

		$where_clause = implode( ' AND ', $where );

		// Build query
		$sql = "SELECT * FROM " . self::$table_name . "
				WHERE {$where_clause}
				ORDER BY timestamp DESC
				LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get log statistics
	 *
	 * @param string $period Period (hour, day, week, month).
	 * @return array
	 */
	public static function get_stats( $period = 'day' ) {
		global $wpdb;

		// Calculate time range
		$intervals = array(
			'hour'  => 'INTERVAL 1 HOUR',
			'day'   => 'INTERVAL 1 DAY',
			'week'  => 'INTERVAL 7 DAY',
			'month' => 'INTERVAL 30 DAY',
		);

		$interval = $intervals[ $period ] ?? $intervals['day'];

		// Total requests
		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::$table_name . "
			WHERE timestamp >= DATE_SUB(NOW(), {$interval})"
		);

		// By ability
		$by_ability = $wpdb->get_results(
			"SELECT ability_name, COUNT(*) as count
			FROM " . self::$table_name . "
			WHERE timestamp >= DATE_SUB(NOW(), {$interval})
			GROUP BY ability_name
			ORDER BY count DESC",
			ARRAY_A
		);

		// By status
		$by_status = $wpdb->get_results(
			"SELECT status, COUNT(*) as count
			FROM " . self::$table_name . "
			WHERE timestamp >= DATE_SUB(NOW(), {$interval})
			GROUP BY status",
			ARRAY_A
		);

		// Unique callers
		$unique_callers = $wpdb->get_var(
			"SELECT COUNT(DISTINCT caller_id) FROM " . self::$table_name . "
			WHERE timestamp >= DATE_SUB(NOW(), {$interval})"
		);

		// Average execution time
		$avg_exec_time = $wpdb->get_var(
			"SELECT AVG(execution_time) FROM " . self::$table_name . "
			WHERE timestamp >= DATE_SUB(NOW(), {$interval})
			AND execution_time IS NOT NULL"
		);

		return array(
			'total_requests'  => (int) $total,
			'by_ability'      => $by_ability,
			'by_status'       => $by_status,
			'unique_callers'  => (int) $unique_callers,
			'avg_exec_time'   => round( (float) $avg_exec_time, 2 ),
			'period'          => $period,
		);
	}

	/**
	 * Clean up old logs
	 *
	 * @param int $days Number of days to keep.
	 * @return int Number of deleted rows.
	 */
	public static function cleanup_old_logs( $days = 90 ) {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . self::$table_name . "
				WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		wpllmseo_log( "Cleaned up {$deleted} old MCP audit logs", 'info' );

		return $deleted;
	}

	/**
	 * Hash arguments array
	 *
	 * @param array $args Arguments to hash.
	 * @return string SHA-256 hash.
	 */
	private static function hash_args( $args ) {
		// Remove sensitive fields before hashing
		$safe_args = $args;
		$sensitive_keys = array( 'api_key', 'token', 'password', 'secret' );

		foreach ( $sensitive_keys as $key ) {
			if ( isset( $safe_args[ $key ] ) ) {
				$safe_args[ $key ] = '[REDACTED]';
			}
		}

		return hash( 'sha256', wp_json_encode( $safe_args ) );
	}

	/**
	 * Get client IP address
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		// Validate IP
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		return 'unknown';
	}

	/**
	 * Get table name
	 *
	 * @return string
	 */
	public static function get_table_name() {
		return self::$table_name;
	}
}

// Initialize
WPLLMSEO_MCP_Audit::init();
