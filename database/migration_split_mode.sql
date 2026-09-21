-- Run ONCE on the live database (safe, no data loss).
ALTER TABLE event_payouts
  MODIFY payout_status
  ENUM('pending','processing','paid','failed','frozen','cancelled','split_settled')
  NOT NULL DEFAULT 'pending';

-- Optional: rows that failed on the Transfer API and whose organizer was
-- paid via split can be closed out. Review before running:
-- UPDATE event_payouts SET payout_status='split_settled' WHERE payout_status='failed' AND event_id IN (...);
