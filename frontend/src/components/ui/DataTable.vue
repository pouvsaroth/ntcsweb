<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseSpinner from './BaseSpinner.vue'

const { t } = useI18n()

interface Column {
  key: string
  label: string
  sortable?: boolean
  /** The field name the backend actually sorts by, when it differs from `key` (e.g. a displayed "Name" column backed by `first_name`). Defaults to `key`. */
  sortKey?: string
  /** Tailwind alignment class override, e.g. 'text-right' for numeric columns. */
  align?: string
}

const props = defineProps<{
  columns: Column[]
  rows: T[]
  rowKey: keyof T
  loading?: boolean
  emptyMessage?: string
  sort?: string
  /** Adds a leading checkbox column — opt-in, so every existing table stays unchanged. */
  selectable?: boolean
  /** Row-key values of the currently selected rows — only meaningful when `selectable` is set. */
  selected?: unknown[]
}>()

const emit = defineEmits<{ sort: [column: string]; 'update:selected': [value: unknown[]] }>()

function sortIndicator(column: Column): '↑' | '↓' | null {
  if (!props.sort) return null
  const active = props.sort.replace(/^-/, '')
  if (active !== (column.sortKey ?? column.key)) return null
  return props.sort.startsWith('-') ? '↓' : '↑'
}

function isSelected(row: T): boolean {
  return props.selected?.includes(row[props.rowKey]) ?? false
}

function toggleRow(row: T, checked: boolean): void {
  const current = props.selected ?? []
  const key = row[props.rowKey]
  emit('update:selected', checked ? [...current, key] : current.filter((k) => k !== key))
}

/** Selects/deselects every row currently rendered (this page only), leaving any selection from other pages untouched. */
function toggleAllOnPage(checked: boolean): void {
  const current = props.selected ?? []
  const pageKeys: unknown[] = props.rows.map((row) => row[props.rowKey])
  emit('update:selected', checked ? [...new Set([...current, ...pageKeys])] : current.filter((k) => !pageKeys.includes(k)))
}

const allOnPageSelected = computed(() => props.rows.length > 0 && props.rows.every((row) => isSelected(row)))
</script>

<template>
  <div class="overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white">
    <table class="w-full min-w-max text-left text-sm">
      <thead class="border-b border-neutral-200 bg-neutral-50">
        <tr>
          <th v-if="selectable" scope="col" class="w-10 px-4 py-3">
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="allOnPageSelected"
              :aria-label="t('common.selectAll')"
              @change="toggleAllOnPage(($event.target as HTMLInputElement).checked)"
            />
          </th>
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            class="px-4 py-3 font-medium text-neutral-600"
            :class="column.align"
          >
            <button
              v-if="column.sortable"
              type="button"
              class="inline-flex items-center gap-1 hover:text-neutral-900"
              @click="emit('sort', column.sortKey ?? column.key)"
            >
              {{ column.label }}
              <span class="w-3 text-neutral-400">{{ sortIndicator(column) }}</span>
            </button>
            <template v-else>{{ column.label }}</template>
          </th>
        </tr>
      </thead>

      <tbody class="divide-y divide-neutral-100">
        <tr v-if="loading">
          <td :colspan="selectable ? columns.length + 1 : columns.length" class="px-4 py-10 text-center text-neutral-400">
            <BaseSpinner class="mx-auto" />
          </td>
        </tr>

        <tr v-else-if="rows.length === 0">
          <td :colspan="selectable ? columns.length + 1 : columns.length" class="px-4 py-10 text-center text-neutral-400">
            {{ emptyMessage ?? t('common.noRecordsFound') }}
          </td>
        </tr>

        <tr v-for="row in rows" v-else :key="String(row[rowKey])" class="hover:bg-neutral-50">
          <td v-if="selectable" class="px-4 py-3">
            <input
              type="checkbox"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              :checked="isSelected(row)"
              :aria-label="t('common.selectRow')"
              @change="toggleRow(row, ($event.target as HTMLInputElement).checked)"
            />
          </td>
          <td v-for="column in columns" :key="column.key" class="px-4 py-3 text-neutral-700" :class="column.align">
            <slot :name="`cell-${column.key}`" :row="row">
              {{ row[column.key] }}
            </slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
