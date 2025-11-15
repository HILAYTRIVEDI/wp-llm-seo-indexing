<?php
class TestCleanup extends WP_UnitTestCase {
    public function test_cleanup_progress_option_exists() {
        // Ensure the option can be created and read
        update_option( 'wpllmseo_cleanup_progress', array( 'running' => false, 'offset' => 0, 'batch' => 1 ) );
        $p = get_option( 'wpllmseo_cleanup_progress' );
        $this->assertIsArray( $p );
        $this->assertArrayHasKey( 'batch', $p );
    }
}
