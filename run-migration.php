<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))).'/wp-load.php';
require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';
global $wpdb;

echo "Running migration...\n";

$validated = WPLLMSEO_DB_Helpers::validate_table_name( $wpdb->prefix . 'wpllmseo_jobs' );
if ( is_wp_error( $validated ) ) {
    echo "Invalid jobs table: " . $validated->get_error_message() . "\n";
    exit;
}

$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'post_id' ) );
if ( empty( $r ) ) {
    $res = WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER job_type" );
    if ( is_wp_error( $res ) ) {
        echo "Failed to add post_id: " . $res->get_error_message() . "\n";
    } else {
        WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD INDEX idx_post_id (post_id)" );
        echo "Added post_id\n";
    }
} else {
    echo "post_id exists\n";
}

$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'snippet_id' ) );
if ( empty( $r ) ) {
    $res = WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD COLUMN snippet_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id" );
    if ( is_wp_error( $res ) ) {
        echo "Failed to add snippet_id: " . $res->get_error_message() . "\n";
    } else {
        WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD INDEX idx_snippet_id (snippet_id)" );
        echo "Added snippet_id\n";
    }
} else {
    echo "snippet_id exists\n";
}

update_option( 'wpllmseo_db_version', '1.1.1' );
echo "Done!\n";
