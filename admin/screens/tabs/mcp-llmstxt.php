<?php
/**
 * MCP LLMs.txt Management Tab
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get LLMs.txt stats.
$llmstxt_stats = WPLLMSEO_MCP_LLMsTxt::get_stats();
$file_path     = ABSPATH . 'llms.txt';
$file_exists   = file_exists( $file_path );
$rules         = $file_exists ? WPLLMSEO_MCP_LLMsTxt::get_rules() : array();
?>

<div class="wpllmseo-mcp-llmstxt">
	<h2><?php esc_html_e( 'LLMs.txt Configuration', 'wpllmseo' ); ?></h2>
	<p class="description">
		<?php
		printf(
			/* translators: %s: URL to LLMs.txt spec */
			esc_html__( 'Control which content is accessible to LLM automation. Learn more about %s.', 'wpllmseo' ),
			'<a href="https://llmstxt.org/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'LLMs.txt specification', 'wpllmseo' ) . '</a>'
		);
		?>
	</p>

	<!-- File Status -->
	<div class="wpllmseo-card">
		<h3><?php esc_html_e( 'File Status', 'wpllmseo' ); ?></h3>
		<table class="wpllmseo-stats-grid">
			<tr>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'File Status', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value">
							<?php if ( $file_exists ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Active', 'wpllmseo' ); ?></span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-warning"><?php esc_html_e( 'Not Found', 'wpllmseo' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Allow Rules', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value">
							<?php echo esc_html( $llmstxt_stats['allow_count'] ); ?>
						</div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Disallow Rules', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value">
							<?php echo esc_html( $llmstxt_stats['disallow_count'] ); ?>
						</div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Cache Status', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value">
							<?php if ( $llmstxt_stats['cached'] ) : ?>
								<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Cached', 'wpllmseo' ); ?></span>
							<?php else : ?>
								<span class="wpllmseo-badge wpllmseo-badge-error"><?php esc_html_e( 'Not Cached', 'wpllmseo' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</td>
			</tr>
		</table>

		<p style="margin-top: 15px;">
			<strong><?php esc_html_e( 'File Location:', 'wpllmseo' ); ?></strong>
			<code><?php echo esc_html( $file_path ); ?></code>
		</p>

		<?php if ( ! $file_exists ) : ?>
			<form method="post" style="margin-top: 15px;">
				<?php wp_nonce_field( 'wpllmseo_mcp_action', 'wpllmseo_mcp_nonce' ); ?>
				<input type="hidden" name="action" value="create_llms_txt" />
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Create Example File', 'wpllmseo' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'This will create a sample llms.txt file with common directives', 'wpllmseo' ); ?>
				</p>
			</form>
		<?php else : ?>
			<form method="post" style="margin-top: 15px; display: inline-block;">
				<?php wp_nonce_field( 'wpllmseo_mcp_action', 'wpllmseo_mcp_nonce' ); ?>
				<input type="hidden" name="action" value="flush_llms_txt_cache" />
				<button type="submit" class="button">
					<?php esc_html_e( 'Flush Cache', 'wpllmseo' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<!-- Current Rules -->
	<?php if ( $file_exists && ! empty( $rules ) ) : ?>
		<div class="wpllmseo-card" style="margin-top: 20px;">
			<h3><?php esc_html_e( 'Current Rules', 'wpllmseo' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 20%;"><?php esc_html_e( 'Type', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Pattern', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rules as $rule ) : ?>
						<tr>
							<td>
								<?php if ( 'allow' === $rule['type'] ) : ?>
									<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Allow', 'wpllmseo' ); ?></span>
								<?php else : ?>
									<span class="wpllmseo-badge wpllmseo-badge-error"><?php esc_html_e( 'Disallow', 'wpllmseo' ); ?></span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $rule['pattern'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<!-- Test Path Tool -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Test Path', 'wpllmseo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Check if a path would be allowed or disallowed by your current rules', 'wpllmseo' ); ?>
		</p>
		
		<form id="wpllmseo-test-path-form" style="margin-top: 15px;">
			<input type="text" 
			       id="test_path_input" 
			       class="regular-text" 
			       placeholder="/example/path" />
			<button type="submit" class="button">
				<?php esc_html_e( 'Test', 'wpllmseo' ); ?>
			</button>
		</form>
		
		<div id="test_path_result" style="margin-top: 15px; display: none;">
			<strong><?php esc_html_e( 'Result:', 'wpllmseo' ); ?></strong>
			<span id="test_path_result_text"></span>
		</div>
	</div>

	<!-- Example File -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Example Configuration', 'wpllmseo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Here\'s an example llms.txt file with common directives:', 'wpllmseo' ); ?>
		</p>
		<pre style="background: #f5f5f5; padding: 15px; overflow-x: auto; border: 1px solid #ddd;"><?php
echo esc_html(
'# LLMs.txt - Control LLM access to your content
# Learn more: https://llmstxt.org/

# Allow public blog posts
Allow: /blog/

# Allow documentation
Allow: /docs/

# Disallow admin pages
Disallow: /wp-admin/
Disallow: /wp-login.php

# Disallow private content
Disallow: /private/
Disallow: /draft/

# Disallow specific post types
Disallow: /attachment/

# Allow everything else
Allow: /'
);
		?></pre>
	</div>
</div>

<script>
jQuery(document).ready(function($) {
	$('#wpllmseo-test-path-form').on('submit', function(e) {
		e.preventDefault();
		var path = $('#test_path_input').val();
		
		if (!path) {
			return;
		}
		
		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'wpllmseo_test_llmstxt_path',
				path: path,
				nonce: '<?php echo esc_js( wp_create_nonce( 'wpllmseo_test_path' ) ); ?>'
			},
			success: function(response) {
				if (response.success) {
					var result = response.data.disallowed ? 
						'<span class="wpllmseo-badge wpllmseo-badge-error">Disallowed</span>' :
						'<span class="wpllmseo-badge wpllmseo-badge-success">Allowed</span>';
					
					if (response.data.matched_rule) {
						result += ' (matched: <code>' + response.data.matched_rule + '</code>)';
					}
					
					$('#test_path_result_text').html(result);
					$('#test_path_result').show();
				}
			}
		});
	});
});
</script>
