# WP LLM SEO & Indexing - Production Deployment Checklist

## ✅ Pre-Deployment Verification

### Code Quality
- [x] All PHP files have valid syntax (no parse errors)
- [x] Backup files removed (.bak, .bak2, etc.)
- [x] No hardcoded API keys or sensitive data
- [x] Debug logging respects WP_DEBUG constant
- [x] Error messages are user-friendly and sanitized
- [x] All AJAX endpoints return JSON (not HTML)

### Security
- [x] All user inputs sanitized and validated
- [x] Nonce verification on all AJAX/form submissions
- [x] Capability checks enforced (manage_options, wpllmseo_* caps)
- [x] SQL queries use $wpdb->prepare()
- [x] Output escaped with esc_html(), esc_attr(), esc_url()
- [x] Log directory protected with .htaccess
- [x] API keys stored encrypted in database

### Assets
- [x] CSS extracted to external files (no inline styles)
- [x] JavaScript extracted to external files (no inline scripts)
- [x] Defensive file_exists() checks before filemtime()
- [x] Assets enqueued with proper dependencies
- [x] Gutenberg panel uses wp.element.createElement (no JSX)

### File Structure
```
wp-llm-seo-indexing/
├── .gitignore                     ✅ Created
├── README.md                      ✅ Exists
├── DEPLOYMENT-CHECKLIST.md        ✅ This file
├── wp-llm-seo-indexing.php       ✅ Main plugin file
├── uninstall.php                  ✅ Cleanup on uninstall
├── admin/
│   ├── assets/
│   │   ├── css/                   ✅ Separated styles
│   │   └── js/                    ✅ Separated scripts
│   ├── components/                ✅ Reusable UI
│   └── screens/                   ✅ Admin pages
├── includes/
│   ├── class-*.php               ✅ Core classes
│   ├── providers/                 ✅ LLM providers
│   └── mcp/                       ✅ MCP integration
├── tests/                         ✅ Test files (excluded from production)
├── var/
│   ├── cache/                     ✅ Protected with index.php
│   └── logs/                      ✅ Protected with .htaccess
└── wp-cli/                        ✅ CLI commands
```

## 🚀 Deployment Steps

### 1. Environment Preparation
- [ ] WordPress 6.0+ installed
- [ ] PHP 8.1+ available
- [ ] Database backup created
- [ ] Test on staging environment first

### 2. Plugin Installation
```bash
# Upload plugin to WordPress
wp plugin install /path/to/wp-llm-seo-indexing.zip

# Or via FTP/SSH
# Upload to: wp-content/plugins/wp-llm-seo-indexing/

# Activate plugin
wp plugin activate wp-llm-seo-indexing
```

### 3. Initial Configuration
- [ ] Navigate to **WP LLM SEO** → **Settings**
- [ ] Enter Google Gemini API key
- [ ] Configure indexing options
- [ ] Set up cron jobs (automatic on activation)
- [ ] Test snippet generation on a sample post

### 4. Post-Deployment Verification
```bash
# Check plugin status
wp plugin list | grep wp-llm-seo-indexing

# Verify database tables created
wp db query "SHOW TABLES LIKE 'wp_llmseo_%'"

# Test worker queue
wp llmseo worker process

# Check cron jobs
wp cron event list | grep wpllmseo
```

### 5. Testing Checklist
- [ ] Create/edit a post → snippet generates successfully
- [ ] Click "Index this post" → no JavaScript errors
- [ ] Click "Regenerate" button → AJAX returns JSON
- [ ] Semantic linking suggestions load correctly
- [ ] AI Sitemap accessible at `/ai-sitemap.jsonl`
- [ ] MCP endpoints return valid JSON
- [ ] RAG search returns relevant results
- [ ] No PHP errors in error logs
- [ ] No JavaScript console errors

### 6. Performance Optimization
- [ ] Enable object caching (Redis/Memcached)
- [ ] Configure WP-Cron or use real cron
- [ ] Set appropriate rate limits
- [ ] Monitor queue processing time
- [ ] Review log rotation settings

## 🔒 Security Hardening

### Production Environment
```php
// wp-config.php settings
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
```

### File Permissions
```bash
# Plugin directory
chmod 755 wp-llm-seo-indexing/

# PHP files
find wp-llm-seo-indexing/ -type f -name "*.php" -exec chmod 644 {} \;

# Log directory (writable by web server)
chmod 755 var/logs/
chmod 644 var/logs/*.log

# Cache directory (writable by web server)
chmod 755 var/cache/
```

### Database Security
- [ ] Use strong database passwords
- [ ] Limit database user privileges
- [ ] Enable SSL for database connections
- [ ] Regular database backups

## 📊 Monitoring

### Key Metrics to Track
- Queue processing time
- Snippet generation success rate
- API call volume and costs
- Error log frequency
- Cache hit rate

### Log Files
```bash
# View recent errors
tail -f var/logs/errors.log

# Check worker activity
tail -f var/logs/worker.log

# Monitor queue
tail -f var/logs/queue.log

# Review snippet generation
tail -f var/logs/snippet.log
```

### Performance Monitoring
```bash
# Check queue status
wp llmseo queue stats

# View worker stats
wp llmseo worker stats

# Check indexing status
wp eval "var_dump(WPLLMSEO_Snippets::get_snippet_count());"
```

## 🔄 Maintenance

### Regular Tasks
- **Daily**: Review error logs
- **Weekly**: Check queue health, clear old logs
- **Monthly**: Database optimization, backup verification
- **Quarterly**: Review API costs, performance audit

### Update Procedure
1. Backup database and files
2. Test update on staging
3. Deactivate plugin
4. Replace plugin files
5. Reactivate plugin
6. Clear caches
7. Test critical functionality

## 🐛 Troubleshooting

### Common Issues

**"Index this post" returns 500 error**
- Check PHP error logs
- Verify asset files exist in admin/assets/
- Ensure nonces are configured correctly

**Snippets not generating**
- Verify Gemini API key is valid
- Check error logs for API responses
- Ensure worker cron is running

**JavaScript errors in console**
- Hard refresh browser (Cmd+Shift+R / Ctrl+Shift+F5)
- Check network tab for 404s on assets
- Verify wp_enqueue_script/style calls

**AJAX returns HTML instead of JSON**
- Check for PHP notices/warnings
- Verify check_ajax_referer() not dying
- Review error_log output

## 📞 Support

For issues or questions:
- Review README.md documentation
- Check error logs in var/logs/
- Enable WP_DEBUG for detailed errors
- Contact: support@theworldtechs.com

## ✨ Production Ready

When all items are checked:
- All tests passing
- No errors in logs
- Performance acceptable
- Security verified
- Documentation complete

**Status**: Ready for Production ✅
**Version**: 1.0.0
**Date**: November 15, 2025
