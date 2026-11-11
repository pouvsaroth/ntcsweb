<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { formatMoney } from '@/utils/currency'

import type { QuotationCurrency, QuotationItem } from '../api'

/**
 * Tick console menus / submenus / tabs to quote. Each ticked item becomes
 * one line at its default price in the quote's currency. Hidden items and
 * items already on the quote can't be ticked.
 */
const props = defineProps<{ modelValue: boolean; tree: QuotationItem[]; currency: QuotationCurrency; existing: number[] }>()
const emit = defineEmits<{ 'update:modelValue': [open: boolean]; add: [items: QuotationItem[]] }>()

const { t, locale } = useI18n()

const search = ref('')
const picked = reactive(new Set<number>())
const open = reactive(new Set<number>())

watch(
  () => props.modelValue,
  (isOpen) => {
    if (!isOpen) return
    picked.clear()
    search.value = ''
  },
)

const label = (i: QuotationItem) => (locale.value === 'km' && i.name_km ? i.name_km : i.name)
const price = (i: QuotationItem) => (props.currency === 'KHR' ? i.price_khr : i.price_usd)
const levelVariant = { menu: 'primary', submenu: 'success', tab: 'neutral' } as const

interface Row {
  item: QuotationItem
  depth: number
}
const rows = computed<Row[]>(() => {
  const q = search.value.trim().toLowerCase()
  const matches = (i: QuotationItem): boolean =>
    [i.name, i.name_km ?? '', i.code ?? ''].some((s) => s.toLowerCase().includes(q)) || i.children.some(matches)
  const out: Row[] = []
  const walk = (items: QuotationItem[], depth: number) => {
    for (const item of items) {
      if (!item.active) continue
      if (q && !matches(item)) continue
      out.push({ item, depth })
      if (q || open.has(item.id)) walk(item.children, depth + 1)
    }
  }
  walk(props.tree, 0)
  return out
})

function toggleOpen(id: number): void {
  if (open.has(id)) open.delete(id)
  else open.add(id)
}

function togglePick(id: number, on: boolean): void {
  if (on) picked.add(id)
  else picked.delete(id)
}

function add(): void {
  const chosen: QuotationItem[] = []
  const walk = (items: QuotationItem[]) => items.forEach((i) => (picked.has(i.id) && chosen.push(i), walk(i.children)))
  walk(props.tree)
  emit('add', chosen)
  emit('update:modelValue', false)
}
</script>

<template>
  <BaseModal :model-value="modelValue" :title="t('bo.billing.quotes.pickerTitle')" size="lg" @update:model-value="emit('update:modelValue', $event)">
    <p class="mb-3 text-sm text-neutral-500">{{ t('bo.billing.quotes.pickerHint') }}</p>
    <div class="mb-3"><BaseInput v-model="search" :placeholder="t('bo.billing.quotes.pickerSearch')" /></div>

    <ul class="max-h-[55vh] divide-y divide-neutral-100 overflow-y-auto rounded-lg border border-neutral-200">
      <li v-for="{ item, depth } in rows" :key="item.id" class="flex items-center gap-2 px-2 py-1.5" :class="item.level === 'menu' ? 'bg-neutral-50' : ''">
        <span :style="{ width: `${depth * 1.1}rem` }" class="shrink-0" />
        <button
          type="button"
          class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200"
          :class="item.children.length && !search ? '' : 'invisible'"
          :aria-label="item.name"
          @click="toggleOpen(item.id)"
        >
          {{ open.has(item.id) ? '▾' : '▸' }}
        </button>
        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2" :class="existing.includes(item.id) ? 'cursor-not-allowed opacity-50' : ''" :title="existing.includes(item.id) ? t('bo.billing.quotes.alreadyAdded') : ''">
          <input
            type="checkbox"
            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
            :checked="picked.has(item.id) || existing.includes(item.id)"
            :disabled="existing.includes(item.id)"
            @change="togglePick(item.id, ($event.target as HTMLInputElement).checked)"
          />
          <BaseBadge :variant="levelVariant[item.level]" class="shrink-0">{{ t(`bo.billing.qItems.levels.${item.level}`) }}</BaseBadge>
          <span class="min-w-0 truncate text-sm text-neutral-800" :class="item.level === 'menu' ? 'font-semibold' : ''">{{ label(item) }}</span>
        </label>
        <span class="shrink-0 text-xs tabular-nums text-neutral-500">{{ formatMoney(price(item), currency) }}</span>
      </li>
    </ul>

    <template #footer>
      <BaseButton variant="ghost" @click="emit('update:modelValue', false)">{{ t('bo.common.cancel') }}</BaseButton>
      <BaseButton :disabled="picked.size === 0" @click="add">{{ t('bo.billing.quotes.pickerAdd', { n: picked.size }) }}</BaseButton>
    </template>
  </BaseModal>
</template>
