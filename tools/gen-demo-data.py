#!/usr/bin/env python3
"""
Erzeugt die erfundenen Beispieldaten api/sql/demo-data.json (Lagerorte, Lose, Aufträge, Inventuren).
Alle Daten sind ausgedacht: keine echten Kunden oder Bestellungen.

Aufbau nach dem Lagerzimmer des Inhabers (siehe CLAUDE.md, „Lagerzimmer und Kästchen-Codes“):
  G100–G999 alle belegt mit wenigen Teilen (gebraucht), ein paar davon als Mischkiste (mehr als 3 Lose)
  H000–H100 voll, N000–N200 voll (neu)
  FG, FN (Figuren gebraucht/neu), FT (Figurenteile), Plate: voll
Gesamtbestand etwa ZIEL_TEILE.

Aufruf:  python3 tools/gen-demo-data.py   (danach Demo neu bauen: cd frontend && npm run build:demo)
"""
import json
import random
from pathlib import Path

ZIEL_TEILE = 125_000
SEED = 2026
# Bereich: (von, bis, Stellen) – Stellen = führende Nullen (N005)
BEREICHE = {
    'G': (100, 999, 3),
    'H': (0, 100, 3),
    'N': (0, 200, 3),
    'FG': (1, 40, 0),
    'FN': (1, 40, 0),
    'FT': (1, 60, 0),
    'Plate': (1, 80, 0),
}
NAMEN = {'G': 'Gebraucht', 'H': 'Regal H', 'N': 'Neu', 'FG': 'Figuren gebraucht', 'FN': 'Figuren neu',
         'FT': 'Figurenteile', 'Plate': 'Plates'}
ANTEIL_MISCHKISTEN_G = 0.04  # so viele G-Kästchen haben noch 4–5 Lose (rot im Lagerplan)

FARBEN = [(1, 'White'), (11, 'Black'), (5, 'Red'), (7, 'Blue'), (3, 'Yellow'), (86, 'Light Bluish Gray'),
          (85, 'Dark Bluish Gray'), (88, 'Reddish Brown'), (2, 'Tan'), (6, 'Green'), (4, 'Orange'),
          (69, 'Dark Tan'), (59, 'Dark Red'), (63, 'Dark Blue'), (80, 'Dark Green'), (34, 'Lime'),
          (48, 'Sand Green'), (42, 'Medium Blue'), (12, 'Trans-Clear')]

TEILE = [  # (Nummer, Name, Kategorie, Preis von, bis)
    ('3001', 'Brick 2 x 4', 'Brick', .05, .30), ('3002', 'Brick 2 x 3', 'Brick', .05, .25),
    ('3003', 'Brick 2 x 2', 'Brick', .03, .20), ('3004', 'Brick 1 x 2', 'Brick', .02, .12),
    ('3005', 'Brick 1 x 1', 'Brick', .02, .10), ('3008', 'Brick 1 x 8', 'Brick', .08, .35),
    ('3009', 'Brick 1 x 6', 'Brick', .06, .30), ('3010', 'Brick 1 x 4', 'Brick', .04, .20),
    ('3622', 'Brick 1 x 3', 'Brick', .03, .15), ('3062b', 'Brick, Round 1 x 1 Open Stud', 'Brick, Round', .02, .10),
    ('3020', 'Plate 2 x 4', 'Plate', .03, .20), ('3021', 'Plate 2 x 3', 'Plate', .03, .15),
    ('3022', 'Plate 2 x 2', 'Plate', .02, .12), ('3023', 'Plate 1 x 2', 'Plate', .01, .08),
    ('3024', 'Plate 1 x 1', 'Plate', .01, .06), ('3034', 'Plate 2 x 8', 'Plate', .06, .30),
    ('3460', 'Plate 1 x 8', 'Plate', .05, .25), ('3623', 'Plate 1 x 3', 'Plate', .02, .10),
    ('3666', 'Plate 1 x 6', 'Plate', .03, .18), ('3710', 'Plate 1 x 4', 'Plate', .02, .12),
    ('3795', 'Plate 2 x 6', 'Plate', .05, .25), ('3832', 'Plate 2 x 10', 'Plate', .10, .40),
    ('3958', 'Plate 6 x 6', 'Plate', .20, .80), ('4073', 'Plate, Round 1 x 1 Straight Side', 'Plate, Round', .01, .06),
    ('3069b', 'Tile 1 x 2 with Groove', 'Tile', .02, .12), ('3068b', 'Tile 2 x 2 with Groove', 'Tile', .04, .25),
    ('3070b', 'Tile 1 x 1 with Groove', 'Tile', .02, .10), ('2431', 'Tile 1 x 4', 'Tile', .04, .20),
    ('6636', 'Tile 1 x 6', 'Tile', .06, .30), ('87079', 'Tile 2 x 4', 'Tile', .08, .35),
    ('98138', 'Tile, Round 1 x 1', 'Tile, Round', .02, .10), ('3040', 'Slope 45 2 x 1', 'Slope', .03, .15),
    ('3039', 'Slope 45 2 x 2', 'Slope', .04, .20), ('3665', 'Slope, Inverted 45 2 x 1', 'Slope, Inverted', .03, .15),
    ('54200', 'Slope 30 1 x 1 x 2/3', 'Slope', .02, .10), ('2780', 'Technic, Pin with Friction Ridges', 'Technic, Pin', .01, .05),
    ('6558', 'Technic, Pin 3L with Friction Ridges', 'Technic, Pin', .02, .08), ('32062', 'Technic, Axle 2 Notched', 'Technic, Axle', .02, .08),
    ('3713', 'Technic, Bush', 'Technic, Bush', .01, .06), ('32523', 'Technic, Liftarm Thick 1 x 3', 'Technic, Liftarm', .03, .15),
    ('3700', 'Technic, Brick 1 x 2 with Hole', 'Technic, Brick', .03, .15), ('4865b', 'Panel 1 x 2 x 1 with Rounded Corners', 'Panel', .04, .20),
    ('60592', 'Window 1 x 2 x 2 Flat Front', 'Window', .06, .30),
]
FIGURENTEILE = [
    ('3626c', 'Minifigure, Head', 'Minifigure, Body Part', .08, .40), ('973', 'Minifigure, Torso', 'Minifigure, Body Part', .15, .80),
    ('970c00', 'Minifigure, Hips and Legs', 'Minifigure, Body Part', .10, .60), ('3624', 'Minifigure, Headgear Cap - Police Hat', 'Minifigure, Headgear', .10, .50),
    ('3901', 'Minifigure, Hair Male', 'Minifigure, Headgear', .10, .60), ('3833', 'Minifigure, Headgear Construction Helmet', 'Minifigure, Headgear', .10, .50),
    ('4485', 'Minifigure, Headgear Cap with Long Flat Bill', 'Minifigure, Headgear', .10, .50),
]
FIGUREN = ['Polizist', 'Feuerwehrmann', 'Bauarbeiter', 'Ritter', 'Pirat', 'Astronaut', 'Koch', 'Ärztin',
           'Mechanikerin', 'Postbote', 'Gärtnerin', 'Taucher', 'Skaterin', 'Bäcker', 'Fußballer', 'Lehrerin']

rnd = random.Random(SEED)


def code(bereich, n):
    stellen = BEREICHE[bereich][2]
    return f'{bereich}{n:0{stellen}d}' if stellen else f'{bereich}{n}'


def los(teil, farbe, ort, menge):
    nr, name, kat, p0, p1 = teil
    return {'item_type': 'PART', 'item_no': nr, 'color_id': farbe[0], 'color_name': farbe[1], 'name': name,
            'category': kat, 'location': ort, 'qty': menge, 'price': round(rnd.uniform(p0, p1), 3),
            'updated_days_ago': rnd.randint(0, 60)}


def fuelle(ort, auswahl, anzahl, menge_von, menge_bis, farben=FARBEN):
    """anzahl verschiedene Lose (Teil + Farbe) in ein Kästchen."""
    belegt, lose = set(), []
    while len(lose) < anzahl:
        teil, farbe = rnd.choice(auswahl), rnd.choice(farben)
        if (teil[0], farbe[0]) in belegt:
            continue
        belegt.add((teil[0], farbe[0]))
        lose.append(los(teil, farbe, ort, rnd.randint(menge_von, menge_bis)))
    return lose


lagerorte, inventar = [], []
for b, (von, bis, _) in BEREICHE.items():
    for n in range(von, bis + 1):
        c = code(b, n)
        lagerorte.append({'code': c, 'name': f'{NAMEN[b]}, Kästchen {c[len(b):]}', 'kind': 'regal'})
        if b == 'G':
            anzahl = rnd.randint(4, 5) if rnd.random() < ANTEIL_MISCHKISTEN_G else rnd.choice([1, 2, 2, 3, 3])
            inventar += fuelle(c, TEILE, anzahl, 3, 25)  # „ein paar Teile“
        elif b in ('H', 'N'):
            inventar += fuelle(c, TEILE, rnd.choice([1, 2, 3]), 40, 260)
        elif b == 'Plate':
            platten = [t for t in TEILE if t[2].startswith('Plate')]
            inventar += fuelle(c, platten, rnd.choice([1, 2, 3]), 60, 320)
        elif b == 'FT':
            inventar += fuelle(c, FIGURENTEILE, rnd.choice([1, 2, 3]), 15, 90)
        else:  # FG, FN: ganze Minifiguren (ausgedachte Nummern)
            for i in range(rnd.choice([1, 2, 3])):
                fig = rnd.choice(FIGUREN)
                inventar.append({'item_type': 'MINIFIG', 'item_no': f'TN-{fig[:3].upper()}{rnd.randint(1, 9):02d}', 'color_id': 0,
                                 'color_name': '', 'name': f'Minifigur {fig} (Beispiel)', 'category': 'Minifigur', 'location': c,
                                 'qty': rnd.randint(1, 6), 'price': round(rnd.uniform(1.5, 6.0), 2), 'updated_days_ago': rnd.randint(0, 60)})
# Doppelte Minifig-Nummern im selben Kästchen zusammenfassen
seen = {}
for it in list(inventar):
    k = (it['location'], it['item_no'], it['color_id'])
    if k in seen:
        seen[k]['qty'] += it['qty']
        inventar.remove(it)
    else:
        seen[k] = it

# Mengen auf ZIEL_TEILE bringen: G bleibt bei „ein paar Teilen“, der Rest wird skaliert.
g = sum(i['qty'] for i in inventar if i['location'].startswith('G'))
rest = [i for i in inventar if not i['location'].startswith('G') and i['item_type'] == 'PART']
faktor = (ZIEL_TEILE - g - sum(i['qty'] for i in inventar if i['item_type'] == 'MINIFIG')) / sum(i['qty'] for i in rest)
for i in rest:
    i['qty'] = max(1, round(i['qty'] * faktor))

# Mischkisten zum Einsortieren (MK-1…3): Teile, die schon einen festen Platz haben, plus eins ohne Platz
regal = [i for i in inventar if i['item_type'] == 'PART']
for k in range(1, 4):
    c = f'MK-{k}'
    lagerorte.append({'code': c, 'name': f'Mischkiste {k}', 'kind': 'mischkiste'})
    gewaehlt = set()
    while len(gewaehlt) < 4:
        v = rnd.choice(regal)
        if (v['item_no'], v['color_id']) not in gewaehlt:
            gewaehlt.add((v['item_no'], v['color_id']))
            inventar.append({**v, 'location': c, 'qty': rnd.randint(5, 60), 'updated_days_ago': rnd.randint(0, 10)})
    inventar.append(los(rnd.choice(TEILE), (12, 'Trans-Clear'), c, rnd.randint(5, 40)))

# Aufträge (erfunden) mit Positionen aus G/H/N
kaeufer = ['brickfan_92', 'mocbuilder', 'steinchenwelt', 'klemmbaustein_kai', 'bluebrick_de', 'citybuilder77',
           'technic_tom', 'lena_bricks', 'modulbau_hh', 'plate_collector']
status = ['Neu', 'Neu', 'Bezahlt', 'Bezahlt', 'Verpackt', 'Versandt', 'Versandt', 'Abgeschlossen', 'Abgeschlossen', 'Bezahlt', 'Neu', 'Verpackt']
regal_idx = [n for n, i in enumerate(inventar) if i['item_type'] == 'PART' and i['location'][0] in 'GHN']
auftraege = []
for n, st in enumerate(status):
    pos = []
    for idx in rnd.sample(regal_idx, rnd.randint(3, 8)):
        pos.append({'lot': idx, 'qty': rnd.randint(1, min(30, inventar[idx]['qty'])), 'price': inventar[idx]['price']})
    auftraege.append({'order_no': str(28471000 + n * 137 + rnd.randint(0, 99)), 'buyer': kaeufer[n % len(kaeufer)], 'status': st,
                      'days_ago': {'Neu': 0, 'Bezahlt': 1, 'Verpackt': 2, 'Versandt': 4, 'Abgeschlossen': 9}[st] + rnd.randint(0, 3),
                      'items': pos})

daten = {
    '_hinweis': 'Erfundene Beispieldaten für Demo und Tests (tools/gen-demo-data.py). Keine echten Bestellungen oder Kunden.',
    'locations': lagerorte, 'inventory': inventar, 'orders': auftraege,
    'stocktakes': [{'location': 'G245'}, {'location': 'H012'}, {'location': 'MK-2'}],
}
ziel = Path(__file__).resolve().parent.parent / 'api' / 'sql' / 'demo-data.json'
# Ein Datensatz pro Zeile: lesbare Git-Diffs, aber kleine Datei
with ziel.open('w', encoding='utf-8') as f:
    f.write('{\n')
    teile = list(daten.items())
    for k, (key, val) in enumerate(teile):
        f.write(f' {json.dumps(key)}: ')
        if isinstance(val, list):
            f.write('[\n' + ',\n'.join('  ' + json.dumps(v, ensure_ascii=False, separators=(',', ':')) for v in val) + '\n ]')
        else:
            f.write(json.dumps(val, ensure_ascii=False))
        f.write(',\n' if k < len(teile) - 1 else '\n')
    f.write('}\n')

gesamt = sum(i['qty'] for i in inventar)
print(f'{len(lagerorte)} Lagerorte, {len(inventar)} Lose, {gesamt:_} Teile, {len(auftraege)} Aufträge -> {ziel}'.replace('_', '.'))
print('Mischkisten in G (mehr als 3 Lose):', sum(1 for l in lagerorte if l['code'].startswith('G') and sum(1 for i in inventar if i['location'] == l['code']) > 3))
