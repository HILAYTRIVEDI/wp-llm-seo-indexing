<?php
/**
 * Media Embeddings
 *
 * Generate embeddings for PDF files and media attachments.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Media_Embeddings
 *
 * Handles embedding generation for non-post content like PDFs and media.
 */
class WPLLMSEO_Media_Embeddings {

	/**
	 * Supported media types.
	 */
	const SUPPORTED_TYPES = array(
		'application/pdf',
		'text/plain',
		'text/csv',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	);

	/**
	 * Max file size (10MB).
	 */
	const MAX_FILE_SIZE = 10485760;

	/**
	 * Initialize media embeddings.
	 */
	public static function init() {
		// Add attachment indexing.
		add_action( 'add_attachment', array( __CLASS__, 'queue_attachment' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'queue_attachment' ) );

		// Add media library column.
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_column' ), 10, 2 );

		// Add bulk action.
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );

		// AJAX handlers.
		add_action( 'wp_ajax_wpllmseo_index_attachment', array( __CLASS__, 'ajax_index_attachment' ) );
		add_action( 'wp_ajax_wpllmseo_extract_pdf_text', array( __CLASS__, 'ajax_extract_pdf_text' ) );

		// REST endpoint.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/media/(?P<id>\d+)/index',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_index_attachment' ),
				'permission_callback' => function() {
					return current_user_can( 'upload_files' );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Queue attachment for indexing.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function queue_attachment( $attachment_id ) {
		$settings = get_option( 'wpllmseo_settings', array() );

		if ( empty( $settings['enable_media_embeddings'] ) ) {
			return;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, self::SUPPORTED_TYPES, true ) ) {
			return;
		}

		// Queue for processing.
		WPLLMSEO_Queue::add_item(
			$attachment_id,
			'attachment',
			array(
				'mime_type' => $mime_type,
			)
		);
	}

	/**
	 * Index attachment (process from queue).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool|WP_Error
	 */
	public static function index_attachment( $attachment_id ) {
		$mime_type = get_post_mime_type( $attachment_id );
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'Attachment file not found.' );
		}

		// Check file size.
		$file_size = filesize( $file_path );
		if ( $file_size > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'file_too_large', 'File exceeds maximum size (10MB).' );
		}

		// Extract text based on mime type.
		$text = '';
		switch ( $mime_type ) {
			case 'application/pdf':
				$text = self::extract_pdf_text( $file_path );
				break;

			case 'text/plain':
			case 'text/csv':
				$text = file_get_contents( $file_path );
				break;

			case 'application/msword':
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				$text = self::extract_docx_text( $file_path );
				break;

			default:
				return new WP_Error( 'unsupported_type', 'Unsupported file type.' );
		}

		if ( empty( $text ) ) {
			return new WP_Error( 'no_text_extracted', 'No text could be extracted from file.' );
		}

		// Chunk and embed.
		$chunks = self::chunk_text( $text, $attachment_id );
		$result = self::generate_embeddings( $chunks, $attachment_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update metadata.
		update_post_meta( $attachment_id, '_wpllmseo_indexed', current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_wpllmseo_chunk_count', count( $chunks ) );
		update_post_meta( $attachment_id, '_wpllmseo_extracted_text_length', strlen( $text ) );

		return true;
	}

	/**
	 * Extract text from PDF.
	 *
	 * @param string $file_path File path.
	 * @return string
	 */
	private static function extract_pdf_text( $file_path ) {
		// Try multiple methods.
		$text = '';

		// Method 1: pdftotext command (if available) via Exec Guard.
		require_once __DIR__ . '/helpers/class-exec-guard.php';
		if ( WPLLMSEO_Exec_Guard::is_available() ) {
			$output = array();
			$cmd    = 'pdftotext ' . escapeshellarg( $file_path ) . ' -';
			$result = WPLLMSEO_Exec_Guard::run( $cmd, $output );
			if ( $result === true && ! empty( $output ) ) {
				$text = implode( "\n", $output );
			}
		}

		// Method 2: Simple parser (basic extraction).
		if ( empty( $text ) ) {
			$content = file_get_contents( $file_path );
			
			// Extract text between stream markers.
			preg_match_all( '/\(([^)]+)\)/s', $content, $matches );
			if ( ! empty( $matches[1] ) ) {
				$text = implode( ' ', $matches[1] );
			}
		}

		// Clean up text.
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return $text;
	}

	/**
	 * Extract text from DOCX.
	 *
	 * @param string $file_path File path.
	 * @return string
	 */
	private static function extract_docx_text( $file_path ) {
		$text = '';

		if ( ! class_exists( 'ZipArchive' ) ) {
			return $text;
		}

		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) === true ) {
			$xml = $zip->getFromName( 'word/document.xml' );
			$zip->close();

			if ( $xml ) {
				// Remove XML tags.
				$text = strip_tags( $xml );
				$text = preg_replace( '/\s+/', ' ', $text );
				$text = trim( $text );
			}
		}

		return $text;
	}

	/**
	 * Chunk text for embeddings.
	 *
	 * @param string $text          Text content.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	private static function chunk_text( $text, $attachment_id ) {
		$chunker = new WPLLMSEO_Chunker();
		$chunks  = $chunker->chunk_content( $text, 500, 50 );

		$attachment = get_post( $attachment_id );
		$title      = $attachment->post_title;
		$url        = wp_get_attachment_url( $attachment_id );

		$formatted_chunks = array();
		foreach ( $chunks as $index => $chunk ) {
			$formatted_chunks[] = array(
				'post_id'      => $attachment_id,
				'chunk_index'  => $index,
				'content'      => $chunk,
				'token_count'  => str_word_count( $chunk ),
				'meta_context' => json_encode(
					array(
						'type'  => 'attachment',
						'title' => $title,
						'url'   => $url,
						'mime'  => get_post_mime_type( $attachment_id ),
					)
				),
			);
		}

		return $formatted_chunks;
	}

	/**
	 * Generate embeddings for chunks.
	 *
	 * @param array $chunks        Chunks array.
	 * @param int   $attachment_id Attachment ID.
	 * @return bool|WP_Error
	 */
	private static function generate_embeddings( $chunks, $attachment_id ) {
		$embedder = new WPLLMSEO_Embedder();

		// Use batch embedder for efficiency and caching.
		$results = $embedder->embed_chunks_batch( $chunks );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		// Persist embeddings per-chunk in attachment meta for now.
		foreach ( $results as $index => $res ) {
			if ( is_wp_error( $res ) ) {
				// Log and continue
				continue;
			}

			$embedding = $res['embedding'];
			$chunk_text = $chunks[ $index ]['content'] ?? '';
			$token_count = $chunks[ $index ]['token_count'] ?? str_word_count( $chunk_text );

			require_once __DIR__ . '/helpers/class-db-helpers.php';
			$meta = array(
				'embedding_dim' => is_array( $embedding ) ? count( $embedding ) : null,
				'token_count' => $token_count,
				'checksum' => hash( 'sha256', wp_json_encode( $embedding ) ),
				'version' => 'v1',
			);

			WPLLMSEO_DB_Helpers::upsert_chunk_embedding( $attachment_id, $index, $chunk_text, $embedding, $meta );
		}

		return true;
	}

	/**
	 * Add media library column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_media_column( $columns ) {
		$columns['wpllmseo_indexed'] = __( 'LLM Indexed', 'wpllmseo' );
		return $columns;
	}

	/**
	 * Render media column.
	 *
	 * @param string $column_name Column name.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_media_column( $column_name, $attachment_id ) {
		if ( 'wpllmseo_indexed' !== $column_name ) {
			return;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, self::SUPPORTED_TYPES, true ) ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		$indexed = get_post_meta( $attachment_id, '_wpllmseo_indexed', true );

		if ( $indexed ) {
			require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';
			$chunk_count = WPLLMSEO_DB_Helpers::get_chunk_count( $attachment_id );
			echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ';
			echo esc_html( $chunk_count ) . ' chunks';
		} else {
			echo '<a href="#" class="wpllmseo-index-media" data-id="' . esc_attr( $attachment_id ) . '">Index Now</a>';
		}
	}

	/**
	 * Add bulk action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function add_bulk_action( $actions ) {
		$actions['wpllmseo_index_media'] = __( 'Generate LLM Embeddings', 'wpllmseo' );
		return $actions;
	}

	/**
	 * Handle bulk action.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action name.
	 * @param array  $post_ids    Post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( 'wpllmseo_index_media' !== $action ) {
			return $redirect_to;
		}

		$queued = 0;
		foreach ( $post_ids as $attachment_id ) {
			$mime_type = get_post_mime_type( $attachment_id );
			if ( in_array( $mime_type, self::SUPPORTED_TYPES, true ) ) {
				WPLLMSEO_Queue::add_item( $attachment_id, 'attachment' );
				$queued++;
			}
		}

		$redirect_to = add_query_arg( 'wpllmseo_media_queued', $queued, $redirect_to );

		return $redirect_to;
	}

	/**
	 * AJAX handler: Index attachment.
	 */
	public static function ajax_index_attachment() {
		check_ajax_referer( 'wpllmseo_media_indexing', 'nonce' );

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );

		if ( ! $attachment_id || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid permissions.' ) );
		}

		$result = self::index_attachment( $attachment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'Attachment indexed successfully.' ) );
	}

	/**
	 * AJAX handler: Extract PDF text.
	 */
	public static function ajax_extract_pdf_text() {
		check_ajax_referer( 'wpllmseo_media_indexing', 'nonce' );

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );

		if ( ! $attachment_id || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Invalid permissions.' ) );
		}

		$file_path = get_attached_file( $attachment_id );
		$text      = self::extract_pdf_text( $file_path );

		wp_send_json_success(
			array(
				'text'   => substr( $text, 0, 1000 ) . '...',
				'length' => strlen( $text ),
			)
		);
	}

	/**
	 * REST endpoint: Index attachment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_index_attachment( $request ) {
		$attachment_id = $request->get_param( 'id' );

		$result = self::index_attachment( $attachment_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';
		return rest_ensure_response(
			array(
				'success'     => true,
				'chunk_count' => WPLLMSEO_DB_Helpers::get_chunk_count( $attachment_id ),
				'indexed_at'  => get_post_meta( $attachment_id, '_wpllmseo_indexed', true ),
			)
		);
	}

	/**
	 * Get indexed media stats.
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;

		$stats = array(
			'total_media'   => 0,
			'indexed_media' => 0,
			'total_chunks'  => 0,
			'by_type'       => array(),
		);

		// Count attachments.
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $attachments as $attachment_id ) {
			$mime_type = get_post_mime_type( $attachment_id );
			if ( ! in_array( $mime_type, self::SUPPORTED_TYPES, true ) ) {
				continue;
			}

			$stats['total_media']++;

			if ( ! isset( $stats['by_type'][ $mime_type ] ) ) {
				$stats['by_type'][ $mime_type ] = array(
					'total'   => 0,
					'indexed' => 0,
				);
			}
			$stats['by_type'][ $mime_type ]['total']++;

			$indexed = get_post_meta( $attachment_id, '_wpllmseo_indexed', true );
			if ( $indexed ) {
				$stats['indexed_media']++;
				$stats['by_type'][ $mime_type ]['indexed']++;
				$chunk_count = WPLLMSEO_DB_Helpers::get_chunk_count( $attachment_id );
				$stats['total_chunks'] += intval( $chunk_count );
			}
		}

		return $stats;
	}
}
