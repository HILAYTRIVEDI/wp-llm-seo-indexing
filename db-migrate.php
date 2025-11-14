<?php
require_once '/Users/hilaytrivedi/Local Sites/aeo/app/public/wp-load.php';
global $wpdb;
$t = $wpdb->prefix . 'wpllmseo_jobs';
echo "Migrating database...\n";
$r = $wpdb->get_results("SHOW COLUMNS FROM $t LIKE 'post_id'");
if (empty($r)) {
    $wpdb->query("ALTER TABLE $t ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER job_type");
    $wpdb->query("ALTER TABLE $t ADD INDEX idx_post_id (post_id)");
    echo "✓ Added post_id column\n";
} else {
    echo "- post_id column already exists\n";
}
$r = $wpdb->get_results("SHOW COLUMNS FROM $t LIKE 'snippet_id'");
if (empty($r)) {
    $wpdb->query("ALTER TABLE $t ADD COLUMN snippet_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id");
    $wpdb->query("ALTER TABLE $t ADD INDEX idx_snippet_id (snippet_id)");
    echo "✓ Added snippet_id column\n";
} else {
    echo "- snippet_id column already exists\n";
}
update_option('wpllmseo_db_version', '1.1.1');
echo "✓ Migration complete!\n";
