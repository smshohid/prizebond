# Test report (executed 10 Nov 2026, PHP 8.2.12, MariaDB 10.4.32, PHPUnit 10.5)

| Command | Result |
|---|---|
| `php bin/lint.php` (syntax of all 114 project PHP files) | 0 errors |
| `composer validate` | valid; `composer.lock` refreshed |
| `vendor/bin/phpunit` (all) | **OK — 191 tests, 2143 assertions, 0 failures, 0 warnings** |
| Unit suite | 58 tests / 1216 assertions |
| Integration suite | 71 tests / 379 assertions |
| Feature suite | 62 tests / 548 assertions |
| Migration test (from empty DB, rollback, re-apply, idempotent re-run) | pass |
| Cron scripts executed as real CLI processes against the test DB | pass |
| Live run against the real Bangladesh Bank + IRD sites (dev DB, not in PHPUnit) | pass — see below |
| Real Apache (XAMPP) request checks of `.htaccess`, asset serving, denied paths | pass after fixing one bug |

Tests use the isolated database `prizebond_test` (bootstrap refuses any DB name without "test"), never send mail (in-memory transport) and
never touch the network (HTTP fake) — except where stated.

**Coverage by requirement:** number normalisation/leading zeros/Bangla digits; series/date validation; eligibility (rules, boundaries, configuration, month-end);
parsing of **11 real official PDFs (draws 115–125)**; validator (count, duplicate, format, amount, missing tier, empty parse, unverified bond type);
IRD adapter on a real captured response; BB listing on a real captured page; SSRF (10 forbidden URL classes, redirect re-validation);
matching (14 cases incl. all listed in the brief, idempotency, batching, series flag); import workflow (staged/review/verify/dispute/override/publish atomicity/duplicate draw/changed documents/corrections with retraction/manual entry/source monitor);
notifications (one mail per user per draw, no duplicates over repeated runs, retry/backoff, give-up, ambiguous timeout → review, stuck rows, opt-out/suspended/unverified, Bangla mail);
auth (registration, verification single-use/expiry, login/logout, reset flow, enumeration-safe responses, rate limits, suspension);
user area (CRUD, bulk import report, IDOR, export, profile); admin RBAC (every role, direct-POST bypass attempts, CSRF); security (CSRF, XSS, SQLi, headers, no inline script/style, private files, secrets);
cron (7 scripts, locks, exit codes, maintenance, backup + retention + public-dir refusal); full end-to-end scenario (13 steps from the brief).

**Defects found by testing and fixed:** DashboardController not autoloadable; `/how-it-works` etc. routed to 404; ambiguous-timeout regex missed "timed out";
tier-code int/string mismatch in validator; test-isolation leak of seeded prize amounts; **parser failed on draw 115's wrapped prize heading** (found live);
IRD "no data for this draw" wrongly reported as dispute (found live); `.htaccess` `<Directory>` block caused HTTP 500 on real Apache.

**Live external verification (10 Nov 2026):** BB listing fetched over verified TLS (11 draws); all 11 PDFs downloaded; 11/11 parse after fix;
IRD cross-check: agree 118/124/125, disputed 119/120/123 (real IRD gaps/typo), not covered 115–117; 121/122 → review_required (listing vs PDF date).
**Not verified:** real SMTP delivery, HTTPS on a public host, MySQL 8, PHP 8.3.
