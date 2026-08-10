
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned DEFAULT NULL,
  `action` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `description` varchar(400) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varbinary(16) DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_admin` (`admin_id`,`created_at`),
  KEY `idx_log_entity` (`entity_type`,`entity_id`),
  KEY `idx_log_action` (`action`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` (`id`, `admin_id`, `action`, `entity_type`, `entity_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES (1,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 14:53:02'),(2,1,'auth.locked','admin',1,'Account locked after 5 failed attempts',_binary '\0\0','curl/8.19.0','2026-08-09 14:54:04'),(3,1,'auth.login','admin',1,'Signed in',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 14:55:29'),(4,1,'auth.logout','admin',1,'Signed out',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 14:56:51'),(5,1,'auth.login','admin',1,'Signed in',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-09 15:01:19'),(6,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:06:08'),(7,1,'destination.create','destination',1,'Created \"Mt. Matutum Viewpoint\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:06:09'),(8,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:06:47'),(9,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:08:02'),(10,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:08:28'),(11,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:09:00'),(12,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:13:07'),(13,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:13:59'),(14,1,'destination.create','destination',2,'Created \"<script>alert(1)</script> Falls\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:13:59'),(15,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:14:22'),(16,1,'destination.create','destination',3,'Created \"XSSProbe\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:14:22'),(17,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:17:25'),(18,1,'destination.create','destination',4,'Created \"Bulol Falls\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:17:26'),(19,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:19:05'),(20,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:19:42'),(21,1,'destination.archive','destination',4,'Archived \"Bulol Falls\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:19:42'),(22,1,'destination.restore','destination',4,'Restored \"Bulol Falls\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:19:43'),(23,1,'destination.update','destination',4,'Updated \"Bulol Falls\"',_binary '\0\0','curl/8.19.0','2026-08-09 15:19:43'),(24,NULL,'arrival.flagged','arrival',2,'Device already logged this destination 1 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:54'),(25,NULL,'arrival.flagged','arrival',3,'Device already logged this destination 2 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:54'),(26,NULL,'arrival.flagged','arrival',4,'Device already logged this destination 3 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:55'),(27,NULL,'arrival.flagged','arrival',5,'Device already logged this destination 4 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:55'),(28,NULL,'arrival.flagged','arrival',6,'Device already logged this destination 5 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:55'),(29,NULL,'arrival.flagged','arrival',7,'Device already logged this destination 6 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:56'),(30,NULL,'arrival.flagged','arrival',8,'Device already logged this destination 7 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:56'),(31,NULL,'arrival.flagged','arrival',9,'Device already logged this destination 8 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-09 15:30:56'),(32,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-09 15:31:39'),(33,1,'auth.login','admin',1,'Signed in',_binary '��	','curl/8.19.0','2026-08-10 04:35:33'),(34,1,'auth.login','admin',1,'Signed in',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 04:41:47'),(35,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:06:21'),(36,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:06:45'),(37,1,'arrival.export','arrival',NULL,'2 arrival record(s) exported to CSV',_binary '\0\0','curl/8.19.0','2026-08-10 05:06:46'),(38,1,'arrival.export','arrival',NULL,'1 arrival record(s) exported to CSV',_binary '\0\0','curl/8.19.0','2026-08-10 05:06:47'),(39,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:07:34'),(40,1,'arrival.manual','arrival',12,'Manual entry recorded (6 visitor(s))',_binary '\0\0','curl/8.19.0','2026-08-10 05:07:34'),(41,1,'arrival.void','arrival',11,'Voided arrival at \"Mt. Matutum Viewpoint\": Duplicate confirmed with the site attendant',_binary '\0\0','curl/8.19.0','2026-08-10 05:07:35'),(42,1,'auth.logout','admin',1,'Signed out',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 05:20:22'),(43,1,'auth.login','admin',1,'Signed in',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 05:20:56'),(44,NULL,'arrival.flagged','arrival',13,'Device already logged this destination 1 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-10 05:30:12'),(45,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:30:13'),(46,1,'feedback.published','feedback',1,'Published a 5-star review of \"Mt. Matutum Viewpoint\"',_binary '\0\0','curl/8.19.0','2026-08-10 05:30:14'),(47,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:30:37'),(48,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:31:33'),(49,1,'feedback.hidden','feedback',1,'Hidden a 5-star review of \"Mt. Matutum Viewpoint\"',_binary '\0\0','curl/8.19.0','2026-08-10 05:31:34'),(50,1,'feedback.published','feedback',1,'Published a 5-star review of \"Mt. Matutum Viewpoint\"',_binary '\0\0','curl/8.19.0','2026-08-10 05:31:34'),(51,NULL,'arrival.flagged','arrival',14,'Device already logged this destination 2 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-10 05:31:35'),(52,NULL,'arrival.flagged','arrival',15,'Device already logged this destination 3 time(s) within 6 hours',_binary '\0\0','curl/8.19.0','2026-08-10 05:32:38'),(53,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:55:55'),(54,1,'manager.create','manager',1,'Registered Ramon Bautista',_binary '\0\0','curl/8.19.0','2026-08-10 05:55:56'),(55,1,'manager.create','manager',2,'Registered Elena Gomez',_binary '\0\0','curl/8.19.0','2026-08-10 05:55:56'),(56,1,'manager.create','manager',3,'Registered Pedro Lim',_binary '\0\0','curl/8.19.0','2026-08-10 05:55:56'),(57,1,'announcement.create','announcement',1,'Created \"Trail Advisory: Upper Circuit Temporarily Closed\"',_binary '\0\0','curl/8.19.0','2026-08-10 05:55:57'),(58,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 05:57:45'),(59,1,'destination.photos','destination',4,'1 photo(s) added to \"Bulol Falls\"',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 05:59:02'),(60,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:02:01'),(61,1,'announcement.dispatch','announcement',1,'Dispatched \"Trail Advisory: Upper Circuit Temporarily Closed\" — 3 sent, 0 failed, 0 skipped',_binary '\0\0','curl/8.19.0','2026-08-10 06:02:01'),(62,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:03:20'),(63,1,'announcement.dispatch','announcement',1,'Dispatched \"Trail Advisory: Upper Circuit Temporarily Closed\" — 0 sent, 0 failed, 0 skipped',_binary '\0\0','curl/8.19.0','2026-08-10 06:03:20'),(64,1,'announcement.dispatch','announcement',1,'Dispatched \"Trail Advisory: Upper Circuit Temporarily Closed\" — 0 sent, 0 failed, 0 skipped',_binary '\0\0','curl/8.19.0','2026-08-10 06:03:20'),(65,1,'announcement.create','announcement',2,'Created \"Kalsangi Pine Ridge Family Day\"',_binary '\0\0','curl/8.19.0','2026-08-10 06:03:21'),(66,1,'destination.update','destination',4,'Updated \"Bulol Falls\"',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 06:09:47'),(67,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:21:39'),(68,1,'report.export','report',NULL,'Monthly report exported for August 2026',_binary '\0\0','curl/8.19.0','2026-08-10 06:21:40'),(69,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:34:20'),(70,1,'account.password','admin',1,'Changed own password',_binary '\0\0','curl/8.19.0','2026-08-10 06:34:22'),(71,1,'arrival.approve','arrival',15,'Approved flagged arrival at \"Mt. Matutum Viewpoint\"',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 06:34:57'),(72,1,'arrival.approve','arrival',14,'Approved flagged arrival at \"Mt. Matutum Viewpoint\"',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 06:35:21'),(73,1,'arrival.approve','arrival',13,'Approved flagged arrival at \"Mt. Matutum Viewpoint\"',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 06:35:26'),(74,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:35:41'),(75,1,'account.create','admin',2,'Created staff account \"staff1\"',_binary '\0\0','curl/8.19.0','2026-08-10 06:35:41'),(76,2,'auth.login','admin',2,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:35:42'),(77,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:37:45'),(78,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:41:06'),(79,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:43:32'),(80,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:46:58'),(81,1,'auth.login','admin',1,'Signed in',_binary '\0\0','curl/8.19.0','2026-08-10 06:48:07'),(82,1,'report.export','report',NULL,'Monthly report exported for August 2026',_binary '\0\0','curl/8.19.0','2026-08-10 06:48:09'),(83,1,'auth.logout','admin',1,'Signed out',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 08:03:27'),(84,1,'auth.locked','admin',1,'Account locked after 5 failed attempts',_binary '\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 08:04:43'),(85,1,'auth.login','admin',1,'Signed in',_binary '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 10:28:05'),(86,1,'auth.logout','admin',1,'Signed out',_binary '\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36','2026-08-10 10:31:01');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `role` enum('officer','staff') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `failed_attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_username` (`username`),
  UNIQUE KEY `uq_admin_email` (`email`),
  KEY `idx_admin_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` (`id`, `full_name`, `username`, `email`, `password_hash`, `password_changed_at`, `role`, `is_active`, `failed_attempts`, `locked_until`, `last_login_at`, `created_at`, `updated_at`) VALUES (1,'Municipal Tourism Officer','officer','tourism@tampakan.gov.ph','$argon2id$v=19$m=65536,t=4,p=1$ZkFUQldLMmE1Y3Vhc0loRw$0EWRXsuubTUTTMSdsJ6+fohiioNqB0YhdAb+AmW8N1I','2026-08-10 14:34:22','officer',1,0,NULL,'2026-08-10 18:28:05','2026-08-09 14:46:12','2026-08-10 10:28:05'),(2,'Ana Reyes','staff1','ana.reyes@tampakan.gov.ph','$argon2id$v=19$m=65536,t=4,p=1$U0JRNlB2bEpUaVcuaWJ0bQ$v5sw3wyNYjjFRavR6veC6v+vccvNJrZSQ/k0U8NI1gg',NULL,'staff',1,0,NULL,'2026-08-10 14:35:42','2026-08-10 06:35:41','2026-08-10 06:35:42');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('announcement','advisory','schedule','event','closure','reminder') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'announcement',
  `audience` enum('public','managers','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `destination_id` int unsigned DEFAULT NULL,
  `banner_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `event_location` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publish_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ann_slug` (`slug`),
  KEY `idx_ann_publish` (`status`,`publish_at`),
  KEY `idx_ann_type` (`type`),
  KEY `idx_ann_audience` (`audience`),
  KEY `fk_ann_dest` (`destination_id`),
  KEY `fk_ann_author` (`created_by`),
  CONSTRAINT `fk_ann_author` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ann_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
INSERT INTO `announcements` (`id`, `title`, `slug`, `body`, `summary`, `type`, `audience`, `status`, `destination_id`, `banner_path`, `event_date`, `event_location`, `publish_at`, `expires_at`, `created_by`, `created_at`, `updated_at`) VALUES (1,'Trail Advisory: Upper Circuit Temporarily Closed','trail-advisory-upper-circuit-temporarily-closed','The upper section of the Tampakan Highland Trail is temporarily closed for slope rehabilitation works. Guided day hikes on the lower loop continue as scheduled. Please advise arriving visitors accordingly.','The upper section of the highland trail is closed for slope rehabilitation.','advisory','both','published',NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-08-10 05:55:57','2026-08-10 05:55:57'),(2,'Kalsangi Pine Ridge Family Day','kalsangi-pine-ridge-family-day','Join the Municipal Tourism Office for a free family day at Kalsangi Pine Ridge. Guided walks, tree planting, and local food stalls.','A free family day at the pine ridge with guided walks.','event','public','published',NULL,NULL,'2026-12-12','Barangay Tablu',NULL,NULL,1,'2026-08-10 06:03:21','2026-08-10 06:03:21');
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `arrival_daily_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `arrival_daily_summary` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `total_records` int unsigned NOT NULL DEFAULT '0',
  `total_visitors` int unsigned NOT NULL DEFAULT '0',
  `local_count` int unsigned NOT NULL DEFAULT '0',
  `domestic_count` int unsigned NOT NULL DEFAULT '0',
  `foreign_count` int unsigned NOT NULL DEFAULT '0',
  `ofw_count` int unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_summary` (`destination_id`,`visit_date`),
  KEY `idx_summary_date` (`visit_date`),
  CONSTRAINT `fk_summary_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=344 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `arrival_daily_summary` WRITE;
/*!40000 ALTER TABLE `arrival_daily_summary` DISABLE KEYS */;
INSERT INTO `arrival_daily_summary` (`id`, `destination_id`, `visit_date`, `total_records`, `total_visitors`, `local_count`, `domestic_count`, `foreign_count`, `ofw_count`, `updated_at`) VALUES (287,1,'2026-06-26',1,1,0,0,1,0,'2026-08-10 06:35:26'),(288,1,'2026-06-27',1,2,0,2,0,0,'2026-08-10 06:35:26'),(289,1,'2026-06-29',1,3,0,3,0,0,'2026-08-10 06:35:26'),(290,1,'2026-06-30',1,6,6,0,0,0,'2026-08-10 06:35:26'),(291,1,'2026-07-01',3,11,0,6,5,0,'2026-08-10 06:35:26'),(292,1,'2026-07-03',2,6,0,0,4,2,'2026-08-10 06:35:26'),(293,1,'2026-07-05',1,3,0,0,0,3,'2026-08-10 06:35:26'),(294,1,'2026-07-07',2,6,0,2,4,0,'2026-08-10 06:35:26'),(295,1,'2026-07-08',3,14,0,14,0,0,'2026-08-10 06:35:26'),(296,1,'2026-07-09',1,4,0,0,4,0,'2026-08-10 06:35:26'),(297,1,'2026-07-10',3,11,4,7,0,0,'2026-08-10 06:35:26'),(298,1,'2026-07-12',2,8,0,8,0,0,'2026-08-10 06:35:26'),(299,1,'2026-07-14',2,8,0,5,3,0,'2026-08-10 06:35:26'),(300,1,'2026-07-15',1,3,0,0,0,3,'2026-08-10 06:35:26'),(301,1,'2026-07-17',1,2,0,2,0,0,'2026-08-10 06:35:26'),(302,1,'2026-07-19',2,10,5,5,0,0,'2026-08-10 06:35:26'),(303,1,'2026-07-22',1,4,4,0,0,0,'2026-08-10 06:35:26'),(304,1,'2026-07-23',2,6,0,4,0,2,'2026-08-10 06:35:26'),(305,1,'2026-07-25',1,1,0,0,1,0,'2026-08-10 06:35:26'),(306,1,'2026-07-26',2,8,0,5,3,0,'2026-08-10 06:35:26'),(307,1,'2026-07-27',2,3,0,2,1,0,'2026-08-10 06:35:26'),(308,1,'2026-07-31',1,5,5,0,0,0,'2026-08-10 06:35:26'),(309,1,'2026-08-01',2,6,3,3,0,0,'2026-08-10 06:35:26'),(310,1,'2026-08-02',1,2,0,0,0,2,'2026-08-10 06:35:26'),(311,1,'2026-08-03',2,3,3,0,0,0,'2026-08-10 06:35:26'),(312,1,'2026-08-05',2,8,0,4,0,4,'2026-08-10 06:35:26'),(313,1,'2026-08-06',3,8,1,4,3,0,'2026-08-10 06:35:26'),(314,1,'2026-08-07',1,2,0,0,2,0,'2026-08-10 06:35:26'),(315,1,'2026-08-09',1,3,0,3,0,0,'2026-08-10 06:35:26'),(316,1,'2026-08-10',3,4,2,2,0,0,'2026-08-10 06:35:26'),(317,4,'2026-06-26',1,6,0,6,0,0,'2026-08-10 06:35:26'),(318,4,'2026-06-27',1,2,0,2,0,0,'2026-08-10 06:35:26'),(319,4,'2026-06-29',2,4,0,3,1,0,'2026-08-10 06:35:26'),(320,4,'2026-07-02',1,5,0,5,0,0,'2026-08-10 06:35:26'),(321,4,'2026-07-03',2,8,0,8,0,0,'2026-08-10 06:35:26'),(322,4,'2026-07-04',1,5,0,5,0,0,'2026-08-10 06:35:26'),(323,4,'2026-07-05',2,10,0,6,0,4,'2026-08-10 06:35:26'),(324,4,'2026-07-06',1,2,0,2,0,0,'2026-08-10 06:35:26'),(325,4,'2026-07-09',2,7,0,4,0,3,'2026-08-10 06:35:26'),(326,4,'2026-07-10',1,6,0,0,0,6,'2026-08-10 06:35:26'),(327,4,'2026-07-14',2,4,0,2,0,2,'2026-08-10 06:35:26'),(328,4,'2026-07-15',2,3,0,3,0,0,'2026-08-10 06:35:26'),(329,4,'2026-07-17',3,7,5,2,0,0,'2026-08-10 06:35:26'),(330,4,'2026-07-18',3,12,0,5,6,1,'2026-08-10 06:35:26'),(331,4,'2026-07-21',2,4,2,0,2,0,'2026-08-10 06:35:26'),(332,4,'2026-07-22',2,4,0,4,0,0,'2026-08-10 06:35:26'),(333,4,'2026-07-23',2,7,0,2,5,0,'2026-08-10 06:35:26'),(334,4,'2026-07-24',1,3,0,3,0,0,'2026-08-10 06:35:26'),(335,4,'2026-07-26',1,3,0,0,3,0,'2026-08-10 06:35:26'),(336,4,'2026-07-31',2,7,0,7,0,0,'2026-08-10 06:35:26'),(337,4,'2026-08-01',1,6,0,6,0,0,'2026-08-10 06:35:26'),(338,4,'2026-08-02',3,11,5,0,6,0,'2026-08-10 06:35:26'),(339,4,'2026-08-03',1,2,0,2,0,0,'2026-08-10 06:35:26'),(340,4,'2026-08-06',1,6,0,0,6,0,'2026-08-10 06:35:26'),(341,4,'2026-08-07',1,2,2,0,0,0,'2026-08-10 06:35:26'),(342,4,'2026-08-08',1,1,0,0,0,1,'2026-08-10 06:35:26'),(343,4,'2026-08-10',1,6,6,0,0,0,'2026-08-10 06:35:26');
/*!40000 ALTER TABLE `arrival_daily_summary` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` (`id`, `name`, `slug`, `icon`, `sort_order`, `created_at`) VALUES (1,'Nature','nature','fa-leaf',1,'2026-08-09 14:46:11'),(2,'Waterfalls','waterfalls','fa-water',2,'2026-08-09 14:46:11'),(3,'Adventure','adventure','fa-person-hiking',3,'2026-08-09 14:46:11'),(4,'Culture','culture','fa-drum',4,'2026-08-09 14:46:11'),(5,'Eco-Tourism','eco-tourism','fa-seedling',5,'2026-08-09 14:46:11'),(6,'Agri-Tourism','agri-tourism','fa-tractor',6,'2026-08-09 14:46:11'),(7,'Historical','historical','fa-landmark',7,'2026-08-09 14:46:11');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `destination_managers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `destination_managers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int unsigned NOT NULL,
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_opt_in` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mgr_dest` (`destination_id`),
  KEY `idx_mgr_sms` (`is_active`,`sms_opt_in`),
  CONSTRAINT `fk_mgr_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `destination_managers` WRITE;
/*!40000 ALTER TABLE `destination_managers` DISABLE KEYS */;
INSERT INTO `destination_managers` (`id`, `destination_id`, `full_name`, `position`, `mobile_number`, `email`, `sms_opt_in`, `is_active`, `created_at`, `updated_at`) VALUES (1,1,'Ramon Bautista','Site Caretaker','+639171112233',NULL,1,1,'2026-08-10 05:55:56','2026-08-10 05:55:56'),(2,4,'Elena Gomez','Barangay Tourism Officer','+639182223344',NULL,1,1,'2026-08-10 05:55:56','2026-08-10 05:55:56'),(3,1,'Pedro Lim','Assistant Caretaker','+639193334455',NULL,1,1,'2026-08-10 05:55:56','2026-08-10 05:55:56');
/*!40000 ALTER TABLE `destination_managers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `destination_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `destination_photos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int unsigned NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `caption` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_cover` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_photo_dest` (`destination_id`,`sort_order`),
  CONSTRAINT `fk_photo_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `destination_photos` WRITE;
/*!40000 ALTER TABLE `destination_photos` DISABLE KEYS */;
INSERT INTO `destination_photos` (`id`, `destination_id`, `file_path`, `caption`, `is_cover`, `sort_order`, `created_at`) VALUES (1,4,'uploads/destinations/397674706c2a75106659f147c167fbc3.jpg',NULL,1,0,'2026-08-10 05:59:02');
/*!40000 ALTER TABLE `destination_photos` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `destinations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `destinations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned DEFAULT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `short_description` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `history` text COLLATE utf8mb4_unicode_ci,
  `operating_hours` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entrance_fee` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facilities` json DEFAULT NULL,
  `reminders` text COLLATE utf8mb4_unicode_ci,
  `barangay` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `contact_person` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_token` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qr_version` smallint unsigned NOT NULL DEFAULT '1',
  `qr_rotated_at` datetime DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dest_slug` (`slug`),
  UNIQUE KEY `uq_dest_qr` (`qr_token`),
  KEY `idx_dest_status` (`status`),
  KEY `idx_dest_category` (`category_id`),
  KEY `idx_dest_featured` (`is_featured`,`status`),
  KEY `fk_dest_author` (`created_by`),
  CONSTRAINT `fk_dest_author` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dest_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `destinations` WRITE;
/*!40000 ALTER TABLE `destinations` DISABLE KEYS */;
INSERT INTO `destinations` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `history`, `operating_hours`, `entrance_fee`, `facilities`, `reminders`, `barangay`, `address`, `latitude`, `longitude`, `contact_person`, `contact_phone`, `contact_email`, `qr_token`, `qr_version`, `qr_rotated_at`, `is_featured`, `status`, `created_by`, `created_at`, `updated_at`) VALUES (1,1,'Mt. Matutum Viewpoint','mt-matutum-viewpoint','A highland lookout framing the perfect cone of Mt. Matutum.','Sweeping views over the valley, best at sunrise when cloud fills the basin.','Named for the volcano it faces','Daily, 5:00 AM - 5:00 PM','PHP 30 per person','[\"Parking\", \"Restroom\", \"Guide\", \"Viewing Deck\"]','Bring a jacket. Mornings drop below 18C.','Liberty',NULL,6.4630000,124.9480000,'Barangay Tourism Desk',NULL,NULL,'5f3391cba14cb06bf9af3fa6a6143ba3',1,NULL,1,'active',1,'2026-08-09 15:06:09','2026-08-09 15:06:09'),(4,2,'Bulol Falls','bulol-falls','A cool multi-tiered cascade, now with a new viewing deck.',NULL,NULL,NULL,NULL,NULL,NULL,'Danlag',NULL,6.4010000,124.9520000,NULL,NULL,NULL,'68f8e49b99eddc2c7a43f41e8b4e1991',1,NULL,1,'active',1,'2026-08-09 15:17:26','2026-08-10 06:09:47');
/*!40000 ALTER TABLE `destinations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int unsigned NOT NULL,
  `arrival_id` bigint unsigned DEFAULT NULL,
  `visitor_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rating` tinyint unsigned NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','published','hidden') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `moderated_by` int unsigned DEFAULT NULL,
  `moderated_at` datetime DEFAULT NULL,
  `device_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fb_dest` (`destination_id`,`status`),
  KEY `idx_fb_status` (`status`),
  KEY `fk_fb_arr` (`arrival_id`),
  KEY `fk_fb_mod` (`moderated_by`),
  CONSTRAINT `fk_fb_arr` FOREIGN KEY (`arrival_id`) REFERENCES `tourist_arrivals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fb_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fb_mod` FOREIGN KEY (`moderated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fb_rating` CHECK ((`rating` between 1 and 5))
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` (`id`, `destination_id`, `arrival_id`, `visitor_name`, `rating`, `comment`, `status`, `moderated_by`, `moderated_at`, `device_hash`, `created_at`) VALUES (1,1,13,'Maria S.',5,'The sunrise view was worth the early trek. Guides were well prepared.','published',1,'2026-08-10 13:31:34','42a340d4612c753ac02b88eb66036f2773ccf4fb5a374039d32d28e58b2eb1e5','2026-08-10 05:30:12'),(4,1,15,NULL,4,'Triple submit test','pending',NULL,NULL,'42a340d4612c753ac02b88eb66036f2773ccf4fb5a374039d32d28e58b2eb1e5','2026-08-10 05:32:39');
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int unsigned NOT NULL,
  `manager_id` int unsigned NOT NULL,
  `channel` enum('sms','portal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `status` enum('queued','sent','failed','delivered') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `provider_ref` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `sent_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ntf_queue` (`status`,`attempts`),
  KEY `idx_ntf_ann` (`announcement_id`),
  KEY `fk_ntf_mgr` (`manager_id`),
  CONSTRAINT `fk_ntf_ann` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ntf_mgr` FOREIGN KEY (`manager_id`) REFERENCES `destination_managers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` (`id`, `announcement_id`, `manager_id`, `channel`, `status`, `provider_ref`, `error_message`, `attempts`, `sent_at`, `read_at`, `created_at`) VALUES (4,1,2,'sms','sent','log-1ba68d3149eb',NULL,1,'2026-08-10 14:02:01',NULL,'2026-08-10 06:02:01'),(5,1,3,'sms','sent','log-a7dc2e063f1e',NULL,1,'2026-08-10 14:02:01',NULL,'2026-08-10 06:02:01'),(6,1,1,'sms','sent','log-b4c55adb58c0',NULL,1,'2026-08-10 14:02:01',NULL,'2026-08-10 06:02:01');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('daily','monthly','quarterly','annual','custom') COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `parameters` json DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rep_period` (`type`,`period_start`,`period_end`),
  KEY `fk_rep_by` (`generated_by`),
  CONSTRAINT `fk_rep_by` FOREIGN KEY (`generated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `setting_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('dedupe_window_hours','6','2026-08-09 14:46:11'),('office_address','Tampakan Municipal Hall, Kamagong St., Brgy. Poblacion, Tampakan, South Cotabato','2026-08-09 14:46:11'),('office_email','','2026-08-09 14:46:11'),('office_municipality','Municipality of Tampakan','2026-08-09 14:46:11'),('office_name','Municipal Tourism Office','2026-08-09 14:46:11'),('office_phone','','2026-08-09 14:46:11'),('office_province','South Cotabato','2026-08-09 14:46:11'),('proximity_metres','500','2026-08-09 14:46:11'),('rate_limit_per_15m','10','2026-08-09 14:46:11'),('retention_months','36','2026-08-09 14:46:11');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `tourist_arrivals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tourist_arrivals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `destination_id` int unsigned NOT NULL,
  `visit_date` date NOT NULL,
  `arrived_at` datetime NOT NULL,
  `full_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `age_bracket` enum('under18','18-24','25-34','35-44','45-54','55-64','65plus') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sex` enum('male','female','prefer_not_to_say') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tourist_type` enum('local','domestic','foreign','overseas_filipino') COLLATE utf8mb4_unicode_ci NOT NULL,
  `stay_type` enum('day_trip','overnight') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origin_country` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origin_province` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origin_city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` enum('leisure','business','education','religious','vfr','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `companions_count` smallint unsigned NOT NULL DEFAULT '0',
  `total_visitors` smallint unsigned NOT NULL DEFAULT '1',
  `consent_given` tinyint(1) NOT NULL DEFAULT '0',
  `source` enum('qr','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'qr',
  `recorded_by` int unsigned DEFAULT NULL,
  `qr_version_used` smallint unsigned DEFAULT NULL,
  `device_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `distance_m` int unsigned DEFAULT NULL,
  `status` enum('valid','flagged','voided') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'valid',
  `flag_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `voided_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `anonymised_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_arr_dest_date` (`destination_id`,`visit_date`),
  KEY `idx_arr_date` (`visit_date`),
  KEY `idx_arr_type` (`tourist_type`),
  KEY `idx_arr_status` (`status`),
  KEY `idx_arr_dedupe` (`device_hash`,`destination_id`,`visit_date`),
  KEY `fk_arr_by` (`recorded_by`),
  KEY `fk_arr_voider` (`voided_by`),
  CONSTRAINT `fk_arr_by` FOREIGN KEY (`recorded_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_arr_dest` FOREIGN KEY (`destination_id`) REFERENCES `destinations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_arr_voider` FOREIGN KEY (`voided_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `tourist_arrivals` WRITE;
/*!40000 ALTER TABLE `tourist_arrivals` DISABLE KEYS */;
INSERT INTO `tourist_arrivals` (`id`, `destination_id`, `visit_date`, `arrived_at`, `full_name`, `age_bracket`, `sex`, `contact_number`, `email`, `tourist_type`, `stay_type`, `nationality`, `origin_country`, `origin_province`, `origin_city`, `purpose`, `companions_count`, `total_visitors`, `consent_given`, `source`, `recorded_by`, `qr_version_used`, `device_hash`, `distance_m`, `status`, `flag_reason`, `void_reason`, `voided_by`, `created_at`, `anonymised_at`) VALUES (10,1,'2026-08-09','2026-08-09 23:39:20','Ej Romero',NULL,NULL,'+639103443488','ejromero294@gmail.com','domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos City',NULL,2,3,1,'qr',NULL,1,'e6a76244f5b5064569eae31c109fe982a6b717d3f3267ef86db3fd5976d82522',NULL,'valid',NULL,NULL,NULL,'2026-08-09 15:39:20',NULL),(11,1,'2026-08-10','2026-08-10 13:06:21',NULL,NULL,NULL,NULL,NULL,'foreign','overnight',NULL,'Australia',NULL,NULL,'leisure',2,3,1,'qr',NULL,1,'42a340d4612c753ac02b88eb66036f2773ccf4fb5a374039d32d28e58b2eb1e5',NULL,'voided',NULL,'Duplicate confirmed with the site attendant',1,'2026-08-10 05:06:21',NULL),(12,4,'2026-08-10','2026-08-10 13:07:34',NULL,NULL,NULL,NULL,NULL,'local','day_trip',NULL,'Philippines','South Cotabato','Tampakan',NULL,5,6,0,'manual',1,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 05:07:34',NULL),(13,1,'2026-08-10','2026-08-10 13:30:12',NULL,NULL,NULL,NULL,NULL,'domestic',NULL,NULL,NULL,NULL,NULL,NULL,1,2,1,'qr',NULL,1,'42a340d4612c753ac02b88eb66036f2773ccf4fb5a374039d32d28e58b2eb1e5',NULL,'valid',NULL,NULL,NULL,'2026-08-10 05:30:12',NULL),(14,1,'2026-08-10','2026-08-10 13:31:35',NULL,NULL,NULL,NULL,NULL,'local',NULL,NULL,NULL,NULL,NULL,NULL,0,1,1,'qr',NULL,1,'42a340d4612c753ac02b88eb66036f2773ccf4fb5a374039d32d28e58b2eb1e5',NULL,'valid',NULL,NULL,NULL,'2026-08-10 05:31:35',NULL),(15,1,'2026-08-10','2026-08-10 13:32:38',NULL,NULL,NULL,NULL,NULL,'local',NULL,NULL,NULL,NULL,NULL,NULL,0,1,1,'qr',NULL,1,'42a340d4612c753ac02b88eb66036f2773ccf4fb5a374039d32d28e58b2eb1e5',NULL,'valid',NULL,NULL,NULL,'2026-08-10 05:32:38',NULL),(16,4,'2026-06-26','2026-06-26 14:42:00',NULL,'45-54','female',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(17,1,'2026-06-26','2026-06-26 16:52:00',NULL,'18-24','male',NULL,NULL,'foreign','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(18,4,'2026-06-27','2026-06-27 12:55:00',NULL,'18-24','male',NULL,NULL,'domestic','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(19,1,'2026-06-27','2026-06-27 07:53:00',NULL,'25-34','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Koronadal','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(20,1,'2026-06-29','2026-06-29 11:39:00',NULL,'18-24','female',NULL,NULL,'domestic','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(21,4,'2026-06-29','2026-06-29 10:24:00',NULL,'18-24','female',NULL,NULL,'foreign','overnight',NULL,'South Korea','Seoul','Seoul','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(22,4,'2026-06-29','2026-06-29 07:57:00',NULL,'18-24','male',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(23,1,'2026-06-30','2026-06-30 07:18:00',NULL,'25-34','female',NULL,NULL,'local','overnight',NULL,'Philippines','South Cotabato','General Santos','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(24,1,'2026-07-01','2026-07-01 11:13:00',NULL,'under18','female',NULL,NULL,'domestic','day_trip',NULL,'South Korea','Seoul','Seoul','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(25,1,'2026-07-01','2026-07-01 14:01:00',NULL,'under18','male',NULL,NULL,'domestic','day_trip',NULL,'South Korea','Seoul','Seoul','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(26,1,'2026-07-01','2026-07-01 12:24:00',NULL,'55-64','female',NULL,NULL,'foreign','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(27,4,'2026-07-02','2026-07-02 13:53:00',NULL,'18-24','male',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Koronadal','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(28,4,'2026-07-03','2026-07-03 09:30:00',NULL,'35-44','female',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(29,4,'2026-07-03','2026-07-03 13:18:00',NULL,'18-24','male',NULL,NULL,'domestic','overnight',NULL,'South Korea','Seoul','Seoul','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(30,1,'2026-07-03','2026-07-03 07:44:00',NULL,'35-44','male',NULL,NULL,'overseas_filipino','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(31,1,'2026-07-03','2026-07-03 13:03:00',NULL,'45-54','male',NULL,NULL,'foreign','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(32,4,'2026-07-04','2026-07-04 14:31:00',NULL,'45-54','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(33,4,'2026-07-05','2026-07-05 16:27:00',NULL,'18-24','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(34,4,'2026-07-05','2026-07-05 09:41:00',NULL,'under18','female',NULL,NULL,'overseas_filipino','overnight',NULL,'South Korea','Seoul','Seoul','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(35,1,'2026-07-05','2026-07-05 13:33:00',NULL,'18-24','male',NULL,NULL,'overseas_filipino','overnight',NULL,'Australia','Victoria','Melbourne','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(36,4,'2026-07-06','2026-07-06 13:13:00',NULL,'45-54','female',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(37,1,'2026-07-07','2026-07-07 14:15:00',NULL,'55-64','male',NULL,NULL,'foreign','overnight',NULL,'Australia','Victoria','Melbourne','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(38,1,'2026-07-07','2026-07-07 15:17:00',NULL,'25-34','female',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','General Santos','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(39,1,'2026-07-08','2026-07-08 11:44:00',NULL,'18-24','male',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Koronadal','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(40,1,'2026-07-08','2026-07-08 10:40:00',NULL,'45-54','female',NULL,NULL,'domestic','day_trip',NULL,'South Korea','Seoul','Seoul','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(41,1,'2026-07-08','2026-07-08 16:54:00',NULL,'25-34','female',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','General Santos','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(42,4,'2026-07-09','2026-07-09 15:53:00',NULL,'25-34','male',NULL,NULL,'domestic','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(43,1,'2026-07-09','2026-07-09 11:13:00',NULL,'under18','female',NULL,NULL,'foreign','overnight',NULL,'Australia','Victoria','Melbourne','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(44,4,'2026-07-09','2026-07-09 10:36:00',NULL,'under18','female',NULL,NULL,'overseas_filipino','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(45,1,'2026-07-10','2026-07-10 15:49:00',NULL,'45-54','female',NULL,NULL,'local','overnight',NULL,'Philippines','South Cotabato','General Santos','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(46,4,'2026-07-10','2026-07-10 16:12:00',NULL,'45-54','female',NULL,NULL,'overseas_filipino','day_trip',NULL,'South Korea','Seoul','Seoul','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(47,1,'2026-07-10','2026-07-10 11:22:00',NULL,'45-54','male',NULL,NULL,'domestic','overnight',NULL,'South Korea','Seoul','Seoul','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(48,1,'2026-07-10','2026-07-10 12:29:00',NULL,'55-64','female',NULL,NULL,'domestic','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(49,1,'2026-07-12','2026-07-12 07:06:00',NULL,'55-64','female',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(50,1,'2026-07-12','2026-07-12 14:47:00',NULL,'18-24','male',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(51,4,'2026-07-14','2026-07-14 15:49:00',NULL,'25-34','male',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(52,1,'2026-07-14','2026-07-14 11:45:00',NULL,'55-64','female',NULL,NULL,'foreign','overnight',NULL,'Philippines','Davao del Sur','Davao City','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(53,4,'2026-07-14','2026-07-14 16:19:00',NULL,'under18','male',NULL,NULL,'overseas_filipino','day_trip',NULL,'Philippines','Davao del Sur','Davao City','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:54',NULL),(54,1,'2026-07-14','2026-07-14 08:00:00',NULL,'under18','female',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(55,4,'2026-07-15','2026-07-15 11:44:00',NULL,'45-54','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','Davao del Sur','Davao City','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(56,1,'2026-07-15','2026-07-15 07:50:00',NULL,'18-24','male',NULL,NULL,'overseas_filipino','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(57,4,'2026-07-15','2026-07-15 14:51:00',NULL,'45-54','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(58,4,'2026-07-17','2026-07-17 15:35:00',NULL,'55-64','female',NULL,NULL,'local','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(59,4,'2026-07-17','2026-07-17 15:15:00',NULL,'under18','female',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Koronadal','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(60,1,'2026-07-17','2026-07-17 11:07:00',NULL,'35-44','male',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(61,4,'2026-07-17','2026-07-17 09:23:00',NULL,'18-24','male',NULL,NULL,'local','overnight',NULL,'Philippines','Davao del Sur','Davao City','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(62,4,'2026-07-18','2026-07-18 16:55:00',NULL,'18-24','male',NULL,NULL,'overseas_filipino','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(63,4,'2026-07-18','2026-07-18 12:20:00',NULL,'under18','female',NULL,NULL,'foreign','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(64,4,'2026-07-18','2026-07-18 11:44:00',NULL,'18-24','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(65,1,'2026-07-19','2026-07-19 15:57:00',NULL,'18-24','male',NULL,NULL,'local','overnight',NULL,'Philippines','Davao del Sur','Davao City','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(66,1,'2026-07-19','2026-07-19 14:38:00',NULL,'18-24','female',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Koronadal','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(67,4,'2026-07-21','2026-07-21 14:03:00',NULL,'35-44','female',NULL,NULL,'foreign','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(68,4,'2026-07-21','2026-07-21 13:28:00',NULL,'55-64','male',NULL,NULL,'local','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(69,4,'2026-07-22','2026-07-22 16:26:00',NULL,'25-34','female',NULL,NULL,'domestic','day_trip',NULL,'South Korea','Seoul','Seoul','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(70,1,'2026-07-22','2026-07-22 13:02:00',NULL,'under18','female',NULL,NULL,'local','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(71,4,'2026-07-22','2026-07-22 11:10:00',NULL,'35-44','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(72,4,'2026-07-23','2026-07-23 12:42:00',NULL,'45-54','male',NULL,NULL,'foreign','overnight',NULL,'Philippines','South Cotabato','Koronadal','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(73,1,'2026-07-23','2026-07-23 10:09:00',NULL,'18-24','female',NULL,NULL,'overseas_filipino','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(74,4,'2026-07-23','2026-07-23 15:05:00',NULL,'under18','female',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(75,1,'2026-07-23','2026-07-23 16:14:00',NULL,'25-34','male',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(76,4,'2026-07-24','2026-07-24 08:03:00',NULL,'18-24','female',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(77,1,'2026-07-25','2026-07-25 14:57:00',NULL,'35-44','male',NULL,NULL,'foreign','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(78,4,'2026-07-26','2026-07-26 07:29:00',NULL,'55-64','female',NULL,NULL,'foreign','day_trip',NULL,'Philippines','South Cotabato','Koronadal','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(79,1,'2026-07-26','2026-07-26 11:50:00',NULL,'18-24','female',NULL,NULL,'domestic','overnight',NULL,'Australia','Victoria','Melbourne','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(80,1,'2026-07-26','2026-07-26 15:25:00',NULL,'under18','female',NULL,NULL,'foreign','day_trip',NULL,'Philippines','South Cotabato','Koronadal','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(81,1,'2026-07-27','2026-07-27 15:25:00',NULL,'25-34','female',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(82,1,'2026-07-27','2026-07-27 09:41:00',NULL,'25-34','male',NULL,NULL,'foreign','day_trip',NULL,'Philippines','Davao del Sur','Davao City','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(83,1,'2026-07-31','2026-07-31 11:51:00',NULL,'under18','female',NULL,NULL,'local','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(84,4,'2026-07-31','2026-07-31 12:26:00',NULL,'under18','female',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',4,5,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(85,4,'2026-07-31','2026-07-31 12:06:00',NULL,'55-64','female',NULL,NULL,'domestic','overnight',NULL,'South Korea','Seoul','Seoul','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(86,1,'2026-08-01','2026-08-01 11:21:00',NULL,'45-54','female',NULL,NULL,'local','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(87,4,'2026-08-01','2026-08-01 09:47:00',NULL,'25-34','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','Tampakan','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(88,1,'2026-08-01','2026-08-01 13:34:00',NULL,'18-24','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(89,4,'2026-08-02','2026-08-02 09:07:00',NULL,'under18','male',NULL,NULL,'foreign','day_trip',NULL,'Philippines','Davao del Sur','Davao City','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(90,4,'2026-08-02','2026-08-02 15:19:00',NULL,'55-64','female',NULL,NULL,'local','overnight',NULL,'Philippines','South Cotabato','Koronadal','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(91,1,'2026-08-02','2026-08-02 08:41:00',NULL,'18-24','male',NULL,NULL,'overseas_filipino','overnight',NULL,'Philippines','Davao del Sur','Davao City','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(92,4,'2026-08-02','2026-08-02 14:15:00',NULL,'under18','male',NULL,NULL,'local','overnight',NULL,'Australia','Victoria','Melbourne','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(93,4,'2026-08-03','2026-08-03 08:18:00',NULL,'25-34','male',NULL,NULL,'domestic','day_trip',NULL,'Australia','Victoria','Melbourne','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(94,1,'2026-08-03','2026-08-03 12:56:00',NULL,'35-44','male',NULL,NULL,'local','overnight',NULL,'Philippines','Davao del Sur','Davao City','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(95,1,'2026-08-03','2026-08-03 13:18:00',NULL,'55-64','female',NULL,NULL,'local','overnight',NULL,'Philippines','Davao del Sur','Davao City','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(96,1,'2026-08-05','2026-08-05 13:17:00',NULL,'25-34','male',NULL,NULL,'domestic','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(97,1,'2026-08-05','2026-08-05 10:30:00',NULL,'18-24','female',NULL,NULL,'overseas_filipino','day_trip',NULL,'Philippines','South Cotabato','General Santos','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(98,4,'2026-08-06','2026-08-06 08:20:00',NULL,'45-54','female',NULL,NULL,'foreign','overnight',NULL,'South Korea','Seoul','Seoul','leisure',5,6,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(99,1,'2026-08-06','2026-08-06 15:01:00',NULL,'under18','female',NULL,NULL,'domestic','overnight',NULL,'Philippines','South Cotabato','Koronadal','leisure',3,4,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(100,1,'2026-08-06','2026-08-06 10:11:00',NULL,'55-64','male',NULL,NULL,'foreign','day_trip',NULL,'Philippines','South Cotabato','Koronadal','leisure',2,3,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(101,1,'2026-08-06','2026-08-06 09:06:00',NULL,'25-34','female',NULL,NULL,'local','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(102,1,'2026-08-07','2026-08-07 13:11:00',NULL,'55-64','male',NULL,NULL,'foreign','day_trip',NULL,'South Korea','Seoul','Seoul','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(103,4,'2026-08-07','2026-08-07 09:31:00',NULL,'55-64','female',NULL,NULL,'local','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',1,2,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL),(104,4,'2026-08-08','2026-08-08 10:00:00',NULL,'25-34','male',NULL,NULL,'overseas_filipino','overnight',NULL,'Philippines','South Cotabato','Tampakan','leisure',0,1,1,'qr',NULL,NULL,NULL,NULL,'valid',NULL,NULL,NULL,'2026-08-10 06:19:55',NULL);
/*!40000 ALTER TABLE `tourist_arrivals` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

