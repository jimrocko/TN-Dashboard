-- TN Dashboard – Schema für MySQL / MariaDB
CREATE TABLE IF NOT EXISTS {p}users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('teilnehmer','lehrer','admin') NOT NULL DEFAULT 'teilnehmer',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_seen DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  module VARCHAR(32) NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX {p}idx_activity_user (user_id, created_at),
  CONSTRAINT {p}fk_activity_user FOREIGN KEY (user_id) REFERENCES {p}users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}bl_cache (
  cache_key VARCHAR(191) PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  fetched_at DATETIME NOT NULL,
  ttl_seconds INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
