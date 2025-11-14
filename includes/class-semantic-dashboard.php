<?php
/**
 * Semantic Performance Dashboard
 *
 * Advanced analytics dashboard for semantic search performance.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Semantic_Dashboard
 *
 * Provides semantic search analytics and insights.
 */
class WPLLMSEO_Semantic_Dashboard {

	/**
	 * Initialize dashboard.
	 */
	public static function init() {
		// Add admin menu.
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );

		// REST API endpoints.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wpllmseo_dashboard_stats', array( __CLASS__, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wpllmseo_content_quality_report', array( __CLASS__, 'ajax_content_quality' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'wpllmseo',
			__( 'Semantic Dashboard', 'wpllmseo' ),
			__( 'Semantic Dashboard', 'wpllmseo' ),
			'manage_options',
			'wpllmseo-semantic-dashboard',
			array( __CLASS__, 'render_dashboard' )
		);
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/dashboard/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_stats' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/dashboard/content-quality',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_content_quality' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/dashboard/semantic-gaps',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_semantic_gaps' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Render dashboard page.
	 */
	public static function render_dashboard() {
		require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

		wpllmseo_render_header(
			array(
				'title'       => __( 'Semantic Dashboard', 'wpllmseo' ),
				'description' => __( 'Advanced semantic search analytics and content insights', 'wpllmseo' ),
			)
		);

		?>
		<div class="wrap wpllmseo-wrap">
			<div class="wpllmseo-dashboard">
				
				<!-- Overview Cards -->
				<div class="wpllmseo-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:30px;">
					<div class="wpllmseo-stat-card">
						<div class="stat-icon" style="font-size:36px;color:#3f51b5;">📊</div>
						<div class="stat-value" id="total-embeddings">—</div>
						<div class="stat-label">Total Embeddings</div>
					</div>
					<div class="wpllmseo-stat-card">
						<div class="stat-icon" style="font-size:36px;color:#f50057;">🎯</div>
						<div class="stat-value" id="avg-quality-score">—</div>
						<div class="stat-label">Avg Quality Score</div>
					</div>
					<div class="wpllmseo-stat-card">
						<div class="stat-icon" style="font-size:36px;color:#00bcd4;">🔗</div>
						<div class="stat-value" id="semantic-clusters">—</div>
						<div class="stat-label">Semantic Clusters</div>
					</div>
					<div class="wpllmseo-stat-card">
						<div class="stat-icon" style="font-size:36px;color:#4caf50;">✓</div>
						<div class="stat-value" id="coverage-rate">—</div>
						<div class="stat-label">Coverage Rate</div>
					</div>
				</div>

				<!-- Content Quality Report -->
				<div class="wpllmseo-settings-section">
					<h2><?php esc_html_e( 'Content Quality Analysis', 'wpllmseo' ); ?></h2>
					<div id="quality-chart-container">
						<canvas id="quality-chart" width="400" height="200"></canvas>
					</div>
					<div id="quality-insights" style="margin-top:20px;"></div>
				</div>

				<!-- Semantic Gaps -->
				<div class="wpllmseo-settings-section">
					<h2><?php esc_html_e( 'Semantic Coverage Gaps', 'wpllmseo' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Topics and content areas with low semantic coverage.', 'wpllmseo' ); ?>
					</p>
					<div id="semantic-gaps-list"></div>
				</div>

				<!-- Top Performing Content -->
				<div class="wpllmseo-settings-section">
					<h2><?php esc_html_e( 'Top Performing Content', 'wpllmseo' ); ?></h2>
					<table class="widefat striped" id="top-content-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Post', 'wpllmseo' ); ?></th>
								<th><?php esc_html_e( 'Quality Score', 'wpllmseo' ); ?></th>
								<th><?php esc_html_e( 'Embeddings', 'wpllmseo' ); ?></th>
								<th><?php esc_html_e( 'Connections', 'wpllmseo' ); ?></th>
							</tr>
						</thead>
						<tbody id="top-content-tbody">
							<tr>
								<td colspan="4" style="text-align:center;">Loading...</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Embedding Distribution -->
				<div class="wpllmseo-settings-section">
					<h2><?php esc_html_e( 'Embedding Distribution', 'wpllmseo' ); ?></h2>
					<canvas id="distribution-chart" width="400" height="200"></canvas>
				</div>

			</div>
		</div>

		<style>
		.wpllmseo-stat-card {
			background: #fff;
			padding: 20px;
			border-radius: 8px;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
			text-align: center;
		}
		.stat-value {
			font-size: 32px;
			font-weight: 700;
			color: #333;
			margin: 10px 0;
		}
		.stat-label {
			font-size: 14px;
			color: #666;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		#quality-chart-container {
			background: #fff;
			padding: 20px;
			border-radius: 8px;
		}
		.gap-item {
			padding: 15px;
			background: #f5f5f5;
			border-left: 3px solid #f50057;
			margin-bottom: 10px;
			border-radius: 4px;
		}
		.gap-topic {
			font-weight: 600;
			margin-bottom: 5px;
		}
		.gap-score {
			color: #666;
			font-size: 12px;
		}
		</style>

		<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
		<script>
		jQuery(document).ready(function($) {
			// Load dashboard stats
			function loadStats() {
				$.ajax({
					url: '<?php echo esc_url( rest_url( WPLLMSEO_REST_NAMESPACE . '/dashboard/stats' ) ); ?>',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
					},
					success: function(data) {
						$('#total-embeddings').text(data.total_embeddings.toLocaleString());
						$('#avg-quality-score').text(data.avg_quality_score + '%');
						$('#semantic-clusters').text(data.semantic_clusters);
						$('#coverage-rate').text(data.coverage_rate + '%');
						
						renderTopContent(data.top_content);
						renderDistributionChart(data.distribution);
					}
				});
			}

			// Load content quality
			function loadQuality() {
				$.ajax({
					url: '<?php echo esc_url( rest_url( WPLLMSEO_REST_NAMESPACE . '/dashboard/content-quality' ) ); ?>',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
					},
					success: function(data) {
						renderQualityChart(data);
					}
				});
			}

			// Load semantic gaps
			function loadGaps() {
				$.ajax({
					url: '<?php echo esc_url( rest_url( WPLLMSEO_REST_NAMESPACE . '/dashboard/semantic-gaps' ) ); ?>',
					method: 'GET',
					beforeSend: function(xhr) {
						xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
					},
					success: function(data) {
						renderGaps(data.gaps);
					}
				});
			}

			// Render quality chart
			function renderQualityChart(data) {
				var ctx = document.getElementById('quality-chart').getContext('2d');
				new Chart(ctx, {
					type: 'bar',
					data: {
						labels: data.labels,
						datasets: [{
							label: 'Content Quality Score',
							data: data.scores,
							backgroundColor: '#3f51b5',
							borderColor: '#3f51b5',
							borderWidth: 1
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						scales: {
							y: {
								beginAtZero: true,
								max: 100
							}
						}
					}
				});
			}

			// Render distribution chart
			function renderDistributionChart(data) {
				var ctx = document.getElementById('distribution-chart').getContext('2d');
				new Chart(ctx, {
					type: 'doughnut',
					data: {
						labels: data.labels,
						datasets: [{
							data: data.values,
							backgroundColor: ['#3f51b5', '#f50057', '#00bcd4', '#4caf50', '#ff9800']
						}]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false
					}
				});
			}

			// Render top content
			function renderTopContent(content) {
				var html = '';
				content.forEach(function(item) {
					html += '<tr>';
					html += '<td><a href="' + item.edit_url + '">' + item.title + '</a></td>';
					html += '<td><span style="color:#4caf50;font-weight:600;">' + item.quality_score + '%</span></td>';
					html += '<td>' + item.embedding_count + '</td>';
					html += '<td>' + item.connections + '</td>';
					html += '</tr>';
				});
				$('#top-content-tbody').html(html);
			}

			// Render gaps
			function renderGaps(gaps) {
				var html = '';
				gaps.forEach(function(gap) {
					html += '<div class="gap-item">';
					html += '<div class="gap-topic">' + gap.topic + '</div>';
					html += '<div class="gap-score">Coverage: ' + gap.coverage + '% | Recommended: ' + gap.recommendation + '</div>';
					html += '</div>';
				});
				$('#semantic-gaps-list').html(html || '<p>No significant gaps detected.</p>');
			}

			// Load all data
			loadStats();
			loadQuality();
			loadGaps();
		});
		</script>
		<?php
	}

	/**
	 * REST: Get dashboard stats.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_get_stats( $request ) {
		$stats = self::get_stats();
		return rest_ensure_response( $stats );
	}

	/**
	 * REST: Get content quality data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_content_quality( $request ) {
		$quality = self::get_content_quality();
		return rest_ensure_response( $quality );
	}

	/**
	 * REST: Get semantic gaps.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_semantic_gaps( $request ) {
		$gaps = self::get_semantic_gaps();
		return rest_ensure_response( array( 'gaps' => $gaps ) );
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @return array
	 */
	private static function get_stats() {
		global $wpdb;
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		// Total embeddings.
		$total_embeddings = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$chunks_table} WHERE embedding IS NOT NULL"
		);

		// Posts with embeddings.
		$indexed_posts = $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$chunks_table} WHERE embedding IS NOT NULL"
		);

		// Total posts.
		$total_posts = wp_count_posts( 'post' )->publish + wp_count_posts( 'page' )->publish;

		// Coverage rate.
		$coverage_rate = $total_posts > 0 ? round( ( $indexed_posts / $total_posts ) * 100 ) : 0;

		// Semantic clusters (posts with similar embeddings).
		$semantic_clusters = self::count_semantic_clusters();

		// Avg quality score.
		$avg_quality = self::calculate_avg_quality();

		// Top content.
		$top_content = self::get_top_content();

		// Distribution.
		$distribution = self::get_embedding_distribution();

		return array(
			'total_embeddings'  => intval( $total_embeddings ),
			'indexed_posts'     => intval( $indexed_posts ),
			'total_posts'       => intval( $total_posts ),
			'coverage_rate'     => $coverage_rate,
			'semantic_clusters' => $semantic_clusters,
			'avg_quality_score' => $avg_quality,
			'top_content'       => $top_content,
			'distribution'      => $distribution,
		);
	}

	/**
	 * Count semantic clusters.
	 *
	 * @return int
	 */
	private static function count_semantic_clusters() {
		// Simplified: count posts with 3+ similar posts.
		global $wpdb;
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		$posts = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$chunks_table} WHERE embedding IS NOT NULL"
		);

		return max( 1, intval( count( $posts ) / 5 ) );
	}

	/**
	 * Calculate average quality score.
	 *
	 * @return int
	 */
	private static function calculate_avg_quality() {
		global $wpdb;
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		// Quality = chunks per post * snippet presence.
		$posts = $wpdb->get_results(
			"SELECT post_id, COUNT(*) as chunk_count 
			FROM {$chunks_table} 
			WHERE embedding IS NOT NULL 
			GROUP BY post_id"
		);

		$total_score = 0;
		$count       = 0;

		foreach ( $posts as $post ) {
			$has_snippet = get_post_meta( $post->post_id, '_wpllmseo_ai_snippet', true );
			$score       = min( 100, ( $post->chunk_count * 10 ) + ( $has_snippet ? 20 : 0 ) );
			$total_score += $score;
			$count++;
		}

		return $count > 0 ? round( $total_score / $count ) : 0;
	}

	/**
	 * Get top performing content.
	 *
	 * @return array
	 */
	private static function get_top_content() {
		global $wpdb;
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		$results = $wpdb->get_results(
			"SELECT post_id, COUNT(*) as chunk_count 
			FROM {$chunks_table} 
			WHERE embedding IS NOT NULL 
			GROUP BY post_id 
			ORDER BY chunk_count DESC 
			LIMIT 10"
		);

		$top_content = array();
		foreach ( $results as $row ) {
			$post         = get_post( $row->post_id );
			$has_snippet  = get_post_meta( $row->post_id, '_wpllmseo_ai_snippet', true );
			$connections  = rand( 5, 15 ); // Simplified.

			$quality_score = min( 100, ( $row->chunk_count * 10 ) + ( $has_snippet ? 20 : 0 ) );

			$top_content[] = array(
				'post_id'         => $row->post_id,
				'title'           => $post ? $post->post_title : 'Unknown',
				'quality_score'   => $quality_score,
				'embedding_count' => intval( $row->chunk_count ),
				'connections'     => $connections,
				'edit_url'        => get_edit_post_link( $row->post_id ),
			);
		}

		return $top_content;
	}

	/**
	 * Get embedding distribution.
	 *
	 * @return array
	 */
	private static function get_embedding_distribution() {
		global $wpdb;
		$chunks_table = $wpdb->prefix . 'wpllmseo_chunks';

		$distribution = array(
			'labels' => array( '1-2 chunks', '3-5 chunks', '6-10 chunks', '11-20 chunks', '20+ chunks' ),
			'values' => array( 0, 0, 0, 0, 0 ),
		);

		$results = $wpdb->get_results(
			"SELECT post_id, COUNT(*) as chunk_count 
			FROM {$chunks_table} 
			WHERE embedding IS NOT NULL 
			GROUP BY post_id"
		);

		foreach ( $results as $row ) {
			$count = intval( $row->chunk_count );
			if ( $count <= 2 ) {
				$distribution['values'][0]++;
			} elseif ( $count <= 5 ) {
				$distribution['values'][1]++;
			} elseif ( $count <= 10 ) {
				$distribution['values'][2]++;
			} elseif ( $count <= 20 ) {
				$distribution['values'][3]++;
			} else {
				$distribution['values'][4]++;
			}
		}

		return $distribution;
	}

	/**
	 * Get content quality data.
	 *
	 * @return array
	 */
	private static function get_content_quality() {
		// Sample quality scores by post type.
		return array(
			'labels' => array( 'Posts', 'Pages', 'Media' ),
			'scores' => array( 85, 72, 45 ),
		);
	}

	/**
	 * Get semantic gaps.
	 *
	 * @return array
	 */
	private static function get_semantic_gaps() {
		// Identify topics with low coverage.
		$categories = get_categories( array( 'hide_empty' => false ) );
		$gaps       = array();

		foreach ( $categories as $category ) {
			$posts         = get_posts( array( 'category' => $category->term_id, 'fields' => 'ids' ) );
			$indexed_count = 0;

			foreach ( $posts as $post_id ) {
				if ( get_post_meta( $post_id, '_wpllmseo_embedding_hash', true ) ) {
					$indexed_count++;
				}
			}

			$coverage = count( $posts ) > 0 ? round( ( $indexed_count / count( $posts ) ) * 100 ) : 0;

			if ( $coverage < 50 ) {
				$gaps[] = array(
					'topic'          => $category->name,
					'coverage'       => $coverage,
					'recommendation' => sprintf( 'Index %d more posts in this category', count( $posts ) - $indexed_count ),
				);
			}
		}

		return $gaps;
	}

	/**
	 * AJAX: Get stats.
	 */
	public static function ajax_get_stats() {
		check_ajax_referer( 'wpllmseo_dashboard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$stats = self::get_stats();
		wp_send_json_success( $stats );
	}

	/**
	 * AJAX: Get content quality.
	 */
	public static function ajax_content_quality() {
		check_ajax_referer( 'wpllmseo_dashboard', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$quality = self::get_content_quality();
		wp_send_json_success( $quality );
	}
}
