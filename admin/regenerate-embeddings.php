<?php
/**
 * Regenerate All Embeddings
 * 
 * This script clears failed jobs and re-queues all posts for embedding.
 * Access: WP Admin → LLM SEO → Queue → "Regenerate All Embeddings" button
 * 
 * @package WPLLMSEO
 */

// Security check
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check permissions
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( __( 'You do not have permission to access this page.', 'wpllmseo' ) );
}

// Handle regeneration request
if ( isset( $_POST['wpllmseo_regenerate_all'] ) && check_admin_referer( 'wpllmseo_admin_action', 'wpllmseo_nonce' ) ) {
	global $wpdb;
	require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';

	$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
	if ( is_wp_error( $validated ) ) {
		wp_die( esc_html__( 'Queue table not found or invalid.', 'wpllmseo' ) );
	}

	$queue_table = $validated;

	// Clear all failed and pending jobs using prepared statement
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$queue_table} WHERE status IN (%s, %s)", 'failed', 'pending' ) );
	
	// Get all published posts
	$posts = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		)
	);
	
	$queue = new WPLLMSEO_Queue();
	$queued = 0;
	
	foreach ( $posts as $post ) {
		// Queue post for embedding
		$job_id = $queue->add(
			'embed_post',
			array( 'post_id' => $post->ID )
		);
		
		if ( $job_id ) {
			$queued++;
		}
	}
	
	// Clear semantic map cache
	delete_transient( 'wpllmseo_sitemap_semantic_map' );
	
	// Success message
	$message = sprintf(
		/* translators: 1: number of deleted jobs, 2: number of queued jobs */
		__( 'Cleared %1$d failed jobs and queued %2$d posts for embedding.', 'wpllmseo' ),
		$deleted,
		$queued
	);
	
	wp_redirect( add_query_arg( array( 'regenerated' => 'true', 'message' => urlencode( $message ) ), admin_url( 'admin.php?page=wpllmseo_queue' ) ) );
	exit;
}
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Regenerate All Embeddings', 'wpllmseo' ); ?></h1>
	
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'Warning:', 'wpllmseo' ); ?></strong>
			<?php esc_html_e( 'This will clear all failed and pending jobs from the queue and re-queue all published posts for embedding generation.', 'wpllmseo' ); ?>
		</p>
	</div>
	
	<div class="card">
		<h2><?php esc_html_e( 'Current Status', 'wpllmseo' ); ?></h2>
		<?php
		global $wpdb;
		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( 'wpllmseo_jobs' );
		$queue_table = is_wp_error( $validated ) ? $wpdb->prefix . 'wpllmseo_jobs' : $validated;

		$stats = array(
			'failed'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'failed' ) ),
			'pending' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'pending' ) ),
			'running' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'running' ) ),
		);
		
		$total_posts = wp_count_posts();
		?>
		
		<ul>
			<li><?php printf( esc_html__( 'Failed jobs: %d', 'wpllmseo' ), (int) $stats['failed'] ); ?></li>
			<li><?php printf( esc_html__( 'Pending jobs: %d', 'wpllmseo' ), (int) $stats['pending'] ); ?></li>
			<li><?php printf( esc_html__( 'Running jobs: %d', 'wpllmseo' ), (int) $stats['running'] ); ?></li>
			<li><?php printf( esc_html__( 'Total published posts: %d', 'wpllmseo' ), (int) $total_posts->publish ); ?></li>
		</ul>
	</div>
	
	<form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to regenerate all embeddings? This may take some time.', 'wpllmseo' ); ?>');">
		<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>
		
		<p>
			<input type="submit" 
			       name="wpllmseo_regenerate_all" 
			       class="button button-primary button-large" 
			       value="<?php esc_attr_e( 'Clear Failed Jobs & Regenerate All', 'wpllmseo' ); ?>" />
		</p>
	</form>
	
	<div class="card">
		<h2><?php esc_html_e( 'After Regeneration', 'wpllmseo' ); ?></h2>
		<p><?php esc_html_e( 'The background worker will automatically process the queue. You can monitor progress in the Queue screen.', 'wpllmseo' ); ?></p>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_queue' ) ); ?>" class="button">
				<?php esc_html_e( 'View Queue', 'wpllmseo' ); ?>
			</a>
		</p>
	</div>
</div>
