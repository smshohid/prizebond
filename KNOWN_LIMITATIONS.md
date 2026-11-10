# Known limitations (honest list)

1. **PHP version:** developed/tested on PHP 8.2.12 (the only runtime available); composer requires `>=8.2`. No 8.3-only features are used, but it has not been run on 8.3. MariaDB 10.4 tested; MySQL 8 not tested here (SQL is standard; JSON columns used).
2. **Real SMTP never exercised** (no credentials). PHPMailer transport is implemented; all tests use an in-memory transport and a log transport. Verify with your provider before launch.
3. **HTTPS/HSTS/Secure cookie** logic exists but was only verifiable over HTTP locally.
4. **IRD** is a look-up form, not a result feed: cross-check is per number (≈5 polite requests per draw). Observed live: IRD matched draws 118/124/125, lacks any data for 115–117 (→ "not covered", manual verify), is missing one number of draw 123, 16 of draw 119 and has a prize-amount typo for draw 120 (→ **disputed**, correctly blocked). Bangladesh Bank prevails; the admin can override with an audited reason.
5. **Official date quirks:** listing label vs PDF date differ for draws 121 (31 Oct vs 2 Nov 2025) and 122 (31 Jan vs 1 Feb 2026) → these import as *review_required* by design. The date printed in the PDF is used after admin confirmation.
6. **Legacy-font PDFs:** Bangla words are unreadable; parser relies on digits/amount structure and a month-token map (Feb, Apr, Jul, Aug, Nov observed; other months are best-effort guesses and fall back to the listing date + admin confirmation). Series count is not parsed for draws 115/117/119 (warning only). Layout changes → `review_required`; manual PDF upload and manual entry always work.
7. **Eligibility rule conflict** (60 days vs 2 calendar months) is surfaced as `uncertain`, never auto-rejected; tax (20% IRD vs 25% PDF text) is displayed as a note only, not computed. Confirm with Bangladesh Bank.
8. Only the Tk.100 bond is enabled; other denominations require a verified official format before they can be enabled.
9. Excel (.xlsx) import is not implemented (no suitable library installed); CSV is. Export is CSV.
10. Ambiguous SMTP outcomes (timeout after DATA) for winning alerts go to `review` and are never auto-resent — an admin must decide; this trades possible non-delivery for no duplicates.
11. Bangla UI is complete for user flows and emails; admin panel and a few meta descriptions are English only.
12. No remote deployment was performed.
