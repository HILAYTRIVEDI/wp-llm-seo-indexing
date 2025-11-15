<?php
/**
 * Exec Guard
 *
 * Small helper to centralize and safety-check shell execution.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_Exec_Guard {

    /**
     * Check whether exec() is available in this environment.
     *
     * @return bool
     */
    public static function is_available() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled = ini_get( 'disable_functions' );
        if ( $disabled ) {
            $disabled = array_map( 'trim', explode( ',', $disabled ) );
            if ( in_array( 'exec', $disabled, true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run a command after basic safety checks.
     * Only allow a small whitelist of binaries to avoid arbitrary execution.
     *
     * @param string $cmd Command to run (properly escaped by caller).
     * @param array  $output Output lines (by reference).
     * @param int    $return_var Return status (by reference).
     * @return true|WP_Error
     */
    public static function run( $cmd, &$output = null, &$return_var = null ) {
        if ( ! self::is_available() ) {
            return new WP_Error( 'exec_disabled', 'Shell execution is disabled on this host.' );
        }

        // Very small whitelist: allow only pdftotext for now.
        $allowed = array( 'pdftotext' );

        // Extract binary name from command.
        $parts  = preg_split( '/\s+/', trim( $cmd ) );
        $binary = basename( $parts[0] );

        if ( ! in_array( $binary, $allowed, true ) ) {
            return new WP_Error( 'exec_not_allowed', 'This command is not permitted.' );
        }

        // Run command suppressed to avoid warnings leaking to output.
        @exec( $cmd, $output, $return_var );

        // If logging enabled, record attempt.
        $settings = get_option( 'wpllmseo_settings', array() );
        if ( ! empty( $settings['exec_guard_enabled'] ) ) {
            // Ensure logger exists.
            if ( file_exists( __DIR__ . '/class-exec-logger.php' ) ) {
                require_once __DIR__ . '/class-exec-logger.php';
                WPLLMSEO_Exec_Logger::log( $cmd, $output, '', $return_var );
            }
        }

        if ( is_int( $return_var ) && 0 !== $return_var ) {
            return new WP_Error( 'exec_failed', 'Command returned non-zero exit code.', array( 'code' => $return_var ) );
        }

        return true;
    }
}
