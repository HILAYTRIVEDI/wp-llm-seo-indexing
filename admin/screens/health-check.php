<?php
/**
 * Health Check Screen
 *
 * Displays system diagnostics and health status.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load component files.
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

// Handle flush rewrite rules action
if ( isset( $_POST['wpllmseo_flush_rewrites'] ) && check_admin_referer( 'wpllmseo_admin_action', 'wpllmseo_nonce' ) ) {
	flush_rewrite_rules();
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Rewrite rules flushed successfully.', 'wpllmseo' ) . '</p></div>';
}

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( 'Health Check', 'wpllmseo' ),
		'description' => __( 'System diagnostics and health status', 'wpllmseo' ),
	)
);

// Get system information
global $wpdb;
$queue = new WPLLMSEO_Queue();
$stats = $queue->get_stats();
$settings = get_option( 'wpllmseo_settings', array() );

// Check rewrite rules
$rewrite_rules = get_option( 'rewrite_rules' );
$has_sitemap_rules = false;
if ( is_array( $rewrite_rules ) ) {
	foreach ( $rewrite_rules as $pattern => $rule ) {
		if ( strpos( $pattern, 'ai-sitemap' ) !== false || strpos( $rule, 'ai-sitemap' ) !== false ) {
			$has_sitemap_rules = true;
			break;
		}
	}
}

// Check cron jobs
$cron_jobs = array(
	'wpllmseo_worker_event' => wp_next_scheduled( 'wpllmseo_worker_event' ),
	'wpllmseo_generate_ai_sitemap_daily' => wp_next_scheduled( 'wpllmseo_generate_ai_sitemap_daily' ),
	'wpllmseo_cleanup_expired_tokens' => wp_next_scheduled( 'wpllmseo_cleanup_expired_tokens' ),
	'wpllmseo_prune_exec_logs_daily' => wp_next_scheduled( 'wpllmseo_prune_exec_logs_daily' ),
);

// Check database tables
$required_tables = array(
	'wpllmseo_snippets',
	'wpllmseo_chunks',
	'wpllmseo_jobs',
	'wpllmseo_jobs_dead_letter',
	'wpllmseo_tokens',
	'wpllmseo_exec_logs',
	'wpllmseo_crawler_logs',
	'wpllmseo_mcp_audit',
	'wpllmseo_mcp_tokens',
);

$table_status = array();
foreach ( $required_tables as $table ) {
	$full_table = $wpdb->prefix . $table;
	$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table ) );
	$count = 0;
	if ( $exists ) {
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table}`" );
	}
	$table_status[ $table ] = array(
		'exists' => (bool) $exists,
		'count' => (int) $count,
	);
}

// Check directories
$required_dirs = array(
	'var' => WPLLMSEO_PLUGIN_DIR . 'var',
	'var/logs' => WPLLMSEO_PLUGIN_DIR . 'var/logs',
	'var/cache' => WPLLMSEO_PLUGIN_DIR . 'var/cache',
);

$dir_status = array();
foreach ( $required_dirs as $name => $path ) {
	$dir_status[ $name ] = array(
		'exists' => file_exists( $path ),
		'writable' => is_writable( $path ),
	);
}

// Check provider configuration
$provider_status = array();
$providers = WPLLMSEO_Provider_Manager::get_providers();
foreach ( $providers as $provider_id => $provider ) {
	$config = WPLLMSEO_Provider_Manager::get_provider_config( $provider_id );
	$has_key = ! empty( $config['api_key'] );
	$cached_models = WPLLMSEO_Provider_Manager::get_cached_models( $provider_id );
	
	$provider_status[ $provider_id ] = array(
		'name' => $provider->get_name(),
		'configured' => $has_key,
		'cached_models' => $cached_models ? count( $cached_models ) : 0,
	);
}

// Active assignments
$active_providers = $settings['active_providers'] ?? array();
$active_models = $settings['active_models'] ?? array();

?>

<div class="wpllmseo-health-check">
	
	<!-- System Status -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'System Status', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Component', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Details', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'WordPress Version', 'wpllmseo' ); ?></strong></td>
					<td><span class="wpllmseo-badge wpllmseo-badge-success">✓</span></td>
					<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'PHP Version', 'wpllmseo' ); ?></strong></td>
					<td>
						<?php if ( version_compare( PHP_VERSION, WPLLMSEO_MIN_PHP_VERSION, '>=' ) ) : ?>
							<span class="wpllmseo-badge wpllmseo-badge-success">✓</span>
						<?php else : ?>
							<span class="wpllmseo-badge wpllmseo-badge-error">✗</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( PHP_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Plugin Version', 'wpllmseo' ); ?></strong></td>
					<td><span class="wpllmseo-badge wpllmseo-badge-success">✓</span></td>
					<td><?php echo esc_html( WPLLMSEO_VERSION ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Database Version', 'wpllmseo' ); ?></strong></td>
					<td><span class="wpllmseo-badge wpllmseo-badge-success">✓</span></td>
					<td><?php echo esc_html( get_option( 'wpllmseo_db_version', 'Unknown' ) ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Provider Configuration -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Provider Configuration', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Provider', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'API Key', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Cached Models', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Active For', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $provider_status as $provider_id => $status ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $status['name'] ); ?></strong></td>
						<td>
							<?php if ( $status['configured'] ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success">✓ Configured</span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-error">✗ Not Configured</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $status['cached_models'] ); ?> models</td>
						<td>
							<?php
							$features = array();
							foreach ( $active_providers as $feature => $active_provider_id ) {
								if ( $active_provider_id === $provider_id ) {
									$features[] = ucfirst( $feature );
								}
							}
							echo esc_html( ! empty( $features ) ? implode( ', ', $features ) : '-' );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		
		<p class="description" style="margin-top: 15px;">
			<strong><?php esc_html_e( 'Active Model Assignments:', 'wpllmseo' ); ?></strong><br>
			<?php if ( ! empty( $active_models['embedding'] ) ) : ?>
				Embedding: <code><?php echo esc_html( $active_models['embedding'] ); ?></code><br>
			<?php endif; ?>
			<?php if ( ! empty( $active_models['generation'] ) ) : ?>
				Generation: <code><?php echo esc_html( $active_models['generation'] ); ?></code>
			<?php endif; ?>
			<?php if ( empty( $active_models['embedding'] ) && empty( $active_models['generation'] ) ) : ?>
				<span style="color: #d63638;"><?php esc_html_e( 'No models configured', 'wpllmseo' ); ?></span>
			<?php endif; ?>
		</p>
	</div>

	<!-- Queue Status -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Queue Status', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Count', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'Pending', 'wpllmseo' ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( $stats['queued'] ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Processing', 'wpllmseo' ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( $stats['processing'] ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Completed', 'wpllmseo' ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( $stats['completed'] ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Failed', 'wpllmseo' ); ?></strong></td>
					<td>
						<?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?>
						<?php if ( $stats['failed'] > 0 ) : ?>
							<span class="wpllmseo-badge wpllmseo-badge-error">⚠</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Total', 'wpllmseo' ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Database Tables -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Database Tables', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Table', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Row Count', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $table_status as $table => $status ) : ?>
					<tr>
						<td><code><?php echo esc_html( $wpdb->prefix . $table ); ?></code></td>
						<td>
							<?php if ( $status['exists'] ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success">✓ Exists</span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-error">✗ Missing</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $status['count'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Directories -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Directories', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Directory', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Exists', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Writable', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $dir_status as $name => $status ) : ?>
					<tr>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td>
							<?php if ( $status['exists'] ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success">✓</span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-error">✗</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $status['writable'] ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success">✓</span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-error">✗</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Cron Jobs -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Scheduled Tasks', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Task', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Next Run', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cron_jobs as $hook => $next_run ) : ?>
					<tr>
						<td><code><?php echo esc_html( $hook ); ?></code></td>
						<td>
							<?php if ( $next_run ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success">✓ Scheduled</span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-error">✗ Not Scheduled</span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( $next_run ) {
								echo esc_html( date_i18n( 'Y-m-d H:i:s', $next_run ) );
								echo ' (' . esc_html( human_time_diff( $next_run ) ) . ')';
							} else {
								echo '-';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Rewrite Rules -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Rewrite Rules', 'wpllmseo' ); ?></h2>
		
		<table class="widefat striped">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'AI Sitemap Rules', 'wpllmseo' ); ?></strong></td>
					<td>
						<?php if ( $has_sitemap_rules ) : ?>
							<span class="wpllmseo-badge wpllmseo-badge-success">✓ Registered</span>
						<?php else : ?>
							<span class="wpllmseo-badge wpllmseo-badge-warning">⚠ Not Found</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		
		<form method="post" style="margin-top: 15px;">
			<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>
			<button type="submit" name="wpllmseo_flush_rewrites" class="button button-secondary">
				<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
				<?php esc_html_e( 'Flush Rewrite Rules', 'wpllmseo' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Click this if sitemaps are returning 404 errors.', 'wpllmseo' ); ?>
			</p>
		</form>
	</div>

</div>
