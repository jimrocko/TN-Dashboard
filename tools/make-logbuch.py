#!/usr/bin/env python3
"""Erzeugt das Logbuch der Entwicklung als PDF (docs/Logbuch.pdf).

Aufruf:  python3 tools/make-logbuch.py [ausgabe.pdf]

Commits, Zeiten und Statistik kommen aus git, die Texte je Arbeitstag aus docs/logbuch.json
(tools/logbuch_daten.py). Fehlt für einen Tag der Text, bricht das Skript ab.
"""
import copy
import os
import sys
from datetime import date

from reportlab.lib import colors
from reportlab.lib.enums import TA_JUSTIFY, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (BaseDocTemplate, Flowable, Frame, KeepTogether, NextPageTemplate, PageBreak, PageTemplate,
                                Paragraph, Spacer, Table, TableStyle)
from reportlab.platypus.tableofcontents import TableOfContents

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import logbuch_daten as L  # noqa: E402

AUSGABE = sys.argv[1] if len(sys.argv) > 1 else os.path.join(L.ROOT, 'docs', 'Logbuch.pdf')
STAND = f'{L.MONATE[date.today().month - 1]} {date.today().year}'

F = '/usr/share/fonts/truetype/'
pdfmetrics.registerFont(TTFont('Text', F + 'crosextra/Carlito-Regular.ttf'))
pdfmetrics.registerFont(TTFont('Text-B', F + 'crosextra/Carlito-Bold.ttf'))
pdfmetrics.registerFont(TTFont('Text-I', F + 'crosextra/Carlito-Italic.ttf'))
pdfmetrics.registerFont(TTFont('Text-BI', F + 'crosextra/Carlito-BoldItalic.ttf'))
pdfmetrics.registerFont(TTFont('Mono', F + 'dejavu/DejaVuSansMono.ttf'))
pdfmetrics.registerFontFamily('Text', normal='Text', bold='Text-B', italic='Text-I', boldItalic='Text-BI')

DUNKEL = colors.HexColor('#1f2a44')
AKZENT = colors.HexColor('#f2c200')
GRAU = colors.HexColor('#5b6475')
LINIE = colors.HexColor('#c9cfda')
HELL = colors.HexColor('#eef1f6')
BLAU = colors.HexColor('#3b82f6')

S = {
    'body': ParagraphStyle('body', fontName='Text', fontSize=10.5, leading=14.2, alignment=TA_JUSTIFY, spaceAfter=6),
    'h1': ParagraphStyle('h1', fontName='Text-B', fontSize=19, leading=23, textColor=DUNKEL, spaceAfter=4),
    'h1sub': ParagraphStyle('h1sub', fontName='Text', fontSize=11, leading=14, textColor=GRAU, spaceAfter=10),
    'h2': ParagraphStyle('h2', fontName='Text-B', fontSize=12.5, leading=16, textColor=DUNKEL, spaceBefore=9, spaceAfter=3),
    'cell': ParagraphStyle('cell', fontName='Text', fontSize=9.3, leading=11.8, alignment=TA_LEFT),
    'cellk': ParagraphStyle('cellk', fontName='Text', fontSize=8.8, leading=11, alignment=TA_LEFT),
    'cellb': ParagraphStyle('cellb', fontName='Text-B', fontSize=9.3, leading=11.8, textColor=colors.white),
    'mono': ParagraphStyle('mono', fontName='Mono', fontSize=7.8, leading=11, textColor=GRAU),
    'toc1': ParagraphStyle('toc1', fontName='Text', fontSize=10.8, leading=15),
    'kz': ParagraphStyle('kz', fontName='Text-B', fontSize=13, leading=16, textColor=DUNKEL),
    'kzl': ParagraphStyle('kzl', fontName='Text', fontSize=8.5, leading=10.5, textColor=GRAU),
}

TAGE = L.tage()
for t in TAGE:
    if not t['text']:
        raise SystemExit(f'In docs/logbuch.json fehlt der Text für den Arbeitstag {t["datum"]}.')
    roh = t['text']['kurz'] + t['text']['zusammenfassung'] + ''.join(x['titel'] + x['text'] for x in t['text']['themen'])
    if L.VERBOTEN.search(roh):
        raise SystemExit(f'Text für {t["datum"]} enthält „{L.VERBOTEN.search(roh).group(0)}“ – bitte umformulieren.')

GESAMT = {'n': 0}
story = []


def zahl(n):
    return f'{n:,}'.replace(',', '.')


class Balken(Flowable):
    def __init__(self, anteil, breite, farbe=BLAU):
        super().__init__()
        self.anteil, self.breite, self.farbe = anteil, breite, farbe

    def wrap(self, *a):
        return self.breite, 9

    def draw(self):
        self.canv.setFillColor(HELL)
        self.canv.rect(0, 1, self.breite, 7, stroke=0, fill=1)
        self.canv.setFillColor(self.farbe)
        self.canv.rect(0, 1, max(1.5, self.breite * self.anteil), 7, stroke=0, fill=1)


def tabelle(kopf, zeilen, breiten, kleine=False, zebra=True):
    st = S['cellk'] if kleine else S['cell']
    data = [[Paragraph(x, S['cellb']) for x in kopf]] + [[x if isinstance(x, Flowable) else Paragraph(x, st) for x in z] for z in zeilen]
    t = Table(data, colWidths=[b * cm for b in breiten], repeatRows=1)
    sty = [('BACKGROUND', (0, 0), (-1, 0), DUNKEL), ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
           ('GRID', (0, 0), (-1, -1), 0.5, LINIE), ('TOPPADDING', (0, 0), (-1, -1), 3.5),
           ('BOTTOMPADDING', (0, 0), (-1, -1), 3.5), ('LEFTPADDING', (0, 0), (-1, -1), 5), ('RIGHTPADDING', (0, 0), (-1, -1), 5)]
    if zebra:
        for i in range(2, len(data), 2):
            sty.append(('BACKGROUND', (0, i), (-1, i), HELL))
    t.setStyle(TableStyle(sty))
    return t


def kennzahlen(t):
    felder = [(str(t['commits']), 'Commits'), (f'{t["von"]}–{t["bis"]}', 'Uhrzeit (erster bis letzter)'),
              (zahl(t['dateien']), 'geänderte Dateien'), (f'+{zahl(t["plus"])} / −{zahl(t["minus"])}', 'Zeilen Quelltext')]
    zellen = [[Paragraph(a, S['kz']), Paragraph(b, S['kzl'])] for a, b in felder]
    t2 = Table([[z for z in zellen]], colWidths=[4 * cm] * 4)
    t2.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, -1), HELL), ('LINEABOVE', (0, 0), (-1, 0), 2, AKZENT),
                            ('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), 8),
                            ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6),
                            ('LINEAFTER', (0, 0), (-2, -1), 0.5, colors.white)]))
    return t2


class Doc(BaseDocTemplate):
    def __init__(self, fn):
        super().__init__(fn, pagesize=A4, leftMargin=2.5 * cm, rightMargin=2.5 * cm, topMargin=2.6 * cm, bottomMargin=2.3 * cm,
                         title='TN Dashboard – Logbuch der Entwicklung', subject='Was wann umgesetzt wurde',
                         author='TN Dashboard', creator='tools/make-logbuch.py')
        frame = Frame(self.leftMargin, self.bottomMargin, self.width, self.height, id='f')
        self.addPageTemplates([PageTemplate('titel', [frame], onPage=titelseite),
                               PageTemplate('inhalt', [frame], onPageEnd=kopf_fuss)])
        self.kap = ''

    def beforeDocument(self):
        self.kap = 'Vorbemerkung'

    def afterFlowable(self, f):
        toc = getattr(f, 'toc', None)
        if toc:
            text, kap = toc
            key = f'h{id(f)}'
            self.canv.bookmarkPage(key)
            self.canv.addOutlineEntry(text, key, level=0)
            self.notify('TOCEntry', (0, text, self.page, key))
            self.kap = kap


def kopf_fuss(canv, doc):
    canv.saveState()
    w, h = A4
    canv.setStrokeColor(AKZENT)
    canv.setLineWidth(1.6)
    canv.line(2.5 * cm, h - 1.75 * cm, w - 2.5 * cm, h - 1.75 * cm)
    canv.setFont('Text-B', 9)
    canv.setFillColor(DUNKEL)
    canv.drawString(2.5 * cm, h - 1.5 * cm, 'TN Dashboard · Logbuch')
    canv.setFont('Text', 9)
    canv.setFillColor(GRAU)
    canv.drawRightString(w - 2.5 * cm, h - 1.5 * cm, doc.kap)
    canv.setStrokeColor(LINIE)
    canv.setLineWidth(0.5)
    canv.line(2.5 * cm, 1.6 * cm, w - 2.5 * cm, 1.6 * cm)
    canv.drawString(2.5 * cm, 1.15 * cm, f'Stand: {STAND}')
    canv.drawRightString(w - 2.5 * cm, 1.15 * cm, f'Seite {doc.page} von {GESAMT["n"]}' if GESAMT['n'] else f'Seite {doc.page}')
    canv.restoreState()


def titelseite(canv, doc):
    canv.saveState()
    w, h = A4
    canv.setFillColor(DUNKEL)
    canv.rect(0, h - 9.5 * cm, w, 9.5 * cm, stroke=0, fill=1)
    canv.setFillColor(AKZENT)
    canv.rect(0, h - 9.5 * cm, w, 0.35 * cm, stroke=0, fill=1)
    canv.drawImage(os.path.join(L.ROOT, 'frontend', 'public', 'icon-512.png'), 2.5 * cm, h - 7.2 * cm, 3.4 * cm, 3.4 * cm, mask='auto')
    canv.setFillColor(AKZENT)
    canv.setFont('Text-BI', 40)
    canv.drawString(6.6 * cm, h - 5.1 * cm, 'TN')
    tw = pdfmetrics.stringWidth('TN ', 'Text-BI', 40)
    canv.setFillColor(colors.white)
    canv.setFont('Text-B', 40)
    canv.drawString(6.6 * cm + tw, h - 5.1 * cm, 'Dashboard')
    canv.setFont('Text', 15)
    canv.drawString(6.6 * cm, h - 6.3 * cm, 'Logbuch der Entwicklung')
    canv.drawImage(os.path.join(L.ROOT, 'docs', 'images', 'klemmi-zeigt.png'), w - 6.4 * cm, 3.2 * cm, 3.8 * cm, 5.6 * cm,
                   mask='auto', preserveAspectRatio=True, anchor='sw')
    canv.restoreState()


def ueberschrift(text, kap, unterzeile=None):
    h = Paragraph(text, S['h1'])
    h.toc = (text, kap)
    teile = [h]
    if unterzeile:
        teile.append(Paragraph(unterzeile, S['h1sub']))
    return teile


# ---------- Titelseite ----------
erster, letzter = TAGE[0], TAGE[-1]
alle_commits = sum(t['commits'] for t in TAGE)
info = [('Dokument', 'Logbuch der Entwicklung'),
        ('Zeitraum', f'{erster["kurz_datum"]} bis {letzter["kurz_datum"]}'),
        ('Arbeitstage', str(len(TAGE))),
        ('Commits', str(alle_commits)),
        ('Quelle', 'Versionsgeschichte (git) des Repositorys „TN-Dashboard“ und docs/logbuch.json'),
        ('Stand', STAND)]
it = Table([[Paragraph(f'<b>{a}</b>', S['cell']), Paragraph(b, S['cell'])] for a, b in info], colWidths=[3.4 * cm, 10.6 * cm])
it.setStyle(TableStyle([('LINEBELOW', (0, 0), (-1, -1), 0.5, LINIE), ('LINEABOVE', (0, 0), (-1, 0), 1.2, DUNKEL),
                        ('TOPPADDING', (0, 0), (-1, -1), 5), ('BOTTOMPADDING', (0, 0), (-1, -1), 5)]))
story += [Spacer(1, 9.3 * cm), it, NextPageTemplate('inhalt'), PageBreak()]

# ---------- Vorbemerkung und Inhalt ----------
story.append(Paragraph('Vorbemerkung', S['h1']))
story.append(Paragraph(
    'Dieses Logbuch dokumentiert die Entwicklung des TN Dashboards Tag für Tag. Jeder Arbeitstag hat eine eigene Seite: '
    'oben die Kennzahlen des Tages, darunter eine Zusammenfassung, die umgesetzten Funktionen nach Themen und am Ende die '
    'Tabelle aller Commits (gespeicherten Arbeitsschritte) mit Uhrzeit und Kurzbeschreibung. Die Nummer in der Tabelle ist die '
    'Kennung des Commits; unter dieser Nummer lässt sich jeder Schritt in der Versionsgeschichte des Repositorys nachschlagen.', S['body']))
story.append(Paragraph(
    'Alle Zeiten sind deutsche Ortszeit. Ein Arbeitstag endet um 4 Uhr nachts, damit Arbeit nach Mitternacht noch zum selben '
    'Tag zählt. „Geänderte Dateien“ zählt jede Datei einmal pro Tag; bei den Zeilen sind erzeugte Dateien (Demo- und '
    'Tutorial-Datei, Bilder, PDFs, Beispieldaten) nicht mitgerechnet. Die Kennzahlen entstehen automatisch aus der '
    'Versionsgeschichte, die Texte sind von Hand geschrieben.', S['body']))
story.append(Spacer(1, 8))
story.append(Paragraph('Inhaltsverzeichnis', S['h1']))
toc = TableOfContents()
toc.levelStyles = [S['toc1']]
story.append(toc)

# ---------- Übersicht ----------
story.append(PageBreak())
story += ueberschrift('Übersicht', 'Übersicht', 'Was wann umgesetzt wurde')
story.append(tabelle(['Datum', 'Commits', 'Umgesetzt'],
                     [[f'<b>{t["kurz_datum"]}</b><br/>{t["titel_datum"].split(",")[0]}', str(t['commits']), t['text']['kurz']] for t in TAGE]
                     + [['<b>Gesamt</b>', f'<b>{alle_commits}</b>', f'{len(TAGE)} Arbeitstage']],
                     [2.6, 1.7, 11.7]))

# ---------- Je Tag ----------
for t in TAGE:
    story.append(PageBreak())
    story += ueberschrift(t['titel_datum'], t['kurz_datum'], f'Arbeitstag {TAGE.index(t) + 1} von {len(TAGE)}')
    story.append(kennzahlen(t))
    story.append(Spacer(1, 6))
    bereiche = ', '.join(f'{name} {n}' for name, n in t['bereiche'])
    story.append(Paragraph(f'<font color="#5b6475">Geänderte Dateien nach Bereich: {bereiche}</font>',
                           ParagraphStyle('b', parent=S['cellk'], spaceAfter=8)))
    story.append(Paragraph('Zusammenfassung', S['h2']))
    story.append(Paragraph(t['text']['zusammenfassung'], S['body']))
    for th in t['text']['themen']:
        story.append(KeepTogether([Paragraph(th['titel'], S['h2']), Paragraph(th['text'], S['body'])]))
    story.append(Paragraph('Commits des Tages', S['h2']))
    zeilen = [[e['zeit'], Paragraph(e['hash'], S['mono']), e['text'] + (' <font color="#5b6475">(Zusammenführung)</font>' if e['merge'] else '')]
              for e in t['eintraege']]
    story.append(tabelle(['Zeit', 'Nr.', 'Beschreibung'], zeilen, [1.4, 1.9, 12.7], kleine=True))

# ---------- Statistik ----------
story.append(PageBreak())
story += ueberschrift('Statistik', 'Statistik', 'Alle Arbeitstage im Vergleich')
mx = max(t['commits'] for t in TAGE)
mz = max(t['plus'] + t['minus'] for t in TAGE) or 1
story.append(tabelle(['Datum', 'Commits', '', 'Dateien', 'Zeilen + / −', ''],
                     [[t['kurz_datum'], str(t['commits']), Balken(t['commits'] / mx, 3.2 * cm), zahl(t['dateien']),
                       f'+{zahl(t["plus"])} / −{zahl(t["minus"])}', Balken((t['plus'] + t['minus']) / mz, 3.2 * cm, AKZENT)] for t in TAGE]
                     + [['<b>Gesamt</b>', f'<b>{alle_commits}</b>', '', '',
                         f'<b>+{zahl(sum(t["plus"] for t in TAGE))} / −{zahl(sum(t["minus"] for t in TAGE))}</b>', '']],
                     [2.3, 1.6, 3.6, 1.6, 3.3, 3.6]))
story.append(Spacer(1, 14))
summe = {}
for t in TAGE:
    for name, n in t['bereiche']:
        summe[name] = summe.get(name, 0) + n
mb = max(summe.values())
story.append(Paragraph('Änderungen nach Bereich', S['h2']))
story.append(Paragraph('Summe der je Tag geänderten Dateien, nach Bereich des Repositorys.', S['body']))
story.append(tabelle(['Bereich', 'Dateiänderungen', ''],
                     [[name, str(n), Balken(n / mb, 7 * cm)] for name, n in sorted(summe.items(), key=lambda x: -x[1])],
                     [5, 3.2, 7.8]))


def bauen():
    doc = Doc(AUSGABE)
    doc.multiBuild(copy.deepcopy(story))
    return doc.page


GESAMT['n'] = bauen()
bauen()
print(f'{AUSGABE}: {GESAMT["n"]} Seiten, {len(TAGE)} Arbeitstage, {alle_commits} Commits')
