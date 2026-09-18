-- Adds token_version, used to invalidate JWTs on other devices when a
-- user changes their password (see JWTService, AuthMiddleware,
-- ProfileController::changePassword, AuthController::resetPassword).
--
-- Run this once against the existing production/dev database:
--   mysql -u <user> -p <database> < migration_token_version.sql

ALTER TABLE `users`
  ADD COLUMN `token_version` int unsigned NOT NULL DEFAULT '0' AFTER `password_hash`;
