<?php
/**
 * Rate Limiter Class
 *
 * Implements rate limiting and quota management for API calls.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Rate_Limiter
 */
class WPLLMSEO_Rate_Limiter {

	/**
	 * Transient prefix for rate limit tracking
	 */
	const TRANSIENT_PREFIX = 'wpllmseo_rate_';

	/**
	 * Option key for quota tracking
	 */
	const QUOTA_KEY = 'wpllmseo_quota_usage';

	/**
	 * Maximum concurrent jobs default
	 */
	const MAX_CONCURRENT_JOBS = 3;

	/**
	 * Check if action is rate limited
	 *
	 * @param string $action Action identifier.
	 * @param int    $limit  Number of allowed actions per window.
	 * @param int    $window Time window in seconds.
	 * @return bool True if allowed, false if rate limited.
	 */
	public static function check( $action, $limit = 60, $window = 60 ) {
		$transient_key = self::TRANSIENT_PREFIX . md5( $action );
		$count = get_transient( $transient_key );

		if ( false === $count ) {
			set_transient( $transient_key, 1, $window );
			return true;
		}

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, $window );
		return true;
	}

	/**
	 * Increment quota usage
	 *
	 * @param string $provider Provider name.
	 * @param int    $tokens   Number of tokens used.
	 * @param float  $cost     Estimated cost.
	 * @return array Updated quota data.
	 */
	public static function increment_quota( $provider, $tokens = 0, $cost = 0.0 ) {
		$quota = get_option( self::QUOTA_KEY, array() );
		$date = gmdate( 'Y-m-d' );

		if ( ! isset( $quota[ $date ] ) ) {
			$quota[ $date ] = array();
		}

		if ( ! isset( $quota[ $date ][ $provider ] ) ) {
			$quota[ $date ][ $provider ] = array(
				'requests' => 0,
				'tokens'   => 0,
				'cost'     => 0.0,
			);
		}

		$quota[ $date ][ $provider ]['requests']++;
		$quota[ $date ][ $provider ]['tokens'] += $tokens;
		$quota[ $date ][ $provider ]['cost'] += $cost;

		// Keep only last 30 days
		$cutoff = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		foreach ( array_keys( $quota ) as $stored_date ) {
			if ( $stored_date < $cutoff ) {
				unset( $quota[ $stored_date ] );
			}
		}

		update_option( self::QUOTA_KEY, $quota, 'no' );

		return $quota[ $date ][ $provider ];
	}

	/**
	 * Get current quota usage
	 *
	 * @param string $provider Provider name (optional).
	 * @param string $period   Period: 'today', 'week', 'month'.
	 * @return array Quota usage data.
	 */
	public static function get_quota_usage( $provider = null, $period = 'today' ) {
		$quota = get_option( self::QUOTA_KEY, array() );
		
		$dates = array();
		switch ( $period ) {
			case 'week':
				for ( $i = 0; $i < 7; $i++ ) {
					$dates[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
				}
				break;
			case 'month':
				for ( $i = 0; $i < 30; $i++ ) {
					$dates[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
				}
				break;
			default:
				$dates[] = gmdate( 'Y-m-d' );
		}

		$usage = array(
			'requests' => 0,
			'tokens'   => 0,
			'cost'     => 0.0,
		);

		foreach ( $dates as $date ) {
			if ( ! isset( $quota[ $date ] ) ) {
				continue;
			}

			if ( $provider ) {
				if ( isset( $quota[ $date ][ $provider ] ) ) {
					$usage['requests'] += $quota[ $date ][ $provider ]['requests'];
					$usage['tokens'] += $quota[ $date ][ $provider ]['tokens'];
					$usage['cost'] += $quota[ $date ][ $provider ]['cost'];
				}
			} else {
				foreach ( $quota[ $date ] as $prov_data ) {
					$usage['requests'] += $prov_data['requests'];
					$usage['tokens'] += $prov_data['tokens'];
					$usage['cost'] += $prov_data['cost'];
				}
			}
		}

		return $usage;
	}

	/**
	 * Check if quota threshold exceeded
	 *
	 * @param float $threshold_cost Daily cost threshold.
	 * @return array Status with exceeded flag and current usage.
	 */
	public static function check_quota_threshold( $threshold_cost = 10.0 ) {
		$usage = self::get_quota_usage( null, 'today' );
		
		return array(
			'exceeded' => $usage['cost'] >= $threshold_cost,
			'usage'    => $usage,
			'threshold' => $threshold_cost,
			'remaining' => max( 0, $threshold_cost - $usage['cost'] ),
		);
	}

	/**
	 * Get concurrent job count
	 *
	 * @return int Number of currently running jobs.
	 */
	public static function get_concurrent_jobs() {
		global $wpdb;
		require_once __DIR__ . '/helpers/class-db-helpers.php';
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
		$table = is_wp_error( $validated ) ? $wpdb->prefix . 'wpllmseo_jobs' : $validated;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %s WHERE status = %s AND locked = %d", $table, 'processing', 1 ) );
	}

	/**
	 * Check if concurrent job limit reached
	 *
	 * @param int $max_concurrent Maximum allowed concurrent jobs.
	 * @return bool True if limit reached, false otherwise.
	 */
	public static function is_concurrent_limit_reached( $max_concurrent = null ) {
		if ( null === $max_concurrent ) {
			$max_concurrent = self::MAX_CONCURRENT_JOBS;
		}

		$current = self::get_concurrent_jobs();
		return $current >= $max_concurrent;
	}

	/**
	 * Reset rate limits (for testing)
	 *
	 * @return bool True on success.
	 */
	public static function reset() {
		global $wpdb;
		
		// Delete all rate limit transients
		require_once __DIR__ . '/helpers/class-db-helpers.php';
		// Use options table constant safely
		$options_table = $wpdb->options;
		$wpdb->query( $wpdb->prepare( "DELETE FROM %s WHERE option_name LIKE %s", $options_table, '_transient_' . self::TRANSIENT_PREFIX . '%' ) );
		
		// Clear quota
		delete_option( self::QUOTA_KEY );
		
		return true;
	}

	/**
	 * Get rate limit status for display
	 *
	 * @return array Status information.
	 */
	public static function get_status() {
		return array(
			'concurrent_jobs'   => self::get_concurrent_jobs(),
			'max_concurrent'    => self::MAX_CONCURRENT_JOBS,
			'quota_today'       => self::get_quota_usage( null, 'today' ),
			'quota_week'        => self::get_quota_usage( null, 'week' ),
			'quota_month'       => self::get_quota_usage( null, 'month' ),
		);
	}
}
