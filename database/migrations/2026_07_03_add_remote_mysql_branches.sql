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
