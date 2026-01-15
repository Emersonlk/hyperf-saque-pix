-- Script SQL para criar as tabelas do projeto
-- Execute este script se o comando migrate não funcionar

USE hyperf;

-- Tabela account
CREATE TABLE IF NOT EXISTS `account` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `balance` DECIMAL(15,2) DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela account_withdraw
CREATE TABLE IF NOT EXISTS `account_withdraw` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `account_id` CHAR(36) NOT NULL,
  `method` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `scheduled` BOOLEAN DEFAULT FALSE,
  `scheduled_for` DATETIME NULL DEFAULT NULL,
  `done` BOOLEAN DEFAULT FALSE,
  `error` BOOLEAN DEFAULT FALSE,
  `error_reason` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`account_id`) REFERENCES `account`(`id`) ON DELETE CASCADE,
  INDEX `idx_scheduled` (`scheduled`, `scheduled_for`, `done`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela account_withdraw_pix
CREATE TABLE IF NOT EXISTS `account_withdraw_pix` (
  `account_withdraw_id` CHAR(36) NOT NULL PRIMARY KEY,
  `type` VARCHAR(50) NOT NULL,
  `key` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`account_withdraw_id`) REFERENCES `account_withdraw`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
