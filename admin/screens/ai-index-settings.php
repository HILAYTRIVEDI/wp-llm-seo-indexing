<?php
/**
 * AI Index Settings Screen
 *
 * Admin UI for AI index configuration and API key management.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load component files.
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

// Handle actions.
$message = '';
$error = '';

if ( isset( $_POST['wpllmseo_save_ai_index_settings'] ) && check_admin_referer( 'wpllmseo_admin_action', 'wpllmseo_nonce' ) ) {
	$settings = get_option( 'wpllmseo_settings', array() );
	
	$settings['gate_embeddings'] = isset( $_POST['gate_embeddings'] ) ? 1 : 0;
	
	update_option( 'wpllmseo_settings', $settings );
	$message = __( 'Settings saved successfully.', 'wpllmseo' );
}

if ( isset( $_POST['wpllmseo_run_export'] ) && check_admin_referer( 'wpllmseo_admin_action', 'wpllmseo_nonce' ) ) {
	$upload_dir = wp_upload_dir();
	$output_dir = $upload_dir['basedir'] . '/ai-index';
	
	if ( ! file_exists( $output_dir ) ) {
		wp_mkdir_p( $output_dir );
	}

	$chunks_result = WPLLMSEO_AI_Index_REST::export_chunks_ndjson(
		$output_dir . '/ai-chunks.ndjson.gz',
		array( 'compress' => true )
	);

	$embeddings_result = WPLLMSEO_AI_Index_REST::export_embeddings_ndjson(
		$output_dir . '/ai-embeddings.ndjson.gz',
		array( 'compress' => true )
	);

	if ( $chunks_result && $embeddings_result ) {
		$message = __( 'Export completed successfully.', 'wpllmseo' );
	} else {
		$error = __( 'Export failed. Check error logs.', 'wpllmseo' );
	}
}

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( 'AI Index Settings', 'wpllmseo' ),
		'description' => __( 'Configure public AI index artifacts and API access', 'wpllmseo' ),
	)
);

// Get current settings.
$settings = get_option( 'wpllmseo_settings', array() );
$gate_embeddings = ! empty( $settings['gate_embeddings'] );

// Get API keys.
$api_keys = WPLLMSEO_API_Auth::get_keys();

// Get export file info.
$upload_dir = wp_upload_dir();
$export_dir = $upload_dir['basedir'] . '/ai-index';
$export_url = $upload_dir['baseurl'] . '/ai-index';

$export_files = array();
if ( file_exists( $export_dir ) ) {
	$files = array( 'ai-chunks.ndjson.gz', 'ai-embeddings.ndjson.gz', 'ai-chunks-delta.ndjson.gz' );
	foreach ( $files as $file ) {
		$file_path = $export_dir . '/' . $file;
		if ( file_exists( $file_path ) ) {
			$export_files[] = array(
				'name'     => $file,
				'size'     => filesize( $file_path ),
				'modified' => filemtime( $file_path ),
				'url'      => $export_url . '/' . $file,
			);
		}
	}
}

// Get manifest URL.
$manifest_url = home_url( '/ai-index/manifest.json' );

?>

<?php if ( $message ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php echo esc_html( $message ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $error ) : ?>
	<div class="notice notice-error is-dismissible">
		<p><?php echo esc_html( $error ); ?></p>
	</div>
<?php endif; ?>

<div class="wpllmseo-ai-index-settings">

	<!-- Overview -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Public AI Index Overview', 'wpllmseo' ); ?></h2>
		<p><?php esc_html_e( 'The AI Index provides public, discoverable artifacts for external LLM indexers and internal RAG pipelines.', 'wpllmseo' ); ?></p>
		
		<table class="widefat">
			<tr>
				<td><strong><?php esc_html_e( 'Manifest URL', 'wpllmseo' ); ?></strong></td>
				<td>
					<code><?php echo esc_html( $manifest_url ); ?></code>
					<a href="<?php echo esc_url( $manifest_url ); ?>" target="_blank" class="button button-small" style="margin-left: 10px;">
						<?php esc_html_e( 'View', 'wpllmseo' ); ?>
					</a>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Chunk Endpoint', 'wpllmseo' ); ?></strong></td>
				<td><code><?php echo esc_url( rest_url( 'ai-index/v1/chunk/{chunk_id}' ) ); ?></code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Embeddings Endpoint', 'wpllmseo' ); ?></strong></td>
				<td><code><?php echo esc_url( rest_url( 'ai-index/v1/embeddings/{chunk_id}' ) ); ?></code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Stats Endpoint', 'wpllmseo' ); ?></strong></td>
				<td><code><?php echo esc_url( rest_url( 'ai-index/v1/stats' ) ); ?></code></td>
			</tr>
		</table>
	</div>

	<!-- Settings Form -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'AI Index Settings', 'wpllmseo' ); ?></h2>
		
		<form method="post">
			<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Gate Embeddings', 'wpllmseo' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="gate_embeddings" value="1" <?php checked( $gate_embeddings ); ?>>
							<?php esc_html_e( 'Require API key for embeddings endpoint access', 'wpllmseo' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, the embeddings endpoint will require a valid API key. The NDJSON export will still include embeddings but the REST endpoint will be gated.', 'wpllmseo' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="wpllmseo_save_ai_index_settings" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'wpllmseo' ); ?>
				</button>
			</p>
		</form>
	</div>

	<!-- API Keys Management -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'API Keys', 'wpllmseo' ); ?></h2>
		<p><?php esc_html_e( 'Manage API keys for gated embeddings access.', 'wpllmseo' ); ?></p>

		<div id="wpllmseo-api-keys-app">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'ID', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $api_keys ) ) : ?>
						<tr>
							<td colspan="5" style="text-align: center; padding: 20px;">
								<?php esc_html_e( 'No API keys created yet.', 'wpllmseo' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $api_keys as $key ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $key['name'] ); ?></strong></td>
								<td><code><?php echo esc_html( $key['id'] ); ?></code></td>
								<td><?php echo esc_html( $key['created'] ); ?></td>
								<td><?php echo esc_html( $key['last_used'] ?? 'Never' ); ?></td>
								<td>
									<button class="button button-small wpllmseo-delete-key" data-key-id="<?php echo esc_attr( $key['id'] ); ?>">
										<?php esc_html_e( 'Delete', 'wpllmseo' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top: 15px;">
				<button id="wpllmseo-create-key-btn" class="button button-secondary">
					<span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
					<?php esc_html_e( 'Create New API Key', 'wpllmseo' ); ?>
				</button>
			</p>

			<!-- Create Key Modal -->
			<div id="wpllmseo-create-key-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
				<div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%;">
					<h3><?php esc_html_e( 'Create New API Key', 'wpllmseo' ); ?></h3>
					<p>
						<label>
							<strong><?php esc_html_e( 'Key Name:', 'wpllmseo' ); ?></strong><br>
							<input type="text" id="wpllmseo-key-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Production Indexer', 'wpllmseo' ); ?>">
						</label>
					</p>
					<p>
						<button id="wpllmseo-create-key-submit" class="button button-primary"><?php esc_html_e( 'Create Key', 'wpllmseo' ); ?></button>
						<button id="wpllmseo-create-key-cancel" class="button"><?php esc_html_e( 'Cancel', 'wpllmseo' ); ?></button>
					</p>
				</div>
			</div>

			<!-- Show Key Modal -->
			<div id="wpllmseo-show-key-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
				<div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%;">
					<h3><?php esc_html_e( 'API Key Created', 'wpllmseo' ); ?></h3>
					<p style="color: #d63638; font-weight: bold;">
						<?php esc_html_e( 'Copy this key now. It will not be shown again.', 'wpllmseo' ); ?>
					</p>
					<p>
						<code id="wpllmseo-new-key" style="display: block; padding: 15px; background: #f0f0f1; font-size: 14px; word-break: break-all;"></code>
					</p>
					<p>
						<button id="wpllmseo-copy-key" class="button button-primary"><?php esc_html_e( 'Copy Key', 'wpllmseo' ); ?></button>
						<button id="wpllmseo-close-key-modal" class="button"><?php esc_html_e( 'Close', 'wpllmseo' ); ?></button>
					</p>
				</div>
			</div>
		</div>
	</div>

	<!-- Export Files -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'Export Files', 'wpllmseo' ); ?></h2>
		
		<?php if ( empty( $export_files ) ) : ?>
			<p><?php esc_html_e( 'No export files generated yet.', 'wpllmseo' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Size', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Last Modified', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $export_files as $file ) : ?>
						<tr>
							<td><code><?php echo esc_html( $file['name'] ); ?></code></td>
							<td><?php echo esc_html( size_format( $file['size'] ) ); ?></td>
							<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', $file['modified'] ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $file['url'] ); ?>" class="button button-small" download>
									<?php esc_html_e( 'Download', 'wpllmseo' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form method="post" style="margin-top: 15px;">
			<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>
			<button type="submit" name="wpllmseo_run_export" class="button button-secondary">
				<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
				<?php esc_html_e( 'Run Export Now', 'wpllmseo' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Generate or update NDJSON export files. Use WP-CLI for delta exports and more options.', 'wpllmseo' ); ?>
			</p>
		</form>
	</div>

	<!-- WP-CLI Commands -->
	<div class="wpllmseo-settings-section">
		<h2><?php esc_html_e( 'WP-CLI Commands', 'wpllmseo' ); ?></h2>
		<p><?php esc_html_e( 'Use these commands for advanced export and embedding operations:', 'wpllmseo' ); ?></p>

		<table class="widefat">
			<tr>
				<td><strong><?php esc_html_e( 'Export all chunks', 'wpllmseo' ); ?></strong></td>
				<td><code>wp ai-index export</code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Export delta since date', 'wpllmseo' ); ?></strong></td>
				<td><code>wp ai-index export --since=2025-11-15T00:00:00</code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Generate embeddings', 'wpllmseo' ); ?></strong></td>
				<td><code>wp ai-index embed --provider=gemini --model=text-embedding-004</code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'View statistics', 'wpllmseo' ); ?></strong></td>
				<td><code>wp ai-index stats</code></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Populate metadata', 'wpllmseo' ); ?></strong></td>
				<td><code>wp ai-index populate-metadata</code></td>
			</tr>
		</table>
	</div>

</div>

<script>
jQuery(document).ready(function($) {
	// Create key modal.
	$('#wpllmseo-create-key-btn').on('click', function() {
		$('#wpllmseo-create-key-modal').css('display', 'flex');
	});

	$('#wpllmseo-create-key-cancel').on('click', function() {
		$('#wpllmseo-create-key-modal').hide();
		$('#wpllmseo-key-name').val('');
	});

	// Create key submit.
	$('#wpllmseo-create-key-submit').on('click', function() {
		var name = $('#wpllmseo-key-name').val();
		if (!name) {
			alert('<?php esc_html_e( 'Please enter a key name', 'wpllmseo' ); ?>');
			return;
		}

		$.ajax({
			url: '<?php echo esc_url( rest_url( 'wpllmseo/v1/api-keys' ) ); ?>',
			method: 'POST',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
			},
			data: { name: name },
			success: function(response) {
				$('#wpllmseo-create-key-modal').hide();
				$('#wpllmseo-key-name').val('');
				$('#wpllmseo-new-key').text(response.key);
				$('#wpllmseo-show-key-modal').css('display', 'flex');
			},
			error: function() {
				alert('<?php esc_html_e( 'Failed to create API key', 'wpllmseo' ); ?>');
			}
		});
	});

	// Copy key.
	$('#wpllmseo-copy-key').on('click', function() {
		var key = $('#wpllmseo-new-key').text();
		navigator.clipboard.writeText(key).then(function() {
			alert('<?php esc_html_e( 'Key copied to clipboard', 'wpllmseo' ); ?>');
		});
	});

	// Close key modal.
	$('#wpllmseo-close-key-modal').on('click', function() {
		$('#wpllmseo-show-key-modal').hide();
		location.reload();
	});

	// Delete key.
	$('.wpllmseo-delete-key').on('click', function() {
		if (!confirm('<?php esc_html_e( 'Are you sure you want to delete this API key?', 'wpllmseo' ); ?>')) {
			return;
		}

		var keyId = $(this).data('key-id');

		$.ajax({
			url: '<?php echo esc_url( rest_url( 'wpllmseo/v1/api-keys/' ) ); ?>' + keyId,
			method: 'DELETE',
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>');
			},
			success: function() {
				location.reload();
			},
			error: function() {
				alert('<?php esc_html_e( 'Failed to delete API key', 'wpllmseo' ); ?>');
			}
		});
	});
});
</script>
