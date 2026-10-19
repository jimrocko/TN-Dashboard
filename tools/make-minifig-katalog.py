#!/usr/bin/env python3
"""
Packt den Minifig-Katalog (Figuren mit Stücklisten, Superset-Cache, EUR-Preise) aus einer SQLite-Datenbank
in api/sql/minifig-katalog.json.gz. Damit rechnet der Minifig-Finder ohne BrickLink-API (wie Ollies
MinifigFinder Standalone). Der Server liest die Datei beim ersten Aufruf ein (Minifig::ensureBundledCatalog).

Quelle: data/minifigfinder.db aus dem MinifigFinder Standalone oder data/legoshop.db aus Ollies Dashboard
(gleiche Tabellen). Die Datei wird nur gelesen. Bestand, Aufträge und Kundendaten werden nicht übernommen.

Aufruf:  python3 tools/make-minifig-katalog.py <pfad/zur/datenbank.db> [Quelle-Text]
"""
import gzip
import json
import sqlite3
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / 'api' / 'sql' / 'minifig-katalog.json.gz'


def rows(conn, sql):
    try:
        return [dict(r) for r in conn.execute(sql).fetchall()]
    except sqlite3.OperationalError:
        return []


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    db = Path(sys.argv[1]).resolve()
    conn = sqlite3.connect(f'file:{db}?mode=ro', uri=True)
    conn.row_factory = sqlite3.Row
    catalog = rows(conn, """SELECT minifig_no, name, category_id, first_seen_at, last_seen_at, subset_fetched_at, subset_json
        FROM minifig_catalog WHERE subset_json IS NOT NULL AND subset_json <> '' ORDER BY rowid""")
    superset = rows(conn, 'SELECT item_no, color_id, fetched_at, response_json FROM minifig_superset_cache ORDER BY item_no, color_id')
    prices = []
    for r in rows(conn, """SELECT item_type, item_no, color_id, condition, currency_code, payload_json, fetched_at
            FROM assortment_price_guide_cache WHERE currency_code = 'EUR'
            AND UPPER(COALESCE(item_type, '')) IN ('M', 'MINIFIG', 'MINIFIGURE', 'P', 'PART')
            ORDER BY rowid"""):
        try:
            p = json.loads(r['payload_json'] or '{}')
        except json.JSONDecodeError:
            continue
        # wie local_reference_prices im Original: avg_price, sonst qty_avg_price
        avg = p.get('avg_price') or p.get('qty_avg_price')
        if avg is None or float(avg) <= 0:
            continue
        prices.append({'item_type': r['item_type'], 'item_no': r['item_no'], 'color_id': int(r['color_id'] if r['color_id'] is not None else -1),
                       'new_or_used': r['condition'] or 'U', 'currency': 'EUR', 'avg_price': float(avg),
                       'source': p.get('source') or 'brickstore_import', 'fetched_at': r['fetched_at']})
    stand = max([c['subset_fetched_at'] or '' for c in catalog] + [''])[:10]
    data = {'format': 'tn-minifig-katalog', 'version': 1, 'quelle': sys.argv[2] if len(sys.argv) > 2 else db.name, 'stand': stand,
            'catalog': catalog, 'superset': superset, 'price_guide': prices}
    raw = json.dumps(data, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    # mtime=0: gleiche Daten ergeben dieselbe Datei (keine unnötigen Änderungen im Repo)
    OUT.write_bytes(gzip.compress(raw, compresslevel=9, mtime=0))
    print(f'{OUT.name}: {len(catalog)} Figuren, {len(superset)} Supersets, {len(prices)} Preise, {OUT.stat().st_size // 1024} KB')


if __name__ == '__main__':
    main()
