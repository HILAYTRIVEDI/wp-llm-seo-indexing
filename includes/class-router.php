<?php
/**
 * Router class for loading admin screens.
 *
 * Handles routing between different admin screens and provides
 * a wrapper for consistent layout with sidebar navigation.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Router
 */
class WPLLMSEO_Router {

	/**
	 * Render a screen with the admin wrapper.
	 *
	 * @param string $screen The screen name to render.
	 */
	public static function render( $screen ) {
		// Determine active screen.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		
		// Map menu slugs to screen files.
		$screen_map = array(
			'wpllmseo_dashboard' => 'dashboard',
			'wpllmseo_queue'     => 'queue',
			'wpllmseo_snippets'  => 'snippets',
			'wpllmseo_logs'      => 'logs',
			'wpllmseo_settings'  => 'settings',
		);

		// Get the screen file to load.
		$screen_file = isset( $screen_map[ $current_page ] ) ? $screen_map[ $current_page ] : $screen;
		$screen_path = WPLLMSEO_PLUGIN_DIR . 'admin/screens/' . $screen_file . '.php';

		// Check if screen file exists.
		if ( ! file_exists( $screen_path ) ) {
			wpllmseo_log( 'Screen file not found: ' . $screen_path, 'error' );
			echo '<div class="wrap"><div class="notice notice-error"><p>';
			echo esc_html__( 'Error: Screen file not found. Please reinstall the plugin.', 'wpllmseo' );
			echo '</p></div></div>';
			return;
		}

		// Start the admin wrapper.
		self::render_wrapper_start( $screen_file );

		// Load the screen content.
		include $screen_path;

		// End the admin wrapper.
		self::render_wrapper_end();
	}

	/**
	 * Render the start of the admin wrapper with sidebar.
	 *
	 * @param string $current_screen The current screen being rendered.
	 */
	private static function render_wrapper_start( $current_screen ) {
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		?>
		<div class="wrap wpllmseo-wrap" id="wpllmseo-app">
			<div class="wpllmseo-container">
				<!-- Sidebar Navigation -->
				<nav class="wpllmseo-sidebar" role="navigation" aria-label="<?php esc_attr_e( 'Plugin Navigation', 'wpllmseo' ); ?>">
					<div class="wpllmseo-sidebar-header">
						<span class="dashicons dashicons-chart-bar"></span>
						<h2><?php esc_html_e( 'AI SEO & Indexing', 'wpllmseo' ); ?></h2>
					</div>
					<ul class="wpllmseo-nav-menu">
						<li class="<?php echo ( 'dashboard' === $current_screen ) ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_dashboard' ) ); ?>" 
							   data-screen="dashboard"
							   aria-current="<?php echo ( 'dashboard' === $current_screen ) ? 'page' : 'false'; ?>">
								<span class="dashicons dashicons-dashboard"></span>
								<?php esc_html_e( 'Dashboard', 'wpllmseo' ); ?>
							</a>
						</li>
						<li class="<?php echo ( 'queue' === $current_screen ) ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_queue' ) ); ?>" 
							   data-screen="queue"
							   aria-current="<?php echo ( 'queue' === $current_screen ) ? 'page' : 'false'; ?>">
								<span class="dashicons dashicons-list-view"></span>
								<?php esc_html_e( 'Index Queue', 'wpllmseo' ); ?>
							</a>
						</li>
						<li class="<?php echo ( 'snippets' === $current_screen ) ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_snippets' ) ); ?>" 
							   data-screen="snippets"
							   aria-current="<?php echo ( 'snippets' === $current_screen ) ? 'page' : 'false'; ?>">
								<span class="dashicons dashicons-media-code"></span>
								<?php esc_html_e( 'Snippets', 'wpllmseo' ); ?>
							</a>
						</li>
						<li class="<?php echo ( 'logs' === $current_screen ) ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_logs' ) ); ?>" 
							   data-screen="logs"
							   aria-current="<?php echo ( 'logs' === $current_screen ) ? 'page' : 'false'; ?>">
								<span class="dashicons dashicons-editor-alignleft"></span>
								<?php esc_html_e( 'Logs', 'wpllmseo' ); ?>
							</a>
						</li>
						<li class="<?php echo ( 'settings' === $current_screen ) ? 'active' : ''; ?>">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_settings' ) ); ?>" 
							   data-screen="settings"
							   aria-current="<?php echo ( 'settings' === $current_screen ) ? 'page' : 'false'; ?>">
								<span class="dashicons dashicons-admin-settings"></span>
								<?php esc_html_e( 'Settings', 'wpllmseo' ); ?>
							</a>
						</li>
					</ul>
				</nav>

				<!-- Main Content Area -->
				<main class="wpllmseo-main" role="main">
		<?php
	}

	/**
	 * Render the end of the admin wrapper.
	 */
	private static function render_wrapper_end() {
		?>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * Get current screen name from query parameters.
	 *
	 * @return string The current screen name.
	 */
	public static function get_current_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		
		$screen_map = array(
			'wpllmseo_dashboard' => 'dashboard',
			'wpllmseo_queue'     => 'queue',
			'wpllmseo_snippets'  => 'snippets',
			'wpllmseo_logs'      => 'logs',
			'wpllmseo_settings'  => 'settings',
		);

		return isset( $screen_map[ $page ] ) ? $screen_map[ $page ] : 'dashboard';
	}
}
