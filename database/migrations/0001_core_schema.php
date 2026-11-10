<?php
/**
 * Core schema. MySQL 8.0+ / MariaDB 10.4+ compatible. All timestamps are UTC.
 * Bond numbers are ALWAYS VARCHAR to preserve leading zeros.
 */
$T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
'up' => [

"CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  mobile VARCHAR(30) NULL,
  status ENUM('active','suspended','deletion_requested','deleted') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  deletion_requested_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_status (status)
) $T",

"CREATE TABLE user_settings (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  language ENUM('en','bn') NOT NULL DEFAULT 'en',
  email_alerts TINYINT(1) NOT NULL DEFAULT 1,
  email_marketing TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) $T",

"CREATE TABLE email_verification_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_evt_hash (token_hash),
  KEY ix_evt_user (user_id),
  CONSTRAINT fk_evt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) $T",

"CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_prt_hash (token_hash),
  KEY ix_prt_user (user_id),
  CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) $T",

"CREATE TABLE bond_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(120) NOT NULL,
  name_bn VARCHAR(160) NULL,
  denomination DECIMAL(12,2) NOT NULL,
  number_length TINYINT UNSIGNED NOT NULL DEFAULT 7,
  series_applicable TINYINT(1) NOT NULL DEFAULT 1,
  series_required TINYINT(1) NOT NULL DEFAULT 0,
  series_affects_matching TINYINT(1) NOT NULL DEFAULT 0,
  source_verified TINYINT(1) NOT NULL DEFAULT 0,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_bt_code (code)
) $T",

"CREATE TABLE prize_bonds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  bond_type_id INT UNSIGNED NOT NULL,
  series VARCHAR(16) NOT NULL DEFAULT '',
  number VARCHAR(16) NOT NULL,
  purchase_date DATE NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_bond_user (user_id, bond_type_id, series, number),
  KEY ix_bond_match (bond_type_id, number, status),
  KEY ix_bond_user (user_id, status),
  CONSTRAINT fk_pb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pb_type FOREIGN KEY (bond_type_id) REFERENCES bond_types(id)
) $T",

"CREATE TABLE result_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(150) NOT NULL,
  base_url VARCHAR(500) NOT NULL,
  authority ENUM('primary','secondary','reference') NOT NULL DEFAULT 'primary',
  adapter VARCHAR(80) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  check_interval_minutes INT UNSIGNED NOT NULL DEFAULT 360,
  last_checked_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_rs_code (code)
) $T",

"CREATE TABLE prize_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bond_type_id INT UNSIGNED NOT NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(100) NOT NULL,
  name_bn VARCHAR(100) NULL,
  amount DECIMAL(14,2) NOT NULL,
  rank_order SMALLINT UNSIGNED NOT NULL,
  expected_count SMALLINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_tier_code (bond_type_id, code),
  KEY ix_tier_rank (bond_type_id, rank_order),
  CONSTRAINT fk_pt_type FOREIGN KEY (bond_type_id) REFERENCES bond_types(id)
) $T",

"CREATE TABLE prize_draws (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bond_type_id INT UNSIGNED NOT NULL,
  draw_number SMALLINT UNSIGNED NOT NULL,
  draw_date DATE NOT NULL,
  series_count SMALLINT UNSIGNED NULL,
  source_id INT UNSIGNED NULL,
  import_status VARCHAR(30) NOT NULL DEFAULT 'imported',
  verification_status ENUM('pending','verified','disputed','rejected') NOT NULL DEFAULT 'pending',
  publication_status ENUM('unpublished','published','withdrawn') NOT NULL DEFAULT 'unpublished',
  lifecycle ENUM('staged','published','matched','notifications_queued','completed') NOT NULL DEFAULT 'staged',
  source_url VARCHAR(500) NULL,
  source_file VARCHAR(500) NULL,
  source_checksum CHAR(64) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  matched_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  published_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_draw (bond_type_id, draw_number),
  KEY ix_draw_pub (publication_status, draw_date),
  KEY ix_draw_life (lifecycle),
  CONSTRAINT fk_draw_type FOREIGN KEY (bond_type_id) REFERENCES bond_types(id),
  CONSTRAINT fk_draw_src FOREIGN KEY (source_id) REFERENCES result_sources(id) ON DELETE SET NULL
) $T",

"CREATE TABLE winning_numbers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  draw_id BIGINT UNSIGNED NOT NULL,
  prize_tier_id INT UNSIGNED NOT NULL,
  number VARCHAR(16) NOT NULL,
  series VARCHAR(16) NOT NULL DEFAULT '',
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('active','void') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_wn (draw_id, prize_tier_id, number, series),
  KEY ix_wn_lookup (number),
  KEY ix_wn_draw (draw_id, status),
  CONSTRAINT fk_wn_draw FOREIGN KEY (draw_id) REFERENCES prize_draws(id) ON DELETE CASCADE,
  CONSTRAINT fk_wn_tier FOREIGN KEY (prize_tier_id) REFERENCES prize_tiers(id)
) $T",

"CREATE TABLE prize_matches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prize_bond_id BIGINT UNSIGNED NOT NULL,
  draw_id BIGINT UNSIGNED NOT NULL,
  winning_number_id BIGINT UNSIGNED NOT NULL,
  amount_snapshot DECIMAL(14,2) NOT NULL,
  eligibility_status ENUM('eligible','unknown','uncertain','ineligible') NOT NULL DEFAULT 'unknown',
  eligibility_note VARCHAR(255) NULL,
  match_status ENUM('detected','void') NOT NULL DEFAULT 'detected',
  notification_status ENUM('pending','queued','sent','failed','not_required') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_match (prize_bond_id, winning_number_id),
  KEY ix_match_draw (draw_id, notification_status),
  CONSTRAINT fk_pm_bond FOREIGN KEY (prize_bond_id) REFERENCES prize_bonds(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_draw FOREIGN KEY (draw_id) REFERENCES prize_draws(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_wn FOREIGN KEY (winning_number_id) REFERENCES winning_numbers(id) ON DELETE CASCADE
) $T",

"CREATE TABLE notification_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(40) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  draw_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  payload JSON NULL,
  status ENUM('pending','sending','sent','failed','cancelled','review') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  next_attempt_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  last_error TEXT NULL,
  idempotency_key VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_nq_key (idempotency_key),
  KEY ix_nq_due (status, next_attempt_at),
  KEY ix_nq_draw (draw_id),
  CONSTRAINT fk_nq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_nq_draw FOREIGN KEY (draw_id) REFERENCES prize_draws(id) ON DELETE SET NULL
) $T",

"CREATE TABLE notification_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(190) NOT NULL,
  type VARCHAR(40) NOT NULL,
  status ENUM('sent','failed') NOT NULL,
  attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  error TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_nl_queue (queue_id),
  KEY ix_nl_created (created_at),
  CONSTRAINT fk_nl_queue FOREIGN KEY (queue_id) REFERENCES notification_queue(id) ON DELETE SET NULL
) $T",

"CREATE TABLE admin_roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  UNIQUE KEY uq_role_name (name)
) $T",

"CREATE TABLE admin_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  UNIQUE KEY uq_perm_name (name)
) $T",

"CREATE TABLE admin_role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_arp_role FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_arp_perm FOREIGN KEY (permission_id) REFERENCES admin_permissions(id) ON DELETE CASCADE
) $T",

"CREATE TABLE admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id INT UNSIGNED NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_admin_email (email),
  CONSTRAINT fk_au_role FOREIGN KEY (role_id) REFERENCES admin_roles(id)
) $T",

"CREATE TABLE result_imports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id INT UNSIGNED NULL,
  bond_type_id INT UNSIGNED NOT NULL,
  draw_id BIGINT UNSIGNED NULL,
  draw_number SMALLINT UNSIGNED NULL,
  draw_date DATE NULL,
  source_url VARCHAR(500) NULL,
  file_path VARCHAR(500) NULL,
  file_checksum CHAR(64) NULL,
  file_format VARCHAR(20) NULL,
  parser_version VARCHAR(20) NULL,
  status ENUM('discovered','fetched','parsed','staged','review_required','verified','disputed','rejected','published','failed') NOT NULL DEFAULT 'discovered',
  origin ENUM('auto','manual_upload','manual_entry') NOT NULL DEFAULT 'auto',
  staged_data JSON NULL,
  validation_report JSON NULL,
  secondary_report JSON NULL,
  admin_id INT UNSIGNED NULL,
  error_message TEXT NULL,
  retrieved_at DATETIME NULL,
  imported_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_imp_status (status),
  KEY ix_imp_draw (bond_type_id, draw_number),
  CONSTRAINT fk_imp_src FOREIGN KEY (source_id) REFERENCES result_sources(id) ON DELETE SET NULL,
  CONSTRAINT fk_imp_type FOREIGN KEY (bond_type_id) REFERENCES bond_types(id),
  CONSTRAINT fk_imp_draw FOREIGN KEY (draw_id) REFERENCES prize_draws(id) ON DELETE SET NULL,
  CONSTRAINT fk_imp_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) $T",

"CREATE TABLE import_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_id BIGINT UNSIGNED NULL,
  level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  message TEXT NOT NULL,
  context JSON NULL,
  created_at DATETIME NOT NULL,
  KEY ix_il_import (import_id),
  CONSTRAINT fk_il_import FOREIGN KEY (import_id) REFERENCES result_imports(id) ON DELETE CASCADE
) $T",

"CREATE TABLE draw_corrections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  draw_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  previous_snapshot JSON NOT NULL,
  new_snapshot JSON NOT NULL,
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_dc (draw_id, version),
  CONSTRAINT fk_dc_draw FOREIGN KEY (draw_id) REFERENCES prize_draws(id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) $T",

"CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  actor VARCHAR(60) NOT NULL DEFAULT 'admin',
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(60) NOT NULL,
  entity_id VARCHAR(40) NULL,
  previous_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  KEY ix_audit_entity (entity, entity_id),
  KEY ix_audit_created (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) $T",

"CREATE TABLE system_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  description VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL
) $T",

"CREATE TABLE cron_job_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(60) NOT NULL,
  status ENUM('running','success','failed','skipped') NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  duration_ms INT UNSIGNED NULL,
  message TEXT NULL,
  KEY ix_cron_job (job_name, started_at)
) $T",

"CREATE TABLE job_locks (
  name VARCHAR(60) PRIMARY KEY,
  locked_until DATETIME NOT NULL,
  owner VARCHAR(80) NOT NULL
) $T",

"CREATE TABLE failed_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(60) NOT NULL,
  reference VARCHAR(80) NULL,
  payload JSON NULL,
  error TEXT NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY ix_fj_open (resolved_at, job_name)
) $T",

"CREATE TABLE rate_limits (
  rl_key VARCHAR(190) PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  KEY ix_rl_window (window_start)
) $T",
],

'down' => [
 'rate_limits','failed_jobs','job_locks','cron_job_logs','system_settings','audit_logs','draw_corrections','import_logs',
 'result_imports','admin_users','admin_role_permissions','admin_permissions','admin_roles','notification_logs',
 'notification_queue','prize_matches','winning_numbers','prize_draws','prize_tiers','result_sources','prize_bonds',
 'bond_types','password_reset_tokens','email_verification_tokens','user_settings','users',
],
];
