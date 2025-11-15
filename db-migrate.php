<?php
require_once '/Users/hilaytrivedi/Local Sites/aeo/app/public/wp-load.php';
require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-db-helpers.php';
global $wpdb;

$t = $wpdb->prefix . 'wpllmseo_jobs';
echo "Migrating database...\n";

$validated = WPLLMSEO_DB_Helpers::validate_table_name( $t );
if ( is_wp_error( $validated ) ) {
    echo "Invalid table: " . $validated->get_error_message() . "\n";
    exit;
}

$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'post_id' ) );
if ( empty( $r ) ) {
    $res = WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER job_type" );
    if ( is_wp_error( $res ) ) {
        echo "Failed to add post_id: " . $res->get_error_message() . "\n";
    } else {
        WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD INDEX idx_post_id (post_id)" );
        echo "✓ Added post_id column\n";
    }
} else {
    echo "- post_id column already exists\n";
}

$r = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM %s LIKE %s", $validated, 'snippet_id' ) );
if ( empty( $r ) ) {
    $res = WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD COLUMN snippet_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id" );
    if ( is_wp_error( $res ) ) {
        echo "Failed to add snippet_id: " . $res->get_error_message() . "\n";
    } else {
        WPLLMSEO_DB_Helpers::safe_alter_table( $validated, "ADD INDEX idx_snippet_id (snippet_id)" );
        echo "✓ Added snippet_id column\n";
    }
} else {
    echo "- snippet_id column already exists\n";
}

update_option( 'wpllmseo_db_version', '1.1.1' );
echo "✓ Migration complete!\n";
