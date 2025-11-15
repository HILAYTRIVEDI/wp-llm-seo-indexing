<?php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/includes/migrations/class-migrate-embeddings.php';

    WP_CLI::add_command( 'wpllmseo migrate-embeddings', function( $args, $assoc_args ) {
        $batch = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 50;
        $offset = isset( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0;
        $dry_run = isset( $assoc_args['dry-run'] ) ? filter_var( $assoc_args['dry-run'], FILTER_VALIDATE_BOOLEAN ) : false;

        $res = WPLLMSEO_Migrate_Embeddings::run( $batch, $offset, $dry_run );
        WP_CLI::success( sprintf( 'Processed %d attachments, migrated %d embeddings, skipped %d', $res['processed'], $res['migrated'], $res['skipped'] ) );
    } );

    WP_CLI::add_command( 'wpllmseo cleanup-postmeta', function( $args, $assoc_args ) {
        $batch = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 50;
        $offset = isset( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : 0;
        $execute = isset( $assoc_args['execute'] ) ? filter_var( $assoc_args['execute'], FILTER_VALIDATE_BOOLEAN ) : false;

        require_once __DIR__ . '/includes/migrations/class-cleanup-postmeta.php';
        $res = WPLLMSEO_Cleanup_Postmeta::run( $batch, $offset, $execute );
        WP_CLI::success( sprintf( 'Processed %d attachments, found %d keys, deleted %d (execute=%s)', $res['processed'], $res['found'], $res['deleted'], $res['execute'] ? 'true' : 'false' ) );
    } );

        WP_CLI::add_command( 'wpllmseo prune-exec-logs', function( $args, $assoc_args ) {
            require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';
            $settings = get_option( 'wpllmseo_settings', array() );
            $days = isset( $assoc_args['days'] ) ? intval( $assoc_args['days'] ) : ( isset( $settings['exec_logs_retention_days'] ) ? intval( $settings['exec_logs_retention_days'] ) : 30 );
            $deleted = WPLLMSEO_Exec_Logger::prune_older_than_days( $days );
            WP_CLI::success( "Pruned {$deleted} exec log rows older than {$days} days." );
        } );
    WP_CLI::add_command( 'wpllmseo migrate-verify', function( $args, $assoc_args ) {
        $sample = isset( $assoc_args['sample'] ) ? intval( $assoc_args['sample'] ) : 5;

        require_once __DIR__ . '/includes/migrations/class-migrate-embeddings.php';

        // Run migration in dry-run mode on a small sample
        $res = WPLLMSEO_Migrate_Embeddings::run( $sample, 0, true );
        WP_CLI::log( sprintf( 'Sample processed: %d, would migrate: %d, skipped: %d', $res['processed'], $res['migrated'], $res['skipped'] ) );
    } );

    WP_CLI::add_command( 'wpllmseo cleanup', function( $args, $assoc_args ) {
        $sub = isset( $args[0] ) ? $args[0] : 'status';
        switch ( $sub ) {
            case 'start':
                $batch = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 50;
                $max_total = isset( $assoc_args['max-total'] ) ? intval( $assoc_args['max-total'] ) : 0;
                update_option( 'wpllmseo_cleanup_progress', array( 'running' => true, 'offset' => 0, 'batch' => $batch, 'total_processed' => 0, 'max_total' => $max_total ) );
                if ( ! wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
                    wp_schedule_event( time(), 'hourly', 'wpllmseo_cleanup_postmeta_job' );
                }
                WP_CLI::success( 'Cleanup started (scheduled hourly).' );
                break;
            case 'stop':
                $progress = get_option( 'wpllmseo_cleanup_progress', array() );
                $progress['running'] = false;
                update_option( 'wpllmseo_cleanup_progress', $progress );
                if ( wp_next_scheduled( 'wpllmseo_cleanup_postmeta_job' ) ) {
                    wp_clear_scheduled_hook( 'wpllmseo_cleanup_postmeta_job' );
                }
                WP_CLI::success( 'Cleanup stopped and unscheduled.' );
                break;
            case 'run-batch':
                $batch = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : ( get_option( 'wpllmseo_cleanup_progress', array() )['batch'] ?? 50 );
                $offset = isset( $assoc_args['offset'] ) ? intval( $assoc_args['offset'] ) : ( get_option( 'wpllmseo_cleanup_progress', array() )['offset'] ?? 0 );
                require_once WPLLMSEO_PLUGIN_DIR . 'includes/migrations/class-cleanup-postmeta.php';
                $res = WPLLMSEO_Cleanup_Postmeta::run( $batch, $offset, true );
                WP_CLI::success( sprintf( 'Processed %d attachments, found %d keys, deleted %d', $res['processed'], $res['found'], $res['deleted'] ) );
                break;
            default:
                $progress = get_option( 'wpllmseo_cleanup_progress', array() );
                WP_CLI::line( 'Cleanup progress:' );
                WP_CLI::print_value( $progress );
                break;
        }
    } );
}
