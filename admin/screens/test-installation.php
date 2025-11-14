<?php
/**
 * Test Installation Screen
 *
 * Verify plugin installation is complete and working.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load component files.
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

global $wpdb;
$all_passed = true;

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( '🧪 Test Installation', 'wpllmseo' ),
		'description' => __( 'Verify that the plugin is properly installed and configured', 'wpllmseo' ),
	)
);
?>

<style>
.test-group {
	margin: 20px 0;
	padding: 15px;
	background: #f6f7f7;
	border-radius: 4px;
}
.wpllmseo-test-table {
	width: 100%;
	border-collapse: collapse;
	margin: 15px 0;
	background: white;
}
.wpllmseo-test-table th,
.wpllmseo-test-table td {
	padding: 10px;
	text-align: left;
	border-bottom: 1px solid #dcdcde;
}
.wpllmseo-test-table th {
	background: #f6f7f7;
	font-weight: 600;
}
.test-success {
	color: #00a32a;
	font-weight: 600;
}
.test-error {
	color: #d63638;
	font-weight: 600;
}
.test-warning {
	color: #dba617;
	font-weight: 600;
}
.test-result-summary {
	padding: 20px;
	margin: 20px 0;
	border-radius: 4px;
	border-left: 4px solid;
}
.test-result-summary.success {
	background: #edfaef;
	border-color: #00a32a;
}
.test-result-summary.error {
	background: #fcf0f1;
	border-color: #d63638;
}
</style>

<div class="wpllmseo-settings">
	
	<!-- Test 1: Database Tables -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( '1. Database Tables', 'wpllmseo' ); ?></h2>
		<div class="test-group">
			<?php
			$required_tables = array(
				'wpllmseo_snippets' => __( 'Snippets storage', 'wpllmseo' ),
				'wpllmseo_chunks' => __( 'Content chunks', 'wpllmseo' ),
				'wpllmseo_jobs' => __( 'Queue jobs', 'wpllmseo' ),
				'wpllmseo_jobs_dead_letter' => __( 'Failed jobs', 'wpllmseo' ),
				'wpllmseo_tokens' => __( 'Token index', 'wpllmseo' ),
			);
			?>
			<table class="wpllmseo-test-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table Name', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Description', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Row Count', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $required_tables as $table => $description ) : ?>
						<?php
						$full_table = $wpdb->prefix . $table;
						$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table ) );
						if ( $exists ) {
							$count = $wpdb->get_var( "SELECT COUNT(*) FROM `$full_table`" );
							?>
							<tr>
								<td><code><?php echo esc_html( $full_table ); ?></code></td>
								<td><?php echo esc_html( $description ); ?></td>
								<td><span class="test-success">✓ <?php esc_html_e( 'Exists', 'wpllmseo' ); ?></span></td>
								<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
							</tr>
						<?php } else { ?>
							<tr>
								<td><code><?php echo esc_html( $full_table ); ?></code></td>
								<td><?php echo esc_html( $description ); ?></td>
								<td><span class="test-error">✗ <?php esc_html_e( 'Missing', 'wpllmseo' ); ?></span></td>
								<td>-</td>
							</tr>
							<?php $all_passed = false; ?>
						<?php } ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Test 2: Settings -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( '2. Plugin Settings', 'wpllmseo' ); ?></h2>
		<div class="test-group">
			<?php
			$settings = get_option( 'wpllmseo_settings', array() );
			$db_version = get_option( 'wpllmseo_db_version', 'Not set' );
			?>
			<?php if ( ! empty( $settings ) ) : ?>
				<p><span class="test-success">✓ <?php esc_html_e( 'Settings initialized', 'wpllmseo' ); ?></span> - <?php echo esc_html( count( $settings ) ); ?> <?php esc_html_e( 'options configured', 'wpllmseo' ); ?></p>
				<p><strong><?php esc_html_e( 'DB Version:', 'wpllmseo' ); ?></strong> <code><?php echo esc_html( $db_version ); ?></code></p>
				
				<?php if ( isset( $settings['providers'] ) && ! empty( $settings['providers'] ) ) : ?>
					<p><span class="test-success">✓ <?php esc_html_e( 'Provider configs exist', 'wpllmseo' ); ?></span></p>
					<table class="wpllmseo-test-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Provider', 'wpllmseo' ); ?></th>
								<th><?php esc_html_e( 'API Key Set', 'wpllmseo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $settings['providers'] as $provider_id => $config ) : ?>
								<?php
								$has_key = ! empty( $config['api_key'] );
								?>
								<tr>
									<td><code><?php echo esc_html( $provider_id ); ?></code></td>
									<td>
										<?php if ( $has_key ) : ?>
											<span class="test-success">✓ <?php esc_html_e( 'Yes', 'wpllmseo' ); ?></span>
										<?php else : ?>
											<span class="test-warning">⚠ <?php esc_html_e( 'Not configured', 'wpllmseo' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><span class="test-warning">⚠ <?php esc_html_e( 'No providers configured yet', 'wpllmseo' ); ?></span></p>
				<?php endif; ?>
			<?php else : ?>
				<p><span class="test-error">✗ <?php esc_html_e( 'Settings not initialized', 'wpllmseo' ); ?></span></p>
				<?php $all_passed = false; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Test 3: Classes -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( '3. Required Classes', 'wpllmseo' ); ?></h2>
		<div class="test-group">
			<?php
			$required_classes = array(
				'WPLLMSEO_Admin' => __( 'Admin interface', 'wpllmseo' ),
				'WPLLMSEO_Provider_Manager' => __( 'Provider management', 'wpllmseo' ),
				'WPLLMSEO_Installer_Upgrader' => __( 'Installation system', 'wpllmseo' ),
				'WPLLMSEO_Queue' => __( 'Job queue', 'wpllmseo' ),
				'WPLLMSEO_Snippets' => __( 'Snippet system', 'wpllmseo' ),
			);
			?>
			<table class="wpllmseo-test-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Class Name', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Description', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $required_classes as $class => $description ) : ?>
						<?php $exists = class_exists( $class ); ?>
						<tr>
							<td><code><?php echo esc_html( $class ); ?></code></td>
							<td><?php echo esc_html( $description ); ?></td>
							<td>
								<?php if ( $exists ) : ?>
									<span class="test-success">✓ <?php esc_html_e( 'Loaded', 'wpllmseo' ); ?></span>
								<?php else : ?>
									<span class="test-error">✗ <?php esc_html_e( 'Not found', 'wpllmseo' ); ?></span>
									<?php $all_passed = false; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Test 4: Directories -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( '4. File System', 'wpllmseo' ); ?></h2>
		<div class="test-group">
			<?php
			$required_dirs = array(
				'var' => __( 'Variable data', 'wpllmseo' ),
				'var/logs' => __( 'Log files', 'wpllmseo' ),
				'var/cache' => __( 'Cache files', 'wpllmseo' ),
			);
			?>
			<table class="wpllmseo-test-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Directory', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Description', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Exists', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Writable', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $required_dirs as $dir => $description ) : ?>
						<?php
						$full_path = WPLLMSEO_PLUGIN_DIR . $dir;
						$exists = file_exists( $full_path );
						$writable = is_writable( $full_path );
						?>
						<tr>
							<td><code><?php echo esc_html( $dir ); ?></code></td>
							<td><?php echo esc_html( $description ); ?></td>
							<td>
								<?php if ( $exists ) : ?>
									<span class="test-success">✓</span>
								<?php else : ?>
									<span class="test-error">✗</span>
									<?php $all_passed = false; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $writable ) : ?>
									<span class="test-success">✓</span>
								<?php else : ?>
									<span class="test-error">✗</span>
									<?php $all_passed = false; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Test 5: Cron Jobs -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( '5. Scheduled Tasks', 'wpllmseo' ); ?></h2>
		<div class="test-group">
			<?php
			$cron_jobs = array(
				'wpllmseo_worker_event' => __( 'Worker (every minute)', 'wpllmseo' ),
				'wpllmseo_generate_ai_sitemap_daily' => __( 'Sitemap generation (daily)', 'wpllmseo' ),
				'wpllmseo_cleanup_expired_tokens' => __( 'Token cleanup (daily)', 'wpllmseo' ),
			);
			?>
			<table class="wpllmseo-test-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Cron Hook', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Description', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Next Run', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $cron_jobs as $hook => $description ) : ?>
						<?php
						$next_run = wp_next_scheduled( $hook );
						?>
						<tr>
							<td><code><?php echo esc_html( $hook ); ?></code></td>
							<td><?php echo esc_html( $description ); ?></td>
							<td>
								<?php if ( $next_run ) : ?>
									<span class="test-success">✓ <?php esc_html_e( 'Scheduled', 'wpllmseo' ); ?></span>
								<?php else : ?>
									<span class="test-error">✗ <?php esc_html_e( 'Not scheduled', 'wpllmseo' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo $next_run ? esc_html( date_i18n( 'Y-m-d H:i:s', $next_run ) ) : '-'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Final Result -->
	<?php if ( $all_passed ) : ?>
		<div class="test-result-summary success">
			<h2 style="color: #00a32a; margin-top: 0;">✓ <?php esc_html_e( 'All Tests Passed!', 'wpllmseo' ); ?></h2>
			<p><?php esc_html_e( 'The plugin is properly installed and ready to use.', 'wpllmseo' ); ?></p>
			<p><strong><?php esc_html_e( 'Next steps:', 'wpllmseo' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Go to LLM SEO > API Providers and add your API key', 'wpllmseo' ); ?></li>
				<li><?php esc_html_e( 'Click Save Provider Settings', 'wpllmseo' ); ?></li>
				<li><?php esc_html_e( 'Click Discover Models to test the connection', 'wpllmseo' ); ?></li>
				<li><?php esc_html_e( 'Create or edit posts - they will be automatically indexed', 'wpllmseo' ); ?></li>
			</ol>
		</div>
	<?php else : ?>
		<div class="test-result-summary error">
			<h2 style="color: #d63638; margin-top: 0;">⚠ <?php esc_html_e( 'Some Tests Failed', 'wpllmseo' ); ?></h2>
			<p><?php esc_html_e( 'Please deactivate and reactivate the plugin to trigger installation, or contact support.', 'wpllmseo' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Action Buttons -->
	<p class="submit">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_providers' ) ); ?>" class="button button-primary">
			<?php esc_html_e( '→ Configure API Providers', 'wpllmseo' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_dashboard' ) ); ?>" class="button">
			<?php esc_html_e( '→ View Dashboard', 'wpllmseo' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button">
			<?php esc_html_e( '← Back to Plugins', 'wpllmseo' ); ?>
		</a>
	</p>

</div>
