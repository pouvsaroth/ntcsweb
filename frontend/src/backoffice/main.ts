import { createPinia } from 'pinia'
import { createApp } from 'vue'

import '@/assets/styles/main.css'

import App from './App.vue'
import { i18n } from './i18n'
import router from './router'

// The back office — the platform's own administration. See docs/backoffice/.
createApp(App).use(createPinia()).use(i18n).use(router).mount('#app')
