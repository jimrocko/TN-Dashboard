// Kopiert das PHP-Backend in dist/, sodass der komplette Ordner dist/ auf den Webserver hochgeladen werden kann.
import { cpSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { basename, dirname, resolve } from 'node:path'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
cpSync(resolve(root, 'api'), resolve(root, 'dist/api'), {
  recursive: true,
  // Lokale Konfiguration und Entwicklungsdatenbank nicht mit ausliefern.
  filter: (src) => basename(src) !== 'config.php' && !src.endsWith('.sqlite'),
})
console.log('Backend nach dist/api kopiert. Ordner dist/ kann hochgeladen werden.')
