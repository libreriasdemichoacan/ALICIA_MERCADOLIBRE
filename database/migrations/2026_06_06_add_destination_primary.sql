ALTER TABLE destination_branches
    ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD INDEX idx_destination_branches_primary (is_primary, is_active);

UPDATE destination_branches
SET is_primary = 1
WHERE id = (
    SELECT id FROM (
        SELECT id FROM destination_branches WHERE is_active = 1 ORDER BY id LIMIT 1
    ) AS first_active_destination
)
AND NOT EXISTS (
    SELECT 1 FROM (
        SELECT id FROM destination_branches WHERE is_primary = 1 LIMIT 1
    ) AS existing_primary_destination
);
