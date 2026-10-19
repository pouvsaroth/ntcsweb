<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { useSiteStore, type ServiceCard } from '@/stores/site'
import { pickText } from '@/utils/bilingual'

/**
 * The website's "group of services" front page (back office → Website →
 * Services page): one small card per service, in order. A card opens its
 * own page (/services/{slug}), the Computer School's pages, or a web
 * address in a new tab.
 */
const { t, locale } = useI18n()
const site = useSiteStore()

const cards = computed(() =>
  (site.info.services?.cards ?? []).map((card: ServiceCard) => ({
    card,
    title: pickText(card, 'title', locale.value),
    text: pickText(card, 'text', locale.value),
    to: card.kind === 'school' ? '/computer-school' : `/services/${card.slug}`,
    external: card.kind === 'link',
  })),
)
</script>

<template>
  <section class="bg-gradient-to-b from-secondary-50 to-white px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
    <div class="mx-auto max-w-5xl">
      <h1 class="text-center text-2xl font-bold text-neutral-900 sm:text-3xl">{{ t('servicesPage.title') }}</h1>
      <p class="mx-auto mt-2 max-w-2xl text-center text-neutral-600">{{ t('servicesPage.subtitle') }}</p>

      <div class="mt-8 flex flex-wrap justify-center gap-4">
        <component
          :is="c.external ? 'a' : RouterLink"
          v-for="c in cards"
          :key="c.card.id"
          v-bind="c.external ? { href: c.card.link_url, target: '_blank', rel: 'noopener' } : { to: c.to }"
          class="group flex w-[calc(50%-0.5rem)] flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:outline-none sm:w-52"
        >
          <div class="aspect-[8/3] bg-neutral-100">
            <img v-if="c.card.image_url" :src="c.card.image_url" :alt="c.title" class="h-full w-full object-cover" />
            <div v-else class="flex h-full items-center justify-center bg-gradient-to-br from-secondary-600 to-secondary-800 text-lg" aria-hidden="true">
              {{ c.card.kind === 'school' ? '💻' : c.external ? '🔗' : '🏫' }}
            </div>
          </div>
          <div class="flex flex-1 flex-col p-3">
            <h2 class="text-sm font-semibold break-words text-neutral-900">{{ c.title }}</h2>
            <p v-if="c.text" class="mt-1 line-clamp-3 flex-1 text-xs text-neutral-600">{{ c.text }}</p>
            <span class="mt-2 text-[11px] text-neutral-400 group-hover:text-neutral-600">{{ t('servicesPage.clickToOpen') }}</span>
          </div>
        </component>
      </div>
    </div>
  </section>
</template>
