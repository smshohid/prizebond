
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_perm_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_arp_perm` (`permission_id`),
  CONSTRAINT `fk_arp_perm` FOREIGN KEY (`permission_id`) REFERENCES `admin_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arp_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`),
  KEY `fk_au_role` (`role_id`),
  CONSTRAINT `fk_au_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `actor` varchar(60) NOT NULL DEFAULT 'admin',
  `action` varchar(80) NOT NULL,
  `entity` varchar(60) NOT NULL,
  `entity_id` varchar(40) DEFAULT NULL,
  `previous_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`previous_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_audit_entity` (`entity`,`entity_id`),
  KEY `ix_audit_created` (`created_at`),
  KEY `fk_audit_admin` (`admin_id`),
  CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bond_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `name_bn` varchar(160) DEFAULT NULL,
  `denomination` decimal(12,2) NOT NULL,
  `number_length` tinyint(3) unsigned NOT NULL DEFAULT 7,
  `series_applicable` tinyint(1) NOT NULL DEFAULT 1,
  `series_required` tinyint(1) NOT NULL DEFAULT 0,
  `series_affects_matching` tinyint(1) NOT NULL DEFAULT 0,
  `source_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bt_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cron_job_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(60) NOT NULL,
  `status` enum('running','success','failed','skipped') NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_cron_job` (`job_name`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `draw_corrections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draw_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `previous_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`previous_snapshot`)),
  `new_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`new_snapshot`)),
  `admin_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dc` (`draw_id`,`version`),
  KEY `fk_dc_admin` (`admin_id`),
  CONSTRAINT `fk_dc_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dc_draw` FOREIGN KEY (`draw_id`) REFERENCES `prize_draws` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_verification_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evt_hash` (`token_hash`),
  KEY `ix_evt_user` (`user_id`),
  CONSTRAINT `fk_evt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_name` varchar(60) NOT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `error` text NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 1,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fj_open` (`resolved_at`,`job_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `import_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` bigint(20) unsigned DEFAULT NULL,
  `level` enum('info','warning','error') NOT NULL DEFAULT 'info',
  `message` text NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_il_import` (`import_id`),
  CONSTRAINT `fk_il_import` FOREIGN KEY (`import_id`) REFERENCES `result_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_locks` (
  `name` varchar(60) NOT NULL,
  `locked_until` datetime NOT NULL,
  `owner` varchar(80) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(190) NOT NULL,
  `batch` int(10) unsigned NOT NULL,
  `applied_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `type` varchar(40) NOT NULL,
  `status` enum('sent','failed') NOT NULL,
  `attempt` smallint(5) unsigned NOT NULL DEFAULT 1,
  `error` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_nl_queue` (`queue_id`),
  KEY `ix_nl_created` (`created_at`),
  CONSTRAINT `fk_nl_queue` FOREIGN KEY (`queue_id`) REFERENCES `notification_queue` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `draw_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` enum('pending','sending','sent','failed','cancelled','review') NOT NULL DEFAULT 'pending',
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `max_attempts` smallint(5) unsigned NOT NULL DEFAULT 5,
  `next_attempt_at` datetime NOT NULL,
  `locked_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `idempotency_key` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nq_key` (`idempotency_key`),
  KEY `ix_nq_due` (`status`,`next_attempt_at`),
  KEY `ix_nq_draw` (`draw_id`),
  KEY `fk_nq_user` (`user_id`),
  CONSTRAINT `fk_nq_draw` FOREIGN KEY (`draw_id`) REFERENCES `prize_draws` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_nq_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prt_hash` (`token_hash`),
  KEY `ix_prt_user` (`user_id`),
  CONSTRAINT `fk_prt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prize_bonds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `bond_type_id` int(10) unsigned NOT NULL,
  `series` varchar(16) NOT NULL DEFAULT '',
  `number` varchar(16) NOT NULL,
  `purchase_date` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bond_user` (`user_id`,`bond_type_id`,`series`,`number`),
  KEY `ix_bond_match` (`bond_type_id`,`number`,`status`),
  KEY `ix_bond_user` (`user_id`,`status`),
  CONSTRAINT `fk_pb_type` FOREIGN KEY (`bond_type_id`) REFERENCES `bond_types` (`id`),
  CONSTRAINT `fk_pb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prize_draws` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bond_type_id` int(10) unsigned NOT NULL,
  `draw_number` smallint(5) unsigned NOT NULL,
  `draw_date` date NOT NULL,
  `series_count` smallint(5) unsigned DEFAULT NULL,
  `source_id` int(10) unsigned DEFAULT NULL,
  `import_status` varchar(30) NOT NULL DEFAULT 'imported',
  `verification_status` enum('pending','verified','disputed','rejected') NOT NULL DEFAULT 'pending',
  `publication_status` enum('unpublished','published','withdrawn') NOT NULL DEFAULT 'unpublished',
  `lifecycle` enum('staged','published','matched','notifications_queued','completed') NOT NULL DEFAULT 'staged',
  `source_url` varchar(500) DEFAULT NULL,
  `source_file` varchar(500) DEFAULT NULL,
  `source_checksum` char(64) DEFAULT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `matched_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_draw` (`bond_type_id`,`draw_number`),
  KEY `ix_draw_pub` (`publication_status`,`draw_date`),
  KEY `ix_draw_life` (`lifecycle`),
  KEY `fk_draw_src` (`source_id`),
  CONSTRAINT `fk_draw_src` FOREIGN KEY (`source_id`) REFERENCES `result_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_draw_type` FOREIGN KEY (`bond_type_id`) REFERENCES `bond_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prize_matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prize_bond_id` bigint(20) unsigned NOT NULL,
  `draw_id` bigint(20) unsigned NOT NULL,
  `winning_number_id` bigint(20) unsigned NOT NULL,
  `amount_snapshot` decimal(14,2) NOT NULL,
  `eligibility_status` enum('eligible','unknown','uncertain','ineligible') NOT NULL DEFAULT 'unknown',
  `eligibility_note` varchar(255) DEFAULT NULL,
  `match_status` enum('detected','void') NOT NULL DEFAULT 'detected',
  `notification_status` enum('pending','queued','sent','failed','not_required') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_match` (`prize_bond_id`,`winning_number_id`),
  KEY `ix_match_draw` (`draw_id`,`notification_status`),
  KEY `fk_pm_wn` (`winning_number_id`),
  CONSTRAINT `fk_pm_bond` FOREIGN KEY (`prize_bond_id`) REFERENCES `prize_bonds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_draw` FOREIGN KEY (`draw_id`) REFERENCES `prize_draws` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pm_wn` FOREIGN KEY (`winning_number_id`) REFERENCES `winning_numbers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prize_tiers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bond_type_id` int(10) unsigned NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `name_bn` varchar(100) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `rank_order` smallint(5) unsigned NOT NULL,
  `expected_count` smallint(5) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tier_code` (`bond_type_id`,`code`),
  KEY `ix_tier_rank` (`bond_type_id`,`rank_order`),
  CONSTRAINT `fk_pt_type` FOREIGN KEY (`bond_type_id`) REFERENCES `bond_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `rl_key` varchar(190) NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`rl_key`),
  KEY `ix_rl_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `result_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int(10) unsigned DEFAULT NULL,
  `bond_type_id` int(10) unsigned NOT NULL,
  `draw_id` bigint(20) unsigned DEFAULT NULL,
  `draw_number` smallint(5) unsigned DEFAULT NULL,
  `draw_date` date DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_checksum` char(64) DEFAULT NULL,
  `file_format` varchar(20) DEFAULT NULL,
  `parser_version` varchar(20) DEFAULT NULL,
  `status` enum('discovered','fetched','parsed','staged','review_required','verified','disputed','rejected','published','failed') NOT NULL DEFAULT 'discovered',
  `origin` enum('auto','manual_upload','manual_entry') NOT NULL DEFAULT 'auto',
  `staged_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`staged_data`)),
  `validation_report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`validation_report`)),
  `secondary_report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`secondary_report`)),
  `admin_id` int(10) unsigned DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `retrieved_at` datetime DEFAULT NULL,
  `imported_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_imp_status` (`status`),
  KEY `ix_imp_draw` (`bond_type_id`,`draw_number`),
  KEY `fk_imp_src` (`source_id`),
  KEY `fk_imp_draw` (`draw_id`),
  KEY `fk_imp_admin` (`admin_id`),
  CONSTRAINT `fk_imp_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_imp_draw` FOREIGN KEY (`draw_id`) REFERENCES `prize_draws` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_imp_src` FOREIGN KEY (`source_id`) REFERENCES `result_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_imp_type` FOREIGN KEY (`bond_type_id`) REFERENCES `bond_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `result_sources` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(150) NOT NULL,
  `base_url` varchar(500) NOT NULL,
  `authority` enum('primary','secondary','reference') NOT NULL DEFAULT 'primary',
  `adapter` varchar(80) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `check_interval_minutes` int(10) unsigned NOT NULL DEFAULT 360,
  `last_checked_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rs_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_settings` (
  `user_id` bigint(20) unsigned NOT NULL,
  `language` enum('en','bn') NOT NULL DEFAULT 'en',
  `email_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `email_marketing` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `status` enum('active','suspended','deletion_requested','deleted') NOT NULL DEFAULT 'active',
  `email_verified_at` datetime DEFAULT NULL,
  `deletion_requested_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `ix_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `winning_numbers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draw_id` bigint(20) unsigned NOT NULL,
  `prize_tier_id` int(10) unsigned NOT NULL,
  `number` varchar(16) NOT NULL,
  `series` varchar(16) NOT NULL DEFAULT '',
  `amount` decimal(14,2) NOT NULL,
  `status` enum('active','void') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wn` (`draw_id`,`prize_tier_id`,`number`,`series`),
  KEY `ix_wn_lookup` (`number`),
  KEY `ix_wn_draw` (`draw_id`,`status`),
  KEY `fk_wn_tier` (`prize_tier_id`),
  CONSTRAINT `fk_wn_draw` FOREIGN KEY (`draw_id`) REFERENCES `prize_draws` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wn_tier` FOREIGN KEY (`prize_tier_id`) REFERENCES `prize_tiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Seed data (default configuration; matches migration 0002)
INSERT INTO `admin_roles` VALUES (1,'super_admin','Full system access');
INSERT INTO `admin_roles` VALUES (2,'result_manager','Imports, verifies, publishes results and runs matching');
INSERT INTO `admin_roles` VALUES (3,'support_admin','Supports users; cannot touch official results');
INSERT INTO `admin_permissions` VALUES (1,'view_dashboard','View admin dashboard');
INSERT INTO `admin_permissions` VALUES (2,'view_users','View user accounts');
INSERT INTO `admin_permissions` VALUES (3,'manage_users','Suspend/reactivate user accounts');
INSERT INTO `admin_permissions` VALUES (4,'view_bonds','View registered bonds');
INSERT INTO `admin_permissions` VALUES (5,'manage_bond_types','Edit bond types and prize tiers');
INSERT INTO `admin_permissions` VALUES (6,'view_draws','View draws and winning numbers');
INSERT INTO `admin_permissions` VALUES (7,'import_results','Upload / enter / fetch results');
INSERT INTO `admin_permissions` VALUES (8,'review_results','Review and edit staged results');
INSERT INTO `admin_permissions` VALUES (9,'verify_results','Approve or reject staged results');
INSERT INTO `admin_permissions` VALUES (10,'publish_results','Publish verified results');
INSERT INTO `admin_permissions` VALUES (11,'correct_results','Correct published results (versioned)');
INSERT INTO `admin_permissions` VALUES (12,'run_matching','Run / re-run matching');
INSERT INTO `admin_permissions` VALUES (13,'view_matches','View prize matches');
INSERT INTO `admin_permissions` VALUES (14,'view_email_queue','View email queue and logs');
INSERT INTO `admin_permissions` VALUES (15,'manage_email_queue','Retry / cancel queued emails');
INSERT INTO `admin_permissions` VALUES (16,'manage_sources','Manage result sources');
INSERT INTO `admin_permissions` VALUES (17,'view_cron','View cron job monitor');
INSERT INTO `admin_permissions` VALUES (18,'view_audit','View audit logs');
INSERT INTO `admin_permissions` VALUES (19,'manage_roles','Manage admin users, roles and permissions');
INSERT INTO `admin_permissions` VALUES (20,'manage_settings','Edit system settings');
INSERT INTO `admin_role_permissions` VALUES (1,1);
INSERT INTO `admin_role_permissions` VALUES (1,2);
INSERT INTO `admin_role_permissions` VALUES (1,3);
INSERT INTO `admin_role_permissions` VALUES (1,4);
INSERT INTO `admin_role_permissions` VALUES (1,5);
INSERT INTO `admin_role_permissions` VALUES (1,6);
INSERT INTO `admin_role_permissions` VALUES (1,7);
INSERT INTO `admin_role_permissions` VALUES (1,8);
INSERT INTO `admin_role_permissions` VALUES (1,9);
INSERT INTO `admin_role_permissions` VALUES (1,10);
INSERT INTO `admin_role_permissions` VALUES (1,11);
INSERT INTO `admin_role_permissions` VALUES (1,12);
INSERT INTO `admin_role_permissions` VALUES (1,13);
INSERT INTO `admin_role_permissions` VALUES (1,14);
INSERT INTO `admin_role_permissions` VALUES (1,15);
INSERT INTO `admin_role_permissions` VALUES (1,16);
INSERT INTO `admin_role_permissions` VALUES (1,17);
INSERT INTO `admin_role_permissions` VALUES (1,18);
INSERT INTO `admin_role_permissions` VALUES (1,19);
INSERT INTO `admin_role_permissions` VALUES (1,20);
INSERT INTO `admin_role_permissions` VALUES (2,1);
INSERT INTO `admin_role_permissions` VALUES (2,6);
INSERT INTO `admin_role_permissions` VALUES (2,7);
INSERT INTO `admin_role_permissions` VALUES (2,8);
INSERT INTO `admin_role_permissions` VALUES (2,9);
INSERT INTO `admin_role_permissions` VALUES (2,10);
INSERT INTO `admin_role_permissions` VALUES (2,11);
INSERT INTO `admin_role_permissions` VALUES (2,12);
INSERT INTO `admin_role_permissions` VALUES (2,13);
INSERT INTO `admin_role_permissions` VALUES (2,14);
INSERT INTO `admin_role_permissions` VALUES (2,17);
INSERT INTO `admin_role_permissions` VALUES (3,1);
INSERT INTO `admin_role_permissions` VALUES (3,2);
INSERT INTO `admin_role_permissions` VALUES (3,4);
INSERT INTO `admin_role_permissions` VALUES (3,6);
INSERT INTO `admin_role_permissions` VALUES (3,13);
INSERT INTO `admin_role_permissions` VALUES (3,14);
INSERT INTO `system_settings` VALUES ('backup.retention_days','14','Days to keep database backups','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('claims.period_note','Prize claims must be made within two years of the draw date (IRD general information). Tax may be deducted at source; rates are set by law and may change.','Informational claim note','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('eligibility.min_calendar_months','2','IRD general information: minimum 2 months after sale date','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('eligibility.min_days','60','Official draw document: bonds sold >= 60 days before draw date (sale date counted, draw date excluded)','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('imports.auto_publish','0','Auto-publish verified imports only when IRD cross-check fully agrees (0 = admin must publish)','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('imports.irD_verification','1','Cross-check staged results against IRD','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('mail.admin_alert_email','','Recipient for admin/import-failure alerts','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('mail.batch_size','50','Emails sent per send_notifications run','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('mail.max_attempts','5','Max delivery attempts per email','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('mail.retry_backoff_minutes','30','Base backoff between attempts (multiplied by attempt number)','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('maintenance.log_retention_days','90','Days to keep cron/notification logs','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('matching.batch_size','500','Winning numbers matched per batch','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('security.login_max_attempts','5','Failed logins before temporary lock','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('security.login_window_minutes','15','Login rate-limit window','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('site.name','Prize Bond Monitor BD','Public site name','2026-11-10 05:16:38');
INSERT INTO `system_settings` VALUES ('sources.check_interval_minutes','360','Default source check interval (every 6 hours)','2026-11-10 05:16:38');
INSERT INTO `bond_types` VALUES (1,'BDT100','Bangladesh Tk. 100 Prize Bond','বাংলাদেশ ১০০ টাকা মূল্যমানের প্রাইজবন্ড',100.00,7,1,0,0,1,1,'Single common-number system: each winning 7-digit number applies to every series (Bangladesh Bank draw documents, IRD PBRIS).','2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `prize_tiers` VALUES (1,1,'1','1st Prize','১ম পুরস্কার',600000.00,1,1,1,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `prize_tiers` VALUES (2,1,'2','2nd Prize','২য় পুরস্কার',325000.00,2,1,1,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `prize_tiers` VALUES (3,1,'3','3rd Prize','৩য় পুরস্কার',100000.00,3,2,1,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `prize_tiers` VALUES (4,1,'4','4th Prize','৪র্থ পুরস্কার',50000.00,4,2,1,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `prize_tiers` VALUES (5,1,'5','5th Prize','৫ম পুরস্কার',10000.00,5,40,1,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `result_sources` VALUES (1,'bb','Bangladesh Bank - Prize Bond','https://www.bb.org.bd/en/index.php/investfacility/prizebond','primary','BangladeshBankAdapter',1,360,NULL,NULL,NULL,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `result_sources` VALUES (2,'ird','IRD Prize Bond Result Inquiry (PBRIS)','https://prizebond.ird.gov.bd/','secondary','IRDAdapter',1,360,NULL,NULL,NULL,'2026-11-10 05:16:38','2026-11-10 05:16:38');
INSERT INTO `result_sources` VALUES (3,'nsd','National Savings Directorate (reference)','https://nationalsavings.gov.bd/','reference','None',0,1440,NULL,NULL,NULL,'2026-11-10 05:16:38','2026-11-10 05:16:38');
