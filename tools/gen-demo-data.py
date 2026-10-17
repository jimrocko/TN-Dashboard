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
          (48, 'Sand Green'), (42, 'Medium Blue'), (12, 'Trans-Clear'), (17, 'Trans-Red'), (15, 'Trans-Light Blue'),
          (68, 'Dark Orange'), (156, 'Medium Azure'), (155, 'Olive Green'), (115, 'Pearl Gold'), (95, 'Flat Silver'),
          (104, 'Bright Pink'), (89, 'Dark Purple'), (150, 'Medium Nougat'), (71, 'Magenta')]

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
    ('3006', 'Brick 2 x 10', 'Brick', .20, .70), ('3007', 'Brick 2 x 8', 'Brick', .15, .60), ('2456', 'Brick 2 x 6', 'Brick', .10, .45),
    ('3245c', 'Brick 1 x 2 x 2 with Inside Stud Holder', 'Brick', .05, .25), ('2357', 'Brick 2 x 2 Corner', 'Brick', .04, .20),
    ('6112', 'Brick 1 x 12', 'Brick', .15, .50), ('3941', 'Brick, Round 2 x 2 with Axle Hole', 'Brick, Round', .04, .20),
    ('4070', 'Brick, Modified 1 x 1 with Headlight', 'Brick, Modified', .03, .15), ('87087', 'Brick, Modified 1 x 1 with Stud on 1 Side', 'Brick, Modified', .02, .12),
    ('3031', 'Plate 4 x 4', 'Plate', .08, .35), ('3032', 'Plate 4 x 6', 'Plate', .10, .45), ('3035', 'Plate 4 x 8', 'Plate', .12, .55),
    ('3036', 'Plate 6 x 8', 'Plate', .20, .80), ('3030', 'Plate 4 x 10', 'Plate', .15, .60), ('2420', 'Plate 2 x 2 Corner', 'Plate', .02, .10),
    ('2445', 'Plate 2 x 12', 'Plate', .12, .50), ('60479', 'Plate 1 x 12', 'Plate', .08, .35), ('3794b', 'Plate, Modified 1 x 2 with 1 Stud (Jumper)', 'Plate, Modified', .02, .10),
    ('63864', 'Tile 1 x 3', 'Tile', .03, .15), ('4162', 'Tile 1 x 8', 'Tile', .08, .35), ('2412b', 'Tile, Modified 1 x 2 Grille', 'Tile, Modified', .03, .15),
    ('3038', 'Slope 45 2 x 3', 'Slope', .05, .25), ('3037', 'Slope 45 2 x 4', 'Slope', .06, .30), ('3298', 'Slope 33 3 x 2', 'Slope', .05, .25),
    ('4286', 'Slope 33 3 x 1', 'Slope', .03, .15), ('3045', 'Slope 45 2 x 2 Double Convex', 'Slope', .06, .25), ('60481', 'Slope 65 2 x 1 x 2', 'Slope', .05, .25),
    ('3701', 'Technic, Brick 1 x 4 with Holes', 'Technic, Brick', .05, .25), ('3894', 'Technic, Brick 1 x 6 with Holes', 'Technic, Brick', .08, .30),
    ('32524', 'Technic, Liftarm Thick 1 x 7', 'Technic, Liftarm', .06, .25), ('32316', 'Technic, Liftarm Thick 1 x 5', 'Technic, Liftarm', .05, .20),
    ('3705', 'Technic, Axle 4', 'Technic, Axle', .03, .12), ('3706', 'Technic, Axle 6', 'Technic, Axle', .04, .15),
    ('43093', 'Technic, Axle 1L with Pin with Friction Ridges', 'Technic, Pin', .02, .08), ('4589', 'Cone 1 x 1', 'Cone', .02, .10),
]
# Namen wie im BrickLink-Katalog: der Minifig-Finder erkennt Figurenteile am Namensanfang (Torso, Hips and Legs, Minifigure, Head …)
FIGURENTEILE = [
    ('3626c', 'Minifigure, Head (Plain)', 'Minifigure, Head', .08, .40), ('973', 'Torso Plain', 'Minifigure, Torso', .15, .80),
    ('970c00', 'Hips and Legs Plain', 'Minifigure, Legs', .10, .60), ('3624', 'Minifigure, Headgear Cap - Police Hat', 'Minifigure, Headgear', .10, .50),
    ('3901', 'Minifigure, Hair Male', 'Minifigure, Headgear', .10, .60), ('3833', 'Minifigure, Headgear Construction Helmet', 'Minifigure, Headgear', .10, .50),
    ('4485', 'Minifigure, Headgear Cap with Long Flat Bill', 'Minifigure, Headgear', .10, .50),
]
FIGUREN = ['Polizist', 'Feuerwehrmann', 'Bauarbeiter', 'Ritter', 'Pirat', 'Astronaut', 'Koch', 'Ärztin',
           'Mechanikerin', 'Postbote', 'Gärtnerin', 'Taucher', 'Skaterin', 'Bäcker', 'Fußballer', 'Lehrerin']

rnd = random.Random(SEED)
PLATTEN = [t for t in TEILE if t[2].startswith('Plate')]


def code(bereich, n):
    stellen = BEREICHE[bereich][2]
    return f'{bereich}{n:0{stellen}d}' if stellen else f'{bereich}{n}'


def los(teil, farbe, ort, menge):
    nr, name, kat, p0, p1 = teil
    return {'item_type': 'PART', 'item_no': nr, 'color_id': farbe[0], 'color_name': farbe[1], 'name': name,
            'category': kat, 'location': ort, 'qty': menge, 'price': round(rnd.uniform(p0, p1), 3),
            'updated_days_ago': rnd.randint(0, 60)}


STAPEL = {}  # (Bereich, Auswahl) → gemischte Liste noch nicht verteilter Kombinationen


ANTEIL_DURCHEINANDER = 0.05  # so oft landet ein zufälliges Teil in einem sonst sortierten Kästchen


def naechste(bereich, auswahl, farben):
    """Nächste Kombination für den Bereich. Der Stapel ist nach Teil sortiert (Farben gemischt),
    damit Kästchen meist ein Teil in mehreren Farben enthalten – wie in einem sortierten Lager.
    Mit ANTEIL_DURCHEINANDER kommt stattdessen eine zufällige Kombination (Unordnung für Umlagern)."""
    key = (bereich, id(auswahl))
    if not STAPEL.get(key):
        teile = list(auswahl)
        rnd.shuffle(teile)
        STAPEL[key] = []
        for t in teile:
            fs = list(farben)
            rnd.shuffle(fs)
            STAPEL[key] += [(t, f) for f in fs]
        STAPEL[key].reverse()  # pop() nimmt von hinten
    if rnd.random() < ANTEIL_DURCHEINANDER:
        return STAPEL[key].pop(rnd.randrange(len(STAPEL[key])))
    return STAPEL[key].pop()


def fuelle(ort, auswahl, anzahl, menge_von, menge_bis, farben=FARBEN):
    """anzahl verschiedene Lose (Teil + Farbe) in ein Kästchen, aus dem Stapel des Bereichs gezogen."""
    bereich = ort.rstrip('0123456789')
    belegt, lose = set(), []
    while len(lose) < anzahl:
        teil, farbe = naechste(bereich, auswahl, farben)
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
            inventar += fuelle(c, PLATTEN, rnd.choice([1, 2, 3]), 60, 320)
        elif b == 'FT':
            inventar += fuelle(c, FIGURENTEILE, rnd.choice([1, 2, 3]), 15, 90)
        else:  # FG, FN: ganze Minifiguren (ausgedachte Nummern)
            for i in range(rnd.choice([1, 2, 3])):
                fig = rnd.choice(FIGUREN)
                inventar.append({'item_type': 'MINIFIG', 'item_no': f'TN-{fig[:3].upper()}{rnd.randint(1, 9):02d}', 'color_id': 0,
                                 'color_name': '', 'name': f'Minifigur {fig} (Beispiel)', 'category': 'Minifigur', 'location': c,
                                 'qty': rnd.randint(1, 6), 'price': round(rnd.uniform(1.5, 6.0), 2), 'updated_days_ago': rnd.randint(0, 60)})
# Ein paar Teile „aus Versehen“ in ein falsches Kästchen desselben Bereichs (für Umlagern-Vorschläge)
ANTEIL_FALSCH = 0.03
for bereich in ('G', 'H', 'N', 'Plate'):
    lose_b = [i for i in inventar if i['location'].rstrip('0123456789') == bereich and i['item_type'] == 'PART']
    kaestchen = sorted({i['location'] for i in lose_b})
    belegung = {k: sum(1 for i in lose_b if i['location'] == k) for k in kaestchen}
    for _ in range(int(len(kaestchen) * ANTEIL_FALSCH)):
        quelle = rnd.choice(lose_b)
        ziel = rnd.choice([k for k in kaestchen if belegung[k] < 3 and k != quelle['location']])
        if any(i['location'] == ziel and i['item_no'] == quelle['item_no'] and i['color_id'] == quelle['color_id'] for i in inventar):
            continue
        inventar.append({**quelle, 'location': ziel, 'qty': rnd.randint(2, 12), 'updated_days_ago': rnd.randint(0, 14)})
        belegung[ziel] += 1

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

# ---- Ergänzungen für Pickliste und Minifig-Finder ----
# Eigener Zufallsgenerator, damit Lager und Aufträge oben unverändert bleiben.
rnd2 = random.Random(SEED + 1)

# Zustand wie bei BrickLink (N = neu, U = gebraucht), je Bereich
ZUSTAND = {'G': 'U', 'H': 'N', 'N': 'N', 'FG': 'U', 'FN': 'N', 'FT': 'U', 'Plate': 'N'}
for i in inventar:
    i['new_or_used'] = ZUSTAND[i['location'].rstrip('0123456789')]

# Aufträge: Plattform, erfundener Kundenname, Versand (Adressen sind ausgedacht)
VORNAMEN = ['Jana', 'Tim', 'Mia', 'Paul', 'Lea', 'Finn', 'Emma', 'Noah', 'Lina', 'Ben', 'Ida', 'Ole']
NACHNAMEN = ['Beispiel', 'Muster', 'Probe', 'Testmann', 'Exempel', 'Vorlage']
VERSAND = [('Großbrief', 1.80), ('Großbrief', 1.80), ('Päckchen', 4.19), ('Paket', 6.19)]
for n, o in enumerate(auftraege):
    o['platform'] = 'BrickOwl' if n % 4 == 3 else 'BrickLink'
    o['customer'] = f'{rnd2.choice(VORNAMEN)} {rnd2.choice(NACHNAMEN)}'
    art, kosten = rnd2.choice(VERSAND)
    o['shipping'] = {'method': art, 'cost': kosten,
                     'address': [o['customer'], f'Beispielweg {rnd2.randint(1, 99)}', f'{rnd2.randint(10, 99)}000 Musterstadt', 'Deutschland']}

# Minifig-Finder: erfundene Figuren (TN-…) mit Stücklisten im BrickLink-Format.
# Gleiche Nummern wie die ganzen Figuren in FG/FN, damit es dafür Shoppreise gibt.
# Jede Figur: Kopf, eigener Torso, Beine, meist Kopfbedeckung/Haare (manchmal 2 Farben zur Wahl).
# Ein Teil der Torsos und Spezialköpfe fehlt im Lager (Figur „1 Teil fehlt“ bzw. weiter weg),
# Spezialköpfe teilen sich mehrere Figuren (Konflikte im gemeinsamen Bauplan).
YELLOW, BLACK, BROWN, RED, BLUE, DBG, WHITE, TAN = 3, 11, 88, 5, 7, 85, 1, 2
KOPFBEDECKUNG = [('3624', 'Minifigure, Headgear Cap - Police Hat', [BLACK, BLUE]),
                 ('3901', 'Minifigure, Hair Male', [BROWN, BLACK, TAN]),
                 ('3833', 'Minifigure, Headgear Construction Helmet', [YELLOW, WHITE, RED]),
                 ('4485', 'Minifigure, Headgear Cap with Long Flat Bill', [RED, BLUE, WHITE])]
BEINFARBEN = [BLACK, BLUE, DBG, TAN, RED, WHITE]
FARBNAME = dict(FARBEN)
figuren, figurteile = [], []  # figurteile: neue Lose in FT

def eintrag(no, name, color, qty=1):
    return {'item': {'no': no, 'name': name, 'type': 'PART', 'category_id': 0}, 'color_id': color,
            'quantity': qty, 'extra_quantity': 0, 'is_alternate': False}

spezialkoepfe = []
for k in range(1, 13):
    spezialkoepfe.append((f'3626cTN{k:02d}', f'Minifigure, Head Beispielgesicht {k} (erfunden)'))
for f_idx, fig in enumerate(FIGUREN):
    for k in range(1, 5):
        no = f'TN-{fig[:3].upper()}{k:02d}'
        torso = (f'973TN{f_idx + 1:02d}{k}', f'Torso {fig} Muster {k} (erfunden)')
        torso_farbe = rnd2.choice([WHITE, BLUE, RED, DBG, BLACK, TAN])
        kopf = rnd2.choice(spezialkoepfe) if rnd2.random() < 0.5 else ('3626c', 'Minifigure, Head (Plain)')
        # Zustand im Lager: baubar / 1 Teil fehlt / mehr fehlt (Beine in einer Farbe, die es im Lager nicht gibt)
        r = rnd2.random()
        zustand = 'baubar' if r < 0.45 else ('fast' if r < 0.75 else 'weit')
        beine = 158 if zustand == 'weit' else rnd2.choice(BEINFARBEN)
        gruppen = [[eintrag(kopf[0], kopf[1], YELLOW)], [eintrag(torso[0], torso[1], torso_farbe)],
                   [eintrag('970c00', 'Hips and Legs Plain', beine)]]
        if rnd2.random() < 0.85:
            hg = rnd2.choice(KOPFBEDECKUNG)
            farben = rnd2.sample(hg[2], 2 if rnd2.random() < 0.3 and len(hg[2]) > 1 else 1)
            gruppen.append([eintrag(hg[0], hg[1], c) for c in farben])
        figuren.append({'minifig_no': no, 'name': f'{fig}, Beispielfigur {k} (erfunden)', 'category_id': 999,
                        'subset': [{'match_no': i + 1, 'entries': g} for i, g in enumerate(gruppen)],
                        '_torso': (torso, torso_farbe), '_kopf': kopf, '_zustand': zustand})

ft_kaestchen = [c for c in (code('FT', n) for n in range(BEREICHE['FT'][0], BEREICHE['FT'][1] + 1))]
def neues_los(no, name, farbe, menge, kat):
    figurteile.append({'item_type': 'PART', 'item_no': no, 'color_id': farbe, 'color_name': FARBNAME.get(farbe, ''),
                       'name': name, 'category': kat, 'location': rnd2.choice(ft_kaestchen), 'qty': menge,
                       'price': round(rnd2.uniform(.4, 2.5), 3), 'updated_days_ago': rnd2.randint(0, 60), 'new_or_used': 'U'})

# Spezialköpfe: knapp (1–2 Stück), ein paar gar nicht vorhanden
for no, name in spezialkoepfe:
    if rnd2.random() < 0.8:
        neues_los(no, name, YELLOW, rnd2.randint(1, 2), 'Minifigure, Head')
koepfe_im_lager = {l['item_no'] for l in figurteile}
for f in figuren:
    (tno, tname), tfarbe = f['_torso']
    if f['_zustand'] == 'baubar' or (f['_zustand'] == 'fast' and f['_kopf'][0] not in koepfe_im_lager and f['_kopf'][0] != '3626c'):
        neues_los(tno, tname, tfarbe, rnd2.randint(1, 3), 'Minifigure, Torso')
# Standardteile der Figuren (Kopf gelb, Beine, Kopfbedeckungen) sicher im Lager
vorhanden = {(i['item_no'], i['color_id']) for i in inventar if i['location'].startswith('FT')}
for g in [e for f in figuren for grp in f['subset'] for e in grp['entries']]:
    key = (g['item']['no'], g['color_id'])
    if key[0].startswith(('973TN', '3626cTN')) or key in vorhanden or key == ('970c00', 158):
        continue
    vorhanden.add(key)
    neues_los(key[0], g['item']['name'], key[1], rnd2.randint(4, 30), 'Minifigure, Headgear' if key[0] not in ('970c00', '3626c') else 'Minifigure, Legs')
inventar += figurteile

# Was der Minifig-Finder schon kennt: geprüfte Teile-Varianten (Supersets) und geladene Stücklisten
kern = sorted({(i['item_no'], i['color_id']) for i in inventar if i['item_type'] == 'PART'
               and i['name'].lower().startswith(('torso ', 'hips ', 'hips and ', 'legs ', 'minifigure, head', 'minifigure head',
                                                  'minifigure, hair', 'minifigure hair', 'minifigure, headgear', 'minifigure headgear',
                                                  'minifigure, neckwear', 'minifigure neckwear'))})
geprueft = sorted(k for k in kern if rnd2.random() < 0.85)
bekannt = sorted({f['minifig_no'] for f in figuren for grp in f['subset'] for e in grp['entries']
                  if (e['item']['no'], e['color_id']) in set(geprueft)})
geladen = sorted(no for no in bekannt if rnd2.random() < 0.85)
for f in figuren:
    for k in ('_torso', '_kopf', '_zustand'):
        f.pop(k)

# Preise wie aus einem BrickStore-Preisimport (6-Monats-Durchschnitt, erfunden)
preise = []
for f in figuren:
    if rnd2.random() < 0.85:
        preise.append({'item_type': 'M', 'item_no': f['minifig_no'], 'color_id': 0, 'avg_price': round(rnd2.uniform(2.5, 18), 2)})
for no, farbe in sorted({(e['item']['no'], e['color_id']) for f in figuren for grp in f['subset'] for e in grp['entries']}):
    if no.startswith(('973TN', '3626cTN')) and rnd2.random() < 0.8:
        preise.append({'item_type': 'P', 'item_no': no, 'color_id': farbe, 'avg_price': round(rnd2.uniform(.3, 3.5), 2)})

daten = {
    '_hinweis': 'Erfundene Beispieldaten für Demo und Tests (tools/gen-demo-data.py). Keine echten Bestellungen oder Kunden.',
    'locations': lagerorte, 'inventory': inventar, 'orders': auftraege,
    'stocktakes': [{'location': 'G245'}, {'location': 'H012'}, {'location': 'N010'}],
    'minifigs': figuren,
    'minifig_state': {'scanned': geprueft, 'subsets_loaded': geladen},
    'price_guide': preise,
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
print(f'Minifig-Finder: {len(figuren)} Figuren, {len(geprueft)}/{len(kern)} Varianten geprüft, {len(geladen)}/{len(bekannt)} Stücklisten, {len(preise)} Preise')
print('Mischkisten in G (mehr als 3 Lose):', sum(1 for l in lagerorte if l['code'].startswith('G') and sum(1 for i in inventar if i['location'] == l['code']) > 3))
