<?php
/**
 * MCP Overview Tab
 *
 * @package WP_LLM_SEO_Indexing
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mcp_status = WPLLMSEO_MCP_Adapter::get_status();
$token_stats = WPLLMSEO_MCP_Auth::get_stats();
$audit_stats = WPLLMSEO_MCP_Audit::get_stats( 'day' );
$llmstxt_stats = WPLLMSEO_MCP_LLMsTxt::get_stats();
?>

<div class="wpllmseo-mcp-overview">
	<h2><?php esc_html_e( 'MCP Integration Status', 'wpllmseo' ); ?></h2>

	<div class="wpllmseo-grid">
		<div class="wpllmseo-stat-card">
			<h3><?php esc_html_e( 'MCP Status', 'wpllmseo' ); ?></h3>
			<div class="stat-value">
				<?php if ( $mcp_status['enabled'] && $mcp_status['adapter_available'] ) : ?>
					<span class="wpllmseo-status-badge success"><?php esc_html_e( 'Active', 'wpllmseo' ); ?></span>
				<?php elseif ( $mcp_status['enabled'] ) : ?>
					<span class="wpllmseo-status-badge warning"><?php esc_html_e( 'Enabled (No Adapter)', 'wpllmseo' ); ?></span>
				<?php else : ?>
					<span class="wpllmseo-status-badge error"><?php esc_html_e( 'Disabled', 'wpllmseo' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="stat-label">
				<?php printf( esc_html__( '%d abilities registered', 'wpllmseo' ), (int) $mcp_status['abilities_count'] ); ?>
			</div>
		</div>

		<div class="wpllmseo-stat-card">
			<h3><?php esc_html_e( 'Active Tokens', 'wpllmseo' ); ?></h3>
			<div class="stat-value"><?php echo (int) $token_stats['active']; ?></div>
			<div class="stat-label">
				<?php printf( esc_html__( '%d revoked, %d expired', 'wpllmseo' ), (int) $token_stats['revoked'], (int) $token_stats['expired'] ); ?>
			</div>
		</div>

		<div class="wpllmseo-stat-card">
			<h3><?php esc_html_e( 'Requests (24h)', 'wpllmseo' ); ?></h3>
			<div class="stat-value"><?php echo (int) $audit_stats['total_requests']; ?></div>
			<div class="stat-label">
				<?php printf( esc_html__( '%d unique callers', 'wpllmseo' ), (int) $audit_stats['unique_callers'] ); ?>
			</div>
		</div>

		<div class="wpllmseo-stat-card">
			<h3><?php esc_html_e( 'LLMs.txt', 'wpllmseo' ); ?></h3>
			<div class="stat-value">
				<?php if ( $llmstxt_stats['file_exists'] ) : ?>
					<span class="wpllmseo-status-badge success"><?php esc_html_e( 'Active', 'wpllmseo' ); ?></span>
				<?php else : ?>
					<span class="wpllmseo-status-badge warning"><?php esc_html_e( 'Not Found', 'wpllmseo' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="stat-label">
				<?php printf( esc_html__( '%d allow, %d disallow rules', 'wpllmseo' ), (int) $llmstxt_stats['allow_count'], (int) $llmstxt_stats['disallow_count'] ); ?>
			</div>
		</div>
	</div>

	<h3><?php esc_html_e( 'Registered Abilities', 'wpllmseo' ); ?></h3>
	<?php if ( ! empty( $mcp_status['abilities'] ) ) : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ability Name', 'wpllmseo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mcp_status['abilities'] as $ability_name ) : ?>
					<tr>
						<td><code><?php echo esc_html( $ability_name ); ?></code></td>
						<td><span class="wpllmseo-status-badge success"><?php esc_html_e( 'Registered', 'wpllmseo' ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'No abilities registered. Enable MCP in Settings to register abilities.', 'wpllmseo' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Quick Actions', 'wpllmseo' ); ?></h3>
	<p>
		<a href="?page=wpllmseo_settings#mcp" class="button"><?php esc_html_e( 'Configure MCP Settings', 'wpllmseo' ); ?></a>
		<a href="?page=wpllmseo-mcp&tab=tokens" class="button button-primary"><?php esc_html_e( 'Manage Tokens', 'wpllmseo' ); ?></a>
		<a href="?page=wpllmseo-mcp&tab=docs" class="button"><?php esc_html_e( 'View Documentation', 'wpllmseo' ); ?></a>
	</p>

	<?php if ( ! $mcp_status['enabled'] ) : ?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'MCP Integration is Disabled', 'wpllmseo' ); ?></strong><br>
				<?php esc_html_e( 'Enable MCP in Settings to start using automated workflows and API access.', 'wpllmseo' ); ?>
			</p>
		</div>
	<?php elseif ( ! $mcp_status['adapter_available'] ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'MCP Adapter Not Found', 'wpllmseo' ); ?></strong><br>
				<?php esc_html_e( 'Install and activate the WordPress MCP adapter plugin to use this feature.', 'wpllmseo' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>
