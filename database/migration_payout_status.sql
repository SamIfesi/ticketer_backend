ALTER TABLE event_payouts
  MODIFY payout_status ENUM('pending','processing','paid','failed','frozen','cancelled','split_settled')
  NOT NULL DEFAULT 'pending';