/**
 * Post Panel JavaScript
 * 
 * @package WP_LLM_SEO_Indexing
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		$('.wpllmseo-regenerate-btn').on('click', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var postId = $btn.data('post-id');
			var $status = $('.wpllmseo-regenerate-status');
			
			$btn.prop('disabled', true);
			$btn.find('.dashicons').addClass('dashicons-update-spin');
			$status.hide().removeClass('success error');
			
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'wpllmseo_regenerate_post',
					nonce: wpllmseoPostPanel.nonce,
					post_id: postId
				},
				success: function(response) {
					if (response.success) {
						$status.addClass('success').text(response.data.message).show();
						
						// Dispatch event for Gutenberg panel to refresh
						if (typeof window.dispatchEvent === 'function') {
							var event = new CustomEvent('wpllmseo_snippet_generated', {
								detail: {
									postId: postId,
									snippet: response.data.snippet
								}
							});
							window.dispatchEvent(event);
						}
						
						setTimeout(function() {
							location.reload();
						}, 1500);
					} else {
						$status.addClass('error').text(response.data.message).show();
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = 'Failed to regenerate. Please try again.';
					if (xhr.responseText) {
						try {
							var data = JSON.parse(xhr.responseText);
							errorMsg = data.data && data.data.message ? data.data.message : errorMsg;
						} catch(e) {
							console.error('Non-JSON response:', xhr.responseText.substring(0, 200));
							errorMsg = 'Server returned invalid response. Status: ' + xhr.status;
						}
					}
					$status.addClass('error').text(errorMsg).show();
				},
				complete: function() {
					$btn.prop('disabled', false);
					$btn.find('.dashicons').removeClass('dashicons-update-spin');
				}
			});
		});
	});
})(jQuery);
