<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import { setLocale } from './i18n'
import { navItems } from './router'
import { useSession } from './session'

/**
 * The back office frame. Navy, not the school app's gold, so nobody mistakes
 * which one they are in. Sidebar from `lg`, a slide-over drawer below it.
 */
const { t, locale } = useI18n()
const session = useSession()
const route = useRoute()
const router = useRouter()

const drawerOpen = ref(false)
watch(() => route.fullPath, () => (drawerOpen.value = false))

const items = computed(() => (session.mustChangePassword ? [] : navItems.filter((item) => session.can(item.permission))))

const icons: Record<string, string> = {
  database:
    'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
  school:
    'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
  users:
    'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
  shield:
    'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  list: 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
  account:
    'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
}

function toggleLocale(): void {
  setLocale(locale.value === 'km' ? 'en' : 'km')
}

async function signOut(): Promise<void> {
  await session.signOut()
  await router.push('/sign-in')
}
</script>

<template>
  <div class="min-h-screen bg-neutral-50">
    <!-- Phone / tablet top bar -->
    <header class="sticky top-0 z-30 flex h-14 items-center justify-between bg-secondary-900 px-4 text-white lg:hidden">
      <button type="button" class="-ml-2 rounded p-2 hover:bg-white/10" :aria-label="t('bo.nav.menu')" @click="drawerOpen = true">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
      </button>
      <span class="font-semibold">{{ t('bo.brand') }}</span>
      <button type="button" class="rounded px-2 py-1 text-sm hover:bg-white/10" @click="toggleLocale">{{ t('bo.language') }}</button>
    </header>

    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-black/40 lg:hidden" @click="drawerOpen = false" />

    <aside
      class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-secondary-900 text-secondary-100 transition-transform lg:translate-x-0"
      :class="drawerOpen ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="border-b border-white/10 px-5 py-5">
        <p class="text-lg font-bold text-white">{{ t('bo.brand') }}</p>
        <p class="text-xs text-secondary-300">{{ t('bo.brandSub') }}</p>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <RouterLink
          v-for="item in items"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-white/10"
          active-class="bg-primary-500 text-secondary-900 hover:bg-primary-500"
        >
          <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
          </svg>
          {{ t(item.labelKey) }}
        </RouterLink>
      </nav>

      <div class="space-y-1 border-t border-white/10 px-3 py-4">
        <RouterLink
          to="/account"
          class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-white/10"
          active-class="bg-primary-500 text-secondary-900 hover:bg-primary-500"
        >
          <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" :d="icons.account" />
          </svg>
          <span class="min-w-0 flex-1 truncate">{{ session.staff?.name }}</span>
        </RouterLink>
        <button
          type="button"
          class="hidden w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-white/10 lg:block"
          @click="toggleLocale"
        >
          {{ t('bo.language') }}
        </button>
        <button type="button" class="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-white/10" @click="signOut">
          {{ t('bo.nav.signOut') }}
        </button>
      </div>
    </aside>

    <main class="px-4 py-6 sm:px-6 lg:ml-64 lg:px-8">
      <RouterView />
    </main>
  </div>
</template>
