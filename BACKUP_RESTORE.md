# Backup & restore

**Automatic:** `daily_maintenance.php` runs `BackupService` (mysqldump `--single-transaction`, utf8mb4) → `BACKUP_DIR`
(default `storage/backups`, mode 0600, refused if inside `public/`). Credentials go through a temporary 0600 defaults file, never the command line.
Each dump is verified (`Dump completed` footer, minimum size). Retention: `backup.retention_days` (default 14). Failures are logged to `storage/logs/backup-*.log` and shown in the cron monitor.
Set `MYSQLDUMP_PATH` if `mysqldump` is not on PATH. Never email dumps.

**Manual:** `php -r "require 'bootstrap/app.php'; print_r((new App\Services\BackupService())->run());"`

**Also back up** `storage/imports/` (original official documents) and `.env` (store securely, separately).

**Off-site copy (no credentials in code):** use a host-level job outside the app, e.g. `rclone sync /var/www/prizebond/storage/backups remote:pbm-backups`
with the rclone config (`~/.config/rclone/rclone.conf`, chmod 600) or `restic`/`rsync -e ssh` with a key limited to that directory. Encrypt at rest (`rclone crypt`/`restic`).

**Restore**
```bash
mysql -u root -p -e "CREATE DATABASE prizebond_restore CHARACTER SET utf8mb4;"
mysql -u root -p prizebond_restore < storage/backups/prizebond_YYYYMMDD_HHMMSS.sql
# inspect, then point DB_DATABASE at it or restore into the live DB with the site in maintenance
php bin/migrate.php          # applies any newer migrations
```
Test a restore at least quarterly.
