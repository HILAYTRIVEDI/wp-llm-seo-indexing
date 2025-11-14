<?php
/**
 * Snippet REST API Class
 *
 * Handles REST API endpoints for snippet operations.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Snippet_REST
 */
class WPLLMSEO_Snippet_REST {

	/**
	 * REST namespace
	 *
	 * @var string
	 */
	private $namespace = 'wp-llmseo/v1';

	/**
	 * Snippets manager instance
	 *
	 * @var WPLLMSEO_Snippets
	 */
	private $snippets;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// Create or update snippet
		register_rest_route(
			$this->namespace,
			'/snippet',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_or_update_snippet' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'args'                => array(
					'post_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'snippet_text' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
				),
			)
		);

		// Delete snippet
		register_rest_route(
			$this->namespace,
			'/snippet/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_snippet' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Reindex snippet
		register_rest_route(
			$this->namespace,
			'/snippet/reindex/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reindex_snippet' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Get snippet by post ID
		register_rest_route(
			$this->namespace,
			'/snippet/post/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_snippet_by_post' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Create or update snippet
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function create_or_update_snippet( $request ) {
		try {
			$post_id      = $request->get_param( 'post_id' );
			$snippet_text = $request->get_param( 'snippet_text' );

			// Validate post exists
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error(
					'invalid_post',
					__( 'Invalid post ID.', 'wpllmseo' ),
					array( 'status' => 404 )
				);
			}

			// Validate snippet text
			if ( empty( trim( $snippet_text ) ) ) {
				return new WP_Error(
					'empty_snippet',
					__( 'Snippet text cannot be empty.', 'wpllmseo' ),
					array( 'status' => 400 )
				);
			}

			// Get snippets instance
			if ( ! $this->snippets ) {
				$this->snippets = new WPLLMSEO_Snippets();
			}

			$snippet_id = $this->snippets->create_snippet( $post_id, $snippet_text );

			if ( ! $snippet_id ) {
				wpllmseo_log( 'Failed to create snippet for post ' . $post_id, 'error' );
				return new WP_Error(
					'create_failed',
					__( 'Failed to create snippet.', 'wpllmseo' ),
					array( 'status' => 500 )
				);
			}

			$snippet = $this->snippets->get_snippet( $snippet_id );

			if ( ! $snippet ) {
				wpllmseo_log( 'Snippet created but could not be retrieved: ' . $snippet_id, 'error' );
				return new WP_Error(
					'retrieve_failed',
					__( 'Snippet created but could not be retrieved.', 'wpllmseo' ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array(
						'id'           => $snippet_id,
						'snippet_text' => $snippet->snippet_text,
						'snippet_hash' => $snippet->snippet_hash,
						'created_at'   => $snippet->created_at,
						'updated_at'   => $snippet->updated_at,
					),
					'message' => __( 'Snippet saved successfully.', 'wpllmseo' ),
				),
				200
			);
		} catch ( Exception $e ) {
			wpllmseo_log( 'Exception in create_or_update_snippet: ' . $e->getMessage(), 'error' );
			return new WP_Error(
				'exception',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete snippet
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function delete_snippet( $request ) {
		$snippet_id = $request->get_param( 'id' );

		if ( ! $this->snippets ) {
			$this->snippets = new WPLLMSEO_Snippets();
		}

		$snippet = $this->snippets->get_snippet( $snippet_id );
		if ( ! $snippet ) {
			return new WP_Error(
				'snippet_not_found',
				__( 'Snippet not found.', 'wpllmseo' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->snippets->delete_snippet( $snippet_id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete snippet.', 'wpllmseo' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Snippet deleted successfully.', 'wpllmseo' ),
			),
			200
		);
	}

	/**
	 * Reindex snippet
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function reindex_snippet( $request ) {
		$snippet_id = $request->get_param( 'id' );

		if ( ! $this->snippets ) {
			$this->snippets = new WPLLMSEO_Snippets();
		}

		$snippet = $this->snippets->get_snippet( $snippet_id );
		if ( ! $snippet ) {
			return new WP_Error(
				'snippet_not_found',
				__( 'Snippet not found.', 'wpllmseo' ),
				array( 'status' => 404 )
			);
		}

		// Trigger reindexing action
		do_action( 'wpllmseo_snippet_updated', $snippet_id, $snippet->post_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Snippet queued for reindexing.', 'wpllmseo' ),
			),
			200
		);
	}

	/**
	 * Get snippet by post ID
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_snippet_by_post( $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( ! $this->snippets ) {
			$this->snippets = new WPLLMSEO_Snippets();
		}

		$snippet = $this->snippets->get_snippet_by_post( $post_id );

		if ( ! $snippet ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'data'    => null,
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'id'           => $snippet->id,
					'post_id'      => $snippet->post_id,
					'snippet_text' => $snippet->snippet_text,
					'snippet_hash' => $snippet->snippet_hash,
					'created_at'   => $snippet->created_at,
					'updated_at'   => $snippet->updated_at,
				),
			),
			200
		);
	}

	/**
	 * Check if user can edit posts
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user can edit.
	 */
	public function check_edit_permission( $request ) {
		$post_id = $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Check if user can delete snippet
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user can delete.
	 */
	public function check_delete_permission( $request ) {
		if ( ! $this->snippets ) {
			$this->snippets = new WPLLMSEO_Snippets();
		}

		$snippet_id = $request->get_param( 'id' );
		$snippet    = $this->snippets->get_snippet( $snippet_id );

		if ( ! $snippet ) {
			return false;
		}

	return current_user_can( 'edit_post', $snippet->post_id ) || current_user_can( 'manage_options' );
}

/**
 * Check if user can manage snippets
 *
 * @param WP_REST_Request $request Request object.
 * @return bool True if user can manage.
 */
public function check_manage_permission( $request ) {
	return WPLLMSEO_Capabilities::rest_permission_callback();
}

/**
 * Check if user can read snippet
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if user can read.
	 */
	public function check_read_permission( $request ) {
		$post_id = $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id ) || current_user_can( 'read_post', $post_id );
	}
}
