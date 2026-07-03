ALTER TABLE destination_branches
  ADD COLUMN last_sync_status VARCHAR(30) NULL AFTER last_connection_checked_at,
  ADD COLUMN last_sync_error VARCHAR(500) NULL AFTER last_sync_status,
  ADD COLUMN last_sync_sales INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sync_error,
  ADD COLUMN last_sync_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sync_sales,
  ADD COLUMN last_sync_at DATETIME NULL AFTER last_sync_items;
