<?php
/**
 * Dashboard Analytics Data Provider
 *
 * Provides analytics data for dashboard widgets and charts.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Dashboard
 *
 * Dashboard data provider with caching.
 */
class WPLLMSEO_Dashboard {

	/**
	 * Cache duration in seconds
	 *
	 * @var int
	 */
	private $cache_duration = 30;

	/**
	 * Get total indexed posts
	 *
	 * @return int Total posts with chunks or snippets.
	 */
	public function get_total_posts_indexed() {
		global $wpdb;

		$cache_key = 'wpllmseo_total_posts_indexed';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$chunks_table   = $wpdb->prefix . 'wpllmseo_chunks';
		$snippets_table = $wpdb->prefix . 'wpllmseo_snippets';

		// Get unique post IDs from both tables.
		$query = "SELECT COUNT(DISTINCT post_id) as total FROM (
			SELECT post_id FROM {$chunks_table}
			UNION
			SELECT post_id FROM {$snippets_table}
		) AS combined";

		$total = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total = $total ? (int) $total : 0;

		set_transient( $cache_key, $total, $this->cache_duration );

		return $total;
	}

	/**
	 * Get total snippets
	 *
	 * @return int Total snippets count.
	 */
	public function get_total_snippets() {
		global $wpdb;

		$cache_key = 'wpllmseo_total_snippets';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table = $wpdb->prefix . 'wpllmseo_snippets';
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total = $total ? (int) $total : 0;

		set_transient( $cache_key, $total, $this->cache_duration );

		return $total;
	}

	/**
	 * Get total chunks
	 *
	 * @return int Total chunks count.
	 */
	public function get_total_chunks() {
		global $wpdb;

		$cache_key = 'wpllmseo_total_chunks';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table = $wpdb->prefix . 'wpllmseo_chunks';
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total = $total ? (int) $total : 0;

		set_transient( $cache_key, $total, $this->cache_duration );

		return $total;
	}

	/**
	 * Get queue length
	 *
	 * @return int Queued jobs count.
	 */
	public function get_queue_length() {
		$queue = new WPLLMSEO_Queue();
		return $queue->get_queue_length();
	}

	/**
	 * Get failed jobs count
	 *
	 * @return int Failed jobs count.
	 */
	public function get_failed_jobs_count() {
		global $wpdb;

		$cache_key = 'wpllmseo_failed_jobs_count';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$table = $wpdb->prefix . 'wpllmseo_jobs';
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'failed'
			)
		);

		$count = $count ? (int) $count : 0;

		set_transient( $cache_key, $count, $this->cache_duration );

		return $count;
	}

	/**
	 * Get recent RAG query latency
	 *
	 * Average of last 20 queries from rag.log.
	 *
	 * @return float Average latency in ms.
	 */
	public function get_recent_rag_latency() {
		$cache_key = 'wpllmseo_rag_latency';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (float) $cached;
		}

		$log_file = WPLLMSEO_PLUGIN_DIR . 'var/logs/rag.log';

		if ( ! file_exists( $log_file ) ) {
			return 0.0;
		}

		$logger = new WPLLMSEO_Logger();
		$lines  = $logger->get_log( 'rag.log', 20 );

		$latencies = array();

		foreach ( $lines as $line ) {
			// Parse execution_time from log context.
			if ( preg_match( '/execution_time["\']:\s*["\']?([\d.]+)ms/', $line, $matches ) ) {
				$latencies[] = (float) $matches[1];
			}
		}

		$average = ! empty( $latencies ) ? array_sum( $latencies ) / count( $latencies ) : 0.0;

		set_transient( $cache_key, $average, 60 );

		return round( $average, 2 );
	}

	/**
	 * Get AI Sitemap status
	 *
	 * @return array Status data.
	 */
	public function get_sitemap_status() {
		$sitemap = new WPLLMSEO_AI_Sitemap();

		return array(
			'is_stale'       => $sitemap->is_cache_stale(),
			'last_generated' => $sitemap->get_last_generated(),
			'file_size'      => $sitemap->get_cache_size(),
		);
	}

	/**
	 * Get daily chunk statistics
	 *
	 * Last 14 days of chunk creation.
	 *
	 * @return array Array of dates and counts.
	 */
	public function get_daily_chunk_stats() {
		global $wpdb;

		$cache_key = 'wpllmseo_daily_chunk_stats';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$table = $wpdb->prefix . 'wpllmseo_chunks';

		// Get last 14 days.
		$days = 14;
		$data = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );

			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$date
				)
			);

			$data[] = array(
				'date'  => $date,
				'count' => $count ? (int) $count : 0,
			);
		}

		set_transient( $cache_key, $data, 60 );

		return $data;
	}

	/**
	 * Get daily RAG queries
	 *
	 * Parse rag.log for query counts by date.
	 *
	 * @return array Array of dates and counts.
	 */
	public function get_daily_rag_queries() {
		$cache_key = 'wpllmseo_daily_rag_queries';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$log_file = WPLLMSEO_PLUGIN_DIR . 'var/logs/rag.log';

		if ( ! file_exists( $log_file ) ) {
			return $this->get_empty_daily_data();
		}

		// Read entire log file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $log_file );
		$lines   = explode( "\n", $content );

		$counts = array();

		foreach ( $lines as $line ) {
			// Parse timestamp: [2025-11-14 10:30:45].
			if ( preg_match( '/\[(\d{4}-\d{2}-\d{2})/', $line, $matches ) ) {
				$date = $matches[1];
				if ( ! isset( $counts[ $date ] ) ) {
					$counts[ $date ] = 0;
				}
				$counts[ $date ]++;
			}
		}

		// Build last 14 days data.
		$data = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$date  = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$count = isset( $counts[ $date ] ) ? $counts[ $date ] : 0;

			$data[] = array(
				'date'  => $date,
				'count' => $count,
			);
		}

		set_transient( $cache_key, $data, 60 );

		return $data;
	}

	/**
	 * Get empty daily data structure
	 *
	 * @return array Empty 14-day data.
	 */
	private function get_empty_daily_data() {
		$data = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$data[] = array(
				'date'  => gmdate( 'Y-m-d', strtotime( "-{$i} days" ) ),
				'count' => 0,
			);
		}
		return $data;
	}

	/**
	 * Get all dashboard stats
	 *
	 * @return array Complete stats array.
	 */
	public function get_all_stats() {
		return array(
			'total_posts_indexed' => $this->get_total_posts_indexed(),
			'total_snippets'      => $this->get_total_snippets(),
			'total_chunks'        => $this->get_total_chunks(),
			'queue_length'        => $this->get_queue_length(),
			'failed_jobs'         => $this->get_failed_jobs_count(),
			'rag_latency'         => $this->get_recent_rag_latency(),
			'sitemap_status'      => $this->get_sitemap_status(),
		);
	}

	/**
	 * Get all chart data
	 *
	 * @return array Chart datasets.
	 */
	public function get_all_charts() {
		return array(
			'daily_chunks'      => $this->get_daily_chunk_stats(),
			'daily_rag_queries' => $this->get_daily_rag_queries(),
		);
	}
}
