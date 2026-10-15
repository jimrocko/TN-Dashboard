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
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {p}idx_inv_item ON {p}inventory (item_no, color_id);
CREATE INDEX IF NOT EXISTS {p}idx_inv_loc ON {p}inventory (location_id);

CREATE TABLE IF NOT EXISTS {p}orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_no TEXT NOT NULL UNIQUE,
  buyer TEXT NOT NULL,
  status TEXT NOT NULL,
  ordered_at TEXT NOT NULL,
  total REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS {p}order_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES {p}orders(id) ON DELETE CASCADE,
  inventory_id INTEGER NULL,
  item_no TEXT NOT NULL,
  color_name TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  qty INTEGER NOT NULL,
  price REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS {p}transfers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_no TEXT NOT NULL,
  color_name TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  from_location_id INTEGER NULL,
  to_location_id INTEGER NULL,
  qty INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS {p}stocktakes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  location_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'offen',
  created_at TEXT NOT NULL,
  closed_at TEXT NULL,
  closed_by INTEGER NULL
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
