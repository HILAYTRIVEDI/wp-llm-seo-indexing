<?php
/**
 * MCP Authentication and Authorization
 *
 * Handles token-based authentication and WordPress capability checks
 * for MCP ability invocations.
 *
 * @package WP_LLM_SEO_Indexing
 * @since 1.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPLLMSEO_MCP_Auth
 *
 * Authentication and authorization for MCP.
 */
class WPLLMSEO_MCP_Auth {

	/**
	 * Token table name
	 *
	 * @var string
	 */
	private static $token_table = null;

	/**
	 * Initialize
	 */
	public static function init() {
		global $wpdb;
		self::$token_table = $wpdb->prefix . 'wpllmseo_mcp_tokens';

		// Create token table
		self::maybe_create_table();

		// Add token management hooks
		add_action( 'wpllmseo_cleanup_expired_tokens', array( __CLASS__, 'cleanup_expired_tokens' ) );
	}

	/**
	 * Create tokens table
	 */
	public static function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS " . self::$token_table . " (
			id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			token CHAR(64) NOT NULL UNIQUE,
			user_id BIGINT(20) NOT NULL,
			name VARCHAR(255),
			capabilities LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			last_used DATETIME,
			expires_at DATETIME,
			revoked TINYINT(1) DEFAULT 0,
			INDEX idx_token (token),
			INDEX idx_user (user_id),
			INDEX idx_revoked (revoked),
			INDEX idx_expires (expires_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		wpllmseo_log( 'MCP tokens table created', 'info' );
	}

	/**
	 * Maybe create table
	 */
	private static function maybe_create_table() {
		global $wpdb;

		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				self::$token_table
			)
		);

		if ( $table_exists !== self::$token_table ) {
			self::create_table();
		}
	}

	/**
	 * Generate new MCP token
	 *
	 * @param int    $user_id      User ID.
	 * @param string $name         Token name/description.
	 * @param array  $capabilities Optional capabilities override.
	 * @param int    $expires_days Days until expiration (0 = never).
	 * @return string|false Token string or false on failure.
	 */
	public static function generate_token( $user_id, $name, $capabilities = array(), $expires_days = 0 ) {
		global $wpdb;

		// Validate user
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		// Generate secure token
		$token = bin2hex( random_bytes( 32 ) ); // 64 character hex string

		// Calculate expiration
		$expires_at = null;
		if ( $expires_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expires_days * DAY_IN_SECONDS ) );
		}

		// Store capabilities
		$caps = empty( $capabilities ) ? $user->allcaps : $capabilities;

		// Insert token
		$result = $wpdb->insert(
			self::$token_table,
			array(
				'token'        => $token,
				'user_id'      => $user_id,
				'name'         => $name,
				'capabilities' => wp_json_encode( $caps ),
				'expires_at'   => $expires_at,
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		if ( $result === false ) {
			wpllmseo_log( 'Failed to create MCP token', 'error' );
			return false;
		}

		wpllmseo_log( "Created MCP token for user {$user_id}: {$name}", 'info' );

		return $token;
	}

	/**
	 * Validate and authenticate token
	 *
	 * @param string $token Token string.
	 * @return array|false User context or false if invalid.
	 */
	public static function authenticate_token( $token ) {
		global $wpdb;

		if ( empty( $token ) || strlen( $token ) !== 64 ) {
			return false;
		}

		// Get token from database
		$token_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::$token_table . "
				WHERE token = %s
				AND revoked = 0
				AND (expires_at IS NULL OR expires_at > NOW())",
				$token
			),
			ARRAY_A
		);

		if ( ! $token_data ) {
			wpllmseo_log( 'Invalid or expired MCP token', 'warning' );
			return false;
		}

		// Update last used timestamp
		$wpdb->update(
			self::$token_table,
			array( 'last_used' => current_time( 'mysql' ) ),
			array( 'id' => $token_data['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		// Build context
		$user = get_userdata( $token_data['user_id'] );
		if ( ! $user ) {
			return false;
		}

		$capabilities = json_decode( $token_data['capabilities'], true );

		return array(
			'token_id'     => $token_data['id'],
			'user_id'      => $token_data['user_id'],
			'caller_id'    => $token_data['name'],
			'user'         => $user,
			'capabilities' => $capabilities,
		);
	}

	/**
	 * Check if context has capability
	 *
	 * @param array  $context    Auth context from authenticate_token().
	 * @param string $capability WordPress capability.
	 * @return bool
	 */
	public static function has_capability( $context, $capability ) {
		if ( empty( $context['capabilities'] ) ) {
			return false;
		}

		return ! empty( $context['capabilities'][ $capability ] );
	}

	/**
	 * Check if context can edit post
	 *
	 * @param array $context Auth context.
	 * @param int   $post_id Post ID.
	 * @return bool
	 */
	public static function can_edit_post( $context, $post_id ) {
		if ( empty( $context['user'] ) ) {
			return false;
		}

		// Check if user can edit this specific post
		return user_can( $context['user'], 'edit_post', $post_id );
	}

	/**
	 * Revoke token
	 *
	 * @param string $token Token string.
	 * @return bool Success.
	 */
	public static function revoke_token( $token ) {
		global $wpdb;

		$result = $wpdb->update(
			self::$token_table,
			array( 'revoked' => 1 ),
			array( 'token' => $token ),
			array( '%d' ),
			array( '%s' )
		);

		if ( $result !== false ) {
			wpllmseo_log( 'Revoked MCP token', 'info' );
			return true;
		}

		return false;
	}

	/**
	 * Revoke token by ID
	 *
	 * @param int $token_id Token ID.
	 * @return bool Success.
	 */
	public static function revoke_token_by_id( $token_id ) {
		global $wpdb;

		$result = $wpdb->update(
			self::$token_table,
			array( 'revoked' => 1 ),
			array( 'id' => $token_id ),
			array( '%d' ),
			array( '%d' )
		);

		return $result !== false;
	}

	/**
	 * Get tokens for user
	 *
	 * @param int  $user_id       User ID.
	 * @param bool $include_revoked Include revoked tokens.
	 * @return array
	 */
	public static function get_user_tokens( $user_id, $include_revoked = false ) {
		global $wpdb;

		$where = $wpdb->prepare( 'user_id = %d', $user_id );

		if ( ! $include_revoked ) {
			$where .= ' AND revoked = 0';
		}

		$tokens = $wpdb->get_results(
			"SELECT id, token, name, created_at, last_used, expires_at, revoked
			FROM " . self::$token_table . "
			WHERE {$where}
			ORDER BY created_at DESC",
			ARRAY_A
		);

		// Mask tokens for security
		foreach ( $tokens as &$token_data ) {
			$token_data['token_masked'] = substr( $token_data['token'], 0, 8 ) . '...' . substr( $token_data['token'], -8 );
			unset( $token_data['token'] ); // Don't return full token
		}

		return $tokens;
	}

	/**
	 * Get all tokens (admin only)
	 *
	 * @param array $filters Filters (user_id, revoked).
	 * @param int   $limit   Limit.
	 * @param int   $offset  Offset.
	 * @return array
	 */
	public static function get_all_tokens( $filters = array(), $limit = 50, $offset = 0 ) {
		global $wpdb;

		$where = array( '1=1' );
		$params = array();

		if ( isset( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = $filters['user_id'];
		}

		if ( isset( $filters['revoked'] ) ) {
			$where[]  = 'revoked = %d';
			$params[] = $filters['revoked'] ? 1 : 0;
		}

		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT id, token, user_id, name, created_at, last_used, expires_at, revoked
				FROM " . self::$token_table . "
				WHERE {$where_clause}
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$tokens = $wpdb->get_results( $sql, ARRAY_A );

		// Mask tokens and add user info
		foreach ( $tokens as &$token_data ) {
			$token_data['token_masked'] = substr( $token_data['token'], 0, 8 ) . '...' . substr( $token_data['token'], -8 );
			unset( $token_data['token'] );

			// Add user display name
			$user = get_userdata( $token_data['user_id'] );
			$token_data['user_name'] = $user ? $user->display_name : 'Unknown';
		}

		return $tokens;
	}

	/**
	 * Cleanup expired tokens
	 */
	public static function cleanup_expired_tokens() {
		global $wpdb;

		$deleted = $wpdb->query(
			"DELETE FROM " . self::$token_table . "
			WHERE expires_at IS NOT NULL
			AND expires_at < NOW()"
		);

		if ( $deleted > 0 ) {
			wpllmseo_log( "Cleaned up {$deleted} expired MCP tokens", 'info' );
		}

		return $deleted;
	}

	/**
	 * Get token statistics
	 *
	 * @return array
	 */
	public static function get_stats() {
		global $wpdb;

		$total = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::$token_table
		);

		$active = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::$token_table . "
			WHERE revoked = 0
			AND (expires_at IS NULL OR expires_at > NOW())"
		);

		$expired = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::$token_table . "
			WHERE expires_at IS NOT NULL
			AND expires_at < NOW()"
		);

		$revoked = $wpdb->get_var(
			"SELECT COUNT(*) FROM " . self::$token_table . "
			WHERE revoked = 1"
		);

		return array(
			'total'   => (int) $total,
			'active'  => (int) $active,
			'expired' => (int) $expired,
			'revoked' => (int) $revoked,
		);
	}

	/**
	 * Get table name
	 *
	 * @return string
	 */
	public static function get_table_name() {
		return self::$token_table;
	}
}

// Initialize
WPLLMSEO_MCP_Auth::init();
