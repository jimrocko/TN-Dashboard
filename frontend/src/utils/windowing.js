// Gemeinsame Schlüssel für die XP-Fensterverwaltung (components/XpShell.vue, components/XpWindowView.vue).

/** inject(WINDOW_VISIBLE): Ref<boolean>, ob das Fenster gerade sichtbar (nicht minimiert) ist. Außerhalb von XP immer true. */
export const WINDOW_VISIBLE = Symbol('tn-window-visible')

/**
 * Zu welchem Fenster gehört eine Route? Gleicher Schlüssel = gleiches Fenster.
 * /inventar?location=3 und /inventar gehören zusammen; jeder Pfad (auch /modul/minifig) ist ein eigenes Fenster.
 */
export const windowKey = (r) => r.path.replace(/\/+$/, '') || '/'
