<?php
/**
 * Migration: Import per-chunk postmeta embeddings into wpllmseo_chunks
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_Migrate_Embeddings {

    /**
     * Process attachments in batches and migrate embeddings.
     *
     * @param int $batch_size
     * @param int $offset
     * @return array Result summary
     */
    public static function run( $batch_size = 50, $offset = 0, $dry_run = false ) {
        global $wpdb;

        require_once __DIR__ . '/../helpers/class-db-helpers.php';

        $attachments = get_posts(
            array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => $batch_size,
                'offset' => $offset,
                'fields' => 'ids',
            )
        );

        $migrated = 0;
        $skipped = 0;

        foreach ( $attachments as $attachment_id ) {
            // Skip if already migrated
            $already = get_post_meta( $attachment_id, '_wpllmseo_migrated', true );
            if ( ! empty( $already ) ) {
                $skipped++;
                continue;
            }

            $chunk_count = get_post_meta( $attachment_id, '_wpllmseo_chunk_count', true );
            if ( empty( $chunk_count ) ) {
                $skipped++;
                continue;
            }

            $attachment_migrated = 0;

            for ( $i = 0; $i < intval( $chunk_count ); $i++ ) {
                $meta_key = "_wpllmseo_embedding_chunk_{$i}";
                $embedding_json = get_post_meta( $attachment_id, $meta_key, true );
                if ( empty( $embedding_json ) ) {
                    continue;
                }

                $embedding = null;
                $decoded = json_decode( $embedding_json, true );
                if ( null !== $decoded ) {
                    $embedding = $decoded;
                } else {
                    // Try to decode as numeric CSV
                    $parts = array_map( 'floatval', preg_split( '/\s*,\s*/', $embedding_json ) );
                    $embedding = $parts;
                }

                $chunks = get_post_meta( $attachment_id, '_wpllmseo_chunks_content', false );
                // Fallback: we may not have stored chunk content; attempt to reconstruct via chunker
                $chunk_text = '';
                if ( isset( $chunks[ $i ] ) ) {
                    $chunk_text = $chunks[ $i ];
                } else {
                    // Reconstruct quickly by extracting text and re-chunking small sample
                    $text = WPLLMSEO_Media_Embeddings::extract_pdf_text( get_attached_file( $attachment_id ) );
                    $chunker = new WPLLMSEO_Chunker();
                    $generated = $chunker->chunk_content( $text, 500, 50 );
                    $chunk_text = $generated[ $i ] ?? ( $generated[0] ?? '' );
                }

                $meta = array(
                    'embedding_dim' => is_array( $embedding ) ? count( $embedding ) : null,
                    'token_count' => str_word_count( $chunk_text ),
                    'checksum' => hash( 'sha256', wp_json_encode( $embedding ) ),
                    'version' => 'v1',
                );

                if ( ! $dry_run ) {
                    $res = WPLLMSEO_DB_Helpers::upsert_chunk_embedding( $attachment_id, $i, $chunk_text, $embedding, $meta );
                    if ( is_wp_error( $res ) ) {
                        // Log and continue
                        continue;
                    }
                    $migrated++;
                    $attachment_migrated++;
                } else {
                    // Dry run: count as would-be migrated if embedding exists
                    $migrated++;
                    $attachment_migrated++;
                }
            }

            // If we migrated any chunks for this attachment, mark it as migrated with timestamp.
            if ( $attachment_migrated > 0 && ! $dry_run ) {
                update_post_meta( $attachment_id, '_wpllmseo_migrated', current_time( 'mysql' ) );
            }
        }

        return array(
            'processed' => count( $attachments ),
            'migrated' => $migrated,
            'skipped' => $skipped,
        );
    }
}

// Register REST route for manual migration trigger (admin-only)
add_action( 'rest_api_init', function() {
        register_rest_route( WPLLMSEO_REST_NAMESPACE, '/migrate/embeddings', array(
        'methods' => 'POST',
        'callback' => function( $request ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
            }

            $batch = intval( $request->get_param( 'batch' ) ?? 50 );
            $offset = intval( $request->get_param( 'offset' ) ?? 0 );
            $dry_run = filter_var( $request->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN );

            $res = WPLLMSEO_Migrate_Embeddings::run( $batch, $offset, $dry_run );

            return rest_ensure_response( $res );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
    ) );
} );

