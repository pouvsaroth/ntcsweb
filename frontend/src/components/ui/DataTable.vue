<script setup lang="ts" generic="T extends Record<string, unknown>">
import { useI18n } from 'vue-i18n'

import BaseSpinner from './BaseSpinner.vue'

const { t } = useI18n()

interface Column {
  key: string
  label: string
  sortable?: boolean
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
}>()

const emit = defineEmits<{ sort: [column: string] }>()

function sortIndicator(column: Column): '↑' | '↓' | null {
  if (!props.sort) return null
  const active = props.sort.replace(/^-/, '')
  if (active !== column.key) return null
  return props.sort.startsWith('-') ? '↓' : '↑'
}
</script>

<template>
  <div class="overflow-x-auto rounded-[--radius-card] border border-neutral-200 bg-white">
    <table class="w-full min-w-max text-left text-sm">
      <thead class="border-b border-neutral-200 bg-neutral-50">
        <tr>
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
              @click="emit('sort', column.key)"
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
          <td :colspan="columns.length" class="px-4 py-10 text-center text-neutral-400">
            <BaseSpinner class="mx-auto" />
          </td>
        </tr>

        <tr v-else-if="rows.length === 0">
          <td :colspan="columns.length" class="px-4 py-10 text-center text-neutral-400">
            {{ emptyMessage ?? t('common.noRecordsFound') }}
          </td>
        </tr>

        <tr v-for="row in rows" v-else :key="String(row[rowKey])" class="hover:bg-neutral-50">
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
