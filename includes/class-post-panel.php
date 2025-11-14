<?php
/**
 * Per-Post LLM Optimization Panel
 *
 * Meta box for post editor showing LLM optimization status and controls.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Post_Panel
 *
 * Adds LLM optimization panel to post editor.
 */
class WPLLMSEO_Post_Panel {

	/**
	 * Initialize meta box.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'wp_ajax_wpllmseo_regenerate_post', array( __CLASS__, 'ajax_regenerate' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add meta box to post editor.
	 */
	public static function add_meta_box() {
		$post_types = array( 'post', 'page' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'wpllmseo_optimization',
				__( 'LLM Optimization', 'wpllmseo' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render meta box content.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'wpllmseo_post_panel', 'wpllmseo_post_panel_nonce' );

		$css_file = WPLLMSEO_PLUGIN_DIR . 'admin/assets/css/post-panel.css';

		// Enqueue CSS
		wp_enqueue_style(
			'wpllmseo-post-panel',
			WPLLMSEO_PLUGIN_URL . 'admin/assets/css/post-panel.css',
			array(),
			file_exists( $css_file ) ? filemtime( $css_file ) : '1.0.0'
		);

		$status      = WPLLMSEO_Change_Tracker::get_indexing_status( $post->ID );
		$ai_snippet  = get_post_meta( $post->ID, '_wpllmseo_ai_snippet', true );
		$seo_meta    = WPLLMSEO_SEO_Compat::get_primary_seo_meta( $post->ID );
		
		?>
		<div class="wpllmseo-post-panel">
			<!-- Status Section -->
			<div class="wpllmseo-panel-section">
				<h4><?php esc_html_e( 'Indexing Status', 'wpllmseo' ); ?></h4>
				<p class="wpllmseo-status-badge wpllmseo-status-<?php echo esc_attr( $status['needs_reindex'] ? 'warning' : 'success' ); ?>">
					<span class="dashicons dashicons-<?php echo $status['needs_reindex'] ? 'warning' : 'yes-alt'; ?>"></span>
					<?php echo esc_html( $status['status_label'] ); ?>
				</p>
				
				<?php if ( $status['last_indexed'] ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: time ago */
							esc_html__( 'Last indexed: %s ago', 'wpllmseo' ),
							esc_html( human_time_diff( $status['last_indexed'], current_time( 'timestamp' ) ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<!-- SEO Source Section -->
			<div class="wpllmseo-panel-section">
				<h4><?php esc_html_e( 'SEO Meta Source', 'wpllmseo' ); ?></h4>
				<p>
					<?php
					switch ( $seo_meta['source'] ) {
						case 'yoast':
							echo '<span class="dashicons dashicons-admin-plugins"></span> ';
							esc_html_e( 'Using Yoast SEO', 'wpllmseo' );
							break;
						case 'rankmath':
							echo '<span class="dashicons dashicons-admin-plugins"></span> ';
							esc_html_e( 'Using Rank Math', 'wpllmseo' );
							break;
						default:
							echo '<span class="dashicons dashicons-wordpress"></span> ';
							esc_html_e( 'Using WordPress defaults', 'wpllmseo' );
							break;
					}
					?>
				</p>
			</div>

			<!-- AI Snippet Section -->
			<div class="wpllmseo-panel-section">
				<h4><?php esc_html_e( 'AI Snippet', 'wpllmseo' ); ?></h4>
				<?php if ( $ai_snippet ) : ?>
					<div class="wpllmseo-snippet-preview">
						<?php echo esc_html( wp_trim_words( $ai_snippet, 20 ) ); ?>
					</div>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'No AI snippet generated yet.', 'wpllmseo' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Embedding Info Section -->
			<div class="wpllmseo-panel-section">
				<h4><?php esc_html_e( 'Embedding Info', 'wpllmseo' ); ?></h4>
				<ul class="wpllmseo-info-list">
					<li>
						<?php esc_html_e( 'Has Embedding:', 'wpllmseo' ); ?>
						<strong><?php echo $status['has_embedding'] ? esc_html__( 'Yes', 'wpllmseo' ) : esc_html__( 'No', 'wpllmseo' ); ?></strong>
					</li>
					<li>
						<?php esc_html_e( 'Content Hash:', 'wpllmseo' ); ?>
						<code><?php echo $status['has_content_hash'] ? esc_html__( 'Set', 'wpllmseo' ) : esc_html__( 'Not set', 'wpllmseo' ); ?></code>
					</li>
				</ul>
			</div>

			<!-- Actions Section -->
			<div class="wpllmseo-panel-section">
				<button type="button" 
				        class="button button-secondary wpllmseo-regenerate-btn" 
				        data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Regenerate Embedding & Snippet', 'wpllmseo' ); ?>
				</button>
				<div class="wpllmseo-regenerate-status" style="display:none;"></div>
			</div>
		</div>

		<?php
	}

	/**
	 * Enqueue panel assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$js_file = WPLLMSEO_PLUGIN_DIR . 'admin/assets/js/post-panel.js';

		// Enqueue JS
		wp_enqueue_script(
			'wpllmseo-post-panel',
			WPLLMSEO_PLUGIN_URL . 'admin/assets/js/post-panel.js',
			array( 'jquery' ),
			file_exists( $js_file ) ? filemtime( $js_file ) : '1.0.0',
			true
		);

		// Localize script
		wp_localize_script(
			'wpllmseo-post-panel',
			'wpllmseoPostPanel',
			array(
				'nonce' => wp_create_nonce( 'wpllmseo_regenerate' ),
			)
		);
	}

	/**
	 * AJAX handler for regeneration.
	 */
	public static function ajax_regenerate() {
		// Verify nonce and return JSON error on failure
		$nonce_check = check_ajax_referer( 'wpllmseo_regenerate', 'nonce', false );
		if ( ! $nonce_check ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please refresh the page and try again.', 'wpllmseo' ),
				),
				403
			);
		}

		if ( ! WPLLMSEO_Capabilities::user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wpllmseo' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'wpllmseo' ) ), 400 );
		}

		// Generate snippet immediately using bulk generator.
		$result = WPLLMSEO_Bulk_Snippet_Generator::process_single_post( $post_id );
		
		if ( $result && isset( $result['success'] ) && $result['success'] ) {
			// Queue for embedding indexing.
			$queue = new WPLLMSEO_Queue();
			$job_id = $queue->add( 'embed_snippet', array( 'snippet_id' => $result['snippet_id'] ) );
			
			// Trigger worker to process immediately in background
			if ( $job_id && function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time(), 'wpllmseo_worker_event' );
			}
			
			wp_send_json_success(
				array(
					'message' => __( 'Snippet generated successfully! Embedding will be created shortly.', 'wpllmseo' ),
					'snippet' => array(
						'id'   => $result['snippet_id'],
						'text' => $result['snippet'],
					),
				)
			);
		} else {
			$error_message = isset( $result['error'] ) ? $result['error'] : __( 'Failed to generate snippet. Please check your API settings.', 'wpllmseo' );
			wp_send_json_error(
				array(
					'message' => $error_message,
				)
			);
		}
	}
}
