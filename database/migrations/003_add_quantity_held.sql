ALTER TABLE ticket_types
  ADD COLUMN quantity_held INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity_sold;