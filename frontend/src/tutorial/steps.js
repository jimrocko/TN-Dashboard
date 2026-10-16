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
// Zeile des gerade angelegten Kontos: ein Teilnehmer mit Papierkorb, der nicht zu den Demo-Konten max und erika gehört
const newestUserRow = () => [...document.querySelectorAll('tr.clickable')].filter((tr) => visible(tr) && tr.querySelector('.delbtn, .confirm'))
  .find((tr) => !/\b(max|erika)\b/.test(tr.querySelector('.mono')?.textContent ?? '')) ?? null

// Gemeinsamer Anfang. Der Schritt `wait: 'choose'` fragt mitten im Bild: Lehrer oder Schüler?
const intro = [
  {
    chapter: 'Hallo!',
    say: 'Hi! Ich bin Klemmi, offizielle Minifigur dieses Lagers. Ich zeig dir, wie du das TN Dashboard bedienst. Du klickst alles selbst, ich zeige dir nur, wohin. Keine Sorge: Hier ist alles nur zum Üben, du kannst nichts kaputt machen.',
    wait: 'next', mood: 'wave',
  },
  { chapter: 'Anmelden', wait: 'choose', say: '' },
]

// ---------- Schüler-Tutorial: Bedienung, jeder Bereich der Startseite wird mindestens einmal geöffnet ----------
const studentList = [
  {
    chapter: 'Anmelden',
    say: 'Schüler, alles klar! In der Schule nimmst du deinen eigenen Zugang, hier leihen wir uns Max aus. Die Daten hab ich schon eingetragen. Tipp auf „Anmelden“.',
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
    say: 'Tadaaa! Alles mit 3001 auf einen Blick. Antippen bringt dich direkt zum Teil oder Lagerort. Merk dir: Die Suche ist dein bester Freund.',
    target: () => q('main section.panel'), wait: 'next', go: '/suche?q=3001',
  },
  // ---------- Minifig-Finder ----------
  {
    chapter: 'Minifig-Finder',
    say: 'Erster Bereich: der Minifig-Finder. Zurück zur Startseite und tipp auf die Kachel.',
    target: () => tile('Minifig-Finder'), wait: { route: /^\/minifig/ }, from: '/',
  },
  {
    say: 'Der Minifig-Finder schaut, welche Minifiguren sich aus losen Teilen in unserem Lager zusammenbauen lassen. Grün heißt: sofort baubar. Gelb heißt: Es fehlt nur noch ein Teil.',
    target: () => q('main .kpis + .kpis') ?? q('main .kpis'), wait: 'next', go: '/minifig',
  },
  {
    say: 'Der gemeinsame Bauplan verteilt die Teile so, dass keins doppelt verplant wird. Tipp bei einer Figur auf „Teile & Lagerorte“.',
    target: () => panel('Gemeinsamer Bauplan')?.querySelector('summary'), wait: 'click', go: '/minifig',
  },
  {
    say: 'Hier steht jedes Teil mit dem Kästchen, in dem es liegt. Das Kästchen kannst du antippen und direkt hingehen. Zusammenbauen, fertig!',
    target: () => panel('Gemeinsamer Bauplan')?.querySelector('details[open] .parts'), wait: 'next', go: '/minifig', mood: 'cheer',
  },
  // ---------- Pickliste ----------
  {
    chapter: 'Pickliste',
    say: 'Weiter zur Pickliste: Startseite, dann die Kachel „Pickliste“.',
    target: () => tile('Pickliste'), wait: { route: /^\/pickliste/ }, from: '/',
  },
  {
    say: 'Das sind die offenen Bestellungen. Mit der Pickliste sammelst du die Teile zusammen. Tipp auf die erste Bestellung.',
    target: () => q('.order'), wait: 'click', go: '/pickliste',
  },
  {
    say: 'Jede Zeile ist ein Teil: rechts das Kästchen und die Menge. Hast du die Teile in der Hand, tipp auf die Zeile. Probier es aus!',
    target: () => q('.row:not(.picked)'), wait: { api: /^POST picking\/items\/\d+\/toggle$/ }, go: '/pickliste',
  },
  {
    say: 'Durchgestrichen heißt: gepickt, mit deinem Namen dran. Fehlt etwas oder stimmt der Lagerort nicht, tippst du auf das Ausrufezeichen und meldest es. Sind alle Zeilen gepickt, schließt du die Bestellung ab.',
    target: () => q('.row.picked') ?? q('.rows'), wait: 'next', go: '/pickliste',
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
    say: 'Jedes Feld ist ein Kästchen im Regal. Tipp auf eins und wir schauen rein.',
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
    say: '„Soll“ ist, was laut Computer im Kästchen liegen müsste. Trag bei einem Teil ein, wie viele du gezählt hast. In der Übung ist jede Zahl okay. Danach Enter drücken oder daneben tippen.',
    target: () => q('input.count'), hint: 'Zahl eintragen', wait: { api: /^POST stocktakes\/\d+\/count$/ },
  },
  {
    say: 'Gezählt! Mit „Abschließen“ wird der Bestand korrigiert.',
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
    say: 'Gebucht! Weiter unten gibt’s den Lagerplan auch hier. Rot markierte Kästchen sind Mischkisten, die noch aufgeteilt werden müssen.',
    target: () => panel('Lagerplan'), wait: 'next', go: '/umlagern',
  },
  // ---------- Aufträge ----------
  {
    chapter: 'Aufträge',
    say: 'Jetzt die Aufträge, da kommen die Bestellungen rein. Startseite, dann „Auftragszentrale“.',
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
    say: 'Hier siehst du, welche Kategorien wir haben, welche Fächer voll sind, wo der Bestand knapp wird und wo noch Mischkisten warten.',
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
    say: 'Hier steht alles, was du heute gemacht hast: Inventur, Umlagerung, Auftrag. Dein Lehrer sieht diese Liste auch. Also: immer schön ordentlich arbeiten.',
    target: () => q('main section.panel'), wait: 'next', go: '/profil',
  },
  {
    chapter: 'Geschafft!',
    say: 'Du hast jeden Bereich mindestens einmal geöffnet: Suche, Minifig-Finder, Pickliste, Inventar mit Lagerplan, Inventur, Teile-Scanner, Umlagern, Aufträge und Analyse. Ich bin stolz auf dich! 🎉',
    wait: 'end', mood: 'cheer',
  },
]


// ---------- Lehrer-Tutorial: Lehrer-Ansicht, Schüler, Dev-Bereich und Einstellungen im Detail; Listen nur kurz ----------
const teacherList = [
  {
    chapter: 'Anmelden',
    say: 'Lehrer, sehr gut! Dann zeige ich dir alles, was nur Lehrer dürfen. Hier melden wir uns als „Herr Lehrer“ an, die Daten sind schon eingetragen. Tipp auf „Anmelden“.',
    target: () => q('form.box button.btn-primary'), wait: { route: /^\/$/ }, go: '/login',
  },
  {
    chapter: 'Startseite',
    say: 'Das ist dieselbe Startseite wie bei den Schülern. Inventar, Umlagern, Aufträge und so weiter funktionieren für dich genauso, die Listen kennst du schnell selbst. Spannend wird es bei den Sachen, die nur du siehst. Los geht’s!',
    target: () => q('.grid'), wait: 'next', from: '/',
  },
  // Lehrer-Ansicht
  {
    chapter: 'Lehrer-Ansicht',
    say: 'Tipp oben rechts auf deinen Namen. Dort versteckt sich die Lehrer-Ansicht.',
    target: () => q('.user .chip'), wait: 'click',
  },
  {
    say: 'Und jetzt auf „Lehrer-Ansicht“.',
    target: () => byText('.menu .item', 'Lehrer-Ansicht'), wait: { route: /^\/lehrer/ },
  },
  {
    say: 'Das ist deine Kommandozentrale. Links stehen alle Konten mit Rolle, wann sie zuletzt aktiv waren und wie viele Aktionen sie gemacht haben. Anmelden und Abmelden zählen dabei nicht mit, nur echte Arbeit.',
    target: () => panel('Teilnehmer'), wait: 'next', go: '/lehrer',
  },
  {
    say: 'Tipp auf Max Mustermann, dann siehst du nur sein Protokoll.',
    target: () => byText('tr.clickable', 'Max Mustermann'), wait: 'click', go: '/lehrer',
  },
  {
    say: 'Rechts steht jetzt alles, was Max gemacht hat: wann, was, in welchem Bereich, mit Details wie Teil, Menge und Lagerort. Jede Umlagerung, jede Inventur, jeder Auftragsschritt.',
    target: () => panel('Protokoll'), wait: 'next', go: '/lehrer',
  },
  {
    say: 'Mit „CSV“ lädst du das Protokoll als Tabelle herunter, zum Beispiel für Excel oder für die Notenbesprechung. „Alle anzeigen“ oben in der Liste zeigt wieder alle zusammen.',
    target: () => panel('Protokoll')?.querySelector('.panel-head .btn') ?? null, wait: 'next', go: '/lehrer',
  },
  // Schüler verwalten
  {
    chapter: 'Schüler anlegen',
    say: 'Neue Schüler legst du hier an: Benutzername (damit melden sie sich an), Anzeigename (den siehst du im Protokoll) und ein Startpasswort mit mindestens 6 Zeichen. Probier’s aus und tipp dann auf „Anlegen“.',
    target: () => panel('Teilnehmer anlegen'), wait: { api: /^POST users$/ }, hint: 'Hier ausfüllen', go: '/lehrer',
  },
  {
    say: 'Angelegt! Das neue Konto steht jetzt in der Liste und kann sich sofort anmelden. Sag dem Schüler sein Startpasswort.',
    target: () => panel('Teilnehmer'), wait: 'next', mood: 'cheer', go: '/lehrer',
  },
  {
    chapter: 'Schüler löschen',
    say: 'Verlässt jemand den Kurs, löschst du sein Konto mit dem Papierkorb ganz rechts. Probier es mit dem Konto, das du gerade angelegt hast: Papierkorb und dann „Ja“. Gelöschte Konten können sich nicht mehr anmelden, ihr Protokoll bleibt aber erhalten.',
    target: () => newestUserRow(), hint: 'Papierkorb, dann „Ja“', wait: { api: /^POST users\/\d+\/delete$/ }, go: '/lehrer',
  },
  {
    chapter: 'Dev-Rolle',
    say: 'Ganz rechts ist die Spalte „Dev“. Lehrer haben immer Zugang zum Dev-Bereich. Schülern kannst du ihn einzeln geben, zum Beispiel deinem Technik-Profi. Tipp bei Max auf „aus“, um ihm die Dev-Rolle zu geben.',
    target: () => byText('tr.clickable', 'Max Mustermann')?.querySelector('.devbtn') ?? null, wait: { api: /^POST users\/\d+\/dev$/ }, go: '/lehrer',
  },
  {
    say: 'Max hat jetzt die Dev-Rolle und sieht ab der nächsten Anmeldung unten den Knopf „Dev-Bereich“. Einen extra Code braucht er nicht, er ist ja schon mit seinem Passwort angemeldet. Mit einem weiteren Tipp nimmst du die Rolle wieder weg.',
    target: () => byText('tr.clickable', 'Max Mustermann'), wait: 'next', go: '/lehrer',
  },
  // Analyse: Aktivität pro Teilnehmer (nur Lehrer)
  {
    chapter: 'Analyse',
    say: 'In der Analyse gibt’s für dich eine Extra-Auswertung. Startseite, dann „Lager- & Auftragsanalyse“.',
    target: () => tile('Lager- & Auftragsanalyse'), wait: { route: /^\/analyse/ }, from: '/',
  },
  {
    say: '„Aktivität pro Teilnehmer“ sehen nur Lehrer: wer wie viel gearbeitet hat, auf einen Blick.',
    target: () => panel('Aktivität pro Teilnehmer'), wait: 'next', go: '/analyse',
  },
  // Dev-Bereich
  {
    chapter: 'Dev-Bereich',
    say: 'Jetzt der Maschinenraum: der Dev-Bereich. Tipp unten auf „Dev-Bereich“.',
    target: () => byText('.bottom .tab', 'Dev-Bereich'), wait: { route: /^\/dev/ },
  },
  {
    say: 'Hier landen nur Lehrer und Schüler mit Dev-Rolle, alle anderen sehen den Knopf gar nicht. Oben sind fünf Reiter: System, Tests, Daten, API und Einstellungen. Ich zeig dir jeden.',
    target: () => q('main .seg'), wait: 'next', go: '/dev',
  },
  {
    say: '„System“ zeigt App-Version, Datenbank, PHP und welche Werte aus der .env der Schule gelesen werden. Passwörter stehen hier nie im Klartext. Wenn mal etwas hakt, schaust du zuerst hier.',
    target: () => q('main .seg ~ *') ?? q('main section.panel'), wait: 'next', go: '/dev',
  },
  {
    say: 'Weiter zu „Tests“.',
    target: () => byText('main .seg button', 'Tests'), wait: 'click', go: '/dev',
  },
  {
    say: 'Die App kann sich selbst prüfen: Datenbank lesen und schreiben, Sitzung, Speicher und alle Bereiche (nur lesend). Tipp auf „Alle Tests starten“.',
    target: () => byText('.btn', 'Alle Tests starten'), wait: 'click', go: '/dev',
  },
  {
    say: 'Grün heißt alles gut. BrickLink und die Teileerkennung brauchen Internet und den Schulserver, in dieser Übung dürfen die also rot sein. Auf dem echten Server sollte alles grün werden.',
    target: () => q('main section.panel'), wait: 'next', go: '/dev',
  },
  {
    say: 'Jetzt „Daten“.',
    target: () => byText('main .seg button', 'Daten'), wait: 'click', go: '/dev',
  },
  {
    say: 'Hier siehst du jede Tabelle der Datenbank, Seite für Seite. Nur lesen, nichts bearbeiten, und Passwörter bleiben versteckt. Praktisch, wenn du wissen willst, was wirklich gespeichert ist.',
    target: () => panel('Tabellen') ?? q('main section.panel'), wait: 'next', go: '/dev',
  },
  {
    say: 'Weiter zu „API“.',
    target: () => byText('main .seg button', 'API'), wait: 'click', go: '/dev',
  },
  {
    say: 'Die API-Konsole schickt einzelne Anfragen an den Server und zeigt die Antwort. Weiter unten rufst du BrickLink-Artikel ab: Das Ergebnis wird in der Datenbank gespeichert und danach von dort gelesen. Das spart Kosten und API-Limits.',
    target: () => panel('API-Konsole') ?? q('main section.panel'), wait: 'next', go: '/dev',
  },
  {
    say: 'Und zum Schluss „Einstellungen“.',
    target: () => byText('main .seg button', 'Einstellungen'), wait: 'click', go: '/dev',
  },
  {
    say: '„Debug-Hilfen“ gelten nur für dieses Gerät: eine kleine Info-Leiste, ein künstlich langsames Netz zum Testen und ein Mitschnitt der API-Aufrufe. Bei „Lokale Daten“ leerst du den App-Cache, falls ein iPad eine alte Version festhält.',
    target: () => panel('Debug-Hilfen'), wait: 'next', go: '/dev',
  },
  // Einstellungen der App
  {
    chapter: 'Einstellungen',
    say: 'Zum Schluss die normalen Einstellungen: Tipp unten auf „Einstellungen“.',
    target: () => byText('.bottom .tab', 'Einstellungen'), wait: { route: /^\/einstellungen/ },
  },
  {
    say: 'Hier stellt jede Person für ihr eigenes Gerät ein, wie die App aussieht: hell oder dunkel, Schriftgröße, Abstände und weniger Bewegung. Weiter unten stehen dein Konto und der Stand der Verbindungen.',
    target: () => panel('Darstellung'), wait: 'next', go: '/einstellungen',
  },
  {
    chapter: 'Geschafft!',
    say: 'Fertig! Du kennst jetzt die Lehrer-Ansicht mit Protokoll und CSV, kannst Schüler anlegen, löschen und die Dev-Rolle vergeben und kennst alle fünf Reiter im Dev-Bereich. Das Lager ist in guten Händen. 🎉',
    wait: 'end', mood: 'cheer',
  },
]

// Kapitel weiterreichen, damit jeder Schritt seins kennt
function withChapters(list) {
  let current = ''
  return list.map((s) => ({ ...s, chapter: (current = s.chapter ?? current) }))
}
export const introSteps = withChapters(intro)
export const tracks = {
  schueler: withChapters(studentList),
  lehrer: withChapters(teacherList),
}
// Konto, das nach der Wahl eingetragen wird (Knopf auf der Anmeldeseite der Demo)
export const accountButton = (track) => byText('.accounts .btn', track === 'lehrer' ? 'Lehrer' : 'Teilnehmer')
