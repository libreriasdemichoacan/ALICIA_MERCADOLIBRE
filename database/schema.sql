CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS meli_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    branch_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(branch_id, 0)) STORED,
    seller_id BIGINT UNSIGNED NOT NULL,
    nickname VARCHAR(120) NULL,
    site_id VARCHAR(10) NOT NULL DEFAULT 'MLM',
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    token_expires_at DATETIME NULL,
    scopes TEXT NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_accounts_branch FOREIGN KEY (branch_id) REFERENCES meli_branches(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_branch_seller (branch_key, seller_id),
    INDEX idx_accounts_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_statuses (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#64748b',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sale_statuses (id, code, name, color, sort_order) VALUES
(1, 'new', 'Venta nueva', '#2563eb', 10),
(2, 'dispatch', 'En despacho', '#7c3aed', 20),
(3, 'packed', 'Empacada', '#0891b2', 30),
(4, 'shipped', 'Enviada', '#ea580c', 40),
(5, 'delivered', 'Entregada', '#16a34a', 50),
(6, 'returned', 'Devuelta', '#dc2626', 60),
(7, 'cancelled', 'Cancelada', '#475569', 70)
ON DUPLICATE KEY UPDATE name = VALUES(name), color = VALUES(color), sort_order = VALUES(sort_order);

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    pack_id BIGINT UNSIGNED NULL,
    account_id BIGINT UNSIGNED NULL,
    internal_status_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    meli_status VARCHAR(50) NULL,
    meli_status_detail VARCHAR(80) NULL,
    date_created DATETIME NULL,
    date_closed DATETIME NULL,
    last_updated DATETIME NULL,
    buyer_id BIGINT UNSIGNED NULL,
    buyer_nickname VARCHAR(120) NULL,
    buyer_first_name VARCHAR(120) NULL,
    buyer_last_name VARCHAR(120) NULL,
    buyer_email VARCHAR(180) NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency_id VARCHAR(10) NOT NULL DEFAULT 'MXN',
    shipping_id BIGINT UNSIGNED NULL,
    shipping_status VARCHAR(80) NULL,
    shipping_substatus VARCHAR(80) NULL,
    logistic_type VARCHAR(80) NULL,
    tracking_number VARCHAR(120) NULL,
    raw_payload JSON NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_account FOREIGN KEY (account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_status FOREIGN KEY (internal_status_id) REFERENCES sale_statuses(id),
    INDEX idx_sales_dates (date_created, date_closed),
    INDEX idx_sales_status (internal_status_id),
    INDEX idx_sales_shipping (shipping_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    meli_item_id VARCHAR(40) NOT NULL,
    variation_id BIGINT UNSIGNED NULL,
    variation_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(variation_id, 0)) STORED,
    title VARCHAR(255) NOT NULL,
    sku VARCHAR(120) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    full_unit_price DECIMAL(12,2) NULL,
    currency_id VARCHAR(10) NOT NULL DEFAULT 'MXN',
    listing_type_id VARCHAR(80) NULL,
    warranty VARCHAR(255) NULL,
    raw_payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_sale_item (sale_id, meli_item_id, variation_key),
    INDEX idx_sale_items_item (meli_item_id, variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    status_id TINYINT UNSIGNED NOT NULL,
    notes VARCHAR(500) NULL,
    changed_by VARCHAR(120) NULL DEFAULT 'sistema',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_history_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_history_status FOREIGN KEY (status_id) REFERENCES sale_statuses(id),
    INDEX idx_history_sale (sale_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_syncs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_item_id VARCHAR(40) NOT NULL,
    variation_id BIGINT UNSIGNED NULL,
    previous_available_quantity INT NULL,
    new_available_quantity INT NULL,
    new_price DECIMAL(12,2) NULL,
    request_payload JSON NULL,
    response_payload JSON NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_item (meli_item_id, variation_id),
    INDEX idx_inventory_success (success, synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_mysql_branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(140) NOT NULL,
    code VARCHAR(40) NULL,
    host VARCHAR(255) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL DEFAULT 3306,
    database_name VARCHAR(140) NOT NULL,
    username VARCHAR(140) NOT NULL,
    password TEXT NULL,
    charset VARCHAR(40) NOT NULL DEFAULT 'utf8mb4',
    notes VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_remote_mysql_branches_active (is_active, name),
    UNIQUE KEY uniq_remote_mysql_branches_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
