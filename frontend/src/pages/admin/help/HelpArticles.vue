<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useHelpCenter } from '@/composables/useHelpCenter'
import type { HelpArticleSummary } from '@/services/help'
import { pickText } from '@/utils/helpText'

/** Help & Support → Help articles: search, then the articles by module. */
const { t, locale } = useI18n()
const { data } = useHelpCenter()

const search = ref('')
const module = ref('')

function moduleLabel(code: string | null): string {
  if (!code) return t('help.general')
  const m = data.value?.modules.find((x) => x.code === code)
  return m ? pickText(locale.value, m.label, m.label_km) : code
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.articles ?? []).filter(
    (a) =>
      (!module.value || (a.module ?? '') === module.value) &&
      (!q || [a.title_en, a.title_km, a.summary_en, a.summary_km].some((s) => (s ?? '').toLowerCase().includes(q))),
  )
})

/** General first, then the modules in the order NewTech put them. */
const groups = computed(() => {
  const out: { key: string; label: string; items: HelpArticleSummary[] }[] = []
  for (const a of filtered.value) {
    const key = a.module ?? ''
    let g = out.find((x) => x.key === key)
    if (!g) {
      g = { key, label: moduleLabel(a.module), items: [] }
      out.push(g)
    }
    g.items.push(a)
  }
  return out.sort((x, y) => (x.key === '' ? -1 : y.key === '' ? 1 : 0))
})

const chips = computed(() => [
  { code: '', label: t('help.allTopics') },
  ...(data.value?.modules ?? []).map((m) => ({ code: m.code, label: pickText(locale.value, m.label, m.label_km) })),
])
</script>

<template>
  <div>
    <input
      v-model="search"
      type="search"
      :placeholder="t('help.searchArticles')"
      class="mb-3 block w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none sm:max-w-md"
    />
    <div v-if="chips.length > 2" class="mb-5 flex flex-wrap gap-2">
      <button
        v-for="c in chips"
        :key="c.code"
        type="button"
        class="rounded-full border px-3 py-1 text-sm transition"
        :class="module === c.code ? 'border-primary-500 bg-primary-500 text-secondary-900' : 'border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50'"
        :aria-pressed="module === c.code"
        @click="module = c.code"
      >
        {{ c.label }}
      </button>
    </div>

    <p v-if="(data?.articles ?? []).length === 0" class="rounded-lg border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('help.noArticles') }}</p>
    <p v-else-if="groups.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('help.noMatch') }}</p>

    <section v-for="g in groups" :key="g.key" class="mb-6">
      <h2 class="mb-2 text-sm font-semibold text-neutral-900">{{ g.label }}</h2>
      <div class="grid gap-3 sm:grid-cols-2">
        <RouterLink
          v-for="a in g.items"
          :key="a.id"
          :to="`/admin/help/articles/${a.id}`"
          class="block rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] transition hover:border-primary-300"
        >
          <p class="font-medium text-neutral-900">{{ pickText(locale, a.title_en, a.title_km) }}</p>
          <p class="mt-1 line-clamp-2 text-sm text-neutral-600">{{ pickText(locale, a.summary_en, a.summary_km) }}</p>
        </RouterLink>
      </div>
    </section>
  </div>
</template>
