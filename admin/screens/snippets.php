<?php
/**
 * Snippets Screen
 *
 * Displays all preferred snippets in a WP_List_Table.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

// Load WP_List_Table if not already loaded
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// Load component files
require_once WPLLMSEO_PLUGIN_DIR . 'admin/components/header.php';

/**
 * Snippets List Table Class
 */
class WPLLMSEO_Snippets_List_Table extends WP_List_Table {

	/**
	 * Snippets manager instance
	 *
	 * @var WPLLMSEO_Snippets
	 */
	private $snippets;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Snippet', 'wpllmseo' ),
				'plural'   => __( 'Snippets', 'wpllmseo' ),
				'ajax'     => false,
			)
		);

		$this->snippets = new WPLLMSEO_Snippets();
	}

	/**
	 * Get table columns
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'ID', 'wpllmseo' ),
			'post'         => __( 'Post', 'wpllmseo' ),
			'snippet'      => __( 'Snippet Preview', 'wpllmseo' ),
			'created_at'   => __( 'Created', 'wpllmseo' ),
			'updated_at'   => __( 'Updated', 'wpllmseo' ),
			'actions'      => __( 'Actions', 'wpllmseo' ),
		);
	}

	/**
	 * Get sortable columns
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'id'         => array( 'id', false ),
			'created_at' => array( 'created_at', true ),
			'updated_at' => array( 'updated_at', false ),
		);
	}

	/**
	 * Get bulk actions
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'wpllmseo' ),
		);
	}

	/**
	 * Process bulk actions
	 */
	public function process_bulk_action() {
		$action = $this->current_action();

		if ( 'delete' === $action ) {
			if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'] ) ) {
				wp_die( esc_html__( 'Security check failed', 'wpllmseo' ) );
			}

			$snippet_ids = isset( $_REQUEST['snippet'] ) ? array_map( 'absint', (array) $_REQUEST['snippet'] ) : array();

			foreach ( $snippet_ids as $snippet_id ) {
				$this->snippets->delete_snippet( $snippet_id );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=wp-llm-seo-indexing&screen=snippets&deleted=' . count( $snippet_ids ) ) );
			exit;
		}
	}

	/**
	 * Prepare table items
	 */
	public function prepare_items() {
		$per_page = 20;
		$current_page = $this->get_pagenum();

		$args = array(
			'per_page'  => $per_page,
			'paged'     => $current_page,
			'orderby'   => isset( $_REQUEST['orderby'] ) ? sanitize_text_field( $_REQUEST['orderby'] ) : 'created_at',
			'order'     => isset( $_REQUEST['order'] ) ? sanitize_text_field( $_REQUEST['order'] ) : 'DESC',
		);

		// Filter by post type
		if ( ! empty( $_REQUEST['post_type'] ) ) {
			$args['post_type'] = sanitize_text_field( $_REQUEST['post_type'] );
		}

		// Search
		if ( ! empty( $_REQUEST['s'] ) ) {
			$args['search'] = sanitize_text_field( $_REQUEST['s'] );
		}

		$this->items = $this->snippets->get_all_snippets( $args );
		$total_items = $this->snippets->get_total_count( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Render checkbox column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="snippet[]" value="%d" />',
			esc_attr( $item->id )
		);
	}

	/**
	 * Render ID column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_id( $item ) {
		return sprintf( '<strong>#%d</strong>', esc_html( $item->id ) );
	}

	/**
	 * Render post column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_post( $item ) {
		$post = get_post( $item->post_id );

		if ( ! $post ) {
			return sprintf(
				'<span style="color: #999;">%s</span>',
				esc_html__( 'Post not found', 'wpllmseo' )
			);
		}

		$edit_link = get_edit_post_link( $post->ID );
		$view_link = get_permalink( $post->ID );

		$output = sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $edit_link ),
			esc_html( $post->post_title )
		);

		$output .= '<br><small style="color: #666;">';
		$output .= sprintf(
			'<a href="%s" target="_blank">%s</a> &middot; %s',
			esc_url( $view_link ),
			esc_html__( 'View', 'wpllmseo' ),
			esc_html( get_post_type_object( $post->post_type )->labels->singular_name )
		);
		$output .= '</small>';

		return $output;
	}

	/**
	 * Render snippet column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_snippet( $item ) {
		$preview = wp_trim_words( $item->snippet_text, 15, '...' );
		
		return sprintf(
			'<div class="wpllmseo-snippet-preview" title="%s">%s</div>',
			esc_attr( $item->snippet_text ),
			esc_html( $preview )
		);
	}

	/**
	 * Render created_at column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_created_at( $item ) {
		return esc_html( date_i18n( 'M j, Y', strtotime( $item->created_at ) ) );
	}

	/**
	 * Render updated_at column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_updated_at( $item ) {
		return esc_html( date_i18n( 'M j, Y', strtotime( $item->updated_at ) ) );
	}

	/**
	 * Render actions column
	 *
	 * @param object $item Item object.
	 * @return string
	 */
	public function column_actions( $item ) {
		$actions = array();

		$actions[] = sprintf(
			'<button type="button" class="button button-small wpllmseo-reindex-snippet" data-snippet-id="%d">%s</button>',
			esc_attr( $item->id ),
			esc_html__( 'Reindex', 'wpllmseo' )
		);

		$actions[] = sprintf(
			'<button type="button" class="button button-small button-link-delete wpllmseo-delete-snippet" data-snippet-id="%d">%s</button>',
			esc_attr( $item->id ),
			esc_html__( 'Delete', 'wpllmseo' )
		);

		return implode( ' ', $actions );
	}

	/**
	 * Render extra tablenav
	 *
	 * @param string $which Position (top or bottom).
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		?>
		<div class="alignleft actions">
			<?php $this->render_post_type_filter(); ?>
			<?php submit_button( __( 'Filter', 'wpllmseo' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Render post type filter
	 */
	private function render_post_type_filter() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$current = isset( $_REQUEST['post_type'] ) ? sanitize_text_field( $_REQUEST['post_type'] ) : '';

		?>
		<select name="post_type">
			<option value=""><?php esc_html_e( 'All Post Types', 'wpllmseo' ); ?></option>
			<?php foreach ( $post_types as $post_type ) : ?>
				<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $current, $post_type->name ); ?>>
					<?php echo esc_html( $post_type->labels->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}

// Initialize and display table
$list_table = new WPLLMSEO_Snippets_List_Table();
$list_table->process_bulk_action();
$list_table->prepare_items();

wpllmseo_render_header(
	array(
		'title'       => __( 'Preferred Snippets', 'wpllmseo' ),
		'description' => __( 'Manage AI-preferred snippets for your content.', 'wpllmseo' ),
	)
);

// Show success/error messages
if ( isset( $_GET['deleted'] ) ) {
	$count = absint( $_GET['deleted'] );
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		sprintf(
			/* translators: %d: number of snippets deleted */
			esc_html( _n( '%d snippet deleted.', '%d snippets deleted.', $count, 'wpllmseo' ) ),
			esc_html( $count )
		)
	);
}

if ( isset( $_GET['reindexed'] ) ) {
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html__( 'Snippet queued for reindexing.', 'wpllmseo' )
	);
}

?>

<div class="wpllmseo-snippets-screen">
	<form method="get">
		<input type="hidden" name="page" value="wp-llm-seo-indexing" />
		<input type="hidden" name="screen" value="snippets" />
		<?php
		$list_table->search_box( __( 'Search Snippets', 'wpllmseo' ), 'snippet' );
		$list_table->display();
		?>
	</form>
</div>
