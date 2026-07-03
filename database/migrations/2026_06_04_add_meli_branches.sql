CREATE TABLE IF NOT EXISTS meli_branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(140) NOT NULL,
    code VARCHAR(40) NULL,
    meli_client_id VARCHAR(120) NOT NULL,
    meli_client_secret VARCHAR(180) NOT NULL,
    meli_redirect_uri VARCHAR(500) NOT NULL,
    meli_site_id VARCHAR(10) NOT NULL DEFAULT 'MLM',
    meli_seller_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_meli_branches_active (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO meli_branches (name, code, meli_client_id, meli_client_secret, meli_redirect_uri, meli_site_id, meli_seller_id, is_active)
SELECT 'Sucursal principal', 'principal',
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key = 'MELI_CLIENT_ID'), ''),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key = 'MELI_CLIENT_SECRET'), ''),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key = 'MELI_REDIRECT_URI'), ''),
       COALESCE((SELECT setting_value FROM app_settings WHERE setting_key = 'MELI_SITE_ID'), 'MLM'),
       NULLIF((SELECT setting_value FROM app_settings WHERE setting_key = 'MELI_SELLER_ID'), ''),
       1
WHERE NOT EXISTS (SELECT 1 FROM meli_branches);

ALTER TABLE meli_accounts
  ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN branch_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(branch_id, 0)) STORED AFTER branch_id;

UPDATE meli_accounts
SET branch_id = (SELECT id FROM meli_branches ORDER BY id LIMIT 1)
WHERE branch_id IS NULL;

ALTER TABLE meli_accounts
  DROP INDEX seller_id,
  ADD CONSTRAINT fk_accounts_branch FOREIGN KEY (branch_id) REFERENCES meli_branches(id) ON DELETE SET NULL,
  ADD UNIQUE KEY uniq_branch_seller (branch_key, seller_id),
  ADD INDEX idx_accounts_branch (branch_id);
