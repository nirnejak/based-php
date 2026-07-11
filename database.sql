-- Import via cPanel → phpMyAdmin. Create the database in
-- cPanel → MySQL Databases first; cPanel prefixes it (e.g. acct_appdb).

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  -- UNIQUE is load-bearing: without it duplicate signups make login non-deterministic.
  email         VARCHAR(255) NOT NULL UNIQUE,
  -- 255: PASSWORD_DEFAULT is not stable across PHP versions and may grow (Argon2).
  password_hash VARCHAR(255) NOT NULL,
  token_version INT NOT NULL DEFAULT 0,
  status        VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  identifier   VARCHAR(255) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  -- UNIX timestamp (seconds). Integer, not DATETIME, so the throttle window is
  -- timezone-independent: a PHP time() boundary and this column share one clock.
  attempted_at INT NOT NULL,
  INDEX idx_identifier_time (identifier, attempted_at),
  INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
