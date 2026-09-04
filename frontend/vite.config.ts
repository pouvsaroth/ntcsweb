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

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss(), writeVersionFile()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // IPv4 only, and a non-default port: on this Windows host, both the IPv6
    // loopback (::1) and the conventional Vite port 5173/5180 range failed
    // with EACCES — a local networking quirk (Hyper-V/WSL reserves port
    // ranges dynamically), nothing to do with Vite. 5299 is free; if it
    // stops being free on another machine, any open port works as long as
    // SANCTUM_STATEFUL_DOMAINS / CORS_ALLOWED_ORIGINS in backend/.env agree.
    host: '127.0.0.1',
    port: 5299,
    strictPort: true,
    proxy: {
      // Same-origin from the browser's point of view (localhost:5173), so
      // Sanctum's session cookie and CSRF token work exactly as they will in
      // production, where nginx serves both the built SPA and the API from
      // one origin. Without this, cookie auth would need cross-site cookies
      // (SameSite=None + Secure), which doesn't work at all over plain HTTP.
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
