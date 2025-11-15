# Queue & Token Optimization Update

## Overview

This update fixes the queue page buttons and implements comprehensive token optimization with 24-hour cooldowns to prevent excessive API usage and costs.

## Changes Made

### 1. Fixed Queue Page Buttons ✅

**Issue**: The "Process Queue" and "Clear Completed" buttons were not working.

**Fix**: 
- Updated JavaScript to properly send POST requests with JSON body
- Added proper error handling and cooldown notifications
- Improved user feedback with better success/warning/error messages

### 2. Optimized Cron Schedule ✅

**Before**: Worker ran every minute (1,440 times per day!)
**After**: Worker runs once daily at 2:00 AM

**Changes**:
- `wpllmseo_worker_event`: Now runs daily at 2:00 AM (was: every minute)
- `wpllmseo_generate_ai_sitemap_daily`: Remains daily at 3:00 AM
- `wpllmseo_cleanup_expired_tokens`: Remains daily at 4:00 AM

### 3. Implemented 24-Hour Cooldown ✅

**Manual Worker Runs**:
- Enforces 24-hour cooldown between manual "Process Queue" button clicks
- Shows clear warning message with next available run time
- Prevents excessive API calls from repeated manual triggers

**Cron Worker Runs**:
- Checks last run timestamp before processing
- Skips execution if less than 24 hours since last run
- Logs cooldown status for transparency

### 4. Token Usage Optimization ✅

**New Token Tracker System**:
- Created `WPLLMSEO_Token_Tracker` class to monitor token usage
- Tracks daily token consumption
- Enforces configurable daily token limit (default: 100,000)
- Prevents worker from processing if limit would be exceeded

**Optimizations**:
- Reduced default batch size from 10 to 5 items
- Worker checks token usage before each job
- Stops processing if daily limit reached
- Removed automatic job triggering (was triggering on every job add)

**New Setting**:
- Added "Daily Token Limit" field in Settings page
- Shows current token usage and percentage
- Default: 100,000 tokens per day
- Prevents runaway API costs

### 5. Process Optimizations ✅

**Worker Process**:
- Reduced cron batch limit from 3 to 5 jobs (but runs once daily)
- Reduced manual batch limit from 10 to 5 jobs
- Added token usage tracking to worker logs
- Improved error handling and logging

**Queue Management**:
- Removed instant worker triggering on job creation
- Jobs now wait for scheduled cron run
- Prevents rapid-fire API calls
- Better for rate limiting

## Migration Steps

### For Existing Installations

Run the migration script to update cron schedules:

```bash
cd wp-content/plugins/wp-llm-seo-indexing
wp eval-file migrate-cron-schedule.php
```

Or manually:

1. The plugin will auto-detect and update on next activation
2. Check Settings → Daily Token Limit is set (default: 100,000)
3. Verify cron jobs in System → Scheduled Events

### What Gets Reset

- Worker cron schedule (from every minute to daily)
- Cooldown timestamps (fresh start)
- Token usage tracking (starts at 0)

## New Features

### Token Usage Dashboard

Settings page now shows:
- Current daily token usage
- Percentage of daily limit used
- Real-time tracking

### Cooldown Notifications

Queue page shows:
- Warning when cooldown is active
- Time until next run available
- Clear explanation of token optimization

### Enhanced Logging

New log files:
- `token-usage.log`: Tracks all token consumption
- `worker.log`: Now includes token usage percentages

## Configuration

### Settings Page

**Daily Token Limit**:
- Location: Settings → Indexing Options
- Default: 100,000 tokens
- Range: 1,000 to 10,000,000
- Recommended: 50,000 - 200,000 for most sites

**Batch Size**:
- Recommended: 5-10 items
- Lower values = less tokens per run
- Higher values = faster processing (but more tokens)

### Cooldown Bypass

For emergencies or testing:

```php
// Bypass cooldown (admin only)
$worker = new WPLLMSEO_Worker();
$result = $worker->run( 10, true ); // true = bypass cooldown
```

## Benefits

### Cost Savings 💰
- **Before**: Up to 1,440 worker runs per day (every minute)
- **After**: 1 worker run per day
- **Savings**: ~99.9% reduction in cron executions

### Token Control 🎯
- Daily token limits prevent runaway costs
- Real-time usage tracking
- Automatic throttling when limit approached

### Better Resource Usage ⚡
- No more every-minute cron jobs
- Scheduled maintenance windows (2-4 AM)
- Reduced server load

### User Protection 🛡️
- Can't accidentally trigger excessive API calls
- Clear feedback about cooldowns
- Transparent token usage

## Troubleshooting

### Buttons Still Not Working?

1. Clear browser cache
2. Hard refresh (Cmd+Shift+R or Ctrl+Shift+F5)
3. Check browser console for errors
4. Verify REST API is accessible: `/wp-json/wp-llmseo/v1/run-worker`

### Need to Run Worker Immediately?

Use WP-CLI (bypasses cooldown):

```bash
wp wpllmseo run-worker --limit=5
```

### Token Limit Too Low?

1. Go to Settings → Daily Token Limit
2. Increase value (e.g., 200,000)
3. Save settings
4. Token usage resets daily at midnight UTC

### Want to Reset Cooldown?

```bash
wp option delete wpllmseo_worker_last_run
wp option delete wpllmseo_worker_last_manual_run
```

## Files Changed

### Core Files
- `includes/class-installer-upgrader.php` - Cron scheduling
- `includes/class-worker.php` - Cooldown logic
- `includes/class-worker-rest.php` - API endpoint updates
- `includes/class-queue.php` - Removed auto-triggering
- `includes/class-admin.php` - Settings handling

### Frontend
- `admin/assets/js/admin.js` - Button fixes and notifications
- `admin/screens/settings.php` - Token limit field
- `admin/screens/queue.php` - No changes needed

### New Files
- `includes/class-token-tracker.php` - Token usage tracking
- `migrate-cron-schedule.php` - Migration script

## API Changes

### Worker REST Endpoint

**Before**:
```javascript
POST /wp-json/wp-llmseo/v1/run-worker
// No body
```

**After**:
```javascript
POST /wp-json/wp-llmseo/v1/run-worker
{
  "limit": 5,
  "bypass_cooldown": false
}
```

**Response** (with cooldown):
```json
{
  "success": false,
  "message": "Worker cooldown active. Please wait 23 hours...",
  "data": {
    "processed": 0,
    "failed": 0,
    "cooldown_active": true,
    "next_run": "2025-11-16 14:30:00"
  }
}
```

## Testing

### Verify Installation

1. Check Settings page loads
2. Verify "Daily Token Limit" field appears
3. Click "Process Queue" button
4. Should show success or cooldown message
5. Click again - should show cooldown warning

### Verify Cron Schedule

```bash
wp cron event list --format=table
```

Should show:
- `wpllmseo_worker_event` - daily
- `wpllmseo_generate_ai_sitemap_daily` - daily  
- `wpllmseo_cleanup_expired_tokens` - daily

### Verify Token Tracking

1. Run worker manually
2. Check Settings page
3. Should see token usage updated
4. Check `var/logs/token-usage.log`

## Support

For issues or questions:
1. Check `var/logs/worker.log` for worker status
2. Check `var/logs/token-usage.log` for token tracking
3. Verify cron jobs are scheduled properly
4. Ensure REST API is accessible

## Future Enhancements

Potential improvements:
- Configurable cooldown period (not just 24 hours)
- Per-user token limits (multi-site)
- Token usage graphs/charts
- Email alerts at 80% token usage
- Automatic batch size adjustment based on token availability
