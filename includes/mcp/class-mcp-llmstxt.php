<?php
/**
 * LLMs.txt Parser and Helper
 *
 * Parses and validates /llms.txt directives for controlling
 * LLM crawler access to content.
 *
 * @package WP_LLM_SEO_Indexing
 * @since 1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_MCP_LLMsTxt
 *
 * Handles LLMs.txt parsing, caching, and allow/deny checks.
 */
class WPLLMSEO_MCP_LLMsTxt {

	/**
	 * Cache key for parsed rules
	 *
	 * @var string
	 */
	const CACHE_KEY = 'wpllmseo_llms_txt_rules';

	/**
	 * Cache duration in seconds (5 minutes)
	 *
	 * @var int
	 */
	const CACHE_DURATION = 300;

	/**
	 * Path to llms.txt file
	 *
	 * @var string
	 */
	private static $file_path = null;

	/**
	 * Parsed rules
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Initialize
	 */
	public static function init() {
		self::$file_path = ABSPATH . 'llms.txt';

		// Hook to flush cache when file changes
		add_action( 'wpllmseo_flush_llms_txt_cache', array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Check if LLMs.txt should be respected
	 *
	 * @return bool
	 */
	public static function should_respect() {
		// Check global setting
		$respect = get_option( 'wpllmseo_mcp_respect_llms_txt', true );

		// Allow admin override
		return apply_filters( 'wpllmseo_respect_llms_txt', $respect );
	}

	/**
	 * Check if path is disallowed by LLMs.txt
	 *
	 * @param string $path URL or path to check.
	 * @return bool True if disallowed, false if allowed.
	 */
	public static function is_disallowed( $path ) {
		// If not respecting LLMs.txt, allow everything
		if ( ! self::should_respect() ) {
			return false;
		}

		// Parse path from full URL
		if ( str_starts_with( $path, 'http' ) ) {
			$parsed = wp_parse_url( $path );
			$path   = $parsed['path'] ?? '/';
		}

		// Ensure path starts with /
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		// Get rules
		$rules = self::get_rules();

		// No rules = allow everything
		if ( empty( $rules ) ) {
			return false;
		}

		// Check disallow rules first (deny takes precedence)
		foreach ( $rules['disallow'] as $pattern ) {
			if ( self::matches_pattern( $path, $pattern ) ) {
				// Check if explicitly allowed
				foreach ( $rules['allow'] as $allow_pattern ) {
					if ( self::matches_pattern( $path, $allow_pattern ) ) {
						return false; // Explicitly allowed
					}
				}
				return true; // Disallowed
			}
		}

		// Default to allow
		return false;
	}

	/**
	 * Get parsed rules from cache or file
	 *
	 * @return array Rules array with 'allow' and 'disallow' keys.
	 */
	public static function get_rules() {
		// Return cached rules if available
		if ( self::$rules !== null ) {
			return self::$rules;
		}

		// Try to get from transient cache
		$cached = get_transient( self::CACHE_KEY );
		if ( $cached !== false ) {
			self::$rules = $cached;
			return self::$rules;
		}

		// Parse from file
		self::$rules = self::parse_file();

		// Cache the result
		set_transient( self::CACHE_KEY, self::$rules, self::CACHE_DURATION );

		return self::$rules;
	}

	/**
	 * Parse LLMs.txt file
	 *
	 * @return array Parsed rules.
	 */
	private static function parse_file() {
		$rules = array(
			'allow'    => array(),
			'disallow' => array(),
			'metadata' => array(),
		);

		// Check if file exists
		if ( ! file_exists( self::$file_path ) ) {
			wpllmseo_log( 'LLMs.txt file not found at: ' . self::$file_path, 'debug' );
			return $rules;
		}

		// Read file
		$content = file_get_contents( self::$file_path );
		if ( $content === false ) {
			wpllmseo_log( 'Failed to read LLMs.txt file', 'error' );
			return $rules;
		}

		// Parse lines
		$lines = explode( "\n", $content );
		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip empty lines and comments
			if ( empty( $line ) || str_starts_with( $line, '#' ) ) {
				continue;
			}

			// Parse directive
			if ( preg_match( '/^(Allow|Disallow):\s*(.+)$/i', $line, $matches ) ) {
				$directive = strtolower( $matches[1] );
				$pattern   = trim( $matches[2] );

				if ( $directive === 'allow' ) {
					$rules['allow'][] = $pattern;
				} elseif ( $directive === 'disallow' ) {
					$rules['disallow'][] = $pattern;
				}
			}

			// Parse metadata (key: value)
			if ( str_contains( $line, ':' ) && ! str_starts_with( $line, 'Allow' ) && ! str_starts_with( $line, 'Disallow' ) ) {
				list( $key, $value ) = explode( ':', $line, 2 );
				$rules['metadata'][ trim( $key ) ] = trim( $value );
			}
		}

		wpllmseo_log( 'Parsed LLMs.txt: ' . count( $rules['allow'] ) . ' allow, ' . count( $rules['disallow'] ) . ' disallow rules', 'debug' );

		return $rules;
	}

	/**
	 * Check if path matches pattern
	 *
	 * @param string $path    Path to check.
	 * @param string $pattern Pattern from LLMs.txt.
	 * @return bool
	 */
	private static function matches_pattern( $path, $pattern ) {
		// Exact match
		if ( $path === $pattern ) {
			return true;
		}

		// Wildcard pattern
		if ( str_contains( $pattern, '*' ) ) {
			// Convert to regex
			$regex = preg_quote( $pattern, '/' );
			$regex = str_replace( '\*', '.*', $regex );
			$regex = '/^' . $regex . '/';

			return (bool) preg_match( $regex, $path );
		}

		// Prefix match (pattern ends with /)
		if ( str_ends_with( $pattern, '/' ) ) {
			return str_starts_with( $path, $pattern );
		}

		// Suffix match (pattern starts with *)
		if ( str_starts_with( $pattern, '*' ) ) {
			return str_ends_with( $path, substr( $pattern, 1 ) );
		}

		// Substring match (pattern contains no wildcards but should match anywhere)
		return str_contains( $path, $pattern );
	}

	/**
	 * Flush cached rules
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		self::$rules = null;
		wpllmseo_log( 'LLMs.txt cache flushed', 'debug' );
	}

	/**
	 * Create example LLMs.txt file
	 *
	 * @return bool Success.
	 */
	public static function create_example_file() {
		$example_content = <<<TXT
# LLMs.txt - Control LLM crawler access
# Learn more: https://example.com/llms-txt

# Block specific paths
Disallow: /private/
Disallow: /draft-*/
Disallow: /admin/

# Block specific categories
Disallow: /category/internal/
Disallow: /category/confidential/

# Block specific post types
Disallow: /product-drafts/

# Allow specific exceptions
Allow: /public/

# Allow everything else by default
Allow: /

# Metadata
License: CC BY 4.0
Contact: admin@example.com
TXT;

		$result = file_put_contents( self::$file_path, $example_content );

		if ( $result !== false ) {
			wpllmseo_log( 'Created example LLMs.txt file', 'info' );
			self::flush_cache();
			return true;
		}

		wpllmseo_log( 'Failed to create example LLMs.txt file', 'error' );
		return false;
	}

	/**
	 * Check if LLMs.txt file exists
	 *
	 * @return bool
	 */
	public static function file_exists() {
		return file_exists( self::$file_path );
	}

	/**
	 * Get file path
	 *
	 * @return string
	 */
	public static function get_file_path() {
		return self::$file_path;
	}

	/**
	 * Get file URL
	 *
	 * @return string
	 */
	public static function get_file_url() {
		return home_url( '/llms.txt' );
	}

	/**
	 * Validate LLMs.txt syntax
	 *
	 * @param string $content File content.
	 * @return array Validation result with 'valid' and 'errors' keys.
	 */
	public static function validate_syntax( $content ) {
		$result = array(
			'valid'  => true,
			'errors' => array(),
		);

		$lines = explode( "\n", $content );
		foreach ( $lines as $line_num => $line ) {
			$line = trim( $line );

			// Skip empty lines and comments
			if ( empty( $line ) || str_starts_with( $line, '#' ) ) {
				continue;
			}

			// Check for valid directive
			if ( ! preg_match( '/^(Allow|Disallow):\s*.+$/i', $line ) && ! str_contains( $line, ':' ) ) {
				$result['valid']    = false;
				$result['errors'][] = sprintf(
					'Line %d: Invalid syntax. Expected "Allow: /path" or "Disallow: /path"',
					$line_num + 1
				);
			}
		}

		return $result;
	}

	/**
	 * Get statistics about rules
	 *
	 * @return array Statistics.
	 */
	public static function get_stats() {
		$rules = self::get_rules();

		return array(
			'file_exists'   => self::file_exists(),
			'allow_count'   => count( $rules['allow'] ),
			'disallow_count' => count( $rules['disallow'] ),
			'metadata'      => $rules['metadata'],
			'cache_key'     => self::CACHE_KEY,
			'cache_duration' => self::CACHE_DURATION,
		);
	}

	/**
	 * Test path against rules
	 *
	 * @param string $path Path to test.
	 * @return array Test result.
	 */
	public static function test_path( $path ) {
		$rules       = self::get_rules();
		$is_disallowed = self::is_disallowed( $path );

		$matched_allow    = array();
		$matched_disallow = array();

		// Find matching allow rules
		foreach ( $rules['allow'] as $pattern ) {
			if ( self::matches_pattern( $path, $pattern ) ) {
				$matched_allow[] = $pattern;
			}
		}

		// Find matching disallow rules
		foreach ( $rules['disallow'] as $pattern ) {
			if ( self::matches_pattern( $path, $pattern ) ) {
				$matched_disallow[] = $pattern;
			}
		}

		return array(
			'path'             => $path,
			'allowed'          => ! $is_disallowed,
			'matched_allow'    => $matched_allow,
			'matched_disallow' => $matched_disallow,
		);
	}
}

// Initialize
WPLLMSEO_MCP_LLMsTxt::init();
