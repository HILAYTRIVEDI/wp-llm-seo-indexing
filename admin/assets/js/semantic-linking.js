/**
 * Semantic Linking JavaScript
 * 
 * @package WP_LLM_SEO_Indexing
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Get suggestions
		$(document).on('click', '.wpllmseo-get-suggestions', function() {
			var postId = $(this).data('post-id');
			$('#wpllmseo-linking-spinner').show();
			$('#wpllmseo-suggestions-container').hide();

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'wpllmseo_get_link_suggestions',
					post_id: postId,
					nonce: wpllmseoSemanticLinking.nonce
				},
				success: function(response) {
					$('#wpllmseo-linking-spinner').hide();
					if (response.success && response.data.suggestions.length > 0) {
						renderSuggestions(response.data.suggestions);
						$('#wpllmseo-suggestions-container').show();
						$('.wpllmseo-insert-all').show();
					} else {
						alert(response.data.message || 'No suggestions found.');
					}
				},
				error: function() {
					$('#wpllmseo-linking-spinner').hide();
					alert('Error fetching suggestions.');
				}
			});
		});

		// Render suggestions
		function renderSuggestions(suggestions) {
			var html = '';
			suggestions.forEach(function(item, index) {
				var score = Math.round(item.similarity * 100);
				html += '<div class="wpllmseo-suggestion" data-index="' + index + '">';
				html += '<div class="wpllmseo-suggestion-title">' + item.title + '</div>';
				html += '<div class="wpllmseo-suggestion-meta">';
				html += '<span class="wpllmseo-suggestion-score">' + score + '% match</span> ';
				html += '<span style="color:#999;">' + item.type + '</span>';
				html += '</div>';
				html += '<div class="wpllmseo-suggestion-actions">';
				html += '<button type="button" class="button button-small wpllmseo-insert-link" data-post-id="' + item.id + '">Insert Link</button> ';
				html += '<button type="button" class="button button-small wpllmseo-dismiss" data-post-id="' + item.id + '">Dismiss</button>';
				html += '</div>';
				html += '</div>';
			});
			$('#wpllmseo-suggestions-list').html(html);
		}

		// Insert single link
		$(document).on('click', '.wpllmseo-insert-link', function() {
			var postId = $(this).data('post-id');
			insertLinks([postId]);
		});

		// Insert all links
		$(document).on('click', '.wpllmseo-insert-all', function() {
			var postIds = [];
			$('.wpllmseo-insert-link').each(function() {
				postIds.push($(this).data('post-id'));
			});
			insertLinks(postIds);
		});

		// Insert links via AJAX
		function insertLinks(postIds) {
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'wpllmseo_insert_links',
					current_post_id: $('.wpllmseo-get-suggestions').data('post-id'),
					link_post_ids: postIds,
					nonce: wpllmseoSemanticLinking.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						location.reload();
					} else {
						alert(response.data.message || 'Error inserting links.');
					}
				}
			});
		}

		// Trigger indexing from Index this post link
		$(document).on('click', '.wpllmseo-trigger-indexing', function(e) {
			e.preventDefault();
			if (!confirm('This will generate a snippet and embedding for this post. Continue?')) {
				return;
			}
			
			var postId = $(this).data('post-id') || $('#wpllmseo-linking-panel').data('post-id');
			if (!postId) {
				alert('Could not determine post ID.');
				return;
			}
			
			var linkElement = $(this);
			linkElement.text('Indexing...').css('pointer-events', 'none');
			
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				dataType: 'json',
				data: {
					action: 'wpllmseo_regenerate_post',
					post_id: postId,
					nonce: wpllmseoSemanticLinking.regenerateNonce
				},
				success: function(response) {
					if (response.success) {
						alert('Snippet and embedding generation started. The page will reload.');
						location.reload();
					} else {
						alert(response.data.message || 'Error generating snippet.');
						linkElement.text('Index this post').css('pointer-events', 'auto');
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = 'Error communicating with server.';
					if (xhr.responseText) {
						try {
							var data = JSON.parse(xhr.responseText);
							errorMsg = data.data && data.data.message ? data.data.message : errorMsg;
						} catch(e) {
							var snippet = xhr.responseText.substring(0, 200);
							console.error('Non-JSON response:', snippet);
							errorMsg = 'Server returned invalid response. Check console for details.';
						}
					}
					alert(errorMsg);
					linkElement.text('Index this post').css('pointer-events', 'auto');
				}
			});
		});

		// Dismiss suggestion
		$(document).on('click', '.wpllmseo-dismiss', function() {
			var postId = $(this).data('post-id');
			$(this).closest('.wpllmseo-suggestion').fadeOut();

			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'wpllmseo_dismiss_suggestion',
					current_post_id: $('.wpllmseo-get-suggestions').data('post-id'),
					dismissed_post_id: postId,
					nonce: wpllmseoSemanticLinking.nonce
				}
			});
		});
	});
})(jQuery);
