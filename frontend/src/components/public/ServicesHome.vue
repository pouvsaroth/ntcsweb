<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'

import { useSiteStore, type ServiceCard } from '@/stores/site'
import { pickText } from '@/utils/bilingual'

/**
 * The website's "group of services" front page (back office → Website →
 * Services page): one card per service, in order — its logo, the Khmer
 * title above the English one (each on one line; the card is as wide as
 * its longest title), a short text. A card opens its own page
 * (/services/{slug}), the Computer School's pages, the Downloads page, the
 * plans page (/plans), or a web address in a new tab.
 */
const { t, locale } = useI18n()
const site = useSiteStore()

const cards = computed(() =>
  (site.info.services?.cards ?? []).map((card: ServiceCard) => ({
    card,
    titleKm: card.title_km.trim(),
    titleEn: card.title_en.trim(),
    text: pickText(card, 'text', locale.value),
    to:
      card.kind === 'school' ? '/computer-school' : card.kind === 'downloads' ? '/downloads' : card.kind === 'plans' ? (card.product_code && card.product_code !== 'school' ? `/plans/${card.product_code}` : '/plans') : `/services/${card.slug}`,
    external: card.kind === 'link',
  })),
)
</script>

<template>
  <section class="bg-gradient-to-b from-secondary-50 to-white px-4 pt-16 pb-10 sm:px-6 sm:py-14 lg:px-8">
    <div class="mx-auto max-w-5xl">
      <h1 class="text-center text-2xl font-bold text-neutral-900 sm:text-3xl">{{ t('servicesPage.title') }}</h1>
      <p class="mx-auto mt-2 max-w-2xl text-center text-neutral-600">{{ t('servicesPage.subtitle') }}</p>

      <div class="mt-8 flex flex-wrap justify-center gap-4">
        <component
          :is="c.external ? 'a' : RouterLink"
          v-for="c in cards"
          :key="c.card.id"
          v-bind="c.external ? { href: c.card.link_url, target: '_blank', rel: 'noopener' } : { to: c.to }"
          class="group flex w-max max-w-full min-w-40 flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:outline-none"
        >
          <!-- A fixed height: an uploaded logo is shown whole, never stretching the card. -->
          <div class="flex h-24 items-center justify-center bg-neutral-50 p-2">
            <img v-if="c.card.image_url" :src="c.card.image_url" :alt="c.titleEn || c.titleKm" class="h-full max-w-full object-contain" />
            <span v-else class="text-3xl" aria-hidden="true">
              {{ c.card.kind === 'school' ? '💻' : c.card.kind === 'downloads' ? '📥' : c.card.kind === 'plans' ? '🏫' : c.external ? '🔗' : '🏫' }}
            </span>
          </div>
          <div class="flex flex-1 flex-col px-4 py-3 text-center">
            <h2 v-if="c.titleKm" class="truncate text-sm font-semibold whitespace-nowrap text-neutral-900">{{ c.titleKm }}</h2>
            <p v-if="c.titleEn" class="truncate text-sm font-semibold whitespace-nowrap text-neutral-700" :class="{ 'mt-0.5': c.titleKm }">{{ c.titleEn }}</p>
            <!-- w-0 min-w-full: the text wraps inside the card's width, it never widens it. -->
            <p v-if="c.text" class="mt-1 line-clamp-2 w-0 min-w-full flex-1 text-xs text-neutral-500">{{ c.text }}</p>
            <span class="mt-2 text-[11px] text-neutral-400 group-hover:text-neutral-600">{{ t('servicesPage.clickToOpen') }}</span>
          </div>
        </component>
      </div>
    </div>
  </section>
</template>
