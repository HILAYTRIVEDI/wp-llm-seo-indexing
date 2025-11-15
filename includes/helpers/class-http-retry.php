<?php
/**
 * HTTP Retry Helper
 *
 * Centralized retry/backoff wrapper around `wp_remote_request`.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_HTTP_Retry {

    /**
     * Make an HTTP request with retries and exponential backoff.
     *
     * @param string $url
     * @param array  $args
     * @param int    $retries
     * @return array|WP_Error
     */
    public static function request( $url, $args = array(), $retries = 3, $options = array() ) {
        $defaults = array(
            'max_retries'      => (int) $retries,
            'initial_delay'    => 0.5, // seconds
            'max_delay'        => 60,  // seconds
            'parse_json_error' => true,
            'logger'           => null, // callable( $message, $context )
        );

        $opts = wp_parse_args( $options, $defaults );
        $attempt = 0;

        while ( $attempt < $opts['max_retries'] ) {
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                $attempt++;
                if ( is_callable( $opts['logger'] ) ) {
                    call_user_func( $opts['logger'], 'http_request_wp_error', array( 'error' => $response ) );
                }

                if ( $attempt < $opts['max_retries'] ) {
                    $delay = min( $opts['initial_delay'] * pow( 2, $attempt ), $opts['max_delay'] );
                    $delay += rand( 0, 500 ) / 1000; // jitter up to 0.5s
                    usleep( (int) ( $delay * 1000000 ) );
                    continue;
                }

                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $parsed = null;
            if ( $opts['parse_json_error'] && ! empty( $body ) ) {
                $parsed = json_decode( $body, true );
            }

            // If provider reports an error object inside body, treat accordingly.
            if ( is_array( $parsed ) && isset( $parsed['error'] ) ) {
                $err_msg = is_string( $parsed['error'] ) ? $parsed['error'] : ( isset( $parsed['error']['message'] ) ? $parsed['error']['message'] : json_encode( $parsed['error'] ) );

                // Some providers surface rate-limit info inside the body.
                if ( $code === 429 || ( false !== stripos( $err_msg, 'rate' ) && $attempt < $opts['max_retries'] ) ) {
                    $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
                    if ( is_numeric( $retry_after ) ) {
                        $sleep = (int) $retry_after;
                    } else {
                        $sleep = min( $opts['initial_delay'] * pow( 2, $attempt ), $opts['max_delay'] );
                    }
                    $sleep += rand( 0, 500 ) / 1000;
                    usleep( (int) ( $sleep * 1000000 ) );
                    $attempt++;
                    continue;
                }

                return new WP_Error( 'provider_error', $err_msg, array( 'code' => $code, 'body' => $parsed ) );
            }

            // Honor explicit 429 with Retry-After header when present.
            if ( 429 === $code ) {
                $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
                if ( is_numeric( $retry_after ) ) {
                    $sleep = (int) $retry_after;
                } else {
                    $sleep = min( $opts['initial_delay'] * pow( 2, $attempt ), $opts['max_delay'] );
                }
                $sleep += rand( 0, 500 ) / 1000;
                if ( is_callable( $opts['logger'] ) ) {
                    call_user_func( $opts['logger'], 'http_rate_limited', compact( 'url', 'code', 'retry_after' ) );
                }

                $attempt++;
                if ( $attempt < $opts['max_retries'] ) {
                    usleep( (int) ( $sleep * 1000000 ) );
                    continue;
                }

                return new WP_Error( 'rate_limit', __( 'Rate limit exceeded', 'wpllmseo' ), array( 'code' => $code ) );
            }

            // Server errors -> retry.
            if ( $code >= 500 && $code < 600 ) {
                $attempt++;
                if ( is_callable( $opts['logger'] ) ) {
                    call_user_func( $opts['logger'], 'http_server_error', compact( 'url', 'code' ) );
                }

                if ( $attempt < $opts['max_retries'] ) {
                    $delay = min( $opts['initial_delay'] * pow( 2, $attempt ), $opts['max_delay'] );
                    $delay += rand( 0, 500 ) / 1000;
                    usleep( (int) ( $delay * 1000000 ) );
                    continue;
                }

                return new WP_Error( 'http_error', sprintf( __( 'HTTP error: %d', 'wpllmseo' ), $code ), array( 'code' => $code ) );
            }

            // 2xx -> success
            if ( $code >= 200 && $code < 300 ) {
                // Sanity check: some providers include top-level error with 200.
                if ( is_array( $parsed ) && isset( $parsed['error'] ) ) {
                    return new WP_Error( 'provider_error', isset( $parsed['error']['message'] ) ? $parsed['error']['message'] : json_encode( $parsed['error'] ), array( 'body' => $parsed ) );
                }

                return $response;
            }

            // 4xx -> client errors (non-retryable by default)
            if ( $code >= 400 && $code < 500 ) {
                $message = $body;
                if ( is_array( $parsed ) ) {
                    if ( isset( $parsed['message'] ) ) {
                        $message = $parsed['message'];
                    } elseif ( isset( $parsed['error'] ) ) {
                        $message = is_string( $parsed['error'] ) ? $parsed['error'] : json_encode( $parsed['error'] );
                    }
                }

                return new WP_Error( 'http_client_error', $message, array( 'code' => $code ) );
            }

            // Fallback: return response
            return $response;
        }

        return new WP_Error( 'max_retries', __( 'Maximum retries exceeded', 'wpllmseo' ) );
    }
}
