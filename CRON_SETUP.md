# Cron setup

All jobs are CLI-only, take a database lock (no overlap; crashed locks expire), log start/finish/duration to `cron_job_logs`
(visible at **Admin → Cron Job Monitor**) and exit `0` ok / `1` failed / `2` skipped (already running).
Times below are cron server time; set the server timezone to Asia/Dhaka for human-friendly schedules (the app itself uses UTC).

| Job | Schedule | Purpose |
|---|---|---|
| `monitor_sources.php` | every 6 h (`0 */6 * * *`) | check Bangladesh Bank, fetch new draw PDFs. Honours `result_sources.check_interval_minutes`; `--force` ignores it |
| `process_imports.php` | every 15 min | parse + validate fetched documents |
| `verify_results.php` | every 15 min | IRD cross-check (≤10 imports per run, polite pacing) |
| `run_matching.php` | every 15 min | match published draws, queue one email per user per draw |
| `send_notifications.php` | every 5 min | deliver queued mail in batches (`mail.batch_size`) |
| `retry_failed_jobs.php` | every 30 min | re-arm retryable failures, reset failed imports, delivery pass |
| `daily_maintenance.php` | daily 03:00 | purge expired tokens/rate limits/old logs, delete stale unverified accounts, **database backup** |

## crontab (VPS)
```
0 */6 * * *    cd /var/www/prizebond && /usr/bin/php cron/monitor_sources.php   >> storage/logs/cron.log 2>&1
*/15 * * * *   cd /var/www/prizebond && /usr/bin/php cron/process_imports.php   >> storage/logs/cron.log 2>&1
*/15 * * * *   cd /var/www/prizebond && /usr/bin/php cron/verify_results.php    >> storage/logs/cron.log 2>&1
*/15 * * * *   cd /var/www/prizebond && /usr/bin/php cron/run_matching.php      >> storage/logs/cron.log 2>&1
*/5 * * * *    cd /var/www/prizebond && /usr/bin/php cron/send_notifications.php >> storage/logs/cron.log 2>&1
*/30 * * * *   cd /var/www/prizebond && /usr/bin/php cron/retry_failed_jobs.php >> storage/logs/cron.log 2>&1
0 3 * * *      cd /var/www/prizebond && /usr/bin/php cron/daily_maintenance.php >> storage/logs/cron.log 2>&1
```
## cPanel
Cron Jobs → add the same lines using the full PHP path shown by the host (e.g. `/usr/local/bin/php /home/USER/prizebond/cron/send_notifications.php`).
If the host limits to few jobs, run `run_matching`+`send_notifications` every 5 min and everything else hourly — all jobs are idempotent.

Never expose `cron/` through the web server. Check health: Admin → Cron Job Monitor, and `storage/logs/`.
