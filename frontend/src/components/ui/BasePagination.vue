<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import type { LengthAwarePaginationMeta } from '@/types/api'

const props = defineProps<{ meta: LengthAwarePaginationMeta }>()
const emit = defineEmits<{ 'update:page': [page: number] }>()

const { t } = useI18n()

/** A compact page-number window (max 7 slots) with ellipses, not a button per page — a table can have thousands of pages. */
const pages = computed<(number | '…')[]>(() => {
  const { current_page: current, last_page: last } = props.meta
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)

  const result: (number | '…')[] = [1]
  const start = Math.max(2, current - 1)
  const end = Math.min(last - 1, current + 1)

  if (start > 2) result.push('…')
  for (let p = start; p <= end; p++) result.push(p)
  if (end < last - 1) result.push('…')
  result.push(last)

  return result
})
</script>

<template>
  <nav
    v-if="meta.last_page > 1"
    class="flex flex-col items-center justify-between gap-3 sm:flex-row"
    :aria-label="t('common.pagination')"
  >
    <!--
      i18n-t (not a plain interpolated string) so each locale keeps its own
      word order around the bolded numbers — Japanese/Korean put "of {total}"
      before the range, English puts it after; a hardcoded HTML layout here
      would only ever be correct for one of those orderings.
    -->
    <i18n-t keypath="common.showingResults" tag="p" class="text-sm text-neutral-500">
      <template #from><span class="font-medium text-neutral-700">{{ meta.from ?? 0 }}</span></template>
      <template #to><span class="font-medium text-neutral-700">{{ meta.to ?? 0 }}</span></template>
      <template #total><span class="font-medium text-neutral-700">{{ meta.total }}</span></template>
    </i18n-t>

    <div class="flex items-center gap-1">
      <button
        type="button"
        class="rounded-md px-2.5 py-1.5 text-sm text-neutral-600 hover:bg-neutral-100 disabled:opacity-40 disabled:pointer-events-none"
        :disabled="meta.current_page <= 1"
        :aria-label="t('common.previousPage')"
        @click="emit('update:page', meta.current_page - 1)"
      >
        ‹
      </button>

      <template v-for="(p, i) in pages" :key="`${p}-${i}`">
        <span v-if="p === '…'" class="px-2 text-sm text-neutral-400">…</span>
        <button
          v-else
          type="button"
          class="min-w-[2rem] rounded-md px-2.5 py-1.5 text-sm"
          :class="
            p === meta.current_page
              ? 'bg-primary-600 text-white'
              : 'text-neutral-600 hover:bg-neutral-100'
          "
          :aria-current="p === meta.current_page ? 'page' : undefined"
          @click="emit('update:page', p)"
        >
          {{ p }}
        </button>
      </template>

      <button
        type="button"
        class="rounded-md px-2.5 py-1.5 text-sm text-neutral-600 hover:bg-neutral-100 disabled:opacity-40 disabled:pointer-events-none"
        :disabled="meta.current_page >= meta.last_page"
        :aria-label="t('common.nextPage')"
        @click="emit('update:page', meta.current_page + 1)"
      >
        ›
      </button>
    </div>
  </nav>
</template>
