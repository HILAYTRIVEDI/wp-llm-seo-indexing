<?php
/**
 * Database helpers for WP LLM SEO plugin.
 *
 * Provides safe helpers for working with table names and executing schema changes.
 *
 * @package WP_LLM_SEO_Indexing
 */

defined( 'ABSPATH' ) || exit;

class WPLLMSEO_DB_Helpers {

	/**
	 * Whitelisted table suffixes the plugin may operate on.
	 * Used to validate dynamic table names.
	 *
	 * @var array
	 */
	private static $allowed_suffixes = array(
		'wpllmseo_snippets',
		'wpllmseo_chunks',
		'wpllmseo_jobs',
		'wpllmseo_jobs_dead_letter',
		'wpllmseo_tokens',
		// Add other known plugin tables here
	);

	/**
	 * Ensure a table name is valid and returns the full prefixed table name.
	 *
	 * @param string $table Table name or suffix.
	 * @return string|WP_Error Full validated table name or WP_Error on failure.
	 */
	public static function validate_table_name( $table ) {
		global $wpdb;

		if ( empty( $table ) ) {
			return new WP_Error( 'invalid_table', 'Empty table name' );
		}

		// If table already contains prefix, normalize and check
		$prefix = $wpdb->prefix;
		if ( strpos( $table, $prefix ) === 0 ) {
			$unprefixed = substr( $table, strlen( $prefix ) );
		} else {
			$unprefixed = $table;
		}

		// Only allow exact matches from whitelist
		if ( in_array( $unprefixed, self::$allowed_suffixes, true ) ) {
			return $prefix . $unprefixed;
		}

		return new WP_Error( 'invalid_table', sprintf( 'Table name not allowed: %s', esc_html( $table ) ) );
	}

	/**
	 * Insert or update chunk embedding row in the chunks table.
	 *
	 * @param int    $post_id Attachment/post ID.
	 * @param int    $chunk_index Chunk index.
	 * @param string $chunk_text Chunk text.
	 * @param array  $embedding Embedding vector or associative data.
	 * @param array  $meta Additional metadata (token_count, embedding_dim, checksum).
	 * @return int|WP_Error Inserted/updated row ID or WP_Error.
	 */
	public static function upsert_chunk_embedding( $post_id, $chunk_index, $chunk_text, $embedding, $meta = array() ) {
		global $wpdb;

		$validated = self::validate_table_name( 'wpllmseo_chunks' );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$embedding_json = wp_json_encode( $embedding );
		$embedding_dim  = isset( $meta['embedding_dim'] ) ? intval( $meta['embedding_dim'] ) : null;
		$token_count    = isset( $meta['token_count'] ) ? intval( $meta['token_count'] ) : 0;
		$checksum       = isset( $meta['checksum'] ) ? $meta['checksum'] : null;
		$version        = isset( $meta['version'] ) ? $meta['version'] : 'v1';

		// Check if row exists
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$validated} WHERE post_id = %d AND chunk_index = %d", $post_id, $chunk_index ) );

		$data = array(
			'post_id' => $post_id,
			'chunk_text' => $chunk_text,
			'chunk_hash' => hash( 'sha256', $chunk_text ),
			'embedding_json' => $embedding_json,
			'embedding_format' => 'json_float_array_v1',
			'embedding_dim' => $embedding_dim,
			'embedding_checksum' => $checksum,
			'embedding_version' => $version,
			'chunk_index' => $chunk_index,
			'token_count' => $token_count,
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $row ) {
			$where = array( 'id' => $row->id );
			$updated = $wpdb->update( $validated, $data, $where );
			if ( false === $updated ) {
				return new WP_Error( 'db_update_failed', 'Failed to update chunk row' );
			}
			return $row->id;
		}

		$data['created_at'] = current_time( 'mysql' );
		$inserted = $wpdb->insert( $validated, $data );
		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', 'Failed to insert chunk row' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get number of chunks for a given post_id.
	 *
	 * @param int $post_id
	 * @return int
	 */
	public static function get_chunk_count( $post_id ) {
		global $wpdb;

		$validated = self::validate_table_name( 'wpllmseo_chunks' );
		if ( is_wp_error( $validated ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$validated} WHERE post_id = %d", $post_id ) );
	}

	/**
	 * Retrieve chunks rows for a post.
	 *
	 * @param int $post_id
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public static function get_chunks_for_post( $post_id, $limit = 50, $offset = 0 ) {
		global $wpdb;

		$validated = self::validate_table_name( 'wpllmseo_chunks' );
		if ( is_wp_error( $validated ) ) {
			return array();
		}

		$sql = $wpdb->prepare( "SELECT * FROM {$validated} WHERE post_id = %d ORDER BY chunk_index ASC LIMIT %d OFFSET %d", $post_id, $limit, $offset );
		return $wpdb->get_results( $sql );
	}

	/**
	 * Safe wrapper for ALTER TABLE queries that validates the table name first.
	 *
	 * @param string $table Table name or suffix.
	 * @param string $sql_fragment SQL fragment to append after ALTER TABLE <table>.
	 * @return int|WP_Error Result of $wpdb->query or WP_Error on failure.
	 */
	public static function safe_alter_table( $table, $sql_fragment ) {
		global $wpdb;

		$validated = self::validate_table_name( $table );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Only allow certain SQL fragment patterns (very conservative)
		$allowed_patterns = array(
			'/^ADD\s+COLUMN\s+/i',
			'/^ADD\s+INDEX\s+/i',
			'/^DROP\s+COLUMN\s+/i',
			'/^ALTER\s+COLUMN\s+/i',
		);

		$ok = false;
		foreach ( $allowed_patterns as $pat ) {
			if ( preg_match( $pat, ltrim( $sql_fragment ) ) ) {
				$ok = true;
				break;
			}
		}

		if ( ! $ok ) {
			return new WP_Error( 'invalid_sql_fragment', 'SQL fragment not allowed' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name validated
		return $wpdb->query( "ALTER TABLE {$validated} {$sql_fragment}" );
	}
}
