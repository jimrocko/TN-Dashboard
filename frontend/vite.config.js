import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig(({ mode }) => {
  // Demo und Tutorial laufen ohne Server (Demo-Backend im Browser) und landen jeweils in einer einzigen HTML-Datei.
  const demo = mode === 'demo' || mode === 'tutorial'
  return {
  // Relative Pfade: Die App funktioniert in jedem Unterordner des Webservers (z. B. /tn-dashboard/).
  base: './',
  // Demo: alles in eine Datei (ohne Server, ohne Service Worker), siehe scripts/inline-demo.mjs.
  build: demo
    ? {
        outDir: mode === 'tutorial' ? '../tutorial-dist' : '../demo-dist', emptyOutDir: true,
        assetsInlineLimit: 100000000, cssCodeSplit: false, modulePreload: false,
        rollupOptions: { output: { inlineDynamicImports: true } },
      }
    : { outDir: '../dist', emptyOutDir: true },
  server: {
    // Lokal: PHP-Server mit `php -S 127.0.0.1:8000 -t ..` im Projektordner starten.
    proxy: { '/api': 'http://127.0.0.1:8000' },
  },
  plugins: [
    vue(),
    !demo && VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icon.svg', 'apple-touch-icon.png'],
      manifest: {
        name: 'TN Dashboard',
        short_name: 'TN Dashboard',
        description: 'Pickliste, Inventar, Minifig-Finder und mehr für den Lego-Shop',
        lang: 'de',
        start_url: './',
        scope: './',
        display: 'standalone',
        orientation: 'any',
        background_color: '#0e1013',
        theme_color: '#0e1013',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icon.svg', sizes: 'any', type: 'image/svg+xml' },
        ],
      },
      workbox: {
        // Die API darf nie aus dem Cache kommen; tutorial.html ist eine eigene Seite (nicht auf die App umleiten).
        navigateFallbackDenylist: [/\/api\//, /tutorial\.html$/, /\.pdf$/],
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        // Sofort zuständig, schon beim ersten Öffnen: dann startet die App auch bei schlechtem WLAN (Offline-Modus)
        clientsClaim: true,
        skipWaiting: true,
      },
    }),
  ],
}
})
