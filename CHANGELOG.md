# Changelog

All notable changes to WP LLM SEO & Indexing will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.0] - 2025-01-11

### 🎉 Production Release

This release represents the first production-ready version with comprehensive testing, security hardening, and performance optimizations.

### ✨ Added

- **Health Check Dashboard** - Comprehensive system diagnostics panel
  - Real-time status checks for rewrite rules, WP-Cron, database tables
  - One-click rewrite rule flush button
  - Sitemap endpoint test links
  - Queue status monitoring
  - PHP/WordPress version compatibility checks
  - Troubleshooting guidance

- **Enhanced Error Handling**
  - Proper error logging via `wpllmseo_log()` function
  - Execution logs with detailed stack traces
  - Dead letter queue for failed jobs
  - Retry mechanism with exponential backoff

- **Rate Limiting System**
  - Per-provider rate limits (configurable)
  - Automatic quota detection from API responses
  - Backoff strategy for rate limit errors
  - Daily quota tracking and reporting

- **Access Token System**
  - Generate time-limited access tokens for sitemap endpoints
  - Token expiration and automatic cleanup
  - Optional public access mode
  - Secure token generation using WordPress APIs

- **Semantic Map Fallback**
  - Category/tag relationship fallback when embeddings not available
  - Graceful degradation for semantic map endpoint
  - Improved content discovery for new installations

### 🔧 Fixed

- **Sitemap 404 Errors** - Rewrite rules now properly registered and flushed on activation
- **Provider Settings Not Saving** - Fixed provider configuration overwrite bug in admin handler
- **Bulk Snippet Generator** - Fixed job scheduling and WP-Cron integration
- **Queue Worker Locking** - Improved job locking mechanism to prevent race conditions
- **Database Migration** - Proper version checking and incremental upgrades

### 🔒 Security

- **Removed Debug Code** - Eliminated all temporary `file_put_contents()` debug statements
- **SQL Injection Prevention** - All queries use `$wpdb->prepare()` with parameterized statements
- **XSS Protection** - Comprehensive output escaping with `esc_html()`, `esc_attr()`, `wp_kses()`
- **CSRF Protection** - Nonce verification on all form submissions and AJAX handlers
- **Capability Checks** - `manage_options` verification on sensitive operations
- **API Key Encryption** - WordPress-native encryption for stored API credentials
- **Input Sanitization** - All user input properly sanitized before processing

### ⚡ Performance

- **Optimized Queries** - Added database indexes for common query patterns
- **Batch Processing** - Bulk operations process in configurable batch sizes
- **Caching Strategy** - Transient caching for sitemap generation
- **Async Job Processing** - Background workers process embeddings asynchronously
- **Chunk Optimization** - Configurable chunk sizes for different providers

### 📚 Documentation

- **PRODUCTION-README.md** - Comprehensive production deployment guide
  - Installation instructions
  - Configuration steps
  - Sitemap endpoint documentation
  - Background processing setup
  - Troubleshooting guide
  - Security best practices
  - Performance optimization tips

- **SECURITY-SETUP.md** - Security hardening guide
- **DEPLOYMENT-CHECKLIST.md** - Pre-deployment verification steps
- **Inline Code Comments** - Improved PHPDoc blocks throughout codebase

### 🔄 Changed

- **Plugin Version** - Bumped from 1.0.0 to 1.2.0 (matching DB schema version)
- **Minimum PHP Version** - Requires PHP 8.1+ (from 7.4)
- **Database Schema** - DB_VERSION now 1.2.0 with updated table structures
- **Logging System** - Standardized on `wpllmseo_log()` function (removed ad-hoc debug logging)
- **Admin Menu Structure** - Added Health Check submenu item
- **Activation Hook** - Now properly placed outside `plugins_loaded` for correct execution

### 🗑️ Deprecated

- **Direct File Logging** - Use `wpllmseo_log()` instead of `file_put_contents()`
- **Legacy Token Format** - Old token structure replaced with WordPress-native implementation

### 🐛 Known Issues

- **WP-Cron Dependency** - Background jobs require WP-Cron or system cron alternative
  - **Workaround**: Set up system cron (see PRODUCTION-README.md)
- **Local Environment DB Connection** - WP-CLI commands may fail in Local by Flywheel
  - **Workaround**: Use WordPress admin interface instead

---

## [1.0.0] - 2024-12-15

### Initial Development Release

- Basic plugin structure and WordPress integration
- Multi-provider LLM API support (Gemini, OpenAI, Claude)
- Embedding generation system
- Sitemap endpoints (JSONL/JSON formats)
- Admin dashboard and settings pages
- Database schema (8 tables)
- Queue and job runner system
- Bulk snippet generator
- MCP (Model Context Protocol) integration
- RAG (Retrieval-Augmented Generation) engine
- Semantic linking system
- Post panel integration
- Security audit logging

---

## Release Versioning Strategy

- **Major** (X.0.0): Breaking changes, major feature additions
- **Minor** (1.X.0): New features, non-breaking changes
- **Patch** (1.2.X): Bug fixes, security patches

---

## Upgrade Notes

### Upgrading to 1.2.0

1. **Backup Database** - Always backup before upgrading
   ```bash
   wp db export backup-before-1.2.0.sql
   ```

2. **Update Plugin Files** - Replace old plugin directory
   ```bash
   # Deactivate first
   wp plugin deactivate wp-llm-seo-indexing
   
   # Replace files (backup old version first)
   mv wp-llm-seo-indexing wp-llm-seo-indexing-1.0.0-backup
   # Upload new version
   
   # Reactivate
   wp plugin activate wp-llm-seo-indexing
   ```

3. **Flush Rewrite Rules** - CRITICAL after upgrade
   ```bash
   wp rewrite flush
   ```
   OR: Settings > Permalinks > Save Changes

4. **Run Health Check** - Verify all systems operational
   - Navigate to AI SEO & Indexing > Health Check
   - Ensure all checks pass (green checkmarks)

5. **Test Sitemap Endpoints** - Verify URLs accessible
   - Click endpoint links in Health Check
   - Should return 200 OK (not 404)

6. **Verify API Providers** - Re-save if needed
   - API SEO & Indexing > API Providers
   - Click "Test Connection" for each provider
   - Save if prompted

---

## Support

- **Bugs**: [GitHub Issues](https://github.com/yourusername/wp-llm-seo-indexing/issues)
- **Security**: See SECURITY.md for vulnerability reporting
- **Documentation**: [Full Docs](https://theworldtechs.com/wp-llm-seo-indexing/docs)

---

**Plugin Repository**: https://github.com/yourusername/wp-llm-seo-indexing  
**WordPress Plugin Page**: https://wordpress.org/plugins/wp-llm-seo-indexing/ (pending)
