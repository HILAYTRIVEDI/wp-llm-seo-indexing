<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'wpllmseo' ) );
}

require_once WPLLMSEO_PLUGIN_DIR . 'includes/helpers/class-exec-logger.php';

$page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$per_page = 25;
$offset = ( $page - 1 ) * $per_page;

// Filters
$filter_user = isset( $_GET['filter_user'] ) ? intval( $_GET['filter_user'] ) : 0;
$filter_result = isset( $_GET['filter_result'] ) ? $_GET['filter_result'] : '';
$filter_from = isset( $_GET['filter_from'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_from'] ) ) : '';
$filter_to = isset( $_GET['filter_to'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_to'] ) ) : '';

$query_args = array(
    'user_id' => $filter_user ?: null,
    'result' => $filter_result !== '' ? $filter_result : null,
    'date_from' => $filter_from ?: null,
    'date_to' => $filter_to ?: null,
    'limit' => $per_page,
    'offset' => $offset,
);

$total = WPLLMSEO_Exec_Logger::count_logs_filtered( $query_args );
$logs = WPLLMSEO_Exec_Logger::query_logs( $query_args );

?>
<div class="wrap">
    <h1><?php esc_html_e( 'Exec Guard Logs', 'wpllmseo' ); ?></h1>

    <?php if ( isset( $_GET['cleared'] ) && '1' === $_GET['cleared'] ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Exec logs cleared.', 'wpllmseo' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log entry deleted.', 'wpllmseo' ); ?></p></div>
    <?php endif; ?>

    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wpllmseo-exec-log-filters">
        <input type="hidden" name="page" value="wpllmseo_exec_logs" />
        <label><?php esc_html_e( 'User ID:', 'wpllmseo' ); ?> <input type="number" name="filter_user" value="<?php echo esc_attr( $filter_user ); ?>" class="small-text" /></label>
        <label style="margin-left:8px;"><?php esc_html_e( 'Result:', 'wpllmseo' ); ?> <input type="text" name="filter_result" value="<?php echo esc_attr( $filter_result ); ?>" class="small-text" /></label>
        <label style="margin-left:8px;"><?php esc_html_e( 'From:', 'wpllmseo' ); ?> <input type="date" name="filter_from" value="<?php echo esc_attr( $filter_from ); ?>" /></label>
        <label style="margin-left:8px;"><?php esc_html_e( 'To:', 'wpllmseo' ); ?> <input type="date" name="filter_to" value="<?php echo esc_attr( $filter_to ); ?>" /></label>
        <button class="button" type="submit" style="margin-left:8px;"><?php esc_html_e( 'Filter', 'wpllmseo' ); ?></button>
        &nbsp;
        <a class="button" href="<?php echo esc_url( remove_query_arg( array( 'filter_user', 'filter_result', 'filter_from', 'filter_to' ) ) ); ?>"><?php esc_html_e( 'Clear', 'wpllmseo' ); ?></a>
        &nbsp;
        <?php $export_url = add_query_arg( array( 'action' => 'wpllmseo_export_exec_logs', '_wpnonce' => wp_create_nonce( 'wpllmseo_export_exec_logs' ), 'filter_user' => $filter_user, 'filter_result' => $filter_result, 'filter_from' => $filter_from, 'filter_to' => $filter_to ), admin_url( 'admin-post.php' ) ); ?>
        <a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV (filtered)', 'wpllmseo' ); ?></a>

        <form style="display:inline; margin-left:8px;" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'wpllmseo_clear_exec_logs' ); ?>
            <input type="hidden" name="action" value="wpllmseo_clear_exec_logs" />
            <button class="button button-danger" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to clear all exec logs? This cannot be undone.', 'wpllmseo' ) ); ?>');"><?php esc_html_e( 'Clear Logs', 'wpllmseo' ); ?></button>
        </form>
    </form>

    <table class="widefat fixed">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ID', 'wpllmseo' ); ?></th>
                <th><?php esc_html_e( 'User', 'wpllmseo' ); ?></th>
                <th><?php esc_html_e( 'Command', 'wpllmseo' ); ?></th>
                <th><?php esc_html_e( 'Result', 'wpllmseo' ); ?></th>
                <th><?php esc_html_e( 'When', 'wpllmseo' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log->id ); ?></td>
                    <td><?php echo esc_html( $log->user_id ); ?></td>
                    <td><code><?php
                        $decoded = json_decode( $log->command, true );
                        if ( null !== $decoded ) {
                            echo esc_html( wp_json_encode( $decoded ) );
                        } else {
                            echo esc_html( $log->command );
                        }
                    ?></code>
                    <div class="row-actions">
                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpllmseo_exec_logs', 'id' => $log->id, 'view' => 'detail' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View', 'wpllmseo' ); ?></a>
                        &nbsp;|&nbsp;
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'wpllmseo_delete_exec_log' ); ?>
                                <?php if ( isset( $_GET['wpllmseo_prune_result'] ) ) : ?>
                                    <div class="notice notice-success is-dismissible">
                                        <p><?php printf( esc_html__( 'Prune completed. Deleted %d log entries.', 'wpllmseo' ), intval( $_GET['wpllmseo_prune_result'] ) ); ?></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ( isset( $_GET['wpllmseo_deleted'] ) ) : ?>
                                    <div class="notice notice-success is-dismissible">
                                        <p><?php esc_html_e( 'Log entry deleted.', 'wpllmseo' ); ?></p>
                                    </div>
                                <?php endif; ?>
                            <input type="hidden" name="action" value="wpllmseo_delete_exec_log" />
                            <input type="hidden" name="log_id" value="<?php echo esc_attr( $log->id ); ?>" />
                            <button class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Delete this log entry?', 'wpllmseo' ) ); ?>');" style="background:none;border:0;padding:0;color:#a00;"><?php esc_html_e( 'Delete', 'wpllmseo' ); ?></button>
                        </form>
                    </div>
                    </td>
                    <td><?php echo esc_html( $log->result ); ?></td>
                    <td><?php echo esc_html( $log->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $base = add_query_arg( array( 'page' => 'wpllmseo_exec_logs' ), admin_url( 'admin.php' ) );
    $total_pages = (int) ceil( $total / $per_page );
    if ( $total_pages > 1 ) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        for ( $i = 1; $i <= $total_pages; $i++ ) {
            $url = add_query_arg( 'paged', $i, $base );
            echo '<a href="' . esc_url( $url ) . '" class="page-numbers">' . esc_html( $i ) . '</a> ';
        }
        echo '</div></div>';
    }
    ?>
</div>
