ALTER TABLE `events`
  ADD COLUMN `payout_plan` ENUM('standard','early') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard'
  AFTER `platform_fee_percentage`;