<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import { useHelpCenter } from '@/composables/useHelpCenter'
import { pickText } from '@/utils/helpText'

/** Help & Support → FAQ: search; each question opens to its answer (all open while searching). */
const { t, locale } = useI18n()
const { data } = useHelpCenter()

const search = ref('')
const openIds = ref<number[]>([])
const searching = computed(() => search.value.trim() !== '')

const faqs = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (data.value?.faqs ?? [])
    .map((f) => ({ id: f.id, question: pickText(locale.value, f.question_en, f.question_km), answer: pickText(locale.value, f.answer_en, f.answer_km) }))
    .filter((f) => !q || f.question.toLowerCase().includes(q) || f.answer.toLowerCase().includes(q))
})

const isOpen = (id: number) => searching.value || openIds.value.includes(id)
function toggle(id: number) {
  openIds.value = openIds.value.includes(id) ? openIds.value.filter((x) => x !== id) : [...openIds.value, id]
}
</script>

<template>
  <div class="max-w-3xl">
    <input
      v-model="search"
      type="search"
      :placeholder="t('help.searchFaq')"
      class="mb-4 block w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-sm shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-200 focus:outline-none sm:max-w-md"
    />

    <p v-if="(data?.faqs ?? []).length === 0" class="rounded-lg border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('help.noFaq') }}</p>
    <p v-else-if="faqs.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">{{ t('help.noMatch') }}</p>

    <ul v-else class="divide-y divide-neutral-200 overflow-hidden rounded-[--radius-card] border border-neutral-200 bg-white shadow-[--shadow-card]">
      <li v-for="f in faqs" :key="f.id">
        <button type="button" class="flex w-full items-start justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" :aria-expanded="isOpen(f.id)" @click="toggle(f.id)">
          <span class="font-medium text-neutral-900">{{ f.question }}</span>
          <svg class="mt-0.5 h-5 w-5 shrink-0 text-neutral-400 transition" :class="{ 'rotate-180': isOpen(f.id) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <p v-if="isOpen(f.id)" class="px-4 pb-4 text-sm whitespace-pre-line text-neutral-700">{{ f.answer }}</p>
      </li>
    </ul>
  </div>
</template>
