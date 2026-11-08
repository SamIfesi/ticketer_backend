ALTER TABLE ticket_types
  ADD COLUMN quantity_held INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity_sold;

ALTER TABLE ticket_types
  ADD CONSTRAINT chk_held_valid CHECK (quantity_sold + quantity_held <= quantity);