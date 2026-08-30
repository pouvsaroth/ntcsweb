<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import { useAdminUiStore } from '@/stores/adminUi'
import type { LengthAwarePaginationMeta } from '@/types/api'

const props = defineProps<{
  meta: LengthAwarePaginationMeta
  /**
   * Pins the bar to the exact bottom of the viewport, so it's always
   * reachable on a long list without scrolling all the way down — `fixed`,
   * not `sticky`: a `sticky` element still rests relative to its
   * container's height, and that height visibly jumps every time a page
   * reload swaps the table for DataTable's single-row loading spinner
   * (much shorter than a full page of rows), so the bar would jump up and
   * down with it. `fixed` is anchored to the viewport itself, immune to
   * that. Opt-in because it assumes the admin layout's sidebar — reads its
   * current width from the shared store so the bar tracks it correctly
   * whether the sidebar is expanded or collapsed to its icon rail — not for
   * the public News/Events pages, which have no sidebar to clear.
   */
  sticky?: boolean
}>()
const emit = defineEmits<{ 'update:page': [page: number] }>()

const { t } = useI18n()
const adminUi = useAdminUiStore()

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
    :class="
      sticky
        ? [
            'fixed inset-x-0 bottom-0 z-10 border-t border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-6',
            adminUi.sidebarCollapsed ? 'lg:left-16' : 'lg:left-64',
          ]
        : ''
    "
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
              ? 'bg-primary-600 text-secondary-900'
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
