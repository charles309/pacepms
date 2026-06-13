-- ===========================================================================
-- PMS — Global database installation script (pms_global)
-- Run with a privileged MySQL account.
-- ===========================================================================

CREATE DATABASE IF NOT EXISTS pms_global
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pms_global;

-- --------------------------------------------------------------------------
-- Super Admins
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS super_admins (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) UNIQUE NOT NULL,
    password    VARCHAR(255) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Auth tokens (opaque, all roles)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    role        ENUM('superadmin','landlord','manager','tenant') NOT NULL,
    token_hash  VARCHAR(64) NOT NULL UNIQUE,
    db_name     VARCHAR(100),
    client_id   INT UNSIGNED,
    expires_at  DATETIME NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token_hash),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Login attempts (rate limiter)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Clients (landlord / manager)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(150) UNIQUE NOT NULL,
    phone           VARCHAR(20),
    role            ENUM('landlord','manager') NOT NULL,
    password        VARCHAR(255) NOT NULL,
    db_name         VARCHAR(100) UNIQUE NOT NULL,
    service_fee_pct DECIMAL(5,2) DEFAULT 5.00,
    balance         DECIMAL(12,2) DEFAULT 0.00,
    is_active       TINYINT(1) DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Global property registry
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS global_properties (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_property_id VARCHAR(20) UNIQUE NOT NULL,
    client_id       INT UNSIGNED NOT NULL,
    db_name         VARCHAR(100) NOT NULL,
    property_name   VARCHAR(200),
    location        TEXT,
    description     TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Global unit registry
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS global_units (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id     VARCHAR(20) UNIQUE NOT NULL,
    pms_property_id VARCHAR(20) NOT NULL,
    client_id       INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pms_property_id) REFERENCES global_properties(pms_property_id)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Global blacklist
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS global_blacklist (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_national_id  VARCHAR(50) NOT NULL,
    full_name           VARCHAR(200),
    phone               VARCHAR(20),
    email               VARCHAR(150),
    last_pms_unit_id    VARCHAR(20),
    last_client_id      INT UNSIGNED,
    amount_owed         DECIMAL(12,2),
    vacate_report       TEXT,
    resolved            TINYINT(1) DEFAULT 0,
    flagged_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_national_id (tenant_national_id)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Payments (master ledger)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id         VARCHAR(20) NOT NULL,
    client_id           INT UNSIGNED NOT NULL,
    tenant_national_id  VARCHAR(50),
    amount              DECIMAL(12,2) NOT NULL,
    service_fee         DECIMAL(12,2) DEFAULT 0.00,
    payment_method      ENUM('mpesa','cash') NOT NULL,
    mpesa_receipt       VARCHAR(50),
    external_reference  VARCHAR(100) UNIQUE,
    checkout_request_id VARCHAR(100),
    status              ENUM('pending','success','failed') DEFAULT 'pending',
    recorded_by         ENUM('system','superadmin') DEFAULT 'system',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (pms_unit_id),
    INDEX idx_client (client_id),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Withdrawals
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS withdrawals (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id        INT UNSIGNED NOT NULL,
    amount_requested DECIMAL(12,2) NOT NULL,
    service_fee      DECIMAL(12,2) NOT NULL,
    amount_disbursed DECIMAL(12,2) NOT NULL,
    status           ENUM('pending','approved','rejected') DEFAULT 'pending',
    bank_details     TEXT,
    verified_by      INT UNSIGNED,
    verified_at      DATETIME,
    notes            TEXT,
    requested_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- System settings
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_by    INT UNSIGNED,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- --------------------------------------------------------------------------
-- Seed settings
-- --------------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('maintenance_mode', '0'),
    ('maintenance_message', ''),
    ('platform_revenue', '0.00'),
    ('pms_property_counter', '0'),
    ('pms_unit_counter', '0')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- --------------------------------------------------------------------------
-- Seed a super admin.
-- Default password below is 'ChangeMe123!' (bcrypt). CHANGE IT after first login.
-- Generate your own with:
--   php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost'=>12]);"
-- --------------------------------------------------------------------------
INSERT INTO super_admins (name, email, password) VALUES
    ('PMS Super Admin', 'admin@pms.co.ke',
     '$2y$12$Xz8i.k1IhmA9.kQQcuUKieWyPKr1cCyF7wgTOLbspY4ZHO9TSc86.')
ON DUPLICATE KEY UPDATE email = email;
