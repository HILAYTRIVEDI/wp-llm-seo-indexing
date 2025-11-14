<?php
/**
 * Embedding Helper Functions
 *
 * Utilities for storing, retrieving and validating embeddings with metadata.
 * Ensures consistency and integrity of embedding data across the plugin.
 *
 * @package WPLLMSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store embedding with metadata and checksum
 *
 * @param string $table    Table name (with prefix).
 * @param int    $row_id   Row ID to update.
 * @param array  $vector   Float array embedding.
 * @param string $version  Optional embedding version.
 * @return bool True on success, false on failure.
 */
function wpllmseo_store_embedding( $table, $row_id, array $vector, $version = 'v1' ) {
	global $wpdb;
	
	if ( empty( $vector ) || ! is_array( $vector ) ) {
		wpllmseo_log( "Invalid embedding vector for row $row_id in $table", 'error' );
		return false;
	}
	
	$json     = wp_json_encode( $vector );
	$dim      = count( $vector );
	$checksum = hash( 'sha256', $json );
	
	$result = $wpdb->update(
		$table,
		array(
			'embedding_json'     => $json,
			'embedding_dim'      => $dim,
			'embedding_checksum' => $checksum,
			'embedding_format'   => 'json_float_array_v1',
			'embedding_version'  => $version,
		),
		array( 'id' => $row_id ),
		array( '%s', '%d', '%s', '%s', '%s' ),
		array( '%d' )
	);
	
	if ( false === $result ) {
		wpllmseo_log( "Failed to store embedding for row $row_id in $table: " . $wpdb->last_error, 'error' );
		return false;
	}
	
	return true;
}

/**
 * Get embedding from row data
 *
 * @param array $row Database row with embedding fields.
 * @return array|null Vector array or null on failure.
 */
function wpllmseo_get_embedding( $row ) {
	if ( empty( $row['embedding_json'] ) ) {
		return null;
	}
	
	$vector = json_decode( $row['embedding_json'], true );
	
	if ( ! is_array( $vector ) ) {
		wpllmseo_log( "Invalid embedding JSON for row {$row['id']}", 'error' );
		return null;
	}
	
	// Validate checksum if available
	if ( ! empty( $row['embedding_checksum'] ) ) {
		$calculated_checksum = hash( 'sha256', $row['embedding_json'] );
		if ( $calculated_checksum !== $row['embedding_checksum'] ) {
			wpllmseo_log( "Embedding checksum mismatch for row {$row['id']}: expected {$row['embedding_checksum']}, got $calculated_checksum", 'error' );
			return null;
		}
	}
	
	return $vector;
}

/**
 * Validate embedding dimensions
 *
 * @param array $row    Database row with embedding_dim field.
 * @param array $vector Vector to validate.
 * @return bool True if valid, false otherwise.
 */
function wpllmseo_validate_embedding_dim( $row, $vector ) {
	if ( ! isset( $row['embedding_dim'] ) || null === $row['embedding_dim'] ) {
		return true; // No stored dimension to validate against
	}
	
	$expected = intval( $row['embedding_dim'] );
	$actual   = count( $vector );
	
	if ( $expected !== $actual ) {
		wpllmseo_log( 
			"Embedding dimension mismatch for row {$row['id']}: expected $expected, got $actual",
			'error'
		);
		return false;
	}
	
	return true;
}

/**
 * Compute cosine similarity between two vectors
 *
 * @param array $vec1 First vector.
 * @param array $vec2 Second vector.
 * @return float|null Similarity score (0-1) or null on error.
 */
function wpllmseo_cosine_similarity( $vec1, $vec2 ) {
	if ( count( $vec1 ) !== count( $vec2 ) ) {
		wpllmseo_log( 'Vector dimension mismatch in cosine similarity', 'error' );
		return null;
	}
	
	$dot_product = 0.0;
	$magnitude1  = 0.0;
	$magnitude2  = 0.0;
	
	for ( $i = 0; $i < count( $vec1 ); $i++ ) {
		$dot_product += $vec1[ $i ] * $vec2[ $i ];
		$magnitude1  += $vec1[ $i ] * $vec1[ $i ];
		$magnitude2  += $vec2[ $i ] * $vec2[ $i ];
	}
	
	$magnitude1 = sqrt( $magnitude1 );
	$magnitude2 = sqrt( $magnitude2 );
	
	if ( $magnitude1 == 0 || $magnitude2 == 0 ) {
		return 0.0;
	}
	
	return $dot_product / ( $magnitude1 * $magnitude2 );
}

/**
 * Migrate legacy BLOB embeddings to JSON format
 *
 * @param string $table     Table name (with prefix).
 * @param int    $batch_size Number of rows to process per batch.
 * @return array Migration statistics.
 */
function wpllmseo_migrate_blob_embeddings( $table, $batch_size = 100 ) {
	global $wpdb;
	
	$stats = array(
		'total'     => 0,
		'migrated'  => 0,
		'skipped'   => 0,
		'failed'    => 0,
	);
	
	// Count rows with BLOB but no JSON
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE embedding IS NOT NULL AND (embedding_json IS NULL OR embedding_json = '')" );
	$stats['total'] = intval( $total );
	
	if ( $stats['total'] === 0 ) {
		return $stats;
	}
	
	$offset = 0;
	
	while ( $offset < $stats['total'] ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT id, embedding FROM $table WHERE embedding IS NOT NULL AND (embedding_json IS NULL OR embedding_json = '') LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			),
			ARRAY_A
		);
		
		foreach ( $rows as $row ) {
			try {
				// Unpack BLOB to float array
				$packed = $row['embedding'];
				$count  = strlen( $packed ) / 4; // 4 bytes per float
				$vector = array_values( unpack( "f{$count}", $packed ) );
				
				if ( wpllmseo_store_embedding( $table, $row['id'], $vector ) ) {
					$stats['migrated']++;
				} else {
					$stats['failed']++;
				}
			} catch ( Exception $e ) {
				wpllmseo_log( "Failed to migrate embedding for row {$row['id']}: " . $e->getMessage(), 'error' );
				$stats['failed']++;
			}
		}
		
		$offset += $batch_size;
	}
	
	return $stats;
}
