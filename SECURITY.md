# Security

Implemented (each has automated tests unless marked *manual*):

* PDO prepared statements everywhere; SQL-injection attempts against login, search, check and admin filters tested.
* Output escaping (`e()`); XSS payloads in names/series/queries tested. Strict CSP (`script-src 'self'`, no inline script/style — enforced by a test), X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS in production.
* CSRF token on every POST (constant-time compare); unsafe-method tests for user + admin.
* Passwords: `password_hash(PASSWORD_DEFAULT)`; tokens: 256-bit random, only SHA-256 stored, expiring (verify 24 h, reset 1 h), single-use. Generic messages avoid account enumeration. Login/registration/reset/public-check/bond-import rate limits (DB-backed, shared-hosting friendly). Session ID regeneration on login, HttpOnly + SameSite=Lax cookie, `Secure` in production/HTTPS, `use_strict_mode`. Suspension takes effect on the next request.
* Authorisation: server-side per-permission middleware (`super_admin`, `result_manager`, `support_admin`), per-action checks on import actions; bonds are scoped by `user_id` in SQL (IDOR returns 404). Admin accounts are separate from user accounts; no default admin password; admin passwords ≥12 chars with mixed case + digit.
* Uploads: finfo MIME + `%PDF-` magic, size caps (8 MB PDF, 1 MB CSV), random stored names, stored outside `public/`, CSV never executed, CSV export neutralises formula injection.
* Outbound fetching: https only, host allow-list (`SOURCE_ALLOWED_HOSTS`), private/loopback IP rejection after DNS, manual re-validated redirects (max 3), size cap, timeouts, TLS verification on, retries only for transient errors.
* Secrets: `.env` only; logs scrub configured secrets; audit log redacts password/token/secret keys; backups outside web root, mode 0600.
* Apache/Nginx rules deny dot-files, directory listing and script execution in `uploads/`; root `.htaccess` denies everything if the project root is ever exposed (verified on Apache: 403).

*Manual/not automated:* TLS/HSTS and `Secure` cookie flag behaviour on a real HTTPS host; web-server hardening; SMTP SPF/DKIM/DMARC; dependency CVE monitoring (`composer audit`).

Report vulnerabilities privately to the site operator. See `PRODUCTION_CHECKLIST.md`.
