import { defineStore } from 'pinia'

// Darstellungs-Einstellungen gelten pro Gerät und werden im Browser gespeichert.
const TUTORIAL = import.meta.env.MODE === 'tutorial'
const KEY = TUTORIAL ? 'tn-tutorial-settings' : 'tn-dashboard-settings'
// style: 'modern' = normales Design, 'xp' = Windows-XP-Desktop.
const defaults = { style: 'modern', theme: 'dark', density: 'comfortable', fontSize: 'normal', reduceMotion: false }

function read() {
  try {
    const s = { ...defaults, ...JSON.parse(localStorage.getItem(KEY) || '{}') }
    return TUTORIAL ? { ...s, style: 'modern' } : s
  } catch {
    return { ...defaults }
  }
}

const media = typeof matchMedia === 'function' ? matchMedia('(prefers-color-scheme: light)') : null

export const useSettings = defineStore('settings', {
  state: () => read(),
  getters: {
    resolvedTheme: (s) => (s.theme === 'system' ? (media?.matches ? 'light' : 'dark') : s.theme),
  },
  actions: {
    apply() {
      const root = document.documentElement
      root.dataset.tnStyle = this.style
      root.dataset.tnTheme = this.resolvedTheme
      root.dataset.tnDensity = this.density
      root.dataset.tnFont = this.fontSize
      root.dataset.tnMotion = this.reduceMotion ? 'reduced' : 'full'
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', this.resolvedTheme === 'light' ? '#f3f4f6' : '#0e1013')
    },
    set(patch) {
      Object.assign(this, patch)
      try { localStorage.setItem(KEY, JSON.stringify(this.$state)) } catch { /* nur für diese Sitzung */ }
      this.apply()
    },
    reset() {
      this.set({ ...defaults })
    },
    init() {
      this.apply()
      media?.addEventListener?.('change', () => this.theme === 'system' && this.apply())
    },
  },
})
