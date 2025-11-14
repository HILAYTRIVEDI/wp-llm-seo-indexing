<?php
/**
 * Logs screen.
 *
 * Displays plugin activity logs and error messages.
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

// Initialize logs reader
$logs_reader = new WPLLMSEO_Logs();
$available_logs = $logs_reader->get_available_logs();

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( 'Activity Logs', 'wpllmseo' ),
		'description' => __( 'Monitor plugin activity and troubleshoot issues', 'wpllmseo' ),
	)
);
?>

<div class="wpllmseo-logs">
	<!-- Search Bar -->
	<div class="wpllmseo-log-search">
		<input 
			type="text" 
			id="log-search-input" 
			class="wpllmseo-search-input" 
			placeholder="<?php esc_attr_e( 'Search logs...', 'wpllmseo' ); ?>"
		/>
	</div>

	<!-- Log Sections (Accordion) -->
	<div class="wpllmseo-log-sections">
		<?php foreach ( $available_logs as $log_info ) : 
			$log_name = $log_info['name'];
			$log_stats = $logs_reader->get_log_stats( $log_name );
			$log_lines = $logs_reader->read_log( $log_name, 500 );
			
			$section_id = 'log-section-' . sanitize_title( $log_name );
		?>
		<div class="wpllmseo-log-section">
			<button 
				class="wpllmseo-log-section-header" 
				type="button"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $section_id ); ?>"
			>
				<span class="wpllmseo-log-title">
					<span class="dashicons dashicons-media-text"></span>
					<?php echo esc_html( $log_name ); ?>
					<?php if ( ! $log_info['exists'] ) : ?>
						<span class="wpllmseo-badge wpllmseo-badge-muted"><?php esc_html_e( 'Empty', 'wpllmseo' ); ?></span>
					<?php else : ?>
						<span class="wpllmseo-badge wpllmseo-badge-info"><?php echo esc_html( count( $log_lines ) . ' lines' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="wpllmseo-log-stats">
					<?php if ( $log_stats['error'] > 0 ) : ?>
						<span class="wpllmseo-stat-badge wpllmseo-stat-error">
							<span class="dashicons dashicons-dismiss"></span>
							<?php echo esc_html( $log_stats['error'] ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $log_stats['warning'] > 0 ) : ?>
						<span class="wpllmseo-stat-badge wpllmseo-stat-warning">
							<span class="dashicons dashicons-warning"></span>
							<?php echo esc_html( $log_stats['warning'] ); ?>
						</span>
					<?php endif; ?>
					<span class="dashicons dashicons-arrow-down-alt2 wpllmseo-accordion-icon"></span>
				</span>
			</button>
			
			<div 
				id="<?php echo esc_attr( $section_id ); ?>" 
				class="wpllmseo-log-section-content"
				style="display: none;"
			>
				<div class="wpllmseo-log-section-actions">
					<a 
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpllmseo_download_log&log=' . urlencode( $log_name ) ), 'wpllmseo_download_log' ) ); ?>" 
						class="button button-secondary"
					>
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Download', 'wpllmseo' ); ?>
					</a>
				</div>
				
				<?php if ( ! empty( $log_lines ) ) : ?>
				<div class="wpllmseo-log-content">
					<pre class="wpllmseo-log-pre"><?php
						foreach ( $log_lines as $line ) {
							echo $line . "\n"; // Already escaped in sanitize_log_line
						}
					?></pre>
				</div>
				<?php else : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No log entries found.', 'wpllmseo' ); ?></p>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
</div>
