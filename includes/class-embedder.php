<?php
/**
 * Embedder
 *
 * Provides embedding helpers, batching and cache integration.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/helpers/class-embedding-cache.php';

class WPLLMSEO_Embedder {

    /** Default provider feature name for embeddings */
    const FEATURE = 'embedding';

    /**
     * Embed a single chunk (wrapper)
     *
     * @param array $chunk Chunk array containing 'content' and metadata.
     * @return true|WP_Error
     */
    public function embed_chunk( $chunk ) {
        $chunks = array( $chunk );
        $res = $this->embed_chunks_batch( $chunks );
        if ( is_wp_error( $res ) ) {
            return $res;
        }

        return isset( $res[0] ) && $res[0] === true ? true : new WP_Error( 'embed_failed', 'Embedding did not succeed' );
    }

    /**
     * Embed multiple chunks in a batch.
     * Returns an array of results aligned with input order (true|WP_Error).
     *
     * @param array $chunks Array of chunk arrays (each contains 'content' and optional meta).
     * @return array|WP_Error
     */
    public function embed_chunks_batch( $chunks ) {
        if ( empty( $chunks ) || ! is_array( $chunks ) ) {
            return new WP_Error( 'invalid_input', 'Chunks must be a non-empty array' );
        }

        // Resolve active provider and model.
        $provider_id = WPLLMSEO_Provider_Manager::get_active_provider( self::FEATURE );
        $model_id    = WPLLMSEO_Provider_Manager::get_active_model( self::FEATURE );

        wpllmseo_log( sprintf( 'Embedder resolving provider/model: provider=%s model=%s', $provider_id, $model_id ), 'debug' );

        if ( empty( $provider_id ) || empty( $model_id ) ) {
            return new WP_Error( 'no_provider', 'No embedding provider/model configured' );
        }

        $provider = WPLLMSEO_Provider_Manager::get_provider( $provider_id );
        $config   = WPLLMSEO_Provider_Manager::get_provider_config( $provider_id );

        if ( ! $provider ) {
            return new WP_Error( 'invalid_provider', 'Active provider not available' );
        }

        $api_key = $config['api_key'] ?? '';
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', 'Provider API key not configured' );
        }

        $results = array();

        // Attempt to reuse cache per-item.
        foreach ( $chunks as $i => $chunk ) {
            $text = is_array( $chunk ) ? ( $chunk['content'] ?? '' ) : (string) $chunk;

            $cached = WPLLMSEO_Embedding_Cache::get( $text, $model_id, $provider_id );
            if ( $cached !== false ) {
                $results[ $i ] = array( 'success' => true, 'embedding' => $cached );
                continue;
            }

            // Fall back to provider call per-item.
            wpllmseo_log( sprintf( 'Calling provider.generate_embedding for provider=%s model=%s', $provider_id, $model_id ), 'debug', array( 'text_preview' => substr( $text, 0, 120 ) ) );
            $res = $provider->generate_embedding( $text, $model_id, $api_key, $config );
            if ( is_wp_error( $res ) ) {
                $results[ $i ] = $res;
                continue;
            }

            $embedding = $res['embedding'];

            // Cache result.
            WPLLMSEO_Embedding_Cache::set( $text, $model_id, $provider_id, $embedding );

            $results[ $i ] = array( 'success' => true, 'embedding' => $embedding );
        }

        return $results;
    }
}
