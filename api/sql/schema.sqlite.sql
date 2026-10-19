-- TN Dashboard – Schema für SQLite (lokale Entwicklung)
CREATE TABLE IF NOT EXISTS {p}users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'teilnehmer' CHECK (role IN ('teilnehmer','lehrer','admin')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL,
  last_seen TEXT NULL
);

CREATE TABLE IF NOT EXISTS {p}activity_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES {p}users(id) ON DELETE CASCADE,
  action TEXT NOT NULL,
  module TEXT NULL,
  details TEXT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_activity_user ON {p}activity_log (user_id, created_at);

CREATE TABLE IF NOT EXISTS {p}bl_cache (
  cache_key TEXT PRIMARY KEY,
  payload TEXT NOT NULL,
  fetched_at TEXT NOT NULL,
  ttl_seconds INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS {p}locations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'regal' CHECK (kind IN ('regal','mischkiste'))
);

CREATE TABLE IF NOT EXISTS {p}inventory (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_type TEXT NOT NULL DEFAULT 'PART',
  item_no TEXT NOT NULL,
  color_id INTEGER NOT NULL DEFAULT 0,
  color_name TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  location_id INTEGER NULL REFERENCES {p}locations(id) ON DELETE SET NULL,
  qty INTEGER NOT NULL DEFAULT 0,
  price REAL NOT NULL DEFAULT 0,
  bl_lot_id INTEGER NULL,
  bl_condition TEXT NOT NULL DEFAULT 'U',
  new_or_used TEXT NOT NULL DEFAULT '',
  bl_inventory_id INTEGER NULL,
  description TEXT NULL,
  updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {p}idx_inv_bl ON {p}inventory (bl_inventory_id);
CREATE INDEX IF NOT EXISTS {p}idx_inv_item ON {p}inventory (item_no, color_id);
CREATE INDEX IF NOT EXISTS {p}idx_inv_loc ON {p}inventory (location_id);

CREATE TABLE IF NOT EXISTS {p}orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  platform TEXT NOT NULL DEFAULT 'TN',
  order_no TEXT NOT NULL,
  buyer TEXT NOT NULL,
  status TEXT NOT NULL,
  platform_status TEXT NULL,
  ordered_at TEXT NOT NULL,
  total REAL NOT NULL DEFAULT 0,
  currency TEXT NULL,
  shipping TEXT NULL,
  details_at TEXT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS {p}idx_order_no ON {p}orders (platform, order_no);

CREATE TABLE IF NOT EXISTS {p}order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES {p}orders(id) ON DELETE CASCADE,
  inventory_id INTEGER NULL,
  item_type TEXT NOT NULL DEFAULT 'PART',
  item_no TEXT NOT NULL,
  color_id INTEGER NULL,
  color_name TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  qty INTEGER NOT NULL,
  price REAL NOT NULL DEFAULT 0,
  new_or_used TEXT NOT NULL DEFAULT '',
  remarks TEXT NULL,
  ext_lot_id INTEGER NULL,
  image_url TEXT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_oi_order ON {p}order_items (order_id);

CREATE TABLE IF NOT EXISTS {p}transfers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_no TEXT NOT NULL,
  color_name TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  from_location_id INTEGER NULL,
  to_location_id INTEGER NULL,
  qty INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  bl_changes TEXT NULL,
  bl_done_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS {p}stocktakes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'offen',
  created_at TEXT NOT NULL,
  closed_at TEXT NULL,
  closed_by INTEGER NULL,
  bl_changes TEXT NULL,
  bl_done_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS {p}stocktake_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  stocktake_id INTEGER NOT NULL REFERENCES {p}stocktakes(id) ON DELETE CASCADE,
  inventory_id INTEGER NULL,
  item_no TEXT NOT NULL,
  color_name TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  expected INTEGER NOT NULL,
  counted INTEGER NULL
);

CREATE TABLE IF NOT EXISTS {p}settings (
  setting_key TEXT NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS {p}dev_access (
  user_id INTEGER NOT NULL PRIMARY KEY,
  granted_by INTEGER NULL,
  granted_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS {p}bl_exports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  open_flag INTEGER NULL UNIQUE,
  transfer_ids TEXT NOT NULL,
  stocktake_ids TEXT NULL,
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  closed_at TEXT NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'offen'
);

-- Archiv der BrickLink-Lose (Lagerort und Zustand bleiben bekannt, wenn ein Los ausverkauft ist)
CREATE TABLE IF NOT EXISTS {p}inventory_history (
  bl_inventory_id INTEGER NOT NULL PRIMARY KEY,
  item_type TEXT NOT NULL DEFAULT 'PART',
  item_no TEXT NOT NULL,
  name TEXT NOT NULL DEFAULT '',
  color_id INTEGER NULL,
  color_name TEXT NOT NULL DEFAULT '',
  qty INTEGER NOT NULL DEFAULT 0,
  new_or_used TEXT NOT NULL DEFAULT '',
  price REAL NOT NULL DEFAULT 0,
  location_code TEXT NULL,
  description TEXT NULL,
  last_seen_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_invh_item ON {p}inventory_history (item_no, color_id);

-- Jeder Abruf bei BrickLink/BrickOwl (Tageslimit im Blick behalten)
CREATE TABLE IF NOT EXISTS {p}api_calls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  api TEXT NOT NULL,
  call_type TEXT NOT NULL,
  endpoint TEXT NOT NULL,
  status INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_api_calls_time ON {p}api_calls (created_at);

-- Pickliste
CREATE TABLE IF NOT EXISTS {p}pick_progress (
  order_item_id INTEGER NOT NULL PRIMARY KEY,
  picked INTEGER NOT NULL DEFAULT 0,
  user_id INTEGER NULL,
  picked_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS {p}pick_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_item_id INTEGER NOT NULL,
  user_id INTEGER NULL,
  action TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_pick_events_time ON {p}pick_events (created_at);

CREATE TABLE IF NOT EXISTS {p}pick_claims (
  order_item_id INTEGER NOT NULL PRIMARY KEY,
  order_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  claimed_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS {p}pick_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL,
  order_item_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  issue_type TEXT NOT NULL,
  details TEXT NULL,
  missing_quantity INTEGER NULL,
  found_location TEXT NULL,
  actual_quantity INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'OPEN',
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_pick_reports_order ON {p}pick_reports (order_id);

CREATE TABLE IF NOT EXISTS {p}pick_location_order (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_group TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS {p}pick_order_state (
  order_id INTEGER NOT NULL PRIMARY KEY,
  revision INTEGER NOT NULL DEFAULT 0,
  completed INTEGER NOT NULL DEFAULT 0
);

-- Minifig-Finder
CREATE TABLE IF NOT EXISTS {p}minifig_superset_cache (
  item_no TEXT NOT NULL,
  color_id INTEGER NOT NULL,
  fetched_at TEXT NOT NULL,
  response_json TEXT NOT NULL,
  PRIMARY KEY (item_no, color_id)
);

CREATE TABLE IF NOT EXISTS {p}minifig_catalog (
  minifig_no TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL DEFAULT '',
  category_id INTEGER NULL,
  first_seen_at TEXT NOT NULL,
  last_seen_at TEXT NOT NULL,
  subset_fetched_at TEXT NULL,
  subset_json TEXT NULL,
  seq INTEGER NULL
);

CREATE TABLE IF NOT EXISTS {p}minifig_subset_state (
  minifig_no TEXT NOT NULL PRIMARY KEY,
  status TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 1,
  last_status_code INTEGER NULL,
  last_error TEXT NULL,
  last_attempt_at TEXT NOT NULL,
  next_retry_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS {p}minifig_decisions (
  minifig_no TEXT NOT NULL PRIMARY KEY,
  decision TEXT NOT NULL,
  reason TEXT NULL,
  decided_at TEXT NOT NULL
);

-- Preise aus BrickStore (6-Monats-Durchschnitt) oder Price Guide
CREATE TABLE IF NOT EXISTS {p}price_guide (
  item_type TEXT NOT NULL,
  item_no TEXT NOT NULL,
  color_id INTEGER NOT NULL DEFAULT -1,
  new_or_used TEXT NOT NULL,
  currency TEXT NOT NULL,
  avg_price REAL NOT NULL,
  source TEXT NOT NULL DEFAULT '',
  fetched_at TEXT NOT NULL,
  PRIMARY KEY (item_type, item_no, color_id, new_or_used, currency)
);

-- Minifig-Finder: eigene BrickStore-Dateien als zusätzlicher oder alleiniger Teilebestand
CREATE TABLE IF NOT EXISTS {p}minifig_bsx (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  items_json TEXT NOT NULL,
  lots INTEGER NOT NULL DEFAULT 0,
  parts INTEGER NOT NULL DEFAULT 0,
  uploaded_by INTEGER NULL,
  created_at TEXT NOT NULL
);
