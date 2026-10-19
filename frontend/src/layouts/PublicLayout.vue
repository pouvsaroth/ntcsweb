<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import PublicFooter from '@/components/layout/PublicFooter.vue'
import PublicHeader from '@/components/layout/PublicHeader.vue'
import MobileBottomNav from '@/components/public/MobileBottomNav.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
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
  () => route.name === 'service-page' || (site.servicesOn && (route.name === 'home' || route.name === 'downloads')),
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

  <div v-else class="relative flex min-h-screen flex-col">
    <div v-if="companyView" class="absolute top-3 right-3 z-30 flex items-center gap-2 sm:top-4 sm:right-6">
      <a
        :href="backOfficeUrl"
        class="rounded-lg border border-neutral-300 bg-white/80 px-3 py-1.5 text-xs font-semibold text-secondary-800 shadow-sm hover:bg-white"
      >
        {{ t('servicesPage.backOffice') }}
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
