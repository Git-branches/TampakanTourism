-- =============================================================================
--  TourSync — Database Schema
--  Tourism Municipal Office Information Management System
--  Municipal Tourism Office, Tampakan, South Cotabato
-- -----------------------------------------------------------------------------
--  Engine  : InnoDB (foreign keys, transactions)
--  Charset : utf8mb4_unicode_ci
--  Target  : MySQL 8.0+ / MariaDB 10.4+
--
--  Apply with:  php database/install.php
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS feedback;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS destination_managers;
DROP TABLE IF EXISTS arrival_daily_summary;
DROP TABLE IF EXISTS tourist_arrivals;
DROP TABLE IF EXISTS destination_photos;
DROP TABLE IF EXISTS destinations;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS admins;

SET FOREIGN_KEY_CHECKS = 1;


-- -----------------------------------------------------------------------------
-- admins
-- Administrative accounts. There is no public registration path anywhere in
-- the system; the first account is created by database/install.php.
-- -----------------------------------------------------------------------------
CREATE TABLE admins (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name       VARCHAR(120)  NOT NULL,
    username        VARCHAR(60)   NOT NULL,
    email           VARCHAR(160)  NOT NULL,
    password_hash   VARCHAR(255)  NOT NULL,
    role            ENUM('officer','staff') NOT NULL DEFAULT 'staff',
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME      NULL,
    last_login_at   DATETIME      NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_username (username),
    UNIQUE KEY uq_admin_email (email),
    KEY idx_admin_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- settings
-- Office profile, SMS credentials, retention policy. Key/value so new options
-- never require a schema change.
-- -----------------------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
    setting_value TEXT         NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- categories
-- Destination classification: Nature, Waterfalls, Culture, Agri-Tourism...
-- -----------------------------------------------------------------------------
CREATE TABLE categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80) NOT NULL,
    slug        VARCHAR(80) NOT NULL,
    icon        VARCHAR(60) NULL,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- destinations
-- The single source of destination truth (Problem 4). The public page, the
-- map marker, and the QR target all read this one row.
-- -----------------------------------------------------------------------------
CREATE TABLE destinations (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id       INT UNSIGNED NULL,
    name              VARCHAR(160) NOT NULL,
    slug              VARCHAR(180) NOT NULL,
    short_description VARCHAR(300) NULL,
    description       TEXT NULL,
    history           TEXT NULL,
    operating_hours   VARCHAR(160) NULL,
    entrance_fee      VARCHAR(120) NULL,
    facilities        JSON NULL,
    reminders         TEXT NULL,
    barangay          VARCHAR(120) NULL,
    address           VARCHAR(255) NULL,
    latitude          DECIMAL(10,7) NULL,
    longitude         DECIMAL(10,7) NULL,
    contact_person    VARCHAR(120) NULL,
    contact_phone     VARCHAR(40)  NULL,
    contact_email     VARCHAR(160) NULL,

    -- Opaque QR identifier. Never the primary key: a rotated token must
    -- invalidate old printed signage without changing the destination itself.
    qr_token          CHAR(32) NOT NULL,
    qr_version        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    qr_rotated_at     DATETIME NULL,

    is_featured       TINYINT(1) NOT NULL DEFAULT 0,
    status            ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_by        INT UNSIGNED NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_dest_slug (slug),
    UNIQUE KEY uq_dest_qr (qr_token),
    KEY idx_dest_status (status),
    KEY idx_dest_category (category_id),
    KEY idx_dest_featured (is_featured, status),

    CONSTRAINT fk_dest_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_dest_author   FOREIGN KEY (created_by)  REFERENCES admins(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- destination_photos
-- -----------------------------------------------------------------------------
CREATE TABLE destination_photos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destination_id INT UNSIGNED NOT NULL,
    file_path      VARCHAR(255) NOT NULL,
    caption        VARCHAR(200) NULL,
    is_cover       TINYINT(1) NOT NULL DEFAULT 0,
    sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_photo_dest (destination_id, sort_order),
    CONSTRAINT fk_photo_dest FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- tourist_arrivals
-- This table IS the municipal tourism statistic. Every column exists either
-- because a report needs it or because record integrity needs it.
-- -----------------------------------------------------------------------------
CREATE TABLE tourist_arrivals (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destination_id   INT UNSIGNED NOT NULL,
    visit_date       DATE NOT NULL,
    arrived_at       DATETIME NOT NULL,

    -- Identifying fields are optional by design (RA 10173 minimisation).
    full_name        VARCHAR(160) NULL,
    age_bracket      ENUM('under18','18-24','25-34','35-44','45-54','55-64','65plus') NULL,
    sex              ENUM('male','female','prefer_not_to_say') NULL,
    contact_number   VARCHAR(40)  NULL,
    email            VARCHAR(160) NULL,

    -- Statistical fields. These carry no direct identifier.
    tourist_type     ENUM('local','domestic','foreign','overseas_filipino') NOT NULL,
    stay_type        ENUM('day_trip','overnight') NULL,
    nationality      VARCHAR(80)  NULL,
    origin_country   VARCHAR(80)  NULL,
    origin_province  VARCHAR(120) NULL,
    origin_city      VARCHAR(120) NULL,
    purpose          ENUM('leisure','business','education','religious','vfr','other') NULL,
    companions_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_visitors   SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    consent_given    TINYINT(1) NOT NULL DEFAULT 0,
    source           ENUM('qr','manual') NOT NULL DEFAULT 'qr',
    recorded_by      INT UNSIGNED NULL,
    qr_version_used  SMALLINT UNSIGNED NULL,

    -- Integrity fields. device_hash is a salted hash of IP+user agent;
    -- the raw address is never stored.
    device_hash      CHAR(64) NULL,
    distance_m       INT UNSIGNED NULL,
    status           ENUM('valid','flagged','voided') NOT NULL DEFAULT 'valid',
    flag_reason      VARCHAR(255) NULL,
    void_reason      VARCHAR(255) NULL,
    voided_by        INT UNSIGNED NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_arr_dest_date (destination_id, visit_date),
    KEY idx_arr_date      (visit_date),
    KEY idx_arr_type      (tourist_type),
    KEY idx_arr_status    (status),
    KEY idx_arr_dedupe    (device_hash, destination_id, visit_date),

    -- RESTRICT, not CASCADE: deleting a destination must never silently
    -- erase official arrival statistics. Destinations are archived instead.
    CONSTRAINT fk_arr_dest   FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_arr_by     FOREIGN KEY (recorded_by)    REFERENCES admins(id)       ON DELETE SET NULL,
    CONSTRAINT fk_arr_voider FOREIGN KEY (voided_by)      REFERENCES admins(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- arrival_daily_summary
-- Deliberately denormalised rollup: one row per destination per day. Written
-- inside the same transaction as the arrival, and fully rebuildable from
-- tourist_arrivals — so the raw table remains the single source of truth.
-- Without it, every dashboard load scans the entire arrivals table.
-- -----------------------------------------------------------------------------
CREATE TABLE arrival_daily_summary (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destination_id  INT UNSIGNED NOT NULL,
    visit_date      DATE NOT NULL,
    total_records   INT UNSIGNED NOT NULL DEFAULT 0,
    total_visitors  INT UNSIGNED NOT NULL DEFAULT 0,
    local_count     INT UNSIGNED NOT NULL DEFAULT 0,
    domestic_count  INT UNSIGNED NOT NULL DEFAULT 0,
    foreign_count   INT UNSIGNED NOT NULL DEFAULT 0,
    ofw_count       INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_summary (destination_id, visit_date),
    KEY idx_summary_date (visit_date),
    CONSTRAINT fk_summary_dest FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- destination_managers
-- Contact records, not user accounts (see decision 13.1). They receive SMS.
-- -----------------------------------------------------------------------------
CREATE TABLE destination_managers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destination_id INT UNSIGNED NOT NULL,
    full_name      VARCHAR(120) NOT NULL,
    position       VARCHAR(120) NULL,
    mobile_number  VARCHAR(20)  NOT NULL,
    email          VARCHAR(160) NULL,
    sms_opt_in     TINYINT(1) NOT NULL DEFAULT 1,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mgr_dest (destination_id),
    KEY idx_mgr_sms (is_active, sms_opt_in),
    CONSTRAINT fk_mgr_dest FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- announcements
-- One composer serves the public site and the manager SMS blast; the audience
-- column decides which, so no duplicate records exist.
-- -----------------------------------------------------------------------------
CREATE TABLE announcements (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(200) NOT NULL,
    slug           VARCHAR(220) NOT NULL,
    body           TEXT NOT NULL,
    summary        VARCHAR(300) NULL,
    type           ENUM('announcement','advisory','schedule','event','closure','reminder') NOT NULL DEFAULT 'announcement',
    audience       ENUM('public','managers','both') NOT NULL DEFAULT 'public',
    status         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    destination_id INT UNSIGNED NULL,
    banner_path    VARCHAR(255) NULL,
    event_date     DATE NULL,
    event_location VARCHAR(200) NULL,
    publish_at     DATETIME NULL,
    expires_at     DATETIME NULL,
    created_by     INT UNSIGNED NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ann_slug (slug),
    KEY idx_ann_publish (status, publish_at),
    KEY idx_ann_type (type),
    KEY idx_ann_audience (audience),
    CONSTRAINT fk_ann_dest   FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL,
    CONSTRAINT fk_ann_author FOREIGN KEY (created_by)     REFERENCES admins(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- notifications
-- One row per manager per announcement: the delivery audit trail. SMS can
-- report 'sent' and sometimes 'delivered' — never 'read'.
-- -----------------------------------------------------------------------------
CREATE TABLE notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT UNSIGNED NOT NULL,
    manager_id      INT UNSIGNED NOT NULL,
    channel         ENUM('sms','portal') NOT NULL DEFAULT 'sms',
    status          ENUM('queued','sent','failed','delivered') NOT NULL DEFAULT 'queued',
    provider_ref    VARCHAR(120) NULL,
    error_message   VARCHAR(255) NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at         DATETIME NULL,
    read_at         DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ntf_queue (status, attempts),
    KEY idx_ntf_ann (announcement_id),
    CONSTRAINT fk_ntf_ann FOREIGN KEY (announcement_id) REFERENCES announcements(id)        ON DELETE CASCADE,
    CONSTRAINT fk_ntf_mgr FOREIGN KEY (manager_id)      REFERENCES destination_managers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- feedback
-- Moderated before public display. Policy: hide abusive and spam only,
-- never merely negative — a government office suppressing criticism is a
-- legitimacy problem, not a moderation one.
-- -----------------------------------------------------------------------------
CREATE TABLE feedback (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    destination_id INT UNSIGNED NOT NULL,
    arrival_id     BIGINT UNSIGNED NULL,
    visitor_name   VARCHAR(120) NULL,
    rating         TINYINT UNSIGNED NOT NULL,
    comment        TEXT NULL,
    status         ENUM('pending','published','hidden') NOT NULL DEFAULT 'pending',
    moderated_by   INT UNSIGNED NULL,
    moderated_at   DATETIME NULL,
    device_hash    CHAR(64) NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fb_dest (destination_id, status),
    KEY idx_fb_status (status),
    CONSTRAINT chk_fb_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_fb_dest FOREIGN KEY (destination_id) REFERENCES destinations(id)     ON DELETE CASCADE,
    CONSTRAINT fk_fb_arr  FOREIGN KEY (arrival_id)     REFERENCES tourist_arrivals(id) ON DELETE SET NULL,
    CONSTRAINT fk_fb_mod  FOREIGN KEY (moderated_by)   REFERENCES admins(id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- reports
-- A record of every generated report, so a figure presented in a meeting
-- stays reproducible afterwards.
-- -----------------------------------------------------------------------------
CREATE TABLE reports (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(200) NULL,
    type         ENUM('daily','monthly','quarterly','annual','custom') NOT NULL,
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,
    parameters   JSON NULL,
    file_path    VARCHAR(255) NULL,
    generated_by INT UNSIGNED NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rep_period (type, period_start, period_end),
    CONSTRAINT fk_rep_by FOREIGN KEY (generated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- activity_logs
-- Append-only. Nothing in the application updates or deletes a row here.
-- -----------------------------------------------------------------------------
CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT UNSIGNED NULL,
    action      VARCHAR(60)  NOT NULL,
    entity_type VARCHAR(60)  NULL,
    entity_id   BIGINT UNSIGNED NULL,
    description VARCHAR(400) NULL,
    ip_address  VARBINARY(16) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_log_admin (admin_id, created_at),
    KEY idx_log_entity (entity_type, entity_id),
    KEY idx_log_action (action),
    CONSTRAINT fk_log_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
