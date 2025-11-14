<?php
/**
 * MCP Documentation Tab
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get MCP status.
$mcp_status   = WPLLMSEO_MCP_Adapter::get_status();
$is_enabled   = WPLLMSEO_MCP_Adapter::is_enabled();
$abilities    = WPLLMSEO_MCP_Adapter::get_ability_definitions();
$api_endpoint = rest_url( 'mcp/v1/' );
?>

<div class="wpllmseo-mcp-docs">
	<h2><?php esc_html_e( 'MCP Integration Documentation', 'wpllmseo' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Learn how to use the Model Context Protocol integration for automation workflows.', 'wpllmseo' ); ?>
	</p>

	<!-- Getting Started -->
	<div class="wpllmseo-card">
		<h3><?php esc_html_e( 'Getting Started', 'wpllmseo' ); ?></h3>
		
		<ol style="margin-left: 20px;">
			<li>
				<strong><?php esc_html_e( 'Install WordPress MCP Adapter', 'wpllmseo' ); ?></strong>
				<p><?php esc_html_e( 'The MCP Adapter plugin is required to enable MCP integration.', 'wpllmseo' ); ?></p>
				<?php if ( ! $mcp_status['adapter_available'] ) : ?>
					<p>
						<span class="wpllmseo-badge wpllmseo-badge-warning"><?php esc_html_e( 'Not Installed', 'wpllmseo' ); ?></span>
						<?php esc_html_e( 'Please install and activate the WordPress MCP Adapter plugin.', 'wpllmseo' ); ?>
					</p>
				<?php else : ?>
					<p>
						<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Installed', 'wpllmseo' ); ?></span>
					</p>
				<?php endif; ?>
			</li>
			
			<li>
				<strong><?php esc_html_e( 'Enable MCP Integration', 'wpllmseo' ); ?></strong>
				<p>
					<?php
					printf(
						/* translators: %s: URL to settings page */
						esc_html__( 'Go to %s and enable the MCP Integration option.', 'wpllmseo' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_settings#mcp' ) ) . '">' . esc_html__( 'Settings', 'wpllmseo' ) . '</a>'
					);
					?>
				</p>
				<?php if ( ! $is_enabled ) : ?>
					<p>
						<span class="wpllmseo-badge wpllmseo-badge-warning"><?php esc_html_e( 'Disabled', 'wpllmseo' ); ?></span>
						<?php esc_html_e( 'MCP integration is currently disabled.', 'wpllmseo' ); ?>
					</p>
				<?php else : ?>
					<p>
						<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Enabled', 'wpllmseo' ); ?></span>
					</p>
				<?php endif; ?>
			</li>
			
			<li>
				<strong><?php esc_html_e( 'Generate Access Token', 'wpllmseo' ); ?></strong>
				<p>
					<?php
					printf(
						/* translators: %s: URL to tokens page */
						esc_html__( 'Create a token in the %s tab to authenticate your MCP client.', 'wpllmseo' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_mcp&tab=tokens' ) ) . '">' . esc_html__( 'Tokens', 'wpllmseo' ) . '</a>'
					);
					?>
				</p>
			</li>
			
			<li>
				<strong><?php esc_html_e( 'Configure Your Client', 'wpllmseo' ); ?></strong>
				<p><?php esc_html_e( 'Use the examples below to integrate with your automation tools.', 'wpllmseo' ); ?></p>
			</li>
		</ol>
	</div>

	<!-- Available Abilities -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Available Abilities', 'wpllmseo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'The following MCP abilities are available for automation:', 'wpllmseo' ); ?>
		</p>
		
		<?php foreach ( $abilities as $ability ) : ?>
			<div class="wpllmseo-ability-doc" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">
				<h4 style="margin-top: 0;">
					<code><?php echo esc_html( $ability['name'] ); ?></code>
					<?php if ( ! empty( $ability['capability_required'] ) ) : ?>
						<span class="wpllmseo-badge wpllmseo-badge-error" style="margin-left: 10px;">
							<?php echo esc_html( $ability['capability_required'] ); ?>
						</span>
					<?php endif; ?>
				</h4>
				<p><?php echo esc_html( $ability['description'] ); ?></p>
				
				<details style="margin-top: 10px;">
					<summary style="cursor: pointer; font-weight: bold;"><?php esc_html_e( 'Input Schema', 'wpllmseo' ); ?></summary>
					<pre style="margin-top: 10px; background: #fff; padding: 10px; overflow-x: auto; border: 1px solid #ddd;"><?php echo esc_html( wp_json_encode( $ability['input_schema'], JSON_PRETTY_PRINT ) ); ?></pre>
				</details>
				
				<details style="margin-top: 10px;">
					<summary style="cursor: pointer; font-weight: bold;"><?php esc_html_e( 'Output Schema', 'wpllmseo' ); ?></summary>
					<pre style="margin-top: 10px; background: #fff; padding: 10px; overflow-x: auto; border: 1px solid #ddd;"><?php echo esc_html( wp_json_encode( $ability['output_schema'], JSON_PRETTY_PRINT ) ); ?></pre>
				</details>
			</div>
		<?php endforeach; ?>
	</div>

	<!-- Authentication -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Authentication', 'wpllmseo' ); ?></h3>
		<p><?php esc_html_e( 'All MCP requests must include a valid access token in the Authorization header:', 'wpllmseo' ); ?></p>
		
		<pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; overflow-x: auto;">Authorization: Bearer YOUR_TOKEN_HERE</pre>
		
		<p style="margin-top: 15px;">
			<?php esc_html_e( 'Tokens inherit the capabilities of the WordPress user who created them. Make sure your user has the necessary permissions for the abilities you want to use.', 'wpllmseo' ); ?>
		</p>
	</div>

	<!-- Example: Fetch Post Summary -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Example: Fetch Post Summary', 'wpllmseo' ); ?></h3>
		<p><?php esc_html_e( 'Get SEO metadata and content sample for a post:', 'wpllmseo' ); ?></p>
		
		<pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; overflow-x: auto;">curl -X POST <?php echo esc_html( $api_endpoint ); ?>call \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ability": "llmseo.fetch_post_summary",
    "input": {
      "post_id": 123
    }
  }'</pre>
	</div>

	<!-- Example: Generate Snippet -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Example: Generate AI Snippet', 'wpllmseo' ); ?></h3>
		<p><?php esc_html_e( 'Generate an AI snippet for a post (async):', 'wpllmseo' ); ?></p>
		
		<pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; overflow-x: auto;">curl -X POST <?php echo esc_html( $api_endpoint ); ?>call \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ability": "llmseo.generate_snippet_preview",
    "input": {
      "post_id": 123,
      "dry_run": false
    }
  }'</pre>
	</div>

	<!-- Example: Bulk Job -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Example: Start Bulk Job', 'wpllmseo' ); ?></h3>
		<p><?php esc_html_e( 'Generate snippets for multiple posts:', 'wpllmseo' ); ?></p>
		
		<pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; overflow-x: auto;">curl -X POST <?php echo esc_html( $api_endpoint ); ?>call \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ability": "llmseo.start_bulk_snippet_job",
    "input": {
      "filters": {
        "post_type": ["post"],
        "post_status": ["publish"]
      },
      "skip_existing": true
    }
  }'</pre>
	</div>

	<!-- Example: Query Job Status -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Example: Query Job Status', 'wpllmseo' ); ?></h3>
		<p><?php esc_html_e( 'Check the progress of a bulk job:', 'wpllmseo' ); ?></p>
		
		<pre style="background: #2c3e50; color: #ecf0f1; padding: 15px; overflow-x: auto;">curl -X POST <?php echo esc_html( $api_endpoint ); ?>call \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "ability": "llmseo.query_job_status",
    "input": {
      "job_id": "bulk_123"
    }
  }'</pre>
	</div>

	<!-- LLMs.txt Support -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'LLMs.txt Support', 'wpllmseo' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: URL to LLMs.txt tab */
				esc_html__( 'The plugin respects /llms.txt directives to control which content is accessible to LLM automation. Configure your rules in the %s tab.', 'wpllmseo' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_mcp&tab=llmstxt' ) ) . '">' . esc_html__( 'LLMs.txt', 'wpllmseo' ) . '</a>'
			);
			?>
		</p>
		
		<p><?php esc_html_e( 'Disallowed paths will be automatically skipped in bulk operations and return errors for direct requests.', 'wpllmseo' ); ?></p>
	</div>

	<!-- Rate Limits & Best Practices -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Best Practices', 'wpllmseo' ); ?></h3>
		
		<ul style="margin-left: 20px;">
			<li><?php esc_html_e( 'Use bulk operations instead of individual requests for better performance', 'wpllmseo' ); ?></li>
			<li><?php esc_html_e( 'Enable skip_existing to avoid regenerating snippets unnecessarily', 'wpllmseo' ); ?></li>
			<li><?php esc_html_e( 'Monitor audit logs regularly for unusual activity', 'wpllmseo' ); ?></li>
			<li><?php esc_html_e( 'Rotate tokens periodically for security', 'wpllmseo' ); ?></li>
			<li><?php esc_html_e( 'Use dry_run mode to preview changes before applying them', 'wpllmseo' ); ?></li>
			<li><?php esc_html_e( 'Set appropriate token expiration periods based on your security requirements', 'wpllmseo' ); ?></li>
		</ul>
	</div>

	<!-- Support -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Need Help?', 'wpllmseo' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: 1: Audit logs URL, 2: Settings URL */
				esc_html__( 'Check the %1$s for debugging information or review your %2$s.', 'wpllmseo' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_mcp&tab=audit' ) ) . '">' . esc_html__( 'audit logs', 'wpllmseo' ) . '</a>',
				'<a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_settings' ) ) . '">' . esc_html__( 'settings', 'wpllmseo' ) . '</a>'
			);
			?>
		</p>
	</div>
</div>
