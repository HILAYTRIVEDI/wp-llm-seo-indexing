<?php
/**
 * LLM Sitemap Hub
 *
 * Extended sitemap features with multiple LLM-optimized endpoints.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_Sitemap_Hub
 *
 * Manages multiple LLM sitemap endpoints.
 */
class WPLLMSEO_Sitemap_Hub {

	/**
	 * Cache directory.
	 */
	const CACHE_DIR = 'var/cache/';

	/**
	 * Initialize sitemap hub.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_sitemap_request' ) );
	}

	/**
	 * Add rewrite rules for sitemap endpoints.
	 */
	public static function add_rewrite_rules() {
		$endpoints = array(
			'llm-sitemap.jsonl',
			'llm-snippets.jsonl',
			'llm-semantic-map.json',
			'llm-context-graph.jsonl',
		);

		foreach ( $endpoints as $endpoint ) {
			add_rewrite_rule(
				'^' . $endpoint . '$',
				'index.php?wpllmseo_sitemap=' . $endpoint,
				'top'
			);
		}

		add_rewrite_tag( '%wpllmseo_sitemap%', '([^&]+)' );
	}

	/**
	 * Handle sitemap requests.
	 */
	public static function handle_sitemap_request() {
		$sitemap = get_query_var( 'wpllmseo_sitemap' );

		if ( ! $sitemap ) {
			return;
		}

		// Check if sitemaps are enabled.
		$settings = get_option( 'wpllmseo_settings', array() );
		if ( empty( $settings['enable_ai_sitemap'] ) ) {
			wp_die( 'LLM Sitemaps are disabled', 'Disabled', array( 'response' => 403 ) );
		}

		// Check access permissions if required.
		$access_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( ! self::verify_access( $access_token ) ) {
			self::log_access_attempt( $sitemap, false );
			wp_die( 'Unauthorized', 'Unauthorized', array( 'response' => 401 ) );
		}

		self::log_access_attempt( $sitemap, true );

		// Route to appropriate handler.
		switch ( $sitemap ) {
			case 'llm-sitemap.jsonl':
				self::serve_llm_sitemap();
				break;

			case 'llm-snippets.jsonl':
				self::serve_snippets_sitemap();
				break;

			case 'llm-semantic-map.json':
				self::serve_semantic_map();
				break;

			case 'llm-context-graph.jsonl':
				self::serve_context_graph();
				break;

			default:
				wp_die( 'Unknown sitemap', 'Not Found', array( 'response' => 404 ) );
		}

		exit;
	}

	/**
	 * Serve main LLM sitemap.
	 */
	private static function serve_llm_sitemap() {
		$page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$per_page = 100;

		$cache_key = 'llm_sitemap_' . $page;
		$cached    = self::get_cached( $cache_key );

		if ( $cached ) {
			self::output_jsonl( $cached );
			return;
		}

		$offset = ( $page - 1 ) * $per_page;

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'offset'         => $offset,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$lines = array();

		foreach ( $posts as $post ) {
			$seo_meta = WPLLMSEO_SEO_Compat::get_primary_seo_meta( $post->ID );

			$lines[] = array(
				'url'         => get_permalink( $post->ID ),
				'title'       => $seo_meta['title'] ?: get_the_title( $post->ID ),
				'description' => $seo_meta['meta_description'] ?: wp_trim_words( $post->post_content, 30 ),
				'lastmod'     => get_the_modified_date( 'c', $post->ID ),
				'type'        => $post->post_type,
				'seoSource'   => $seo_meta['source'],
			);
		}

		self::cache( $cache_key, $lines, 1800 ); // 30 minutes
		self::output_jsonl( $lines );
	}

	/**
	 * Serve snippets sitemap.
	 */
	private static function serve_snippets_sitemap() {
		global $wpdb;

		$page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
		$per_page = 100;

		$cache_key = 'snippets_sitemap_' . $page;
		$cached    = self::get_cached( $cache_key );

		if ( $cached ) {
			self::output_jsonl( $cached );
			return;
		}

		$offset = ( $page - 1 ) * $per_page;
		$table  = $wpdb->prefix . 'llmseo_snippets';

		$snippets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_modified 
				FROM {$table} s 
				LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID 
				WHERE p.post_status = 'publish'
				ORDER BY s.created_at DESC 
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$lines = array();

		foreach ( $snippets as $snippet ) {
			$lines[] = array(
				'url'       => get_permalink( $snippet->post_id ),
				'title'     => $snippet->title,
				'snippet'   => wp_trim_words( $snippet->content, 50 ),
				'postTitle' => $snippet->post_title,
				'created'   => gmdate( 'c', strtotime( $snippet->created_at ) ),
			);
		}

		self::cache( $cache_key, $lines, 1800 );
		self::output_jsonl( $lines );
	}

	/**
	 * Serve semantic map (relationships between posts).
	 */
	private static function serve_semantic_map() {
		$cache_key = 'semantic_map';
		$cached    = self::get_cached( $cache_key );

		if ( $cached ) {
			self::output_json( $cached );
			return;
		}

		// Get posts with embeddings.
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_key'       => '_wpllmseo_embedding_hash',
				'meta_compare'   => 'EXISTS',
			)
		);

		$nodes = array();
		$edges = array();

		foreach ( $posts as $post ) {
			$nodes[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post->ID ),
				'url'   => get_permalink( $post->ID ),
				'type'  => $post->post_type,
			);

			// Get related posts (simplified - would use actual semantic similarity).
			$related = get_post_meta( $post->ID, '_wpllmseo_related_posts', true );
			if ( is_array( $related ) ) {
				foreach ( $related as $related_id => $score ) {
					$edges[] = array(
						'source' => $post->ID,
						'target' => $related_id,
						'weight' => $score,
					);
				}
			}
		}

		$map = array(
			'nodes' => $nodes,
			'edges' => $edges,
			'meta'  => array(
				'generated' => gmdate( 'c' ),
				'nodeCount' => count( $nodes ),
				'edgeCount' => count( $edges ),
			),
		);

		self::cache( $cache_key, $map, 3600 ); // 1 hour
		self::output_json( $map );
	}

	/**
	 * Serve context graph (categories, tags, relationships).
	 */
	private static function serve_context_graph() {
		$cache_key = 'context_graph';
		$cached    = self::get_cached( $cache_key );

		if ( $cached ) {
			self::output_jsonl( $cached );
			return;
		}

		$lines = array();

		// Get categories.
		$categories = get_categories( array( 'hide_empty' => true ) );
		foreach ( $categories as $category ) {
			$lines[] = array(
				'type'        => 'category',
				'id'          => $category->term_id,
				'name'        => $category->name,
				'slug'        => $category->slug,
				'description' => $category->description,
				'count'       => $category->count,
				'url'         => get_category_link( $category->term_id ),
			);
		}

		// Get tags.
		$tags = get_tags( array( 'hide_empty' => true, 'number' => 50 ) );
		foreach ( $tags as $tag ) {
			$lines[] = array(
				'type'  => 'tag',
				'id'    => $tag->term_id,
				'name'  => $tag->name,
				'slug'  => $tag->slug,
				'count' => $tag->count,
				'url'   => get_tag_link( $tag->term_id ),
			);
		}

		self::cache( $cache_key, $lines, 3600 );
		self::output_jsonl( $lines );
	}

	/**
	 * Verify access token or permissions.
	 *
	 * @param string $token Access token.
	 * @return bool
	 */
	private static function verify_access( $token ) {
		$settings = get_option( 'wpllmseo_settings', array() );

		// Check if sitemap hub is enabled (defaults to true for backward compatibility)
		$hub_enabled = isset( $settings['sitemap_hub_public'] ) ? $settings['sitemap_hub_public'] : true;

		// Public access if enabled (default: true)
		if ( $hub_enabled ) {
			return true;
		}

		// Token-based access.
		$required_token = isset( $settings['sitemap_hub_token'] ) ? $settings['sitemap_hub_token'] : '';
		if ( ! empty( $token ) && ! empty( $required_token ) ) {
			return hash_equals( $required_token, $token );
		}

		return false;
	}

	/**
	 * Log access attempts for analytics.
	 *
	 * @param string $endpoint Endpoint accessed.
	 * @param bool   $success  Whether access was granted.
	 */
	private static function log_access_attempt( $endpoint, $success ) {
		$log_entry = array(
			'endpoint'   => $endpoint,
			'success'    => $success,
			'ip'         => self::get_client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'timestamp'  => current_time( 'mysql' ),
		);

		$logs = get_option( 'wpllmseo_sitemap_access_logs', array() );
		$logs[] = $log_entry;

		// Keep only last 1000 entries.
		if ( count( $logs ) > 1000 ) {
			$logs = array_slice( $logs, -1000 );
		}

		update_option( 'wpllmseo_sitemap_access_logs', $logs );
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$ip = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return $ip;
	}

	/**
	 * Get cached data.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	private static function get_cached( $key ) {
		return get_transient( 'wpllmseo_sitemap_' . $key );
	}

	/**
	 * Cache data.
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $data    Data to cache.
	 * @param int    $expires Expiration in seconds.
	 */
	private static function cache( $key, $data, $expires ) {
		set_transient( 'wpllmseo_sitemap_' . $key, $data, $expires );
	}

	/**
	 * Output JSONL format.
	 *
	 * @param array $lines Array of data lines.
	 */
	private static function output_jsonl( $lines ) {
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		foreach ( $lines as $line ) {
			echo wp_json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n";
		}
	}

	/**
	 * Output JSON format.
	 *
	 * @param mixed $data Data to output.
	 */
	private static function output_json( $data ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}
}
