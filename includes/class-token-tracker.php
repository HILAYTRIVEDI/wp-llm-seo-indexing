<?php
/**
 * Token Usage Tracker Class
 *
 * Tracks and limits token usage to optimize costs and prevent excessive API calls.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Token_Tracker
 */
class WPLLMSEO_Token_Tracker {

	/**
	 * Option name for token usage tracking
	 *
	 * @var string
	 */
	private $option_name = 'wpllmseo_token_usage';

	/**
	 * Daily token limit
	 *
	 * @var int
	 */
	private $daily_limit = 100000; // 100k tokens per day by default

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
		$this->logger = new WPLLMSEO_Logger();
		
		// Allow configuration of daily limit
		$settings = get_option( 'wpllmseo_settings', array() );
		if ( isset( $settings['daily_token_limit'] ) ) {
			$this->daily_limit = absint( $settings['daily_token_limit'] );
		}
	}

	/**
	 * Check if we can use tokens
	 *
	 * @param int $estimated_tokens Estimated tokens for the operation.
	 * @return bool True if within limit, false otherwise.
	 */
	public function can_use_tokens( $estimated_tokens = 0 ) {
		$usage = $this->get_daily_usage();
		
		// Check if adding this would exceed the limit
		if ( $usage['tokens_used'] + $estimated_tokens > $this->daily_limit ) {
			$this->logger->warning(
				sprintf( 'Daily token limit would be exceeded. Current: %d, Estimated: %d, Limit: %d',
					$usage['tokens_used'],
					$estimated_tokens,
					$this->daily_limit
				),
				array(),
				'token-usage.log'
			);
			return false;
		}

		return true;
	}

	/**
	 * Record token usage
	 *
	 * @param int    $tokens      Number of tokens used.
	 * @param string $operation   Operation description.
	 * @param array  $context     Additional context.
	 * @return bool True on success, false on failure.
	 */
	public function record_usage( $tokens, $operation = '', $context = array() ) {
		$usage = $this->get_daily_usage();
		
		// Reset if it's a new day
		if ( $usage['date'] !== gmdate( 'Y-m-d' ) ) {
			$usage = array(
				'date'        => gmdate( 'Y-m-d' ),
				'tokens_used' => 0,
				'operations'  => array(),
			);
		}

		$usage['tokens_used'] += absint( $tokens );
		$usage['operations'][] = array(
			'time'      => current_time( 'mysql' ),
			'tokens'    => absint( $tokens ),
			'operation' => $operation,
			'context'   => $context,
		);

		// Keep only last 100 operations to prevent option from growing too large
		if ( count( $usage['operations'] ) > 100 ) {
			$usage['operations'] = array_slice( $usage['operations'], -100 );
		}

		update_option( $this->option_name, $usage, false );

		$this->logger->info(
			sprintf( 'Token usage recorded: %d tokens for %s (Total today: %d/%d)',
				$tokens,
				$operation,
				$usage['tokens_used'],
				$this->daily_limit
			),
			$context,
			'token-usage.log'
		);

		return true;
	}

	/**
	 * Get daily usage statistics
	 *
	 * @return array Usage statistics.
	 */
	public function get_daily_usage() {
		$usage = get_option( $this->option_name, array() );

		// Initialize if empty or reset if new day
		if ( empty( $usage ) || ! isset( $usage['date'] ) || $usage['date'] !== gmdate( 'Y-m-d' ) ) {
			$usage = array(
				'date'        => gmdate( 'Y-m-d' ),
				'tokens_used' => 0,
				'operations'  => array(),
			);
		}

		return $usage;
	}

	/**
	 * Get remaining tokens for today
	 *
	 * @return int Remaining tokens.
	 */
	public function get_remaining_tokens() {
		$usage = $this->get_daily_usage();
		return max( 0, $this->daily_limit - $usage['tokens_used'] );
	}

	/**
	 * Get percentage of daily limit used
	 *
	 * @return float Percentage used (0-100).
	 */
	public function get_usage_percentage() {
		$usage = $this->get_daily_usage();
		
		if ( $this->daily_limit === 0 ) {
			return 0;
		}

		return ( $usage['tokens_used'] / $this->daily_limit ) * 100;
	}

	/**
	 * Reset daily usage (for testing or admin override)
	 *
	 * @return bool True on success.
	 */
	public function reset_daily_usage() {
		$usage = array(
			'date'        => gmdate( 'Y-m-d' ),
			'tokens_used' => 0,
			'operations'  => array(),
		);

		update_option( $this->option_name, $usage, false );

		$this->logger->info(
			'Token usage reset',
			array(),
			'token-usage.log'
		);

		return true;
	}

	/**
	 * Estimate tokens for text
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	public function estimate_tokens( $text ) {
		// Rough estimation: ~1 token per 4 characters for English text
		// This is a conservative estimate for most LLM tokenizers
		return (int) ceil( strlen( $text ) / 4 );
	}
}
