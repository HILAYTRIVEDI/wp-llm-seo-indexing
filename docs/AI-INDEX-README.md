# AI Index: Public Discovery & RAG-Ready Artifacts

## Overview

The AI Index feature provides public, discoverable artifacts that allow external LLM indexers to ingest your WordPress site content and enables fast internal RAG (Retrieval-Augmented Generation) pipelines.

## Features

- **Public Manifest**: Discoverable JSON manifest at `/ai-index/manifest.json`
- **NDJSON Exports**: Gzipped chunk and embedding feeds for bulk ingestion
- **REST API Endpoints**: Real-time access to individual chunks and embeddings
- **API Key Gating**: Optional authentication for embedding access
- **Delta Support**: Incremental updates via `--since` parameter
- **WP-CLI Commands**: Automated export and embedding generation
- **Admin UI**: Manage API keys and run exports from WordPress admin

## Architecture

### Database Schema

New columns added to `wp_wpllmseo_chunks` table:

```sql
chunk_id VARCHAR(64)          -- Unique identifier (e.g., "1858-0001")
text LONGTEXT                 -- Full chunk text
start_word INT                -- Starting word position
end_word INT                  -- Ending word position
word_count INT                -- Number of words
char_count INT                -- Character count
token_estimate INT            -- Estimated token count
chunk_hash CHAR(64)          -- MD5 hash of chunk content
embedding_model VARCHAR(128)  -- Model used for embedding
```

### API Endpoints

#### Public Endpoints (No Authentication)

1. **Manifest**: `GET /ai-index/manifest.json`
   - Returns discovery information and links to exports
   - Cache: 1 hour

2. **Chunk Metadata**: `GET /wp-json/ai-index/v1/chunk/{chunk_id}`
   - Returns chunk text preview, metadata, and links
   - Accessible for published posts only
   - Cache: 1 hour

3. **Stats**: `GET /wp-json/ai-index/v1/stats`
   - Returns chunk and embedding statistics
   - Cache: 5 minutes

#### Gated Endpoints (Optional API Key)

4. **Embeddings**: `GET /wp-json/ai-index/v1/embeddings/{chunk_id}`
   - Returns full embedding vector
   - Requires `X-API-Key` header when gating enabled
   - Cache: 24 hours

#### Static File Endpoints

5. **Chunks Export**: `GET /ai-index/ai-chunks.ndjson.gz`
   - Gzipped NDJSON file with all chunk metadata
   - One JSON object per line

6. **Embeddings Export**: `GET /ai-index/ai-embeddings.ndjson.gz`
   - Gzipped NDJSON file with all embeddings
   - Includes vector arrays

7. **Delta Export**: `GET /ai-index/ai-chunks-delta.ndjson.gz`
   - Incremental changes since last full export

## WP-CLI Commands

### Export Commands

```bash
# Export all chunks and embeddings
wp ai-index export

# Export to specific directory
wp ai-index export --output-dir=/path/to/exports

# Export only chunks (skip embeddings)
wp ai-index export --type=chunks

# Export only embeddings
wp ai-index export --type=embeddings

# Export delta since specific date/time
wp ai-index export --since=2025-11-15T00:00:00

# Limit export size
wp ai-index export --limit=1000

# Export without compression
wp ai-index export --no-gz
```

### Embedding Generation

```bash
# Generate embeddings for all chunks without them
wp ai-index embed --provider=gemini --model=text-embedding-004

# Process in smaller batches
wp ai-index embed --batch=50

# Limit number of chunks to process
wp ai-index embed --limit=500
```

### Utility Commands

```bash
# View statistics
wp ai-index stats

# Populate metadata for existing chunks
wp ai-index populate-metadata
```

## Admin Interface

Navigate to **WP Admin > AI SEO > AI Index**

### Settings

- **Gate Embeddings**: Enable/disable API key requirement for embeddings endpoint
- **Export Files**: View and download generated NDJSON files
- **Run Export**: Trigger manual export from admin UI

### API Key Management

1. Click "Create New API Key"
2. Enter a descriptive name (e.g., "Production Indexer")
3. Copy the generated key (shown only once)
4. Use key in `X-API-Key` header for gated endpoints

**Delete Keys**: Click "Delete" button next to any key to revoke access

## Usage Examples

### External LLM Indexer Flow

```bash
# 1. Fetch manifest to discover endpoints
curl https://yoursite.com/ai-index/manifest.json | jq .

# 2. Download full chunk export
curl https://yoursite.com/ai-index/ai-chunks.ndjson.gz -o chunks.ndjson.gz
zcat chunks.ndjson.gz | head -n 5

# 3. Download embeddings (if not gated)
curl https://yoursite.com/ai-index/ai-embeddings.ndjson.gz -o embeddings.ndjson.gz

# 4. Or fetch individual chunks via REST API
curl https://yoursite.com/wp-json/ai-index/v1/chunk/1858-0001 | jq .

# 5. Fetch gated embeddings with API key
curl -H "X-API-Key: wpllm_abc123..." \
  https://yoursite.com/wp-json/ai-index/v1/embeddings/1858-0001 | jq .
```

### Internal RAG Pipeline

```bash
# 1. Populate metadata for all chunks
wp ai-index populate-metadata

# 2. Generate embeddings
wp ai-index embed --provider=gemini --model=text-embedding-004

# 3. Export for caching or external use
wp ai-index export

# 4. Check statistics
wp ai-index stats
```

### Incremental Updates (Delta Sync)

```bash
# Record timestamp of last sync
LAST_SYNC="2025-11-16T10:00:00"

# Export only changes since last sync
wp ai-index export --since=$LAST_SYNC

# Delta file will be created at:
# wp-content/uploads/ai-index/ai-chunks-delta.ndjson.gz
```

## Data Formats

### Manifest JSON

```json
{
  "version": "1.3.0",
  "site": "Your Site Name",
  "site_url": "https://yoursite.com",
  "generated": "2025-11-16T10:30:00+00:00",
  "content_count": 1523,
  "chunk_index_url": "https://yoursite.com/ai-index/ai-chunks.ndjson.gz",
  "embeddings_index_url": "https://yoursite.com/ai-index/ai-embeddings.ndjson.gz",
  "delta_url": "https://yoursite.com/ai-index/ai-chunks-delta.ndjson.gz",
  "endpoints": {
    "chunk": "https://yoursite.com/wp-json/ai-index/v1/chunk/{chunk_id}",
    "embeddings": "https://yoursite.com/wp-json/ai-index/v1/embeddings/{chunk_id}",
    "stats": "https://yoursite.com/wp-json/ai-index/v1/stats"
  }
}
```

### Chunk NDJSON Line

```json
{
  "chunk_id": "1858-0001",
  "post_id": 1858,
  "start_word": 0,
  "end_word": 150,
  "word_count": 150,
  "char_count": 892,
  "token_estimate": 195,
  "text_preview": "This is the beginning of the chunk text...",
  "chunk_url": "https://yoursite.com/wp-json/ai-index/v1/chunk/1858-0001",
  "last_modified": "2025-11-16 10:15:23",
  "chunk_hash": "a1b2c3d4...",
  "source_post_hash": "e5f6g7h8..."
}
```

### Embedding NDJSON Line

```json
{
  "chunk_id": "1858-0001",
  "model": "text-embedding-004",
  "dim": 768,
  "vec": [0.123, -0.456, 0.789, ...],
  "last_modified": "2025-11-16 10:15:23"
}
```

## Security Considerations

### API Key Storage

- API keys are hashed using WordPress `wp_hash_password()` before storage
- Plain text keys are only shown once at creation time
- Keys are validated using `wp_check_password()` for secure comparison

### Embedding Access Control

When **Gate Embeddings** is enabled:

- NDJSON exports still include full embeddings (static files)
- REST API `/embeddings/{chunk_id}` requires valid `X-API-Key` header
- Missing or invalid keys return `403 Forbidden`

### Public Chunk Access

- Chunk metadata is public for published posts only
- Unpublished or private posts return `403` or `404`
- No WordPress authentication required for public content

## Performance Optimization

### Caching

All endpoints set appropriate `Cache-Control` headers:

- Manifest: 1 hour
- Chunks: 1 hour
- Embeddings: 24 hours
- NDJSON files: 24 hours
- Stats: 5 minutes

### CORS Headers

All endpoints include `Access-Control-Allow-Origin: *` for cross-origin access by indexers.

### File Compression

- All NDJSON exports are gzipped by default
- Typical compression ratio: 5-10x smaller
- Use `--no-gz` flag to export uncompressed (for debugging)

### Atomic File Writes

Export process:

1. Write to temporary file (`.tmp` extension)
2. Complete write operation
3. Atomic rename to final filename
4. Prevents partial downloads during export

## Troubleshooting

### Manifest Returns 404

```bash
# Flush rewrite rules
wp rewrite flush

# Or via admin: Settings > Permalinks > Save Changes
```

### NDJSON Files Not Found

```bash
# Run export to generate files
wp ai-index export

# Check file permissions
ls -la wp-content/uploads/ai-index/
```

### No Chunks Have Embeddings

```bash
# Generate embeddings
wp ai-index embed --provider=gemini --model=text-embedding-004

# Check provider configuration
wp option get wpllmseo_settings --format=json | jq '.active_providers, .active_models'
```

### API Key Authentication Fails

- Ensure `X-API-Key` header is set correctly
- Check that gating is enabled: Admin > AI Index > Settings
- Verify key hasn't been deleted
- Try creating a new API key

## Integration Examples

### Python Indexer

```python
import requests
import gzip
import json

# Fetch manifest
manifest = requests.get('https://yoursite.com/ai-index/manifest.json').json()

# Download chunks
chunks_url = manifest['chunk_index_url']
response = requests.get(chunks_url)

# Decompress and parse NDJSON
chunks = []
for line in gzip.decompress(response.content).decode().split('\n'):
    if line.strip():
        chunks.append(json.loads(line))

print(f"Loaded {len(chunks)} chunks")

# Fetch individual embedding with API key
headers = {'X-API-Key': 'wpllm_your_key_here'}
embedding = requests.get(
    f"https://yoursite.com/wp-json/ai-index/v1/embeddings/{chunks[0]['chunk_id']}",
    headers=headers
).json()

print(f"Embedding dimension: {embedding['dim']}")
```

### JavaScript/Node.js

```javascript
const axios = require('axios');
const zlib = require('zlib');

// Fetch manifest
const manifest = await axios.get('https://yoursite.com/ai-index/manifest.json');
console.log('Site:', manifest.data.site);
console.log('Total chunks:', manifest.data.content_count);

// Download and decompress chunks
const chunksGz = await axios.get(manifest.data.chunk_index_url, {
  responseType: 'arraybuffer'
});

const chunks = zlib.gunzipSync(chunksGz.data)
  .toString()
  .split('\n')
  .filter(line => line.trim())
  .map(line => JSON.parse(line));

console.log(`Loaded ${chunks.length} chunks`);

// Fetch embedding with API key
const embedding = await axios.get(
  `https://yoursite.com/wp-json/ai-index/v1/embeddings/${chunks[0].chunk_id}`,
  { headers: { 'X-API-Key': 'wpllm_your_key_here' } }
);

console.log('Vector length:', embedding.data.vec.length);
```

## Roadmap

### Phase 1 (Current)
- ✅ Manifest endpoint
- ✅ Chunk NDJSON export
- ✅ Chunk REST endpoint
- ✅ API key gating
- ✅ WP-CLI export commands

### Phase 2 (Planned)
- Scheduled automatic exports via cron
- Vector database adapter for large sites
- Webhook notifications for delta updates
- Multi-format support (Parquet, Arrow)

### Phase 3 (Future)
- Semantic search public wrapper
- JSON-LD per chunk
- Usage analytics and monitoring
- Rate limiting for public endpoints

## Support

For issues or feature requests:
- GitHub: https://github.com/HILAYTRIVEDI/wp-llm-seo-indexing
- Documentation: https://theworldtechs.com/docs/ai-index

## License

GPL v2 or later
