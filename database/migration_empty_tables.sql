SET FOREIGN_KEY_CHECKS = 0;

START TRANSACTION;

-- Child records first
DELETE FROM `ticket_checkins`;
DELETE FROM `tickets`;

-- Then bookings
DELETE FROM `bookings`;

-- Financial/history records
DELETE FROM `transaction_logs`;
DELETE FROM `event_payouts`;

-- Logs/history
DELETE FROM `activity_logs`;
DELETE FROM `dev_logs`;
DELETE FROM `notifications`;
DELETE FROM `jobs`;
DELETE FROM `email_verifications`;

-- Reset ticket inventory
UPDATE `ticket_types`
SET `quantity_sold` = 0;

-- Reset organizer payment cancellation counter
UPDATE `organizer_payment_details`
SET `cancellation_count` = 0;

-- Reset AUTO_INCREMENT counters
ALTER TABLE `ticket_checkins` AUTO_INCREMENT = 1;
ALTER TABLE `tickets` AUTO_INCREMENT = 1;
ALTER TABLE `bookings` AUTO_INCREMENT = 1;
ALTER TABLE `transaction_logs` AUTO_INCREMENT = 1;
ALTER TABLE `event_payouts` AUTO_INCREMENT = 1;
ALTER TABLE `activity_logs` AUTO_INCREMENT = 1;
ALTER TABLE `dev_logs` AUTO_INCREMENT = 1;
ALTER TABLE `notifications` AUTO_INCREMENT = 1;
ALTER TABLE `jobs` AUTO_INCREMENT = 1;
ALTER TABLE `email_verifications` AUTO_INCREMENT = 1;

COMMIT;

SET FOREIGN_KEY_CHECKS = 1;