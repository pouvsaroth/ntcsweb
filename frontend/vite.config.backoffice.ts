import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// The back office's build — the platform's own administration, not a
// school's (see docs/backoffice/). Same shape as vite.config.admin.ts, whose
// docblock explains the `root` / `/src` alias arrangement: its own folder
// (backoffice/) with its own index.html, so the dev server's SPA fallback
// serves it for every deep link. Its own port and its own dist folder, so it
// can be deployed on its own host behind an IP allowlist.
// https://vite.dev/config/
export default defineConfig({
  root: fileURLToPath(new URL('./backoffice', import.meta.url)),
  publicDir: fileURLToPath(new URL('./public', import.meta.url)),
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '/src': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: fileURLToPath(new URL('./dist/backoffice', import.meta.url)),
    emptyOutDir: true,
  },
  server: {
    host: '127.0.0.1',
    port: 5301,
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
