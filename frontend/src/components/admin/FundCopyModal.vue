<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountsService, type Account } from '@/services/accounting'
import { fundsService, type Fund, type FundEntry } from '@/services/funds'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { thisMonth } from '@/utils/date'

/**
 * Fund > Funds: Copy. The money in rows ticked on the statement come back
 * as a list (same provider, amount and remark); set the pay date, For month
 * and account once for all, change any row, then Save adds them all.
 */
const props = defineProps<{ modelValue: boolean; fund: Fund | null; entries: FundEntry[] }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [fund: Fund] }>()

const { t } = useI18n()

interface Row {
  key: number
  party: string
  fund_provider_id: number | null
  amount: string
  transacted_on: string
  for_month: string
  remark: string
}

function today(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** 'YYYY-MM' one month later. */
function monthAfter(month: string): string {
  const [year, mon] = month.split('-').map(Number) as [number, number]
  return mon === 12 ? `${year + 1}-01` : `${year}-${String(mon + 1).padStart(2, '0')}`
}

const accounts = ref<Account[]>([])
const accountOptions = computed(() => accounts.value.map((a) => ({ value: String(a.id), label: `${a.code} — ${a.name}` })))

const all = reactive({ transacted_on: '', for_month: '', cash_account_id: '' })
const rows = ref<Row[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const money = (value: number) => formatMoney(value, props.fund?.currency ?? 'USD')
const total = computed(() => rows.value.reduce((sum, row) => sum + Number(row.amount || 0), 0))
const canSave = computed(() => rows.value.length > 0 && !!all.cash_account_id && rows.value.every((row) => Number(row.amount || 0) > 0 && row.transacted_on && row.for_month))
const rowError = (index: number, field: string) => errors.value[`entries.${index}.${field}`]?.[0]

watch(
  () => props.modelValue,
  async (open) => {
    if (!open) return
    errors.value = {}
    saveError.value = null
    // The month after the latest one copied (e.g. March's list becomes April).
    const months = props.entries.map((e) => e.for_month ?? e.transacted_on.slice(0, 7)).sort()
    all.transacted_on = today()
    all.for_month = months.length ? monthAfter(months[months.length - 1]!) : thisMonth()
    const firstAccount = props.entries.find((e) => e.cash_account)?.cash_account?.id
    all.cash_account_id = firstAccount ? String(firstAccount) : all.cash_account_id
    rows.value = props.entries.map((entry, i) => ({
      key: i,
      party: entry.party ?? '',
      fund_provider_id: entry.fund_provider_id ?? null,
      amount: String(entry.amount),
      transacted_on: all.transacted_on,
      for_month: all.for_month,
      remark: entry.remark ?? '',
    }))
    if (accounts.value.length === 0) {
      accounts.value = await accountsService
        .listAll()
        .then((list) => list.filter((a) => a.is_bank_or_cash && a.is_active))
        .catch(() => [])
    }
    if (!all.cash_account_id && accounts.value.length === 1) all.cash_account_id = String(accounts.value[0]!.id)
  },
  { immediate: true },
)

/** The boxes above the list set every row. */
function setAll(field: 'transacted_on' | 'for_month', value: string) {
  all[field] = value
  for (const row of rows.value) row[field] = value
}

function removeRow(index: number) {
  rows.value.splice(index, 1)
  errors.value = {}
}

async function save() {
  if (!props.fund || !canSave.value) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    const result = await fundsService.copyEntries(
      props.fund.id,
      rows.value.map((row) => ({
        amount: Number(row.amount),
        transacted_on: row.transacted_on,
        for_month: row.for_month,
        cash_account_id: Number(all.cash_account_id),
        // A provider keeps their link; a typed "from" stays as text.
        fund_provider_id: row.fund_provider_id,
        party: row.fund_provider_id ? null : row.party.trim() || null,
        remark: row.remark.trim() || null,
      })),
    )
    emit('saved', result.fund)
    emit('update:modelValue', false)
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) {
      errors.value = e.errors
      saveError.value = Object.values(e.errors)[0]?.[0] ?? e.message
    } else {
      saveError.value = e instanceof ApiRequestError ? e.message : t('admin.funds.saveFailed')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="modelValue" size="xl" :title="t('admin.funds.copyTitle', { name: fund?.name ?? '—' })" @update:model-value="emit('update:modelValue', $event)">
    <form v-if="fund" class="space-y-4" @submit.prevent="save">
      <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
      <p class="text-sm text-neutral-600">{{ t('admin.funds.copyHint') }}</p>

      <div class="grid gap-3 sm:grid-cols-3">
        <BaseInput :model-value="all.transacted_on" type="date" required :label="t('admin.funds.payDate')" @update:model-value="setAll('transacted_on', $event)" />
        <BaseInput :model-value="all.for_month" type="month" required :label="t('admin.funds.forMonth')" @update:model-value="setAll('for_month', $event)" />
        <SearchableSelect v-model="all.cash_account_id" required :options="accountOptions" :label="t('admin.funds.receivedInto')" :placeholder="t('admin.funds.selectAccount')" :error="errors['entries.0.cash_account_id']?.[0]" />
      </div>

      <p v-if="rows.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">{{ t('admin.funds.copyEmpty') }}</p>

      <!-- Phone: one card per row. -->
      <div v-if="rows.length" class="space-y-2 sm:hidden">
        <div v-for="(row, i) in rows" :key="row.key" class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium text-neutral-900">{{ row.party || '—' }}</p>
            <button type="button" class="text-xs font-medium text-danger-600 hover:text-red-700" @click="removeRow(i)">{{ t('admin.funds.removeEntry') }}</button>
          </div>
          <BaseInput v-model="row.amount" type="number" required :label="t('admin.funds.amount')" :error="rowError(i, 'amount')" />
          <div class="grid grid-cols-2 gap-2">
            <BaseInput v-model="row.transacted_on" type="date" required :label="t('admin.funds.payDate')" :error="rowError(i, 'transacted_on')" />
            <BaseInput v-model="row.for_month" type="month" required :label="t('admin.funds.forMonth')" :error="rowError(i, 'for_month')" />
          </div>
          <BaseInput v-model="row.remark" :label="t('admin.funds.remark')" :error="rowError(i, 'remark')" />
        </div>
      </div>

      <div v-if="rows.length" class="hidden overflow-x-auto sm:block">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="py-2 pr-2 font-medium">{{ t('admin.funds.from') }}</th>
              <th class="py-2 pr-2 font-medium">{{ t('admin.funds.amount') }}</th>
              <th class="py-2 pr-2 font-medium">{{ t('admin.funds.payDate') }}</th>
              <th class="py-2 pr-2 font-medium">{{ t('admin.funds.forMonth') }}</th>
              <th class="py-2 pr-2 font-medium">{{ t('admin.funds.remark') }}</th>
              <th class="py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in rows" :key="row.key" class="border-b border-neutral-100 align-top">
              <td class="whitespace-nowrap py-2 pr-3 pt-4 font-medium text-neutral-900">{{ row.party || '—' }}</td>
              <td class="w-32 py-2 pr-2">
                <input v-model="row.amount" type="number" min="0" step="any" required class="w-full rounded-md border border-neutral-300 px-2 py-1.5 text-right tabular-nums focus:border-primary-500 focus:outline-none" :aria-label="t('admin.funds.amount')" />
                <p v-if="rowError(i, 'amount')" class="mt-1 text-xs text-danger-600">{{ rowError(i, 'amount') }}</p>
              </td>
              <td class="w-40 py-2 pr-2">
                <DateInput :model-value="row.transacted_on" required :invalid="!!rowError(i, 'transacted_on')" @update:model-value="row.transacted_on = $event" />
                <p v-if="rowError(i, 'transacted_on')" class="mt-1 text-xs text-danger-600">{{ rowError(i, 'transacted_on') }}</p>
              </td>
              <td class="w-36 py-2 pr-2">
                <input v-model="row.for_month" type="month" required class="w-full rounded-md border border-neutral-300 px-2 py-1.5 focus:border-primary-500 focus:outline-none" :aria-label="t('admin.funds.forMonth')" />
                <p v-if="rowError(i, 'for_month')" class="mt-1 text-xs text-danger-600">{{ rowError(i, 'for_month') }}</p>
              </td>
              <td class="min-w-40 py-2 pr-2">
                <input v-model="row.remark" type="text" class="w-full rounded-md border border-neutral-300 px-2 py-1.5 focus:border-primary-500 focus:outline-none" :aria-label="t('admin.funds.remark')" />
              </td>
              <td class="py-2 pt-3.5 text-right">
                <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeRow(i)">{{ t('admin.funds.removeEntry') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="rows.length" class="rounded-lg bg-neutral-50 px-3 py-2 text-sm text-neutral-700">
        {{ t('admin.funds.copyTotal', { n: rows.length }) }}: <span class="font-semibold text-neutral-900">{{ money(total) }}</span>
      </p>
    </form>
    <template #footer>
      <BaseButton variant="outline" @click="emit('update:modelValue', false)">{{ t('common.close') }}</BaseButton>
      <BaseButton :loading="saving" :disabled="!canSave" @click="save">{{ t('admin.funds.copySave', { n: rows.length }) }}</BaseButton>
    </template>
  </BaseModal>
</template>
