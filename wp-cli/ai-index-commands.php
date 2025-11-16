<?php
/**
 * WP-CLI Commands for AI Index Export
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI Index CLI commands for export and embedding management.
 */
class WPLLMSEO_AI_Index_CLI {

	/**
	 * Export chunks and embeddings to NDJSON files.
	 *
	 * ## OPTIONS
	 *
	 * [--output-dir=<dir>]
	 * : Output directory for export files.
	 * ---
	 * default: wp-content/uploads/ai-index
	 * ---
	 *
	 * [--since=<datetime>]
	 * : Export only chunks updated since this datetime (ISO 8601 format).
	 *
	 * [--limit=<number>]
	 * : Limit number of chunks to export.
	 *
	 * [--no-gz]
	 * : Do not compress output files.
	 *
	 * [--type=<type>]
	 * : Export type: chunks, embeddings, or both.
	 * ---
	 * default: both
	 * options:
	 *   - chunks
	 *   - embeddings
	 *   - both
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-index export
	 *     wp ai-index export --output-dir=/var/www/html/exports
	 *     wp ai-index export --since=2025-11-15T00:00:00
	 *     wp ai-index export --type=chunks --limit=1000
	 *
	 * @when after_wp_load
	 */
	public function export( $args, $assoc_args ) {
		$output_dir = $assoc_args['output-dir'] ?? ABSPATH . 'wp-content/uploads/ai-index';
		$since      = $assoc_args['since'] ?? null;
		$limit      = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null;
		$compress   = ! isset( $assoc_args['no-gz'] );
		$type       = $assoc_args['type'] ?? 'both';

		// Create output directory.
		if ( ! file_exists( $output_dir ) ) {
			wp_mkdir_p( $output_dir );
			WP_CLI::log( "Created directory: {$output_dir}" );
		}

		$export_args = array(
			'since'    => $since,
			'limit'    => $limit,
			'compress' => $compress,
		);

		$extension = $compress ? '.ndjson.gz' : '.ndjson';
		$success   = true;

		// Export chunks.
		if ( in_array( $type, array( 'chunks', 'both' ), true ) ) {
			$chunks_file = $output_dir . '/ai-chunks' . $extension;
			WP_CLI::log( 'Exporting chunks...' );

			$result = WPLLMSEO_AI_Index_REST::export_chunks_ndjson( $chunks_file, $export_args );

			if ( $result ) {
				$size = filesize( $chunks_file );
				WP_CLI::success( sprintf( 'Chunks exported to %s (%s)', $chunks_file, size_format( $size ) ) );
			} else {
				WP_CLI::error( 'Failed to export chunks' );
				$success = false;
			}
		}

		// Export embeddings.
		if ( in_array( $type, array( 'embeddings', 'both' ), true ) ) {
			$embeddings_file = $output_dir . '/ai-embeddings' . $extension;
			WP_CLI::log( 'Exporting embeddings...' );

			$result = WPLLMSEO_AI_Index_REST::export_embeddings_ndjson( $embeddings_file, $export_args );

			if ( $result ) {
				$size = filesize( $embeddings_file );
				WP_CLI::success( sprintf( 'Embeddings exported to %s (%s)', $embeddings_file, size_format( $size ) ) );
			} else {
				WP_CLI::error( 'Failed to export embeddings' );
				$success = false;
			}
		}

		// Export delta if since parameter is provided.
		if ( $since && in_array( $type, array( 'chunks', 'both' ), true ) ) {
			$delta_file = $output_dir . '/ai-chunks-delta' . $extension;
			WP_CLI::log( 'Exporting delta...' );

			$result = WPLLMSEO_AI_Index_REST::export_chunks_ndjson( $delta_file, $export_args );

			if ( $result ) {
				$size = filesize( $delta_file );
				WP_CLI::success( sprintf( 'Delta exported to %s (%s)', $delta_file, size_format( $size ) ) );
			}
		}

		if ( $success ) {
			WP_CLI::success( 'Export completed successfully' );
		}
	}

	/**
	 * Generate embeddings for chunks that don't have them.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<provider>]
	 * : Provider to use (gemini, openai, claude).
	 *
	 * [--model=<model>]
	 * : Model to use for embedding.
	 *
	 * [--batch=<number>]
	 * : Number of chunks to process in one batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum number of chunks to embed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-index embed --provider=gemini --model=text-embedding-004
	 *     wp ai-index embed --batch=50 --limit=500
	 *
	 * @when after_wp_load
	 */
	public function embed( $args, $assoc_args ) {
		global $wpdb;

		$provider = $assoc_args['provider'] ?? null;
		$model    = $assoc_args['model'] ?? null;
		$batch    = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 100;
		$limit    = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null;

		// Get active provider and model from settings if not specified.
		if ( ! $provider || ! $model ) {
			$settings = get_option( 'wpllmseo_settings', array() );
			$provider = $provider ?? $settings['active_providers']['embedding'] ?? null;
			$model    = $model ?? $settings['active_models']['embedding'] ?? null;
		}

		if ( ! $provider || ! $model ) {
			WP_CLI::error( 'Provider and model must be specified or configured in settings' );
			return;
		}

		WP_CLI::log( "Using provider: {$provider}, model: {$model}" );

		// Get chunks without embeddings.
		$table = $wpdb->prefix . 'wpllmseo_chunks';
		$where = 'embedding IS NULL OR embedding = ""';
		
		$limit_clause = '';
		if ( $limit ) {
			$limit_clause = $wpdb->prepare( ' LIMIT %d', $limit );
		}

		$chunks = $wpdb->get_results(
			"SELECT id, chunk_id, text FROM `{$table}` WHERE {$where} ORDER BY id" . $limit_clause,
			ARRAY_A
		);

		if ( empty( $chunks ) ) {
			WP_CLI::success( 'No chunks need embedding' );
			return;
		}

		$total = count( $chunks );
		WP_CLI::log( "Found {$total} chunks without embeddings" );

		// Initialize embedder.
		$embedder = new WPLLMSEO_Embedder();
		$processed = 0;
		$failed = 0;

		// Process in batches.
		$chunk_batches = array_chunk( $chunks, $batch );

		foreach ( $chunk_batches as $batch_index => $chunk_batch ) {
			$batch_num = $batch_index + 1;
			WP_CLI::log( "Processing batch {$batch_num}..." );

			foreach ( $chunk_batch as $chunk ) {
				try {
					if ( empty( $chunk['text'] ) ) {
						continue;
					}

					// Generate embedding.
					$provider_instance = WPLLMSEO_Provider_Manager::get_provider( $provider );
					if ( ! $provider_instance ) {
						WP_CLI::warning( "Provider {$provider} not available" );
						$failed++;
						continue;
					}

					$embedding = $provider_instance->generate_embedding( $chunk['text'], $model );

					if ( is_wp_error( $embedding ) ) {
						WP_CLI::warning( "Failed to generate embedding for chunk {$chunk['chunk_id']}: " . $embedding->get_error_message() );
						$failed++;
						continue;
					}

					// Save embedding.
					$wpdb->update(
						$table,
						array(
							'embedding'       => wp_json_encode( $embedding ),
							'embedding_model' => $model,
							'updated_at'      => current_time( 'mysql' ),
						),
						array( 'id' => $chunk['id'] ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);

					$processed++;

				} catch ( Exception $e ) {
					WP_CLI::warning( "Error processing chunk {$chunk['chunk_id']}: " . $e->getMessage() );
					$failed++;
				}
			}

			// Brief pause between batches to avoid rate limits.
			if ( $batch_num < count( $chunk_batches ) ) {
				sleep( 1 );
			}
		}

		WP_CLI::success( sprintf(
			'Embedding completed: %d processed, %d failed',
			$processed,
			$failed
		) );
	}

	/**
	 * Display AI index statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-index stats
	 *
	 * @when after_wp_load
	 */
	public function stats( $args, $assoc_args ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpllmseo_chunks';

		$total_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$chunks_with_embeddings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE embedding IS NOT NULL AND embedding != ''" );
		$chunks_without_embeddings = $total_chunks - $chunks_with_embeddings;

		$last_updated = $wpdb->get_var( "SELECT MAX(updated_at) FROM `{$table}`" );

		WP_CLI::log( '' );
		WP_CLI::log( 'AI Index Statistics:' );
		WP_CLI::log( '===================' );
		WP_CLI::log( sprintf( 'Total chunks: %d', $total_chunks ) );
		WP_CLI::log( sprintf( 'Chunks with embeddings: %d (%.1f%%)', $chunks_with_embeddings, $total_chunks > 0 ? ( $chunks_with_embeddings / $total_chunks * 100 ) : 0 ) );
		WP_CLI::log( sprintf( 'Chunks without embeddings: %d', $chunks_without_embeddings ) );
		WP_CLI::log( sprintf( 'Last updated: %s', $last_updated ?? 'Never' ) );
		WP_CLI::log( '' );

		// Check export files.
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/ai-index';

		if ( file_exists( $export_dir ) ) {
			WP_CLI::log( 'Export Files:' );
			WP_CLI::log( '=============' );

			$files = array( 'ai-chunks.ndjson.gz', 'ai-embeddings.ndjson.gz', 'ai-chunks-delta.ndjson.gz' );
			foreach ( $files as $file ) {
				$file_path = $export_dir . '/' . $file;
				if ( file_exists( $file_path ) ) {
					$size = filesize( $file_path );
					$modified = date( 'Y-m-d H:i:s', filemtime( $file_path ) );
					WP_CLI::log( sprintf( '%s: %s (updated %s)', $file, size_format( $size ), $modified ) );
				} else {
					WP_CLI::log( sprintf( '%s: Not found', $file ) );
				}
			}
			WP_CLI::log( '' );
		}
	}

	/**
	 * Populate chunk metadata fields from existing data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-index populate-metadata
	 *
	 * @when after_wp_load
	 */
	public function populate_metadata( $args, $assoc_args ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpllmseo_chunks';

		WP_CLI::log( 'Populating chunk metadata...' );

		// Get all chunks.
		$chunks = $wpdb->get_results( "SELECT id, post_id, chunk_index, chunk_text FROM `{$table}`", ARRAY_A );
		$total = count( $chunks );

		WP_CLI::log( "Found {$total} chunks to process" );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing chunks', $total );

		foreach ( $chunks as $chunk ) {
			$text = $chunk['chunk_text'] ?? '';
			$words = str_word_count( $text );
			$chars = mb_strlen( $text );
			$tokens = (int) ( $words * 1.3 );
			
			$chunk_id = $chunk['post_id'] . '-' . str_pad( $chunk['chunk_index'], 4, '0', STR_PAD_LEFT );
			$chunk_hash = md5( $text );

			$wpdb->update(
				$table,
				array(
					'chunk_id'       => $chunk_id,
					'text'           => $text,
					'word_count'     => $words,
					'char_count'     => $chars,
					'token_estimate' => $tokens,
					'chunk_hash'     => $chunk_hash,
				),
				array( 'id' => $chunk['id'] ),
				array( '%s', '%s', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success( "Metadata populated for {$total} chunks" );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'ai-index', 'WPLLMSEO_AI_Index_CLI' );
}
