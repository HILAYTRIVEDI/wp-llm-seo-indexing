<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up WordPress testing environment.
 *
 * @package WPLLMSEO
 */

// Define test environment
define( 'WPLLMSEO_TESTING', true );

// Load WordPress test environment if available
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Load WordPress test functions
if ( file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	require_once $_tests_dir . '/includes/functions.php';
	
	// Manually load plugin
	function _manually_load_plugin() {
		require dirname( dirname( __FILE__ ) ) . '/wp-llm-seo-indexing.php';
	}
	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
	
	// Start up the WP testing environment
	require $_tests_dir . '/includes/bootstrap.php';
	
} else {
	// Fallback for environments without WP test suite
	echo "Warning: WordPress test suite not found. Using fallback.\n";
	
	// Define minimal WordPress constants
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/' );
	}
	
	// Load plugin
	require dirname( dirname( __FILE__ ) ) . '/wp-llm-seo-indexing.php';
	
	// Minimal WP_UnitTestCase stub
	if ( ! class_exists( 'WP_UnitTestCase' ) ) {
		class WP_UnitTestCase extends PHPUnit\Framework\TestCase {
			public function setUp(): void {
				parent::setUp();
			}
			
			public function tearDown(): void {
				parent::tearDown();
			}
		}
	}
}
