# Production readiness checklist

- [ ] Document root is `public/`; `https://host/.env`, `/storage/`, `/vendor/`, `/app/` are 403/404
- [ ] `APP_ENV=production`, strong `APP_KEY`, `display_errors=Off`, `expose_php=Off`
- [ ] HTTPS valid; HTTP redirects; HSTS present; session cookie shows `Secure`
- [ ] DB user has only privileges on its own database; DB not publicly reachable
- [ ] SMTP credentials set; SPF/DKIM/DMARC configured; test verification + reset + (admin-triggered) alert mails arrive
- [ ] `MAIL_TRANSPORT=smtp` (not `log`); `mail.admin_alert_email` set in System Settings
- [ ] First super-admin created via CLI; no shared admin accounts
- [ ] All 7 cron jobs scheduled; Cron Monitor shows recent successes; backup file appears daily
- [ ] Restore test performed; off-site backup copy configured
- [ ] `php bin/lint.php && vendor/bin/phpunit` green on the deployed code (test DB only!)
- [ ] `composer audit` clean; PHP/MariaDB patched
- [ ] Log rotation for `storage/logs`
- [ ] `imports.auto_publish` left at 0 unless you accept IRD-agreement-only publication
- [ ] Contact email set (`CONTACT_EMAIL`), privacy/terms reviewed by the operator's counsel
- [ ] First real draw run end-to-end: monitor → review → publish → matching → email to a test account
