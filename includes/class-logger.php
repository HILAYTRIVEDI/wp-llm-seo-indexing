<?php
/**
 * Logger Class
 *
 * Handles logging for queue, worker, and error events.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Logger
 */
class WPLLMSEO_Logger {

	/**
	 * Log directory path
	 *
	 * @var string
	 */
	private $log_dir;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->log_dir = WPLLMSEO_PLUGIN_DIR . 'var/logs';
		$this->ensure_log_directory();
	}

	/**
	 * Ensure log directory exists
	 */
	private function ensure_log_directory() {
		if ( ! file_exists( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		// Protect logs directory with .htaccess
		$htaccess_file = $this->log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content = "Order deny,allow\nDeny from all";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess_file, $htaccess_content );
		}

		// Add index.php for extra protection
		$index_file = $this->log_dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index_file, '<?php // Silence is golden.' );
		}
	}

	/**
	 * Log an info message
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 * @param string $log_file Log file name (default: queue.log).
	 */
	public function info( $message, $context = array(), $log_file = 'queue.log' ) {
		$this->write_log( 'INFO', $message, $context, $log_file );
	}

	/**
	 * Log an error message
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 * @param string $log_file Log file name (default: errors.log).
	 */
	public function error( $message, $context = array(), $log_file = 'errors.log' ) {
		$this->write_log( 'ERROR', $message, $context, $log_file );
	}

	/**
	 * Log a debug message
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 * @param string $log_file Log file name (default: queue.log).
	 */
	public function debug( $message, $context = array(), $log_file = 'queue.log' ) {
		// Only log debug messages if WP_DEBUG is enabled
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$this->write_log( 'DEBUG', $message, $context, $log_file );
	}

	/**
	 * Log a warning message
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 * @param string $log_file Log file name (default: queue.log).
	 */
	public function warning( $message, $context = array(), $log_file = 'queue.log' ) {
		$this->write_log( 'WARNING', $message, $context, $log_file );
	}

	/**
	 * Write log entry to file
	 *
	 * @param string $level   Log level (INFO, ERROR, DEBUG, WARNING).
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 * @param string $log_file Log file name.
	 */
	private function write_log( $level, $message, $context, $log_file ) {
		$timestamp = current_time( 'Y-m-d H:i:s' );
		
		// Format context if provided
		$context_str = '';
		if ( ! empty( $context ) ) {
			$context_str = ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}

		$log_entry = sprintf(
			"[%s] [%s] %s%s\n",
			$timestamp,
			$level,
			$message,
			$context_str
		);

	$file_path = $this->log_dir . '/' . $log_file;

	// Rotate log file if it exceeds 2MB
	if ( file_exists( $file_path ) && filesize( $file_path ) > 2 * 1024 * 1024 ) {
			$this->rotate_log( $file_path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file_path, $log_entry, FILE_APPEND );
	}

	/**
	 * Rotate log file
	 *
	 * @param string $file_path Path to log file.
	 */
	private function rotate_log( $file_path ) {
		$backup_path = $file_path . '.' . time() . '.bak';
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename
		rename( $file_path, $backup_path );

		// Delete old backup files (keep only last 3)
		$dir = dirname( $file_path );
		$basename = basename( $file_path );
		$pattern = $dir . '/' . $basename . '.*.bak';
		
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$backups = glob( $pattern );
		if ( count( $backups ) > 3 ) {
			usort( $backups, function( $a, $b ) {
				return filemtime( $a ) - filemtime( $b );
			} );
			
			// Delete oldest backups
			$to_delete = array_slice( $backups, 0, count( $backups ) - 3 );
			foreach ( $to_delete as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
				unlink( $file );
			}
		}
	}

	/**
	 * Clear all log files
	 */
	public function clear_logs() {
		$log_files = array( 'queue.log', 'worker.log', 'errors.log', 'snippet.log' );
		
		foreach ( $log_files as $log_file ) {
			$file_path = $this->log_dir . '/' . $log_file;
			if ( file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
				unlink( $file_path );
			}
		}
	}

	/**
	 * Get log file contents
	 *
	 * @param string $log_file Log file name.
	 * @param int    $lines    Number of lines to retrieve (default: 100).
	 * @return string Log contents.
	 */
	public function get_log( $log_file = 'queue.log', $lines = 100 ) {
		$file_path = $this->log_dir . '/' . $log_file;
		
		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		// Read last N lines
		$file = new SplFileObject( $file_path, 'r' );
		$file->seek( PHP_INT_MAX );
		$total_lines = $file->key();
		$start_line = max( 0, $total_lines - $lines );

		$file->seek( $start_line );
		$log_content = '';
		
		while ( ! $file->eof() ) {
			$log_content .= $file->fgets();
		}

		return $log_content;
	}

	/**
	 * Test log writing (dry run)
	 *
	 * @return bool True on success, false on failure.
	 */
	public function test_logging() {
		$test_message = 'Test log entry at ' . current_time( 'Y-m-d H:i:s' );
		
		try {
			$this->info( $test_message, array( 'test' => true ), 'test.log' );
			
			$file_path = $this->log_dir . '/test.log';
			if ( file_exists( $file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
				unlink( $file_path );
				return true;
			}
			
			return false;
		} catch ( Exception $e ) {
			return false;
		}
	}
}
