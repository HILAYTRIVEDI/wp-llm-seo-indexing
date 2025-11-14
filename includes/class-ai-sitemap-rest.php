<?php
/**
 * AI Sitemap REST Handler
 *
 * Handles /ai-sitemap.jsonl endpoint with rate limiting.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_AI_Sitemap_REST
 *
 * Public JSONL endpoint with streaming.
 */
class WPLLMSEO_AI_Sitemap_REST {

	/**
	 * AI Sitemap instance
	 *
	 * @var WPLLMSEO_AI_Sitemap
	 */
	private $sitemap;

	/**
	 * Logger instance
	 *
	 * @var WPLLMSEO_Logger
	 */
	private $logger;

	/**
	 * Rate limit: requests per minute
	 *
	 * @var int
	 */
	private $rate_limit = 10;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->sitemap = new WPLLMSEO_AI_Sitemap();
		$this->logger  = new WPLLMSEO_Logger();

		// Register rewrite rules.
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );

		// Register query vars.
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );

		// Handle template redirect.
		add_action( 'template_redirect', array( $this, 'handle_sitemap_request' ) );

		// Register REST endpoint for regeneration.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Add rewrite rules
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^ai-sitemap\.jsonl$',
			'index.php?wpllmseo_ai_sitemap=1',
			'top'
		);
	}

	/**
	 * Add query vars
	 *
	 * @param array $vars Query vars.
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wpllmseo_ai_sitemap';
		return $vars;
	}

	/**
	 * Handle sitemap request
	 */
	public function handle_sitemap_request() {
		$is_sitemap = get_query_var( 'wpllmseo_ai_sitemap' );

		if ( ! $is_sitemap ) {
			return;
		}

		// Check if sitemap is enabled.
		$settings = get_option( 'wpllmseo_settings', array() );
		if ( empty( $settings['enable_ai_sitemap'] ) ) {
			wp_die( esc_html__( 'AI Sitemap is disabled.', 'wpllmseo' ), 403 );
		}

		// Check rate limit.
		if ( ! $this->check_rate_limit() ) {
			$this->send_rate_limit_error();
			exit;
		}

		// Regenerate if cache is stale.
		if ( $this->sitemap->is_cache_stale() ) {
			$this->sitemap->regenerate_cache();
		}

		// Set headers.
		header( 'Content-Type: application/jsonl; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		$cache_size = $this->sitemap->get_cache_size();
		if ( false !== $cache_size ) {
			header( 'Content-Length: ' . $cache_size );
		}

		// Support HTTP Range requests.
		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			$this->handle_range_request();
		} else {
			// Stream cached sitemap.
			$streamed = $this->sitemap->stream_cached_sitemap();

			if ( ! $streamed ) {
				// Fallback: generate on-the-fly.
				$this->sitemap->generate_jsonl( true );
			}
		}

		exit;
	}

	/**
	 * Handle HTTP Range requests
	 */
	private function handle_range_request() {
		$cache_file = WPLLMSEO_PLUGIN_DIR . 'var/cache/ai-sitemap.jsonl';

		if ( ! file_exists( $cache_file ) ) {
			return;
		}

		$file_size = filesize( $cache_file );
		$range     = sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) );

		// Parse range header.
		if ( preg_match( '/bytes=(\d+)-(\d*)/', $range, $matches ) ) {
			$start = (int) $matches[1];
			$end   = ! empty( $matches[2] ) ? (int) $matches[2] : $file_size - 1;

			header( 'HTTP/1.1 206 Partial Content' );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size );
			header( 'Content-Length: ' . ( $end - $start + 1 ) );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $cache_file, 'r' );
			fseek( $handle, $start ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek

			$bytes_to_read = $end - $start + 1;
			while ( $bytes_to_read > 0 && ! feof( $handle ) ) {
				$chunk_size = min( 8192, $bytes_to_read );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				echo fread( $handle, $chunk_size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				flush();
				$bytes_to_read -= $chunk_size;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}
	}

	/**
	 * Check rate limit
	 *
	 * @return bool True if within limit.
	 */
	private function check_rate_limit() {
		$ip            = $this->get_client_ip();
		$transient_key = 'wpllmseo_sitemap_rl_' . md5( $ip );

		// Get request count.
		$requests = get_transient( $transient_key );
		if ( false === $requests ) {
			$requests = 0;
		}

		// Check if exceeded.
		if ( $requests >= $this->rate_limit ) {
			return false;
		}

		// Increment and update.
		$requests++;
		set_transient( $transient_key, $requests, 60 );

		return true;
	}

	/**
	 * Send rate limit error
	 */
	private function send_rate_limit_error() {
		header( 'HTTP/1.1 429 Too Many Requests' );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Retry-After: 60' );

		$error = array(
			'error'   => 'rate_limit_exceeded',
			'message' => 'Rate limit exceeded. Maximum 10 requests per minute.',
		);

		echo wp_json_encode( $error );

		$this->logger->warning(
			'Rate limit exceeded for AI Sitemap',
			array( 'ip' => $this->get_client_ip() ),
			'ai-sitemap.log'
		);
	}

	/**
	 * Get client IP address
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$ip_list = explode( ',', $ip );
		$ip      = trim( $ip_list[0] );

		return $ip;
	}

	/**
	 * Register REST routes for regeneration
	 */
	public function register_rest_routes() {
		register_rest_route(
			'wp-llmseo/v1',
			'/sitemap/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_regenerate' ),
				'permission_callback' => array( 'WPLLMSEO_Capabilities', 'rest_permission_callback' ),
			)
		);

		register_rest_route(
			'wp-llmseo/v1',
			'/sitemap/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( 'WPLLMSEO_Capabilities', 'rest_permission_callback' ),
			)
		);
	}

	/**
	 * Handle regenerate request
	 *
	 * @return WP_REST_Response Response.
	 */
	public function handle_regenerate() {
		$result = $this->sitemap->regenerate_cache();

		if ( $result ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'AI Sitemap regenerated successfully.', 'wpllmseo' ),
					'data'    => array(
						'last_generated' => $this->sitemap->get_last_generated(),
						'file_size'      => $this->sitemap->get_cache_size(),
					),
				),
				200
			);
		} else {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Failed to regenerate AI Sitemap.', 'wpllmseo' ),
				),
				500
			);
		}
	}

	/**
	 * Handle status request
	 *
	 * @return WP_REST_Response Response.
	 */
	public function handle_status() {
		$last_generated = $this->sitemap->get_last_generated();
		$file_size      = $this->sitemap->get_cache_size();
		$is_stale       = $this->sitemap->is_cache_stale();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'last_generated'      => $last_generated,
					'last_generated_human' => $last_generated ? human_time_diff( $last_generated, current_time( 'timestamp' ) ) . ' ago' : 'Never',
					'file_size'           => $file_size,
					'file_size_human'     => $file_size ? size_format( $file_size ) : 'N/A',
					'is_stale'            => $is_stale,
					'status'              => $is_stale ? 'Needs Regeneration' : 'Up to date',
				),
			),
			200
		);
	}
}
