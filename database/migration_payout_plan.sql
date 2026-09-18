ALTER TABLE `events`
  ADD COLUMN `payout_plan` ENUM('standard','early') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard'
  AFTER `platform_fee_percentage`;

-- Tracks how much of event_payouts.organizer_amount has actually been
-- transferred so far. Without this, triggerPayout() can only ever pay
-- an event out once (payout_status flips to 'paid' permanently) — fine
-- for the 'standard' plan (one payout, after the event ends), but wrong
-- for 'early', where a payout can happen mid-event while more bookings
-- are still landing. total_paid_out lets each trigger send only the
-- new delta (organizer_amount - total_paid_out).
ALTER TABLE `event_payouts`
  ADD COLUMN `total_paid_out` DECIMAL(12,2) NOT NULL DEFAULT 0.00
  AFTER `organizer_amount`;