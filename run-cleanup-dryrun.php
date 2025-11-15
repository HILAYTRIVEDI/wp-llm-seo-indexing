<?php
// One-off runner: execute cleanup postmeta in dry-run mode and print JSON output.
define( 'WP_USE_THEMES', false );
// Adjust path to WP load - relative to plugin root (same as force-install.php)
require_once __DIR__ . '/../../../wp-load.php';

// Bootstrap the plugin code required
require_once __DIR__ . '/includes/migrations/class-cleanup-postmeta.php';

// Run dry-run (execute=false)
$batch = 50;
$offset = 0;
$execute = false;

$result = WPLLMSEO_Cleanup_Postmeta::run( $batch, $offset, $execute );

header( 'Content-Type: application/json' );
echo json_encode( $result, JSON_PRETTY_PRINT );
