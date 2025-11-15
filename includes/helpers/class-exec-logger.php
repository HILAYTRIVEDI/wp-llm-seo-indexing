<?php
/**
 * Exec Guard Logger
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_Exec_Logger {

    public static function log( $command, $stdout = '', $stderr = '', $result = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'wpllmseo_exec_logs';
        $user_id = get_current_user_id() ?: null;

        $data = array(
            'user_id' => $user_id,
            'command' => $command,
            'stdout' => wp_json_encode( $stdout ),
            'stderr' => wp_json_encode( $stderr ),
            'result' => intval( $result ),
            'created_at' => current_time( 'mysql' ),
        );

        $format = array( '%d', '%s', '%s', '%s', '%d', '%s' );
        $wpdb->insert( $table, $data, $format );
    }

    /**
     * Retrieve exec logs.
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function get_logs( $limit = 50, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpllmseo_exec_logs';
        $sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset );
        return $wpdb->get_results( $sql );
    }

    /**
     * Get logs with optional filters.
     *
     * @param array $args [ 'user_id', 'result', 'date_from', 'date_to', 'limit', 'offset' ]
     * @return array
     */
    public static function query_logs( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpllmseo_exec_logs';

        $where = array();
        $params = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 'user_id = %d';
            $params[] = intval( $args['user_id'] );
        }
        if ( isset( $args['result'] ) && $args['result'] !== '' ) {
            $where[] = 'result = %d';
            $params[] = intval( $args['result'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'created_at >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'created_at <= %s';
            $params[] = $args['date_to'];
        }

        $where_sql = '';
        if ( ! empty( $where ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where );
        }

        $limit = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
        $offset = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        // Prepare with dynamic params
        $prepared = $wpdb->prepare( $sql, $params );
        return $wpdb->get_results( $prepared );
    }

    /**
     * Count logs with optional filters.
     *
     * @param array $args
     * @return int
     */
    public static function count_logs_filtered( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpllmseo_exec_logs';

        $where = array();
        $params = array();

        if ( ! empty( $args['user_id'] ) ) {
            $where[] = 'user_id = %d';
            $params[] = intval( $args['user_id'] );
        }
        if ( isset( $args['result'] ) && $args['result'] !== '' ) {
            $where[] = 'result = %d';
            $params[] = intval( $args['result'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'created_at >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'created_at <= %s';
            $params[] = $args['date_to'];
        }

        $where_sql = '';
        if ( ! empty( $where ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where );
        }

        $sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        if ( ! empty( $params ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        }

        return (int) $wpdb->get_var( $sql );
    }

    /**
     * Delete a single log entry by id.
     *
     * @param int $id
     * @return bool
     */
    public static function delete_log( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpllmseo_exec_logs';
        $deleted = $wpdb->delete( $table, array( 'id' => intval( $id ) ), array( '%d' ) );
        return ( $deleted !== false );
    }

    /**
     * Prune logs older than N days.
     *
     * @param int $days
     * @return int Number of rows deleted
     */
    public static function prune_older_than_days( $days ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpllmseo_exec_logs';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $res = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
        return (int) $res;
    }

    /**
     * Count total exec logs.
     *
     * @return int
     */
    public static function count_logs() {
        global $wpdb;
        $table = $wpdb->prefix . 'wpllmseo_exec_logs';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }
}
