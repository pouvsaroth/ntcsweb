<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

import { quotationItemsApi, type QuotationItem, type QuotationItemInput, type QuotationItemLevel } from '../../api'
import { useSession } from '../../session'

/**
 * Billing > Quotation items: the console's menus > submenus > tabs, each
 * with a default USD / KHR price. Prices and "shown" save as soon as they
 * change; names through a small form.
 */
const { t } = useI18n()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => session.can('platform.billing.manage'))

const tree = ref<QuotationItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const savedId = ref<number | null>(null)
const search = ref('')
const open = reactive(new Set<number>())

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    tree.value = await quotationItemsApi.tree()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

interface Row {
  item: QuotationItem
  depth: number
}

/** The rows shown: open branches, or — while searching — every match with the menus above it. */
const rows = computed<Row[]>(() => {
  const q = search.value.trim().toLowerCase()
  const matches = (i: QuotationItem): boolean =>
    [i.name, i.name_km ?? '', i.code ?? ''].some((s) => s.toLowerCase().includes(q)) || i.children.some(matches)
  const out: Row[] = []
  const walk = (items: QuotationItem[], depth: number) => {
    for (const item of items) {
      if (q && !matches(item)) continue
      out.push({ item, depth })
      if (q || open.has(item.id)) walk(item.children, depth + 1)
    }
  }
  walk(tree.value, 0)
  return out
})

function toggle(id: number): void {
  if (open.has(id)) open.delete(id)
  else open.add(id)
}
function expandAll(): void {
  const walk = (items: QuotationItem[]) => items.forEach((i) => (i.children.length && open.add(i.id), walk(i.children)))
  walk(tree.value)
}

const levelVariant = { menu: 'primary', submenu: 'success', tab: 'neutral' } as const

async function patch(item: QuotationItem, body: QuotationItemInput): Promise<void> {
  error.value = null
  try {
    const updated = await quotationItemsApi.update(item.id, body)
    Object.assign(item, { ...updated, children: item.children })
    savedId.value = item.id
    setTimeout(() => savedId.value === item.id && (savedId.value = null), 1500)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

function onPrice(item: QuotationItem, field: 'price_usd' | 'price_khr', raw: string): void {
  const value = Math.max(0, Number(raw) || 0)
  if (value !== item[field]) void patch(item, { [field]: value })
}

// ── add / rename ───────────────────────────────────────────────────────────
const formOpen = ref(false)
const formParent = ref<QuotationItem | null>(null)
const formItem = ref<QuotationItem | null>(null)
const form = reactive({ name: '', name_km: '', code: '', price_usd: '0', price_khr: '0' })
const formBusy = ref(false)
const formError = ref<string | null>(null)
const formLevel = computed<QuotationItemLevel>(() => formItem.value?.level ?? (formParent.value ? (formParent.value.level === 'menu' ? 'submenu' : 'tab') : 'menu'))

function openForm(parent: QuotationItem | null, item: QuotationItem | null = null): void {
  formParent.value = parent
  formItem.value = item
  form.name = item?.name ?? ''
  form.name_km = item?.name_km ?? ''
  form.code = item?.code ?? ''
  form.price_usd = String(item?.price_usd ?? 0)
  form.price_khr = String(item?.price_khr ?? 0)
  formError.value = null
  formOpen.value = true
}

async function saveForm(): Promise<void> {
  formBusy.value = true
  formError.value = null
  const body: QuotationItemInput = {
    name: form.name.trim(),
    name_km: form.name_km.trim() || null,
    code: form.code.trim() || null,
    price_usd: Number(form.price_usd) || 0,
    price_khr: Number(form.price_khr) || 0,
  }
  try {
    if (formItem.value) {
      await patch(formItem.value, body)
    } else {
      const created = await quotationItemsApi.create({ ...body, parent_id: formParent.value?.id ?? null })
      if (formParent.value) {
        formParent.value.children.push(created)
        open.add(formParent.value.id)
      } else {
        tree.value.push(created)
      }
    }
    formOpen.value = false
  } catch (e) {
    formError.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    formBusy.value = false
  }
}

async function remove(item: QuotationItem): Promise<void> {
  if (!(await confirmDialog.confirm({ message: t('bo.billing.qItems.deleteConfirm', { name: item.name }), danger: true }))) return
  try {
    await quotationItemsApi.remove(item.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  }
}

onMounted(load)
</script>

<template>
  <div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('bo.billing.qItems.hint') }}</p>

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
      <div class="min-w-0 flex-1"><BaseInput v-model="search" :placeholder="t('bo.billing.qItems.search')" /></div>
      <div class="flex flex-wrap gap-2">
        <BaseButton size="sm" variant="ghost" @click="expandAll">{{ t('bo.billing.qItems.expandAll') }}</BaseButton>
        <BaseButton size="sm" variant="ghost" @click="open.clear()">{{ t('bo.billing.qItems.collapseAll') }}</BaseButton>
        <BaseButton v-if="canManage" size="sm" @click="openForm(null)">+ {{ t('bo.billing.qItems.addMenu') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading && !tree.length" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="rows.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('bo.billing.qItems.empty') }}
    </p>

    <ul v-else class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
      <li
        v-for="{ item, depth } in rows"
        :key="item.id"
        class="flex flex-col gap-2 px-3 py-2 lg:flex-row lg:items-center"
        :class="[!item.active ? 'opacity-60' : '', item.level === 'menu' ? 'bg-neutral-50' : '']"
      >
        <div class="flex min-w-0 flex-1 items-center gap-2" :style="{ paddingLeft: `${depth * 1.25}rem` }">
          <button
            type="button"
            class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200"
            :class="item.children.length ? '' : 'invisible'"
            :aria-label="item.name"
            @click="toggle(item.id)"
          >
            {{ open.has(item.id) || search ? '▾' : '▸' }}
          </button>
          <BaseBadge :variant="levelVariant[item.level]" class="shrink-0">{{ t(`bo.billing.qItems.levels.${item.level}`) }}</BaseBadge>
          <div class="min-w-0">
            <p class="truncate text-sm text-neutral-900" :class="item.level === 'menu' ? 'font-semibold' : ''">
              <span v-if="item.code" class="mr-1 font-mono text-xs text-neutral-400">{{ item.code }}</span>{{ item.name }}
            </p>
            <p v-if="item.name_km" class="truncate text-xs text-neutral-500">{{ item.name_km }}</p>
          </div>
          <span v-if="savedId === item.id" class="shrink-0 text-xs text-green-600">✓ {{ t('bo.billing.qItems.saved') }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 pl-8 lg:flex-nowrap lg:pl-0">
          <label class="flex items-center gap-1 text-xs text-neutral-500">
            $
            <input
              type="number"
              min="0"
              step="0.01"
              :value="item.price_usd"
              :disabled="!canManage"
              :aria-label="t('bo.billing.qItems.priceUsd')"
              class="w-24 rounded-md border border-neutral-300 px-2 py-1 text-right text-sm tabular-nums disabled:bg-neutral-100"
              @change="onPrice(item, 'price_usd', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="flex items-center gap-1 text-xs text-neutral-500">
            ៛
            <input
              type="number"
              min="0"
              step="100"
              :value="item.price_khr"
              :disabled="!canManage"
              :aria-label="t('bo.billing.qItems.priceKhr')"
              class="w-28 rounded-md border border-neutral-300 px-2 py-1 text-right text-sm tabular-nums disabled:bg-neutral-100"
              @change="onPrice(item, 'price_khr', ($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="flex w-20 items-center gap-1 text-xs text-neutral-600" :title="t('bo.billing.qItems.active')">
            <input
              type="checkbox"
              :checked="item.active"
              :disabled="!canManage"
              class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500"
              @change="patch(item, { active: ($event.target as HTMLInputElement).checked })"
            />
            {{ item.active ? t('bo.billing.qItems.shown') : t('bo.billing.qItems.hidden') }}
          </label>
          <div v-if="canManage" class="flex items-center">
            <BaseButton
              size="sm"
              variant="ghost"
              :class="item.level === 'tab' ? 'invisible' : ''"
              :title="t('bo.billing.qItems.addUnder', { name: item.name })"
              @click="openForm(item)"
            >
              +
            </BaseButton>
            <BaseButton size="sm" variant="ghost" @click="openForm(null, item)">{{ t('bo.billing.qItems.edit') }}</BaseButton>
            <BaseButton size="sm" variant="ghost" @click="remove(item)">{{ t('bo.billing.qItems.delete') }}</BaseButton>
          </div>
        </div>
      </li>
    </ul>

    <BaseModal v-model="formOpen" :title="formItem ? formItem.name : t('bo.billing.qItems.newItem', { level: t(`bo.billing.qItems.levels.${formLevel}`) })">
      <BaseAlert v-if="formError" variant="danger" class="mb-4">{{ formError }}</BaseAlert>
      <p v-if="formParent" class="mb-3 text-sm text-neutral-500">{{ t('bo.billing.qItems.addUnder', { name: formParent.name }) }}</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <BaseInput v-model="form.name" :label="t('bo.billing.qItems.name')" required class="sm:col-span-2" />
        <BaseInput v-model="form.name_km" :label="t('bo.billing.qItems.nameKm')" class="sm:col-span-2" />
        <BaseInput v-model="form.code" :label="t('bo.billing.qItems.code')" />
        <span class="hidden sm:block" />
        <BaseInput v-model="form.price_usd" type="number" :label="t('bo.billing.qItems.priceUsd')" />
        <BaseInput v-model="form.price_khr" type="number" :label="t('bo.billing.qItems.priceKhr')" />
      </div>
      <template #footer>
        <BaseButton variant="ghost" @click="formOpen = false">{{ t('bo.common.cancel') }}</BaseButton>
        <BaseButton :loading="formBusy" :disabled="!form.name.trim()" @click="saveForm">{{ t('bo.common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
