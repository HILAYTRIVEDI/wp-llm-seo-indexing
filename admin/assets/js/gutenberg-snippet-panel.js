/**
 * Gutenberg Snippet Panel
 *
 * Adds a sidebar panel for managing preferred snippets in the block editor.
 *
 * @package WP_LLM_SEO_Indexing
 */

(function() {
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor || wp.editPost; // Use wp.editor for WP 6.6+
	const { Component, Fragment } = wp.element;
	const { TextareaControl, Button, Spinner, Icon } = wp.components;
	const { withSelect, withDispatch } = wp.data;
	const { compose } = wp.compose;
	const apiFetch = wp.apiFetch;
	const { __ } = wp.i18n;

	/**
	 * Snippet Panel Component
	 */
	class SnippetPanel extends Component {
		constructor(props) {
			super(props);

			this.state = {
				snippetText: '',
				existingSnippet: null,
				loading: true,
				saving: false,
				hasChanges: false,
			};

			this.loadSnippet = this.loadSnippet.bind(this);
			this.saveSnippet = this.saveSnippet.bind(this);
			this.handleTextChange = this.handleTextChange.bind(this);
		}

		componentDidMount() {
			this.loadSnippet();
			
			// Listen for snippet generation events from the classic editor panel
			window.addEventListener('wpllmseo_snippet_generated', (e) => {
				if (e.detail && e.detail.postId === this.props.postId) {
					// Reload snippet after generation
					this.loadSnippet();
				}
			});
		}

		/**
		 * Load existing snippet from API
		 */
		loadSnippet() {
			const { postId } = this.props;

			if (!postId) {
				this.setState({ loading: false });
				return;
			}

			this.setState({ loading: true });

			apiFetch({
				path: `/wp-llmseo/v1/snippet/post/${postId}`,
				method: 'GET',
			})
				.then((data) => {
					if (data.success && data.data) {
						this.setState({
							snippetText: data.data.snippet_text || '',
							existingSnippet: data.data,
							loading: false,
							hasChanges: false,
						});
					} else {
						this.setState({
							snippetText: '',
							existingSnippet: null,
							loading: false,
							hasChanges: false,
						});
					}
				})
				.catch((error) => {
					console.error('Error loading snippet:', error);
					this.setState({
						loading: false,
						existingSnippet: null,
					});
				});
		}

		/**
		 * Save snippet via REST API
		 */
		saveSnippet() {
			const { postId, createNotice } = this.props;
			const { snippetText } = this.state;

			if (!snippetText.trim()) {
				createNotice('error', __('Snippet text cannot be empty.', 'wpllmseo'));
				return;
			}

			this.setState({ saving: true });

			apiFetch({
				path: '/wp-llmseo/v1/snippet',
				method: 'POST',
				data: {
					post_id: postId,
					snippet_text: snippetText,
				},
			})
				.then((data) => {
					if (data.success) {
						this.setState({
							existingSnippet: data.data,
							saving: false,
							hasChanges: false,
						});

						createNotice(
							'success',
							__('Preferred snippet saved successfully.', 'wpllmseo'),
							{ type: 'snackbar' }
						);
					} else {
						throw new Error(data.message || 'Unknown error');
					}
				})
				.catch((error) => {
					console.error('Error saving snippet:', error);
					this.setState({ saving: false });

					createNotice(
						'error',
						error.message || __('Failed to save snippet.', 'wpllmseo'),
						{ type: 'snackbar' }
					);
				});
		}

		/**
		 * Handle text change
		 */
		handleTextChange(value) {
			this.setState({
				snippetText: value,
				hasChanges: true,
			});
		}

		/**
		 * Render component
		 */
		render() {
			const { postId } = this.props;
			const { snippetText, existingSnippet, loading, saving, hasChanges } = this.state;
			const { createElement: el } = wp.element;

			if (!postId) {
				return el('div', { style: { padding: '16px', color: '#666' } },
					__('Save the post to manage preferred snippets.', 'wpllmseo')
				);
			}

			if (loading) {
				return el('div', { style: { padding: '16px', textAlign: 'center' } },
					el(Spinner)
				);
			}

			const elements = [];

			// Success indicator if snippet exists
			if (existingSnippet) {
				elements.push(
					el('div', {
						key: 'success-indicator',
						style: {
							display: 'flex',
							alignItems: 'center',
							padding: '8px 12px',
							backgroundColor: '#d4edda',
							border: '1px solid #c3e6cb',
							borderRadius: '4px',
							color: '#155724',
							marginBottom: '12px',
						}
					},
						el(Icon, {
							icon: 'yes-alt',
							style: {
								marginRight: '8px',
								fill: '#28a745',
							}
						}),
						el('span', null, __('Preferred snippet is set', 'wpllmseo'))
					)
				);
			}

			// Textarea control
			elements.push(
				el(TextareaControl, {
					key: 'textarea',
					label: __('Preferred Snippet Text', 'wpllmseo'),
					help: __('This text will be used for AI indexing and search. Keep it concise and relevant.', 'wpllmseo'),
					value: snippetText,
					onChange: this.handleTextChange,
					rows: 6,
					placeholder: __('Enter a concise summary or key points from this content...', 'wpllmseo')
				})
			);

			// Save button
			elements.push(
				el(Button, {
					key: 'button',
					isPrimary: true,
					isBusy: saving,
					disabled: saving || !hasChanges || !snippetText.trim(),
					onClick: this.saveSnippet,
					style: { width: '100%', marginTop: '12px' }
				},
					saving
						? __('Saving...', 'wpllmseo')
						: existingSnippet
							? __('Update Preferred Snippet', 'wpllmseo')
							: __('Set as Preferred Snippet', 'wpllmseo')
				)
			);

			// Help text
			elements.push(
				el('p', {
					key: 'help',
					style: { marginTop: '12px', fontSize: '12px', color: '#666' }
				},
					__('The snippet will be automatically indexed for AI search.', 'wpllmseo')
				)
			);

			return el('div', null, elements);
		}
	}

	/**
	 * Connect to WordPress data stores
	 */
	const ConnectedSnippetPanel = compose([
		withSelect((select) => {
			const { getCurrentPostId } = select('core/editor');
			return {
				postId: getCurrentPostId(),
			};
		}),
		withDispatch((dispatch) => {
			const { createNotice } = dispatch('core/notices');
			return {
				createNotice,
			};
		}),
	])(SnippetPanel);

	/**
	 * Register plugin
	 */
	registerPlugin('wpllmseo-snippet-panel', {
		render: function() {
			const { createElement: el } = wp.element;
			return el(
				PluginDocumentSettingPanel,
				{
					name: 'wpllmseo-snippet-panel',
					title: __('Preferred Snippet', 'wpllmseo'),
					icon: 'media-text'
				},
				el(ConnectedSnippetPanel)
			);
		}
	});
})();
