CREATE TABLE IF NOT EXISTS remote_stock_sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    remote_branch_id BIGINT UNSIGNED NULL,
    remote_branch_name VARCHAR(140) NULL,
    meli_item_id VARCHAR(40) NOT NULL,
    stock_quantity INT NULL,
    price DECIMAL(12,2) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remote_stock_logs_branch FOREIGN KEY (remote_branch_id) REFERENCES remote_mysql_branches(id) ON DELETE SET NULL,
    INDEX idx_remote_stock_logs_branch_date (remote_branch_id, synced_at),
    INDEX idx_remote_stock_logs_item (meli_item_id),
    INDEX idx_remote_stock_logs_success (success, synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
