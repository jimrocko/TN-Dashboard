// Die Schritte des Tutorials. Klemmi spricht `say`, hebt `target` hervor und wartet auf `wait`:
//   'next'            → Knopf „Weiter“ (nur erklären)
//   'click'           → Antippen des hervorgehobenen Elements
//   { route: RegExp } → bis die Seite gewechselt ist
//   { api: RegExp }   → bis eine Aktion gebucht ist (Methode + Route, z. B. „POST transfers“)
// `from`: Der Schritt beginnt auf dieser Seite. Ist man woanders, lotst Klemmi erst dorthin zurück.
// `go`: Notausgang, falls das Ziel nicht auftaucht („Ich finde es nicht“).
// `mood`: Klemmis Haltung (idle, wave, point, cheer).

const visible = (el) => !!el && el.getClientRects().length > 0
const q = (sel) => [...document.querySelectorAll(sel)].find(visible) ?? null
const byText = (sel, text) => [...document.querySelectorAll(sel)].find((el) => visible(el) && el.textContent.includes(text)) ?? null
const panel = (title) => [...document.querySelectorAll('section.panel')].find((s) => s.querySelector('h2')?.textContent.trim().startsWith(title)) ?? null
const tile = (title) => [...document.querySelectorAll('.tile')].find((t) => t.querySelector('h2')?.textContent.trim() === title) ?? null

export const homeTab = () => q('.bottom .tab')

export const steps = [
  {
    say: 'Hi! Ich bin Klemmi, offizielle Minifigur dieses Lagers. Ich zeig dir in ein paar Minuten alles, was du im TN Dashboard brauchst. Keine Sorge: Hier ist alles Spielgeld und Spielsteine, du kannst nichts kaputt machen. Außer meine Frisur. Die ist aber eh aus Plastik.',
    wait: 'next', mood: 'wave',
  },
  {
    say: 'Erst mal anmelden. In der Schule nimmst du deinen eigenen Zugang, hier leihen wir uns Max aus. Tipp auf „Teilnehmer: max“, dann trag ich die Daten für dich ein.',
    target: () => byText('.accounts .btn', 'Teilnehmer'), wait: 'click', mood: 'point', go: '/login',
  },
  {
    say: 'Perfekt. Jetzt noch auf „Anmelden“. (Psst: Das Passwort von Max ist wirklich schlecht. Mach das bei deinem besser.)',
    target: () => q('form.box button.btn-primary'), wait: { route: /^\/$/ }, mood: 'point', go: '/login',
  },
  {
    say: 'Willkommen auf der Startseite! Jede Kachel ist ein Bereich vom Lager. Die kleine Zeile unten in jeder Kachel verrät dir, was dort gerade los ist.',
    target: () => q('.grid'), wait: 'next', from: '/',
  },
  {
    say: 'Das hier ist die große Suche. Sie findet Teile, Farben, Lagerorte und Aufträge. Tipp mal 3001 ein (das ist der klassische 2×4-Stein, mein Lieblingsstein) und drück Enter.',
    target: () => q('form.search'), wait: { route: /^\/suche/ }, mood: 'point', from: '/',
  },
  {
    say: 'Tadaaa! Alles mit 3001 auf einen Blick. Antippen bringt dich direkt zum Teil oder Lagerort. Merk dir: Die Suche ist dein bester Freund. Nach mir natürlich.',
    target: () => q('main section.panel'), wait: 'next', go: '/suche?q=3001',
  },
  {
    say: 'Ab ins Inventar! Geh erst zurück zur Startseite und tipp dann auf die Kachel „Inventar“.',
    target: () => tile('Inventar'), wait: { route: /^\/inventar/ }, from: '/', mood: 'point',
  },
  {
    say: 'Das ist das Inventar: Jede Zeile ist ein Los, also ein Teil in einer Farbe an einem Lagerort. Ganz schön viele Steine … Tipp oben auf „Lagerplan“, dann zeig ich dir unser Lagerzimmer.',
    target: () => byText('.btn', 'Lagerplan'), wait: 'click', mood: 'point', go: '/inventar',
  },
  {
    say: 'So sieht unser Lagerzimmer von oben aus. Links an der Wand steht G (gebrauchte Teile), rechts H und N (neue Teile), unten die Figurenteile und Plates. Tipp auf G!',
    target: () => q('.zone.z-G'), wait: 'click', mood: 'point', go: '/inventar',
  },
  {
    say: 'G hat 900 Kästchen, deshalb sind sie in Hunderter-Blöcke aufgeteilt. Such dir einen Block aus.',
    target: () => q('.blk:not(.empty)'), wait: 'click', mood: 'point', go: '/inventar',
  },
  {
    say: 'Jedes Feld ist ein Kästchen im Regal. Tipp auf eins und wir schauen rein. Ich hoffe, da liegt kein Kaugummi drin.',
    target: () => q('.cells'), wait: { route: /^\/lagerort\// }, mood: 'point', go: '/inventar',
  },
  {
    say: 'Hier siehst du, was im Kästchen liegt. Bei G gilt: höchstens 3 verschiedene Lose pro Kästchen. Sind es mehr, ist es eine Mischkiste und gehört aufgeteilt. Später kannst du hier ein Teil antippen und siehst, wo es sonst noch liegt.',
    target: () => panel('Inhalt'), wait: 'next', go: '/lagerort/G101',
  },
  {
    say: 'Jetzt wird’s ernst: Wir ändern den Bestand! Dafür zählen wir nach, das nennt sich Inventur. Tipp auf „Inventur starten“.',
    target: () => byText('.btn', 'Inventur starten') ?? byText('.btn', 'Offene Inventur öffnen'), wait: { route: /^\/inventur/ }, mood: 'point', go: '/lagerort/G101',
  },
  {
    say: 'Links steht, wie viele Teile laut Computer im Kästchen sein sollen. Trag bei einem Teil ein, wie viele du gezählt hast. Hier darfst du ruhig flunkern, ich verrat’s keinem. Danach Enter drücken oder daneben tippen.',
    target: () => q('input.count'), wait: { api: /^POST stocktakes\/\d+\/count$/ }, mood: 'point',
  },
  {
    say: 'Gezählt! Mit „Abschließen“ wird der Bestand korrigiert. Wie Zaubern, nur mit Mathe.',
    target: () => byText('.btn', 'Abschließen'), wait: { api: /^POST stocktakes\/\d+\/close$/ }, mood: 'point',
  },
  {
    say: 'Erledigt! Jede Abweichung wurde als Korrektur gebucht. Und weil alles protokolliert wird, weiß dein Lehrer später auch, wer so fleißig war.',
    target: () => q('p.success') ?? q('main section.panel'), wait: 'next', mood: 'cheer',
  },
  {
    say: 'Weiter geht’s mit Umlagern, meinem Lieblingssport. Zurück zur Startseite und dann auf „Umlagern“.',
    target: () => tile('Umlagern'), wait: { route: /^\/umlagern/ }, from: '/', mood: 'point',
  },
  {
    say: 'Die App findet von selbst drei Sorten Unordnung: falsch einsortierte Teile, gleiche Teile in anderen Farben und Teile derselben Art (Bricks zu Bricks, Plates zu Plates). Umgelagert wird immer nur im selben Bereich, gebraucht bleibt gebraucht.',
    target: () => panel('Vorschläge')?.querySelector('.panel-head') ?? null, wait: 'next', go: '/umlagern',
  },
  {
    say: 'Nimm gleich den ersten Vorschlag und tipp auf „Übernehmen“. In echt holst du dann die Teile aus dem einen Kästchen und legst sie ins andere.',
    target: () => panel('Vorschläge')?.querySelector('tbody .btn') ?? null, wait: { api: /^POST transfers$/ }, mood: 'point', go: '/umlagern',
  },
  {
    say: 'Gebucht! Weiter unten gibt’s den Lagerplan auch hier. Rot markierte Kästchen sind Mischkisten, die noch aufgeteilt werden müssen. Falls dir mal langweilig ist …',
    target: () => panel('Lagerplan'), wait: 'next', mood: 'idle', go: '/umlagern',
  },
  {
    say: 'Jetzt die Aufträge, da kommt das Geld rein (Spielgeld, leider). Zurück zur Startseite und dann auf „Auftragszentrale“.',
    target: () => tile('Auftragszentrale'), wait: { route: /^\/auftraege/ }, from: '/', mood: 'point',
  },
  {
    say: 'Das sind die offenen Bestellungen. Tipp einen Auftrag an.',
    target: () => q('main section.panel tbody tr.clickable'), wait: 'click', mood: 'point', go: '/auftraege',
  },
  {
    say: 'Hier siehst du, welche Teile gepackt werden müssen und wo sie liegen. Ist ein Schritt fertig, schiebst du den Auftrag weiter. Tipp auf „Weiter zu …“.',
    target: () => byText('.btn', 'Weiter zu'), wait: { api: /^POST orders\/\d+\/status$/ }, mood: 'point', go: '/auftraege',
  },
  {
    say: 'Läuft! Jetzt noch die Analyse, das ist das Zahlen-Dashboard. Zurück zur Startseite und auf „Lager- & Auftragsanalyse“.',
    target: () => tile('Lager- & Auftragsanalyse'), wait: { route: /^\/analyse/ }, from: '/', mood: 'point',
  },
  {
    say: 'Hier siehst du, welche Kategorien wir haben, welche Fächer voll sind, wo der Bestand knapp wird und wo noch Mischkisten warten. Ideal, um schlau auszusehen.',
    target: () => q('main section.panel'), wait: 'next', go: '/analyse',
  },
  {
    say: 'Fast geschafft! Tipp oben rechts auf deinen Namen.',
    target: () => q('.user .chip'), wait: 'click', mood: 'point',
  },
  {
    say: 'Und jetzt auf „Meine Aktivitäten“.',
    target: () => byText('.menu .item', 'Meine Aktivitäten'), wait: { route: /^\/profil/ }, mood: 'point',
  },
  {
    say: 'Hier steht alles, was du heute gemacht hast: Inventur, Umlagerung, Auftrag. Dein Lehrer sieht diese Liste auch. Also: immer schön ordentlich arbeiten. Ich schau auch zu. 👀',
    target: () => q('main section.panel'), wait: 'next', go: '/profil',
  },
  {
    say: 'Geschafft! Du kennst jetzt Suche, Inventar, Lagerplan, Inventur, Umlagern, Aufträge und Analyse. Den Teile-Scanner (das Kamera-Symbol in der Suche) probierst du am besten im echten Lager aus. Ich bin stolz auf dich! 🎉',
    wait: 'end', mood: 'cheer',
  },
]
