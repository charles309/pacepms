-- ===========================================================================
-- PMS — Per-client database template.
-- Executed by SuperAdminController::createClient() against the new client DB.
-- The CREATE DATABASE / USE is handled by the provisioner; this file contains
-- only table definitions (no comments-with-semicolons, no multi-statement DDL
-- that the splitter cannot handle).
-- ===========================================================================

CREATE TABLE IF NOT EXISTS properties (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_property_id VARCHAR(20) UNIQUE NOT NULL,
    name            VARCHAR(200) NOT NULL,
    address         TEXT,
    description     TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS units (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id     VARCHAR(20) UNIQUE NOT NULL,
    pms_property_id VARCHAR(20) NOT NULL,
    unit_number     VARCHAR(50),
    floor           VARCHAR(20),
    rent_amount     DECIMAL(10,2) NOT NULL,
    status          ENUM('vacant','occupied') DEFAULT 'vacant',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenants (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id  VARCHAR(20) NOT NULL,
    national_id  VARCHAR(50) UNIQUE NOT NULL,
    full_name    VARCHAR(200) NOT NULL,
    phone        VARCHAR(20),
    email        VARCHAR(150),
    move_in_date DATE,
    password     VARCHAR(255),
    is_active    TINYINT(1) DEFAULT 1,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (pms_unit_id),
    INDEX idx_national_id (national_id),
    INDEX idx_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS former_tenants (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_tenant_id  INT UNSIGNED,
    pms_unit_id         VARCHAR(20),
    national_id         VARCHAR(50),
    full_name           VARCHAR(200),
    phone               VARCHAR(20),
    email               VARCHAR(150),
    move_in_date        DATE,
    vacate_date         DATE,
    vacate_reason       TEXT,
    outstanding_balance DECIMAL(10,2) DEFAULT 0.00,
    is_defaulter        TINYINT(1) DEFAULT 0,
    transferred_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vacate_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id     VARCHAR(20) NOT NULL,
    tenant_id       INT UNSIGNED NOT NULL,
    reported_by     ENUM('landlord','manager') NOT NULL,
    reason          TEXT NOT NULL,
    outstanding_amt DECIMAL(10,2) DEFAULT 0.00,
    report_date     DATE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id VARCHAR(20),
    tenant_id   INT UNSIGNED NOT NULL,
    subject     VARCHAR(255),
    message     TEXT NOT NULL,
    status      ENUM('open','in_progress','resolved') DEFAULT 'open',
    admin_reply TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pms_unit_id VARCHAR(20),
    tenant_id   INT UNSIGNED,
    sender_role ENUM('landlord','manager'),
    channel     ENUM('app','email','both') NOT NULL,
    subject     VARCHAR(255),
    body        TEXT NOT NULL,
    sent_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_history (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    global_payment_id  BIGINT UNSIGNED,
    pms_unit_id        VARCHAR(20),
    tenant_national_id VARCHAR(50),
    amount             DECIMAL(12,2),
    service_fee        DECIMAL(12,2) DEFAULT 0.00,
    payment_method     ENUM('mpesa','cash'),
    mpesa_receipt      VARCHAR(50),
    status             ENUM('pending','success','failed'),
    paid_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unit (pms_unit_id),
    INDEX idx_national (tenant_national_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    user_role  ENUM('tenant','admin'),
    type       VARCHAR(100),
    title      VARCHAR(255),
    body       TEXT,
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_role, is_read)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT UNSIGNED,
    actor_role  VARCHAR(50),
    action      VARCHAR(100),
    target_type VARCHAR(100),
    target_id   VARCHAR(50),
    old_value   JSON,
    new_value   JSON,
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_actor (actor_id),
    INDEX idx_target (target_type, target_id)
) ENGINE=InnoDB;
