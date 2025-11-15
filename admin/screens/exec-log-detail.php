<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
}

require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';

$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
if ( ! $id ) {
    wp_die( esc_html__( 'Invalid log id', 'wpllmseo' ) );
}

$logs = WPLLMSEO_Exec_Logger::get_logs( 1, 0 );
$entry = null;
foreach ( $logs as $l ) {
    if ( intval( $l->id ) === $id ) {
        $entry = $l;
        break;
    }
}

if ( ! $entry ) {
    // Try direct DB fetch
    global $wpdb;
    $table = $wpdb->prefix . 'wpllmseo_exec_logs';
    $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
}

if ( ! $entry ) {
    wp_die( esc_html__( 'Log entry not found', 'wpllmseo' ) );
}

?>
<div class="wrap">
    <h1><?php esc_html_e( 'Exec Log Detail', 'wpllmseo' ); ?></h1>

    <table class="form-table">
        <tr><th><?php esc_html_e( 'ID', 'wpllmseo' ); ?></th><td><?php echo esc_html( $entry->id ); ?></td></tr>
        <tr><th><?php esc_html_e( 'User ID', 'wpllmseo' ); ?></th><td><?php echo esc_html( $entry->user_id ); ?></td></tr>
        <tr><th><?php esc_html_e( 'Command', 'wpllmseo' ); ?></th><td><pre><?php echo esc_html( $entry->command ); ?></pre></td></tr>
        <tr><th><?php esc_html_e( 'Stdout', 'wpllmseo' ); ?></th><td><pre><?php echo esc_html( $entry->stdout ); ?></pre></td></tr>
        <tr><th><?php esc_html_e( 'Stderr', 'wpllmseo' ); ?></th><td><pre><?php echo esc_html( $entry->stderr ); ?></pre></td></tr>
        <tr><th><?php esc_html_e( 'Result', 'wpllmseo' ); ?></th><td><?php echo esc_html( $entry->result ); ?></td></tr>
        <tr><th><?php esc_html_e( 'Created At', 'wpllmseo' ); ?></th><td><?php echo esc_html( $entry->created_at ); ?></td></tr>
    </table>

    <p><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpllmseo_exec_logs' ), admin_url( 'admin.php' ) ) ); ?>" class="button">
        <?php esc_html_e( 'Back', 'wpllmseo' ); ?>
    </a></p>
</div>
