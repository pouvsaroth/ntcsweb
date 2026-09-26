import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { i18n } from './i18n'
import router from './router/admin'
import './assets/styles/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(i18n)
app.use(router)

app.mount('#app')

// Makes the home-screen icon install as a real app (one reused window, no
// new browser tab per tap) — see public/sw.js. Production only: in dev it
// would sit between Vite's dev server and the browser for no benefit.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Not fatal — the app works the same, the icon just may open as a tab.
    })
  })
}
