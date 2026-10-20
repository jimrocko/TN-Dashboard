// Kurzhilfe je Bereich (Knopf „?“ oben rechts, Wunsch des Inhabers, Sitzung 6).
// Klemmi erklärt nur diesen einen Bereich. Nur Erklärschritte (Weiter), nichts wird angetippt oder gebucht,
// deshalb ist die Hilfe auch in der echten App mit echten Daten gefahrlos.
// Fehlt ein Ziel (z. B. leere Liste), spricht Klemmi trotzdem, nur ohne Lichtkegel.
import { reactive } from 'vue'

const visible = (el) => !!el && el.getClientRects().length > 0
const q = (sel) => [...document.querySelectorAll(sel)].find(visible) ?? null
const byText = (sel, text) => [...document.querySelectorAll(sel)].find((el) => visible(el) && el.textContent.includes(text)) ?? null
const panel = (title) => [...document.querySelectorAll('main section.panel, .xp-window section.panel')]
  .find((s) => visible(s) && s.querySelector('h2')?.textContent.trim().startsWith(title)) ?? null
const head = () => q('main .page-head')

const end = (say) => ({ say, wait: 'end', mood: 'cheer' })

const topics = {
  start: {
    title: 'Startseite',
    steps: [
      { say: 'Willkommen auf der Startseite! Unter dem Titel ist die Suche: Teil-Nummer, Name, Farbe, Lagerort oder Auftrag eintippen und Enter.', target: () => q('main form.search') },
      { say: 'Rechts im Suchfeld sitzt das Scan-Symbol. Damit fotografierst du ein Teil, und die App erkennt es.', target: () => q('main form.search .scan') },
      { say: 'Jede Kachel ist ein Bereich. Die kleine Zeile unten verrät, was dort gerade los ist, zum Beispiel wie viele Vorschläge in Umlagern warten.', target: () => q('main .grid') },
      { say: 'Unten geht es immer zurück nach Hause und zu den Einstellungen. Oben rechts auf deinen Namen tippen zeigt deine Aktivitäten.', target: () => q('.bottom') },
      end('In jedem Bereich gibt es oben rechts „Hilfe“. Da erkläre ich dir genau diesen Bereich oder mache die große Rundführung mit dir.'),
    ],
  },
  inventar: {
    title: 'Inventar',
    steps: [
      { say: 'Hier siehst du den ganzen Bestand. Jedes Lot ist ein Teil in einer Farbe an einem Lagerort. Oben steht, wie viele Lots, Teile und welcher Wert es insgesamt sind.', target: head },
      { say: 'Mit dem Suchfeld und den Auswahlfeldern filterst du nach Nummer, Name, Farbe, Kategorie oder Lagerort. Ganz rechts schaltest du zwischen „Kategorien“ und einer langen „Liste“ um.', target: () => q('main .panel-head.toolbar') },
      { say: 'Die Lots sind nach Kategorien sortiert, zum Beispiel Brick, Plate oder Tile. Tippst du eine Kategorie an, klappt sie auf und zeigt ihre Lots.', target: () => q('.cat-head') ?? q('main .table-wrap') },
      { say: 'Über „Lagerplan“ siehst du das Lagerzimmer von oben. Bereich antippen, dann den Hunderter-Block, dann das Kästchen, und schon siehst du, was drin liegt.', target: () => byText('main .btn', 'Lagerplan') },
      end('Noch ein Tipp: Eine Teil-Nummer antippen öffnet die Teile-Seite, das Pfeil-Symbol am Zeilenende bringt das Lot direkt zum Umlagern.'),
    ],
  },
  umlagern: {
    title: 'Umlagern',
    steps: [
      { say: 'Hier räumen wir das Lager auf. Die App sucht von selbst, was nicht dort liegt, wo es hingehört.', target: head },
      { say: 'Drei Sorten Vorschläge: falsch einsortierte Teile, gleiche Teile in anderen Farben und Teile derselben Art. Umgelagert wird immer nur im selben Bereich, gebraucht bleibt gebraucht. Oben wählst du die Ansicht und die Sorte.', target: () => q('.umlist .bar') ?? q('.umlist') },
      { say: '„Nach Ziel-Kästchen“: Alles, was in dasselbe Kästchen gehört, steht zusammen. Hast du umgeräumt, tippst du „Übernehmen“ oder für das ganze Kästchen „Alle übernehmen“. „Nach Teil“ zeigt jeden Vorschlag einzeln mit Weg von → nach.', target: () => q('.umlist .groups') },
      { say: 'Danach öffnet sich „Zu BrickLink übertragen“: Der Knopf kopiert den Bestand und öffnet BrickLink, dort fügst du ihn unten beim Upload ein. Erst dann ist auch der Shop aktuell.', target: () => q('.bl-export') ?? q('.umlist') },
      { say: 'Im Reiter „Lagerplan“ sind Mischkisten rot: G-Kästchen mit mehr als 3 verschiedenen Lots, die noch aufgeteilt werden müssen. „Verlauf“ zeigt, was zuletzt umgelagert wurde.', target: () => q('.umlist .tabs') },
      { say: 'Willst du etwas umbuchen, das nicht vorgeschlagen wird? Reiter „Manuell umbuchen“: Lot suchen, Ziel wählen, Menge eintragen, fertig.', target: () => q('.umlist .t-manuell') },
      end('Unten unter „Zuletzt umgelagert“ siehst du, wer zuletzt was bewegt hat. Viel Spaß beim Aufräumen!'),
    ],
  },
  inventur: {
    title: 'Inventur',
    steps: [
      { say: 'Bei der Inventur zählst du nach, ob im Kästchen wirklich so viel liegt, wie die App glaubt.', target: head },
      { say: 'Oben rechts wählst du einen Lagerort und startest eine neue Inventur.', target: () => q('main .page-head .toolbar') },
      { say: '„Heute zählen“: die Kästchen des Tages, gleich für alle. Zuerst die, bei denen in der Pickliste ein Problem gemeldet wurde, dann nie oder lange nicht gezählte.', target: () => q('main section.today') },
      { say: 'Hier stehen alle Inventuren: offene zum Weiterzählen und fertige zum Nachschauen. Antippen öffnet sie.', target: () => q('main .split section.panel') },
      { say: 'In einer offenen Inventur trägst du bei jedem Teil die gezählte Menge ein. „Soll“ ist, was laut App da sein müsste, „Diff.“ die Abweichung.', target: () => panel('Inventur') },
      end('Mit „Abschließen“ wird der Bestand auf deine gezählten Mengen korrigiert. Danach öffnet sich „Zu BrickLink übertragen“, damit die Mengen auch im Shop stimmen. Zähl lieber zweimal!'),
    ],
  },
  analyse: {
    title: 'Analyse',
    steps: [
      { say: 'Die Analyse ist das Zahlen-Dashboard: Wie sieht unser Lager gerade aus?', target: head },
      { say: '„Bestand nach Kategorie“ zeigt, wovon wir am meisten haben. Oben rechts wechselst du zwischen Teilen, Lagerwert und Lots. Ein Balken öffnet die Kategorie im Inventar.', target: () => panel('Bestand nach Kategorie') },
      { say: '„Volle Regalplätze“ und „Mischkisten“ zeigen, wo es eng wird oder noch aufgeräumt werden muss. Antippen öffnet das Kästchen.', target: () => panel('Volle Regalplätze') },
      { say: '„Aufträge nach Status“ führt dich direkt in die Auftragszentrale, zum Beispiel zu allen bezahlten Bestellungen.', target: () => panel('Aufträge nach Status') },
      end('„Niedriger Bestand“ zeigt Regalplätze mit weniger als 20 Stück. Lehrer sehen hier außerdem, wer wie viel gearbeitet hat.'),
    ],
  },
  auftraege: {
    title: 'Auftragszentrale',
    steps: [
      { say: 'Hier kommen die Bestellungen aus dem Shop rein.', target: head },
      { say: 'Oben filterst du nach Status: aktiv, alle oder ein bestimmter Schritt wie „Bezahlt“ oder „Verpackt“.', target: () => q('main .page-head .toolbar') },
      { say: 'Tippst du einen Auftrag an, öffnet er sich daneben.', target: () => q('main section.panel') },
      { say: 'Die Positionen sind nach Lagerort sortiert, so läufst du beim Picken den kürzesten Weg. Rot heißt: Es liegt zu wenig da.', target: () => panel('Auftrag') },
      end('Ist ein Schritt erledigt, schiebst du den Auftrag mit „Weiter zu …“ in den nächsten Status.'),
    ],
  },
  scanner: {
    title: 'Teile-Scanner',
    steps: [
      { say: 'Du hast ein Teil in der Hand und weißt nicht, was es ist? Der Teile-Scanner erkennt es per Foto.', target: head },
      { say: 'Leg das Teil einzeln auf eine helle, ruhige Fläche. Dann „Kamera starten“ und „Erkennen“, oder „Foto wählen“, wenn du lieber die Kamera-App nimmst.', target: () => panel('Foto') },
      { say: 'Ist es ein Teil, öffnet sich sofort die Teile-Seite: welche Farben wir haben und in welchem Kästchen es liegt.', target: () => panel('Ergebnis') },
      end('Lag die Erkennung daneben? Auf der Teile-Seite stehen unten andere Möglichkeiten zum Antippen.'),
    ],
  },
  teil: {
    title: 'Teile-Seite',
    steps: [
      { say: 'Die Teile-Seite zeigt alles zu einer Teil-Nummer.', target: head },
      { say: 'Oben die Kennzahlen: Bestand, Anzahl Farben, Wert und wie viel schon in offenen Aufträgen reserviert ist.', target: () => q('main .stats') },
      { say: 'Hier siehst du jede Farbe mit ihrem Lagerort. Mit „Umlagern“ bringst du ein Lot direkt woandershin.', target: () => panel('Bestand nach Farbe') },
      end('Weiter unten: Aufträge mit diesem Teil und die letzten Umlagerungen. Lagerorte und Aufträge kannst du antippen.'),
    ],
  },
  lagerort: {
    title: 'Lagerort',
    steps: [
      { say: 'Das ist ein einzelnes Kästchen im Regal. Mit den Pfeilen oben blätterst du zum Nachbarfach.', target: head },
      { say: 'Hier liegt drin, was in diesem Kästchen ist, mit Menge und Wert. Bei G sind höchstens 3 Lots erlaubt, sonst ist es eine Mischkiste.', target: () => panel('Inhalt') },
      { say: 'Gehört etwas woandershin oder soll etwas hierher, steht das unter „Umlagerungsvorschläge“.', target: () => panel('Umlagerungsvorschläge') },
      end('Über „Inventur starten“ zählst du genau dieses Kästchen nach.'),
    ],
  },
  suche: {
    title: 'Suche',
    steps: [
      { say: 'Die Suche findet Teil-Nummern, Namen, Farben, Kategorien, Lagerorte und Aufträge. Rechts im Feld startet das Scan-Symbol den Teile-Scanner.', target: () => q('main form.search') },
      end('Die Treffer sind nach Lagerorten, Teilen und Aufträgen sortiert. Antippen öffnet die passende Seite.'),
    ],
  },
  modul: {
    title: 'Externes Programm',
    steps: [
      end('Dieser Bereich ist ein eigenes Programm der Schule. Er wird gerade ins Dashboard eingebunden, dann erkläre ich ihn dir hier.'),
    ],
  },
}

const byPath = [
  [/^\/$/, 'start'],
  [/^\/inventar/, 'inventar'], [/^\/umlagern/, 'umlagern'], [/^\/inventur/, 'inventur'], [/^\/analyse/, 'analyse'],
  [/^\/auftraege/, 'auftraege'], [/^\/scanner/, 'scanner'], [/^\/teil\//, 'teil'], [/^\/lagerort\//, 'lagerort'],
  [/^\/suche/, 'suche'], [/^\/modul\//, 'modul'],
]

/** Hilfe-Thema für eine Seite (oder null, dann gibt es kein „?“). */
export function helpTopic(path) {
  const hit = byPath.find(([re]) => re.test(path))
  return hit ? hit[1] : null
}

/** Schritte für das Hilfe-Fenster, Kapitel = Name des Bereichs. */
export const helpSteps = (id) => topics[id].steps.map((s) => ({ wait: 'next', ...s, chapter: topics[id].title }))

/** Rundführung (Tutorial mit Übungsdaten): auf dem Schulserver tutorial.html neben der App (package-dist.mjs),
 * in der Demo die Datei aus dem Repo. */
export const tutorialUrl = import.meta.env.MODE === 'demo'
  ? 'https://raw.githack.com/jimrocko/TN-Dashboard/claude/charming-einstein-8qzsen/TN-Dashboard-Tutorial.html'
  : 'tutorial.html'

/** Gerade offene Hilfe (App.vue zeigt dann Klemmi). */
export const help = reactive({ topic: null, run: 0 })
export function openHelp(topic) { help.topic = topic; help.run++ }
export function closeHelp() { help.topic = null }
