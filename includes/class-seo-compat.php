<?php
/**
 * SEO Plugin Compatibility Layer
 *
 * Detects and integrates with Yoast SEO and Rank Math to avoid conflicts.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_SEO_Compat
 *
 * Manages compatibility with external SEO plugins.
 */
class WPLLMSEO_SEO_Compat {

	/**
	 * Detected SEO plugin.
	 *
	 * @var string|null
	 */
	private static $active_plugin = null;

	/**
	 * Initialize compatibility layer.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'maybe_remove_duplicate_meta' ), 1 );
		add_filter( 'wpllmseo_should_output_meta', array( __CLASS__, 'check_meta_output_permission' ), 10, 2 );
	}

	/**
	 * Detect active SEO plugin.
	 *
	 * @return string|null 'yoast', 'rankmath', or null.
	 */
	public static function detect_seo_plugin() {
		if ( null !== self::$active_plugin ) {
			return self::$active_plugin;
		}

		// Check for Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			self::$active_plugin = 'yoast';
			return 'yoast';
		}

		// Check for Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			self::$active_plugin = 'rankmath';
			return 'rankmath';
		}

		self::$active_plugin = 'none';
		return null;
	}

	/**
	 * Get primary SEO metadata from active plugin.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array with title, meta_description, canonical, robots, schema.
	 */
	public static function get_primary_seo_meta( $post_id ) {
		$plugin = self::detect_seo_plugin();
		
		$meta = array(
			'title'            => '',
			'meta_description' => '',
			'canonical'        => '',
			'robots'           => '',
			'schema'           => array(),
			'source'           => $plugin ?? 'none',
		);

		switch ( $plugin ) {
			case 'yoast':
				$meta = self::get_yoast_meta( $post_id );
				break;

			case 'rankmath':
				$meta = self::get_rankmath_meta( $post_id );
				break;

			default:
				$meta = self::get_fallback_meta( $post_id );
				break;
		}

		return $meta;
	}

	/**
	 * Get Yoast SEO metadata.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO metadata.
	 */
	private static function get_yoast_meta( $post_id ) {
		$meta = array(
			'title'            => '',
			'meta_description' => '',
			'canonical'        => '',
			'robots'           => '',
			'schema'           => array(),
			'source'           => 'yoast',
		);

		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			return $meta;
		}

		// Get Yoast meta values.
		$meta['title']            = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		$meta['meta_description'] = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		$meta['canonical']        = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		
		// Get robots meta.
		$noindex  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$nofollow = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
		
		$robots_parts = array();
		if ( '1' === $noindex ) {
			$robots_parts[] = 'noindex';
		}
		if ( '1' === $nofollow ) {
			$robots_parts[] = 'nofollow';
		}
		$meta['robots'] = implode( ', ', $robots_parts );

		// If title is empty, use Yoast's replacement variables.
		if ( empty( $meta['title'] ) && function_exists( 'wpseo_replace_vars' ) ) {
			$meta['title'] = wpseo_replace_vars( '%%title%% %%sep%% %%sitename%%', get_post( $post_id ) );
		}

		// If description is empty, use excerpt or auto-generate.
		if ( empty( $meta['meta_description'] ) ) {
			$post = get_post( $post_id );
			$meta['meta_description'] = ! empty( $post->post_excerpt ) 
				? wp_trim_words( $post->post_excerpt, 30 ) 
				: wp_trim_words( strip_shortcodes( $post->post_content ), 30 );
		}

		return $meta;
	}

	/**
	 * Get Rank Math metadata.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO metadata.
	 */
	private static function get_rankmath_meta( $post_id ) {
		$meta = array(
			'title'            => '',
			'meta_description' => '',
			'canonical'        => '',
			'robots'           => '',
			'schema'           => array(),
			'source'           => 'rankmath',
		);

		if ( ! class_exists( 'RankMath' ) ) {
			return $meta;
		}

		// Get Rank Math meta values.
		$meta['title']            = get_post_meta( $post_id, 'rank_math_title', true );
		$meta['meta_description'] = get_post_meta( $post_id, 'rank_math_description', true );
		$meta['canonical']        = get_post_meta( $post_id, 'rank_math_canonical_url', true );
		
		// Get robots meta.
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $robots ) ) {
			$meta['robots'] = implode( ', ', $robots );
		}

		// If title is empty, use Rank Math's helper.
		if ( empty( $meta['title'] ) && class_exists( 'RankMath\Helper' ) ) {
			$meta['title'] = \RankMath\Helper::get_post_meta( 'title', $post_id );
		}

		// If description is empty, use excerpt or auto-generate.
		if ( empty( $meta['meta_description'] ) ) {
			$post = get_post( $post_id );
			$meta['meta_description'] = ! empty( $post->post_excerpt ) 
				? wp_trim_words( $post->post_excerpt, 30 ) 
				: wp_trim_words( strip_shortcodes( $post->post_content ), 30 );
		}

		return $meta;
	}

	/**
	 * Get fallback metadata when no SEO plugin active.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO metadata.
	 */
	private static function get_fallback_meta( $post_id ) {
		$post = get_post( $post_id );
		
		if ( ! $post ) {
			return array(
				'title'            => '',
				'meta_description' => '',
				'canonical'        => '',
				'robots'           => '',
				'schema'           => array(),
				'source'           => 'fallback',
			);
		}

		$meta = array(
			'title'            => get_the_title( $post_id ) . ' - ' . get_bloginfo( 'name' ),
			'meta_description' => ! empty( $post->post_excerpt ) 
				? wp_trim_words( $post->post_excerpt, 30 ) 
				: wp_trim_words( strip_shortcodes( $post->post_content ), 30 ),
			'canonical'        => get_permalink( $post_id ),
			'robots'           => '',
			'schema'           => array(),
			'source'           => 'fallback',
		);

		return $meta;
	}

	/**
	 * Check if plugin should output meta tags.
	 *
	 * @param bool   $should_output Default permission.
	 * @param string $meta_type     Type of meta (title, description, etc.).
	 * @return bool
	 */
	public static function check_meta_output_permission( $should_output, $meta_type ) {
		$settings = get_option( 'wpllmseo_settings', array() );
		$prefer_external = $settings['prefer_external_seo'] ?? true;

		// If preference is to use external SEO plugin, don't output if one is detected.
		if ( $prefer_external && self::detect_seo_plugin() ) {
			return false;
		}

		return $should_output;
	}

	/**
	 * Remove duplicate meta tags when external SEO plugin is active.
	 */
	public static function maybe_remove_duplicate_meta() {
		$settings = get_option( 'wpllmseo_settings', array() );
		$prefer_external = $settings['prefer_external_seo'] ?? true;

		if ( ! $prefer_external ) {
			return;
		}

		$plugin = self::detect_seo_plugin();
		
		if ( ! $plugin ) {
			return;
		}

		// Remove our meta output hooks if external SEO plugin is preferred.
		remove_action( 'wp_head', 'wpllmseo_output_meta_tags', 1 );
		remove_filter( 'wp_title', 'wpllmseo_filter_title' );
	}

	/**
	 * Get compatibility status for admin display.
	 *
	 * @return array Status information.
	 */
	public static function get_compat_status() {
		$plugin   = self::detect_seo_plugin();
		$settings = get_option( 'wpllmseo_settings', array() );
		$prefer_external = $settings['prefer_external_seo'] ?? true;

		$status = array(
			'active_plugin'    => $plugin ?? 'none',
			'prefer_external'  => $prefer_external,
			'meta_output'      => ! $plugin || ! $prefer_external,
			'json_ld_output'   => true, // LLM JSON-LD is always separate.
		);

		switch ( $plugin ) {
			case 'yoast':
				$status['message'] = __( 'Yoast SEO detected. Using Yoast metadata for LLM features.', 'wpllmseo' );
				$status['level']   = 'info';
				break;

			case 'rankmath':
				$status['message'] = __( 'Rank Math detected. Using Rank Math metadata for LLM features.', 'wpllmseo' );
				$status['level']   = 'info';
				break;

			default:
				$status['message'] = __( 'No external SEO plugin detected. Using built-in meta generation.', 'wpllmseo' );
				$status['level']   = 'success';
				break;
		}

		return $status;
	}
}
