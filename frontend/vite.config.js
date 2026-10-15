import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig(({ mode }) => {
  const demo = mode === 'demo'
  return {
  // Relative Pfade: Die App funktioniert in jedem Unterordner des Webservers (z. B. /tn-dashboard/).
  base: './',
  // Demo: alles in eine Datei (ohne Server, ohne Service Worker), siehe scripts/inline-demo.mjs.
  build: demo
    ? { outDir: '../demo-dist', emptyOutDir: true, assetsInlineLimit: 100000000, cssCodeSplit: false, modulePreload: false }
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
        background_color: '#0a0f17',
        theme_color: '#0a0f17',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icon.svg', sizes: 'any', type: 'image/svg+xml' },
        ],
      },
      workbox: {
        // Die API darf nie aus dem Cache kommen.
        navigateFallbackDenylist: [/\/api\//],
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
      },
    }),
  ],
}
})
