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
// Where /api goes in development: the local docker backend, or — with
// BO_DEV_API=https://smis.newtechkh.com — the live server, to try screen
// changes against real data (anything saved is saved for real). For the live
// server the cookies are re-written for 127.0.0.1 and the request looks like
// it came from the live page, so its sign-in and CSRF checks accept it.
const devApi = process.env.BO_DEV_API || 'http://localhost:8080'
const live = devApi.startsWith('https://')
const apiProxy = {
  target: devApi,
  changeOrigin: true,
  secure: true,
  ...(live
    ? {
        cookieDomainRewrite: '',
        headers: { origin: devApi, referer: `${devApi}/` },
        configure: (proxy: { on: (event: 'proxyRes', cb: (res: { headers: Record<string, string | string[] | undefined> }) => void) => void }) => {
          // http://127.0.0.1 cannot keep a cookie marked Secure for another site.
          proxy.on('proxyRes', (res) => {
            const set = res.headers['set-cookie']
            if (Array.isArray(set)) res.headers['set-cookie'] = set.map((c) => c.replace(/;\s*Secure/gi, '').replace(/;\s*SameSite=None/gi, '; SameSite=Lax'))
          })
        },
      }
    : {}),
}

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
      '/api': apiProxy,
      '/sanctum': apiProxy,
    },
  },
})
