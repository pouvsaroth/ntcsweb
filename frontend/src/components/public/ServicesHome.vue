<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { useSiteStore } from '@/stores/site'
import { pickText } from '@/utils/bilingual'

/**
 * The website's "group of services" front page (back office → Website →
 * Services page): one card per service — the Computer School's own pages
 * and the School Management System's product page.
 */
const { t, locale } = useI18n()
const site = useSiteStore()

const cards = computed(() => {
  const s = site.info.services
  if (!s) return []
  return [
    { key: 'computer_school', to: '/computer-school', card: s.computer_school, fallback: t('servicesPage.computerSchool') },
    { key: 'sms', to: '/school-management-system', card: s.sms, fallback: t('servicesPage.sms') },
  ].map((c) => ({
    ...c,
    title: pickText(c.card, 'title', locale.value) || c.fallback,
    text: pickText(c.card, 'text', locale.value),
  }))
})
</script>

<template>
  <section class="bg-gradient-to-b from-secondary-50 to-white px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
    <div class="mx-auto max-w-5xl">
      <h1 class="text-center text-2xl font-bold text-neutral-900 sm:text-3xl">{{ t('servicesPage.title') }}</h1>
      <p class="mx-auto mt-2 max-w-2xl text-center text-neutral-600">{{ t('servicesPage.subtitle') }}</p>

      <div class="mt-10 grid gap-6 md:grid-cols-2">
        <RouterLink
          v-for="c in cards"
          :key="c.key"
          :to="c.to"
          class="group flex flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:outline-none"
        >
          <div class="aspect-[16/9] bg-neutral-100">
            <img v-if="c.card.image_url" :src="c.card.image_url" :alt="c.title" class="h-full w-full object-cover" />
            <div v-else class="flex h-full items-center justify-center bg-gradient-to-br from-secondary-600 to-secondary-800 text-5xl" aria-hidden="true">
              {{ c.key === 'sms' ? '🏫' : '💻' }}
            </div>
          </div>
          <div class="flex flex-1 flex-col p-6">
            <h2 class="text-xl font-semibold text-neutral-900">{{ c.title }}</h2>
            <p v-if="c.text" class="mt-2 flex-1 text-sm whitespace-pre-line text-neutral-600">{{ c.text }}</p>
            <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-secondary-700 group-hover:text-secondary-900">
              {{ t('servicesPage.open') }}
              <span aria-hidden="true" class="transition-transform group-hover:translate-x-0.5">→</span>
            </span>
          </div>
        </RouterLink>
      </div>
    </div>
  </section>
</template>
