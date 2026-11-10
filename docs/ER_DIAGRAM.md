# Entity-relationship diagram

```mermaid
erDiagram
  users ||--|| user_settings : has
  users ||--o{ email_verification_tokens : has
  users ||--o{ password_reset_tokens : has
  users ||--o{ prize_bonds : registers
  bond_types ||--o{ prize_bonds : classifies
  bond_types ||--o{ prize_tiers : defines
  bond_types ||--o{ prize_draws : "draws of"
  bond_types ||--o{ result_imports : ""
  result_sources ||--o{ result_imports : provides
  result_sources ||--o{ prize_draws : "source of"
  result_imports }o--o| prize_draws : "published as"
  result_imports ||--o{ import_logs : logs
  prize_draws ||--o{ winning_numbers : contains
  prize_tiers ||--o{ winning_numbers : "prize rank"
  prize_draws ||--o{ draw_corrections : "versions"
  prize_bonds ||--o{ prize_matches : "matched by"
  winning_numbers ||--o{ prize_matches : "wins"
  prize_draws ||--o{ prize_matches : ""
  users ||--o{ notification_queue : receives
  prize_draws ||--o{ notification_queue : "alerts for"
  notification_queue ||--o{ notification_logs : "attempts"
  admin_roles ||--o{ admin_users : has
  admin_roles ||--o{ admin_role_permissions : grants
  admin_permissions ||--o{ admin_role_permissions : ""
  admin_users ||--o{ audit_logs : performs
  admin_users ||--o{ draw_corrections : makes
```
Standalone: `system_settings`, `cron_job_logs`, `job_locks`, `failed_jobs`, `rate_limits`, `migrations`.

Key constraints: `users.email` unique; `prize_bonds (user, type, series, number)` unique; `prize_draws (bond_type, draw_number)` unique;
`winning_numbers (draw, tier, number, series)` unique; `prize_matches (bond, winning_number)` unique (a bond may match several tiers in one draw);
`notification_queue.idempotency_key` unique. Bond numbers are VARCHAR(16) everywhere. All timestamps UTC.
