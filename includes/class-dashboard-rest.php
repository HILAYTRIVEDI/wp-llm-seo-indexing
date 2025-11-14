<?php
/**
 * Dashboard REST API Endpoints
 *
 * Provides REST endpoints for dashboard analytics data.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Dashboard_REST
 *
 * Dashboard REST API with caching.
 */
class WPLLMSEO_Dashboard_REST {

	/**
	 * Dashboard instance
	 *
	 * @var WPLLMSEO_Dashboard
	 */
	private $dashboard;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->dashboard = new WPLLMSEO_Dashboard();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public function register_routes() {
		register_rest_route(
			'wp-llmseo/v1',
			'/dashboard/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'wp-llmseo/v1',
			'/dashboard/charts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_charts' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'wp-llmseo/v1',
			'/dashboard/sitemap',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_sitemap_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check permission
	 *
	 * @return bool True if user can manage plugin.
	 */
	public function check_permission() {
		return WPLLMSEO_Capabilities::rest_permission_callback();
	}	/**
	 * Get dashboard stats
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_stats( $request ) {
		$start_time = microtime( true );

		$stats = $this->dashboard->get_all_stats();

		$execution_time = ( microtime( true ) - $start_time ) * 1000;

		return new WP_REST_Response(
			array(
				'success'        => true,
				'data'           => $stats,
				'execution_time' => round( $execution_time, 2 ) . 'ms',
			),
			200
		);
	}

	/**
	 * Get chart data
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_charts( $request ) {
		$start_time = microtime( true );

		$charts = $this->dashboard->get_all_charts();

		$execution_time = ( microtime( true ) - $start_time ) * 1000;

		return new WP_REST_Response(
			array(
				'success'        => true,
				'data'           => $charts,
				'execution_time' => round( $execution_time, 2 ) . 'ms',
			),
			200
		);
	}

	/**
	 * Get sitemap status
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_sitemap_status( $request ) {
		$status = $this->dashboard->get_sitemap_status();

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $status,
			),
			200
		);
	}
}
