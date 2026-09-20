-- =============================================================================
--  TourSync — Database Schema (final structure)
--  Tourism Municipal Office Information Management System
--  Municipal Tourism Office, Tampakan, South Cotabato
-- -----------------------------------------------------------------------------
--  Generated 2026-09-20 from the running database, so a fresh install gets
--  exactly the structure the system uses: 42 tables, InnoDB, utf8mb4_unicode_ci.
--  Target  : MySQL 8.0+ / MariaDB 10.4+
--
--  Apply with:  php database/install.php      (a new, empty database)
--  Upgrade an existing database with:  php database/migrate.php
--
--  Documented in docs/database/ — ERD, database design, data dictionary.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tourist_arrivals`;
DROP TABLE IF EXISTS `tour_request_destinations`;
DROP TABLE IF EXISTS `tour_guides`;
DROP TABLE IF EXISTS `tour_guide_requests`;
DROP TABLE IF EXISTS `tour_guide_credentials`;
DROP TABLE IF EXISTS `tour_guide_certificates`;
DROP TABLE IF EXISTS `sms_inbox`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `promo_videos`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `manager_notifications`;
DROP TABLE IF EXISTS `manager_notification_reads`;
DROP TABLE IF EXISTS `inspection_requirements`;
DROP TABLE IF EXISTS `inspection_reports`;
DROP TABLE IF EXISTS `inspection_photos`;
DROP TABLE IF EXISTS `inspection_items`;
DROP TABLE IF EXISTS `hero_slides`;
DROP TABLE IF EXISTS `guide_reviews`;
DROP TABLE IF EXISTS `feedback`;
DROP TABLE IF EXISTS `destinations`;
DROP TABLE IF EXISTS `destination_routes`;
DROP TABLE IF EXISTS `destination_photos`;
DROP TABLE IF EXISTS `destination_managers`;
DROP TABLE IF EXISTS `destination_change_requests`;
DROP TABLE IF EXISTS `destination_alerts`;
DROP TABLE IF EXISTS `data_requests`;
DROP TABLE IF EXISTS `contact_messages`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `arrival_reports`;
DROP TABLE IF EXISTS `arrival_report_entries`;
DROP TABLE IF EXISTS `arrival_report_documents`;
DROP TABLE IF EXISTS `arrival_report_days`;
DROP TABLE IF EXISTS `arrival_daily_summary`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `announcement_photos`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `admin_recovery_codes`;
DROP TABLE IF EXISTS `admin_notifications`;
DROP TABLE IF EXISTS `admin_notification_reads`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `about_photos`;

-- -----------------------------------------------------------------------------
--  about_photos
-- -----------------------------------------------------------------------------
CREATE TABLE `about_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `section` varchar(40) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(190) DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_about_photos_section` (`section`,`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  activity_logs
-- -----------------------------------------------------------------------------
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned DEFAULT NULL,
  `manager_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity_type` varchar(60) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(400) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_admin` (`admin_id`,`created_at`),
  KEY `idx_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_log_action` (`action`),
  KEY `idx_log_manager` (`manager_id`,`created_at`),
  KEY `idx_activity_when` (`created_at`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_log_manager` FOREIGN KEY (`manager_id`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  admin_notification_reads
-- -----------------------------------------------------------------------------
CREATE TABLE `admin_notification_reads` (
  `notification_id` int(10) unsigned NOT NULL,
  `admin_id` int(10) unsigned NOT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`notification_id`,`admin_id`),
  KEY `idx_read_admin` (`admin_id`),
  CONSTRAINT `fk_notif_read_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_read_notification` FOREIGN KEY (`notification_id`) REFERENCES `admin_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  admin_notifications
-- -----------------------------------------------------------------------------
CREATE TABLE `admin_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` varchar(400) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_created` (`created_at`),
  KEY `idx_notif_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  admin_recovery_codes
-- -----------------------------------------------------------------------------
CREATE TABLE `admin_recovery_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recovery_admin` (`admin_id`,`used_at`),
  CONSTRAINT `fk_recovery_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  admins
-- -----------------------------------------------------------------------------
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(60) NOT NULL,
  `email` varchar(160) NOT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `alert_sms_opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_changed_at` datetime DEFAULT NULL,
  `totp_secret` varchar(255) DEFAULT NULL,
  `totp_confirmed_at` datetime DEFAULT NULL,
  `totp_last_step` bigint(20) unsigned DEFAULT NULL,
  `role` enum('officer','staff') NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`),
  UNIQUE KEY `uq_admin_email` (`email`),
  KEY `idx_admin_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  announcement_photos
-- -----------------------------------------------------------------------------
CREATE TABLE `announcement_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int(10) unsigned NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ann_photos` (`announcement_id`,`sort_order`,`id`),
  CONSTRAINT `fk_ann_photos` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  announcements
-- -----------------------------------------------------------------------------
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `slug` varchar(220) NOT NULL,
  `body` text NOT NULL,
  `summary` varchar(300) DEFAULT NULL,
  `type` enum('announcement','advisory','schedule','closure','reminder','event','festival','community','municipal','activity') NOT NULL DEFAULT 'announcement',
  `audience` enum('public','managers','both') NOT NULL DEFAULT 'public',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `destination_id` int(10) unsigned DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `event_location` varchar(200) DEFAULT NULL,
  `publish_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ann_slug` (`slug`),
  KEY `idx_ann_publish` (`status`,`publish_at`),
  KEY `idx_ann_type` (`type`),
  KEY `idx_ann_audience` (`audience`),
  KEY `fk_ann_dest` (`destination_id`),
  KEY `fk_ann_author` (`created_by`),
  CONSTRAINT `fk_ann_author` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ann_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  arrival_daily_summary
-- -----------------------------------------------------------------------------
CREATE TABLE `arrival_daily_summary` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `total_records` int(10) unsigned NOT NULL DEFAULT 0,
  `total_visitors` int(10) unsigned NOT NULL DEFAULT 0,
  `local_count` int(10) unsigned NOT NULL DEFAULT 0,
  `domestic_count` int(10) unsigned NOT NULL DEFAULT 0,
  `foreign_count` int(10) unsigned NOT NULL DEFAULT 0,
  `ofw_count` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_summary` (`destination_id`,`visit_date`),
  KEY `idx_summary_date` (`visit_date`),
  CONSTRAINT `fk_summary_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  arrival_report_days
-- -----------------------------------------------------------------------------
CREATE TABLE `arrival_report_days` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int(10) unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `local_count` int(10) unsigned NOT NULL DEFAULT 0,
  `domestic_count` int(10) unsigned NOT NULL DEFAULT 0,
  `foreign_count` int(10) unsigned NOT NULL DEFAULT 0,
  `ofw_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_visitors` int(10) unsigned GENERATED ALWAYS AS (`local_count` + `domestic_count` + `foreign_count` + `ofw_count`) STORED,
  `male_count` int(10) unsigned DEFAULT NULL,
  `female_count` int(10) unsigned DEFAULT NULL,
  `children_count` int(10) unsigned DEFAULT NULL,
  `adults_count` int(10) unsigned DEFAULT NULL,
  `seniors_count` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ard_report_date` (`report_id`,`visit_date`),
  CONSTRAINT `fk_ard_report` FOREIGN KEY (`report_id`) REFERENCES `arrival_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  arrival_report_documents
-- -----------------------------------------------------------------------------
CREATE TABLE `arrival_report_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int(10) unsigned NOT NULL,
  `stored_name` varchar(80) NOT NULL,
  `original_name` varchar(200) NOT NULL,
  `mime_type` enum('image/jpeg','image/png','application/pdf') NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `covers_date` date DEFAULT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ard_stored` (`stored_name`),
  KEY `idx_ard_report` (`report_id`),
  KEY `fk_ardoc_manager` (`uploaded_by`),
  CONSTRAINT `fk_ardoc_manager` FOREIGN KEY (`uploaded_by`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ardoc_report` FOREIGN KEY (`report_id`) REFERENCES `arrival_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  arrival_report_entries
-- -----------------------------------------------------------------------------
CREATE TABLE `arrival_report_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int(10) unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `row_no` smallint(5) unsigned NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `address_text` varchar(160) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `sex` enum('male','female') DEFAULT NULL,
  `tourist_type` enum('local','domestic','foreign','overseas_filipino') NOT NULL DEFAULT 'domestic',
  `origin_city` varchar(120) DEFAULT NULL,
  `origin_province` varchar(120) DEFAULT NULL,
  `origin_country` varchar(80) DEFAULT NULL,
  `confidence` enum('high','low') NOT NULL DEFAULT 'low',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_are_line` (`report_id`,`visit_date`,`row_no`),
  KEY `idx_are_date` (`report_id`,`visit_date`),
  CONSTRAINT `fk_are_report` FOREIGN KEY (`report_id`) REFERENCES `arrival_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  arrival_reports
-- -----------------------------------------------------------------------------
CREATE TABLE `arrival_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('draft','submitted','reviewing','approved','rejected') NOT NULL DEFAULT 'draft',
  `notes` varchar(500) DEFAULT NULL,
  `submitted_by` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ar_dest_period` (`destination_id`,`period_start`),
  KEY `idx_ar_status` (`status`),
  KEY `fk_ar_manager` (`submitted_by`),
  KEY `fk_ar_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_ar_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`),
  CONSTRAINT `fk_ar_manager` FOREIGN KEY (`submitted_by`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ar_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  categories
-- -----------------------------------------------------------------------------
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `icon` varchar(60) DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  contact_messages
-- -----------------------------------------------------------------------------
CREATE TABLE `contact_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `subject` varchar(120) NOT NULL,
  `category` varchar(30) DEFAULT NULL,
  `message` varchar(2000) NOT NULL,
  `status` enum('new','read','answered','spam') NOT NULL DEFAULT 'new',
  `handled_by` int(10) unsigned DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `office_note` varchar(600) DEFAULT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `anonymised_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_status` (`status`,`created_at`),
  KEY `fk_contact_admin` (`handled_by`),
  KEY `idx_contact_category` (`category`,`created_at`),
  CONSTRAINT `fk_contact_admin` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  data_requests
-- -----------------------------------------------------------------------------
CREATE TABLE `data_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(20) NOT NULL,
  `organisation` varchar(190) DEFAULT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `home_address` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `designation` varchar(160) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `purpose` varchar(1000) NOT NULL,
  `requested_data` varchar(2000) NOT NULL,
  `needed_by` date DEFAULT NULL,
  `status` enum('new','in_progress','fulfilled','declined') NOT NULL DEFAULT 'new',
  `handled_by` int(10) unsigned DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `office_note` varchar(1000) DEFAULT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `anonymised_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_data_request_ref` (`reference`),
  KEY `idx_data_requests_status` (`status`,`created_at`),
  KEY `idx_data_requests_device` (`device_hash`,`created_at`),
  KEY `fk_data_request_admin` (`handled_by`),
  CONSTRAINT `fk_data_request_admin` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  destination_alerts
-- -----------------------------------------------------------------------------
CREATE TABLE `destination_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned DEFAULT NULL,
  `raised_by` int(10) unsigned DEFAULT NULL,
  `channel` enum('portal','sms') NOT NULL DEFAULT 'portal',
  `category` enum('closure','hazard','accident','weather','utility','crowding','other','road') NOT NULL DEFAULT 'other',
  `severity` enum('info','warning','urgent') NOT NULL DEFAULT 'warning',
  `message` varchar(1000) NOT NULL,
  `raw_text` varchar(1000) DEFAULT NULL,
  `from_number` varchar(20) DEFAULT NULL,
  `provider_ref` varchar(120) DEFAULT NULL,
  `status` enum('new','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'new',
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolution_note` varchar(600) DEFAULT NULL,
  `office_reply` varchar(600) DEFAULT NULL,
  `replied_by` int(10) unsigned DEFAULT NULL,
  `replied_at` datetime DEFAULT NULL,
  `reply_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_alert_status` (`status`,`severity`,`created_at`),
  KEY `idx_alert_dest` (`destination_id`,`created_at`),
  KEY `fk_alert_mgr` (`raised_by`),
  KEY `fk_alert_admin` (`acknowledged_by`),
  KEY `fk_alert_replied_by` (`replied_by`),
  CONSTRAINT `fk_alert_admin` FOREIGN KEY (`acknowledged_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alert_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alert_mgr` FOREIGN KEY (`raised_by`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alert_replied_by` FOREIGN KEY (`replied_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  destination_change_requests
-- -----------------------------------------------------------------------------
CREATE TABLE `destination_change_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `requested_by` int(10) unsigned DEFAULT NULL,
  `proposed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`proposed`)),
  `reason` varchar(600) DEFAULT NULL,
  `status` enum('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(600) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dcr_status` (`status`,`created_at`),
  KEY `idx_dcr_dest` (`destination_id`,`created_at`),
  KEY `fk_dcr_mgr` (`requested_by`),
  KEY `fk_dcr_admin` (`reviewed_by`),
  CONSTRAINT `fk_dcr_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dcr_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dcr_mgr` FOREIGN KEY (`requested_by`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  destination_managers
-- -----------------------------------------------------------------------------
CREATE TABLE `destination_managers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(60) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `position` varchar(120) DEFAULT NULL,
  `mobile_number` varchar(20) NOT NULL,
  `email` varchar(160) DEFAULT NULL,
  `sms_opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `reply_sms_opt_in` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_username` (`username`),
  KEY `idx_mgr_dest` (`destination_id`),
  KEY `idx_mgr_sms` (`is_active`,`sms_opt_in`),
  CONSTRAINT `fk_mgr_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  destination_photos
-- -----------------------------------------------------------------------------
CREATE TABLE `destination_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `is_cover` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_photo_dest` (`destination_id`,`sort_order`),
  CONSTRAINT `fk_photo_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  destination_routes
-- -----------------------------------------------------------------------------
CREATE TABLE `destination_routes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `from_landmark` varchar(160) NOT NULL,
  `directions` text NOT NULL,
  `travel_time` varchar(60) DEFAULT NULL,
  `distance` varchar(60) DEFAULT NULL,
  `transport` varchar(160) DEFAULT NULL,
  `fare_note` varchar(160) DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_route_dest` (`destination_id`,`sort_order`),
  CONSTRAINT `fk_route_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  destinations
-- -----------------------------------------------------------------------------
CREATE TABLE `destinations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `attraction_code` varchar(40) DEFAULT NULL,
  `short_description` varchar(300) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `history` text DEFAULT NULL,
  `operating_hours` varchar(160) DEFAULT NULL,
  `entrance_fee` varchar(120) DEFAULT NULL,
  `facilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`facilities`)),
  `reminders` text DEFAULT NULL,
  `safety_notes` text DEFAULT NULL,
  `barangay` varchar(120) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `offline_map_image` varchar(255) DEFAULT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `contact_phone` varchar(40) DEFAULT NULL,
  `local_hotline` varchar(120) DEFAULT NULL,
  `contact_email` varchar(160) DEFAULT NULL,
  `qr_token` char(32) NOT NULL,
  `qr_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `qr_rotated_at` datetime DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','archived') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dest_slug` (`slug`),
  UNIQUE KEY `uq_dest_qr` (`qr_token`),
  KEY `idx_dest_status` (`status`),
  KEY `idx_dest_category` (`category_id`),
  KEY `idx_dest_featured` (`is_featured`,`status`),
  KEY `fk_dest_author` (`created_by`),
  CONSTRAINT `fk_dest_author` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dest_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  feedback
-- -----------------------------------------------------------------------------
CREATE TABLE `feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `arrival_id` bigint(20) unsigned DEFAULT NULL,
  `visitor_name` varchar(120) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `comment` text DEFAULT NULL,
  `status` enum('pending','published','hidden') NOT NULL DEFAULT 'pending',
  `moderated_by` int(10) unsigned DEFAULT NULL,
  `moderated_at` datetime DEFAULT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fb_dest` (`destination_id`,`status`),
  KEY `idx_fb_status` (`status`),
  KEY `fk_fb_arr` (`arrival_id`),
  KEY `fk_fb_mod` (`moderated_by`),
  KEY `idx_fb_device` (`device_hash`,`destination_id`,`created_at`),
  CONSTRAINT `fk_fb_arr` FOREIGN KEY (`arrival_id`) REFERENCES `tourist_arrivals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fb_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fb_mod` FOREIGN KEY (`moderated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fb_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  guide_reviews
-- -----------------------------------------------------------------------------
CREATE TABLE `guide_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guide_id` int(10) unsigned NOT NULL,
  `visitor_name` varchar(120) DEFAULT NULL,
  `visitor_email` varchar(190) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `comment` varchar(1000) DEFAULT NULL,
  `status` enum('pending','published','hidden') NOT NULL DEFAULT 'pending',
  `moderated_by` int(10) unsigned DEFAULT NULL,
  `moderated_at` datetime DEFAULT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guide_reviews_guide` (`guide_id`,`status`),
  KEY `idx_guide_reviews_status` (`status`,`created_at`),
  KEY `idx_guide_reviews_device` (`device_hash`,`created_at`),
  KEY `fk_guide_review_admin` (`moderated_by`),
  CONSTRAINT `fk_guide_review_admin` FOREIGN KEY (`moderated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_guide_review_guide` FOREIGN KEY (`guide_id`) REFERENCES `tour_guides` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  hero_slides
-- -----------------------------------------------------------------------------
CREATE TABLE `hero_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `image_path` varchar(255) NOT NULL DEFAULT '',
  `eyebrow` varchar(120) NOT NULL DEFAULT '',
  `title` varchar(160) NOT NULL DEFAULT '',
  `body` varchar(400) NOT NULL DEFAULT '',
  `status` enum('published','draft') NOT NULL DEFAULT 'published',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hero_order` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  inspection_items
-- -----------------------------------------------------------------------------
CREATE TABLE `inspection_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int(10) unsigned NOT NULL,
  `requirement_id` int(10) unsigned NOT NULL,
  `status` enum('pending','submitted','approved','rejected','needs_revision') NOT NULL DEFAULT 'pending',
  `remarks` varchar(600) DEFAULT NULL,
  `office_comment` varchar(600) DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_item` (`report_id`,`requirement_id`),
  KEY `idx_item_status` (`report_id`,`status`),
  KEY `fk_item_req` (`requirement_id`),
  KEY `fk_item_admin` (`reviewed_by`),
  CONSTRAINT `fk_item_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_item_report` FOREIGN KEY (`report_id`) REFERENCES `inspection_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_req` FOREIGN KEY (`requirement_id`) REFERENCES `inspection_requirements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  inspection_photos
-- -----------------------------------------------------------------------------
CREATE TABLE `inspection_photos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `stored_name` varchar(80) NOT NULL,
  `original_name` varchar(200) NOT NULL,
  `mime_type` enum('image/jpeg','image/png') NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `caption` varchar(300) DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_photo_stored` (`stored_name`),
  KEY `idx_photo_item` (`item_id`),
  KEY `fk_photo_mgr` (`uploaded_by`),
  CONSTRAINT `fk_photo_item` FOREIGN KEY (`item_id`) REFERENCES `inspection_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photo_mgr` FOREIGN KEY (`uploaded_by`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  inspection_reports
-- -----------------------------------------------------------------------------
CREATE TABLE `inspection_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `status` enum('draft','submitted','reviewing','approved','rejected') NOT NULL DEFAULT 'draft',
  `submitted_by` int(10) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `office_remarks` varchar(1000) DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `site_visit_required` tinyint(1) NOT NULL DEFAULT 0,
  `site_visit_at` datetime DEFAULT NULL,
  `site_visit_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_insp_dest` (`destination_id`,`status`),
  KEY `idx_insp_status` (`status`,`submitted_at`),
  KEY `fk_insp_mgr` (`submitted_by`),
  KEY `fk_insp_admin` (`reviewed_by`),
  CONSTRAINT `fk_insp_admin` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_mgr` FOREIGN KEY (`submitted_by`) REFERENCES `destination_managers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  inspection_requirements
-- -----------------------------------------------------------------------------
CREATE TABLE `inspection_requirements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `guidance` text DEFAULT NULL,
  `min_photos` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `max_photos` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ir_active` (`is_active`,`sort_order`),
  KEY `fk_ir_admin` (`created_by`),
  CONSTRAINT `fk_ir_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  manager_notification_reads
-- -----------------------------------------------------------------------------
CREATE TABLE `manager_notification_reads` (
  `notification_id` int(10) unsigned NOT NULL,
  `manager_id` int(10) unsigned NOT NULL,
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`notification_id`,`manager_id`),
  KEY `idx_mgr_reads_manager` (`manager_id`),
  CONSTRAINT `fk_mgr_reads_manager` FOREIGN KEY (`manager_id`) REFERENCES `destination_managers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mgr_reads_notif` FOREIGN KEY (`notification_id`) REFERENCES `manager_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  manager_notifications
-- -----------------------------------------------------------------------------
CREATE TABLE `manager_notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` varchar(400) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `entity_type` varchar(40) DEFAULT NULL,
  `entity_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mgr_notif_dest` (`destination_id`,`id`),
  CONSTRAINT `fk_mgr_notif_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  notifications
-- -----------------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int(10) unsigned NOT NULL,
  `manager_id` int(10) unsigned NOT NULL,
  `channel` enum('sms','portal') NOT NULL DEFAULT 'sms',
  `status` enum('queued','sent','failed','delivered') NOT NULL DEFAULT 'queued',
  `provider_ref` varchar(120) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ntf_queue` (`status`,`attempts`),
  KEY `idx_ntf_ann` (`announcement_id`),
  KEY `fk_ntf_mgr` (`manager_id`),
  CONSTRAINT `fk_ntf_ann` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ntf_mgr` FOREIGN KEY (`manager_id`) REFERENCES `destination_managers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  promo_videos
-- -----------------------------------------------------------------------------
CREATE TABLE `promo_videos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `caption` varchar(600) DEFAULT NULL,
  `destination_id` int(10) unsigned DEFAULT NULL,
  `category` enum('promo','event','archive','visitor') NOT NULL DEFAULT 'promo',
  `source` enum('upload','external') NOT NULL DEFAULT 'upload',
  `file_path` varchar(255) DEFAULT NULL,
  `mime_type` varchar(60) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `external_url` varchar(500) DEFAULT NULL,
  `poster_path` varchar(255) DEFAULT NULL,
  `is_hero` tinyint(1) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_video_public` (`status`,`sort_order`,`id`),
  KEY `idx_video_dest` (`destination_id`,`status`),
  KEY `fk_video_admin` (`created_by`),
  CONSTRAINT `fk_video_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_video_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  reports
-- -----------------------------------------------------------------------------
CREATE TABLE `reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) DEFAULT NULL,
  `type` enum('daily','monthly','quarterly','annual','custom') NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parameters`)),
  `file_path` varchar(255) DEFAULT NULL,
  `generated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rep_period` (`type`,`period_start`,`period_end`),
  KEY `fk_rep_by` (`generated_by`),
  CONSTRAINT `fk_rep_by` FOREIGN KEY (`generated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  settings
-- -----------------------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  sms_inbox
-- -----------------------------------------------------------------------------
CREATE TABLE `sms_inbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_number` varchar(20) DEFAULT NULL,
  `body` varchar(1000) DEFAULT NULL,
  `provider_ref` varchar(120) DEFAULT NULL,
  `outcome` enum('alert_created','unknown_sender','duplicate','rejected','empty') NOT NULL DEFAULT 'rejected',
  `alert_id` int(10) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_inbox_ref` (`provider_ref`),
  KEY `idx_inbox_outcome` (`outcome`,`created_at`),
  KEY `fk_inbox_alert` (`alert_id`),
  KEY `idx_inbox_when` (`created_at`),
  CONSTRAINT `fk_inbox_alert` FOREIGN KEY (`alert_id`) REFERENCES `destination_alerts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  tour_guide_certificates
-- -----------------------------------------------------------------------------
CREATE TABLE `tour_guide_certificates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `guide_id` int(10) unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `issuer` varchar(160) DEFAULT NULL,
  `issued_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `stored_name` varchar(80) NOT NULL,
  `original_name` varchar(200) NOT NULL,
  `mime_type` enum('image/jpeg','image/png','application/pdf') NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tgcert_stored` (`stored_name`),
  KEY `idx_tgcert_guide` (`guide_id`,`issued_on`),
  KEY `fk_tgcert_admin` (`uploaded_by`),
  CONSTRAINT `fk_tgcert_admin` FOREIGN KEY (`uploaded_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tgcert_guide` FOREIGN KEY (`guide_id`) REFERENCES `tour_guides` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  tour_guide_credentials
-- -----------------------------------------------------------------------------
CREATE TABLE `tour_guide_credentials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guide_id` int(10) unsigned NOT NULL,
  `label` varchar(160) NOT NULL,
  `issuer` varchar(160) DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tgc_guide` (`guide_id`,`sort_order`),
  CONSTRAINT `fk_tgc_guide` FOREIGN KEY (`guide_id`) REFERENCES `tour_guides` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  tour_guide_requests
-- -----------------------------------------------------------------------------
CREATE TABLE `tour_guide_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reference_code` varchar(12) NOT NULL,
  `issued_at` datetime DEFAULT NULL,
  `destination_id` int(10) unsigned DEFAULT NULL,
  `needs_advice` tinyint(1) NOT NULL DEFAULT 0,
  `source` enum('qr','website') NOT NULL DEFAULT 'website',
  `visitor_name` varchar(120) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `party_size` smallint(5) unsigned NOT NULL DEFAULT 1,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` varchar(40) DEFAULT NULL,
  `notes` varchar(600) DEFAULT NULL,
  `status` enum('new','acknowledged','assigned','completed','declined','cancelled','no_show') NOT NULL DEFAULT 'new',
  `guide_id` int(10) unsigned DEFAULT NULL,
  `guide_name` varchar(120) DEFAULT NULL,
  `guide_contact` varchar(20) DEFAULT NULL,
  `meeting_point` varchar(160) DEFAULT NULL,
  `office_note` varchar(600) DEFAULT NULL,
  `handled_by` int(10) unsigned DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `office_notified_at` datetime DEFAULT NULL,
  `visitor_notified_at` datetime DEFAULT NULL,
  `met_at` datetime DEFAULT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `anonymised_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guide_ref` (`reference_code`),
  KEY `idx_guide_status` (`status`,`created_at`),
  KEY `idx_guide_dest` (`destination_id`,`created_at`),
  KEY `fk_guide_admin` (`handled_by`),
  KEY `idx_guide_date_status` (`preferred_date`,`status`),
  KEY `idx_guide_assigned` (`guide_id`,`preferred_date`),
  CONSTRAINT `fk_guide_admin` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_guide_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_request_guide` FOREIGN KEY (`guide_id`) REFERENCES `tour_guides` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  tour_guides
-- -----------------------------------------------------------------------------
CREATE TABLE `tour_guides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `guide_code` varchar(20) NOT NULL,
  `verify_token` char(32) NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','suspended','revoked') NOT NULL DEFAULT 'active',
  `valid_until` date DEFAULT NULL,
  `id_issued_at` datetime DEFAULT NULL,
  `status_note` varchar(600) DEFAULT NULL,
  `notes` varchar(600) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tg_code` (`guide_code`),
  UNIQUE KEY `uniq_tg_token` (`verify_token`),
  KEY `idx_tg_status` (`status`,`full_name`),
  KEY `fk_tg_admin` (`created_by`),
  CONSTRAINT `fk_tg_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  tour_request_destinations
-- -----------------------------------------------------------------------------
CREATE TABLE `tour_request_destinations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int(10) unsigned NOT NULL,
  `destination_id` int(10) unsigned DEFAULT NULL,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_trd_pair` (`request_id`,`destination_id`),
  KEY `idx_trd_request` (`request_id`,`sort_order`),
  KEY `idx_trd_dest` (`destination_id`),
  CONSTRAINT `fk_trd_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_trd_request` FOREIGN KEY (`request_id`) REFERENCES `tour_guide_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  tourist_arrivals
-- -----------------------------------------------------------------------------
CREATE TABLE `tourist_arrivals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int(10) unsigned NOT NULL,
  `report_id` int(10) unsigned DEFAULT NULL,
  `visit_date` date NOT NULL,
  `arrived_at` datetime NOT NULL,
  `full_name` varchar(160) DEFAULT NULL,
  `age_bracket` enum('under18','18-24','25-34','35-44','45-54','55-64','65plus') DEFAULT NULL,
  `sex` enum('male','female','prefer_not_to_say') DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `tourist_type` enum('local','domestic','foreign','overseas_filipino') NOT NULL,
  `stay_type` enum('day_trip','overnight') DEFAULT NULL,
  `nationality` varchar(80) DEFAULT NULL,
  `origin_country` varchar(80) DEFAULT NULL,
  `origin_province` varchar(120) DEFAULT NULL,
  `origin_city` varchar(120) DEFAULT NULL,
  `logbook_address` varchar(160) DEFAULT NULL,
  `logbook_row` smallint(5) unsigned DEFAULT NULL,
  `purpose` enum('leisure','business','education','religious','vfr','other') DEFAULT NULL,
  `companions_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `total_visitors` smallint(5) unsigned NOT NULL DEFAULT 1,
  `consent_given` tinyint(1) NOT NULL DEFAULT 0,
  `source` enum('qr','manual') NOT NULL DEFAULT 'qr',
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `qr_version_used` smallint(5) unsigned DEFAULT NULL,
  `device_hash` char(64) DEFAULT NULL,
  `client_uuid` char(36) DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `distance_m` int(10) unsigned DEFAULT NULL,
  `status` enum('valid','flagged','voided') NOT NULL DEFAULT 'valid',
  `flag_reason` varchar(255) DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `anonymised_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_arr_client_uuid` (`client_uuid`),
  KEY `idx_arr_dest_date` (`destination_id`,`visit_date`),
  KEY `idx_arr_date` (`visit_date`),
  KEY `idx_arr_type` (`tourist_type`),
  KEY `idx_arr_status` (`status`),
  KEY `idx_arr_dedupe` (`device_hash`,`destination_id`,`visit_date`),
  KEY `fk_arr_by` (`recorded_by`),
  KEY `fk_arr_voider` (`voided_by`),
  KEY `idx_arr_report` (`report_id`,`visit_date`,`logbook_row`),
  CONSTRAINT `fk_arr_by` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_arr_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`),
  CONSTRAINT `fk_arr_report` FOREIGN KEY (`report_id`) REFERENCES `arrival_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arr_voider` FOREIGN KEY (`voided_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
