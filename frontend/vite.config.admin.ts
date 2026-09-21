import { writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig, type Plugin } from 'vite'

/**
 * Writes `dist/version.json` with a fresh build id after every production
 * build (build-only — `apply: 'build'` means this never runs under `vite`
 * dev serve, so local dev never sees a version.json at all). The running
 * SPA polls this file (see App.vue) to notice when a newer build has been
 * deployed and reload itself, instead of a visitor silently running stale
 * JS until they think to hard-refresh.
 */
function writeVersionFile(): Plugin {
  return {
    name: 'write-version-file',
    apply: 'build',
    writeBundle(options) {
      const outDir = options.dir ?? 'dist'
      writeFileSync(join(outDir, 'version.json'), JSON.stringify({ version: String(Date.now()) }))
    },
  }
}

// The admin/ERP app's build — see vite.config.ts for the public website's
// own config, and this pair's shared docblock there.
//
// `root` points at admin/ (its own index.html lives there, referencing
// @/main-admin.ts via the alias below — a relative `../src/main-admin.ts`
// looks reasonable but breaks, since the browser resolves that against the
// page's own URL and collapses it to `/src/main-admin.ts`, which Vite then
// looks up *inside* `root` and never finds) rather than a root-level second
// HTML file — Vite's dev-server SPA fallback (serving that HTML for any
// unmatched path, which is what makes Vue Router's history-mode routing
// work on a hard refresh or a deep link) only ever targets
// `<root>/index.html`, never an arbitrarily-named file. `publicDir`/`build.outDir`
// are absolute so they still resolve against the project root, not against
// this now-different `root`, and the `@` alias is unaffected either way
// since it's already an absolute path.
// https://vite.dev/config/
export default defineConfig({
  root: fileURLToPath(new URL('./admin', import.meta.url)),
  publicDir: fileURLToPath(new URL('./public', import.meta.url)),
  plugins: [vue(), tailwindcss(), writeVersionFile()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      // admin/index.html's script tag references /src/main-admin.ts, but
      // `root` is admin/ (see below), so a plain root-relative "/src/..."
      // would only ever look inside admin/src, which doesn't exist — this
      // alias redirects that exact URL prefix to the real src/ one level up.
      '/src': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: fileURLToPath(new URL('./dist/admin', import.meta.url)),
    emptyOutDir: true,
  },
  server: {
    host: '127.0.0.1',
    port: 5300,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
