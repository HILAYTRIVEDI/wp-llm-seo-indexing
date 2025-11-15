<?php
/**
 * Settings screen.
 *
 * Plugin configuration and settings page.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load component files.
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

// Get current settings.
$settings = wp_parse_args(
	get_option( 'wpllmseo_settings', array() ),
	array(
		'api_key'                  => '',
		'model'                    => WPLLMSEO_GEMINI_MODEL,
		'auto_index'               => false,
		'batch_size'               => 10,
		'enable_logging'           => true,
		'theme_mode'               => 'auto',
		'enable_ai_sitemap'        => true,
		'content_license'          => 'GPL',
		'prefer_external_seo'      => true,
		'use_similarity_threshold' => true,
		'enable_llm_jsonld'        => false,
	)
);

// Get SEO compatibility status.
$compat_status = WPLLMSEO_SEO_Compat::get_compat_status();

// Check for success message.
$updated = isset( $_GET['updated'] ) && 'true' === $_GET['updated'];

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( 'Settings', 'wpllmseo' ),
		'description' => __( 'Configure your AI SEO & Indexing plugin', 'wpllmseo' ),
	)
);
?>

<div class="wpllmseo-settings">
	<?php if ( $updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved successfully.', 'wpllmseo' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_settings' ) ); ?>" class="wpllmseo-settings-form">
		<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>

		<!-- SEO Plugin Compatibility -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'SEO Plugin Compatibility', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure compatibility with Yoast SEO and Rank Math.', 'wpllmseo' ); ?>
			</p>

			<?php if ( 'none' !== $compat_status['active_plugin'] ) : ?>
				<div class="notice notice-<?php echo esc_attr( $compat_status['level'] ); ?> inline">
					<p><?php echo esc_html( $compat_status['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Prefer External SEO Plugin', 'wpllmseo' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" 
									       id="prefer_external_seo" 
									       name="prefer_external_seo" 
									       value="1" 
									       <?php checked( $settings['prefer_external_seo'], true ); ?> />
									<?php esc_html_e( 'Use Yoast/Rank Math meta tags instead of generating duplicates', 'wpllmseo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, this plugin will use meta tags from Yoast or Rank Math for LLM features and avoid duplicate output.', 'wpllmseo' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Enable LLM JSON-LD', 'wpllmseo' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" 
									       id="enable_llm_jsonld" 
									       name="enable_llm_jsonld" 
									       value="1" 
									       <?php checked( $settings['enable_llm_jsonld'], true ); ?> />
									<?php esc_html_e( 'Output LLM-optimized JSON-LD structured data', 'wpllmseo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Adds semantic metadata for LLM crawlers including AI summaries, embeddings info, and semantic keywords.', 'wpllmseo' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Use Similarity Threshold', 'wpllmseo' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" 
									       id="use_similarity_threshold" 
									       name="use_similarity_threshold" 
									       value="1" 
									       <?php checked( $settings['use_similarity_threshold'], true ); ?> />
									<?php esc_html_e( 'Skip re-indexing when content changes are below 95% similarity', 'wpllmseo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Prevents unnecessary API calls for minor content changes like typo fixes or small edits.', 'wpllmseo' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Indexing Options -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Indexing Options', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Control how content is indexed and processed.', 'wpllmseo' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Auto Index', 'wpllmseo' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" 
									       id="auto_index" 
									       name="auto_index" 
									       value="1" 
									       <?php checked( $settings['auto_index'], true ); ?> />
									<?php esc_html_e( 'Automatically index new and updated content', 'wpllmseo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, content will be added to the indexing queue automatically when published or updated.', 'wpllmseo' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="batch_size"><?php esc_html_e( 'Batch Size', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="number" 
							       id="batch_size" 
							       name="batch_size" 
							       value="<?php echo esc_attr( $settings['batch_size'] ); ?>" 
							       min="1" 
							       max="50" 
							       class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Number of items to process in each batch. Lower values (5-10) optimize token usage.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="daily_token_limit"><?php esc_html_e( 'Daily Token Limit', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="number" 
							       id="daily_token_limit" 
							       name="daily_token_limit" 
							       value="<?php echo esc_attr( isset( $settings['daily_token_limit'] ) ? $settings['daily_token_limit'] : 100000 ); ?>" 
							       min="1000" 
							       max="10000000" 
							       step="1000" 
							       class="regular-text" />
							<p class="description">
								<?php 
								if ( class_exists( 'WPLLMSEO_Token_Tracker' ) ) {
									$tracker = new WPLLMSEO_Token_Tracker();
									$usage = $tracker->get_daily_usage();
									printf(
										/* translators: 1: tokens used, 2: percentage */
										esc_html__( 'Maximum tokens per day. Today: %1$d tokens (%.1f%%). Prevents excessive API costs.', 'wpllmseo' ),
										$usage['tokens_used'],
										$tracker->get_usage_percentage()
									);
								} else {
									esc_html_e( 'Maximum tokens to use per day. This prevents excessive API costs.', 'wpllmseo' );
								}
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- General Settings -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'General Settings', 'wpllmseo' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Enable Logging', 'wpllmseo' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" 
									       id="enable_logging" 
									       name="enable_logging" 
									       value="1" 
									       <?php checked( $settings['enable_logging'], true ); ?> />
									<?php esc_html_e( 'Enable activity logging', 'wpllmseo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Logs will be stored in the plugin directory. Disable to improve performance.', 'wpllmseo' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="theme_mode"><?php esc_html_e( 'Theme Mode', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select id="theme_mode" name="theme_mode">
								<option value="auto" <?php selected( $settings['theme_mode'], 'auto' ); ?>>
									<?php esc_html_e( 'Auto (Follow System)', 'wpllmseo' ); ?>
								</option>
								<option value="light" <?php selected( $settings['theme_mode'], 'light' ); ?>>
									<?php esc_html_e( 'Light', 'wpllmseo' ); ?>
								</option>
								<option value="dark" <?php selected( $settings['theme_mode'], 'dark' ); ?>>
									<?php esc_html_e( 'Dark', 'wpllmseo' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose your preferred theme mode for the admin interface.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- AI Sitemap Settings -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'AI Sitemap JSONL', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Configure the AI Sitemap JSONL endpoint for LLM crawlers.', 'wpllmseo' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Enable AI Sitemap', 'wpllmseo' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" 
									       id="enable_ai_sitemap" 
									       name="enable_ai_sitemap" 
									       value="1" 
									       <?php checked( $settings['enable_ai_sitemap'], true ); ?> />
									<?php esc_html_e( 'Enable public AI Sitemap JSONL endpoint', 'wpllmseo' ); ?>
								</label>
								<p class="description">
									<?php
									$sitemap_url = home_url( '/ai-sitemap.jsonl' );
									printf(
										/* translators: %s: AI Sitemap URL */
										esc_html__( 'When enabled, sitemap will be available at: %s', 'wpllmseo' ),
										'<code>' . esc_html( $sitemap_url ) . '</code>'
									);
									?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="content_license"><?php esc_html_e( 'Content License', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select id="content_license" name="content_license">
								<option value="GPL" <?php selected( $settings['content_license'], 'GPL' ); ?>>
									<?php esc_html_e( 'GPL (General Public License)', 'wpllmseo' ); ?>
								</option>
								<option value="CC-BY" <?php selected( $settings['content_license'], 'CC-BY' ); ?>>
									<?php esc_html_e( 'CC-BY (Creative Commons Attribution)', 'wpllmseo' ); ?>
								</option>
								<option value="CC-BY-SA" <?php selected( $settings['content_license'], 'CC-BY-SA' ); ?>>
									<?php esc_html_e( 'CC-BY-SA (Creative Commons ShareAlike)', 'wpllmseo' ); ?>
								</option>
								<option value="Custom" <?php selected( $settings['content_license'], 'Custom' ); ?>>
									<?php esc_html_e( 'Custom License', 'wpllmseo' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'License information included in AI Sitemap for LLM attribution.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Sitemap Actions', 'wpllmseo' ); ?>
						</th>
						<td>
							<button type="button" id="wpllmseo-regenerate-sitemap" class="button">
								<span class="dashicons dashicons-update"></span>
								<?php esc_html_e( 'Regenerate AI Sitemap', 'wpllmseo' ); ?>
							</button>
							<p class="description">
								<?php
								$sitemap = new WPLLMSEO_AI_Sitemap();
								$last_generated = $sitemap->get_last_generated();
								$file_size = $sitemap->get_cache_size();
								
								if ( $last_generated ) {
									printf(
										/* translators: 1: Time ago, 2: File size */
										esc_html__( 'Last generated: %1$s ago (%2$s)', 'wpllmseo' ),
										esc_html( human_time_diff( $last_generated, current_time( 'timestamp' ) ) ),
										$file_size ? esc_html( size_format( $file_size ) ) : esc_html__( 'Unknown', 'wpllmseo' )
									);
								} else {
									esc_html_e( 'Not generated yet.', 'wpllmseo' );
								}
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Bulk AI Snippet Generator -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Bulk AI Snippet Generator', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Generate AI snippets for all posts in bulk. By default, posts with existing snippets will be skipped.', 'wpllmseo' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Bulk Generation', 'wpllmseo' ); ?>
						</th>
						<td>
							<div class="wpllmseo-bulk-controls">
								<label>
									<input type="checkbox" id="wpllmseo-skip-existing" checked />
									<?php esc_html_e( 'Skip posts with existing AI snippets', 'wpllmseo' ); ?>
								</label>
								<br><br>
								<label>
									<?php esc_html_e( 'Batch size:', 'wpllmseo' ); ?>
									<input type="number" id="wpllmseo-batch-size" value="10" min="1" max="50" class="small-text" />
								</label>
								<br><br>
								<button type="button" id="wpllmseo-dry-run" class="button">
									<span class="dashicons dashicons-visibility"></span>
									<?php esc_html_e( 'Preview (Dry Run)', 'wpllmseo' ); ?>
								</button>
								<button type="button" id="wpllmseo-start-bulk" class="button button-primary">
									<span class="dashicons dashicons-admin-generic"></span>
									<?php esc_html_e( 'Start Bulk Generation', 'wpllmseo' ); ?>
								</button>
							</div>
							<div id="wpllmseo-bulk-status" style="display:none; margin-top: 15px;"></div>
							<p class="description">
								<?php esc_html_e( 'Note: Bulk generation respects LLMs.txt restrictions if the file exists in your site root.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- LLM Sitemap Hub -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'LLM Sitemap Hub', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Multiple specialized sitemap endpoints for different LLM consumption patterns.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sitemap_hub_public"><?php esc_html_e( 'Public Access', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="sitemap_hub_public" 
								       id="sitemap_hub_public" 
								       value="1" 
								       <?php checked( ! empty( $settings['sitemap_hub_public'] ) ); ?> />
								<?php esc_html_e( 'Allow public access to sitemap hub endpoints', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'If disabled, endpoints require an access token.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sitemap_hub_token"><?php esc_html_e( 'Access Token', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="text" 
							       name="sitemap_hub_token" 
							       id="sitemap_hub_token" 
							       value="<?php echo esc_attr( $settings['sitemap_hub_token'] ?? '' ); ?>" 
							       class="regular-text" 
							       placeholder="<?php esc_attr_e( 'Leave empty to auto-generate', 'wpllmseo' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Optional token for authenticated access. Auto-generated if empty.', 'wpllmseo' ); ?>
							</p>
							<p style="margin-top:8px;">
								<strong><?php esc_html_e( 'Available Endpoints:', 'wpllmseo' ); ?></strong><br>
								<code><?php echo esc_html( home_url( '/llm-sitemap.jsonl' ) ); ?></code> - Main sitemap<br>
								<code><?php echo esc_html( home_url( '/llm-snippets.jsonl' ) ); ?></code> - AI snippets<br>
								<code><?php echo esc_html( home_url( '/llm-semantic-map.json' ) ); ?></code> - Relationships<br>
								<code><?php echo esc_html( home_url( '/llm-context-graph.jsonl' ) ); ?></code> - Taxonomy context
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- LLM Content API -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'LLM Content API', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Secure REST API for programmatic content access with rate limiting.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="llm_api_public"><?php esc_html_e( 'Public Access', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="llm_api_public" 
								       id="llm_api_public" 
								       value="1" 
								       <?php checked( ! empty( $settings['llm_api_public'] ) ); ?> />
								<?php esc_html_e( 'Allow public API access (no token required)', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Enable for ChatGPT/Claude crawlers. Disable to require authentication.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="llm_api_token"><?php esc_html_e( 'API Token', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="text" 
							       name="llm_api_token" 
							       id="llm_api_token" 
							       value="<?php echo esc_attr( $settings['llm_api_token'] ?? '' ); ?>" 
							       class="regular-text" 
							       placeholder="<?php esc_attr_e( 'Leave empty to auto-generate', 'wpllmseo' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Token for authenticated API requests. Auto-generated if empty.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="llm_api_rate_limit"><?php esc_html_e( 'Rate Limiting', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="llm_api_rate_limit" 
								       id="llm_api_rate_limit" 
								       value="1" 
								       <?php checked( ! empty( $settings['llm_api_rate_limit'] ) ); ?> />
								<?php esc_html_e( 'Enable rate limiting', 'wpllmseo' ); ?>
							</label>
							<div style="margin-top:10px;">
								<label for="llm_api_rate_limit_value">
									<?php esc_html_e( 'Max requests per hour:', 'wpllmseo' ); ?>
								</label>
								<input type="number" 
								       name="llm_api_rate_limit_value" 
								       id="llm_api_rate_limit_value" 
								       value="<?php echo esc_attr( $settings['llm_api_rate_limit_value'] ?? 100 ); ?>" 
								       min="10" 
								       max="10000" 
								       style="width:100px;" />
							</div>
							<p class="description">
								<?php esc_html_e( 'Limits requests per IP address or token to prevent abuse.', 'wpllmseo' ); ?>
							</p>
							<p style="margin-top:8px;">
								<strong><?php esc_html_e( 'API Endpoints:', 'wpllmseo' ); ?></strong><br>
								<code><?php echo esc_html( rest_url( 'llm/v1/post/{id}' ) ); ?></code> - Get single post<br>
								<code><?php echo esc_html( rest_url( 'llm/v1/search?q={query}' ) ); ?></code> - Search posts<br>
								<code><?php echo esc_html( rest_url( 'llm/v1/batch' ) ); ?></code> - Batch request (POST)
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Semantic Internal Linking -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Semantic Internal Linking', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'AI-powered internal linking suggestions based on content similarity.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="enable_semantic_linking"><?php esc_html_e( 'Enable Semantic Linking', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="enable_semantic_linking" 
								       id="enable_semantic_linking" 
								       value="1" 
								       <?php checked( ! empty( $settings['enable_semantic_linking'] ) ); ?> />
								<?php esc_html_e( 'Show internal link suggestions in post editor', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Uses embedding similarity to recommend related posts for internal linking.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="semantic_linking_threshold"><?php esc_html_e( 'Similarity Threshold', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="range" 
							       name="semantic_linking_threshold" 
							       id="semantic_linking_threshold" 
							       value="<?php echo esc_attr( $settings['semantic_linking_threshold'] ?? 0.75 ); ?>" 
							       min="0.5" 
							       max="0.95" 
							       step="0.05" 
							       style="width:300px;" />
							<span id="semantic_threshold_value"><?php echo esc_html( ( $settings['semantic_linking_threshold'] ?? 0.75 ) * 100 ); ?>%</span>
							<p class="description">
								<?php esc_html_e( 'Minimum similarity score to suggest a link (75% recommended).', 'wpllmseo' ); ?>
							</p>
							<script>
							document.getElementById('semantic_linking_threshold').addEventListener('input', function(e) {
								document.getElementById('semantic_threshold_value').textContent = Math.round(e.target.value * 100) + '%';
							});
							</script>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="semantic_linking_max_suggestions"><?php esc_html_e( 'Max Suggestions', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="number" 
							       name="semantic_linking_max_suggestions" 
							       id="semantic_linking_max_suggestions" 
							       value="<?php echo esc_attr( $settings['semantic_linking_max_suggestions'] ?? 5 ); ?>" 
							       min="1" 
							       max="10" 
							       style="width:100px;" />
							<p class="description">
								<?php esc_html_e( 'Maximum number of link suggestions to show per post.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Media Embeddings -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Media Embeddings (PDF/Documents)', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Generate embeddings for PDF files and documents in media library.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="enable_media_embeddings"><?php esc_html_e( 'Enable Media Embeddings', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="enable_media_embeddings" 
								       id="enable_media_embeddings" 
								       value="1" 
								       <?php checked( ! empty( $settings['enable_media_embeddings'] ) ); ?> />
								<?php esc_html_e( 'Automatically index uploaded PDFs and documents', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Supported: PDF, TXT, CSV, DOC, DOCX (max 10MB)', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Model Management -->
		<?php WPLLMSEO_Model_Manager::render_ui(); ?>

		<!-- Crawler Logs -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Crawler Verification', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Track and log LLM crawler access attempts.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="enable_crawler_logs"><?php esc_html_e( 'Enable Logging', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="enable_crawler_logs" 
								       id="enable_crawler_logs" 
								       value="1" 
								       <?php checked( ! empty( $settings['enable_crawler_logs'] ) ); ?> />
								<?php esc_html_e( 'Log all LLM crawler access attempts', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'View detailed logs in Crawler Logs page.', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- HTML Renderer -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'LLM-Optimized HTML Renderer', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Render clean, LLM-friendly HTML versions of content.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="enable_html_renderer"><?php esc_html_e( 'Enable Renderer', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="enable_html_renderer" 
								       id="enable_html_renderer" 
								       value="1" 
								       <?php checked( ! empty( $settings['enable_html_renderer'] ) ); ?> />
								<?php esc_html_e( 'Add LLM HTML link tag to head', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Access via ?llm_render=1 query parameter', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- MCP Integration (v1.1.0+) -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'MCP Integration', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Model Context Protocol integration for automation workflows. Requires WordPress MCP adapter plugin.', 'wpllmseo' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="wpllmseo_enable_mcp"><?php esc_html_e( 'Enable MCP', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="wpllmseo_enable_mcp" 
								       id="wpllmseo_enable_mcp" 
								       value="1" 
								       <?php checked( ! empty( $settings['wpllmseo_enable_mcp'] ) ); ?> />
								<?php esc_html_e( 'Enable MCP ability registration', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: URL to MCP settings page */
									esc_html__( 'Manage tokens and audit logs in %s.', 'wpllmseo' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wpllmseo_mcp' ) ) . '">' . esc_html__( 'MCP Integration', 'wpllmseo' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpllmseo_mcp_respect_llms_txt"><?php esc_html_e( 'Respect LLMs.txt', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" 
								       name="wpllmseo_mcp_respect_llms_txt" 
								       id="wpllmseo_mcp_respect_llms_txt" 
								       value="1" 
								       <?php checked( ! empty( $settings['wpllmseo_mcp_respect_llms_txt'] ) ); ?> />
								<?php esc_html_e( 'Honor /llms.txt directives', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Automatically skip disallowed paths in MCP operations', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Save Button -->
		<p class="submit">
			<input type="submit" 
			       name="wpllmseo_save_settings" 
			       id="submit" 
			       class="button button-primary" 
			       value="<?php esc_attr_e( 'Save Settings', 'wpllmseo' ); ?>" />
		</p>
	</form>
	
	<script>
	jQuery(document).ready(function($) {
		$('#wpllmseo-regenerate-sitemap').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true).find('.dashicons').addClass('dashicons-update-spin');
			
			$.ajax({
				url: '<?php echo esc_url( rest_url( 'wp-llmseo/v1/sitemap/regenerate' ) ); ?>',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
				},
				success: function(response) {
					alert(response.message || 'Sitemap regenerated successfully!');
					location.reload();
				},
				error: function(xhr) {
					var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Failed to regenerate sitemap.';
					alert(message);
				},
				complete: function() {
					$btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update-spin');
				}
			});
		});

		// Bulk snippet generator
		var bulkJobId = null;
		var bulkStatusInterval = null;

		function startBulkJob(dryRun) {
			var $btn = dryRun ? $('#wpllmseo-dry-run') : $('#wpllmseo-start-bulk');
			var $status = $('#wpllmseo-bulk-status');
			
			$btn.prop('disabled', true).find('.dashicons').addClass('dashicons-update-spin');
			$status.html('<div class="notice notice-info inline"><p>Starting...</p></div>').show();

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'wpllmseo_start_bulk_snippets',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
					skip_existing: $('#wpllmseo-skip-existing').is(':checked') ? '1' : '0',
					batch_size: $('#wpllmseo-batch-size').val(),
					dry_run: dryRun ? '1' : '0',
					post_types: ['post', 'page']
				},
				success: function(response) {
					if (response.success) {
						bulkJobId = response.data.job_id;
						
						if (dryRun) {
							checkBulkStatus();
						} else {
							$status.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
							bulkStatusInterval = setInterval(checkBulkStatus, 3000);
						}
					} else {
						$status.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
					}
				},
				error: function() {
					$status.html('<div class="notice notice-error inline"><p>Failed to start bulk job.</p></div>');
				},
				complete: function() {
					$btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update-spin');
				}
			});
		}

		function checkBulkStatus() {
			if (!bulkJobId) return;

			$.ajax({
				url: ajaxurl,
				method: 'GET',
				data: {
					action: 'wpllmseo_bulk_snippet_status',
					nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
					job_id: bulkJobId
				},
				success: function(response) {
					if (response.success && response.data.found) {
						var status = response.data;
						var html = '<div class="notice notice-info inline"><p>';
						html += '<strong>Status:</strong> ' + status.status + '<br>';
						html += '<strong>Progress:</strong> ' + status.processed + ' / ' + status.total;
						
						if (status.skipped > 0) {
							html += ' (' + status.skipped + ' skipped)';
						}
						if (status.errors > 0) {
							html += ' (' + status.errors + ' errors)';
						}
						if (status.eta_minutes > 0) {
							html += '<br><strong>ETA:</strong> ' + status.eta_minutes + ' minutes';
						}
						html += '</p></div>';

						$('#wpllmseo-bulk-status').html(html);

						if (status.status === 'completed' || status.status === 'dry_run_complete') {
							clearInterval(bulkStatusInterval);
							$('#wpllmseo-bulk-status').html('<div class="notice notice-success inline"><p><strong>Complete!</strong> Processed ' + status.processed + ' posts (' + status.skipped + ' skipped, ' + status.errors + ' errors)</p></div>');
						}
					}
				}
			});
		}

		$('#wpllmseo-dry-run').on('click', function(e) {
			e.preventDefault();
			startBulkJob(true);
		});

		$('#wpllmseo-start-bulk').on('click', function(e) {
			e.preventDefault();
			if (confirm('Start bulk AI snippet generation? This may take several minutes depending on the number of posts.')) {
				startBulkJob(false);
			}
		});
	});
	</script>

	<!-- System Information -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'System Information', 'wpllmseo' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin Version', 'wpllmseo' ); ?></th>
					<td><?php echo esc_html( WPLLMSEO_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress Version', 'wpllmseo' ); ?></th>
					<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP Version', 'wpllmseo' ); ?></th>
					<td><?php echo esc_html( phpversion() ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'REST API Endpoint', 'wpllmseo' ); ?></th>
					<td><code><?php echo esc_html( rest_url( WPLLMSEO_REST_NAMESPACE ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log Directory', 'wpllmseo' ); ?></th>
					<td>
						<code><?php echo esc_html( WPLLMSEO_PLUGIN_DIR . 'var/logs/' ); ?></code>
						<?php if ( is_writable( WPLLMSEO_PLUGIN_DIR . 'var/logs/' ) ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
							<?php esc_html_e( 'Writable', 'wpllmseo' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-dismiss" style="color: #d63638;"></span>
							<?php esc_html_e( 'Not writable', 'wpllmseo' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</div>
