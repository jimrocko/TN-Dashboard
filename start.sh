#!/usr/bin/env bash
# TN Dashboard lokal starten (Mac/Linux).
#   ./start.sh              baut die App beim ersten Mal und startet sie
#   ./start.sh --neu-bauen  baut die App neu (nach Code-Änderungen)
# Test-Daten liegen in local-data/ und bleiben beim Neubauen erhalten.
set -e
cd "$(dirname "$0")"

if ! command -v php >/dev/null; then
  echo "PHP fehlt. Mac: 'brew install php'  ·  Linux: 'sudo apt install php-cli php-sqlite3'"; exit 1
fi
if ! php -m | grep -qi '^pdo_sqlite$'; then
  echo "Die PHP-Erweiterung pdo_sqlite fehlt (Linux: 'sudo apt install php-sqlite3')."; exit 1
fi
if ! command -v npm >/dev/null; then
  echo "Node.js fehlt. Download: https://nodejs.org (LTS-Version)"; exit 1
fi

# Lokale Einstellungen: SQLite statt MySQL. Wird von der App automatisch gefunden.
if [ ! -f .env ]; then
  mkdir -p local-data
  printf 'TN_DB_DRIVER=sqlite\nTN_DB_PATH=%s/local-data/tn_dashboard.sqlite\n' "$PWD" > .env
  echo "Lokale .env angelegt (SQLite in local-data/)."
fi

if [ ! -f dist/index.html ] || [ "$1" = "--neu-bauen" ]; then
  echo "Baue die App …"
  (cd frontend && npm install && npm run build)
fi

php dist/api/install.php --demo

PORT="${PORT:-8000}"
echo
echo "  TN Dashboard läuft:  http://localhost:$PORT"
echo "  Test-Konten:         lehrer/lehrer123 · max/max12345 · erika/erika123"
echo "  Auf dem iPad (gleiches WLAN): http://<IP-dieses-Computers>:$PORT"
echo "  Beenden mit Strg+C"
echo
exec php -S 0.0.0.0:"$PORT" -t dist
