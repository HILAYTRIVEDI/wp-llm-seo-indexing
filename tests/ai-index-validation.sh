#!/bin/bash
# AI Index Validation Tests
# Run these commands to verify the AI Index implementation

echo "=== AI Index Validation Tests ==="
echo ""

# Set your site URL
SITE_URL="https://theworldtechs.com"

echo "1. Testing Manifest Endpoint..."
curl -sS "${SITE_URL}/ai-index/manifest.json" | jq .
echo ""

echo "2. Testing Stats Endpoint..."
curl -sS "${SITE_URL}/wp-json/ai-index/v1/stats" | jq .
echo ""

echo "3. Populating Chunk Metadata (via WP-CLI)..."
echo "Run: wp ai-index populate-metadata"
echo ""

echo "4. Exporting Chunks and Embeddings (via WP-CLI)..."
echo "Run: wp ai-index export"
echo ""

echo "5. Viewing Export Stats (via WP-CLI)..."
echo "Run: wp ai-index stats"
echo ""

echo "6. Testing Chunks NDJSON Export..."
curl -sS "${SITE_URL}/ai-index/ai-chunks.ndjson.gz" -o /tmp/ai-chunks.ndjson.gz 2>&1 | head -n 5
if [ -f /tmp/ai-chunks.ndjson.gz ]; then
    echo "Downloaded ai-chunks.ndjson.gz"
    zcat /tmp/ai-chunks.ndjson.gz | head -n 2 | jq .
    rm /tmp/ai-chunks.ndjson.gz
fi
echo ""

echo "7. Testing Chunk Endpoint..."
echo "Get a chunk_id from the export, then test:"
echo "curl -sS ${SITE_URL}/wp-json/ai-index/v1/chunk/{chunk_id} | jq ."
echo ""

echo "8. Testing Gated Embeddings Endpoint (without key)..."
echo "curl -sS ${SITE_URL}/wp-json/ai-index/v1/embeddings/{chunk_id}"
echo "Should return 403 if gating is enabled"
echo ""

echo "9. Creating API Key (via WP-CLI or Admin UI)..."
echo "Admin UI: Go to AI Index > API Keys > Create New API Key"
echo "Or WP-CLI: Use REST API to create key"
echo ""

echo "10. Testing Embeddings with API Key..."
echo "curl -H 'X-API-Key: YOUR_KEY' ${SITE_URL}/wp-json/ai-index/v1/embeddings/{chunk_id} | jq ."
echo ""

echo "=== WP-CLI Commands ==="
echo ""
echo "# Populate metadata for existing chunks"
echo "wp ai-index populate-metadata"
echo ""
echo "# Export all chunks and embeddings"
echo "wp ai-index export"
echo ""
echo "# Export only chunks"
echo "wp ai-index export --type=chunks"
echo ""
echo "# Export delta since specific date"
echo "wp ai-index export --since=2025-11-15T00:00:00"
echo ""
echo "# Generate embeddings for chunks without them"
echo "wp ai-index embed --provider=gemini --model=text-embedding-004 --batch=100"
echo ""
echo "# View statistics"
echo "wp ai-index stats"
echo ""

echo "=== Integration Flow ==="
echo ""
echo "1. After importing content or creating new posts, chunks are created"
echo "2. Run: wp ai-index populate-metadata (to add chunk_id, token_estimate, etc.)"
echo "3. Run: wp ai-index embed (to generate embeddings for new chunks)"
echo "4. Run: wp ai-index export (to create public NDJSON files)"
echo "5. External indexers can fetch ${SITE_URL}/ai-index/manifest.json"
echo "6. Indexers download ai-chunks.ndjson.gz for chunk metadata"
echo "7. Indexers download ai-embeddings.ndjson.gz or use REST API for vectors"
echo ""

echo "=== Acceptance Criteria ==="
echo ""
echo "✓ GET /ai-index/manifest.json returns valid JSON with chunk_index_url and embeddings_index_url"
echo "✓ ai-chunks.ndjson.gz contains chunk JSON lines with token_estimate and chunk_hash"
echo "✓ GET /wp-json/ai-index/v1/chunk/{chunk_id} returns chunk metadata without auth"
echo "✓ GET /wp-json/ai-index/v1/embeddings/{chunk_id} enforces API key when gating enabled"
echo "✓ WP-CLI export commands run without errors and produce gzipped files"
echo "✓ Admin UI shows API keys and allows creation/revocation"
echo "✓ All endpoints set Cache-Control and CORS headers"
echo ""
