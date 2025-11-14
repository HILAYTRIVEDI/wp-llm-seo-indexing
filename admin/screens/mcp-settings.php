<?php
/**
 * MCP Settings Admin Page
 *
 * Admin UI for MCP configuration, token management, and audit logs.
 *
 * @package WP_LLM_SEO_Indexing
 * @since 1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check permissions
if ( ! WPLLMSEO_Capabilities::user_can_manage() ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
}

// Get current tab
$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

// Handle actions
if ( isset( $_POST['wpllmseo_mcp_action'] ) ) {
	check_admin_referer( 'wpllmseo_mcp_action' );

	$action = sanitize_key( $_POST['wpllmseo_mcp_action'] );

	switch ( $action ) {
		case 'generate_token':
			$user_id = intval( $_POST['user_id'] ?? get_current_user_id() );
			$name    = sanitize_text_field( $_POST['token_name'] ?? 'MCP Token' );
			$expires = intval( $_POST['expires_days'] ?? 0 );

			$token = WPLLMSEO_MCP_Auth::generate_token( $user_id, $name, array(), $expires );

			if ( $token ) {
				// Store in transient for display (will be shown once)
				set_transient( 'wpllmseo_new_token_' . get_current_user_id(), $token, 300 );
				wp_redirect( add_query_arg( array( 'tab' => 'tokens', 'token_created' => '1' ), admin_url( 'admin.php?page=wpllmseo_mcp' ) ) );
				exit;
			}
			break;

		case 'revoke_token':
			$token_id = intval( $_POST['token_id'] ?? 0 );
			if ( $token_id ) {
				WPLLMSEO_MCP_Auth::revoke_token_by_id( $token_id );
				wp_redirect( add_query_arg( array( 'tab' => 'tokens', 'token_revoked' => '1' ), admin_url( 'admin.php?page=wpllmseo_mcp' ) ) );
				exit;
			}
			break;

		case 'create_llms_txt':
			WPLLMSEO_MCP_LLMsTxt::create_example_file();
			wp_redirect( add_query_arg( array( 'tab' => 'llms-txt', 'file_created' => '1' ), admin_url( 'admin.php?page=wpllmseo_mcp' ) ) );
			exit;
			break;

		case 'flush_llms_txt_cache':
			WPLLMSEO_MCP_LLMsTxt::flush_cache();
			wp_redirect( add_query_arg( array( 'tab' => 'llms-txt', 'cache_flushed' => '1' ), admin_url( 'admin.php?page=wpllmseo_mcp' ) ) );
			exit;
			break;
	}
}

// Get MCP status
$mcp_status = WPLLMSEO_MCP_Adapter::get_status();
?>

<div class="wrap">
	<h1><?php esc_html_e( 'MCP Integration', 'wpllmseo' ); ?></h1>

	<?php
	// Show notices
	if ( isset( $_GET['token_created'] ) ) {
		$new_token = get_transient( 'wpllmseo_new_token_' . get_current_user_id() );
		if ( $new_token ) {
			?>
			<div class="notice notice-success">
				<p>
					<strong><?php esc_html_e( 'Token Created Successfully!', 'wpllmseo' ); ?></strong><br>
					<?php esc_html_e( 'Copy this token now. It will not be shown again:', 'wpllmseo' ); ?><br>
					<code style="font-size:12px;background:#f0f0f0;padding:8px;display:inline-block;margin-top:5px;"><?php echo esc_html( $new_token ); ?></code>
					<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( $new_token ); ?>')">
						<?php esc_html_e( 'Copy', 'wpllmseo' ); ?>
					</button>
				</p>
			</div>
			<?php
			delete_transient( 'wpllmseo_new_token_' . get_current_user_id() );
		}
	}

	if ( isset( $_GET['token_revoked'] ) ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Token revoked successfully.', 'wpllmseo' ); ?></p>
		</div>
		<?php
	}

	if ( isset( $_GET['file_created'] ) ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Example LLMs.txt file created successfully.', 'wpllmseo' ); ?></p>
		</div>
		<?php
	}

	if ( isset( $_GET['cache_flushed'] ) ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'LLMs.txt cache flushed successfully.', 'wpllmseo' ); ?></p>
		</div>
		<?php
	}
	?>

	<h2 class="nav-tab-wrapper">
		<a href="?page=wpllmseo_mcp&tab=overview" class="nav-tab <?php echo $current_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Overview', 'wpllmseo' ); ?>
		</a>
		<a href="?page=wpllmseo_mcp&tab=tokens" class="nav-tab <?php echo $current_tab === 'tokens' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Authentication', 'wpllmseo' ); ?>
		</a>
		<a href="?page=wpllmseo_mcp&tab=llms-txt" class="nav-tab <?php echo $current_tab === 'llms-txt' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'LLMs.txt', 'wpllmseo' ); ?>
		</a>
		<a href="?page=wpllmseo_mcp&tab=audit" class="nav-tab <?php echo $current_tab === 'audit' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Audit Log', 'wpllmseo' ); ?>
		</a>
		<a href="?page=wpllmseo_mcp&tab=docs" class="nav-tab <?php echo $current_tab === 'docs' ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Documentation', 'wpllmseo' ); ?>
		</a>
	</h2>

	<div class="wpllmseo-mcp-tab-content">
		<?php
		switch ( $current_tab ) {
			case 'overview':
				require_once __DIR__ . '/tabs/mcp-overview.php';
				break;

			case 'tokens':
				require_once __DIR__ . '/tabs/mcp-tokens.php';
				break;

			case 'llms-txt':
				require_once __DIR__ . '/tabs/mcp-llmstxt.php';
				break;

			case 'audit':
				require_once __DIR__ . '/tabs/mcp-audit.php';
				break;

			case 'docs':
				require_once __DIR__ . '/tabs/mcp-docs.php';
				break;

			default:
				require_once __DIR__ . '/tabs/mcp-overview.php';
		}
		?>
	</div>
</div>

<style>
.wpllmseo-mcp-tab-content {
	margin-top: 20px;
}

.wpllmseo-status-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 3px;
	font-size: 12px;
	font-weight: 600;
}

.wpllmseo-status-badge.success {
	background: #d4edda;
	color: #155724;
}

.wpllmseo-status-badge.error {
	background: #f8d7da;
	color: #721c24;
}

.wpllmseo-status-badge.warning {
	background: #fff3cd;
	color: #856404;
}

.wpllmseo-stat-card {
	background: white;
	border: 1px solid #ccd0d4;
	border-radius: 4px;
	padding: 20px;
	margin-bottom: 20px;
}

.wpllmseo-stat-card h3 {
	margin-top: 0;
	font-size: 14px;
	color: #646970;
	font-weight: 600;
	text-transform: uppercase;
}

.wpllmseo-stat-card .stat-value {
	font-size: 32px;
	font-weight: 700;
	color: #1d2327;
	margin: 10px 0;
}

.wpllmseo-stat-card .stat-label {
	font-size: 13px;
	color: #646970;
}

.wpllmseo-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
	margin: 20px 0;
}
</style>
