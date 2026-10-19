-- Plava nafta CMS - SQLite schema (local development and tests). Re-runnable.

-- Later columns (status, closed_from/until, ina_notice …) are added by Db::migrate() → UPGRADES.
CREATE TABLE IF NOT EXISTS stations (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL UNIQUE,
  address       TEXT NOT NULL DEFAULT '',
  phone         TEXT NOT NULL DEFAULT '',
  aliases       TEXT NOT NULL DEFAULT '',
  county        TEXT NOT NULL DEFAULT '',
  city          TEXT NOT NULL DEFAULT '',
  postal_code   TEXT NOT NULL DEFAULT '',
  ina_region    TEXT NOT NULL DEFAULT '',
  sales_area    TEXT NOT NULL DEFAULT '',
  ina_url       TEXT NOT NULL DEFAULT '',
  working_hours TEXT NOT NULL DEFAULT '[]',
  updated_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin (
  id                   INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  email                TEXT NOT NULL,
  password_hash        TEXT NOT NULL,
  totp_secret_enc      TEXT NULL,
  totp_last_step       INTEGER NOT NULL DEFAULT 0,
  must_change_password INTEGER NOT NULL DEFAULT 1,
  session_version      INTEGER NOT NULL DEFAULT 1,
  updated_at           TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS recovery_codes (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  code_hash TEXT NOT NULL,
  used_at   TEXT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  ip           TEXT NOT NULL,
  stage        TEXT NOT NULL,
  success      INTEGER NOT NULL,
  attempted_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS login_attempts_ip_time ON login_attempts (ip, attempted_at);

CREATE TABLE IF NOT EXISTS audit_log (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  action       TEXT NOT NULL,
  station_name TEXT NOT NULL DEFAULT '',
  detail       TEXT NOT NULL DEFAULT '',
  ip           TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL
);
