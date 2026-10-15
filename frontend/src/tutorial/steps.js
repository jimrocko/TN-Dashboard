// Die Schritte des Tutorials (Bedienung als Teilnehmer, jeder Bereich der Startseite wird mindestens einmal geöffnet).
// Klemmi spricht `say`, hebt `target` hervor und wartet auf `wait`:
//   'next'            → Knopf „Weiter“ (nur erklären)
//   'click'           → Antippen des hervorgehobenen Elements
//   { route: RegExp } → bis die Seite gewechselt ist
//   { api: RegExp }   → bis eine Aktion gebucht ist (Methode + Route, z. B. „POST transfers“)
//   'end'             → Abschluss mit Konfetti
// `chapter`: Kapitel-Name oben in der Sprechblase (gilt bis zum nächsten Schritt mit `chapter`).
// `from`: Der Schritt beginnt auf dieser Seite. Ist man woanders, lotst Klemmi erst dorthin zurück.
// `go`: Notausgang, falls das Ziel nicht auftaucht („Ich finde es nicht“).
// `hint`: Schild am Pfeil (Standard „Hier tippen!“).
// `mood`: Klemmis Haltung (idle, wave, point, cheer). Bei Tipp-Schritten zeigt er automatisch.

const visible = (el) => !!el && el.getClientRects().length > 0
const q = (sel) => [...document.querySelectorAll(sel)].find(visible) ?? null
const byText = (sel, text) => [...document.querySelectorAll(sel)].find((el) => visible(el) && el.textContent.includes(text)) ?? null
const panel = (title) => [...document.querySelectorAll('section.panel')].find((s) => s.querySelector('h2')?.textContent.trim().startsWith(title)) ?? null
const tile = (title) => [...document.querySelectorAll('.tile')].find((t) => t.querySelector('h2')?.textContent.trim() === title) ?? null

export const homeTab = () => q('.bottom .tab')

const list = [
  // ---------- Start ----------
  {
    chapter: 'Hallo!',
    say: 'Hi! Ich bin Klemmi, offizielle Minifigur dieses Lagers. Ich zeig dir, wie du das TN Dashboard bedienst. Du klickst alles selbst, ich zeige dir nur, wohin. Keine Sorge: Hier ist alles Spielgeld und Spielsteine, du kannst nichts kaputt machen. Außer meine Frisur. Die ist aber eh aus Plastik.',
    wait: 'next', mood: 'wave',
  },
  {
    chapter: 'Anmelden',
    say: 'Erst mal anmelden. In der Schule nimmst du deinen eigenen Zugang, hier leihen wir uns Max aus. Tipp auf „Teilnehmer: max“, dann sind die Daten eingetragen.',
    target: () => byText('.accounts .btn', 'Teilnehmer'), wait: 'click', go: '/login',
  },
  {
    say: 'Perfekt. Jetzt noch auf „Anmelden“. (Psst: Das Passwort von Max ist wirklich schlecht. Mach das bei deinem besser.)',
    target: () => q('form.box button.btn-primary'), wait: { route: /^\/$/ }, go: '/login',
  },
  {
    chapter: 'Startseite',
    say: 'Willkommen auf der Startseite! Jede Kachel ist ein Bereich. Wir öffnen gleich jeden davon mindestens einmal. Die kleine Zeile unten in jeder Kachel verrät dir, was dort gerade los ist.',
    target: () => q('.grid'), wait: 'next', from: '/',
  },
  // ---------- Suche ----------
  {
    chapter: 'Suche',
    say: 'Das hier ist die große Suche. Sie findet Teile, Farben, Lagerorte und Aufträge. Tipp mal 3001 ein (das ist der klassische 2×4-Stein, mein Lieblingsstein) und drück Enter.',
    target: () => q('form.search'), wait: { route: /^\/suche/ }, from: '/', hint: 'Hier eintippen',
  },
  {
    say: 'Tadaaa! Alles mit 3001 auf einen Blick. Antippen bringt dich direkt zum Teil oder Lagerort. Merk dir: Die Suche ist dein bester Freund. Nach mir natürlich.',
    target: () => q('main section.panel'), wait: 'next', go: '/suche?q=3001',
  },
  // ---------- Minifig-Finder ----------
  {
    chapter: 'Minifig-Finder',
    say: 'Erster Bereich: der Minifig-Finder. Zurück zur Startseite und tipp auf die Kachel.',
    target: () => tile('Minifig-Finder'), wait: { route: /^\/modul\/minifig/ }, from: '/',
  },
  {
    say: 'Der Minifig-Finder ist ein eigenes Programm vom Shop, das hier eingebunden wird. Damit findest du jede Figur und ihre Varianten. Sogar meinen Cousin Klaus, und der versteckt sich gern.',
    target: () => q('main .panel'), wait: 'next', go: '/modul/minifig',
  },
  // ---------- Pickliste ----------
  {
    chapter: 'Pickliste',
    say: 'Weiter zur Pickliste: Startseite, dann die Kachel „Pickliste“.',
    target: () => tile('Pickliste'), wait: { route: /^\/modul\/pickliste/ }, from: '/',
  },
  {
    say: 'Mit der Pickliste sammelst du die Teile für Bestellungen zusammen und meldest, wenn etwas fehlt. Auch ein eigenes Programm, das hier angedockt wird. Picken heißt übrigens nicht in der Nase. Nur damit das klar ist.',
    target: () => q('main .panel'), wait: 'next', go: '/modul/pickliste',
  },
  // ---------- Inventar und Lagerplan ----------
  {
    chapter: 'Inventar',
    say: 'Ab ins Inventar! Startseite, dann „Inventar“.',
    target: () => tile('Inventar'), wait: { route: /^\/inventar/ }, from: '/',
  },
  {
    say: 'Das ist das Inventar: Jede Zeile ist ein Los, also ein Teil in einer Farbe an einem Lagerort. Ganz schön viele Steine … Tipp oben auf „Lagerplan“, dann zeig ich dir unser Lagerzimmer.',
    target: () => byText('.btn', 'Lagerplan'), wait: 'click', go: '/inventar',
  },
  {
    say: 'So sieht unser Lagerzimmer von oben aus. Links an der Wand steht G (gebrauchte Teile), rechts H und N (neue Teile), unten die Figurenteile und Plates. Tipp auf G!',
    target: () => q('.zone.z-G'), wait: 'click', go: '/inventar',
  },
  {
    say: 'G hat 900 Kästchen, deshalb sind sie in Hunderter-Blöcke aufgeteilt. Such dir einen Block aus.',
    target: () => q('.blk:not(.empty)'), wait: 'click', go: '/inventar',
  },
  {
    say: 'Jedes Feld ist ein Kästchen im Regal. Tipp auf eins und wir schauen rein. Ich hoffe, da liegt kein Kaugummi drin.',
    target: () => q('.cells'), wait: { route: /^\/lagerort\// }, go: '/inventar',
  },
  {
    chapter: 'Lagerort',
    say: 'Hier siehst du, was im Kästchen liegt. Bei G gilt: höchstens 3 verschiedene Lose pro Kästchen. Sind es mehr, ist es eine Mischkiste und gehört aufgeteilt. Tippst du später ein Teil an, siehst du, wo es sonst noch liegt.',
    target: () => panel('Inhalt'), wait: 'next', go: '/lagerort/G101',
  },
  // ---------- Inventur ----------
  {
    chapter: 'Inventur',
    say: 'Jetzt wird’s ernst: Wir ändern den Bestand! Dafür zählen wir nach, das nennt sich Inventur. Tipp auf „Inventur starten“.',
    target: () => byText('.btn', 'Inventur starten') ?? byText('.btn', 'Offene Inventur öffnen'), wait: { route: /^\/inventur/ }, go: '/lagerort/G101',
  },
  {
    say: '„Soll“ ist, was laut Computer im Kästchen liegen müsste. Trag bei einem Teil ein, wie viele du gezählt hast. Hier darfst du ruhig flunkern, ich verrat’s keinem. Danach Enter drücken oder daneben tippen.',
    target: () => q('input.count'), hint: 'Zahl eintragen', wait: { api: /^POST stocktakes\/\d+\/count$/ },
  },
  {
    say: 'Gezählt! Mit „Abschließen“ wird der Bestand korrigiert. Wie Zaubern, nur mit Mathe.',
    target: () => byText('.btn', 'Abschließen'), wait: { api: /^POST stocktakes\/\d+\/close$/ },
  },
  {
    say: 'Erledigt! Jede Abweichung wurde als Korrektur gebucht. Und weil alles protokolliert wird, weiß dein Lehrer später auch, wer so fleißig war.',
    target: () => q('p.success') ?? q('main section.panel'), wait: 'next', mood: 'cheer',
  },
  // ---------- Teile-Scanner ----------
  {
    chapter: 'Teile-Scanner',
    say: 'Du hast ein Teil in der Hand und keine Ahnung, was das ist? Dafür gibt’s den Teile-Scanner. Startseite, dann „Teile-Scanner“.',
    target: () => tile('Teile-Scanner'), wait: { route: /^\/scanner/ }, from: '/',
  },
  {
    say: 'Im Lager hältst du das Teil vor die Kamera oder wählst ein Foto, und die App erkennt es. Hier in der Übung gibt’s keine Kamera, deshalb schummeln wir: Tipp auf „Demo: Erkennung ohne Foto testen“.',
    target: () => byText('.btn', 'Erkennung ohne Foto'), wait: { route: /^\/teil\// }, go: '/scanner',
  },
  {
    chapter: 'Teile-Seite',
    say: 'Erkannt! Die Teile-Seite zeigt dir, in welchen Farben wir das Teil haben und in welchem Kästchen es liegt. So weißt du sofort, wohin es gehört.',
    target: () => panel('Bestand nach Farbe') ?? q('main section.panel'), wait: 'next',
  },
  // ---------- Umlagern ----------
  {
    chapter: 'Umlagern',
    say: 'Weiter geht’s mit Umlagern, meinem Lieblingssport. Startseite, dann „Umlagern“.',
    target: () => tile('Umlagern'), wait: { route: /^\/umlagern/ }, from: '/',
  },
  {
    say: 'Die App findet von selbst drei Sorten Unordnung: falsch einsortierte Teile, gleiche Teile in anderen Farben und Teile derselben Art (Bricks zu Bricks, Plates zu Plates). Umgelagert wird immer nur im selben Bereich, gebraucht bleibt gebraucht.',
    target: () => panel('Vorschläge')?.querySelector('.panel-head') ?? null, wait: 'next', go: '/umlagern',
  },
  {
    say: 'Nimm gleich den ersten Vorschlag und tipp auf „Übernehmen“. In echt holst du dann die Teile aus dem einen Kästchen und legst sie ins andere.',
    target: () => panel('Vorschläge')?.querySelector('tbody .btn') ?? null, wait: { api: /^POST transfers$/ }, go: '/umlagern',
  },
  {
    say: 'Gebucht! Weiter unten gibt’s den Lagerplan auch hier. Rot markierte Kästchen sind Mischkisten, die noch aufgeteilt werden müssen. Falls dir mal langweilig ist …',
    target: () => panel('Lagerplan'), wait: 'next', go: '/umlagern',
  },
  // ---------- Aufträge ----------
  {
    chapter: 'Aufträge',
    say: 'Jetzt die Aufträge, da kommt das Geld rein (Spielgeld, leider). Startseite, dann „Auftragszentrale“.',
    target: () => tile('Auftragszentrale'), wait: { route: /^\/auftraege/ }, from: '/',
  },
  {
    say: 'Das sind die offenen Bestellungen. Tipp einen Auftrag an.',
    target: () => q('main section.panel tbody tr.clickable'), wait: 'click', go: '/auftraege',
  },
  {
    say: 'Hier siehst du, welche Teile gepackt werden müssen und wo sie liegen. Ist ein Schritt fertig, schiebst du den Auftrag weiter. Tipp auf „Weiter zu …“.',
    target: () => byText('.btn', 'Weiter zu'), wait: { api: /^POST orders\/\d+\/status$/ }, go: '/auftraege',
  },
  // ---------- Inventur-Übersicht ----------
  {
    chapter: 'Inventur & Bestandsprüfung',
    say: 'Die Inventur kennst du schon von vorhin. Über die Kachel kommst du auch direkt hin. Startseite, dann „Inventur & Bestandsprüfung“.',
    target: () => tile('Inventur & Bestandsprüfung'), wait: { route: /^\/inventur/ }, from: '/',
  },
  {
    say: 'Hier stehen alle Inventuren: offene zum Weiterzählen und fertige zum Nachschauen. Oben rechts startest du eine neue für jedes Kästchen.',
    target: () => q('main section.panel'), wait: 'next', go: '/inventur',
  },
  // ---------- Analyse ----------
  {
    chapter: 'Analyse',
    say: 'Letzter Bereich: die Analyse, das Zahlen-Dashboard. Startseite, dann „Lager- & Auftragsanalyse“.',
    target: () => tile('Lager- & Auftragsanalyse'), wait: { route: /^\/analyse/ }, from: '/',
  },
  {
    say: 'Hier siehst du, welche Kategorien wir haben, welche Fächer voll sind, wo der Bestand knapp wird und wo noch Mischkisten warten. Ideal, um schlau auszusehen.',
    target: () => q('main section.panel'), wait: 'next', go: '/analyse',
  },
  // ---------- Protokoll ----------
  {
    chapter: 'Meine Aktivitäten',
    say: 'Fast geschafft! Tipp oben rechts auf deinen Namen.',
    target: () => q('.user .chip'), wait: 'click',
  },
  {
    say: 'Und jetzt auf „Meine Aktivitäten“.',
    target: () => byText('.menu .item', 'Meine Aktivitäten'), wait: { route: /^\/profil/ },
  },
  {
    say: 'Hier steht alles, was du heute gemacht hast: Inventur, Umlagerung, Auftrag. Dein Lehrer sieht diese Liste auch. Also: immer schön ordentlich arbeiten. Ich schau auch zu. 👀',
    target: () => q('main section.panel'), wait: 'next', go: '/profil',
  },
  {
    chapter: 'Geschafft!',
    say: 'Du hast jeden Bereich mindestens einmal geöffnet: Suche, Minifig-Finder, Pickliste, Inventar mit Lagerplan, Inventur, Teile-Scanner, Umlagern, Aufträge und Analyse. Ich bin stolz auf dich! 🎉',
    wait: 'end', mood: 'cheer',
  },
]

// Kapitel weiterreichen, damit jeder Schritt seins kennt
let current = ''
export const steps = list.map((s) => ({ ...s, chapter: (current = s.chapter ?? current) }))
