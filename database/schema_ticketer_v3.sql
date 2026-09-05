SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) UNSIGNED NOT NULL,
  `action`      varchar(100)     NOT NULL,
  `description` varchar(255)     DEFAULT NULL,
  `ip_address`  varchar(50)      DEFAULT NULL,
  `created_at`  datetime         DEFAULT current_timestamp(),
  `updated_at`  datetime         DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
  -- !! No FOREIGN KEY — logs are immutable, must outlive any row they reference !!
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id`                  int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             int(10) UNSIGNED NOT NULL,
  `event_id`            int(10) UNSIGNED NOT NULL,
  `ticket_type_id`      int(10) UNSIGNED NOT NULL,
  `quantity`            int(10) UNSIGNED NOT NULL DEFAULT 1,
  `unit_price`          decimal(10,2)    NOT NULL,
  `total_amount`        decimal(10,2)    NOT NULL,
  `paystack_reference`  varchar(255)     DEFAULT NULL,
  `payment_status`      enum('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at`             datetime         DEFAULT NULL,
  `refunded_at`         datetime         DEFAULT NULL,   -- [C] when was this refunded?
  `deleted_at`          datetime         DEFAULT NULL,   -- soft-delete
  `created_at`          datetime         DEFAULT current_timestamp(),
  `updated_at`          datetime         DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `paystack_reference` (`paystack_reference`),
  KEY `user_id`        (`user_id`),
  KEY `event_id`       (`event_id`),
  KEY `ticket_type_id` (`ticket_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       varchar(255) NOT NULL,
  `slug`       varchar(255) NOT NULL,
  `icon`       varchar(20)  DEFAULT 'ticket',
  `created_at` datetime     DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE `categories`;
INSERT INTO `categories` (`id`, `name`, `slug`, `icon`) VALUES
(1, 'Music',      'music',      'music'       ),
(2, 'Technology', 'technology', 'monitor'     ),
(3, 'Sports',     'sports',     'trophy'      ),
(4, 'Arts',       'arts',       'palette'     ),
(5, 'Business',   'business',   'briefcase'   ),
(6, 'Food',       'food',       'utensils'    ),
(7, 'Education',  'education',  'book-open'   ),
(8, 'Health',     'health',     'heart-pulse' ),
(9, 'Party',      'party',      'star'        );


CREATE TABLE IF NOT EXISTS `dev_logs` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `method`        varchar(10)  DEFAULT NULL,
  `endpoint`      varchar(255) DEFAULT NULL,
  `user_id`       int(10) UNSIGNED DEFAULT NULL,
  `ip_address`    varchar(50)  DEFAULT NULL,
  `payload`       text         DEFAULT NULL,
  `response_code` int(11)      DEFAULT NULL,
  `created_at`    datetime     DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    int(10) UNSIGNED NOT NULL,
  `email`      varchar(200) NOT NULL,
  `otp`        varchar(6)   NOT NULL,
  `type`       enum('register','email_change','forgot_password') NOT NULL DEFAULT 'register',
  `is_used`    tinyint(1)   DEFAULT 0,
  `expires_at` datetime     NOT NULL,
  `created_at` datetime     DEFAULT current_timestamp(),
  `updated_at` datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `organizer_id`      int(10) UNSIGNED NOT NULL,
  `category_id`       int(10) UNSIGNED DEFAULT NULL,
  `title`             varchar(255) NOT NULL,
  `slug`              varchar(255) NOT NULL,
  `description`       text         DEFAULT NULL,
  `location`          varchar(255) DEFAULT NULL,
  `banner_image`      varchar(255) DEFAULT NULL,
  `banner_public_id`  varchar(255) DEFAULT NULL,
  `contact_email`     varchar(255) DEFAULT NULL,
  `contact_phone`     varchar(50)  DEFAULT NULL,
  `start_date`        datetime     NOT NULL,
  `end_date`          datetime     NOT NULL,
  `total_tickets`     int(10) UNSIGNED NOT NULL,
  `status`            enum('draft','published','cancelled','completed','deleted') NOT NULL DEFAULT 'draft',
  `deleted_at`        datetime     DEFAULT NULL,
  `created_at`        datetime     DEFAULT current_timestamp(),
  `updated_at`        datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `organizer_id` (`organizer_id`),
  KEY `category_id`  (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`         varchar(100) NOT NULL,
  `payload`      longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status`       enum('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `attempts`     tinyint(4)   NOT NULL DEFAULT 0,
  `max_attempts` tinyint(4)   NOT NULL DEFAULT 3,
  `error`        text         DEFAULT NULL,
  `available_at` datetime     NOT NULL DEFAULT current_timestamp(),
  `created_at`   datetime     NOT NULL DEFAULT current_timestamp(),
  `updated_at`   datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_status_available` (`status`,`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `organizer_applications` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) UNSIGNED NOT NULL,
  `org_name`    varchar(255) NOT NULL,
  `event_type`  varchar(255) NOT NULL,
  `phone`       varchar(50)  NOT NULL,
  `reason`      text         DEFAULT NULL,
  `status`      enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime     DEFAULT NULL,
  `created_at`  datetime     DEFAULT current_timestamp(),
  `updated_at`  datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id`     (`user_id`),
  KEY `reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `user_id`    int(10) UNSIGNED NOT NULL,
  `event_id`   int(10) UNSIGNED NOT NULL,
  `qr_token`   varchar(255) NOT NULL,
  `is_used`    tinyint(1)   DEFAULT 0,
  `used_at`    datetime     DEFAULT NULL,
  `deleted_at` datetime     DEFAULT NULL,
  `created_at` datetime     DEFAULT current_timestamp(),
  `updated_at` datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_token` (`qr_token`),
  KEY `booking_id` (`booking_id`),
  KEY `user_id`    (`user_id`),
  KEY `event_id`   (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ticket_types` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`      int(10) UNSIGNED NOT NULL,
  `name`          varchar(100)  NOT NULL,
  `description`   varchar(255)  DEFAULT NULL,
  `price`         decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity`      int(10) UNSIGNED NOT NULL,
  `quantity_sold` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `sales_end_at`  datetime      DEFAULT NULL,
  `created_at`    datetime      DEFAULT current_timestamp(),
  `updated_at`    datetime      DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `chk_no_oversell` CHECK (`quantity_sold` <= `quantity`)  -- [B]
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `type`         VARCHAR(60)   NOT NULL,
  `title`        VARCHAR(255)  NOT NULL,
  `body`         TEXT          NOT NULL,
  `action_url`   VARCHAR(500)  DEFAULT NULL,
  `related_id`   INT UNSIGNED  DEFAULT NULL,
  `related_type` VARCHAR(60)   DEFAULT NULL,
  `is_read`      TINYINT(1)    NOT NULL DEFAULT 0,
  `read_at`      DATETIME      DEFAULT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user_read`   (`user_id`, `is_read`),
  KEY `idx_notifications_user_recent` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transaction_logs` (
  `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `booking_id`         INT UNSIGNED    NOT NULL,
  `user_id`            INT UNSIGNED    NOT NULL,
  `event_id`           INT UNSIGNED    NOT NULL,
  `organizer_id`       INT UNSIGNED    NOT NULL,
  `type`               VARCHAR(60)     NOT NULL,
  `amount`             DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `currency`           CHAR(3)         NOT NULL DEFAULT 'NGN',
  `paystack_reference` VARCHAR(255)    DEFAULT NULL,
  `paystack_status`    VARCHAR(60)     DEFAULT NULL,
  `quantity`           INT UNSIGNED    DEFAULT 1,
  `unit_price`         DECIMAL(10,2)   DEFAULT 0.00,
  `platform_fee`       DECIMAL(10,2)   DEFAULT 0.00,
  `organizer_amount`   DECIMAL(10,2)   DEFAULT 0.00,
  `ticket_type_name`   VARCHAR(100)    DEFAULT NULL,
  `event_title`        VARCHAR(255)    DEFAULT NULL,
  `note`               TEXT            DEFAULT NULL,
  `ip_address`         VARCHAR(50)     DEFAULT NULL,
  `performed_by`       INT UNSIGNED    DEFAULT NULL,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_txlog_booking`   (`booking_id`),
  KEY `idx_txlog_user`      (`user_id`),
  KEY `idx_txlog_event`     (`event_id`),
  KEY `idx_txlog_organizer` (`organizer_id`),
  KEY `idx_txlog_type`      (`type`),
  KEY `idx_txlog_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `organizer_payment_details` (
  `id`                      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`                 INT UNSIGNED   NOT NULL UNIQUE,
  `bank_name`               VARCHAR(100)   NOT NULL,
  `bank_code`               VARCHAR(20)    NOT NULL,   -- Paystack bank code e.g. "058"
  `account_number`          VARCHAR(20)    NOT NULL,
  `account_name`            VARCHAR(255)   NOT NULL,   -- resolved by Paystack
  `paystack_subaccount_code` VARCHAR(100)  DEFAULT NULL, -- e.g. "ACCT_xxxxxxxxxx"
  `paystack_subaccount_id`  INT UNSIGNED   DEFAULT NULL,
  `platform_fee_percentage` DECIMAL(5,2)   NOT NULL,   -- e.g. 10.00 = 10%
  `is_verified`             TINYINT(1)     NOT NULL DEFAULT 0,
  `is_flagged`              TINYINT(1)     NOT NULL DEFAULT 0,
  `flag_reason`             TEXT           DEFAULT NULL,
  `cancellation_count`      INT UNSIGNED   NOT NULL DEFAULT 0,
  `created_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_opd_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_payouts` (
  `id`                      INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `event_id`                INT UNSIGNED   NOT NULL UNIQUE,
  `organizer_id`            INT UNSIGNED   NOT NULL,
  `gross_revenue`           DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `platform_fee_percentage` DECIMAL(5,2)   NOT NULL,
  `platform_fee_amount`     DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `organizer_amount`        DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `payout_status`           ENUM('pending','processing','paid','failed','frozen','cancelled')
                            NOT NULL DEFAULT 'pending',
  `paystack_transfer_code`  VARCHAR(100)   DEFAULT NULL,
  `paystack_transfer_ref`   VARCHAR(255)   DEFAULT NULL,
  `hold_until`              DATETIME       NOT NULL,
  `triggered_by`            INT UNSIGNED   DEFAULT NULL,
  `freeze_reason`           TEXT           DEFAULT NULL,
  `frozen_by`               INT UNSIGNED   DEFAULT NULL,
  `frozen_at`               DATETIME       DEFAULT NULL,
  `paid_at`                 DATETIME       DEFAULT NULL,
  `failed_at`               DATETIME       DEFAULT NULL,
  `failure_reason`          TEXT           DEFAULT NULL,
  `attempts`                TINYINT        NOT NULL DEFAULT 0,
  `created_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payout_organizer` (`organizer_id`),
  KEY `idx_payout_status`    (`payout_status`),
  KEY `idx_payout_hold`      (`hold_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `events`
  ADD COLUMN `platform_fee_percentage` DECIMAL(5,2) DEFAULT NULL
  AFTER `total_tickets`;
ALTER TABLE organizer_payment_details
  ADD COLUMN paystack_recipient_code VARCHAR(100) DEFAULT NULL
  AFTER paystack_subaccount_id;

ALTER TABLE jobs
ADD COLUMN queue VARCHAR(30) NOT NULL DEFAULT 'email'
AFTER type;
UPDATE jobs SET queue = 'pdf' WHERE type = 'generate_ticket';
UPDATE jobs SET queue = 'pdf' WHERE type = 'generate_tickets_bulk';

CREATE TABLE IF NOT EXISTS `users` (
  `id`                     int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                   varchar(255) NOT NULL,
  `email`                  varchar(200) NOT NULL,
  `password_hash`          varchar(255) NOT NULL,
  `role`                   enum('attendee','organizer','admin','dev') NOT NULL DEFAULT 'attendee',
  `email_verified`         tinyint(1)   DEFAULT 0,
  `email_verified_at`      datetime     DEFAULT NULL,
  `avatar`                 varchar(255) DEFAULT NULL,
  `avatar_public_id`       varchar(255) DEFAULT NULL,
  `is_active`              tinyint(1)   DEFAULT 1,
  `created_at`             datetime     DEFAULT current_timestamp(),
  `updated_at`             datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reset_token`            varchar(64)  DEFAULT NULL,
  `reset_token_expires_at` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

TRUNCATE TABLE `users`;
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `email_verified`, `email_verified_at`, `is_active`) VALUES
(1, 'Sam Dev', 'sam@dev.local', '$2a$13$PifloQ/KgrZjNJkbAw.J1e6/f3kgC3w7Faieinzd5RWGAAvxM4KI2', 'dev', 1, NOW(), 1),
(2, 'Admin Ticketer', 'admin@admin.local', '$2a$12$9C69.y9Q/JFhm/PdCGYuKOwpUBIVMRuz50mHvOidh.bHSWCw4Dd1C', 'admin', 1, NOW(), 1);

ALTER TABLE `users`
  ADD COLUMN `google_id`      VARCHAR(255) DEFAULT NULL AFTER `avatar_public_id`,
  ADD COLUMN `auth_provider`  ENUM('email', 'google') NOT NULL DEFAULT 'email' AFTER `google_id`,
  ADD UNIQUE KEY `google_id` (`google_id`);

ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`)        REFERENCES `users`        (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`event_id`)       REFERENCES `events`       (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`) ON DELETE RESTRICT;

ALTER TABLE `email_verifications`
  ADD CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`organizer_id`) REFERENCES `users`      (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `events_ibfk_2` FOREIGN KEY (`category_id`)  REFERENCES `categories` (`id`) ON DELETE SET NULL;

ALTER TABLE `organizer_applications`
  ADD CONSTRAINT `organizer_applications_ibfk_1` FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `organizer_applications_ibfk_2` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`event_id`)   REFERENCES `events`   (`id`) ON DELETE RESTRICT;

ALTER TABLE `ticket_types`
  ADD CONSTRAINT `ticket_types_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT;

DELIMITER $$

CREATE TRIGGER `trg_organizer_app_no_duplicate`
BEFORE INSERT ON `organizer_applications`
FOR EACH ROW
BEGIN
  DECLARE active_count INT DEFAULT 0;
  SELECT COUNT(*) INTO active_count
  FROM `organizer_applications`
  WHERE `user_id` = NEW.user_id
    AND `status` IN ('pending', 'approved');

  IF active_count > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'User already has a pending or approved organizer application.';
  END IF;
END$$

CREATE TRIGGER `trg_booking_paid`
AFTER UPDATE ON `bookings`
FOR EACH ROW
BEGIN
  IF OLD.payment_status != 'paid' AND NEW.payment_status = 'paid' THEN
    UPDATE `ticket_types`
    SET `quantity_sold` = `quantity_sold` + NEW.quantity
    WHERE `id` = NEW.ticket_type_id;
  END IF;
END$$

CREATE TRIGGER `trg_booking_refund`
AFTER UPDATE ON `bookings`
FOR EACH ROW
BEGIN
  IF OLD.payment_status = 'paid' AND NEW.payment_status = 'refunded' THEN
    UPDATE `ticket_types`
    SET `quantity_sold` = GREATEST(0, `quantity_sold` - OLD.quantity)
    WHERE `id` = NEW.ticket_type_id;
  END IF;
END$$

CREATE TRIGGER `trg_events_soft_delete`
AFTER UPDATE ON `events`
FOR EACH ROW
BEGIN
  IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
    INSERT INTO `activity_logs`
      (`user_id`, `action`, `description`, `ip_address`, `created_at`, `updated_at`)
    VALUES (
      IFNULL(@session_user_id, NEW.organizer_id),
      'event_deleted',
      CONCAT(
        'Event #', NEW.id, ' "', NEW.title, '" was soft-deleted. ',
        'Status: ', NEW.status, '. ',
        'total_tickets: ', NEW.total_tickets, '.'
      ),
      NULL,
      NOW(),
      NOW()
    );
  END IF;
END$$

DELIMITER ;


CREATE OR REPLACE VIEW `v_event_sales` AS
SELECT
  e.id                                                         AS event_id,
  e.title                                                      AS event_title,
  e.total_tickets,
  COALESCE(SUM(CASE WHEN b.payment_status = 'paid'     THEN b.quantity ELSE 0 END), 0) AS tickets_sold,
  COALESCE(SUM(CASE WHEN b.payment_status = 'refunded' THEN b.quantity ELSE 0 END), 0) AS tickets_refunded,
  e.total_tickets
    - COALESCE(SUM(CASE WHEN b.payment_status = 'paid' THEN b.quantity ELSE 0 END), 0)
    + COALESCE(SUM(CASE WHEN b.payment_status = 'refunded' THEN b.quantity ELSE 0 END), 0)
                                                               AS tickets_available,
  COALESCE(SUM(CASE WHEN b.payment_status = 'paid'     THEN b.total_amount ELSE 0 END), 0) AS total_revenue
FROM `events` e
LEFT JOIN `bookings` b ON b.event_id = e.id AND b.deleted_at IS NULL
GROUP BY e.id, e.title, e.total_tickets;

COMMIT;