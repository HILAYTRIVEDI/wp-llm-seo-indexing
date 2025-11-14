<?php
/**
 * Logs Reader and Sanitizer
 *
 * Provides secure log file reading with sensitive data redaction.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Logs
 *
 * Log file reader with sanitization.
 */
class WPLLMSEO_Logs {

	/**
	 * Log directory path
	 *
	 * @var string
	 */
	private $log_dir;

	/**
	 * Available log files
	 *
	 * @var array
	 */
	private $available_logs = array(
		'worker.log',
		'queue.log',
		'ai-sitemap.log',
		'rag.log',
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->log_dir = WPLLMSEO_PLUGIN_DIR . 'var/logs/';
	}

	/**
	 * Read log file
	 *
	 * @param string $log_name Log filename.
	 * @param int    $lines    Number of lines to read.
	 * @return array Log lines.
	 */
	public function read_log( $log_name, $lines = 500 ) {
		// Validate log name.
		if ( ! in_array( $log_name, $this->available_logs, true ) ) {
			return array();
		}

		$log_path = $this->log_dir . $log_name;

		if ( ! file_exists( $log_path ) ) {
			return array();
		}

		$logger   = new WPLLMSEO_Logger();
		$raw_logs = $logger->get_log( $log_name, $lines );

		// Convert string to array of lines.
		if ( empty( $raw_logs ) ) {
			return array();
		}
		
		$log_lines = explode( "\n", trim( $raw_logs ) );
		
		// Sanitize each line.
		return array_map( array( $this, 'sanitize_log_line' ), $log_lines );
	}

	/**
	 * Sanitize log line
	 *
	 * @param string $line Log line.
	 * @return string Sanitized line.
	 */
	public function sanitize_log_line( $line ) {
		// Redact sensitive data.
		$line = $this->redact_sensitive_data( $line );

		// Escape HTML.
		return esc_html( $line );
	}

	/**
	 * Redact sensitive data
	 *
	 * @param string $line Log line.
	 * @return string Redacted line.
	 */
	private function redact_sensitive_data( $line ) {
		// Redact embedding arrays.
		$line = preg_replace(
			'/embedding["\']:\s*\[[\d\s,.-]+\]/',
			'embedding: [REDACTED]',
			$line
		);

		// Redact API keys.
		$line = preg_replace(
			'/gemini_api_key["\']:\s*["\'][^"\']+["\']/',
			'gemini_api_key: "[REDACTED]"',
			$line
		);

		// Redact any AIza... API keys.
		$line = preg_replace(
			'/AIza[a-zA-Z0-9_-]+/',
			'AIza***REDACTED***',
			$line
		);

		return $line;
	}

	/**
	 * Get log statistics
	 *
	 * @param string $log_name Log filename.
	 * @return array Stats array.
	 */
	public function get_log_stats( $log_name ) {
		$lines = $this->read_log( $log_name, 1000 );

		$stats = array(
			'total'   => count( $lines ),
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
		);

		foreach ( $lines as $line ) {
			if ( stripos( $line, '[ERROR]' ) !== false ) {
				$stats['error']++;
			} elseif ( stripos( $line, '[WARNING]' ) !== false ) {
				$stats['warning']++;
			} elseif ( stripos( $line, '[INFO]' ) !== false ) {
				$stats['info']++;
			}
		}

		return $stats;
	}

	/**
	 * Get available logs
	 *
	 * @return array Available log files with metadata.
	 */
	public function get_available_logs() {
		$logs = array();

		foreach ( $this->available_logs as $log_name ) {
			$log_path = $this->log_dir . $log_name;

			$logs[] = array(
				'name'   => $log_name,
				'exists' => file_exists( $log_path ),
				'size'   => file_exists( $log_path ) ? filesize( $log_path ) : 0,
				'mtime'  => file_exists( $log_path ) ? filemtime( $log_path ) : 0,
			);
		}

		return $logs;
	}

	/**
	 * Download log file
	 *
	 * Streams sanitized log file for download.
	 *
	 * @param string $log_name Log filename.
	 */
	public function download_log( $log_name ) {
		// Validate log name.
		if ( ! in_array( $log_name, $this->available_logs, true ) ) {
			wp_die( esc_html__( 'Invalid log file.', 'wpllmseo' ) );
		}

		$log_path = $this->log_dir . $log_name;

		if ( ! file_exists( $log_path ) ) {
			wp_die( esc_html__( 'Log file not found.', 'wpllmseo' ) );
		}

		// Read and sanitize all lines.
		$lines = $this->read_log( $log_name, 50000 );

		// Set headers.
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $log_name . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Output sanitized content.
		echo implode( "\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Check if errors.log has recent errors
	 *
	 * @return bool True if errors within 24 hours.
	 */
	public function has_recent_errors() {
		$log_path = $this->log_dir . 'errors.log';

		if ( ! file_exists( $log_path ) ) {
			return false;
		}

		$mtime = filemtime( $log_path );

		// Check if modified within last 24 hours.
		return ( time() - $mtime ) < ( 24 * 60 * 60 );
	}

	/**
	 * Search log file
	 *
	 * @param string $log_name Log filename.
	 * @param string $search   Search query.
	 * @param int    $limit    Max results.
	 * @return array Matching lines.
	 */
	public function search_log( $log_name, $search, $limit = 100 ) {
		$lines = $this->read_log( $log_name, 5000 );

		if ( empty( $search ) ) {
			return array_slice( $lines, 0, $limit );
		}

		$results = array();
		$search  = strtolower( $search );

		foreach ( $lines as $line ) {
			if ( stripos( $line, $search ) !== false ) {
				$results[] = $line;

				if ( count( $results ) >= $limit ) {
					break;
				}
			}
		}

		return $results;
	}
}
