# Prize Bond Monitor BD

Free, public Bangladesh prize bond monitoring site: users register bond numbers; the system watches the official
Bangladesh Bank draw results, validates and cross-checks them, matches registered bonds and emails a summary when a
published result matches. Informational only — not affiliated with any government body, no payments, no prize money.

* PHP 8.2+ (8.3 recommended), MySQL 8 / MariaDB 10.4+, Apache or Nginx, Composer
* Bootstrap 5 (vendored, no CDN), English + Bangla, PHPMailer, smalot/pdfparser, PHPUnit

## What the official sources actually look like (verified 10 Nov 2026)

* **Bangladesh Bank** lists one PDF per draw (`.../investfacility/prizebond/125thdraw.pdf`). PDFs use a legacy Bangla font;
  digits are plain ASCII. Tk.100 draws are a **single common-number system**: 46 seven-digit numbers
  (1st Tk 6,00,000 ×1; 2nd 3,25,000 ×1; 3rd 1,00,000 ×2; 4th 50,000 ×2; 5th 10,000 ×40), each winning in **every series**.
* **IRD PBRIS** (`prizebond.ird.gov.bd`) is a number look-up form (no bulk listing); it also says "general number, without series".
* Therefore the matching rule is: *same bond type + same 7-digit number*; series is stored but does not affect matching
  (it is configurable per bond type: `series_affects_matching`).
* The two sources describe the minimum holding period differently (60 days vs 2 calendar months) — see `EligibilityService`.

## Quick start (local)

```bash
composer install
cp .env.example .env            # edit DB_* and MAIL_*
php bin/install.php --create-db # checks, migrations, seeds, creates first super-admin (prompted)
php -S localhost:8080 -t public # or point Apache/Nginx document root at public/
php cron/monitor_sources.php --force && php cron/process_imports.php && php cron/verify_results.php
```
Admin panel: `/admin`. No default credentials exist. See `DEPLOYMENT.md`, `CRON_SETUP.md`, `SECURITY.md`,
`BACKUP_RESTORE.md`, `TEST_REPORT.md`, `KNOWN_LIMITATIONS.md`, `PRODUCTION_CHECKLIST.md`.

## Workflow

`discovered → fetched → parsed → staged → (review_required | verified | disputed) → published → matched → notifications_queued → completed`

1. `monitor_sources` finds new draw PDFs, stores them untouched with SHA-256 (never sends mail).
2. `process_imports` parses + validates (counts per prize, formats, duplicates, amounts, dates vs listing).
3. `verify_results` cross-checks numbers with IRD: *agree* → verified; *mismatch* → **disputed, publication blocked**;
   *IRD has no data for the draw* → stays staged for manual verification.
4. An administrator reviews/edits, verifies and publishes (auto-publish is off by default).
5. `run_matching` (idempotent) → one queued email per user per draw → `send_notifications` (retry, backoff, logs).

## Layout

`app/` Core (kernel, DB, session), Controllers, Services (importers, matching, mail…), Middleware · `routes/` · `resources/views|lang` ·
`database/migrations|schema.sql` · `cron/` · `bin/` (install, migrate, create-admin, lint) · `tests/` · `storage/` (private) · `public/` (only web root).
