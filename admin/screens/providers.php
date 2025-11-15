<?php
/**
 * Provider Settings Screen
 *
 * Multi-provider API configuration page.
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
$settings  = get_option( 'wpllmseo_settings', array() );
$providers = WPLLMSEO_Provider_Manager::get_providers();

// Force initialization if providers are empty
if ( empty( $providers ) ) {
	WPLLMSEO_Provider_Manager::init();
	$providers = WPLLMSEO_Provider_Manager::get_providers();
}

// Check for success message.
$updated = isset( $_GET['updated'] ) && 'true' === $_GET['updated'];
$has_errors = isset( $_GET['errors'] ) && '1' === $_GET['errors'];
$error_messages = $has_errors ? get_transient( 'wpllmseo_provider_errors' ) : array();
if ( $error_messages ) {
	delete_transient( 'wpllmseo_provider_errors' );
}

// Render page header.
wpllmseo_render_header(
	array(
		'title'       => __( 'API Providers', 'wpllmseo' ),
		'description' => __( 'Configure LLM API providers for embeddings and text generation', 'wpllmseo' ),
	)
);
?>

<div class="wpllmseo-providers">
	<?php
	// Quick debug info: show current active provider/model selections
	$active_providers = $settings['active_providers'] ?? array();
	$active_models = $settings['active_models'] ?? array();
	?>
	<div class="notice notice-info inline">
		<p><strong><?php esc_html_e( 'Active assignments (embedding/generation):', 'wpllmseo' ); ?></strong>
		<?php echo esc_html( 'Providers: ' . wp_json_encode( $active_providers ) ); ?>
		<?php echo '<br/>' . esc_html( 'Models: ' . wp_json_encode( $active_models ) ); ?></p>
	</div>
	<?php if ( $updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Provider settings saved successfully.', 'wpllmseo' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $has_errors && ! empty( $error_messages ) ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><strong><?php esc_html_e( 'There were errors saving provider settings:', 'wpllmseo' ); ?></strong></p>
			<ul>
				<?php foreach ( $error_messages as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<!-- Provider Configuration -->
	<form method="post" action="" id="wpllmseo-provider-form">
		<?php wp_nonce_field( 'wpllmseo_admin_action', 'wpllmseo_nonce' ); ?>

		<?php foreach ( $providers as $provider_id => $provider ) : ?>
			<?php
			$config      = WPLLMSEO_Provider_Manager::get_provider_config( $provider_id );
			$is_active   = ! empty( $config['api_key'] );
			$models      = WPLLMSEO_Provider_Manager::get_cached_models( $provider_id );
			$model_count = $models ? count( $models ) : 0;
			?>

			<div class="wpllmseo-settings-section wpllmseo-provider-section" data-provider="<?php echo esc_attr( $provider_id ); ?>">
				<h2>
					<?php echo esc_html( $provider->get_name() ); ?>
					<?php if ( $is_active ) : ?>
						<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Active', 'wpllmseo' ); ?></span>
					<?php else : ?>
						<span class="wpllmseo-badge wpllmseo-badge-error"><?php esc_html_e( 'Inactive', 'wpllmseo' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="description">
					<?php echo esc_html( $provider->get_description() ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $provider->get_config_fields() as $field ) : ?>
							<?php
							$field_id    = 'provider_' . $provider_id . '_' . $field['id'];
							$field_name  = 'providers[' . $provider_id . '][' . $field['id'] . ']';
							$field_value = $config[ $field['id'] ] ?? '';
							?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( $field['label'] ); ?>
									</label>
								</th>
								<td>
									<?php if ( 'password' === $field['type'] ) : ?>
										<input type="password" 
										       id="<?php echo esc_attr( $field_id ); ?>" 
										       name="<?php echo esc_attr( $field_name ); ?>" 
										       value="<?php echo esc_attr( $field_value ); ?>" 
										       class="regular-text"
										       autocomplete="off" />
									<?php elseif ( 'text' === $field['type'] ) : ?>
										<input type="text" 
										       id="<?php echo esc_attr( $field_id ); ?>" 
										       name="<?php echo esc_attr( $field_name ); ?>" 
										       value="<?php echo esc_attr( $field_value ); ?>" 
										       class="regular-text" />
									<?php endif; ?>

									<?php if ( ! empty( $field['description'] ) ) : ?>
										<p class="description">
											<?php
											echo esc_html( $field['description'] );
											if ( ! empty( $field['link'] ) ) :
												?>
												<a href="<?php echo esc_url( $field['link'] ); ?>" target="_blank" rel="noopener noreferrer">
													<?php esc_html_e( 'Get API key', 'wpllmseo' ); ?> →
												</a>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>

						<!-- Model Discovery -->
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Model Discovery', 'wpllmseo' ); ?></label>
							</th>
							<td>
								<button type="button" 
								        class="button wpllmseo-discover-models" 
								        data-provider="<?php echo esc_attr( $provider_id ); ?>"
								        <?php echo ! $is_active ? 'disabled' : ''; ?>>
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Discover Models', 'wpllmseo' ); ?>
								</button>
								<button type="button" 
								        class="button wpllmseo-clear-cache" 
								        data-provider="<?php echo esc_attr( $provider_id ); ?>"
								        <?php echo ! $is_active ? 'disabled' : ''; ?>>
									<span class="dashicons dashicons-trash"></span>
									<?php esc_html_e( 'Clear Cache', 'wpllmseo' ); ?>
								</button>

								<p class="description">
									<?php
									if ( $model_count > 0 ) {
										printf(
											/* translators: %d: number of models */
											esc_html( _n( '%d model cached', '%d models cached', $model_count, 'wpllmseo' ) ),
											esc_html( number_format_i18n( $model_count ) )
										);
									} else {
										esc_html_e( 'No models cached yet. Click "Discover Models" after saving API key.', 'wpllmseo' );
									}
									?>
								</p>

								<div class="wpllmseo-models-list" data-provider="<?php echo esc_attr( $provider_id ); ?>" style="margin-top: 15px; display: none;"></div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>

		<!-- Feature Model Assignment -->
		<div class="wpllmseo-settings-section">
			<h2><?php esc_html_e( 'Model Assignment', 'wpllmseo' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Select which provider and model to use for each feature.', 'wpllmseo' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="embedding_provider"><?php esc_html_e( 'Embedding Provider', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="active_providers[embedding]" id="embedding_provider" class="wpllmseo-provider-select">
								<option value=""><?php esc_html_e( 'Select Provider', 'wpllmseo' ); ?></option>
								<?php foreach ( $providers as $provider_id => $provider ) : ?>
									<?php
									$config    = WPLLMSEO_Provider_Manager::get_provider_config( $provider_id );
									$is_active = ! empty( $config['api_key'] );
									$selected  = ( $settings['active_providers']['embedding'] ?? '' ) === $provider_id;
									?>
									<option value="<?php echo esc_attr( $provider_id ); ?>" 
									        <?php selected( $selected ); ?>>
										<?php echo esc_html( $provider->get_name() ); ?>
										<?php echo ! $is_active ? ' (' . esc_html__( 'Not Configured', 'wpllmseo' ) . ')' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Provider used for generating content embeddings', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>

					<tr class="wpllmseo-model-row" data-feature="embedding" style="<?php echo ! empty( $settings['active_providers']['embedding'] ?? '' ) ? '' : 'display: none;'; ?>">
						<th scope="row">
							<label for="embedding_model"><?php esc_html_e( 'Embedding Model', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="active_models[embedding]" id="embedding_model" class="wpllmseo-model-select">
								<option value=""><?php esc_html_e( 'Select Model', 'wpllmseo' ); ?></option>
								<?php
								$current_model = $settings['active_models']['embedding'] ?? '';
								if ( $current_model ) {
									echo '<option value="' . esc_attr( $current_model ) . '" selected>' . esc_html( $current_model ) . '</option>';
								}
								?>
							</select>
							<button type="button" class="button wpllmseo-test-model" data-feature="embedding" style="<?php echo $current_model ? '' : 'display: none;'; ?>">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Test Model', 'wpllmseo' ); ?>
							</button>
							<div class="wpllmseo-test-result" data-feature="embedding" style="margin-top: 10px; display: none;"></div>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="generation_provider"><?php esc_html_e( 'Generation Provider', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="active_providers[generation]" id="generation_provider" class="wpllmseo-provider-select">
								<option value=""><?php esc_html_e( 'Select Provider', 'wpllmseo' ); ?></option>
								<?php foreach ( $providers as $provider_id => $provider ) : ?>
									<?php
									$config    = WPLLMSEO_Provider_Manager::get_provider_config( $provider_id );
									$is_active = ! empty( $config['api_key'] );
									$selected  = ( $settings['active_providers']['generation'] ?? '' ) === $provider_id;
									?>
									<option value="<?php echo esc_attr( $provider_id ); ?>" 
									        <?php selected( $selected ); ?>>
										<?php echo esc_html( $provider->get_name() ); ?>
										<?php echo ! $is_active ? ' (' . esc_html__( 'Not Configured', 'wpllmseo' ) . ')' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Provider used for generating AI snippets and descriptions', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>

					<tr class="wpllmseo-model-row" data-feature="generation" style="<?php echo ! empty( $settings['active_providers']['generation'] ?? '' ) ? '' : 'display: none;'; ?>">
						<th scope="row">
							<label for="generation_model"><?php esc_html_e( 'Generation Model', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="active_models[generation]" id="generation_model" class="wpllmseo-model-select">
								<option value=""><?php esc_html_e( 'Select Model', 'wpllmseo' ); ?></option>
								<?php
								$current_gen_model = $settings['active_models']['generation'] ?? '';
								if ( $current_gen_model ) {
									echo '<option value="' . esc_attr( $current_gen_model ) . '" selected>' . esc_html( $current_gen_model ) . '</option>';
								}
								?>
							</select>
							<button type="button" class="button wpllmseo-test-model" data-feature="generation" style="<?php echo $current_gen_model ? '' : 'display: none;'; ?>">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Test Model', 'wpllmseo' ); ?>
							</button>
							<div class="wpllmseo-test-result" data-feature="generation" style="margin-top: 10px; display: none;"></div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Save Button -->
		<p class="submit">
			<input type="submit" 
			       name="wpllmseo_save_providers" 
			       id="submit" 
			       class="button button-primary" 
			       value="<?php esc_attr_e( 'Save Provider Settings', 'wpllmseo' ); ?>" />
		</p>
	</form>
</div>

<script>
jQuery(document).ready(function($) {
	var nonce = '<?php echo esc_js( wp_create_nonce( 'wpllmseo_admin_action' ) ); ?>';

	// Model discovery
	$('.wpllmseo-discover-models').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var providerId = $btn.data('provider');
		var $list = $('.wpllmseo-models-list[data-provider="' + providerId + '"]');

		$btn.prop('disabled', true);
		$btn.find('.dashicons').addClass('dashicons-update-spin');

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'wpllmseo_discover_models',
				provider_id: providerId,
				force: true,
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					$list.show();
					var html = '<p><strong>' + response.data.count + ' models discovered:</strong></p><ul class="wpllmseo-model-grid">';
					response.data.models.forEach(function(model) {
						html += '<li><span class="wpllmseo-badge wpllmseo-badge-info">' + model.capability + '</span> ';
						html += '<code>' + model.name + '</code>';
						if (model.description) {
							html += '<br><small>' + model.description + '</small>';
						}
						html += '</li>';
					});
					html += '</ul>';
					$list.html(html);
				} else {
					alert('Discovery failed: ' + response.data.message);
				}
			},
			complete: function() {
				$btn.prop('disabled', false);
				$btn.find('.dashicons').removeClass('dashicons-update-spin');
			}
		});
	});

	// Clear cache
	$('.wpllmseo-clear-cache').on('click', function(e) {
		e.preventDefault();
		var providerId = $(this).data('provider');

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'wpllmseo_clear_model_cache',
				provider_id: providerId,
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					location.reload();
				}
			}
		});
	});

	// Provider selection change - load models
	$('.wpllmseo-provider-select').on('change', function() {
		var $select = $(this);
		var feature = $select.attr('id').replace('_provider', '');
		var providerId = $select.val();
		var $modelRow = $('.wpllmseo-model-row[data-feature="' + feature + '"]');
		var $modelSelect = $modelRow.find('.wpllmseo-model-select');

		if (!providerId) {
			$modelRow.hide();
			return;
		}

		$modelRow.show();
		
		// Remember the currently selected model before reloading
		var currentSelectedModel = $modelSelect.val();
		
		$modelSelect.html('<option value="">Loading models...</option>').prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'wpllmseo_discover_models',
				provider_id: providerId,
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					var capability = feature === 'embedding' ? 'embedding' : 'generation';
					var html = '<option value="">Select Model</option>';
					
					response.data.models.forEach(function(model) {
						if (model.capability === capability || (capability === 'generation' && model.capability === 'chat')) {
							var label = model.label;
							if (model.recommended) {
								label += ' (Recommended)';
							}
							var selected = (currentSelectedModel && model.id === currentSelectedModel) ? ' selected' : '';
							html += '<option value="' + model.id + '"' + selected + '>' + label + '</option>';
						}
					});
					
					$modelSelect.html(html).prop('disabled', false);
					
					// Show test button if a model is selected
					if ($modelSelect.val()) {
						$modelRow.find('.wpllmseo-test-model').show();
					}
				}
			}
		});
	});

	// Test model
	$('.wpllmseo-test-model').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var feature = $btn.data('feature');
		var providerId = $('#' + feature + '_provider').val();
		var modelId = $('#' + feature + '_model').val();
		var $result = $('.wpllmseo-test-result[data-feature="' + feature + '"]');

		if (!modelId) {
			alert('Please select a model first');
			return;
		}

		$btn.prop('disabled', true);
		$result.html('<p>Testing model...</p>').show();

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'wpllmseo_test_model',
				provider_id: providerId,
				model_id: modelId,
				nonce: nonce
			},
			success: function(response) {
				if (response.success) {
					var html = '<div class="notice notice-success inline"><p>';
					html += '<strong>✓ Test successful!</strong><br>';
					html += 'Latency: ' + response.data.latency + 'ms<br>';
					html += 'Sample: ' + response.data.sample;
					if (response.data.cost_estimate) {
						html += '<br>Estimated cost: $' + response.data.cost_estimate.toFixed(6);
					}
					html += '</p></div>';
					$result.html(html);
				} else {
					$result.html('<div class="notice notice-error inline"><p>Test failed: ' + response.data.message + '</p></div>');
				}
			},
			complete: function() {
				$btn.prop('disabled', false);
			}
		});
	});

	// Trigger initial load for selected providers
	$('.wpllmseo-provider-select').each(function() {
		if ($(this).val()) {
			$(this).trigger('change');
		}
	});
});
</script>

<style>
.wpllmseo-provider-section {
	border-left: 4px solid #0073aa;
	padding-left: 15px;
}
.wpllmseo-model-grid {
	list-style: none;
	margin: 10px 0;
	padding: 0;
}
.wpllmseo-model-grid li {
	padding: 8px;
	margin: 5px 0;
	background: #f9f9f9;
	border-radius: 3px;
}
.wpllmseo-badge-info {
	background: #0073aa;
}
.required {
	color: #dc3232;
}
</style>
