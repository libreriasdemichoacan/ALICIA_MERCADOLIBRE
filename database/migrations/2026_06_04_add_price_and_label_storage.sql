ALTER TABLE inventory_syncs
  MODIFY new_available_quantity INT NULL,
  ADD COLUMN new_price DECIMAL(12,2) NULL AFTER new_available_quantity;
