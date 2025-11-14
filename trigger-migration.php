<?php
/**
 * Trigger database migration
 * 
 * This file will be auto-deleted after execution
 */

// Load WordPress
require_once dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';

// Trigger migration
if ( class_exists( 'WPLLMSEO_Installer_Upgrader' ) ) {
    WPLLMSEO_Installer_Upgrader::run_migrations();
    echo "Database migration completed successfully!\n";
} else {
    echo "Error: Installer class not found. Please ensure plugin is activated.\n";
}

// Delete this file
@unlink( __FILE__ );
