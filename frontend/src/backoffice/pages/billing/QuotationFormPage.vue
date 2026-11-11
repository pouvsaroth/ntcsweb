<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'

import {
  quotationItemsApi,
  quotationsApi,
  schoolsApi,
  type Quotation,
  type QuotationCurrency,
  type QuotationInput,
  type QuotationItem,
  type QuotationLine,
  type QuotationStatus,
  type School,
} from '../../api'
import QuotationItemPicker from '../../components/QuotationItemPicker.vue'
import { useSession } from '../../session'

/**
 * One quotation: who it is for, currency and dates, the lines (picked from
 * the catalog or typed by hand), discount, tax and notes. Totals shown here
 * are a preview; the server works out the saved ones the same way.
 */
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const confirmDialog = useConfirmDialogStore()
const canManage = computed(() => session.can('platform.billing.manage'))

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const quotation = ref<Quotation | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const form = reactive<Omit<QuotationInput, 'lines'>>({
  tenant_id: null,
  customer_name: '',
  contact_name: null,
  phone: null,
  email: null,
  address: null,
  currency: 'USD',
  issue_date: today(),
  valid_until: null,
  discount_type: 'amount',
  discount_value: 0,
  tax_percent: 0,
  notes: null,
  status: 'draft',
})

interface DraftLine extends QuotationLine {
  key: number
}
let nextKey = 1
const lines = ref<DraftLine[]>([])

// ── customer ───────────────────────────────────────────────────────────────
const customerMode = ref<'existing' | 'prospect'>('prospect')
const schools = ref<School[]>([])
const schoolOptions = computed(() => schools.value.map((s) => ({ value: String(s.id), label: s.name, hint: s.slug })))
const tenantValue = computed({
  get: () => (form.tenant_id ? String(form.tenant_id) : ''),
  set: (v: string) => {
    form.tenant_id = v ? Number(v) : null
    const s = schools.value.find((x) => x.id === form.tenant_id)
    if (s) {
      form.customer_name = s.name
      form.phone ||= s.phone
      form.email ||= s.email
      form.contact_name ||= s.owner?.name ?? null
    }
  },
})
watch(customerMode, (mode) => {
  if (mode === 'prospect') form.tenant_id = null
})

// ── catalog + lines ────────────────────────────────────────────────────────
const catalog = ref<QuotationItem[]>([])
const catalogById = computed(() => {
  const map = new Map<number, QuotationItem>()
  const walk = (items: QuotationItem[]) => items.forEach((i) => (map.set(i.id, i), walk(i.children)))
  walk(catalog.value)
  return map
})
const parentLabel = (item: QuotationItem): string | null => {
  const names: string[] = []
  let p = item.parent_id ? catalogById.value.get(item.parent_id) : undefined
  while (p) {
    names.unshift(locale.value === 'km' && p.name_km ? p.name_km : p.name)
    p = p.parent_id ? catalogById.value.get(p.parent_id) : undefined
  }
  return names.length ? names.join(' › ') : null
}

const pickerOpen = ref(false)
const existingIds = computed(() => lines.value.map((l) => l.item_id).filter((x): x is number => x !== null))

function addItems(items: QuotationItem[]): void {
  for (const item of items) {
    lines.value.push({
      key: nextKey++,
      item_id: item.id,
      level: item.level,
      code: item.code,
      name: item.name,
      name_km: item.name_km,
      parent_label: parentLabel(item),
      quantity: 1,
      unit_price: form.currency === 'KHR' ? item.price_khr : item.price_usd,
      description: null,
    })
  }
}

function addCustomLine(): void {
  lines.value.push({ key: nextKey++, item_id: null, name: t('bo.billing.quotes.newLineName'), quantity: 1, unit_price: 0, description: null })
}

/** When the currency changes, offer to take the catalog's prices in the new one. */
watch(
  () => form.currency,
  async (cur, prev) => {
    if (!prev || cur === prev || !lines.value.some((l) => l.item_id)) return
    if (!(await confirmDialog.confirm({ message: t('bo.billing.quotes.repriceConfirm', { currency: cur }) }))) return
    for (const l of lines.value) {
      const item = l.item_id ? catalogById.value.get(l.item_id) : undefined
      if (item) l.unit_price = cur === 'KHR' ? item.price_khr : item.price_usd
    }
  },
)

// ── totals (preview; the server's are the saved ones) ──────────────────────
const decimals = computed(() => (form.currency === 'KHR' ? 0 : 2))
const round = (n: number) => {
  const f = 10 ** decimals.value
  return Math.round((n + Number.EPSILON) * f) / f
}
const lineAmount = (l: QuotationLine) => round(round(Number(l.quantity) || 0) * round(Number(l.unit_price) || 0))
const subtotal = computed(() => round(lines.value.reduce((s, l) => s + lineAmount(l), 0)))
const discount = computed(() => {
  const v = Number(form.discount_value) || 0
  return form.discount_type === 'percent' ? round((subtotal.value * Math.min(v, 100)) / 100) : Math.min(round(v), subtotal.value)
})
const tax = computed(() => round(((subtotal.value - discount.value) * (Number(form.tax_percent) || 0)) / 100))
const total = computed(() => round(subtotal.value - discount.value + tax.value))
const money = (n: number) => formatMoney(n, form.currency)

const currencyOptions = [
  { value: 'USD', label: 'USD ($)' },
  { value: 'KHR', label: 'KHR (៛)' },
]
const statusOptions = computed(() => (['draft', 'sent', 'accepted', 'rejected'] as const).map((s) => ({ value: s, label: t(`bo.billing.quotes.statuses.${s}`) })))
const discountTypeOptions = computed(() => [
  { value: 'amount', label: t('bo.billing.quotes.discountAmount') },
  { value: 'percent', label: t('bo.billing.quotes.discountPercent') },
])

// ── load / save ────────────────────────────────────────────────────────────
function fill(q: Quotation): void {
  quotation.value = q
  Object.assign(form, {
    tenant_id: q.tenant_id,
    customer_name: q.customer_name,
    contact_name: q.contact_name,
    phone: q.phone,
    email: q.email,
    address: q.address,
    currency: q.currency,
    issue_date: q.issue_date,
    valid_until: q.valid_until,
    discount_type: q.discount_type,
    discount_value: q.discount_value,
    tax_percent: q.tax_percent,
    notes: q.notes,
    status: q.status,
  })
  customerMode.value = q.tenant_id ? 'existing' : 'prospect'
  lines.value = (q.lines ?? []).map((l) => ({ ...l, key: nextKey++ }))
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [tree, schoolPage, q] = await Promise.all([
      quotationItemsApi.tree(),
      schoolsApi.list({ page: 1, per_page: 200, sort: 'name' }).catch(() => ({ data: [] as School[] })),
      id.value ? quotationsApi.show(id.value) : Promise.resolve(null),
    ])
    catalog.value = tree
    schools.value = schoolPage.data
    if (q) fill(q)
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('bo.common.failed')
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  notice.value = null
  fieldErrors.value = {}
  const body: QuotationInput = {
    ...form,
    valid_until: form.valid_until || null,
    lines: lines.value.map((l) => ({
      item_id: l.item_id,
      name: l.name,
      name_km: l.name_km ?? null,
      description: l.description || null,
      quantity: Number(l.quantity) || 0,
      unit_price: Number(l.unit_price) || 0,
    })),
  }
  try {
    const saved = id.value ? await quotationsApi.update(id.value, body) : await quotationsApi.create(body)
    fill(saved)
    notice.value = t('bo.billing.quotes.saved', { number: saved.number })
    if (!id.value) await router.replace(`/billing/quotations/${saved.id}`)
  } catch (e) {
    if (e instanceof ApiRequestError) {
      error.value = e.message
      fieldErrors.value = (e.errors ?? {}) as Record<string, string[]>
    } else {
      error.value = t('bo.common.failed')
    }
  } finally {
    saving.value = false
  }
}

// ── export (saved quotes only) ─────────────────────────────────────────────
const exportLang = ref<'en' | 'km'>(locale.value === 'km' ? 'km' : 'en')
const exportFormats = computed(() => [
  { format: 'xlsx' as const, label: t('bo.billing.quotes.excel') },
  { format: 'pdf' as const, label: t('bo.billing.quotes.pdf') },
  { format: 'png' as const, label: t('bo.billing.quotes.image') },
])

const lineName = (l: QuotationLine) => (locale.value === 'km' && l.name_km ? l.name_km : l.name)
const lineParent = (l: QuotationLine) => (locale.value === 'km' && l.parent_label_km ? l.parent_label_km : l.parent_label)

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
      <div>
        <RouterLink to="/billing/quotations" class="text-sm text-neutral-500 hover:underline">← {{ t('bo.billing.quotes.back') }}</RouterLink>
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ quotation ? t('bo.billing.quotes.editTitle', { number: quotation.number }) : t('bo.billing.quotes.newQuote') }}
        </h2>
      </div>
      <BaseButton v-if="canManage" :loading="saving" :disabled="loading" @click="save">{{ t('bo.billing.quotes.save') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="notice" variant="success" class="mb-4">{{ notice }}</BaseAlert>
    <section
      v-if="!loading && quotation"
      class="mb-4 flex flex-col gap-2 rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card] sm:flex-row sm:items-center"
    >
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-semibold text-neutral-800">{{ t('bo.billing.quotes.export') }}</span>
        <select v-model="exportLang" :aria-label="t('bo.billing.quotes.exportLang')" class="rounded-md border border-neutral-300 py-1 pr-8 pl-2 text-sm">
          <option value="en">English</option>
          <option value="km">ខ្មែរ</option>
        </select>
        <BaseButton
          v-for="f in exportFormats"
          :key="f.format"
          size="sm"
          variant="outline"
          :href="quotationsApi.exportUrl(quotation.id, f.format, exportLang)"
        >
          {{ f.label }}
        </BaseButton>
      </div>
      <p class="text-xs text-neutral-500 sm:ml-auto">{{ t('bo.billing.quotes.exportHint') }}</p>
    </section>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <fieldset v-else :disabled="!canManage" class="space-y-4">
      <div class="grid gap-4 lg:grid-cols-2">
        <!-- Customer -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h3 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('bo.billing.quotes.customerSection') }}</h3>
          <div class="mb-3 flex gap-4 text-sm">
            <label class="flex items-center gap-1.5"><input v-model="customerMode" type="radio" value="prospect" class="text-primary-600" /> {{ t('bo.billing.quotes.prospect') }}</label>
            <label class="flex items-center gap-1.5"><input v-model="customerMode" type="radio" value="existing" class="text-primary-600" /> {{ t('bo.billing.quotes.existingCustomer') }}</label>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <SearchableSelect v-if="customerMode === 'existing'" v-model="tenantValue" :options="schoolOptions" :placeholder="t('bo.billing.quotes.pickCustomer')" class="sm:col-span-2" />
            <BaseInput v-model="form.customer_name" :label="t('bo.billing.quotes.customerName')" required class="sm:col-span-2" :error="fieldErrors.customer_name?.[0]" />
            <BaseInput :model-value="form.contact_name ?? ''" :label="t('bo.billing.quotes.contactName')" @update:model-value="form.contact_name = $event || null" />
            <BaseInput :model-value="form.phone ?? ''" :label="t('bo.billing.quotes.phone')" @update:model-value="form.phone = $event || null" />
            <BaseInput :model-value="form.email ?? ''" type="email" :label="t('bo.billing.quotes.email')" :error="fieldErrors.email?.[0]" class="sm:col-span-2" @update:model-value="form.email = $event || null" />
            <BaseInput :model-value="form.address ?? ''" :label="t('bo.billing.quotes.address')" class="sm:col-span-2" @update:model-value="form.address = $event || null" />
          </div>
        </section>

        <!-- Details -->
        <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
          <h3 class="mb-3 text-sm font-semibold text-neutral-800">{{ t('bo.billing.quotes.details') }}</h3>
          <div class="grid gap-3 sm:grid-cols-2">
            <BaseSelect :model-value="form.currency" :label="t('bo.billing.quotes.currency')" :options="currencyOptions" @update:model-value="form.currency = $event as QuotationCurrency" />
            <BaseSelect :model-value="form.status" :label="t('bo.billing.quotes.status')" :options="statusOptions" @update:model-value="form.status = $event as QuotationStatus" />
            <div>
              <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.quotes.date') }}</label>
              <DateInput v-model="form.issue_date" required />
            </div>
            <div>
              <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.quotes.validUntil') }}</label>
              <DateInput :model-value="form.valid_until" @update:model-value="form.valid_until = $event || null" />
              <p v-if="fieldErrors.valid_until?.[0]" class="mt-1 text-xs text-red-600">{{ fieldErrors.valid_until[0] }}</p>
            </div>
          </div>
        </section>
      </div>

      <!-- Lines -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-semibold text-neutral-800">{{ t('bo.billing.quotes.itemsSection') }}</h3>
          <div v-if="canManage" class="flex flex-wrap gap-2">
            <BaseButton size="sm" @click="pickerOpen = true">+ {{ t('bo.billing.quotes.addItems') }}</BaseButton>
            <BaseButton size="sm" variant="outline" @click="addCustomLine">+ {{ t('bo.billing.quotes.addCustomLine') }}</BaseButton>
          </div>
        </div>
        <p v-if="fieldErrors.lines?.[0]" class="mb-2 text-sm text-red-600">{{ fieldErrors.lines[0] }}</p>

        <p v-if="lines.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('bo.billing.quotes.noLines') }}</p>
        <div v-else class="space-y-2 lg:space-y-0">
          <div class="hidden grid-cols-[minmax(0,2fr)_minmax(0,2fr)_5rem_8rem_8rem_2rem] gap-2 border-b border-neutral-200 pb-1 text-xs font-medium text-neutral-500 lg:grid">
            <span>{{ t('bo.billing.quotes.item') }}</span>
            <span>{{ t('bo.billing.quotes.description') }}</span>
            <span class="text-right">{{ t('bo.billing.quotes.qty') }}</span>
            <span class="text-right">{{ t('bo.billing.quotes.unitPrice') }}</span>
            <span class="text-right">{{ t('bo.billing.quotes.amount') }}</span>
            <span />
          </div>
          <div
            v-for="(line, i) in lines"
            :key="line.key"
            class="grid gap-2 rounded-lg border border-neutral-200 p-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_5rem_8rem_8rem_2rem] lg:items-start lg:rounded-none lg:border-0 lg:border-b lg:border-neutral-100 lg:px-0 lg:py-2"
          >
            <div class="min-w-0">
              <p v-if="lineParent(line)" class="truncate text-xs text-neutral-400">{{ lineParent(line) }}</p>
              <input
                v-if="line.item_id === null"
                v-model="line.name"
                class="w-full rounded-md border border-neutral-300 px-2 py-1 text-sm"
                :aria-label="t('bo.billing.quotes.item')"
              />
              <p v-else class="text-sm font-medium text-neutral-900">{{ lineName(line) }}</p>
            </div>
            <input
              :value="line.description ?? ''"
              :placeholder="t('bo.billing.quotes.description')"
              class="w-full rounded-md border border-neutral-300 px-2 py-1 text-sm"
              @input="line.description = ($event.target as HTMLInputElement).value"
            />
            <div class="grid grid-cols-3 gap-2 lg:contents">
              <label class="text-xs text-neutral-500 lg:contents">
                <span class="lg:hidden">{{ t('bo.billing.quotes.qty') }}</span>
                <input v-model.number="line.quantity" type="number" min="0.01" step="1" class="w-full rounded-md border border-neutral-300 px-2 py-1 text-right text-sm tabular-nums" />
              </label>
              <label class="text-xs text-neutral-500 lg:contents">
                <span class="lg:hidden">{{ t('bo.billing.quotes.unitPrice') }}</span>
                <input
                  v-model.number="line.unit_price"
                  type="number"
                  min="0"
                  :step="form.currency === 'KHR' ? 100 : 0.01"
                  class="w-full rounded-md border border-neutral-300 px-2 py-1 text-right text-sm tabular-nums"
                />
              </label>
              <div class="text-xs text-neutral-500 lg:contents">
                <span class="lg:hidden">{{ t('bo.billing.quotes.amount') }}</span>
                <p class="py-1 text-right text-sm font-medium tabular-nums text-neutral-900">{{ money(lineAmount(line)) }}</p>
              </div>
            </div>
            <button
              v-if="canManage"
              type="button"
              class="justify-self-end rounded p-1 text-neutral-400 hover:bg-red-50 hover:text-red-600"
              :aria-label="t('bo.billing.quotes.removeLine')"
              @click="lines.splice(i, 1)"
            >
              ✕
            </button>
          </div>
        </div>

        <!-- Totals -->
        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:justify-between">
          <div class="w-full lg:max-w-md">
            <label class="mb-1.5 block text-sm font-medium text-neutral-700">{{ t('bo.billing.quotes.notes') }}</label>
            <textarea
              :value="form.notes ?? ''"
              rows="4"
              class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm"
              @input="form.notes = ($event.target as HTMLTextAreaElement).value || null"
            />
          </div>
          <dl class="w-full space-y-2 text-sm lg:w-80">
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('bo.billing.quotes.subtotal') }}</dt>
              <dd class="tabular-nums">{{ money(subtotal) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
              <dt class="flex items-center gap-2 text-neutral-500">
                {{ t('bo.billing.quotes.discount') }}
                <select v-model="form.discount_type" class="rounded-md border border-neutral-300 py-0.5 pr-7 pl-2 text-xs">
                  <option v-for="o in discountTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <input v-model.number="form.discount_value" type="number" min="0" class="w-24 rounded-md border border-neutral-300 px-2 py-0.5 text-right text-xs tabular-nums" />
              </dt>
              <dd class="tabular-nums">−{{ money(discount) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
              <dt class="flex items-center gap-2 text-neutral-500">
                {{ t('bo.billing.quotes.tax') }}
                <input v-model.number="form.tax_percent" type="number" min="0" max="100" class="w-20 rounded-md border border-neutral-300 px-2 py-0.5 text-right text-xs tabular-nums" />
              </dt>
              <dd class="tabular-nums">{{ money(tax) }}</dd>
            </div>
            <div class="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold">
              <dt>{{ t('bo.billing.quotes.total') }}</dt>
              <dd class="tabular-nums">{{ money(total) }}</dd>
            </div>
          </dl>
        </div>
      </section>
    </fieldset>

    <QuotationItemPicker v-model="pickerOpen" :tree="catalog" :currency="form.currency" :existing="existingIds" @add="addItems" />
  </div>
</template>
