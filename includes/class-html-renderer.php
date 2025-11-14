<?php
/**
 * Advanced HTML Renderer
 *
 * Render LLM-optimized HTML output for content.
 *
 * @package WP_LLM_SEO_Indexing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_HTML_Renderer
 *
 * Renders content in LLM-friendly HTML format.
 */
class WPLLMSEO_HTML_Renderer {

	/**
	 * Initialize renderer.
	 */
	public static function init() {
		// Add custom query var.
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );

		// Template redirect.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_llm_html' ) );

		// REST endpoint.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Add link tag to head.
		add_action( 'wp_head', array( __CLASS__, 'add_llm_html_link' ), 5 );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'llm_render';
		return $vars;
	}

	/**
	 * Maybe render LLM HTML.
	 */
	public static function maybe_render_llm_html() {
		if ( ! get_query_var( 'llm_render' ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$html = self::render_post_html( $post_id );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Render post as LLM-optimized HTML.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function render_post_html( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		// Get metadata.
		$seo_meta    = WPLLMSEO_SEO_Compat::get_primary_seo_meta( $post_id );
		$ai_snippet  = get_post_meta( $post_id, '_wpllmseo_ai_snippet', true );
		$jsonld_data = WPLLMSEO_LLM_JSONLD::generate_jsonld( $post_id );

		// Build HTML.
		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<meta name="robots" content="noindex">
			<title><?php echo esc_html( $seo_meta['title'] ?: $post->post_title ); ?></title>
			
			<?php if ( $seo_meta['meta_description'] ) : ?>
				<meta name="description" content="<?php echo esc_attr( $seo_meta['meta_description'] ); ?>">
			<?php endif; ?>

			<!-- LLM JSON-LD -->
			<script type="application/ld+json">
			<?php echo wp_json_encode( $jsonld_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); ?>
			</script>

			<style>
				* {
					margin: 0;
					padding: 0;
					box-sizing: border-box;
				}
				body {
					font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
					line-height: 1.6;
					color: #333;
					max-width: 800px;
					margin: 0 auto;
					padding: 40px 20px;
					background: #fff;
				}
				header {
					margin-bottom: 40px;
					padding-bottom: 20px;
					border-bottom: 2px solid #e0e0e0;
				}
				h1 {
					font-size: 2.5em;
					line-height: 1.2;
					margin-bottom: 20px;
					color: #1a1a1a;
				}
				.meta {
					color: #666;
					font-size: 0.9em;
					margin-bottom: 10px;
				}
				.meta-item {
					display: inline-block;
					margin-right: 20px;
				}
				.ai-snippet {
					background: #f5f5f5;
					border-left: 4px solid #3f51b5;
					padding: 20px;
					margin: 30px 0;
					font-style: italic;
				}
				.ai-snippet-label {
					font-weight: 600;
					color: #3f51b5;
					font-style: normal;
					margin-bottom: 10px;
					display: block;
				}
				.content {
					font-size: 1.1em;
					line-height: 1.8;
				}
				.content h2 {
					font-size: 1.8em;
					margin: 40px 0 20px;
					color: #1a1a1a;
				}
				.content h3 {
					font-size: 1.4em;
					margin: 30px 0 15px;
					color: #333;
				}
				.content p {
					margin-bottom: 20px;
				}
				.content ul, .content ol {
					margin: 20px 0 20px 40px;
				}
				.content li {
					margin-bottom: 10px;
				}
				.content a {
					color: #3f51b5;
					text-decoration: none;
					border-bottom: 1px solid #3f51b5;
				}
				.content a:hover {
					background: #e8eaf6;
				}
				.content blockquote {
					border-left: 4px solid #e0e0e0;
					padding-left: 20px;
					margin: 20px 0;
					color: #666;
				}
				.content code {
					background: #f5f5f5;
					padding: 2px 6px;
					border-radius: 3px;
					font-family: 'Courier New', monospace;
				}
				.content pre {
					background: #f5f5f5;
					padding: 20px;
					border-radius: 5px;
					overflow-x: auto;
					margin: 20px 0;
				}
				.content img {
					max-width: 100%;
					height: auto;
					border-radius: 5px;
					margin: 20px 0;
				}
				.taxonomy {
					margin-top: 40px;
					padding-top: 20px;
					border-top: 1px solid #e0e0e0;
				}
				.taxonomy-item {
					display: inline-block;
					margin-right: 15px;
					margin-bottom: 10px;
				}
				.taxonomy-label {
					font-weight: 600;
					color: #666;
				}
				.tag {
					display: inline-block;
					background: #e8eaf6;
					color: #3f51b5;
					padding: 5px 12px;
					border-radius: 15px;
					font-size: 0.85em;
					margin-right: 8px;
					margin-bottom: 8px;
				}
				footer {
					margin-top: 60px;
					padding-top: 20px;
					border-top: 1px solid #e0e0e0;
					color: #999;
					font-size: 0.85em;
				}
			</style>
		</head>
		<body>
			<header>
				<h1><?php echo esc_html( $post->post_title ); ?></h1>
				
				<div class="meta">
					<span class="meta-item">
						<strong>Author:</strong> <?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?>
					</span>
					<span class="meta-item">
						<strong>Published:</strong> <?php echo esc_html( get_the_date( 'F j, Y', $post ) ); ?>
					</span>
					<span class="meta-item">
						<strong>Modified:</strong> <?php echo esc_html( get_the_modified_date( 'F j, Y', $post ) ); ?>
					</span>
				</div>

				<?php if ( $ai_snippet ) : ?>
					<div class="ai-snippet">
						<span class="ai-snippet-label">AI Summary</span>
						<?php echo esc_html( $ai_snippet ); ?>
					</div>
				<?php endif; ?>
			</header>

			<article class="content">
				<?php echo wp_kses_post( self::clean_content( $post->post_content ) ); ?>
			</article>

			<div class="taxonomy">
				<?php
				$categories = get_the_category( $post_id );
				if ( ! empty( $categories ) ) :
					?>
					<div class="taxonomy-item">
						<span class="taxonomy-label">Categories:</span>
						<?php foreach ( $categories as $category ) : ?>
							<span class="tag"><?php echo esc_html( $category->name ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php
				$tags = get_the_tags( $post_id );
				if ( ! empty( $tags ) ) :
					?>
					<div class="taxonomy-item">
						<span class="taxonomy-label">Tags:</span>
						<?php foreach ( $tags as $tag ) : ?>
							<span class="tag"><?php echo esc_html( $tag->name ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<footer>
				<p>
					<strong>Original URL:</strong> <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_url( get_permalink( $post_id ) ); ?></a>
				</p>
				<p>
					<strong>Source:</strong> <?php echo esc_html( $seo_meta['source'] ); ?>
				</p>
				<p>
					This is an LLM-optimized rendering. For the full experience, visit the original URL.
				</p>
			</footer>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Clean content for LLM rendering.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function clean_content( $content ) {
		// Apply standard WordPress filters.
		$content = apply_filters( 'the_content', $content );

		// Remove shortcodes not processed.
		$content = strip_shortcodes( $content );

		// Clean up empty paragraphs.
		$content = preg_replace( '/<p>(\s|&nbsp;)*<\/p>/', '', $content );

		// Ensure all links are absolute.
		$content = self::make_links_absolute( $content );

		return $content;
	}

	/**
	 * Make links absolute.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private static function make_links_absolute( $content ) {
		$home_url = home_url();

		// Convert relative links.
		$content = preg_replace_callback(
			'/(href|src)=["\']([^"\']+)["\']/i',
			function( $matches ) use ( $home_url ) {
				$url = $matches[2];

				// Skip if already absolute.
				if ( strpos( $url, 'http' ) === 0 || strpos( $url, '//' ) === 0 ) {
					return $matches[0];
				}

				// Make absolute.
				$absolute_url = rtrim( $home_url, '/' ) . '/' . ltrim( $url, '/' );

				return $matches[1] . '="' . $absolute_url . '"';
			},
			$content
		);

		return $content;
	}

	/**
	 * Add LLM HTML link tag.
	 */
	public static function add_llm_html_link() {
		if ( ! is_singular( array( 'post', 'page' ) ) ) {
			return;
		}

		$settings = get_option( 'wpllmseo_settings', array() );
		if ( empty( $settings['enable_html_renderer'] ) ) {
			return;
		}

		$post_id = get_the_ID();
		$llm_url = add_query_arg( 'llm_render', '1', get_permalink( $post_id ) );

		echo '<link rel="alternate" type="text/html" href="' . esc_url( $llm_url ) . '" title="LLM-Optimized Version">' . "\n";
	}

	/**
	 * Register REST routes.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			WPLLMSEO_REST_NAMESPACE,
			'/render/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_render_post' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * REST: Render post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function rest_render_post( $request ) {
		$post_id = $request->get_param( 'id' );
		$html    = self::render_post_html( $post_id );

		return rest_ensure_response(
			array(
				'post_id' => $post_id,
				'html'    => $html,
			)
		);
	}

	/**
	 * Generate reading time.
	 *
	 * @param string $content Content.
	 * @return int Minutes.
	 */
	private static function calculate_reading_time( $content ) {
		$word_count   = str_word_count( wp_strip_all_tags( $content ) );
		$reading_time = ceil( $word_count / 200 );

		return max( 1, $reading_time );
	}

	/**
	 * Extract table of contents from headings.
	 *
	 * @param string $content Content.
	 * @return array
	 */
	public static function extract_toc( $content ) {
		$toc = array();

		preg_match_all( '/<h([2-6])[^>]*id=["\']([^"\']+)["\'][^>]*>(.*?)<\/h\1>/i', $content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$toc[] = array(
				'level' => intval( $match[1] ),
				'id'    => $match[2],
				'text'  => wp_strip_all_tags( $match[3] ),
			);
		}

		return $toc;
	}

	/**
	 * Add IDs to headings.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function add_heading_ids( $content ) {
		$content = preg_replace_callback(
			'/<h([2-6])([^>]*)>(.*?)<\/h\1>/i',
			function( $matches ) {
				$level   = $matches[1];
				$attrs   = $matches[2];
				$text    = $matches[3];
				$id      = sanitize_title( wp_strip_all_tags( $text ) );

				// Check if ID already exists.
				if ( strpos( $attrs, 'id=' ) !== false ) {
					return $matches[0];
				}

				return sprintf( '<h%s%s id="%s">%s</h%s>', $level, $attrs, $id, $text, $level );
			},
			$content
		);

		return $content;
	}
}
