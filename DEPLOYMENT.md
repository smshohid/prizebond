# Deployment

Nothing here was deployed to a remote server; the application was installed and verified locally (XAMPP: Apache, MariaDB 10.4, PHP 8.2.12).
**Document root must be `public/`.** Never expose the project root.

## A. Shared hosting (cPanel)

1. Create a MySQL database + user (utf8mb4). Note host/name/user/password.
2. Choose a layout:
   * **Preferred:** set the domain's document root to `…/prizebond/public`.
   * **If the host forces `public_html`:** upload the project to `/home/USER/prizebond/` (outside the web root) and put only
     the *contents of `public/`* in `public_html/`. Edit `public_html/index.php`:
     `define('BASE_PATH', '/home/USER/prizebond');` and change the two `require` paths to use it. Keep `.htaccess` and `assets/`.
     Never place `.env`, `app/`, `storage/`, `vendor/` under `public_html`.
3. Upload files (or `git clone`), then `composer install --no-dev --optimize-autoloader` (SSH, or run locally and upload `vendor/`).
4. `cp .env.example .env`; set `APP_ENV=production`, `APP_URL=https://yourdomain`, `APP_KEY` (random 64 hex), DB_*, MAIL_*.
   `chmod 640 .env`.
5. `php bin/install.php` — verifies PHP/extensions/DB, creates storage dirs, runs migrations, seeds the Tk.100 bond type and
   the official prize structure, prompts for the first super-admin (12+ chars). Safe to re-run.
6. Enable HTTPS (AutoSSL/Let's Encrypt). The app redirects HTTP→HTTPS in production and sets HSTS + Secure cookies.
7. Cron: see `CRON_SETUP.md`. 8. Test: `php cron/monitor_sources.php --force`, then log in at `/admin`, upload/inspect imports.
9. Email test: register a user; verification mail must arrive. Check `/admin/email-queue` and `/admin/email-logs`.
10. Verify `storage/` is writable by PHP and that `https://domain/.env`, `/storage/…`, `/vendor/…` return 403/404.

## B. VPS (Ubuntu/Debian)

```bash
apt install nginx php8.3-fpm php8.3-{cli,mysql,mbstring,curl,xml,zip,gd} mariadb-server composer git unzip
mysql -e "CREATE DATABASE prizebond CHARACTER SET utf8mb4; CREATE USER 'pb'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON prizebond.* TO 'pb'@'localhost';"
git clone … /var/www/prizebond && cd /var/www/prizebond && composer install --no-dev -o
cp .env.example .env && nano .env && php bin/install.php
chown -R www-data:www-data storage public/uploads && chmod -R u=rwX,g=rX,o= storage .env
```
Nginx:
```nginx
server {
  listen 443 ssl http2; server_name example.com;
  root /var/www/prizebond/public; index index.php;
  location / { try_files $uri /index.php?$query_string; }
  location ~ \.php$ { try_files $uri =404; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
  location ~ /\.(?!well-known) { deny all; }
  location ~* ^/uploads/.*\.(php|phtml|phar)$ { deny all; }
  client_max_body_size 9m;
}
server { listen 80; server_name example.com; return 301 https://$host$request_uri; }
```
Apache: `DocumentRoot /var/www/prizebond/public`, `AllowOverride All`, `mod_rewrite`, `mod_headers`.
TLS: `certbot --nginx -d example.com`. Firewall: `ufw allow 22,80,443/tcp; ufw enable`; MariaDB bound to localhost.
Logging: app logs in `storage/logs` (rotate with logrotate, 0640). Cron: `CRON_SETUP.md`. Backups: `BACKUP_RESTORE.md`.
PHP: `expose_php=Off`, `display_errors=Off`, `curl.cainfo` set to a CA bundle if cURL cannot verify TLS (or `HTTP_CA_BUNDLE` in `.env`).

## Updating
`git pull && composer install --no-dev -o && php bin/migrate.php` (migrations are versioned; `php bin/migrate.php rollback` reverts the last batch).
