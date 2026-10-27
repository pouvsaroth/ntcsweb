<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import LanguageSwitch from './components/LanguageSwitch.vue'
import { chatsApi } from './api'
import { navItems } from './router'
import { useSession } from './session'

/**
 * The back office frame (laid out like SmartGPS-V3's back office). Navy, not
 * the school app's gold, so nobody mistakes which one they are in. Sidebar
 * from `lg`, foldable to an icon rail (remembered), and a slide-over drawer
 * below it. Your name at the bottom is the way to your account, with
 * sign-out beside it; the language switch is in the top bar.
 */
const { t } = useI18n()
const session = useSession()
const route = useRoute()
const router = useRouter()

const drawerOpen = ref(false)

/** Desktop only: the sidebar folded to icons. Kept in this browser; a private window just starts unfolded. */
const FOLD_KEY = 'ntcsweb.backoffice.sidebar-folded'
function readFolded(): boolean {
  try {
    return localStorage.getItem(FOLD_KEY) === '1'
  } catch {
    return false
  }
}
const folded = ref(readFolded())
function toggleFold(): void {
  folded.value = !folded.value
  try {
    localStorage.setItem(FOLD_KEY, folded.value ? '1' : '0')
  } catch {
    // Not remembered; it still folds.
  }
}
/** Classes that apply only while folded (always `lg:` ones: the phone drawer is never folded). */
const onRail = (cls: string) => (folded.value ? cls : '')
watch(() => route.fullPath, () => (drawerOpen.value = false))

const items = computed(() => (session.mustChangePassword ? [] : navItems.filter((item) => session.can(item.permission))))

/** Customers waiting for an answer in the support chat (stage 10): a badge on Support. */
const chatsWaiting = ref(0)
async function refreshWaiting(): Promise<void> {
  if (!session.can('platform.support.read') || session.mustChangePassword) return
  try {
    chatsWaiting.value = (await chatsApi.waiting()).waiting
  } catch {
    /* keep the last count */
  }
}
let waitingTimer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  void refreshWaiting()
  waitingTimer = setInterval(refreshWaiting, 30000)
})
onBeforeUnmount(() => clearInterval(waitingTimer))
watch(() => route.fullPath, () => route.path.startsWith('/support') && void refreshWaiting())

const icons: Record<string, string> = {
  cog: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
  globe:
    'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418',
  eye: 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
  card: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
  database:
    'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
  school:
    'M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5',
  users:
    'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
  shield:
    'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  tag: 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3zM6 6h.008v.008H6V6z',
  list: 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
}

const onAccount = computed(() => route.path.startsWith('/account'))

/** Where you are, for the top bar: the menu item the page belongs to, or your account. */
const pageTitle = computed(() => {
  if (route.path.startsWith('/account')) return t('bo.nav.account')
  const item = navItems.find((n) => route.path === n.to || route.path.startsWith(`${n.to}/`))
  return item ? t(item.labelKey) : ''
})

/** Up to two letters from the name, for the avatar. */
const initials = computed(() =>
  (session.staff?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join(''),
)

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
      <LanguageSwitch dark />
    </header>

    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-black/40 lg:hidden" @click="drawerOpen = false" />

    <aside
      class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-secondary-900 text-secondary-100 transition-[transform,width] lg:translate-x-0"
      :class="[drawerOpen ? 'translate-x-0' : '-translate-x-full', onRail('lg:w-16')]"
    >
      <div class="flex items-center gap-2 border-b border-white/10 px-4 py-4" :class="onRail('lg:flex-col lg:px-2')">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary-500 text-sm font-extrabold text-secondary-900" aria-hidden="true">BO</span>
        <div class="min-w-0 flex-1" :class="onRail('lg:hidden')">
          <p class="truncate font-bold text-white">{{ t('bo.brand') }}</p>
          <p class="truncate text-xs text-secondary-300">{{ t('bo.brandSub') }}</p>
        </div>
        <button
          type="button"
          class="hidden h-8 w-8 shrink-0 place-items-center rounded-lg text-secondary-300 hover:bg-white/10 hover:text-white lg:grid"
          :title="folded ? t('bo.nav.expand') : t('bo.nav.collapse')"
          :aria-label="folded ? t('bo.nav.expand') : t('bo.nav.collapse')"
          :aria-expanded="!folded"
          @click="toggleFold"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path v-if="folded" stroke-linecap="round" stroke-linejoin="round" d="M5.25 4.5l7.5 7.5-7.5 7.5m6-15l7.5 7.5-7.5 7.5" />
            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
          </svg>
        </button>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" :class="onRail('lg:px-2')">
        <RouterLink
          v-for="item in items"
          :key="item.to"
          :to="item.to"
          class="relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium hover:bg-white/10"
          :class="onRail('lg:justify-center lg:px-0')"
          active-class="bg-primary-500 text-secondary-900 hover:bg-primary-500"
          :title="folded ? t(item.labelKey) : undefined"
          :aria-label="t(item.labelKey)"
        >
          <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
          </svg>
          <span class="truncate" :class="onRail('lg:hidden')">{{ t(item.labelKey) }}</span>
          <span
            v-if="item.to === '/support' && chatsWaiting > 0"
            class="ml-auto rounded-full bg-red-600 px-1.5 text-[11px] font-bold text-white"
            :class="onRail('lg:absolute lg:ml-0 lg:translate-x-3 lg:-translate-y-2')"
          >
            {{ chatsWaiting }}
          </span>
        </RouterLink>
      </nav>

      <!-- Your name is the way to your account; sign-out sits beside it, never inside the link. -->
      <div class="flex items-center gap-1 border-t border-white/10 px-3 py-3" :class="onRail('lg:flex-col lg:px-2')">
        <RouterLink
          to="/account"
          class="flex min-w-0 flex-1 items-center gap-3 rounded-lg p-2 hover:bg-white/10"
          :class="[onAccount ? 'bg-white/10' : '', onRail('lg:flex-none lg:p-1')]"
          :title="t('bo.nav.account')"
          :aria-label="`${t('bo.nav.account')}: ${session.staff?.name ?? ''}`"
        >
          <span
            class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-primary-200 to-primary-500 text-xs font-extrabold text-secondary-900"
            aria-hidden="true"
          >
            {{ initials }}
          </span>
          <span class="grid min-w-0 flex-1" :class="onRail('lg:hidden')">
            <span class="truncate text-sm font-semibold" :class="onAccount ? 'text-primary-400' : 'text-white'">{{ session.staff?.name }}</span>
            <span class="truncate text-xs text-secondary-300">{{ session.staff?.email }}</span>
          </span>
        </RouterLink>
        <button
          type="button"
          class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-secondary-300 hover:bg-white/10 hover:text-primary-400"
          :title="t('bo.nav.signOut')"
          :aria-label="t('bo.nav.signOut')"
          @click="signOut"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"
            />
          </svg>
        </button>
      </div>
    </aside>

    <div class="transition-[margin]" :class="folded ? 'lg:ml-16' : 'lg:ml-64'">
      <!-- Desktop top bar: where you are, and the language switch. -->
      <header
        class="sticky top-0 z-20 hidden h-16 items-center justify-between gap-4 border-b border-neutral-200 bg-white/90 px-8 backdrop-blur lg:flex"
      >
        <p class="flex min-w-0 items-baseline gap-2 text-sm">
          <span class="text-neutral-500">{{ t('bo.brand') }}</span>
          <span class="text-neutral-400" aria-hidden="true">/</span>
          <span class="truncate font-bold text-neutral-900">{{ pageTitle }}</span>
        </p>
        <LanguageSwitch />
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
