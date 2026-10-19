<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'

import { useSiteStore } from '@/stores/site'
import { lines, pickText } from '@/utils/bilingual'

/**
 * The School Management System's product page, from the services front page
 * (back office → Website → Services page). Gone while that page is off.
 */
const { t, locale } = useI18n()
const site = useSiteStore()
const router = useRouter()

const sms = computed(() => site.info.services?.sms ?? null)
const heading = computed(() => (sms.value ? pickText(sms.value, 'heading', locale.value) || pickText(sms.value, 'title', locale.value) || t('servicesPage.sms') : ''))
const description = computed(() => (sms.value ? pickText(sms.value, 'description', locale.value) || pickText(sms.value, 'text', locale.value) : ''))
const features = computed(() => (sms.value ? lines(pickText(sms.value, 'features', locale.value)) : []))

onMounted(() => site.load())
// The services page is off: there is no product page to show.
watch(
  () => site.loaded && !site.servicesOn,
  (gone) => gone && void router.replace('/'),
  { immediate: true },
)
</script>

<template>
  <div v-if="sms">
    <section class="bg-gradient-to-b from-secondary-50 to-white px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
      <div class="mx-auto grid max-w-6xl items-center gap-8 lg:grid-cols-2">
        <div>
          <RouterLink to="/" class="text-sm font-medium text-secondary-700 hover:underline">← {{ t('servicesPage.backToServices') }}</RouterLink>
          <h1 class="mt-3 text-3xl font-bold text-neutral-900 sm:text-4xl">{{ heading }}</h1>
          <p v-if="description" class="mt-4 text-base whitespace-pre-line text-neutral-600">{{ description }}</p>
          <div class="mt-6 flex flex-wrap gap-3">
            <a
              :href="sms.sign_in_url"
              class="inline-flex items-center rounded-lg bg-primary-500 px-5 py-2.5 text-sm font-semibold text-secondary-900 shadow-sm hover:bg-primary-400"
            >
              {{ t('servicesPage.signIn') }}
            </a>
            <RouterLink to="/contact" class="inline-flex items-center rounded-lg border border-neutral-300 bg-white px-5 py-2.5 text-sm font-semibold text-neutral-800 hover:bg-neutral-50">
              {{ t('servicesPage.contactUs') }}
            </RouterLink>
          </div>
        </div>
        <div class="overflow-hidden rounded-2xl border border-neutral-200 bg-neutral-100 shadow-sm">
          <img v-if="sms.image_url" :src="sms.image_url" :alt="heading" class="aspect-[16/10] w-full object-cover" />
          <div v-else class="flex aspect-[16/10] items-center justify-center bg-gradient-to-br from-secondary-600 to-secondary-800 text-6xl" aria-hidden="true">🏫</div>
        </div>
      </div>
    </section>

    <section v-if="features.length" class="px-4 py-10 sm:px-6 lg:px-8">
      <div class="mx-auto max-w-6xl">
        <h2 class="text-xl font-semibold text-neutral-900">{{ t('servicesPage.features') }}</h2>
        <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="(feature, i) in features" :key="i" class="flex items-start gap-3 rounded-xl border border-neutral-200 bg-white p-4">
            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm text-primary-800" aria-hidden="true">✓</span>
            <span class="text-sm text-neutral-800">{{ feature }}</span>
          </li>
        </ul>
      </div>
    </section>
  </div>
  <div v-else class="min-h-[60vh]" />
</template>
