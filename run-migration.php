<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))).'/wp-load.php';
global $wpdb;
$jobs_table = $wpdb->prefix . 'wpllmseo_jobs';
echo "Running migration...\n";
$r = $wpdb->get_results("SHOW COLUMNS FROM $jobs_table LIKE 'post_id'");
if (empty($r)) {
    $wpdb->query("ALTER TABLE $jobs_table ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER job_type");
    $wpdb->query("ALTER TABLE $jobs_table ADD INDEX idx_post_id (post_id)");
    echo "Added post_id\n";
} else { echo "post_id exists\n"; }
$r = $wpdb->get_results("SHOW COLUMNS FROM $jobs_table LIKE 'snippet_id'");
if (empty($r)) {
    $wpdb->query("ALTER TABLE $jobs_table ADD COLUMN snippet_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id");
    $wpdb->query("ALTER TABLE $jobs_table ADD INDEX idx_snippet_id (snippet_id)");
    echo "Added snippet_id\n";
} else { echo "snippet_id exists\n"; }
update_option('wpllmseo_db_version', '1.1.1');
echo "Done!\n";
