"""Gemeinsame Daten für Logbuch und Handbuch: Commits aus git, Texte aus docs/logbuch.json.

Zeiten in deutscher Ortszeit (Europe/Berlin). Ein Arbeitstag endet um 4 Uhr nachts,
damit Arbeit nach Mitternacht noch zum selben Tag zählt.
"""
import json
import os
import re
import subprocess
from collections import Counter, OrderedDict
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
JSON = os.path.join(ROOT, 'docs', 'logbuch.json')
ZONE = ZoneInfo('Europe/Berlin')
TAG_ENDE = 4  # Uhr
WOCHENTAGE = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag']
MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']

# Was in Commit-Betreffs nicht im Logbuch stehen darf (geheimer Stil, Personennamen, alter Begriff).
# Steht so etwas im Betreff, braucht der Commit einen Ersatztext in docs/logbuch.json → commit_texte.
VERBOTEN = re.compile(r'\bXP\b|(?-i:Xp[A-Z])|Retro|Konami|Spaß-Apps|Ollie|Schul|(?-i:\bLose[ns]?\b)|versteckten Code', re.I)

# Generierte oder binäre Dateien zählen nicht als geänderte Zeilen
GENERIERT = re.compile(r'^(TN-Dashboard-(Demo|Tutorial)\.html|docs/.*\.(pdf|png|jpg)|frontend/public/.*\.png|tools/.*\.png|'
                       r'api/sql/(demo-data\.json|minifig-katalog\.json\.gz)|.*package-lock\.json)$')

BEREICHE = [
    (r'^api/', 'Backend (PHP)'),
    (r'^frontend/src/demo/', 'Demo-Backend'),
    (r'^frontend/src/tutorial/', 'Tutorial und Hilfe'),
    (r'^frontend/', 'Oberfläche (Vue)'),
    (r'^(README\.md|CLAUDE\.md|AGENTS\.md|docs/)', 'Dokumentation'),
    (r'^tools/', 'Werkzeuge'),
    (r'^TN-Dashboard-', 'Demo- und Tutorial-Datei'),
    (r'', 'Sonstiges'),
]


def bereich(pfad):
    for muster, name in BEREICHE:
        if re.match(muster, pfad):
            return name
    return 'Sonstiges'


def texte():
    with open(JSON, encoding='utf-8') as f:
        return json.load(f)


def _git(*args):
    return subprocess.run(['git', *args], cwd=ROOT, capture_output=True, text=True, check=True).stdout


def commits():
    """Alle Commits ab HEAD, älteste zuerst, mit Ortszeit, Arbeitstag und Dateistatistik."""
    roh = _git('log', '--reverse', '--format=@@%H|%h|%at|%P|%s', '--numstat')
    liste = []
    for block in roh.split('@@')[1:]:
        zeilen = block.strip('\n').split('\n')
        voll, kurz, ts, eltern, betreff = zeilen[0].split('|', 4)
        zeit = datetime.fromtimestamp(int(ts), ZONE)
        tag = (zeit - timedelta(hours=TAG_ENDE)).date()
        dateien, plus, minus = [], 0, 0
        for z in zeilen[1:]:
            teile = z.split('\t')
            if len(teile) != 3:
                continue
            a, b, pfad = teile
            pfad = re.sub(r'\{[^}]*=> ([^}]*)\}', r'\1', pfad).replace('//', '/')
            pfad = pfad.split(' => ')[-1]
            dateien.append(pfad)
            if a != '-' and not GENERIERT.match(pfad):
                plus += int(a)
                minus += int(b)
        liste.append({'hash': kurz, 'zeit': zeit, 'tag': tag, 'betreff': betreff, 'merge': len(eltern.split()) > 1,
                      'dateien': dateien, 'plus': plus, 'minus': minus})
    return liste


def anzeige_text(c, ersatz):
    text = ersatz.get(c['hash'], c['betreff'])
    if VERBOTEN.search(text):
        raise SystemExit(f'Commit {c["hash"]}: Betreff braucht einen Ersatztext in docs/logbuch.json (commit_texte): {text}')
    return re.sub(r'\s*\(#\d+\)$', '', text)


def tage():
    """Arbeitstage mit Commits und Statistik, älteste zuerst."""
    t = texte()
    ersatz = t.get('commit_texte', {})
    ausgelassen = set(t.get('commit_auslassen', []))
    nach_tag = OrderedDict()
    for c in commits():
        nach_tag.setdefault(c['tag'], []).append(c)
    ergebnis = []
    for tag, cs in nach_tag.items():
        dateien = set()
        bereiche = Counter()
        for c in cs:
            if c['merge']:
                continue
            for d in c['dateien']:
                if d not in dateien:
                    dateien.add(d)
                    bereiche[bereich(d)] += 1
        eintraege = [{'hash': c['hash'], 'zeit': c['zeit'].strftime('%H:%M'), 'text': anzeige_text(c, ersatz), 'merge': c['merge']}
                     for c in cs if c['hash'] not in ausgelassen]
        ergebnis.append({
            'datum': tag,
            'titel_datum': f'{WOCHENTAGE[tag.weekday()]}, {tag.day}. {MONATE[tag.month - 1]} {tag.year}',
            'kurz_datum': tag.strftime('%d.%m.%Y'),
            'commits': len(cs),
            'eintraege': eintraege,
            'dateien': len(dateien),
            'bereiche': bereiche.most_common(),
            'plus': sum(c['plus'] for c in cs if not c['merge']),
            'minus': sum(c['minus'] for c in cs if not c['merge']),
            'von': cs[0]['zeit'].strftime('%H:%M'),
            'bis': cs[-1]['zeit'].strftime('%H:%M'),
            'text': t['tage'].get(tag.isoformat()),
        })
    return ergebnis
