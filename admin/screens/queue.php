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
		'error'      => $item->error_message,
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

// Define row actions.
$actions = array(
	'view'   => array(
		'label' => __( 'View', 'wpllmseo' ),
		'url'   => function( $row ) {
			return '#view-' . $row['id'];
		},
	),
	'process' => array(
		'label' => __( 'Process', 'wpllmseo' ),
		'url'   => function( $row ) {
			return wp_nonce_url(
				admin_url( 'admin.php?page=wpllmseo_queue&action=process&item=' . $row['id'] ),
				'wpllmseo_process_item'
			);
		},
		'class' => 'wpllmseo-process-item',
		'data'  => true,
	),
	'remove' => array(
		'label' => __( 'Remove', 'wpllmseo' ),
		'url'   => function( $row ) {
			return wp_nonce_url(
				admin_url( 'admin.php?page=wpllmseo_queue&action=remove&item=' . $row['id'] ),
				'wpllmseo_remove_item'
			);
		},
		'class' => 'wpllmseo-remove-item',
		'data'  => true,
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
				'actions'  => $actions,
			)
		);
	}
	?>
</div>
