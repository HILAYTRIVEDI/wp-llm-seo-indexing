<?php
/**
 * Worker REST API Class
 *
 * Provides REST endpoints for worker management.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Worker_REST
 */
class WPLLMSEO_Worker_REST {

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
	 * Constructor
	 */
	public function __construct() {
		$this->worker = new WPLLMSEO_Worker();
		$this->queue = new WPLLMSEO_Queue();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes
	 */
	public function register_routes() {
		// Run worker endpoint
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/run-worker',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_worker' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		// Get queue stats
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/queue-stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_queue_stats' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		// Get worker status
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/worker-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_worker_status' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		// Force unlock worker
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/unlock-worker',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unlock_worker' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);
	}

	/**
	 * Run worker endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function run_worker( $request ) {
		$limit = $request->get_param( 'limit' ) ? absint( $request->get_param( 'limit' ) ) : 10;

		$result = $this->worker->run( $limit );

		return rest_ensure_response(
			array(
				'success' => $result['success'],
				'message' => $result['message'],
				'data'    => array(
					'processed' => $result['processed'],
					'failed'    => $result['failed'] ?? 0,
				),
			)
		);
	}

	/**
	 * Get queue stats endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_queue_stats( $request ) {
		$stats = $this->queue->get_stats();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $stats,
			)
		);
	}

	/**
	 * Get worker status endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_worker_status( $request ) {
		$status = $this->worker->get_status();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $status,
			)
		);
	}

	/**
	 * Unlock worker endpoint
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function unlock_worker( $request ) {
		$result = $this->worker->force_release_lock();

		return rest_ensure_response(
			array(
				'success' => $result,
				'message' => $result ? __( 'Worker lock released', 'wpllmseo' ) : __( 'Failed to release lock', 'wpllmseo' ),
			)
		);
	}

	/**
	 * Check manage options permission
	 *
	 * @return bool True if user has permission.
	 */
	public function check_manage_permission() {
		return current_user_can( 'manage_options' );
	}
}
