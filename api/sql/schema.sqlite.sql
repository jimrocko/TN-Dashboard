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
