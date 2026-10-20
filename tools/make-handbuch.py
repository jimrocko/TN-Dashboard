#!/usr/bin/env python3
"""Erzeugt das Handbuch als PDF (docs/Handbuch.pdf).

Aufruf:  python3 tools/make-handbuch.py <bilder-ordner> [ausgabe.pdf]

Die Bilder (Bildschirmfotos mit den erfundenen Beispieldaten) erzeugt
tools/handbuch-bilder.cjs gegen den lokalen PHP-Server. Braucht reportlab und Pillow.
"""
import copy
import io
import os
import sys
from datetime import date

from PIL import Image as PILImage
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import cm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (BaseDocTemplate, CondPageBreak, Frame, Image, KeepTogether, NextPageTemplate,
                                PageBreak, PageTemplate, Paragraph, Spacer, Table, TableStyle)
from reportlab.platypus.tableofcontents import TableOfContents

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BILDER = sys.argv[1] if len(sys.argv) > 1 else '/tmp/handbuch-bilder'
AUSGABE = sys.argv[2] if len(sys.argv) > 2 else os.path.join(ROOT, 'docs', 'Handbuch.pdf')
MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']
STAND = f'{MONATE[date.today().month - 1]} {date.today().year}'

# ---------- Schriften und Farben ----------
F = '/usr/share/fonts/truetype/'
pdfmetrics.registerFont(TTFont('Text', F + 'crosextra/Carlito-Regular.ttf'))
pdfmetrics.registerFont(TTFont('Text-B', F + 'crosextra/Carlito-Bold.ttf'))
pdfmetrics.registerFont(TTFont('Text-I', F + 'crosextra/Carlito-Italic.ttf'))
pdfmetrics.registerFont(TTFont('Text-BI', F + 'crosextra/Carlito-BoldItalic.ttf'))
pdfmetrics.registerFont(TTFont('Mono', F + 'dejavu/DejaVuSansMono.ttf'))
pdfmetrics.registerFontFamily('Text', normal='Text', bold='Text-B', italic='Text-I', boldItalic='Text-BI')

DUNKEL = colors.HexColor('#1f2a44')
AKZENT = colors.HexColor('#f2c200')   # Lego-Gelb wie in der App
GRAU = colors.HexColor('#5b6475')
LINIE = colors.HexColor('#c9cfda')
HELL = colors.HexColor('#eef1f6')

S = {}
S['body'] = ParagraphStyle('body', fontName='Text', fontSize=10.5, leading=14.2, alignment=TA_JUSTIFY, spaceAfter=6)
S['h1'] = ParagraphStyle('h1', fontName='Text-B', fontSize=19, leading=23, textColor=DUNKEL, spaceBefore=4, spaceAfter=12)
S['h2'] = ParagraphStyle('h2', fontName='Text-B', fontSize=13.5, leading=17, textColor=DUNKEL, spaceBefore=12, spaceAfter=5)
S['h3'] = ParagraphStyle('h3', fontName='Text-B', fontSize=11, leading=14, textColor=DUNKEL, spaceBefore=8, spaceAfter=3)
S['li'] = ParagraphStyle('li', parent=S['body'], leftIndent=14, bulletIndent=3, spaceAfter=2.5)
S['step'] = ParagraphStyle('step', parent=S['body'], leftIndent=18, bulletIndent=0, spaceAfter=2.5)
S['cap'] = ParagraphStyle('cap', fontName='Text-I', fontSize=9, leading=11.5, textColor=GRAU, alignment=TA_CENTER, spaceBefore=3, spaceAfter=10)
S['cell'] = ParagraphStyle('cell', fontName='Text', fontSize=9.3, leading=11.8, alignment=TA_LEFT)
S['cellb'] = ParagraphStyle('cellb', parent=S['cell'], fontName='Text-B', textColor=colors.white)
S['note'] = ParagraphStyle('note', parent=S['body'], fontSize=10, leading=13.5, alignment=TA_LEFT, spaceAfter=0)
S['code'] = ParagraphStyle('code', fontName='Mono', fontSize=8.3, leading=11, textColor=DUNKEL, alignment=TA_LEFT)
S['toc1'] = ParagraphStyle('toc1', fontName='Text-B', fontSize=10.8, leading=14, spaceBefore=4)
S['toc2'] = ParagraphStyle('toc2', fontName='Text', fontSize=10, leading=12.6, leftIndent=18)
S['lof'] = ParagraphStyle('lof', fontName='Text', fontSize=9.8, leading=12.8, leftIndent=0)

# ---------- Bausteine ----------
story = []
nr = {'k': 0, 'u': 0, 'abb': 0, 'tab': 0}


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def c(s):
    """Code im Fließtext."""
    return f'<font name="Mono" size="8.8">{esc(s)}</font>'


def p(text):
    story.append(Paragraph(text, S['body']))


def kapitel(titel):
    nr['k'] += 1
    nr['u'] = 0
    if nr['k'] > 1:
        story.append(PageBreak())
    h = Paragraph(f'{nr["k"]}&nbsp;&nbsp;{titel}', S['h1'])
    h.toc = (0, f'{nr["k"]}  {titel}', f'{nr["k"]}  {titel}')
    story.append(h)


def abschnitt(titel):
    nr['u'] += 1
    story.append(CondPageBreak(4 * cm))
    t = f'{nr["k"]}.{nr["u"]}&nbsp;&nbsp;{titel}'
    h = Paragraph(t, S['h2'])
    h.toc = (1, f'{nr["k"]}.{nr["u"]}  {titel}', None)
    story.append(h)


def unter(titel):
    story.append(CondPageBreak(3 * cm))
    story.append(Paragraph(titel, S['h3']))


def liste(punkte):
    for x in punkte:
        story.append(Paragraph(x, S['li'], bulletText='•'))
    story.append(Spacer(1, 4))


def schritte(punkte):
    for i, x in enumerate(punkte, 1):
        story.append(Paragraph(x, S['step'], bulletText=f'{i}.'))
    story.append(Spacer(1, 4))


def hinweis(text, titel='Hinweis'):
    t = Table([[Paragraph(f'<b>{titel}:</b> {text}', S['note'])]], colWidths=[16 * cm])
    t.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), HELL), ('LINEBEFORE', (0, 0), (0, -1), 3, AKZENT),
        ('LEFTPADDING', (0, 0), (-1, -1), 10), ('RIGHTPADDING', (0, 0), (-1, -1), 10),
        ('TOPPADDING', (0, 0), (-1, -1), 7), ('BOTTOMPADDING', (0, 0), (-1, -1), 7)]))
    story.append(Spacer(1, 3))
    story.append(t)
    story.append(Spacer(1, 9))


def tabelle(kopf, zeilen, breiten, titel=None):
    data = [[Paragraph(x, S['cellb']) for x in kopf]] + [[Paragraph(x, S['cell']) for x in z] for z in zeilen]
    t = Table(data, colWidths=[b * cm for b in breiten], repeatRows=1)
    st = [('BACKGROUND', (0, 0), (-1, 0), DUNKEL), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
          ('GRID', (0, 0), (-1, -1), 0.5, LINIE), ('TOPPADDING', (0, 0), (-1, -1), 4),
          ('BOTTOMPADDING', (0, 0), (-1, -1), 4), ('LEFTPADDING', (0, 0), (-1, -1), 5), ('RIGHTPADDING', (0, 0), (-1, -1), 5)]
    for i in range(2, len(data), 2):
        st.append(('BACKGROUND', (0, i), (-1, i), HELL))
    t.setStyle(TableStyle(st))
    teile = [Spacer(1, 2)]
    if titel:
        nr['tab'] += 1
        teile.append(Paragraph(f'Tabelle {nr["tab"]}: {titel}', ParagraphStyle('tc', parent=S['cap'], spaceBefore=0, spaceAfter=4)))
    teile.append(t)
    story.append(KeepTogether(teile) if len(zeilen) < 14 else teile[-1])
    story.append(Spacer(1, 10))


def bild_datei(name, max_px=1500):
    """PNG als JPEG verkleinern (kleinere PDF-Datei)."""
    im = PILImage.open(os.path.join(BILDER, name + '.png')).convert('RGB')
    if im.width > max_px:
        im = im.resize((max_px, round(im.height * max_px / im.width)), PILImage.LANCZOS)
    buf = io.BytesIO()
    im.save(buf, 'JPEG', quality=82, optimize=True)
    buf.seek(0)
    return buf, im.width, im.height


class Abb(Paragraph):
    """Bildunterschrift, die sich ins Abbildungsverzeichnis einträgt."""


def _caption(text):
    nr['abb'] += 1
    cap = Abb(f'Abbildung {nr["abb"]}: {text}', S['cap'])
    cap.lof = f'Abbildung {nr["abb"]}: {text}'
    return cap


def abb(name, text, breite=14):
    buf, w, h = bild_datei(name)
    img = Image(buf, width=breite * cm, height=breite * cm * h / w)
    rahmen = Table([[img]], colWidths=[breite * cm + 2])
    rahmen.setStyle(TableStyle([('BOX', (0, 0), (-1, -1), 0.6, LINIE), ('LEFTPADDING', (0, 0), (-1, -1), 1),
                                ('RIGHTPADDING', (0, 0), (-1, -1), 1), ('TOPPADDING', (0, 0), (-1, -1), 1), ('BOTTOMPADDING', (0, 0), (-1, -1), 1)]))
    story.append(KeepTogether([Spacer(1, 4), rahmen, _caption(text)]))


def abb_paar(a, b, text, hoehe=11):
    zellen = []
    for name in (a, b):
        buf, w, h = bild_datei(name, 700)
        zellen.append(Image(buf, width=hoehe * cm * w / h, height=hoehe * cm))
    t = Table([zellen], colWidths=[7.5 * cm, 7.5 * cm])
    t.setStyle(TableStyle([('ALIGN', (0, 0), (-1, -1), 'CENTER')]))
    story.append(KeepTogether([Spacer(1, 4), t, _caption(text)]))


class LOF(TableOfContents):
    """Abbildungsverzeichnis: hört auf eigene Einträge statt auf Überschriften."""

    def notify(self, kind, stuff):
        if kind == 'LOFEntry':
            self.addEntry(*stuff)


class Doc(BaseDocTemplate):
    def __init__(self, fn, **kw):
        super().__init__(fn, pagesize=A4, leftMargin=2.5 * cm, rightMargin=2.5 * cm, topMargin=2.6 * cm, bottomMargin=2.3 * cm,
                         title='TN Dashboard – Handbuch', subject='Handbuch zur Bedienung und Technik', author='TN Dashboard',
                         creator='tools/make-handbuch.py', **kw)
        frame = Frame(self.leftMargin, self.bottomMargin, self.width, self.height, id='f')
        self.addPageTemplates([PageTemplate('titel', [frame], onPage=titelseite),
                               PageTemplate('inhalt', [frame], onPageEnd=kopf_fuss)])
        self.kap = ''

    def beforeDocument(self):
        self.kap = 'Vorbemerkung'

    def afterFlowable(self, f):
        toc = getattr(f, 'toc', None)
        if toc:
            stufe, text, kap = toc
            key = f'h{id(f)}'
            self.canv.bookmarkPage(key)
            self.canv.addOutlineEntry(text, key, level=stufe, closed=stufe > 0)
            self.notify('TOCEntry', (stufe, text, self.page, key))
            if kap:
                self.kap = kap
        if getattr(f, 'kap_name', None):
            self.kap = f.kap_name
        lof = getattr(f, 'lof', None)
        if lof:
            key = f'a{id(f)}'
            self.canv.bookmarkPage(key)
            self.notify('LOFEntry', (0, lof, self.page, key))


GESAMT = {'n': 0}


def kopf_fuss(canv, doc):
    canv.saveState()
    w, h = A4
    canv.setStrokeColor(AKZENT)
    canv.setLineWidth(1.6)
    canv.line(2.5 * cm, h - 1.75 * cm, w - 2.5 * cm, h - 1.75 * cm)
    canv.setFont('Text-B', 9)
    canv.setFillColor(DUNKEL)
    canv.drawString(2.5 * cm, h - 1.5 * cm, 'TN Dashboard · Handbuch')
    canv.setFont('Text', 9)
    canv.setFillColor(GRAU)
    canv.drawRightString(w - 2.5 * cm, h - 1.5 * cm, doc.kap)
    canv.setStrokeColor(LINIE)
    canv.setLineWidth(0.5)
    canv.line(2.5 * cm, 1.6 * cm, w - 2.5 * cm, 1.6 * cm)
    canv.drawString(2.5 * cm, 1.15 * cm, f'Stand: {STAND}')
    seite = f'Seite {doc.page} von {GESAMT["n"]}' if GESAMT['n'] else f'Seite {doc.page}'
    canv.drawRightString(w - 2.5 * cm, 1.15 * cm, seite)
    canv.restoreState()


def titelseite(canv, doc):
    canv.saveState()
    w, h = A4
    canv.setFillColor(DUNKEL)
    canv.rect(0, h - 9.5 * cm, w, 9.5 * cm, stroke=0, fill=1)
    canv.setFillColor(AKZENT)
    canv.rect(0, h - 9.5 * cm, w, 0.35 * cm, stroke=0, fill=1)
    canv.drawImage(os.path.join(ROOT, 'frontend', 'public', 'icon-512.png'), 2.5 * cm, h - 7.2 * cm, 3.4 * cm, 3.4 * cm, mask='auto')
    canv.setFillColor(AKZENT)
    canv.setFont('Text-BI', 40)
    canv.drawString(6.6 * cm, h - 5.1 * cm, 'TN')
    tw = pdfmetrics.stringWidth('TN ', 'Text-BI', 40)
    canv.setFillColor(colors.white)
    canv.setFont('Text-B', 40)
    canv.drawString(6.6 * cm + tw, h - 5.1 * cm, 'Dashboard')
    canv.setFont('Text', 15)
    canv.drawString(6.6 * cm, h - 6.3 * cm, 'Handbuch zur Bedienung und Technik')
    canv.drawImage(os.path.join(ROOT, 'docs', 'images', 'klemmi.png'), w - 6.2 * cm, 3.2 * cm, 3.6 * cm, 5.6 * cm,
                   mask='auto', preserveAspectRatio=True, anchor='sw')
    canv.restoreState()


def info_tabelle():
    zeilen = [
        ('Dokument', 'Handbuch zur Bedienung und Technik'),
        ('Anwendung', 'TN Dashboard: Web-App für den Lego-Shop (Lager, Umlagern, Inventur, Pickliste, Minifig-Finder, Analyse)'),
        ('Zielgruppe', 'Lehrkräfte, Teilnehmerinnen und Teilnehmer, technisch Verantwortliche'),
        ('Stand', STAND),
        ('Abbildungen', 'Bildschirmfotos der Anwendung mit erfundenen Beispieldaten (iPad-Format)'),
        ('Quelle', 'Repository „TN-Dashboard“, Ordner docs/ und tools/'),
    ]
    t = Table([[Paragraph(f'<b>{a}</b>', S['cell']), Paragraph(b, S['cell'])] for a, b in zeilen], colWidths=[3.4 * cm, 10.6 * cm])
    t.setStyle(TableStyle([('LINEBELOW', (0, 0), (-1, -1), 0.5, LINIE), ('LINEABOVE', (0, 0), (-1, 0), 1.2, DUNKEL),
                           ('VALIGN', (0, 0), (-1, -1), 'TOP'), ('TOPPADDING', (0, 0), (-1, -1), 5), ('BOTTOMPADDING', (0, 0), (-1, -1), 5)]))
    return t


# =====================================================================
# Inhalt
# =====================================================================
story.append(Spacer(1, 9.3 * cm))
story.append(info_tabelle())
story.append(NextPageTemplate('inhalt'))
story.append(PageBreak())

# ---------- Vorbemerkung und Verzeichnis ----------
story.append(Paragraph('Vorbemerkung', S['h1']))
p('Dieses Handbuch beschreibt das <b>TN Dashboard</b> vollständig: die Bedienung aller Bereiche für die tägliche Arbeit im '
  'Lego-Shop, die Aufgaben der Lehrkraft und den technischen Aufbau der Anwendung bis hin zu Installation, Konfiguration und '
  'Datenbank. Die Kapitel 1 bis 6 richten sich an alle, die mit der App arbeiten; Kapitel 7 an die Personen, die sie betreiben '
  'oder weiterentwickeln. Der Anhang enthält ein Glossar, Antworten auf häufige Fragen und das Abbildungsverzeichnis.')
p('Alle Abbildungen zeigen die Anwendung mit <b>erfundenen Beispieldaten</b> (rund 125.000 Teile in über 1.400 Kästchen, '
  'erfundene Aufträge und Namen wie „Max Mustermann“). Echte Kunden-, Auftrags- oder Bestandsdaten enthält dieses Dokument nicht. '
  'Die Bildschirmfotos entstehen im iPad-Format, dem Hauptgerät im Shop; auf PC und Handy sieht die App bis auf die Anordnung gleich aus.')
liste([
    '<b>Fett</b> gesetzte Begriffe bezeichnen Knöpfe, Reiter oder Bereiche, so wie sie in der App heißen.',
    f'Dateinamen, Einstellungen und technische Werte stehen in Festbreitenschrift, zum Beispiel {c("api/config.php")}.',
    'Kästen mit gelbem Rand enthalten Hinweise, die man bei der Arbeit beachten sollte.',
])
story.append(PageBreak())
ih = Paragraph('Inhaltsverzeichnis', S['h1'])
ih.kap_name = 'Inhaltsverzeichnis'
story.append(ih)
toc = TableOfContents()
toc.levelStyles = [S['toc1'], S['toc2']]
toc.dotsMinLevel = 0
story.append(toc)

# =====================================================================
kapitel('Einleitung')
abschnitt('Zweck der Anwendung')
p('Im Lego-Shop werden gebrauchte und neue Lego-Teile, Minifiguren und Sets über die Verkaufsplattformen BrickLink und BrickOwl '
  'verkauft. Der Bestand liegt in einem Lagerzimmer mit weit über tausend kleinen Kästchen. Für die Arbeit wurden bisher viele '
  'einzelne Werkzeuge genutzt: BrickLink im Browser, BrickStore-Dateien, Listen und ein separates Shop-Dashboard.')
p('Das TN Dashboard bündelt diese Arbeit in <b>einer</b> Anwendung, die im Browser auf iPad, PC und Handy läuft und sich wie eine App '
  'auf den Home-Bildschirm legen lässt. Es unterstützt bei folgenden Aufgaben:')
liste([
    '<b>Inventar:</b> Wo liegt welches Teil, wie viel ist vorhanden, was ist es wert?',
    '<b>Umlagern:</b> Das Lager ordnen. Die App findet selbst, was nicht dort liegt, wo es hingehört.',
    '<b>Inventur:</b> Kästchen nachzählen und den Bestand korrigieren, mit einer täglichen Zählliste.',
    '<b>Pickliste:</b> Bestellungen gemeinsam zusammensuchen, nach Laufweg sortiert.',
    '<b>Minifig-Finder:</b> Minifiguren finden, die sich aus losen Teilen im Lager bauen lassen.',
    '<b>Analyse:</b> Kennzahlen zu Lager, Aufträgen und Mitarbeit.',
])
p('Jede Aktion wird pro Person <b>protokolliert</b>. Die Lehrkraft kann so jederzeit nachvollziehen, wer was gemacht hat, und das '
  'Protokoll als CSV-Datei exportieren.')

abschnitt('Benutzergruppen und Rollen')
p('Es gibt drei Rollen. Die Rolle wird beim Anlegen des Kontos festgelegt; die Dev-Rolle vergibt die Lehrkraft zusätzlich an einzelne '
  'Teilnehmerinnen und Teilnehmer.')
tabelle(['Recht', 'Teilnehmer', 'Teilnehmer mit Dev-Rolle', 'Lehrkraft'], [
    ['Alle Shop-Bereiche (Inventar, Umlagern, Inventur, Pickliste, Minifig-Finder, Analyse, Suche, Scanner)', 'ja', 'ja', 'ja'],
    ['Eigenes Protokoll und Abzeichen („Meine Aktivitäten“)', 'ja', 'ja', 'ja'],
    ['Dev-Bereich (Systemdaten, Selbsttests, Tabellen lesen, Einstellungen)', '–', 'ja', 'ja'],
    ['Pick-Statistik', '–', 'ja', 'ja'],
    ['Lehrer-Ansicht: Konten anlegen und löschen, Protokoll aller, Dev-Rolle vergeben, Bestand übernehmen', '–', '–', 'ja'],
], [8.2, 2.2, 3.2, 2.4], 'Rollen und Rechte')

abschnitt('Geräte und Voraussetzungen')
liste([
    '<b>iPad</b> (Hauptgerät, quer und hochkant), <b>PC</b> und <b>Handy</b> mit einem aktuellen Browser (Safari, Chrome, Edge, Firefox).',
    'Eine Verbindung zum Server über WLAN. Pickliste und Inventur arbeiten auch bei kurzen Ausfällen weiter (Abschnitt 5.6).',
    'Für den Teile-Scanner eine Kamera und eine HTTPS-Verbindung zum Server.',
    'Ein eigenes Konto mit Benutzername und Passwort, angelegt durch die Lehrkraft.',
])

abschnitt('Grundprinzipien')
liste([
    '<b>Alles ist anklickbar.</b> Jeder Lagerort öffnet seine Lagerort-Seite, jede Teil-Nummer die Teile-Seite, jeder Balken in der '
    'Analyse die passende Liste.',
    '<b>Daten aus der eigenen Datenbank.</b> Die App rechnet immer mit den Daten auf dem Server. Externe Dienste wie BrickLink '
    'werden nur auf Knopfdruck abgefragt; die Antworten werden gespeichert. Das spart Kosten und schont die Tageslimits.',
    '<b>Die App schreibt nie selbst zu BrickLink.</b> Änderungen am Bestand werden als Datei bereitgestellt und von einer Person im '
    'BrickLink-Shop eingefügt (Kapitel 5).',
    '<b>Gemeinsames Arbeiten.</b> Mehrere Personen können gleichzeitig picken, umlagern und zählen. Die App verhindert, dass sich '
    'Buchungen gegenseitig überschreiben.',
    '<b>Begriff „Lot“.</b> Ein Lot ist ein Posten im Bestand: ein bestimmtes Teil in einer Farbe und einem Zustand an einem Lagerort, '
    'mit Menge und Preis (siehe Glossar).',
])

# =====================================================================
kapitel('Erste Schritte')
abschnitt('Anmeldung')
p('Beim Öffnen der Adresse erscheint die Anmeldung. Benutzername und Passwort erhält man von der Lehrkraft. Nach der Anmeldung öffnet '
  'sich die Startseite. Abmelden kann man sich oben rechts über das Menü am eigenen Namen.')
p('Beim ersten Öffnen eines Tages zeigt die App eine kurze <b>Startanimation</b>: Der Logo-Stein setzt sich zusammen, darunter läuft ein '
  'Ladebalken aus Noppen. Ein Tipp oder eine Taste überspringt sie. Sie lässt sich pro Gerät im Dev-Bereich abschalten.')
abb('login', 'Anmeldung', 12)

abschnitt('Startseite')
p('Oben in der Kopfzeile stehen links der Logo-Stein, in der Mitte der Titel „TN Dashboard“ mit einem Gruß nach Tageszeit und rechts '
  'das Profil mit dem Verbindungsstatus („Online“ bzw. „Offline“). Darunter folgen die Suche und sechs große Kacheln mit Live-Zahlen:')
tabelle(['Kachel', 'Wofür', 'Live-Zahl'], [
    ['Minifig-Finder', 'Minifiguren finden, die sich aus losen Teilen bauen lassen', 'baubar / 1 Teil fehlt'],
    ['Pickliste', 'Bestellungen zusammensuchen, Fehlmengen melden', 'offene Bestellungen'],
    ['Inventar', 'Bestand, Lagerorte und Lagerplan', 'Gesamtbestand in Teilen'],
    ['Umlagern', 'Vorschläge zum Ordnen des Lagers', 'offene Vorschläge'],
    ['Inventur &amp; Bestandsprüfung', 'Kästchen zählen und korrigieren', 'offene Prüfungen'],
    ['Lager- &amp; Auftragsanalyse', 'Kennzahlen und Bestenliste', 'Datum der letzten Analyse'],
], [4.2, 7.6, 4.2], 'Kacheln der Startseite')
p('Unten in der Leiste stehen <b>Home</b>, <b>Einstellungen</b> und (mit Dev-Rolle) der <b>Dev-Bereich</b>. Auf PC und iPad stehen die '
  'Kacheln in zwei Reihen zu je drei, auf dem Handy zu zweit.')
abb('start', 'Startseite auf dem iPad')
abb_paar('handy-start', 'handy-pickliste', 'Startseite und Pickliste auf dem Handy', 12)

abschnitt('Suche')
p('Die Suche oben auf der Startseite durchsucht alles auf einmal: Teil-Nummern, Namen, Farben, Kategorien, Lagerorte und Aufträge. '
  'Die Ergebnisse sind nach Art gegliedert; jeder Treffer führt zur passenden Seite. Rechts im Suchfeld öffnet das '
  '<b>Scan-Symbol</b> den Teile-Scanner (Abschnitt 3.4).')
abb('suche', 'Suchergebnis für die Teil-Nummer 3001')

abschnitt('Hilfe und Tutorial')
p('Oben rechts in jedem Bereich (und unten auf der Startseite) sitzt der Knopf <b>Hilfe</b>. Er bietet zwei Möglichkeiten:')
liste([
    '<b>Diesen Bereich erklären:</b> Das Maskottchen Klemmi, eine rote Lego-Figur, erklärt in wenigen Schritten den aktuellen Bereich. '
    'Die wichtigen Stellen werden hervorgehoben. Dabei wird nichts gebucht oder verändert; die Hilfe ist daher auch mit echten Daten '
    'gefahrlos. „Fertig“, das X oder die Esc-Taste schließen sie.',
    '<b>Rundführung durch die ganze App:</b> öffnet das Tutorial in einem neuen Tab. Es arbeitet mit eigenen Übungsdaten und fragt zu '
    'Beginn „Wer bist du?“: Das <b>Schüler-Tutorial</b> öffnet jeden Bereich mindestens einmal, das <b>Lehrer-Tutorial</b> erklärt '
    'ausführlich Lehrer-Ansicht, Konten, Dev-Rolle und Dev-Bereich. Jeder Schritt wird selbst getippt.',
])
abb('hilfe', 'Kurzhilfe im Inventar: Klemmi erklärt den Bereich')

abschnitt('Installation als App')
p('Das TN Dashboard ist eine installierbare Web-App (PWA). Es braucht keinen App Store und verursacht keine Kosten. Es läuft weiterhin '
  'auf dem Server, öffnet sich aber im Vollbild mit eigenem Symbol (dem Logo-Stein).')
tabelle(['Gerät', 'Vorgehen'], [
    ['iPad / iPhone (Safari)', 'Adresse öffnen → Teilen-Symbol (Viereck mit Pfeil) → <b>„Zum Home-Bildschirm“</b> → „Hinzufügen“'],
    ['Android (Chrome)', 'Adresse öffnen → Menü (drei Punkte) → <b>„App installieren“</b>'],
    ['PC (Chrome / Edge)', 'Adresse öffnen → Symbol <b>„Installieren“</b> rechts in der Adressleiste'],
], [4.2, 11.8], 'App auf dem Gerät installieren')
p('Updates kommen automatisch beim nächsten Öffnen. Voraussetzung ist eine HTTPS-Verbindung zum Server. Die Anleitung steht auch in der '
  'App unter Einstellungen → Hilfe.')

abschnitt('Meine Aktivitäten und Abzeichen')
p('Ein Tipp auf den eigenen Namen oben rechts öffnet <b>Meine Aktivitäten</b>: das eigene Protokoll (es ist dasselbe, das die Lehrkraft '
  'sieht) mit CSV-Export und darüber die <b>Abzeichen</b>. Abzeichen gibt es zum Beispiel für den ersten Pick, 100 umgelagerte Lots '
  '(„Lagermeister“), zehn abgeschlossene Inventuren („Zählkünstler“) oder Arbeit vor 8 Uhr („Frühaufsteher“). Offene Abzeichen zeigen '
  'den Fortschritt.')
abb('profil', 'Meine Aktivitäten mit Abzeichen')

abschnitt('Einstellungen')
p('Unter <b>Einstellungen</b> stellt jede Person für ihr Gerät Hell- oder Dunkel-Design, Dichte, Schriftgröße und Animationen ein. '
  'Dort stehen außerdem die Hilfe mit dem Tutorial-Knopf, die Installationsanleitung, die Kontodaten und der Verbindungsstatus.')
abb('einstellungen', 'Einstellungen (unterer Teil mit Hilfe, Konto und Verbindungen)')

# =====================================================================
kapitel('Das Lager')
abschnitt('Aufbau des Lagerzimmers und Kästchen-Codes')
p('Das Lager ist ein Raum mit Regalen voller kleiner Kästchen. Jeder Lagerort ist <b>ein Kästchen</b>. Sein Code besteht aus dem Bereich '
  'und einer Nummer ohne Bindestrich, zum Beispiel <b>G245</b>, <b>N005</b>, <b>FT3</b> oder <b>Plate2</b>. In den Bereichen G, H und N '
  'ist die Nummer immer dreistellig mit führenden Nullen.')
tabelle(['Bereich', 'Bedeutung', 'Lage im Raum', 'Beispiel'], [
    ['G', 'Gebrauchte Teile', 'linke Wand, G100 bis G999', 'G245'],
    ['H', 'Weiterer Regalbereich', 'rechte Wand oben, H000 bis H999', 'H012'],
    ['N', 'Neue Teile', 'rechte Wand unten, N000 bis N999', 'N005'],
    ['FG', 'Figuren gebraucht', 'unten links', 'FG7'],
    ['FN', 'Figuren neu (direkt gekaufte Figuren)', 'unten links', 'FN3'],
    ['FT', 'Figurenteile', 'unten links', 'FT12'],
    ['Plate', 'Platten', 'unten links', 'Plate2'],
], [2, 5.4, 5.6, 3], 'Bereiche und Kästchen-Codes')
p('Codes werden immer <b>natürlich sortiert</b>: N4 steht vor N150. Ein G-Kästchen mit <b>mehr als drei verschiedenen Lots</b> heißt '
  '<b>Mischkiste</b>; es ist noch nicht aufgeteilt und wird in Umlagern und auf der Lagerort-Seite rot markiert. Der Lagerort eines '
  'Lots steht bei BrickLink in den <b>Remarks</b> (Bemerkungsfeld).')

abschnitt('Inventar')
p('Das Inventar zeigt den gesamten Bestand. Oben stehen die Summen (Kategorien, Lots, Teile, Wert). Standard ist die Ansicht nach '
  '<b>Kategorien</b> (Brick, Plate, Tile, Technic …): Jede Kategorie zeigt Lots, Teile und Wert; ein Tipp klappt sie auf. Mit dem '
  'Umschalter <b>Liste</b> sieht man alle Lots in einer Tabelle. Sobald man filtert (Nummer, Name, Kategorie, Lagerort) erscheint '
  'automatisch die Liste. Ein Tipp auf eine Zeile öffnet die Teile-Seite.')
abb('inventar', 'Inventar nach Kategorien')
p('Der Knopf <b>Lagerplan</b> klappt das Lagerzimmer von oben auf: links G, rechts H und N, unten links die Figuren- und Plattenbereiche. '
  'Ein Tipp auf einen Bereich zeigt die Hunderter-Blöcke, dann die einzelnen Kästchen; ein Tipp auf ein Kästchen öffnet seinen Inhalt. '
  'Graue Kästchen sind nicht angelegt. Im Inventar ist der Plan neutral, ohne Farben.')
abb('inventar-plan', 'Lagerplan im Inventar')

abschnitt('Lagerort-Seite')
p('Die Lagerort-Seite zeigt, was in einem Kästchen liegt: Inhalt mit Menge und Wert, Kennzahlen, den Inventur-Stand, offene '
  'Zähl-Vormerkungen, Umlagerungsvorschläge hinein und hinaus, offene Aufträge mit Teilen von hier und die letzten Umlagerungen. Mit den '
  'Pfeilen oben blättert man zum Nachbarkästchen. Über <b>Umlagern</b> neben einem Lot lässt es sich direkt umbuchen.')
abb('lagerort', 'Lagerort-Seite des Kästchens G245')

abschnitt('Teile-Scanner und Teile-Seite')
p('Der <b>Teile-Scanner</b> erkennt Teile per Foto. Er wird über das Scan-Symbol in der Suche geöffnet.')
schritte([
    'Das Teil einzeln auf eine helle, ruhige Fläche legen.',
    '<b>Kamera starten</b> und <b>Erkennen</b> tippen oder mit <b>Foto wählen</b> ein Bild aus der Kamera-App nehmen.',
    'Das Foto wird verkleinert und über den Server an den Erkennungsdienst Brickognize geschickt.',
    'Ist das beste Ergebnis ein Teil, öffnet sich sofort dessen Teile-Seite; andere Treffer stehen dort als Alternativen.',
])
abb('scanner', 'Teile-Scanner')
p('Die <b>Teile-Seite</b> zeigt alles zu einer Teil-Nummer: Bild, Bestand, Anzahl der Farben, Wert, die in offenen Aufträgen reservierte '
  'Menge, den Bestand nach Farbe und Lagerort (mit Umlagern-Knopf), Aufträge mit dem Teil, letzte Umlagerungen und einen Link zu BrickLink. '
  'Jede Teil-Nummer in der App führt hierher.')
abb('teil', 'Teile-Seite für „Brick 2 x 4“ (3001)')

# =====================================================================
kapitel('Arbeitsabläufe')
abschnitt('Umlagern')
p('Umlagern hilft, das Lager in Ordnung zu bringen. Die App findet von selbst, was nicht dort liegt, wo es hingehört, und schlägt ein '
  'Ziel vor. Vorschläge gelten immer nur <b>innerhalb eines Bereichs</b> (gebraucht bleibt gebraucht) und füllen ein Kästchen mit '
  'höchstens drei Lots. Ziel ist jeweils das Kästchen, in dem schon am meisten davon liegt.')
tabelle(['Vorschlagsart', 'Erkennt', 'Vorschlag'], [
    ['Falsch einsortiert', 'gleiches Teil in gleicher Farbe in mehreren Kästchen', 'alles in das Kästchen mit der größten Menge'],
    ['Farben zusammenlegen', 'gleiches Teil in anderen Farben verstreut', 'in ein Kästchen, das nur dieses Teil enthält'],
    ['Gleiche Teile-Art', 'Teile derselben Art (z. B. Plates) verstreut', 'in ein Kästchen, das nur diese Art enthält'],
    ['Falscher Bereich', 'neues Teil im Gebraucht-Bereich oder umgekehrt', 'zum gleichen Teil im richtigen Bereich'],
    ['Mischkiste aufteilen', 'G-Kästchen mit mehr als drei Lots', 'die drei größten bleiben, der Rest zum gleichen Teil, zur gleichen Art oder ins nächste leere Kästchen'],
    ['Kästchen leeren', 'einziges Lot mit höchstens drei Teilen', 'zu den gleichen Teilen, das Kästchen wird frei'],
    ['Verwechselbares trennen', 'leicht verwechselbare Teile im selben Kästchen', 'das kleinere Lot woandershin'],
], [3.6, 5.6, 6.8], 'Arten von Umlagerungsvorschlägen')
p('<b>Verwechselbar</b> sind zum Beispiel ähnliche Farben (Light Gray und Light Bluish Gray, Tan und Dark Tan), Varianten derselben '
  'Teil-Nummer (3626b und 3626c), fast gleiche Teile (Tile 1 x 2 und Tile 1 x 2 with Groove) und ähnliche Größen (Plate 1 x 4 und '
  'Plate 1 x 5). Solche Teile schlägt die App auch nie zum Zusammenlegen vor.')
unter('Aufbau der Seite')
p('Oben zeigt ein Balken, <b>zu wie viel Prozent das Lager sortiert ist</b> (Anteil der Lots ohne offenen Vorschlag), je Bereich und mit '
  'der Veränderung zum Stand von vor etwa einer Woche („+2 % seit …“). Darunter liegt ein Panel mit vier Reitern: <b>Vorschläge</b>, '
  '<b>Lagerplan</b>, <b>Manuell umbuchen</b> und <b>Verlauf</b>.')
abb('umlagern', 'Umlagern: Fortschritt und Vorschläge nach Ziel-Kästchen')
p('Beim Öffnen stehen die Vorschläge <b>nach Ziel-Kästchen</b> gruppiert: Alles, was in dasselbe Kästchen gehört, steht zusammen und '
  'lässt sich mit <b>Alle übernehmen</b> auf einmal buchen. Die Ansicht <b>Nach Teil</b> zeigt jeden Vorschlag einzeln mit dem Weg '
  '„von → nach“. Über das Auswahlfeld rechts lässt sich nach Vorschlagsart filtern; der farbige Punkt in jeder Zeile zeigt die Art.')
abb('umlagern-teil', 'Umlagern: Vorschläge nach Teil')
p('Im Reiter <b>Lagerplan</b> sind Bereiche, Hunderter-Blöcke und Kästchen <b>nach Unordnung eingefärbt</b>: ohne Farbe = sortiert, '
  'dann gelb, orange und rot. Rot sind auch Mischkisten. So sieht man auf einen Blick, wo sich Aufräumen am meisten lohnt.')
abb('umlagern-plan', 'Lagerplan in Umlagern, gefärbt nach Unordnung')
unter('Einen Vorschlag ausführen')
schritte([
    'Vorschlag wählen und die Teile aus dem Quell-Kästchen nehmen.',
    'In das Ziel-Kästchen legen und auf <b>Übernehmen</b> tippen (oder <b>Alle übernehmen</b> für eine ganze Gruppe).',
    'Es öffnet sich das Fenster <b>Zu BrickLink übertragen</b>. Entweder sofort übertragen (Abschnitt 5.2) oder <b>Später</b> wählen; '
    'der Knopf bleibt dann unten auf der Seite.',
])
p('Unter <b>Manuell umbuchen</b> lässt sich jedes Lot frei an einen anderen Lagerort buchen, auch in Teilmengen. Liegt am Ziel schon '
  'dasselbe Teil, werden die Lots zusammengeführt. Der <b>Verlauf</b> listet alle Umlagerungen mit Zeit und Person.')
abb('umlagern-manuell', 'Manuell umbuchen')

abschnitt('Inventur und „Heute zählen“')
p('In der Inventur wird ein Kästchen nachgezählt. Abweichungen werden erfasst und beim Abschluss im Bestand korrigiert.')
unter('Zählliste des Tages')
p('Oben steht das Panel <b>Heute zählen</b> mit einigen Kästchen (Standard: fünf, einstellbar in der Lehrer-Ansicht). Die Liste wird '
  'beim ersten Abruf des Tages festgelegt und gilt für alle. Die Reihenfolge:')
schritte([
    'Kästchen, bei denen in der Pickliste ein Fehlteil, eine falsche Restmenge oder ein falscher Lagerort gemeldet wurde (älteste Meldung zuerst),',
    'danach Kästchen, die noch nie oder am längsten nicht gezählt wurden,',
    'bei Gleichstand die mit dem höheren Lagerwert.',
])
p('So wird das Lager nach und nach komplett durchgezählt, und gemeldete Fehler landen direkt bei der Inventur. Vorgemerkte Kästchen '
  'sind auch auf der Lagerort-Seite markiert.')
abb('inventur', 'Inventur mit dem Panel „Heute zählen“')
unter('Ein Kästchen zählen')
schritte([
    'Bei einem Kästchen der Zählliste <b>Starten</b> tippen oder oben einen Lagerort wählen und <b>Inventur starten</b>.',
    'Für jedes Lot die tatsächlich gezählte Menge eintragen. Die Spalte „Diff.“ zeigt die Abweichung.',
    '<b>Abschließen</b> tippen. Der Bestand wird korrigiert, eine Vormerkung für das Kästchen ist damit erledigt.',
    'Gab es Korrekturen, öffnet sich wie beim Umlagern <b>Zu BrickLink übertragen</b>.',
])
p('Mit der Geräte-Einstellung „blind zählen“ (Dev-Bereich) bleiben Soll-Mengen und Abweichungen bis zum Abschluss verborgen.')
abb('inventur-zaehlen', 'Eine laufende Inventur')

abschnitt('Pickliste')
p('Die Pickliste dient zum Zusammensuchen (Kommissionieren) offener Bestellungen von BrickLink und BrickOwl. Offen sind Bestellungen mit '
  'dem Status „Bezahlt“ oder „Verpackt“. Jede Karte zeigt Plattform, Käufer, Anzahl der Positionen und Teile sowie den Fortschritt.')
abb('pickliste', 'Pickliste: offene Bestellungen')
p('Nach dem Öffnen einer Bestellung stehen die Positionen nach <b>Laufweg</b> sortiert (die Reihenfolge der Lagerbereiche legt die '
  'Lehrkraft fest). Jede Zeile zeigt Bild, Teil, Farbe, Zustand, Kästchen, Menge und den Restbestand nach dem Picken. Gleiche '
  'Kästchen untereinander sind orange umrandet.')
abb('pickliste-auftrag', 'Eine geöffnete Bestellung mit Bereichs-Auswahl')
unter('Bedienung')
liste([
    '<b>Antippen</b> einer Position = gepickt (mit Namen). Erneutes Antippen nimmt es zurück.',
    '<b>Markieren</b> = Positionen für sich vormerken, damit andere sie nicht doppelt suchen. Die Vormerkung bleibt nach dem Picken bestehen, '
    'bis man sie wieder aufhebt.',
    '<b>Bereich</b> (G, H, N, FG …) wählen = nur dessen Positionen sehen, mit Fortschritt je Bereich. <b>Bereich übernehmen</b> merkt alle freien '
    'Positionen darin auf einmal vor; so teilen sich mehrere Personen das Lager auf.',
    '<b>„!“ – Problem melden</b>: Fehlteil, falscher Lagerort oder falsche Restmenge. Alle sehen die Meldung sofort mit Namen; jeder darf sie '
    'schließen. Solche Meldungen setzen das Kästchen auf die Zählliste der Inventur.',
    'Ist alles gepickt, wird die Bestellung abgeschlossen. Bei Großbriefen gibt es eine Briefvorschau zum Ausdrucken.',
])
hinweis('Die Seite aktualisiert sich jede Sekunde. Mehrere Personen können also gleichzeitig an derselben Bestellung arbeiten.')

abschnitt('Auftragszentrale')
p('Die Auftragszentrale zeigt alle Bestellungen nach Status (Neu, Bezahlt, Verpackt, Versandt, Abgeschlossen), die Positionen sortiert '
  'nach Lagerort und erlaubt, den Status weiterzuschalten. Sie hat keine eigene Kachel und wird über die Suche, die Analyse („Aufträge '
  'nach Status“), die Teile-Seite und die Lagerort-Seite erreicht.')
abb('auftraege', 'Auftragszentrale')

abschnitt('Minifig-Finder')
p('Der Minifig-Finder findet Minifiguren, die sich aus losen Teilen im Lager bauen lassen. Grundlage ist ein mitgelieferter '
  '<b>Figurenkatalog</b> mit über 9.000 Minifiguren samt Stücklisten und Preisen. Die Rechnung läuft vollständig auf dem Server, '
  'ohne Abfragen bei BrickLink.')
unter('Woher kommen die Teile?')
p('Oben wählt man die Quelle, die für alle gilt: <b>Nur Lager</b> (der eigene Bestand), <b>Lager + BSX-Dateien</b> (zusätzlich eigene '
  'BrickStore-Dateien, z. B. eine gekaufte Teilekiste) oder <b>Nur BSX-Dateien</b>. Dateien werden mit „BSX-Datei hinzufügen“ hochgeladen. '
  'Liegt ein Teil im Lager und in einer Datei, legt man fest, was zuerst verbraucht wird und in welcher Reihenfolge die Dateien drankommen.')
abb('minifig', 'Minifig-Finder: Erklärung, Quelle und Ergebnis-Kacheln')
unter('Die Liste „Figuren“')
p('Alle Ergebnisse stehen in einer Liste mit Reitern. Die Zahlen-Kacheln oben springen direkt zum passenden Reiter.')
tabelle(['Reiter', 'Inhalt'], [
    ['Bauplan', 'Figuren, die sich gemeinsam bauen lassen, ohne dass ein Teil doppelt verplant wird (Standard)'],
    ['Einzeln baubar', 'jede Figur für sich betrachtet'],
    ['1 Teil fehlt', 'Figuren mit genau einem Fehlteil, mit Kosten'],
    ['Gemeinsam ergänzbar', 'Figuren, die sich zusammen mit wenigen Zukäufen bauen lassen; darunter ausgeschlossene Figuren'],
    ['Blockiert', 'Teile sind schon für andere Figuren verplant'],
    ['Fehlteile', 'welches Teil die meisten Figuren freischaltet'],
    ['Alle', 'Rangliste aller Figuren, standardmäßig nach Figurenwert'],
], [4, 12], 'Reiter im Minifig-Finder')
p('Jede Figur hat eine Nummer, zeigt Figurenwert, Wert der Lagerteile, Vorteil und wie oft sie sich bauen lässt. Die aufklappbare '
  'Teileliste nennt je Teil Farbe, Menge je Figur und insgesamt sowie Kästchen (Remarks) und Bemerkung. Über <b>Herunterladen …</b> '
  'lassen sich Listen (Reiter, Nummernbereich) als CSV- oder BrickStore-Datei speichern.')
abb('minifig-liste', 'Reiter „Bauplan“ mit Knopf „Fertig gebaut“')
unter('Fertig gebaut, abziehen und einlagern')
schritte([
    'Figur zusammensuchen und bei der Figur auf <b>Fertig gebaut</b> tippen (Anzahl wählbar). Sie kommt in die gemeinsame Sammlung '
    '„Fertige Figuren“; ihre Teile sind sofort aus der Rechnung heraus.',
    'Am Ende tippt eine Person auf <b>Sammlung abschließen</b>. Es entsteht eine Datei für BrickLink mit Minus-Mengen je Lot '
    '(einfügen unter „Upload/Update Inventory“) und, falls Teile aus BSX-Dateien stammen, die geänderten Dateien.',
    'Im Schritt <b>Figuren einlagern</b> werden Zustand (gebraucht → FG, neu → FN), Preis (Vorschlag aus den Figurenpreisen) und '
    'Kästchen geprüft. Eine zweite Datei legt die Figuren bei BrickLink als neue Lots an.',
    'Nach <b>Ja, ist hochgeladen</b> werden die Teile im Bestand abgezogen und die Figuren als neue Lots eingetragen.',
])

abschnitt('Analyse und Bestenliste')
p('Die Analyse zeigt Bestand nach Kategorie, volle Regalplätze, Mischkisten, Engpässe, Aufträge nach Status und – nur für die '
  'Lehrkraft – die Aktivität pro Teilnehmer. Ein Tipp auf einen Balken öffnet die passende Liste.')
abb('analyse', 'Lager- und Auftragsanalyse')
p('Ganz unten steht abgetrennt die <b>Bestenliste</b>: wer am meisten gepickt, umgelagert und gezählt hat und wer die meisten Abzeichen '
  'hat, wählbar für diese Woche, diesen Monat oder gesamt.')
abb('analyse-bestenliste', 'Bestenliste am Ende der Analyse')

# =====================================================================
kapitel('BrickLink und Shop-Dashboard')
abschnitt('Grundsatz')
p('Der Verkaufsbestand liegt bei <b>BrickLink</b>. Das TN Dashboard hält eine eigene Kopie in seiner Datenbank und rechnet nur damit. '
  'Es <b>schreibt nie selbst</b> zu BrickLink: Jede Änderung (Umlagerung, Inventur-Korrektur, gebaute Figuren) wird als XML-Datei '
  'bereitgestellt, die eine Person im BrickLink-Shop einfügt. Abfragen bei BrickLink gibt es nur auf Knopfdruck; jeder Abruf wird gezählt '
  f'(Tabelle {c("tn_api_calls")}).')

abschnitt('Übertragung nach Umlagern und Inventur')
p('Direkt nach einer Umlagerung oder einer Inventur mit Korrekturen öffnet sich das Fenster <b>Zu BrickLink übertragen</b>.')
schritte([
    'Auf <b>Kopieren und BrickLink öffnen</b> tippen. Der ganze Bestand wird als XML in die Zwischenablage kopiert (alle Lots mit der Menge '
    'nach dem Abziehen, Lagerort in den Remarks), und die Upload-Seite von BrickLink öffnet sich in einem neuen Tab.',
    'Bei BrickLink unten beim Upload einfügen und bestätigen.',
    'Zurück in der App <b>Ja, ist eingefügt</b> tippen. Erst dann gelten die Änderungen als übertragen. „Nein, verwerfen“ bricht ab.',
])
hinweis('Es läuft immer nur <b>eine</b> Übertragung. Alle sehen sie, und jede Person kann sie abschließen. So überschreibt kein älterer '
        'Bestand einen neueren. Eine einzige Datei enthält immer den gesamten Bestand.')

abschnitt('Gleichzeitiges Arbeiten')
p('Bucht jemand ein Lot um, prüft der Server, ob Lagerort und Menge noch dem Stand auf dem Bildschirm entsprechen. Hat eine andere Person '
  'das Lot inzwischen umgelagert, erscheint „Dieses Lot wurde gerade von jemand anderem umgelagert“, und die Ansicht lädt neu. Es geht '
  'also immer nur die erste Buchung durch.')

abschnitt('Bestand und Aufträge holen')
p('In der Lehrer-Ansicht gibt es zwei Wege, Daten von den Plattformen zu holen:')
liste([
    '<b>Shop-Daten holen</b>: Bestand und Aufträge von BrickLink und BrickOwl (frühestens alle 10 Minuten). Auftragspositionen werden pro '
    'Auftrag nur einmal geladen.',
    '<b>Lager aus BrickLink übernehmen</b>: alle Lots mit Lot-ID und Lagerort aus den Remarks. Fehlende Lagerorte werden angelegt, Lots, die es '
    'bei BrickLink nicht mehr gibt, entfernt.',
])
hinweis('Vor einem Abruf offene Übertragungen abschließen, sonst überschreibt der Abruf die noch nicht übertragenen Umlagerungen. Als '
        'Sicherung bricht der Import ab, wenn BrickLink keine oder auffällig wenige Lots liefert (unter 20 % des bisherigen Bestands).',
        'Achtung')

abschnitt('Abgleich mit dem Shop-Dashboard')
p('Auf demselben Rechner läuft das bestehende Shop-Dashboard (in der App „Ollies Dashboard“ genannt, Python/FastAPI). Es lädt den '
  'BrickLink-Bestand alle vier Stunden in seine eigene SQLite-Datenbank. Das TN Dashboard liest diese Datei <b>nur</b> und übernimmt den '
  'Bestand, damit beide Programme immer denselben Stand haben:')
liste([
    'Hat das Shop-Dashboard neu geladen, übernimmt das TN Dashboard den neuen Stand automatisch.',
    'Solange Umlagerungen oder Inventuren noch nicht bei BrickLink eingefügt sind, wartet die Übernahme, damit nichts verloren geht.',
    'Nach „Ja, ist eingefügt“ wird das Shop-Dashboard sofort zum Neuladen aufgefordert.',
    'Im Dev-Bereich (Einstellungen → „Ollies Dashboard“) gibt es die Knöpfe <b>Bei BrickLink neu laden</b> und <b>Letzten Stand übernehmen</b>.',
])

abschnitt('Arbeiten bei schlechtem WLAN')
p('Pickliste und Inventur arbeiten auch ohne Verbindung weiter. Die zuletzt geöffnete Bestellung bzw. Inventur bleibt sichtbar. Picken, '
  'Markieren, Melden und gezählte Mengen werden auf dem Gerät gespeichert und automatisch nachgeschickt, sobald das WLAN wieder da ist. '
  'Unten erscheint dann „Offline · x Änderungen warten“, oben rechts steht „Offline“ statt „Online“.')
liste([
    'Hat in der Zwischenzeit jemand anderes dieselbe Position gepickt, zeigt die App „Nicht übernommen“ an, statt etwas zu überschreiben.',
    'Umlagern braucht immer den aktuellen Stand und funktioniert daher nur mit Verbindung.',
    'Voraussetzung: HTTPS und die App einmal mit WLAN geöffnet.',
])

# =====================================================================
kapitel('Lehrer-Ansicht und Dev-Bereich')
abschnitt('Lehrer-Ansicht')
p('Die Lehrer-Ansicht ist nur für Lehrkräfte sichtbar (Menü am eigenen Namen). Sie enthält:')
liste([
    '<b>Teilnehmer</b>: Liste aller Konten mit Rolle, letzter Aktivität und Anzahl Aktionen. Ein Tipp auf einen Namen filtert das Protokoll.',
    '<b>Protokoll</b> aller oder einzelner Teilnehmer mit Zeit, Aktion, Bereich und Details, exportierbar als <b>CSV</b>.',
    '<b>Teilnehmer anlegen</b> (Benutzername, Anzeigename, Startpasswort mit mindestens sechs Zeichen).',
    '<b>Konto löschen</b> (Papierkorb mit Rückfrage): Das Konto wird deaktiviert, das Protokoll bleibt erhalten, der Benutzername wird frei.',
    '<b>Dev-Rolle</b> vergeben oder entziehen (Spalte „Dev“).',
    '<b>BrickLink-Shop</b>: Lager aus BrickLink übernehmen; <b>Shop-Daten holen</b>: Bestand und Aufträge von BrickLink und BrickOwl.',
    '<b>Pickliste: Laufweg</b>: Reihenfolge der Lagerbereiche (eine pro Zeile; der längste passende Anfang gewinnt) und weitere Einstellungen.',
    '<b>Zählliste</b>: wie viele Kästchen täglich unter „Heute zählen“ stehen (1 bis 30, gilt ab dem nächsten Tag).',
])
abb('lehrer', 'Lehrer-Ansicht mit Teilnehmern und Protokoll')
abb('lehrer-2', 'Lehrer-Ansicht: Teilnehmer anlegen, BrickLink-Shop und Protokoll')

abschnitt('Pick-Statistik')
p('Die Pick-Statistik (Knopf in der Pickliste, nur für Lehrkräfte und Dev-Rolle) wertet jeden Pick und jede Rücknahme aus: bearbeitete '
  'und vollständig gepickte Bestellungen, Positionen, Teile, Korrekturen und die Dauer je Bestellung, gefiltert nach Zeitraum, Person und '
  'Plattform.')
abb('pick-statistik', 'Pick-Statistik')

abschnitt('Dev-Bereich')
p('Der Dev-Bereich ist für alle gedacht, die an der App mitarbeiten. Zugang haben Lehrkräfte und Teilnehmer mit Dev-Rolle; jede Route '
  'prüft die Rolle auf dem Server. Ein zusätzlicher Code ist nicht nötig, da man mit dem eigenen Passwort angemeldet ist. Der Bereich ist '
  'für Daten nur lesend; Passwörter und gespeicherte Geheimnisse werden nie angezeigt.')
tabelle(['Reiter', 'Inhalt'], [
    ['System', 'App-Version und Modus, Datenbank, PHP mit Erweiterungen und Upload-Grenzen, Dienste (BrickLink, Teileerkennung, HTTPS), '
               'Herkunft der Einstellungen (nur Namen, nie Werte), Infos zum eigenen Gerät'],
    ['Tests', 'Selbsttests mit einem Tipp: Datenbank lesen und schreiben, Sitzung, Temp-Ordner, BrickLink, Teileerkennung, '
              'alle lesenden API-Endpunkte, Gerätefunktionen'],
    ['Daten', 'alle Tabellen ansehen, blättern, filtern und als JSON kopieren (nur lesen)'],
    ['API', 'Konsole für lesende Aufrufe, die letzten Aufrufe mit Status und Dauer, BrickLink-Artikel abrufen'],
    ['Einstellungen', 'Debug-Anzeige, langsames Netz simulieren, Aufrufe mitschreiben, Cache leeren, Startanimation, '
                      'Shop-Dashboard-Abgleich und Einstellungen je Bereich (pro Gerät)'],
], [3, 13], 'Reiter des Dev-Bereichs')
abb('dev', 'Dev-Bereich, Reiter „System“')
abb('dev-tests', 'Dev-Bereich, Reiter „Tests“')
abb('dev-daten', 'Dev-Bereich, Reiter „Daten“ (Passwort-Hashes verdeckt)')
unter('Einstellungen je Bereich')
p('Unter Dev-Bereich → Einstellungen lassen sich für jedes Gerät eigene Vorgaben setzen, jeweils mit „Standard“-Knopf:')
tabelle(['Bereich', 'Einstellungen'], [
    ['Inventar', 'Lots pro Abruf, Sortierung, Lagerplan anzeigen'],
    ['Umlagern', 'Vorschläge pro Seite, Filter, Verlauf, Lagerplan, BrickLink-Pop-up'],
    ['Inventur', 'blind zählen (Soll und Abweichungen bis zum Abschluss verborgen)'],
    ['Analyse', 'Kennzahl der Diagramme'],
    ['Start', 'Startanimation ein/aus, „Jetzt abspielen“'],
], [3, 13], 'Geräte-Einstellungen je Bereich')
abb('dev-einstellungen', 'Dev-Bereich, Reiter „Einstellungen“')

# =====================================================================
kapitel('Technische Dokumentation')
abschnitt('Architektur')
tabelle(['Schicht', 'Technik', 'Beschreibung'], [
    ['Frontend', 'Vue 3, Vite, Pinia, vue-router', 'Installierbare Web-App (PWA) mit Service Worker. Hash-Routing (#/inventar), '
                                                   'damit kein URL-Rewriting nötig ist. Auf dem Server liegen nur fertige Dateien.'],
    ['Backend', 'PHP 8.1+ ohne Framework', f'Einziger Einstieg {c("api/index.php")}, Routen über {c("?r=<route>")}. Läuft auf jedem '
                                           'normalen Webspace.'],
    ['Datenbank', 'MySQL / MariaDB (lokal SQLite)', f'Alle Tabellen mit Präfix {c("tn_")}, getrennt von anderen Anwendungen in derselben Datenbank.'],
    ['Extern', 'BrickLink, BrickOwl, Brickognize', 'Nur auf Knopfdruck bzw. für die Fotoerkennung; Antworten werden in der Datenbank gespeichert.'],
], [2.5, 4.3, 9.2], 'Schichten der Anwendung')
p('Jede schreibende Aktion ruft das Protokoll auf (Benutzer, Aktion, Bereich, Details). Das Frontend spricht ausschließlich über einen '
  'fetch-Wrapper mit dem Backend. Für Demo und Tutorial wird dieser Wrapper durch ein Demo-Backend im Browser ersetzt, das dieselben Regeln '
  'nachbildet (Abschnitt 7.7).')

abschnitt('Aufbau des Repositorys')
tabelle(['Pfad', 'Inhalt'], [
    [c('api/index.php'), 'Routing aller API-Aufrufe, Rechteprüfung, Protokoll'],
    [c('api/install.php'), 'Einrichtung im Browser oder auf der Kommandozeile (Tabellen, erstes Lehrer-Konto, Beispieldaten, BrickStore-Import)'],
    [c('api/lib/Shop.php'), 'Lager, Aufträge, Umlagern und Vorschläge, Inventur, Analyse, Teile- und Lagerort-Seite'],
    [c('api/lib/Picking.php'), 'Pickliste und Pick-Statistik'],
    [c('api/lib/Minifig.php'), 'Minifig-Finder: Katalog, Rechnung, Sammlung „Fertige Figuren“, Exporte'],
    [c('api/lib/Counting.php'), 'Zähl-Vormerkungen und Zählliste des Tages'],
    [c('api/lib/BrickLinkSync.php'), 'BrickLink-Übertragung und Import des Lagers'],
    [c('api/lib/Sync.php'), 'Bestand und Aufträge von BrickLink und BrickOwl holen'],
    [c('api/lib/OllieSync.php'), 'Abgleich mit dem Shop-Dashboard (liest dessen SQLite-Datei)'],
    [c('api/lib/Brickognize.php'), 'Teileerkennung per Foto'],
    [c('api/lib/Dev.php'), 'Dev-Bereich: Zugang, Systemdaten, Selbsttests, Tabellen-Ansicht'],
    [c('api/lib/env.php'), 'liest die vorhandene .env des Servers (nur lesen)'],
    [c('api/sql/'), 'Schemas für MySQL und SQLite, erfundene Beispieldaten, Figurenkatalog'],
    [c('frontend/src/views/'), 'eine Ansicht pro Bereich, Lehrer-Ansicht, Dev-Bereich'],
    [c('frontend/src/components/'), 'wiederverwendbare Bausteine (Lagerplan, Teilebild, BrickLink-Übertragung …)'],
    [c('frontend/src/demo/'), 'Demo-Backend im Browser (gleiche Regeln wie PHP)'],
    [c('frontend/src/tutorial/'), 'Tutorial und Kurzhilfe mit Klemmi'],
    [c('frontend/src/utils/'), 'Hilfsfunktionen (Bereiche, XML für BrickLink, Offline-Modus, Einstellungen)'],
    [c('tools/'), 'Skripte für Beispieldaten, Icons, Figurenkatalog und dieses Handbuch'],
    [c('docs/'), 'Logo, Bildschirmfotos, dieses Handbuch'],
    [c('TN-Dashboard-Demo.html'), 'komplette App als eine Datei mit erfundenen Daten'],
    [c('TN-Dashboard-Tutorial.html'), 'geführte Tour mit Klemmi'],
    [c('.github/workflows/build.yml'), 'automatische Build-Prüfung'],
    [c('start.sh') + ', ' + c('start.bat'), 'lokal starten (SQLite, Test-Konten)'],
    [c('CLAUDE.md'), 'Projektwissen für die Weiterentwicklung (Regeln, Entscheidungen, offene Punkte, Verlauf)'],
], [5.6, 10.4], 'Wichtige Dateien und Ordner')

abschnitt('Installation und Updates')
schritte([
    f'App bauen: {c("cd frontend && npm ci && npm run build")}. Das ergibt den Ordner {c("dist/")} mit App und PHP-Backend '
    '(fertig gebaut auch als Download der Build-Prüfung auf GitHub).',
    f'Den Inhalt von {c("dist/")} auf den Webserver laden, z. B. nach {c("/tn-dashboard/")}.',
    f'{c("https://<server>/tn-dashboard/api/install.php")} öffnen. Die Seite findet die .env selbst (App-Ordner, Web-Root oder eine '
    'Ebene darüber), zeigt die gefundenen Namen (nie Werte), legt die Tabellen und das erste Lehrer-Konto an.',
    'Als Lehrkraft anmelden und in der Lehrer-Ansicht die Teilnehmer anlegen.',
    f'Ersten Bestand holen: „Shop-Daten holen“ in der Lehrer-Ansicht, alternativ {c("php api/install.php --sync")} oder aus einer '
    f'BrickStore-Datei {c("php api/install.php --bsx bestand.bsx")}.',
])
p(f'<b>Update:</b> neuen Inhalt von {c("dist/")} hochladen und {c("api/install.php")} einmal öffnen. Bestehende Daten bleiben erhalten, '
  'neue Tabellen und Spalten werden ergänzt.')
p('<b>Voraussetzungen:</b> PHP 8.1+ mit PDO (MySQL, für den Abgleich mit dem Shop-Dashboard zusätzlich pdo_sqlite) und cURL, MySQL oder '
  'MariaDB, HTTPS (für Kamera, Installation als App und Offline-Modus), Internetzugang zu api.brickognize.com für die Teileerkennung.')

abschnitt('Konfiguration')
p(f'Die Zugangsdaten kommen aus der vorhandenen {c(".env")} des Servers. Sie wird nur gelesen und nie verändert; eine .htaccess sperrt '
  f'sie für Zugriffe über das Web. Liegt sie woanders oder heißen die Variablen anders, wird {c("api/config.example.php")} nach '
  f'{c("api/config.php")} kopiert und dort {c("env_file")} bzw. {c("env_map")} angepasst.')
tabelle(['Variable', 'Bedeutung'], [
    [c('DB_HOST') + ', ' + c('DB_PORT'), 'Datenbank-Server'],
    [c('DB_DATABASE') + ' / ' + c('DB_NAME'), 'Name der Datenbank'],
    [c('DB_USERNAME') + ' / ' + c('DB_USER'), 'Benutzer der Datenbank'],
    [c('DB_PASSWORD') + ' / ' + c('DB_PASS'), 'Passwort der Datenbank'],
    [c('BRICKLINK_CONSUMER_KEY') + ', ' + c('…_SECRET') + ', ' + c('BRICKLINK_TOKEN') + ', ' + c('…_SECRET'), 'Zugang zur BrickLink-API'],
    [c('BRICKOWL_API_KEY'), 'Zugang zur BrickOwl-API'],
    [c('OLLIE_DB_PATH'), 'Pfad zur Datenbank des Shop-Dashboards (data/legoshop.db)'],
    [c('OLLIE_URL'), 'Adresse des Shop-Dashboards, Standard http://127.0.0.1:8000'],
], [7.6, 8.4], 'Erkannte Variablen der .env')
p(f'Weitere Werte in {c("api/config.php")}: {c("brickognize.url")} (null schaltet die Teileerkennung ab), {c("shop.sender")} '
  f'(Absender der Briefvorschau), {c("sync.min_interval")} (Mindestabstand der Abrufe) und Testverzeichnisse für Abrufe ohne Internet.')

abschnitt('Datenbank')
p(f'Das Schema steht in {c("api/sql/schema.mysql.sql")} und {c("api/sql/schema.sqlite.sql")}; beide werden immer gemeinsam geändert. '
  'Neuere Tabellen und Spalten legt die Anwendung bei Bedarf selbst an, damit Updates ohne Datenverlust laufen.')
tabelle(['Tabellen', 'Zweck'], [
    [c('tn_users') + ', ' + c('tn_dev_access'), 'Konten (Passwörter nur als Hash) und Dev-Rolle'],
    [c('tn_activity_log'), 'Protokoll aller Aktionen'],
    [c('tn_locations') + ', ' + c('tn_inventory') + ', ' + c('tn_inventory_history'), 'Lagerorte, Lots, Archiv früherer Lots'],
    [c('tn_orders') + ', ' + c('tn_order_items'), 'Aufträge und Positionen'],
    [c('tn_transfers') + ', ' + c('tn_sort_progress'), 'Umlagerungen, Sortier-Fortschritt je Tag'],
    [c('tn_stocktakes') + ', ' + c('tn_stocktake_items'), 'Inventuren und gezählte Mengen'],
    [c('tn_count_requests') + ', ' + c('tn_daily_counts'), 'Zähl-Vormerkungen und Zählliste des Tages'],
    [c('tn_bl_exports') + ', ' + c('tn_bl_cache') + ', ' + c('tn_api_calls'), 'BrickLink-Übertragungen, Zwischenspeicher, Abrufzähler'],
    [c('tn_pick_…') + ' (6 Tabellen)', 'Fortschritt, Ereignisse, Vormerkungen, Meldungen, Laufweg und Status der Pickliste'],
    [c('tn_minifig_…') + ', ' + c('tn_price_guide'), 'Figurenkatalog, Entscheidungen, BSX-Dateien, fertige Figuren, Preise'],
    [c('tn_settings'), 'Einstellungen der Anwendung (Werte werden nie angezeigt)'],
], [7.6, 8.4], 'Tabellen der Datenbank')

abschnitt('Schnittstellen')
p(f'Alle Aufrufe gehen an {c("api/index.php?r=<route>")} und liefern JSON. Die wichtigsten Gruppen:')
tabelle(['Route', 'Zweck'], [
    [c('auth/login, auth/logout, auth/me'), 'Anmeldung und Sitzung'],
    [c('inventory, inventory/meta, part/<nr>, location/<code>'), 'Bestand, Teile- und Lagerort-Seite'],
    [c('transfers, transfers/suggestions'), 'Umlagern, Vorschläge und Fortschritt'],
    [c('stocktakes, stocktakes/today'), 'Inventur und Zählliste des Tages'],
    [c('picking/orders, picking/items/<id>/…'), 'Pickliste: picken, markieren, melden'],
    [c('minifig/results, minifig/builds, minifig/bsx'), 'Minifig-Finder'],
    [c('bricklink/pending, /stock, /start, /finish, /import'), 'BrickLink-Übertragung und Import'],
    [c('sync/inventory, sync/orders'), 'Shop-Daten holen'],
    [c('ollie/status, /import, /refresh'), 'Abgleich mit dem Shop-Dashboard'],
    [c('recognize'), 'Teileerkennung per Foto'],
    [c('achievements, analysis, dashboard'), 'Abzeichen, Analyse, Startseite'],
    [c('dev/system, dev/tables, dev/test'), 'Dev-Bereich (nur mit Dev-Rolle)'],
], [8, 8], 'Wichtige API-Routen')

abschnitt('Demo, Tutorial und Builds')
tabelle(['Befehl (im Ordner frontend/)', 'Ergebnis'], [
    [c('npm run build'), 'Ordner dist/ mit App und PHP-Backend zum Hochladen'],
    [c('npm run build:demo'), 'TN-Dashboard-Demo.html: komplette App als eine Datei mit erfundenen Daten, ohne Server'],
    [c('npm run build:tutorial'), 'TN-Dashboard-Tutorial.html: geführte Tour mit Klemmi'],
], [6, 10], 'Builds')
p(f'Demo und Tutorial benutzen das Demo-Backend ({c("frontend/src/demo/")}), das alle Regeln des PHP-Backends nachbildet und die Daten im '
  'Browser speichert. Jede neue Backend-Funktion wird deshalb doppelt gebaut. Die erfundenen Beispieldaten erzeugt '
  f'{c("tools/gen-demo-data.py")}. Lokal startet {c("./start.sh")} (bzw. {c("start.bat")}) die App mit SQLite und den Test-Konten '
  f'{c("lehrer/lehrer123")}, {c("max/max12345")} und {c("erika/erika123")}.')

abschnitt('Tests und Qualitätssicherung')
liste([
    f'Die <b>Build-Prüfung</b> ({c(".github/workflows/build.yml")}) prüft bei jedem Hochladen PHP-Syntax, den Build von App und Demo und '
    f'{c("install.php")}.',
    '<b>Vergleichstests</b>: Für Umlagerungsvorschläge, Zählliste, Pickliste und Minifig-Finder wurden die Ergebnisse von PHP-Backend und '
    'Demo-Backend (und beim Minifig-Finder des ursprünglichen Python-Programms) vollständig verglichen – ohne Unterschiede.',
    '<b>Browser-Tests</b> mit Playwright auf iPad- und Handy-Größe, unter anderem für das Tutorial, den Offline-Modus mit zwei Personen und '
    'die Kurzhilfe.',
    '<b>Selbsttests</b> im Dev-Bereich prüfen die Installation auf dem Server.',
])

abschnitt('Datenschutz und Sicherheit')
liste([
    'Protokolliert werden nur Aktionen in der App (z. B. „Umgelagert“, „Teil erkannt“), keine Inhalte außerhalb der App.',
    'Passwörter liegen nur als Hash in der Datenbank und erscheinen nirgends in der App, auch nicht im Dev-Bereich.',
    'Fotos des Teile-Scanners gehen nur zur Erkennung an Brickognize und werden auf dem Server nicht gespeichert.',
    'Zugangsdaten stehen nur in der .env bzw. config.php auf dem Server, nie im Repository.',
    'Das Repository und die öffentliche Demo enthalten ausschließlich erfundene Lager- und Auftragsdaten.',
    'Jede Route prüft Anmeldung und Rolle auf dem Server; gelöschte Konten können sich nicht mehr anmelden.',
])

# =====================================================================
kapitel('Anhang')
abschnitt('Glossar')
tabelle(['Begriff', 'Erklärung'], [
    ['BrickLink', 'größter Online-Marktplatz für Lego-Teile; dort liegt der Verkaufsbestand des Shops'],
    ['BrickOwl', 'zweiter Marktplatz, über den Bestellungen eingehen'],
    ['BrickStore / BSX', 'Programm zur Bestandsverwaltung; BSX ist sein Dateiformat'],
    ['Brickognize', 'kostenloser Dienst zur Erkennung von Lego-Teilen per Foto'],
    ['Lot', 'Posten im Bestand: ein Teil in einer Farbe und einem Zustand an einem Lagerort, mit Menge und Preis'],
    ['Lot-ID', 'Nummer eines Lots bei BrickLink'],
    ['Kästchen', 'ein Lagerort im Lagerzimmer, z. B. G245'],
    ['Mischkiste', 'G-Kästchen mit mehr als drei verschiedenen Lots, noch nicht aufgeteilt'],
    ['Remarks', 'Bemerkungsfeld eines Lots bei BrickLink; enthält den Lagerort'],
    ['Picken', 'Teile einer Bestellung aus dem Lager zusammensuchen'],
    ['Laufweg', 'Reihenfolge der Lagerbereiche beim Picken'],
    ['Inventur', 'Nachzählen eines Kästchens und Korrigieren des Bestands'],
    ['Zustand N / U', 'neu (New) bzw. gebraucht (Used)'],
    ['PWA', 'Progressive Web App: Website, die sich wie eine App installieren lässt'],
    ['Dev-Rolle', 'Zusatzrecht für den Dev-Bereich, vergeben durch die Lehrkraft'],
    ['Klemmi', 'Maskottchen der App; erklärt in Hilfe und Tutorial'],
    ['Shop-Dashboard', 'bestehendes Programm des Shops auf demselben Rechner („Ollies Dashboard“)'],
], [3.6, 12.4], 'Begriffe')

abschnitt('Häufige Fragen')
fragen = [
    ('Ich habe mein Passwort vergessen.', 'Die Lehrkraft kann das Konto löschen und neu anlegen; das Protokoll bleibt erhalten.'),
    ('Bei BrickLink stehen andere Mengen als in der App.',
     'Wahrscheinlich ist eine Übertragung noch offen. Unten auf der Umlagern-Seite „Zu BrickLink übertragen“ öffnen und abschließen.'),
    ('Beim Umlagern erscheint „Dieses Lot wurde gerade von jemand anderem umgelagert“.',
     'Eine andere Person war schneller. Die Ansicht lädt neu und zeigt den aktuellen Stand.'),
    ('Die Kamera im Teile-Scanner startet nicht.',
     'Die Kamera braucht HTTPS und die Erlaubnis im Browser. Alternativ „Foto wählen“ benutzen.'),
    ('Unten steht „Offline · Änderungen warten“.',
     'Die Änderungen sind auf dem Gerät gespeichert und werden automatisch nachgeschickt, sobald das WLAN wieder da ist.'),
    ('Ein Kästchen ist im Lagerplan grau.', 'Es ist noch nicht angelegt; es entsteht beim ersten Umbuchen dorthin oder beim Import.'),
    ('Wie prüfe ich, ob der Server richtig eingerichtet ist?', 'Dev-Bereich → Tests → „Alle Tests starten“.'),
]
for f, a in fragen:
    story.append(KeepTogether([Paragraph(f'<b>{f}</b>', ParagraphStyle('q', parent=S['body'], spaceAfter=1)),
                               Paragraph(a, ParagraphStyle('a', parent=S['body'], leftIndent=12))]))

story.append(PageBreak())
abschnitt('Abbildungsverzeichnis')
lof = LOF()
lof.levelStyles = [S['lof']]
story.append(lof)


# ---------- Bauen ----------
def bauen():
    doc = Doc(AUSGABE)
    doc.multiBuild(copy.deepcopy(story))  # jede Runde mit frischen Bausteinen
    return doc.page


nr_stand = dict(nr)
seiten = bauen()
GESAMT['n'] = seiten
bauen()
print(f'{AUSGABE}: {seiten} Seiten, {nr_stand["abb"]} Abbildungen, {nr_stand["tab"]} Tabellen')
