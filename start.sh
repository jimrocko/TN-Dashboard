#!/usr/bin/env bash
# TN Dashboard lokal starten (Mac/Linux) und im Browser öffnen.
#   ./start.sh              baut die App beim ersten Mal, startet sie und öffnet den Browser
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
  echo "Node.js fehlt. Mac: 'brew install node'  ·  sonst https://nodejs.org (LTS-Version)"; exit 1
fi

# Lokale Einstellungen: SQLite statt MySQL. Wird von der App automatisch gefunden.
if [ ! -f .env ]; then
  mkdir -p local-data
  printf 'TN_DB_DRIVER=sqlite\nTN_DB_PATH=%s/local-data/tn_dashboard.sqlite\n' "$PWD" > .env
  echo "Lokale .env angelegt (SQLite in local-data/)."
fi

if [ ! -f dist/index.html ] || [ "$1" = "--neu-bauen" ]; then
  echo "Baue die App (beim ersten Mal ca. 1 Minute) …"
  (cd frontend && npm install && npm run build)
fi

php dist/api/install.php --demo

# Freien Port suchen (8000 ist auf dem Mac manchmal schon belegt).
port_busy() { php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$1"; }
PORT="${PORT:-8000}"
while port_busy "$PORT"; do
  echo "Port $PORT ist belegt, versuche $((PORT + 1)) …"
  PORT=$((PORT + 1))
done

# 127.0.0.1 statt localhost: Safari versucht bei localhost zuerst IPv6, der Server hört auf IPv4.
URL="http://127.0.0.1:$PORT"

php -S 0.0.0.0:"$PORT" -t dist &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null' EXIT INT TERM

# Warten, bis der Server wirklich antwortet, erst dann den Browser öffnen.
for _ in $(seq 1 50); do
  port_busy "$PORT" && break
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "Der Server konnte nicht starten (siehe Meldung oben)."; exit 1
  fi
  sleep 0.2
done

echo
echo "  TN Dashboard läuft:  $URL"
echo "  Test-Konten:         lehrer/lehrer123 · max/max12345 · erika/erika123"
echo "  Auf dem iPad (gleiches WLAN): http://<IP-dieses-Computers>:$PORT"
echo "  Beenden mit Ctrl+C"
echo

if [ -z "$NO_BROWSER" ]; then
  if command -v open >/dev/null && [ "$(uname)" = "Darwin" ]; then
    open "$URL"
  elif command -v xdg-open >/dev/null; then
    xdg-open "$URL" >/dev/null 2>&1 || true
  fi
fi

wait "$SERVER_PID"
