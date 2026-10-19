<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

import CompanyHeader from '@/components/layout/CompanyHeader.vue'
import PublicFooter from '@/components/layout/PublicFooter.vue'
import PublicHeader from '@/components/layout/PublicHeader.vue'
import MobileBottomNav from '@/components/public/MobileBottomNav.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useSiteStore } from '@/stores/site'

const site = useSiteStore()
const route = useRoute()
const { t } = useI18n()
const logoFailed = ref(false)

/**
 * The services front page and the School Management System page are the
 * company's, not the computer school's: a plain header, without the
 * school's menu or its bottom tab bar.
 */
const companyView = computed(() => route.name === 'service-page' || (route.name === 'home' && site.servicesOn))

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

  <div v-else class="flex min-h-screen flex-col">
    <CompanyHeader v-if="companyView" />
    <PublicHeader v-else />
    <!-- pb-16 clears the fixed MobileBottomNav (mobile/tablet only, see
         its own lg:hidden) so the last section on a page is never hidden
         behind it. -->
    <main class="flex-1" :class="companyView ? '' : 'pb-16 lg:pb-0'">
      <RouterView />
    </main>
    <PublicFooter />
    <MobileBottomNav v-if="!companyView" />
  </div>
</template>
