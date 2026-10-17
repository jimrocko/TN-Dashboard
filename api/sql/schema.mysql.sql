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

CREATE TABLE IF NOT EXISTS {p}locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  kind ENUM('regal','mischkiste') NOT NULL DEFAULT 'regal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}inventory (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_type VARCHAR(16) NOT NULL DEFAULT 'PART',
  item_no VARCHAR(40) NOT NULL,
  color_id INT NOT NULL DEFAULT 0,
  color_name VARCHAR(60) NOT NULL DEFAULT '',
  name VARCHAR(200) NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT '',
  location_id INT UNSIGNED NULL,
  qty INT NOT NULL DEFAULT 0,
  price DECIMAL(10,3) NOT NULL DEFAULT 0,
  bl_lot_id BIGINT UNSIGNED NULL,
  bl_condition CHAR(1) NOT NULL DEFAULT 'U',
  new_or_used CHAR(1) NOT NULL DEFAULT '',
  bl_inventory_id BIGINT NULL,
  description VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL,
  INDEX {p}idx_inv_item (item_no, color_id),
  UNIQUE INDEX {p}idx_inv_bl (bl_inventory_id),
  INDEX {p}idx_inv_loc (location_id),
  CONSTRAINT {p}fk_inv_loc FOREIGN KEY (location_id) REFERENCES {p}locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(16) NOT NULL DEFAULT 'TN',
  order_no VARCHAR(32) NOT NULL,
  buyer VARCHAR(100) NOT NULL,
  status VARCHAR(32) NOT NULL,
  platform_status VARCHAR(32) NULL,
  ordered_at DATETIME NOT NULL,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NULL,
  shipping TEXT NULL,
  details_at DATETIME NULL,
  UNIQUE INDEX {p}idx_order_no (platform, order_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  inventory_id INT UNSIGNED NULL,
  item_type VARCHAR(16) NOT NULL DEFAULT 'PART',
  item_no VARCHAR(40) NOT NULL,
  color_id INT NULL,
  color_name VARCHAR(60) NOT NULL DEFAULT '',
  name VARCHAR(200) NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(10,3) NOT NULL DEFAULT 0,
  new_or_used CHAR(1) NOT NULL DEFAULT '',
  remarks VARCHAR(255) NULL,
  ext_lot_id BIGINT NULL,
  image_url VARCHAR(255) NULL,
  CONSTRAINT {p}fk_oi_order FOREIGN KEY (order_id) REFERENCES {p}orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}transfers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_no VARCHAR(40) NOT NULL,
  color_name VARCHAR(60) NOT NULL DEFAULT '',
  name VARCHAR(200) NOT NULL,
  from_location_id INT UNSIGNED NULL,
  to_location_id INT UNSIGNED NULL,
  qty INT NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  bl_changes TEXT NULL,
  bl_done_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}stocktakes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id INT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'offen',
  created_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  bl_changes TEXT NULL,
  bl_done_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}stocktake_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stocktake_id INT UNSIGNED NOT NULL,
  inventory_id INT UNSIGNED NULL,
  item_no VARCHAR(40) NOT NULL,
  color_name VARCHAR(60) NOT NULL DEFAULT '',
  name VARCHAR(200) NOT NULL,
  expected INT NOT NULL,
  counted INT NULL,
  CONSTRAINT {p}fk_si_st FOREIGN KEY (stocktake_id) REFERENCES {p}stocktakes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}dev_access (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  granted_by INT UNSIGNED NULL,
  granted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}bl_exports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  open_flag TINYINT NULL UNIQUE,
  transfer_ids TEXT NOT NULL,
  stocktake_ids TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'offen'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Archiv der BrickLink-Lose (Lagerort und Zustand bleiben bekannt, wenn ein Los ausverkauft ist)
CREATE TABLE IF NOT EXISTS {p}inventory_history (
  bl_inventory_id BIGINT NOT NULL PRIMARY KEY,
  item_type VARCHAR(16) NOT NULL DEFAULT 'PART',
  item_no VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL DEFAULT '',
  color_id INT NULL,
  color_name VARCHAR(60) NOT NULL DEFAULT '',
  qty INT NOT NULL DEFAULT 0,
  new_or_used CHAR(1) NOT NULL DEFAULT '',
  price DECIMAL(10,3) NOT NULL DEFAULT 0,
  location_code VARCHAR(64) NULL,
  description VARCHAR(255) NULL,
  last_seen_at DATETIME NOT NULL,
  INDEX {p}idx_invh_item (item_no, color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jeder Abruf bei BrickLink/BrickOwl (Tageslimit im Blick behalten)
CREATE TABLE IF NOT EXISTS {p}api_calls (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api VARCHAR(16) NOT NULL,
  call_type VARCHAR(40) NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  status INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX {p}idx_api_calls_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pickliste
CREATE TABLE IF NOT EXISTS {p}pick_progress (
  order_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
  picked TINYINT(1) NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NULL,
  picked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}pick_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_item_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX {p}idx_pick_events_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}pick_claims (
  order_item_id INT UNSIGNED NOT NULL PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  claimed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}pick_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  issue_type VARCHAR(20) NOT NULL,
  details VARCHAR(500) NULL,
  missing_quantity INT NULL,
  found_location VARCHAR(64) NULL,
  actual_quantity INT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'OPEN',
  created_at DATETIME NOT NULL,
  INDEX {p}idx_pick_reports_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}pick_location_order (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_group VARCHAR(64) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}pick_order_state (
  order_id INT UNSIGNED NOT NULL PRIMARY KEY,
  revision INT NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Minifig-Finder
CREATE TABLE IF NOT EXISTS {p}minifig_superset_cache (
  item_no VARCHAR(40) NOT NULL,
  color_id INT NOT NULL,
  fetched_at DATETIME NOT NULL,
  response_json MEDIUMTEXT NOT NULL,
  PRIMARY KEY (item_no, color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}minifig_catalog (
  minifig_no VARCHAR(40) NOT NULL PRIMARY KEY,
  name VARCHAR(255) NOT NULL DEFAULT '',
  category_id INT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  subset_fetched_at DATETIME NULL,
  subset_json MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}minifig_subset_state (
  minifig_no VARCHAR(40) NOT NULL PRIMARY KEY,
  status VARCHAR(10) NOT NULL,
  attempts INT NOT NULL DEFAULT 1,
  last_status_code INT NULL,
  last_error VARCHAR(1000) NULL,
  last_attempt_at DATETIME NOT NULL,
  next_retry_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {p}minifig_decisions (
  minifig_no VARCHAR(40) NOT NULL PRIMARY KEY,
  decision VARCHAR(10) NOT NULL,
  reason VARCHAR(80) NULL,
  decided_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preise aus BrickStore (6-Monats-Durchschnitt) oder Price Guide
CREATE TABLE IF NOT EXISTS {p}price_guide (
  item_type VARCHAR(16) NOT NULL,
  item_no VARCHAR(40) NOT NULL,
  color_id INT NOT NULL DEFAULT -1,
  new_or_used CHAR(1) NOT NULL,
  currency VARCHAR(3) NOT NULL,
  avg_price DECIMAL(12,4) NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT '',
  fetched_at DATETIME NOT NULL,
  PRIMARY KEY (item_type, item_no, color_id, new_or_used, currency)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
