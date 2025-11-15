<?php
/**
 * Cleanup old per-chunk postmeta entries created by earlier versions.
 * Supports dry-run preview and actual deletion.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_Cleanup_Postmeta {

    /**
     * Scan attachments for _wpllmseo_embedding_chunk_{index} keys and either preview or delete.
     *
     * @param int  $batch_size
     * @param int  $offset
     * @param bool $execute Whether to actually delete or just dry-run.
     * @return array
     */
    public static function run( $batch_size = 50, $offset = 0, $execute = false ) {
        global $wpdb;

        $attachments = get_posts(
            array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => $batch_size,
                'offset' => $offset,
                'fields' => 'ids',
            )
        );

        $found = 0;
        $deleted = 0;
        $samples = array();

        foreach ( $attachments as $attachment_id ) {
            $meta = get_post_meta( $attachment_id );
            foreach ( $meta as $k => $v ) {
                if ( preg_match( '/^_wpllmseo_embedding_chunk_\d+$/', $k ) ) {
                    $found++;
                    if ( count( $samples ) < 10 ) {
                        $samples[] = array( 'post_id' => $attachment_id, 'meta_key' => $k );
                    }
                    if ( $execute ) {
                        delete_post_meta( $attachment_id, $k );
                        $deleted++;
                    }
                }
            }
        }

        return array(
            'processed' => count( $attachments ),
            'found' => $found,
            'deleted' => $deleted,
            'samples' => $samples,
            'execute' => (bool) $execute,
        );
    }
}

// REST route to run cleanup (admin-only)
add_action( 'rest_api_init', function() {
    register_rest_route( WPLLMSEO_REST_NAMESPACE, '/cleanup/postmeta', array(
        'methods' => 'POST',
        'callback' => function( $request ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
            }

            $batch = intval( $request->get_param( 'batch' ) ?? 50 );
            $offset = intval( $request->get_param( 'offset' ) ?? 0 );
            $execute = filter_var( $request->get_param( 'execute' ), FILTER_VALIDATE_BOOLEAN );

            $res = WPLLMSEO_Cleanup_Postmeta::run( $batch, $offset, $execute );

            // If this was a dry-run preview, persist a short-lived preview summary
            if ( ! $execute ) {
                $preview = array(
                    'time' => time(),
                    'batch' => $batch,
                    'offset' => $offset,
                    'processed' => $res['processed'] ?? 0,
                    'found' => $res['found'] ?? 0,
                );
                // store for 24 hours
                update_option( 'wpllmseo_cleanup_last_preview', $preview );
            }

            return rest_ensure_response( $res );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
    ) );
} );

// REST route to fetch cleanup progress (admin-only)
add_action( 'rest_api_init', function() {
    register_rest_route( WPLLMSEO_REST_NAMESPACE, '/cleanup/progress', array(
        'methods'  => 'GET',
        'callback' => function() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'forbidden', 'Insufficient permissions', array( 'status' => 403 ) );
            }
            $progress = get_option( 'wpllmseo_cleanup_progress', array() );
            return rest_ensure_response( $progress );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
    ) );
} );
