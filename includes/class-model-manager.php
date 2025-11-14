<?php
/**
 * Model Version Management
 *
 * Track and manage different embedding model versions.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Model_Manager
 *
 * Manages embedding model versions and migrations.
 */
class WPLLMSEO_Model_Manager {

	/**
	 * Current model version key.
	 */
	const MODEL_VERSION_KEY = '_wpllmseo_model_version';

	/**
	 * Available models.
	 */
	const AVAILABLE_MODELS = array(
		'text-embedding-004' => array(
			'name'       => 'Gemini Text Embedding 004',
			'dimensions' => 768,
			'provider'   => 'Google',
			'cost_per_1k' => 0.00025,
		),
		'text-embedding-3-small' => array(
			'name'       => 'OpenAI Embedding Small',
			'dimensions' => 1536,
			'provider'   => 'OpenAI',
			'cost_per_1k' => 0.0001,
		),
		'text-embedding-3-large' => array(
			'name'       => 'OpenAI Embedding Large',
			'dimensions' => 3072,
			'provider'   => 'OpenAI',
			'cost_per_1k' => 0.0002,
		),
	);

	/**
	 * Initialize model manager.
	 */
	public static function init() {
		// AJAX handlers.
		add_action( 'wp_ajax_wpllmseo_switch_model', array( __CLASS__, 'ajax_switch_model' ) );
		add_action( 'wp_ajax_wpllmseo_migration_status', array( __CLASS__, 'ajax_migration_status' ) );

		// REST endpoints.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Admin notices.
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/models',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_models' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/models/switch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_switch_model' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'model' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/models/migrate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_migrate_embeddings' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Get current model version.
	 *
	 * @return string
	 */
	public static function get_current_model() {
		$settings = get_option( 'wpllmseo_settings', array() );
		return $settings['model'] ?? WPLLMSEO_GEMINI_MODEL;
	}

	/**
	 * Get model info.
	 *
	 * @param string $model_id Model ID.
	 * @return array|null
	 */
	public static function get_model_info( $model_id ) {
		return self::AVAILABLE_MODELS[ $model_id ] ?? null;
	}

	/**
	 * Get all posts with their model versions.
	 *
	 * @return array
	 */
	public static function get_version_distribution() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT meta_value as model, COUNT(*) as count 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = '_wpllmseo_model_version' 
			GROUP BY meta_value"
		);

		$distribution = array();
		foreach ( $results as $row ) {
			$distribution[ $row->model ] = intval( $row->count );
		}

		return $distribution;
	}

	/**
	 * Switch to new model.
	 *
	 * @param string $new_model New model ID.
	 * @return bool|WP_Error
	 */
	public static function switch_model( $new_model ) {
		if ( ! isset( self::AVAILABLE_MODELS[ $new_model ] ) ) {
			return new WP_Error( 'invalid_model', 'Invalid model selected.' );
		}

		$current_model = self::get_current_model();

		if ( $current_model === $new_model ) {
			return new WP_Error( 'same_model', 'Model is already active.' );
		}

		// Update settings.
		$settings          = get_option( 'wpllmseo_settings', array() );
		$settings['model'] = $new_model;
		update_option( 'wpllmseo_settings', $settings );

		// Create migration job.
		self::create_migration_job( $current_model, $new_model );

		return true;
	}

	/**
	 * Create migration job.
	 *
	 * @param string $from_model Old model.
	 * @param string $to_model   New model.
	 */
	private static function create_migration_job( $from_model, $to_model ) {
		global $wpdb;

		// Get all posts that need migration.
		$posts_to_migrate = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} 
				WHERE meta_key = %s AND meta_value = %s",
				self::MODEL_VERSION_KEY,
				$from_model
			)
		);

		// Store migration job.
		$job = array(
			'from_model'  => $from_model,
			'to_model'    => $to_model,
			'total_posts' => count( $posts_to_migrate ),
			'processed'   => 0,
			'failed'      => 0,
			'started_at'  => current_time( 'mysql' ),
			'status'      => 'pending',
			'post_ids'    => $posts_to_migrate,
		);

		update_option( 'wpllmseo_migration_job', $job );

		// Schedule background processing.
		if ( ! wp_next_scheduled( 'wpllmseo_process_migration' ) ) {
			wp_schedule_single_event( time() + 60, 'wpllmseo_process_migration' );
		}
	}

	/**
	 * Process migration batch.
	 *
	 * @param int $batch_size Batch size.
	 */
	public static function process_migration_batch( $batch_size = 5 ) {
		$job = get_option( 'wpllmseo_migration_job' );

		if ( ! $job || 'pending' !== $job['status'] ) {
			return;
		}

		$job['status'] = 'running';
		update_option( 'wpllmseo_migration_job', $job );

		$remaining_posts = array_slice( $job['post_ids'], $job['processed'] );
		$batch_posts     = array_slice( $remaining_posts, 0, $batch_size );

		foreach ( $batch_posts as $post_id ) {
			$result = self::migrate_post_embeddings( $post_id, $job['to_model'] );

			if ( is_wp_error( $result ) ) {
				$job['failed']++;
			} else {
				$job['processed']++;
			}
		}

		// Update job status.
		if ( $job['processed'] >= $job['total_posts'] ) {
			$job['status']      = 'completed';
			$job['completed_at'] = current_time( 'mysql' );
		}

		update_option( 'wpllmseo_migration_job', $job );

		// Schedule next batch if not complete.
		if ( 'completed' !== $job['status'] ) {
			wp_schedule_single_event( time() + 120, 'wpllmseo_process_migration' );
		}
	}

	/**
	 * Migrate post embeddings to new model.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $new_model New model.
	 * @return bool|WP_Error
	 */
	private static function migrate_post_embeddings( $post_id, $new_model ) {
		global $wpdb;
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		// Delete old embeddings.
		$wpdb->delete(
			$chunks_table,
			array( 'post_id' => $post_id )
		);

		// Re-queue post for indexing with new model.
		WPLLMSEO_Queue::add_item( $post_id, 'post' );

		// Update model version.
		update_post_meta( $post_id, self::MODEL_VERSION_KEY, $new_model );

		return true;
	}

	/**
	 * Get migration status.
	 *
	 * @return array
	 */
	public static function get_migration_status() {
		$job = get_option( 'wpllmseo_migration_job', array() );

		if ( empty( $job ) ) {
			return array(
				'active' => false,
			);
		}

		$progress = $job['total_posts'] > 0 ? round( ( $job['processed'] / $job['total_posts'] ) * 100 ) : 0;

		return array(
			'active'      => 'running' === $job['status'],
			'from_model'  => $job['from_model'],
			'to_model'    => $job['to_model'],
			'total'       => $job['total_posts'],
			'processed'   => $job['processed'],
			'failed'      => $job['failed'],
			'progress'    => $progress,
			'status'      => $job['status'],
			'started_at'  => $job['started_at'] ?? null,
			'completed_at' => $job['completed_at'] ?? null,
		);
	}

	/**
	 * Cancel migration.
	 */
	public static function cancel_migration() {
		delete_option( 'wpllmseo_migration_job' );
		wp_clear_scheduled_hook( 'wpllmseo_process_migration' );
	}

	/**
	 * REST: Get models.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_get_models( $request ) {
		$current_model = self::get_current_model();
		$distribution  = self::get_version_distribution();

		$models = array();
		foreach ( self::AVAILABLE_MODELS as $model_id => $info ) {
			$models[] = array(
				'id'          => $model_id,
				'name'        => $info['name'],
				'provider'    => $info['provider'],
				'dimensions'  => $info['dimensions'],
				'cost_per_1k' => $info['cost_per_1k'],
				'is_active'   => $model_id === $current_model,
				'post_count'  => $distribution[ $model_id ] ?? 0,
			);
		}

		return rest_ensure_response(
			array(
				'models'       => $models,
				'current'      => $current_model,
				'distribution' => $distribution,
			)
		);
	}

	/**
	 * REST: Switch model.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_switch_model( $request ) {
		$new_model = $request->get_param( 'model' );

		$result = self::switch_model( $new_model );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'Model switched successfully. Migration job started.',
			)
		);
	}

	/**
	 * REST: Migrate embeddings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_migrate_embeddings( $request ) {
		$status = self::get_migration_status();

		if ( $status['active'] ) {
			return rest_ensure_response( $status );
		}

		// Process a batch immediately.
		self::process_migration_batch( 10 );

		$status = self::get_migration_status();

		return rest_ensure_response( $status );
	}

	/**
	 * AJAX: Switch model.
	 */
	public static function ajax_switch_model() {
		check_ajax_referer( 'wpllmseo_model_manager', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$new_model = sanitize_text_field( $_POST['model'] ?? '' );

		$result = self::switch_model( $new_model );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Model switched successfully.' ) );
	}

	/**
	 * AJAX: Migration status.
	 */
	public static function ajax_migration_status() {
		check_ajax_referer( 'wpllmseo_model_manager', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$status = self::get_migration_status();
		wp_send_json_success( $status );
	}

	/**
	 * Admin notices.
	 */
	public static function admin_notices() {
		$status = self::get_migration_status();

		if ( ! $status['active'] ) {
			return;
		}

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Model Migration In Progress', 'wpllmseo' ); ?></strong><br>
				<?php
				printf(
					/* translators: %1$d: processed count, %2$d: total count, %3$d: progress percentage */
					esc_html__( 'Migrating embeddings: %1$d / %2$d posts (%3$d%%)', 'wpllmseo' ),
					$status['processed'],
					$status['total'],
					$status['progress']
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render model manager UI.
	 */
	public static function render_ui() {
		$current_model = self::get_current_model();
		$distribution  = self::get_version_distribution();
		$migration     = self::get_migration_status();

		?>
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Model Version Management', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Manage embedding model versions and migrate existing embeddings.', 'wpllmseo' ); ?>
			</p>

			<!-- Current Model -->
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Current Model', 'wpllmseo' ); ?></th>
						<td>
							<select id="wpllmseo-model-selector" class="regular-text">
								<?php foreach ( self::AVAILABLE_MODELS as $model_id => $info ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $current_model, $model_id ); ?>>
										<?php echo esc_html( $info['name'] . ' (' . $info['provider'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<button type="button" id="wpllmseo-switch-model" class="button" style="margin-left:10px;">
							<?php esc_html_e( 'Switch Model', 'wpllmseo' ); ?>
						</button>
						<p class="description">
							<?php
							$current_info = self::AVAILABLE_MODELS[ $current_model ] ?? null;
							if ( $current_info ) {
								printf(
									/* translators: %1$d: dimensions, %2$f: cost */
									esc_html__( 'Dimensions: %1$d | Cost: $%2$f per 1K tokens', 'wpllmseo' ),
									intval( $current_info['dimensions'] ),
									floatval( $current_info['cost_per_1k'] )
								);
							} else {
								esc_html_e( 'Model information not available. Configure provider in API Providers page.', 'wpllmseo' );
							}
							?>
						</p>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Model Distribution -->
			<h3><?php esc_html_e( 'Version Distribution', 'wpllmseo' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Model', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Posts', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::AVAILABLE_MODELS as $model_id => $info ) : ?>
						<tr>
							<td>
								<?php echo esc_html( $info['name'] ); ?>
								<?php if ( $model_id === $current_model ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $distribution[ $model_id ] ?? 0 ); ?></td>
							<td>
								<?php if ( $model_id !== $current_model && ! empty( $distribution[ $model_id ] ) ) : ?>
									<span style="color:#666;"><?php esc_html_e( 'Needs migration', 'wpllmseo' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Migration Status -->
			<?php if ( $migration['active'] ) : ?>
				<div id="wpllmseo-migration-status" style="margin-top:20px;padding:15px;background:#e8eaf6;border-left:3px solid #3f51b5;">
					<h4 style="margin-top:0;"><?php esc_html_e( 'Migration in Progress', 'wpllmseo' ); ?></h4>
					<p>
						<?php
						printf(
							/* translators: %1$s: from model, %2$s: to model */
							esc_html__( 'Migrating from %1$s to %2$s', 'wpllmseo' ),
							'<strong>' . esc_html( $migration['from_model'] ) . '</strong>',
							'<strong>' . esc_html( $migration['to_model'] ) . '</strong>'
						);
						?>
					</p>
					<div class="progress-bar" style="width:100%;height:20px;background:#ccc;border-radius:10px;overflow:hidden;">
						<div style="width:<?php echo esc_attr( $migration['progress'] ); ?>%;height:100%;background:#3f51b5;transition:width 0.3s;"></div>
					</div>
					<p style="margin-top:10px;">
						<?php
						printf(
							/* translators: %1$d: processed, %2$d: total, %3$d: progress */
							esc_html__( '%1$d / %2$d posts (%3$d%%)', 'wpllmseo' ),
							$migration['processed'],
							$migration['total'],
							$migration['progress']
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#wpllmseo-switch-model').on('click', function() {
				var model = $('#wpllmseo-model-selector').val();
				
				if (!confirm('Switch to ' + model + '? This will re-index all posts with the new model.')) {
					return;
				}

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'wpllmseo_switch_model',
						model: model,
						nonce: '<?php echo wp_create_nonce( 'wpllmseo_model_manager' ); ?>'
					},
					success: function(response) {
						if (response.success) {
							alert(response.data.message);
							location.reload();
						} else {
							alert(response.data.message || 'Error switching model.');
						}
					}
				});
			});
		});
		</script>
		<?php
	}
}
