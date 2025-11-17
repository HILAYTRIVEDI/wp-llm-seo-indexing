<?php
/**
 * Queue screen.
 *
 * Displays the indexing queue with pending and completed items.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load component files.
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/table.php';

// Handle bulk actions
if ( isset( $_POST['wpllmseo_clear_failed_regenerate'] ) && check_admin_referer( 'wpllmseo_admin_action', 'wpllmseo_nonce' ) ) {
	global $wpdb;
	require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';
	$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
	if ( is_wp_error( $validated ) ) {
		wp_die( esc_html__( 'Queue table not found or invalid.', 'wpllmseo' ) );
	}

	$queue_table = $validated;

	// Clear all failed jobs
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$queue_table} WHERE status = %s", 'failed' ) );

	// Clear all pending jobs to avoid duplicates
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue_table} WHERE status = %s", 'pending' ) );
	
	// Get all published posts
	$posts = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		)
	);
	
	$queue_obj = new WPLLMSEO_Queue();
	$queued = 0;
	
	foreach ( $posts as $post ) {
		$job_id = $queue_obj->add(
			'embed_post',
			array( 'post_id' => $post->ID )
		);
		if ( $job_id ) {
			$queued++;
		}
	}
	
	// Clear semantic map cache
	delete_transient( 'wpllmseo_sitemap_semantic_map' );
	
	echo '<div class="notice notice-success is-dismissible"><p>';
	printf(
		/* translators: 1: deleted count, 2: queued count */
		esc_html__( 'Cleared %1$d failed jobs and queued %2$d posts for re-indexing.', 'wpllmseo' ),
		(int) $deleted,
		(int) $queued
	);
	echo '</p></div>';
}

// Handle remove duplicates action
if ( isset( $_POST['wpllmseo_remove_duplicates'] ) && check_admin_referer( 'wpllmseo_admin_action', 'wpllmseo_nonce' ) ) {
	$queue_obj = new WPLLMSEO_Queue();
	$removed = $queue_obj->remove_duplicate_jobs();
	
	echo '<div class="notice notice-success is-dismissible"><p>';
	printf(
		/* translators: %d: number of duplicate jobs removed */
		esc_html__( 'Removed %d duplicate jobs from the queue.', 'wpllmseo' ),
		(int) $removed
	);
	echo '</p></div>';
}

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( 'Index Queue', 'wpllmseo' ),
		'description' => __( 'Manage and monitor your content indexing queue', 'wpllmseo' ),
		'actions'     => array(
			array(
				'label' => __( 'Process Queue', 'wpllmseo' ),
				'url'   => '#',
				'class' => 'button-primary wpllmseo-run-worker',
				'icon'  => 'update',
			),
			array(
				'label' => __( 'Clear Completed', 'wpllmseo' ),
				'url'   => '#',
				'class' => 'button-secondary wpllmseo-clear-completed',
				'icon'  => 'trash',
			),
		),
	)
);

// Get real queue data
$queue = new WPLLMSEO_Queue();
$queue_stats = $queue->get_stats();
$queue_items_raw = $queue->get_all_items( 100 ); // Get last 100 items

// Transform to display format
$queue_items = array();
foreach ( $queue_items_raw as $item ) {
	$job_data = json_decode( $item->payload, true );
	$post_id = isset( $job_data['post_id'] ) ? $job_data['post_id'] : $item->post_id;
	$post_title = $post_id ? get_the_title( $post_id ) : __( 'N/A', 'wpllmseo' );
	
	// Map job types to readable names
	$type_map = array(
		'chunk_post' => __( 'Post Chunking', 'wpllmseo' ),
		'embed_chunk' => __( 'Chunk Embedding', 'wpllmseo' ),
		'embed_snippet' => __( 'Snippet Embedding', 'wpllmseo' ),
		'regenerate_sitemap' => __( 'Sitemap Regen', 'wpllmseo' ),
	);
	$type_display = isset( $type_map[ $item->job_type ] ) ? $type_map[ $item->job_type ] : $item->job_type;
	
	// Map status
	$status_map = array(
		'queued' => 'Pending',
		'processing' => 'Processing',
		'completed' => 'Completed',
		'failed' => 'Failed',
	);
	$status_display = isset( $status_map[ $item->status ] ) ? $status_map[ $item->status ] : $item->status;
	
	// Determine priority (simplified - could be enhanced)
	$priority = 'Medium';
	if ( $item->job_type === 'regenerate_sitemap' ) {
		$priority = 'Low';
	} elseif ( $item->attempts > 1 ) {
		$priority = 'High';
	}
	
	$queue_items[] = array(
		'id'         => $item->id,
		'title'      => $post_title . ' (' . $type_display . ')',
		'type'       => $type_display,
		'status'     => $status_display,
		'priority'   => $priority,
		'added'      => $item->created_at,
		'post_id'    => $post_id,
		'job_type'   => $item->job_type,
		'attempts'   => $item->attempts,
		'error'      => isset( $item->error_message ) ? $item->error_message : '',
	);
}

// Define table columns.
$columns = array(
	array(
		'key'      => 'title',
		'label'    => __( 'Title', 'wpllmseo' ),
		'sortable' => true,
	),
	array(
		'key'      => 'type',
		'label'    => __( 'Type', 'wpllmseo' ),
		'sortable' => true,
	),
	array(
		'key'      => 'status',
		'label'    => __( 'Status', 'wpllmseo' ),
		'sortable' => true,
		'format'   => function( $value ) {
			$class_map = array(
				'Pending'    => 'warning',
				'Processing' => 'info',
				'Completed'  => 'success',
				'Failed'     => 'error',
			);
			$class = isset( $class_map[ $value ] ) ? $class_map[ $value ] : 'default';
			return '<span class="wpllmseo-badge wpllmseo-badge-' . esc_attr( $class ) . '">' . esc_html( $value ) . '</span>';
		},
	),
	array(
		'key'      => 'priority',
		'label'    => __( 'Priority', 'wpllmseo' ),
		'sortable' => true,
	),
	array(
		'key'      => 'added',
		'label'    => __( 'Added', 'wpllmseo' ),
		'sortable' => true,
		'format'   => function( $value ) {
			return human_time_diff( strtotime( $value ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wpllmseo' );
		},
	),
);
?>

<div class="wpllmseo-queue">
	<!-- Queue Stats -->
	<div class="wpllmseo-queue-stats">
		<div class="wpllmseo-stat-item">
			<span class="wpllmseo-stat-value"><?php echo esc_html( $queue_stats['queued'] ); ?></span>
			<span class="wpllmseo-stat-label"><?php esc_html_e( 'Pending', 'wpllmseo' ); ?></span>
		</div>
		<div class="wpllmseo-stat-item">
			<span class="wpllmseo-stat-value"><?php echo esc_html( $queue_stats['processing'] ); ?></span>
			<span class="wpllmseo-stat-label"><?php esc_html_e( 'Processing', 'wpllmseo' ); ?></span>
		</div>
		<div class="wpllmseo-stat-item">
			<span class="wpllmseo-stat-value"><?php echo esc_html( $queue_stats['completed'] ); ?></span>
			<span class="wpllmseo-stat-label"><?php esc_html_e( 'Completed', 'wpllmseo' ); ?></span>
		</div>
		<div class="wpllmseo-stat-item">
			<span class="wpllmseo-stat-value"><?php echo esc_html( $queue_stats['failed'] ); ?></span>
			<span class="wpllmseo-stat-label"><?php esc_html_e( 'Failed', 'wpllmseo' ); ?></span>
		</div>
	</div>

	<?php if ( $queue_stats['failed'] > 0 ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'Failed Jobs Detected', 'wpllmseo' ); ?></strong><br>
				<?php
				printf(
					/* translators: %d: number of failed jobs */
					esc_html( _n( '%d job has failed.', '%d jobs have failed.', $queue_stats['failed'], 'wpllmseo' ) ),
					(int) $queue_stats['failed']
				);
				echo ' ';
				esc_html_e( 'This is likely due to API configuration issues. Clear failed jobs and regenerate after fixing your API settings.', 'wpllmseo' );
				?>
			</p>
			<form method="post" style="margin-top: 10px;" onsubmit="return confirm('<?php esc_attr_e( 'This will clear all failed jobs and re-queue all published posts. Continue?', 'wpllmseo' ); ?>');">
				<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>
				<button type="submit" name="wpllmseo_clear_failed_regenerate" class="button button-primary">
					<span class="dashicons dashicons-update-alt" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Clear Failed & Regenerate All', 'wpllmseo' ); ?>
				</button>
			</form>
		</div>
	<?php endif; ?>

	<!-- Duplicate Jobs Notice -->
	<div class="notice notice-info inline" style="margin-top: 20px;">
		<p>
			<strong><?php esc_html_e( 'Queue Maintenance', 'wpllmseo' ); ?></strong><br>
			<?php esc_html_e( 'If you see duplicate jobs in the queue, click below to remove them. The oldest job for each unique item will be kept.', 'wpllmseo' ); ?>
		</p>
		<form method="post" style="margin-top: 10px;">
			<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>
			<button type="submit" name="wpllmseo_remove_duplicates" class="button button-secondary">
				<span class="dashicons dashicons-admin-generic" style="margin-top: 3px;"></span>
				<?php esc_html_e( 'Remove Duplicate Jobs', 'wpllmseo' ); ?>
			</button>
		</form>
	</div>

	<!-- Queue Table -->
	<?php
	if ( empty( $queue_items ) ) {
		?>
		<div class="wpllmseo-card">
			<div class="wpllmseo-card-body">
				<p><?php esc_html_e( 'No items in queue. Add posts to start indexing!', 'wpllmseo' ); ?></p>
			</div>
		</div>
		<?php
	} else {
		wpllmseo_render_table(
			array(
				'columns'  => $columns,
				'rows'     => $queue_items,
				'id'       => 'queue-table',
				'sortable' => true,
			)
		);
	}
	?>
</div>
