<?php
/**
 * Snippet Manager Class
 *
 * Handles CRUD operations for preferred snippets.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPLLMSEO_Snippets
 */
class WPLLMSEO_Snippets {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'wpllmseo_snippets';
		
		// Add Classic Editor metabox
		add_action( 'add_meta_boxes', array( $this, 'add_snippet_metabox' ) );
		add_action( 'save_post', array( $this, 'save_snippet_metabox' ) );
		
		// Enqueue assets
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_gutenberg_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Create or update snippet
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $snippet_text Snippet text.
	 * @return int|false Snippet ID or false on failure.
	 */
	public function create_snippet( $post_id, $snippet_text ) {
		global $wpdb;

		$post_id = absint( $post_id );
		$snippet_text = wp_kses_post( $snippet_text );
		$snippet_hash = hash( 'sha256', $snippet_text );

		// Check if snippet already exists for this post
		$existing = $this->get_snippet_by_post( $post_id );

		if ( $existing ) {
			return $this->update_snippet( $existing->id, $snippet_text );
		}

		$result = $wpdb->insert(
			$this->table_name,
			array(
				'post_id'      => $post_id,
				'snippet_text' => $snippet_text,
				'snippet_hash' => $snippet_hash,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $result ) {
			$snippet_id = $wpdb->insert_id;
			
			// Update post meta
			update_post_meta( $post_id, '_wpllmseo_snippet_id', $snippet_id );
			
			// Log activity
			wpllmseo_log( sprintf( 'Snippet created for post %d (ID: %d)', $post_id, $snippet_id ), 'info' );
			
			// Trigger indexing
			do_action( 'wpllmseo_snippet_created', $snippet_id, $post_id );
			
			return $snippet_id;
		}

		return false;
	}

	/**
	 * Update snippet
	 *
	 * @param int    $snippet_id   Snippet ID.
	 * @param string $snippet_text Snippet text.
	 * @return bool True on success, false on failure.
	 */
	public function update_snippet( $snippet_id, $snippet_text ) {
		global $wpdb;

		$snippet_id = absint( $snippet_id );
		$snippet_text = wp_kses_post( $snippet_text );
		$snippet_hash = hash( 'sha256', $snippet_text );

		$result = $wpdb->update(
			$this->table_name,
			array(
				'snippet_text' => $snippet_text,
				'snippet_hash' => $snippet_hash,
				'updated_at'   => current_time( 'mysql' ),
				'embedding'    => null, // Clear old embedding
			),
			array( 'id' => $snippet_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wpllmseo_log( sprintf( 'Snippet updated (ID: %d)', $snippet_id ), 'info' );
			
			// Get post_id for the snippet
			$snippet = $this->get_snippet( $snippet_id );
			if ( $snippet ) {
				do_action( 'wpllmseo_snippet_updated', $snippet_id, $snippet->post_id );
			}
			
			return true;
		}

		return false;
	}

	/**
	 * Delete snippet
	 *
	 * @param int $snippet_id Snippet ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_snippet( $snippet_id ) {
		global $wpdb;

		$snippet_id = absint( $snippet_id );
		
		// Get snippet before deletion to clear post meta
		$snippet = $this->get_snippet( $snippet_id );

		$result = $wpdb->delete(
			$this->table_name,
			array( 'id' => $snippet_id ),
			array( '%d' )
		);

		if ( $result ) {
			if ( $snippet ) {
				delete_post_meta( $snippet->post_id, '_wpllmseo_snippet_id' );
			}
			
			wpllmseo_log( sprintf( 'Snippet deleted (ID: %d)', $snippet_id ), 'info' );
			return true;
		}

		return false;
	}

	/**
	 * Get snippet by ID
	 *
	 * @param int $snippet_id Snippet ID.
	 * @return object|null Snippet object or null.
	 */
	public function get_snippet( $snippet_id ) {
		global $wpdb;

		$snippet_id = absint( $snippet_id );

		$snippet = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE id = %d",
				$snippet_id
			)
		);

		return $snippet;
	}

	/**
	 * Get snippet by post ID
	 *
	 * @param int $post_id Post ID.
	 * @return object|null Snippet object or null.
	 */
	public function get_snippet_by_post( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );

		$snippet = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE post_id = %d ORDER BY created_at DESC LIMIT 1",
				$post_id
			)
		);

		return $snippet;
	}

	/**
	 * Get all snippets with pagination
	 *
	 * @param array $args Query arguments.
	 * @return array Array of snippet objects.
	 */
	public function get_all_snippets( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'  => 20,
			'paged'     => 1,
			'post_type' => '',
			'search'    => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$offset = ( $args['paged'] - 1 ) * $args['per_page'];
		$limit  = $args['per_page'];

		$where = '1=1';
		$join  = '';

		// Filter by post type
		if ( ! empty( $args['post_type'] ) ) {
			$join .= " INNER JOIN {$wpdb->posts} p ON s.post_id = p.ID";
			$where .= $wpdb->prepare( ' AND p.post_type = %s', $args['post_type'] );
		}

		// Search in snippet text
		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND s.snippet_text LIKE %s', $search );
		}

		$orderby = in_array( $args['orderby'], array( 'id', 'post_id', 'created_at', 'updated_at' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$snippets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.* FROM {$this->table_name} s {$join} WHERE {$where} ORDER BY s.{$orderby} {$order} LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		return $snippets;
	}

	/**
	 * Get total snippet count
	 *
	 * @param array $args Query arguments.
	 * @return int Total count.
	 */
	public function get_total_count( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'post_type' => '',
			'search'    => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$where = '1=1';
		$join  = '';

		if ( ! empty( $args['post_type'] ) ) {
			$join .= " INNER JOIN {$wpdb->posts} p ON s.post_id = p.ID";
			$where .= $wpdb->prepare( ' AND p.post_type = %s', $args['post_type'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND s.snippet_text LIKE %s', $search );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} s {$join} WHERE {$where}"
		);

		return (int) $count;
	}

	/**
	 * Add Classic Editor metabox
	 */
	public function add_snippet_metabox() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'wpllmseo_snippet_metabox',
				__( 'AI Preferred Snippet', 'wpllmseo' ),
				array( $this, 'render_snippet_metabox' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render Classic Editor metabox
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_snippet_metabox( $post ) {
		wp_nonce_field( 'wpllmseo_save_snippet', 'wpllmseo_snippet_nonce' );

		$snippet = $this->get_snippet_by_post( $post->ID );
		$snippet_text = $snippet ? $snippet->snippet_text : '';

		?>
		<div class="wpllmseo-snippet-metabox">
			<p>
				<label for="wpllmseo_snippet_text">
					<?php esc_html_e( 'Enter the preferred snippet for this content. This will be used as the primary result in AI-powered search.', 'wpllmseo' ); ?>
				</label>
			</p>
			<textarea 
				id="wpllmseo_snippet_text" 
				name="wpllmseo_snippet_text" 
				rows="8" 
				style="width: 100%;"
				placeholder="<?php esc_attr_e( 'Select important text from your content or write a custom snippet...', 'wpllmseo' ); ?>"
			><?php echo esc_textarea( $snippet_text ); ?></textarea>
			
			<?php if ( $snippet ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: formatted date */
						esc_html__( 'Last updated: %s', 'wpllmseo' ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $snippet->updated_at ) ) )
					);
					?>
					<span style="color: #46b450; margin-left: 10px;">✓ <?php esc_html_e( 'Snippet set', 'wpllmseo' ); ?></span>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No snippet set yet.', 'wpllmseo' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save Classic Editor metabox
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_snippet_metabox( $post_id ) {
		// Check nonce
		if ( ! isset( $_POST['wpllmseo_snippet_nonce'] ) || ! wp_verify_nonce( $_POST['wpllmseo_snippet_nonce'], 'wpllmseo_save_snippet' ) ) {
			return;
		}

		// Check autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save snippet
		if ( isset( $_POST['wpllmseo_snippet_text'] ) ) {
			$snippet_text = trim( $_POST['wpllmseo_snippet_text'] );

			if ( ! empty( $snippet_text ) ) {
				$this->create_snippet( $post_id, $snippet_text );
			} else {
				// Delete snippet if text is empty
				$snippet = $this->get_snippet_by_post( $post_id );
				if ( $snippet ) {
					$this->delete_snippet( $snippet->id );
				}
			}
		}
	}

	/**
	 * Enqueue Gutenberg sidebar panel assets
	 */
	public function enqueue_gutenberg_assets() {
		$asset_file = WPLLMSEO_PLUGIN_DIR . 'admin/assets/js/gutenberg-snippet-panel.js';
		
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		wp_enqueue_script(
			'wpllmseo-gutenberg-snippet',
			WPLLMSEO_PLUGIN_URL . 'admin/assets/js/gutenberg-snippet-panel.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ),
			filemtime( $asset_file ),
			true
		);

		// Enqueue snippet CSS for Gutenberg
		wp_enqueue_style(
			'wpllmseo-snippets',
			WPLLMSEO_PLUGIN_URL . 'admin/assets/css/snippets.css',
			array(),
			filemtime( WPLLMSEO_PLUGIN_DIR . 'admin/assets/css/snippets.css' )
		);

		wp_localize_script(
			'wpllmseo-gutenberg-snippet',
			'wpllmseoSnippet',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'apiUrl'   => rest_url( 'wp-llmseo/v1/snippet' ),
				'postId'   => get_the_ID(),
				'labels'   => array(
					'title'       => __( 'AI Preferred Snippet', 'wpllmseo' ),
					'description' => __( 'Set the preferred snippet for AI-powered search results.', 'wpllmseo' ),
					'button'      => __( 'Set as Preferred Snippet', 'wpllmseo' ),
					'update'      => __( 'Update Snippet', 'wpllmseo' ),
					'remove'      => __( 'Remove Snippet', 'wpllmseo' ),
					'saved'       => __( 'Snippet saved successfully!', 'wpllmseo' ),
					'error'       => __( 'Error saving snippet. Please try again.', 'wpllmseo' ),
					'noText'      => __( 'Please enter snippet text.', 'wpllmseo' ),
					'snippetSet'  => __( 'Snippet is set', 'wpllmseo' ),
				),
			)
		);
	}

	/**
	 * Enqueue admin assets for Classic Editor and admin screens
	 */
	public function enqueue_admin_assets( $hook ) {
		// Enqueue on post edit screens and snippets admin page
		$screen = get_current_screen();
		
		if ( ! $screen ) {
			return;
		}

		$is_post_screen = in_array( $screen->base, array( 'post', 'post-new' ), true );
		$is_snippets_screen = isset( $_GET['page'] ) && 'wp-llm-seo-indexing' === $_GET['page'] && isset( $_GET['screen'] ) && 'snippets' === $_GET['screen'];

		if ( $is_post_screen || $is_snippets_screen ) {
			wp_enqueue_style(
				'wpllmseo-snippets',
				WPLLMSEO_PLUGIN_URL . 'admin/assets/css/snippets.css',
				array(),
				filemtime( WPLLMSEO_PLUGIN_DIR . 'admin/assets/css/snippets.css' )
			);
		}
	}
}
