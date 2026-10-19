-- Plava nafta CMS - MySQL 8 / MariaDB 10.4+ schema. Re-runnable.

CREATE TABLE IF NOT EXISTS stations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(190) NOT NULL,
  address       VARCHAR(255) NOT NULL DEFAULT '',
  phone         VARCHAR(64)  NOT NULL DEFAULT '',
  aliases       TEXT         NOT NULL,
  county        VARCHAR(120) NOT NULL DEFAULT '',
  city          VARCHAR(120) NOT NULL DEFAULT '',
  postal_code   VARCHAR(16)  NOT NULL DEFAULT '',
  ina_region    VARCHAR(120) NOT NULL DEFAULT '',
  sales_area    VARCHAR(120) NOT NULL DEFAULT '',
  ina_url       VARCHAR(255) NOT NULL DEFAULT '',
  working_hours TEXT         NOT NULL,
  updated_at    DATETIME     NOT NULL,
  UNIQUE KEY stations_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exactly one administrator: the primary key is pinned to 1.
CREATE TABLE IF NOT EXISTS admin (
  id                   TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  email                VARCHAR(190) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  totp_secret_enc      VARCHAR(255) NULL,
  totp_last_step       BIGINT       NOT NULL DEFAULT 0,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  session_version      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at           DATETIME     NOT NULL,
  CONSTRAINT admin_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recovery_codes (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  used_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45) NOT NULL,
  stage        VARCHAR(16) NOT NULL,
  success      TINYINT(1)  NOT NULL,
  attempted_at DATETIME    NOT NULL,
  KEY login_attempts_ip_time (ip, attempted_at),
  KEY login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(32)  NOT NULL,
  station_name VARCHAR(190) NOT NULL DEFAULT '',
  detail       TEXT         NOT NULL,
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  created_at   DATETIME     NOT NULL,
  KEY audit_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
