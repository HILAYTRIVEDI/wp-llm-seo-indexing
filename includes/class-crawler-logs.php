<?php
/**
 * Crawler Verification Logs
 *
 * Track and verify LLM crawler access attempts.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Crawler_Logs
 *
 * Logs and verifies LLM crawler activity.
 */
class WPLLMSEO_Crawler_Logs {

	/**
	 * Known LLM crawler user agents.
	 */
	const KNOWN_CRAWLERS = array(
		'ChatGPT-User'    => 'OpenAI ChatGPT',
		'GPTBot'          => 'OpenAI GPTBot',
		'ClaudeBot'       => 'Anthropic Claude',
		'Google-Extended' => 'Google Bard/Gemini',
		'PerplexityBot'   => 'Perplexity AI',
		'Applebot'        => 'Apple Intelligence',
		'Bytespider'      => 'ByteDance',
	);

	/**
	 * Log table name.
	 */
	const LOG_TABLE = 'wpllmseo_crawler_logs';

	/**
	 * Initialize crawler logs.
	 */
	public static function init() {
		// Log requests to LLM endpoints.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log_request' ), 1 );

		// Admin page.
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_page' ), 25 );

		// AJAX handlers.
		add_action( 'wp_ajax_wpllmseo_crawler_stats', array( __CLASS__, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wpllmseo_clear_logs', array( __CLASS__, 'ajax_clear_logs' ) );

		// REST endpoints.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Create table on activation.
		register_activation_hook( WPLLMSEO_PLUGIN_FILE, array( __CLASS__, 'create_table' ) );
	}

	/**
	 * Create log table.
	 */
	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . self::LOG_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL,
			ip_address varchar(45) NOT NULL,
			user_agent text NOT NULL,
			crawler_type varchar(50) DEFAULT NULL,
			request_uri text NOT NULL,
			endpoint_type varchar(50) DEFAULT NULL,
			response_code int(11) DEFAULT NULL,
			response_time float DEFAULT NULL,
			verified tinyint(1) DEFAULT 0,
			metadata text DEFAULT NULL,
			PRIMARY KEY (id),
			KEY timestamp (timestamp),
			KEY crawler_type (crawler_type),
			KEY endpoint_type (endpoint_type),
			KEY verified (verified)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Maybe log request.
	 */
	public static function maybe_log_request() {
		$settings = get_option( 'wpllmseo_settings', array() );

		if ( empty( $settings['enable_crawler_logs'] ) ) {
			return;
		}

		// Check if this is an LLM endpoint.
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$endpoint_type = self::detect_endpoint_type( $request_uri );

		if ( ! $endpoint_type ) {
			return;
		}

		// Detect crawler.
		$user_agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$crawler_type = self::detect_crawler( $user_agent );

		// Log the request.
		self::log_request(
			array(
				'ip_address'    => self::get_client_ip(),
				'user_agent'    => $user_agent,
				'crawler_type'  => $crawler_type,
				'request_uri'   => $request_uri,
				'endpoint_type' => $endpoint_type,
			)
		);
	}

	/**
	 * Detect endpoint type.
	 *
	 * @param string $uri Request URI.
	 * @return string|false
	 */
	private static function detect_endpoint_type( $uri ) {
		if ( strpos( $uri, '/ai-sitemap.jsonl' ) !== false ) {
			return 'ai_sitemap';
		}

		if ( strpos( $uri, '/llm-sitemap.jsonl' ) !== false ) {
			return 'llm_sitemap';
		}

		if ( strpos( $uri, '/llm-snippets.jsonl' ) !== false ) {
			return 'llm_snippets';
		}

		if ( strpos( $uri, '/llm-semantic-map.json' ) !== false ) {
			return 'semantic_map';
		}

		if ( strpos( $uri, '/llm-context-graph.jsonl' ) !== false ) {
			return 'context_graph';
		}

		if ( strpos( $uri, '/wp-json/llm/v1/' ) !== false ) {
			return 'llm_api';
		}

		if ( strpos( $uri, '/wp-json/wp-llmseo/v1/' ) !== false ) {
			return 'rest_api';
		}

		return false;
	}

	/**
	 * Detect crawler from user agent.
	 *
	 * @param string $user_agent User agent string.
	 * @return string|null
	 */
	private static function detect_crawler( $user_agent ) {
		foreach ( self::KNOWN_CRAWLERS as $bot => $name ) {
			if ( stripos( $user_agent, $bot ) !== false ) {
				return $bot;
			}
		}

		return null;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$ip = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return $ip;
	}

	/**
	 * Log request.
	 *
	 * @param array $data Log data.
	 */
	public static function log_request( $data ) {
		global $wpdb;

		require_once __DIR__ . '/helpers/class-db-helpers.php';
		$validated = WPLLMSEO_DB_Helpers::validate_table_name( self::LOG_TABLE );
		$table_name = is_wp_error( $validated ) ? $wpdb->prefix . self::LOG_TABLE : $validated;

		$wpdb->insert(
			$table_name,
			array(
				'timestamp'     => current_time( 'mysql' ),
				'ip_address'    => $data['ip_address'] ?? '',
				'user_agent'    => $data['user_agent'] ?? '',
				'crawler_type'  => $data['crawler_type'],
				'request_uri'   => $data['request_uri'] ?? '',
				'endpoint_type' => $data['endpoint_type'] ?? '',
				'response_code' => $data['response_code'] ?? null,
				'response_time' => $data['response_time'] ?? null,
				'verified'      => $data['verified'] ?? 0,
				'metadata'      => isset( $data['metadata'] ) ? json_encode( $data['metadata'] ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%d', '%s' )
		);
	}

	/**
	 * Get crawler stats.
	 *
	 * @param int $days Number of days.
	 * @return array
	 */
	public static function get_stats( $days = 30 ) {
		global $wpdb;

		$validated = WPLLMSEO_DB_Helpers::validate_table_name( self::LOG_TABLE );
		$table_name = is_wp_error( $validated ) ? $wpdb->prefix . self::LOG_TABLE : $validated;
		$since_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Total requests.
		$total_requests = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %s WHERE timestamp >= %s", $table_name, $since_date ) );

		// Verified vs unverified.
		$verified = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %s WHERE timestamp >= %s AND verified = %d", $table_name, $since_date, 1 ) );

		// By crawler type.
		$by_crawler = $wpdb->get_results( $wpdb->prepare( "SELECT crawler_type, COUNT(*) as count FROM %s WHERE timestamp >= %s GROUP BY crawler_type ORDER BY count DESC", $table_name, $since_date ) );

		// By endpoint.
		$by_endpoint = $wpdb->get_results( $wpdb->prepare( "SELECT endpoint_type, COUNT(*) as count FROM %s WHERE timestamp >= %s GROUP BY endpoint_type ORDER BY count DESC", $table_name, $since_date ) );

		// Daily trend.
		$daily_trend = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(timestamp) as date, COUNT(*) as count FROM %s WHERE timestamp >= %s GROUP BY DATE(timestamp) ORDER BY date ASC", $table_name, $since_date ) );

		return array(
			'total_requests' => intval( $total_requests ),
			'verified'       => intval( $verified ),
			'unverified'     => intval( $total_requests ) - intval( $verified ),
			'by_crawler'     => $by_crawler,
			'by_endpoint'    => $by_endpoint,
			'daily_trend'    => $daily_trend,
		);
	}

	/**
	 * Get recent logs.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function get_recent_logs( $limit = 50 ) {
		global $wpdb;

		$validated = WPLLMSEO_DB_Helpers::validate_table_name( self::LOG_TABLE );
		$table_name = is_wp_error( $validated ) ? $wpdb->prefix . self::LOG_TABLE : $validated;

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %s ORDER BY timestamp DESC LIMIT %d", $table_name, $limit ) );
	}

	/**
	 * Clear old logs.
	 *
	 * @param int $days Days to keep.
	 */
	public static function clear_old_logs( $days = 90 ) {
		global $wpdb;

		$validated = WPLLMSEO_DB_Helpers::validate_table_name( self::LOG_TABLE );
		$table_name = is_wp_error( $validated ) ? $wpdb->prefix . self::LOG_TABLE : $validated;
		$since_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM %s WHERE timestamp < %s", $table_name, $since_date ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/crawler-logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_logs' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'limit' => array(
						'default' => 50,
						'type'    => 'integer',
					),
				),
			)
		);

		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/crawler-logs/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get_stats' ),
				'permission_callback' => function() {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'days' => array(
						'default' => 30,
						'type'    => 'integer',
					),
				),
			)
		);
	}

	/**
	 * REST: Get logs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_get_logs( $request ) {
		$limit = $request->get_param( 'limit' );
		$logs  = self::get_recent_logs( $limit );

		return rest_ensure_response( array( 'logs' => $logs ) );
	}

	/**
	 * REST: Get stats.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_get_stats( $request ) {
		$days  = $request->get_param( 'days' );
		$stats = self::get_stats( $days );

		return rest_ensure_response( $stats );
	}

	/**
	 * Add admin page.
	 */
	public static function add_admin_page() {
		add_submenu_page(
			'wpllmseo',
			__( 'Crawler Logs', 'wpllmseo' ),
			__( 'Crawler Logs', 'wpllmseo' ),
			'manage_options',
			'wpllmseo-crawler-logs',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_admin_page() {
		require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

		wpllmseo_render_header(
			array(
				'title'       => __( 'Crawler Verification Logs', 'wpllmseo' ),
				'description' => __( 'Monitor LLM crawler activity and verify access', 'wpllmseo' ),
			)
		);

		$stats = self::get_stats( 30 );
		$logs  = self::get_recent_logs( 100 );

		?>
		<div class="wrap wpllmseo-wrap">
			
			<!-- Stats Cards -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px;">
				<div class="wpllmseo-stat-card">
					<div class="stat-value"><?php echo esc_html( number_format( $stats['total_requests'] ) ); ?></div>
					<div class="stat-label">Total Requests</div>
				</div>
				<div class="wpllmseo-stat-card">
					<div class="stat-value" style="color:#4caf50;"><?php echo esc_html( number_format( $stats['verified'] ) ); ?></div>
					<div class="stat-label">Verified Crawlers</div>
				</div>
				<div class="wpllmseo-stat-card">
					<div class="stat-value" style="color:#f50057;"><?php echo esc_html( number_format( $stats['unverified'] ) ); ?></div>
					<div class="stat-label">Unverified</div>
				</div>
			</div>

			<!-- By Crawler Type -->
			<div class="wpllmseo-settings-section">
				<h2><?php esc_html_e( 'Requests by Crawler Type', 'wpllmseo' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Crawler', 'wpllmseo' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'wpllmseo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['by_crawler'] as $crawler ) : ?>
							<tr>
								<td>
									<?php
									echo esc_html( self::KNOWN_CRAWLERS[ $crawler->crawler_type ] ?? $crawler->crawler_type ?? 'Unknown' );
									?>
								</td>
								<td><?php echo esc_html( number_format( $crawler->count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- By Endpoint -->
			<div class="wpllmseo-settings-section">
				<h2><?php esc_html_e( 'Requests by Endpoint', 'wpllmseo' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Endpoint', 'wpllmseo' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'wpllmseo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['by_endpoint'] as $endpoint ) : ?>
							<tr>
								<td><code><?php echo esc_html( $endpoint->endpoint_type ); ?></code></td>
								<td><?php echo esc_html( number_format( $endpoint->count ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Recent Logs -->
			<div class="wpllmseo-settings-section">
				<h2>
					<?php esc_html_e( 'Recent Access Logs', 'wpllmseo' ); ?>
					<button type="button" id="wpllmseo-clear-logs" class="button" style="float:right;">
						<?php esc_html_e( 'Clear Old Logs', 'wpllmseo' ); ?>
					</button>
				</h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'wpllmseo' ); ?></th>
							<th><?php esc_html_e( 'Crawler', 'wpllmseo' ); ?></th>
							<th><?php esc_html_e( 'Endpoint', 'wpllmseo' ); ?></th>
							<th><?php esc_html_e( 'IP', 'wpllmseo' ); ?></th>
							<th><?php esc_html_e( 'Verified', 'wpllmseo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( human_time_diff( strtotime( $log->timestamp ), current_time( 'timestamp' ) ) . ' ago' ); ?></td>
								<td>
									<?php
									$crawler_name = $log->crawler_type ? ( self::KNOWN_CRAWLERS[ $log->crawler_type ] ?? $log->crawler_type ) : 'Unknown';
									echo esc_html( $crawler_name );
									?>
								</td>
								<td><code><?php echo esc_html( $log->endpoint_type ); ?></code></td>
								<td><?php echo esc_html( $log->ip_address ); ?></td>
								<td>
									<?php if ( $log->verified ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color:#4caf50;"></span>
									<?php else : ?>
										<span class="dashicons dashicons-warning" style="color:#f50057;"></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
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
			margin-bottom: 10px;
		}
		.stat-label {
			font-size: 14px;
			color: #666;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		</style>

		<script>
		jQuery(document).ready(function($) {
			$('#wpllmseo-clear-logs').on('click', function() {
				if (!confirm('Clear logs older than 90 days?')) {
					return;
				}

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'wpllmseo_clear_logs',
						nonce: '<?php echo wp_create_nonce( 'wpllmseo_crawler_logs' ); ?>'
					},
					success: function(response) {
						if (response.success) {
							alert('Old logs cleared successfully.');
							location.reload();
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * AJAX: Get stats.
	 */
	public static function ajax_get_stats() {
		check_ajax_referer( 'wpllmseo_crawler_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$days  = absint( $_POST['days'] ?? 30 );
		$stats = self::get_stats( $days );

		wp_send_json_success( $stats );
	}

	/**
	 * AJAX: Clear logs.
	 */
	public static function ajax_clear_logs() {
		check_ajax_referer( 'wpllmseo_crawler_logs', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		self::clear_old_logs( 90 );

		wp_send_json_success( array( 'message' => 'Old logs cleared.' ) );
	}
}
