<?php
/**
 * Dashboard screen.
 *
 * Main dashboard with overview statistics and charts.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load component files.
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/card.php';
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/chart.php';

// Get real dashboard stats
$dashboard = new WPLLMSEO_Dashboard();
$stats_data = $dashboard->get_all_stats();

// Calculate success rate from queue stats
$queue = new WPLLMSEO_Queue();
$queue_stats = $queue->get_stats();
$success_rate = $queue_stats['total'] > 0 
	? round( ( $queue_stats['completed'] / $queue_stats['total'] ) * 100, 1 ) . '%' 
	: '0%';
?>

<div class="wrap">
	<?php
	// Render page header using WordPress native markup
	wpllmseo_render_header(
		array(
			'title'       => __( 'Dashboard', 'wpllmseo' ),
			'description' => __( 'Overview of your AI SEO & Indexing performance', 'wpllmseo' ),
			'actions'     => array(
				array(
					'label' => __( 'Run Worker', 'wpllmseo' ),
					'url'   => '#',
					'class' => 'button-primary wpllmseo-run-worker',
					'icon'  => 'update',
				),
			),
		)
	);
	?>

	<!-- Stats Cards using Material UI design -->
	<div class="wpllmseo-stats-grid">
		<?php
		wpllmseo_render_card(
			array(
				'title'    => __( 'Indexed Posts', 'wpllmseo' ),
				'value'    => $stats_data['total_posts_indexed'],
				'icon'     => 'yes-alt',
				'color'    => 'primary',
				'trend'    => __( 'Posts with chunks or snippets', 'wpllmseo' ),
			)
		);

		wpllmseo_render_card(
			array(
				'title'    => __( 'Total Snippets', 'wpllmseo' ),
				'value'    => $stats_data['total_snippets'],
				'icon'     => 'media-code',
				'color'    => 'info',
			)
		);

		wpllmseo_render_card(
			array(
				'title'    => __( 'Total Chunks', 'wpllmseo' ),
				'value'    => $stats_data['total_chunks'],
				'icon'     => 'media-text',
				'color'    => 'secondary',
			)
		);

		wpllmseo_render_card(
			array(
				'title'    => __( 'Queue Length', 'wpllmseo' ),
				'value'    => $stats_data['queue_length'],
				'icon'     => 'clock',
				'color'    => 'warning',
			)
		);

		wpllmseo_render_card(
			array(
				'title' => __( 'Failed Jobs', 'wpllmseo' ),
				'value' => $stats_data['failed_jobs'],
				'icon'  => 'warning',
				'color' => 'error',
			)
		);
		
		// AI Sitemap Status Card
		$sitemap_url = home_url( '/ai-sitemap.jsonl' );
		wpllmseo_render_card(
			array(
				'title' => __( 'AI Sitemap', 'wpllmseo' ),
				'value' => $stats_data['sitemap_status']['is_stale'] ? __( 'Needs Regen', 'wpllmseo' ) : __( 'Up to date', 'wpllmseo' ),
				'icon'  => 'media-document',
				'color' => $stats_data['sitemap_status']['is_stale'] ? 'warning' : 'success',
				'trend' => '<a href="' . esc_url( $sitemap_url ) . '" target="_blank">' . __( 'View Sitemap', 'wpllmseo' ) . '</a>',
			)
		);
		?>
	</div>

	<!-- Charts Section -->
	<div class="wpllmseo-charts-section">
		<div class="wpllmseo-charts-grid">
			<!-- Daily Chunks Chart -->
			<div class="wpllmseo-card">
				<div class="wpllmseo-card-header">
					<h3 class="wpllmseo-card-title"><?php esc_html_e( 'Daily Indexed Chunks (Last 14 Days)', 'wpllmseo' ); ?></h3>
				</div>
				<div class="wpllmseo-card-body">
					<canvas id="daily-chunks-chart" style="height: 300px;"></canvas>
					<div class="wpllmseo-chart-loading" data-chart="chunks">
						<span class="spinner is-active"></span>
						<p><?php esc_html_e( 'Loading chart data...', 'wpllmseo' ); ?></p>
					</div>
				</div>
			</div>

			<!-- Daily RAG Queries Chart -->
			<div class="wpllmseo-card">
				<div class="wpllmseo-card-header">
					<h3 class="wpllmseo-card-title"><?php esc_html_e( 'RAG Queries Over Time (Last 14 Days)', 'wpllmseo' ); ?></h3>
				</div>
				<div class="wpllmseo-card-body">
					<canvas id="daily-rag-queries-chart" style="height: 300px;"></canvas>
					<div class="wpllmseo-chart-loading" data-chart="rag">
						<span class="spinner is-active"></span>
						<p><?php esc_html_e( 'Loading chart data...', 'wpllmseo' ); ?></p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Recent Activity -->
	<div class="wpllmseo-recent-activity">
		<div class="wpllmseo-card">
			<div class="wpllmseo-card-header">
				<h3 class="wpllmseo-card-title"><?php esc_html_e( 'Recent Activity', 'wpllmseo' ); ?></h3>
			</div>
			<div class="wpllmseo-card-body">
				<ul class="wpllmseo-activity-list">
					<?php
					// Get recent completed jobs from queue
					global $wpdb;
					$queue_table = $wpdb->prefix . 'wpllmseo_queue';
					$recent_jobs = $wpdb->get_results(
						"SELECT * FROM {$queue_table} 
						WHERE status = 'completed' 
						ORDER BY updated_at DESC 
						LIMIT 10"
					);

					if ( ! empty( $recent_jobs ) ) {
						foreach ( $recent_jobs as $job ) {
							$icon_map = array(
								'chunk_post' => 'dashicons-media-text',
								'embed_chunk' => 'dashicons-yes-alt',
								'embed_snippet' => 'dashicons-media-code',
								'regenerate_sitemap' => 'dashicons-update',
							);
							$icon = isset( $icon_map[ $job->job_type ] ) ? $icon_map[ $job->job_type ] : 'dashicons-yes';
							
							$job_data = json_decode( $job->payload, true );
							$post_id = isset( $job_data['post_id'] ) ? $job_data['post_id'] : $job->post_id;
							$post_title = $post_id ? get_the_title( $post_id ) : __( 'Unknown Post', 'wpllmseo' );
							
							$message_map = array(
								'chunk_post' => sprintf( __( 'Chunked post: %s', 'wpllmseo' ), $post_title ),
								'embed_chunk' => sprintf( __( 'Generated embedding for: %s', 'wpllmseo' ), $post_title ),
								'embed_snippet' => sprintf( __( 'Generated snippet embedding for: %s', 'wpllmseo' ), $post_title ),
								'regenerate_sitemap' => __( 'Regenerated AI sitemap', 'wpllmseo' ),
							);
							$message = isset( $message_map[ $job->job_type ] ) ? $message_map[ $job->job_type ] : $job->job_type;
							?>
							<li>
								<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
								<span><?php echo esc_html( $message ); ?></span>
								<time><?php echo esc_html( human_time_diff( strtotime( $job->updated_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'wpllmseo' ) ); ?></time>
							</li>
							<?php
						}
					} else {
						?>
						<li>
							<span class="dashicons dashicons-info"></span>
							<span><?php esc_html_e( 'No recent activity. Start indexing content to see activity here.', 'wpllmseo' ); ?></span>
						</li>
						<?php
					}
					?>
				</ul>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Run Worker button handler
	$('.wpllmseo-run-worker').on('click', function(e) {
		e.preventDefault();
		
		var $button = $(this);
		var originalText = $button.text();
		
		if ($button.prop('disabled')) {
			return;
		}
		
		$button.prop('disabled', true).text('<?php echo esc_js( __( 'Running...', 'wpllmseo' ) ); ?>');
		
		$.ajax({
			url: '<?php echo esc_js( rest_url( 'wp-llmseo/v1/run-worker' ) ); ?>',
			method: 'POST',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
			},
			success: function(response) {
				if (response.success) {
					alert(response.message);
					location.reload();
				} else {
					alert('<?php echo esc_js( __( 'Error running worker.', 'wpllmseo' ) ); ?>');
				}
			},
			error: function() {
				alert('<?php echo esc_js( __( 'Error running worker.', 'wpllmseo' ) ); ?>');
			},
			complete: function() {
				$button.prop('disabled', false).text(originalText);
			}
		});
	});
});
</script>
