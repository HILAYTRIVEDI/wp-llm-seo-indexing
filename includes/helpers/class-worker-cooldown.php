<?php
/**
 * Worker Cooldown helper
 *
 * Manage cooldown timestamps for long-running worker processes.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_Worker_Cooldown {

    const TRANSIENT_KEY = 'wpllmseo_worker_last_run_';

    /** Default cooldown seconds (24 hours) */
    const DEFAULT_COOLDOWN = 86400;

    /**
     * Check whether operation is allowed (not in cooldown).
     *
     * @param string $operation
     * @param int|null $cooldown_seconds
     * @return bool True when allowed (not cooling down)
     */
    public static function is_allowed( $operation, $cooldown_seconds = null ) {
        if ( null === $cooldown_seconds ) {
            $cooldown_seconds = self::DEFAULT_COOLDOWN;
        }

        $key = self::TRANSIENT_KEY . $operation;
        $last = get_option( $key );
        if ( false === $last || empty( $last ) ) {
            return true;
        }

        $last_time = intval( $last );
        return ( time() - $last_time ) >= intval( $cooldown_seconds );
    }

    /**
     * Record the last run time for an operation.
     *
     * @param string $operation
     * @return bool
     */
    public static function record_run( $operation ) {
        $key = self::TRANSIENT_KEY . $operation;
        return update_option( $key, time() );
    }

    /**
     * Clear the cooldown timer for operation.
     *
     * @param string $operation
     * @return bool
     */
    public static function clear( $operation ) {
        $key = self::TRANSIENT_KEY . $operation;
        return delete_option( $key );
    }
}
