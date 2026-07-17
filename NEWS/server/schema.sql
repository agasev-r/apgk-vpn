-- APGK VPN Web Control Panel Database Schema
-- Database: `apgk_apivpn`

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(20) DEFAULT 'admin',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clients` (
  `client_id` VARCHAR(6) PRIMARY KEY, -- Unique 6-digit ID (e.g. 581903)
  `name` VARCHAR(100) DEFAULT NULL,    -- ФИО
  `enterprise` VARCHAR(100) DEFAULT NULL, -- Предприятие
  `status` VARCHAR(20) DEFAULT 'disconnected', -- connected, disconnected, error
  `tunnel_name` VARCHAR(50) DEFAULT NULL,
  `ip` VARCHAR(50) DEFAULT NULL,
  `autostart` TINYINT(1) DEFAULT 0,
  `autoconnect` TINYINT(1) DEFAULT 0,
  `minimize_to_tray` TINYINT(1) DEFAULT 1,
  `rx_bytes` BIGINT DEFAULT 0,
  `tx_bytes` BIGINT DEFAULT 0,
  `config` LONGTEXT DEFAULT NULL,
  `last_seen` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commands` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` VARCHAR(6) NOT NULL,
  `command` VARCHAR(50) NOT NULL, -- update_config, connect, disconnect, restart, update_settings
  `payload` LONGTEXT DEFAULT NULL, -- config content or settings JSON
  `status` VARCHAR(20) DEFAULT 'pending', -- pending, sent, executed, failed
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `traffic_history` (
  `client_id` VARCHAR(6) NOT NULL,
  `date` DATE NOT NULL,
  `rx_bytes` BIGINT UNSIGNED DEFAULT 0,
  `tx_bytes` BIGINT UNSIGNED DEFAULT 0,
  `duration_seconds` INT DEFAULT 0,
  `public_ip` VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (`client_id`, `date`),
  FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `connection_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` VARCHAR(6) NOT NULL,
  `event_type` VARCHAR(20) NOT NULL, -- connect, disconnect
  `ip` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default Super Admin (username: roman, password: orrz20054Q+)
-- Password hash generated using password_hash('orrz20054Q+', PASSWORD_BCRYPT)
INSERT INTO `admins` (`username`, `password_hash`, `role`)
VALUES ('roman', '$2y$10$wE.6480nCqK9j1U9dG7y6OMU6t5tW8m5a/iP/O5pL1fLgQxM4hS6q', 'superadmin')
ON DUPLICATE KEY UPDATE `username`=`username`;

