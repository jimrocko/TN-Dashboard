// Verlauf in ganzen Sätzen (Wunsch des Inhabers, Sitzung 8: „mein Lehrer versteht nix“).
// describe(eintrag) → { text, kind, minor }: text = Satz ohne Namen („hat 3× 3001 von G101 nach G205 umgelagert“),
// kind = Bereich für Symbol/Farbe, minor = Kleinkram (Anmelden, Bereich geöffnet, Suche), der unter „Wichtiges“ ausgeblendet wird.
import { moduleById } from '../modules'

const yes = (v) => v === true || v === 1 || v === '1' || v === 'true'
const n = (v) => (v === undefined || v === null || v === '' ? '' : v)
const area = (id) => (id === 'suche' ? 'die Suche' : moduleById(id)?.title ?? id ?? 'einen Bereich')
const STATUS = { Bezahlt: 'bezahlt', Verpackt: 'verpackt', Versendet: 'versendet', Offen: 'offen' }
const REASON = { missing: 'Teil fehlt', wrong_quantity: 'Menge stimmt nicht', wrong_location: 'Lagerort stimmt nicht', damaged: 'Teil beschädigt', other: 'Sonstiges' }
const KEYS = { teil: 'Teil', menge: 'Menge', lagerort: 'Kästchen', von: 'von', nach: 'nach', auftrag: 'Auftrag', figur: 'Figur', datei: 'Datei', lose: 'Lots', lots: 'Lots', teile: 'Teile', neu: 'neu', entfernt: 'entfernt', korrekturen: 'Korrekturen', quelle: 'Quelle', anzahl: 'Anzahl', username: 'Konto' }

const T = {
  login: () => ['hat sich angemeldet', 'konto', true],
  logout: () => ['hat sich abgemeldet', 'konto', true],
  open: (d, r) => [`hat ${area(r.module)} geöffnet`, r.module, true],
  search: (d) => [`hat nach „${n(d.q)}“ gesucht`, 'suche', true],
  transfer: (d) => [d.teil ? `hat ${n(d.menge)}× ${d.teil} von ${n(d.von)} nach ${n(d.nach)} umgelagert` : 'hat ein Lot umgelagert', 'umlagern'],
  order_status: (d) => [`hat ${d.auftrag ? `Auftrag ${d.auftrag}` : 'einen Auftrag'} auf „${STATUS[d.status] ?? n(d.status)}“ gesetzt`, 'auftraege'],
  stocktake_created: (d) => [`hat in Kästchen ${n(d.lagerort)} eine Inventur begonnen`, 'inventur'],
  stocktake_closed: (d) => [d.lagerort ? `hat die Inventur in Kästchen ${d.lagerort} abgeschlossen` + (Number(d.korrekturen) ? ` (${d.korrekturen} Korrektur${Number(d.korrekturen) === 1 ? '' : 'en'})` : ' (alles stimmte)') : 'hat eine Inventur abgeschlossen', 'inventur'],
  count_daily_size: (d) => [`hat „Heute zählen“ auf ${n(d.anzahl)} Kästchen pro Tag gestellt`, 'inventur'],
  recognize: (d) => [d.teil ? `hat Teil ${d.teil}${d.name ? ` (${d.name})` : ''} per Foto erkannt` : 'hat ein Foto prüfen lassen, aber nichts erkannt', 'scanner', true],
  pick_item: (d) => [`hat ${n(d.menge)}× ${n(d.teil)} für Auftrag ${n(d.auftrag)} gepickt`, 'pickliste'],
  pick_item_reset: (d) => [`hat das Picken von ${n(d.teil)} (Auftrag ${n(d.auftrag)}) zurückgenommen`, 'pickliste'],
  pick_claim: (d) => [`hat sich ${n(d.teil)} in Auftrag ${n(d.auftrag)} vorgemerkt`, 'pickliste', true],
  pick_unclaim: (d) => [`hat die Vormerkung für ${n(d.teil)} (Auftrag ${n(d.auftrag)}) aufgehoben`, 'pickliste', true],
  pick_report: (d) => [`hat ein Problem gemeldet: ${REASON[d.grund] ?? n(d.grund)} bei ${n(d.teil)} (Auftrag ${n(d.auftrag)})`, 'pickliste'],
  pick_order_completed: (d) => [`hat Auftrag ${n(d.auftrag)} fertig gepickt`, 'pickliste'],
  pick_order_reopened: (d) => [`hat Auftrag ${n(d.auftrag)} wieder geöffnet`, 'pickliste'],
  pick_location_order: () => ['hat den Laufweg der Pickliste geändert', 'pickliste'],
  pick_reset: () => ['hat die Pick-Testdaten gelöscht', 'pickliste'],
  bricklink_export_start: (d) => [`hat die Übertragung zu BrickLink gestartet (${n(d.umlagerungen) || 0} Umlagerungen, ${n(d.inventuren) || 0} Inventuren)`, 'bricklink'],
  bricklink_export: (d) => [`hat bestätigt: Änderungen sind bei BrickLink eingefügt${d.aenderungen ? ` (${d.aenderungen})` : ''}`, 'bricklink'],
  bricklink_export_verworfen: () => ['hat die Übertragung zu BrickLink verworfen', 'bricklink'],
  bricklink_export_entfernt: (d) => [`hat die offene BrickLink-Übertragung ohne Übertragung entfernt (${n(d.umlagerungen) || 0} Umlagerungen, ${n(d.inventuren) || 0} Inventuren)`, 'bricklink'],
  bricklink_import: (d) => [`hat das Lager aus BrickLink übernommen (${n(d.lose)} Lots)`, 'bricklink'],
  bsx_import: (d) => [`hat den Bestand aus einer BrickStore-Datei ersetzt${d.lots ? ` (${d.lots} Lots)` : ''}`, 'inventar'],
  sync_inventory: (d) => [`hat den Bestand vom Shop geholt (${n(d.lose)} Lots)`, 'inventar'],
  sync_orders: () => ['hat die Aufträge vom Shop geholt', 'auftraege'],
  ollie_import: (d) => [`hat den Bestand aus dem Shop-Dashboard übernommen (${n(d.lose)} Lots)`, 'inventar'],
  ollie_refresh: (d) => [`hat den Bestand bei BrickLink neu geladen (${n(d.lose)} Lots)`, 'inventar'],
  ollie_auto_import: (d) => [`Bestand automatisch abgeglichen (${n(d.lose)} Lots)`, 'inventar', true],
  minifig_build_add: (d) => [`hat ${n(d.menge)}× Figur ${n(d.figur)} fertig gebaut`, 'minifig'],
  minifig_build_delete: (d) => [`hat Figur ${n(d.figur)} wieder aus „Fertige Figuren“ genommen`, 'minifig'],
  minifig_build_lots: (d) => [`hat bei Figur ${n(d.figur)} die Orte der Teile geändert`, 'minifig'],
  minifig_build_export_start: (d) => [`hat die Sammlung „Fertige Figuren“ abgeschlossen (${n(d.figuren)} Einträge)`, 'minifig'],
  minifig_build_export_done: () => ['hat bestätigt: Figuren sind bei BrickLink hochgeladen', 'minifig'],
  minifig_build_export_verworfen: () => ['hat den Abschluss der fertigen Figuren verworfen', 'minifig'],
  minifig_fehlteil: (d) => [`hat ${n(d.menge)}× ${n(d.teil)} in ${n(d.lagerort)} als fehlend abgezogen${yes(d.zaehlen) ? ' und zum Nachzählen vorgemerkt' : ''}`, 'minifig'],
  minifig_decision: (d) => [d.aktion === 'allow' ? `hat Figur ${n(d.figur)} wieder zugelassen` : `hat Figur ${n(d.figur)} auf „Nicht bauen“ gesetzt`, 'minifig'],
  minifig_bsx_add: (d) => [`hat die Datei ${n(d.datei)} in den Minifig-Finder geladen`, 'minifig'],
  minifig_bsx_delete: (d) => [`hat die Datei ${n(d.datei)} aus dem Minifig-Finder gelöscht`, 'minifig'],
  minifig_source: (d) => [`hat die Teile-Quelle im Minifig-Finder auf „${{ bestand: 'nur Lager', beides: 'Lager + Dateien', bsx: 'nur Dateien' }[d.quelle] ?? n(d.quelle)}“ gestellt`, 'minifig'],
  minifig_priority: (d) => [`hat im Minifig-Finder „${d.zuerst === 'bsx' ? 'Dateien zuerst' : 'Lager zuerst'}“ eingestellt`, 'minifig'],
  minifig_catalog_import: () => ['hat den Figurenkatalog eingelesen', 'minifig'],
  minifig_price_import: (d) => [`hat Figurenpreise eingelesen (${n(d.preise)})`, 'minifig'],
  user_created: (d) => [`hat das Konto „${n(d.username)}“ angelegt`, 'konto'],
  user_deleted: (d) => [`hat das Konto „${n(d.username)}“ gelöscht`, 'konto'],
  dev_granted: (d) => [`hat „${n(d.username)}“ Zugang zum Dev-Bereich gegeben`, 'konto'],
  dev_revoked: (d) => [`hat „${n(d.username)}“ den Dev-Bereich weggenommen`, 'konto'],
  permissions: (d) => [`hat die Berechtigungen von „${n(d.konto)}“ geändert${d.aenderung ? `: ${d.aenderung}` : ''}`, 'konto'],
  permissions_default: (d) => [`hat die Standard-Berechtigungen für neue Teilnehmer geändert${d.aenderung ? `: ${d.aenderung}` : ''}`, 'konto'],
  dev_test: (d) => [`hat einen Selbsttest laufen lassen (${n(d.test)}: ${yes(d.ok) ? 'ok' : 'Fehler'})`, 'dev', true],
  demo_seed: () => ['hat Beispieldaten geladen', 'dev'],
}

const restDetails = (d) => Object.entries(d ?? {}).filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
  .map(([k, v]) => `${KEYS[k] ?? k} ${v === true ? 'ja' : v === false ? 'nein' : v}`).join(', ')

export function describe(r) {
  const d = r.details ?? {}
  const f = T[r.action]
  if (f) {
    const [text, kind, minor] = f(d, r)
    return { text, kind: kind ?? r.module ?? 'sonst', minor: !!minor }
  }
  // Unbekannte Aktion: lesbar aus Name und Details zusammensetzen
  const name = String(r.action ?? '').replace(/_/g, ' ')
  const rest = restDetails(d)
  return { text: `${name}${rest ? `: ${rest}` : ''}`, kind: r.module ?? 'sonst', minor: false }
}

// Überschrift je Tag: Heute, Gestern, sonst Wochentag + Datum
export function dayLabel(iso) {
  const d = new Date(String(iso).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return ''
  const key = (x) => `${x.getFullYear()}-${x.getMonth()}-${x.getDate()}`
  const today = new Date()
  const yest = new Date(today.getTime() - 86400000)
  if (key(d) === key(today)) return 'Heute'
  if (key(d) === key(yest)) return 'Gestern'
  return d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' })
}

export const KIND_LABEL = { umlagern: 'Umlagern', inventur: 'Inventur', pickliste: 'Pickliste', auftraege: 'Aufträge', minifig: 'Minifig-Finder', inventar: 'Inventar',
  bricklink: 'BrickLink', scanner: 'Teile-Scanner', suche: 'Suche', konto: 'Konten', dev: 'Technik', analyse: 'Analyse', sonst: 'Sonstiges' }
