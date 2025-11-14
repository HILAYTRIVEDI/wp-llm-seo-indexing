<?php
/**
 * LLM-Optimized JSON-LD Output
 *
 * Generates structured data optimized for LLM crawlers.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_LLM_JSONLD
 *
 * Outputs LLM-specific JSON-LD structured data.
 */
class WPLLMSEO_LLM_JSONLD {

	/**
	 * Initialize JSON-LD output.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_jsonld' ), 99 );
	}

	/**
	 * Output JSON-LD structured data.
	 */
	public static function output_jsonld() {
		$settings = get_option( 'wpllmseo_settings', array() );
		
		if ( empty( $settings['enable_llm_jsonld'] ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		$data    = self::generate_jsonld( $post_id );

		if ( empty( $data ) ) {
			return;
		}

		echo '<script type="application/ld+json" data-llmseo="true">' . "\n";
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
		echo '</script>' . "\n";
	}

	/**
	 * Generate JSON-LD data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array JSON-LD data structure.
	 */
	public static function generate_jsonld( $post_id ) {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		// Get SEO meta from external plugin if available.
		$seo_meta = WPLLMSEO_SEO_Compat::get_primary_seo_meta( $post_id );

		// Get embedding info.
		$embedding_hash = get_post_meta( $post_id, '_wpllmseo_embedding_hash', true );
		$last_indexed   = get_post_meta( $post_id, '_wpllmseo_last_indexed', true );

		// Get AI snippet.
		$snippet_table = $wpdb->prefix . 'llmseo_snippets';
		$snippet = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT title, content FROM {$snippet_table} WHERE post_id = %d ORDER BY created_at DESC LIMIT 1",
				$post_id
			)
		);

		// Get semantic keywords from chunks.
		$chunks_table = $wpdb->prefix . 'llmseo_chunks';
		$chunk_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$chunks_table} WHERE post_id = %d",
				$post_id
			)
		);

		// Build LLM-optimized JSON-LD.
		$data = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'@id'              => get_permalink( $post_id ),
			'headline'         => ! empty( $seo_meta['title'] ) ? $seo_meta['title'] : get_the_title( $post_id ),
			'description'      => $seo_meta['meta_description'] ?? '',
			'url'              => get_permalink( $post_id ),
			'datePublished'    => get_the_date( 'c', $post_id ),
			'dateModified'     => get_the_modified_date( 'c', $post_id ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url(),
			),
			// LLM-specific extensions.
			'llmOptimization'  => array(
				'@type'               => 'LLMMetadata',
				'aiSummary'           => $snippet ? wp_trim_words( $snippet->content, 50 ) : '',
				'semanticVectorHash'  => $embedding_hash ? substr( $embedding_hash, 0, 16 ) : null,
				'embeddingVersion'    => 'v1.0',
				'lastIndexed'         => $last_indexed ? gmdate( 'c', $last_indexed ) : null,
				'chunkCount'          => (int) $chunk_count,
				'seoMetaSource'       => $seo_meta['source'] ?? 'fallback',
				'contentFingerprint'  => get_post_meta( $post_id, '_wpllmseo_content_hash', true ) ?: null,
			),
		);

		// Add canonical if available.
		if ( ! empty( $seo_meta['canonical'] ) ) {
			$data['mainEntityOfPage'] = array(
				'@type' => 'WebPage',
				'@id'   => $seo_meta['canonical'],
			);
		}

		// Add featured image if available.
		if ( has_post_thumbnail( $post_id ) ) {
			$image_id  = get_post_thumbnail_id( $post_id );
			$image_url = wp_get_attachment_image_url( $image_id, 'full' );
			
			if ( $image_url ) {
				$data['image'] = array(
					'@type' => 'ImageObject',
					'url'   => $image_url,
				);
			}
		}

		// Filter to allow customization.
		return apply_filters( 'wpllmseo_jsonld_data', $data, $post_id );
	}

	/**
	 * Get JSON-LD data for API responses.
	 *
	 * @param int $post_id Post ID.
	 * @return array JSON-LD data.
	 */
	public static function get_jsonld_for_api( $post_id ) {
		return self::generate_jsonld( $post_id );
	}
}
