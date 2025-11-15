<?php
/**
 * Embedding Cache helper
 *
 * Provides a small persistent cache for embeddings using transients.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPLLMSEO_Embedding_Cache {

    /** Default TTL for cache entries (30 days) */
    const DEFAULT_TTL = 2592000;

    /**
     * Generate a cache key for a given input text, model and provider.
     *
     * @param string $text
     * @param string $model
     * @param string $provider
     * @return string
     */
    public static function make_key( $text, $model, $provider ) {
        $hash = hash( 'sha256', $provider . '|' . $model . '|' . $text );
        return 'wpllmseo_embedding_' . $hash;
    }

    /**
     * Get embedding from cache.
     *
     * @param string $text
     * @param string $model
     * @param string $provider
     * @return array|false Embedding array or false when not present.
     */
    public static function get( $text, $model, $provider ) {
        $key = self::make_key( $text, $model, $provider );
        $val = get_transient( $key );
        return $val === false ? false : $val;
    }

    /**
     * Set embedding in cache.
     *
     * @param string $text
     * @param string $model
     * @param string $provider
     * @param array  $embedding
     * @param int    $ttl Seconds to live (optional).
     * @return bool
     */
    public static function set( $text, $model, $provider, $embedding, $ttl = null ) {
        if ( null === $ttl ) {
            $ttl = self::DEFAULT_TTL;
        }
        $key = self::make_key( $text, $model, $provider );
        return set_transient( $key, $embedding, (int) $ttl );
    }

    /**
     * Delete cache entry.
     *
     * @param string $text
     * @param string $model
     * @param string $provider
     * @return bool
     */
    public static function delete( $text, $model, $provider ) {
        $key = self::make_key( $text, $model, $provider );
        return delete_transient( $key );
    }
}
