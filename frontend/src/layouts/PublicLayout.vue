<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import PublicFooter from '@/components/layout/PublicFooter.vue'
import PublicHeader from '@/components/layout/PublicHeader.vue'
import MobileBottomNav from '@/components/public/MobileBottomNav.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import LanguageSwitcher from '@/components/ui/LanguageSwitcher.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const route = useRoute()
const { t } = useI18n()
const logoFailed = ref(false)

/**
 * The services front page and each card's own page are the company's, not
 * the computer school's: no title bar, no school menu, no bottom tab bar —
 * those start once a visitor opens the Computer School. Only a Back office
 * button and the language button stay, in the corner. While the services
 * page is on, the Downloads page is one of its cards, so it looks the same.
 */
const companyView = computed(
  () => route.name === 'service-page' || route.name === 'plans' || route.name === 'signup' || route.name === 'signup-status' || (site.servicesOn && (route.name === 'home' || route.name === 'downloads')),
)

/** The platform back office (staff only), beside the language button. */
const backOfficeUrl = (import.meta.env.VITE_BACKOFFICE_URL as string | undefined) || 'https://smis.newtechkh.com'

onMounted(async () => {
  await site.load()
  // No Website module on the school's plan: nothing to count.
  if (site.info.website_enabled !== false) site.pingVisit()
})
</script>

<template>
  <!-- The school's plan has no Website module (back office billing): only the
       name, a short notice and the way in to the system. /login is a real
       <a>, as in PublicHeader. -->
  <div v-if="site.loaded && site.info.website_enabled === false" class="flex min-h-screen flex-col items-center justify-center px-4 text-center">
    <img v-if="site.info.logo && !logoFailed" :src="site.info.logo" alt="" class="mb-4 h-20 w-20 object-contain" @error="logoFailed = true" />
    <h1 class="text-xl font-semibold text-neutral-900">{{ site.info.name }}</h1>
    <p v-if="site.info.name_en" class="text-sm text-neutral-500">{{ site.info.name_en }}</p>
    <h2 class="mt-6 text-lg font-medium text-neutral-800">{{ t('siteOffline.title') }}</h2>
    <p class="mt-2 max-w-md text-sm text-neutral-600">{{ t('siteOffline.message') }}</p>
    <BaseButton href="/login" class="mt-6">{{ t('siteOffline.signIn') }}</BaseButton>
  </div>

  <!-- Until the settings arrive we do not know whether this is the services
       page or the school's website, so neither is drawn — otherwise the
       school's menu flashes up first on newtechkh.com. -->
  <div v-else-if="!site.loaded" class="flex min-h-screen items-center justify-center" aria-busy="true">
    <BaseSpinner />
  </div>

  <div v-else class="relative flex min-h-screen flex-col">
    <div v-if="companyView" class="absolute top-3 right-3 z-30 flex items-center gap-2 sm:top-4 sm:right-6">
      <!-- Icon only; its name shows on hover (and to screen readers). -->
      <a
        :href="backOfficeUrl"
        class="group relative flex h-8 w-8 items-center justify-center rounded-lg border border-neutral-300 bg-white/80 text-secondary-800 shadow-sm hover:bg-white focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:outline-none"
        :aria-label="t('servicesPage.backOffice')"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <rect x="3" y="3" width="7" height="7" rx="1.5" />
          <rect x="14" y="3" width="7" height="7" rx="1.5" />
          <rect x="3" y="14" width="7" height="7" rx="1.5" />
          <rect x="14" y="14" width="7" height="7" rx="1.5" />
        </svg>
        <span
          class="pointer-events-none absolute top-full right-0 mt-1.5 rounded-md bg-neutral-900 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
          aria-hidden="true"
        >
          {{ t('servicesPage.backOffice') }}
        </span>
      </a>
      <LanguageSwitcher />
    </div>
    <PublicHeader v-else />
    <!-- pb-16 clears the fixed MobileBottomNav (mobile/tablet only, see
         its own lg:hidden) so the last section on a page is never hidden
         behind it. -->
    <main class="relative flex-1" :class="companyView ? '' : 'pb-16 lg:pb-0'">
      <RouterView />
    </main>
    <PublicFooter />
    <MobileBottomNav v-if="!companyView" />
  </div>
</template>
