# WP LLM SEO - Installation Guide

## Fresh Installation Steps

### Method 1: Using the Force Install Script (Recommended)

1. **Make sure you're logged into WordPress as an Administrator**

2. **Access the force install script in your browser:**
   ```
   http://llm-indexing.local/wp-content/plugins/wp-llm-seo-indexing-master/force-install.php
   ```
   (Replace `llm-indexing.local` with your actual site URL)

3. **The script will:**
   - Create necessary directories
   - Create all database tables
   - Initialize default settings
   - Add user capabilities
   - Schedule cron jobs
   - Set up the plugin completely

4. **Click the "Go to API Providers Settings" button** or navigate to:
   `WP Admin > LLM SEO > API Providers`

5. **Add your API key(s):**
   - For Gemini: Get your key from https://makersuite.google.com/app/apikey
   - For OpenAI: Get your key from https://platform.openai.com/api-keys
   - For Claude: Get your key from https://console.anthropic.com/

6. **Click "Save Provider Settings"**

7. **Click "Discover Models"** to verify your API key works

---

### Method 2: Normal WordPress Plugin Activation

If the force install script isn't needed:

1. Go to `WP Admin > Plugins`
2. Find "WP LLM SEO & Indexing"
3. Click "Deactivate" (if already active)
4. Click "Activate"
5. Go to `WP Admin > LLM SEO > API Providers`
6. Configure your API keys

---

## Verification

After installation, verify everything is working:

### Check Database Tables

Run this SQL query in phpMyAdmin or your database tool:

```sql
SHOW TABLES LIKE 'wp_wpllmseo%';
```

You should see these tables:
- `wp_wpllmseo_snippets`
- `wp_wpllmseo_chunks`
- `wp_wpllmseo_jobs`
- `wp_wpllmseo_jobs_dead_letter`
- `wp_wpllmseo_tokens`

### Check Settings

Go to `WP Admin > LLM SEO > Dashboard` - you should see the dashboard without errors.

---

## Troubleshooting

### Tables Not Created

1. Use the force install script (Method 1)
2. Check database user permissions - needs CREATE TABLE privilege
3. Check PHP error logs for database errors

### Settings Not Saving

1. Make sure you're using a valid API key (at least 10 characters)
2. The validation no longer requires API calls, so it should save immediately
3. Check browser console for JavaScript errors
4. Check that the form is actually submitting (look for redirect)

### Plugin Not Showing in Menu

1. Clear WordPress object cache
2. Deactivate and reactivate the plugin
3. Check user capabilities - you need `manage_options`

---

## What's Fixed

1. **Validation no longer blocks saves** - API keys are validated for format only, not by calling the API
2. **Activation hooks properly registered** - Now outside `plugins_loaded` hook
3. **Single initialization** - Removed duplicate `plugins_loaded` hooks
4. **Auto-upgrade on load** - Tables will be created/upgraded automatically

---

## Next Steps After Installation

1. **Configure API Provider** - Add at least one API key
2. **Create/Edit Posts** - The plugin will auto-index content
3. **Check Queue** - Go to `LLM SEO > Queue` to see indexing jobs
4. **View Dashboard** - Monitor embedding statistics
5. **Test Search** - Use the RAG API endpoint to test semantic search

---

## Support

If you encounter issues:

1. Check the `var/logs/plugin.log` file in the plugin directory
2. Enable WP_DEBUG in wp-config.php
3. Check browser console for JavaScript errors
4. Verify database tables exist and are not corrupted
