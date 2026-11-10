<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { myDiscountCodesService, type MyDiscountCode } from '@/services/discountCodes'
import type { Invoice } from '@/services/invoices'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

// Student portal: my discount codes, and putting one on an unpaid course invoice.
const { t } = useI18n()

const codes = ref<MyDiscountCode[]>([])
const invoices = ref<Invoice[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)
const success = ref<string | null>(null)

/** Per invoice: the code typed, saving, and its error. */
const entry = reactive<Record<number, { code: string; saving: boolean; error: string | null }>>({})

const stateVariant: Record<MyDiscountCode['state'], 'success' | 'neutral' | 'warning' | 'danger' | 'primary'> = {
  active: 'success',
  used: 'primary',
  scheduled: 'primary',
  expired: 'danger',
  inactive: 'neutral',
}

function discountText(row: MyDiscountCode): string {
  if (row.discount_type === 'amount') return formatMoney(row.value, row.currency)
  const percent = `${Number(row.value)}%`
  return row.max_discount ? t('admin.discountCodes.percentUpTo', { percent, max: formatMoney(row.max_discount, row.currency) }) : percent
}

function stateLabel(state: MyDiscountCode['state']): string {
  return state === 'used' ? t('admin.myDiscountCodes.used') : t(`admin.discountCodes.effective.${state}`)
}

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const result = await myDiscountCodesService.list()
    codes.value = result.codes
    invoices.value = result.invoices
    for (const invoice of result.invoices) entry[invoice.id] ??= { code: '', saving: false, error: null }
  } catch (e) {
    loadError.value = e instanceof ApiRequestError ? e.message : t('admin.myDiscountCodes.loadFailed')
  } finally {
    loading.value = false
  }
}

/** Fill the first invoice's box from a code card. */
function useOnInvoice(code: string) {
  const first = invoices.value[0]
  if (first) entry[first.id].code = code
}

async function apply(invoice: Invoice) {
  const state = entry[invoice.id]
  if (!state.code.trim()) return
  state.saving = true
  state.error = null
  success.value = null
  try {
    const updated = await myDiscountCodesService.apply(invoice.id, state.code.trim())
    success.value = t('admin.myDiscountCodes.appliedOn', {
      number: updated.invoice_number,
      amount: formatMoney(updated.discount, updated.currency),
      balance: formatMoney(updated.balance, updated.currency),
    })
    await load()
  } catch (e) {
    state.error = e instanceof ApiRequestError ? (e.fieldError('discount_code') ?? e.message) : t('admin.discountCodes.checkFailed')
  } finally {
    state.saving = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myDiscountCodes.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myDiscountCodes.subtitle') }}</p>
    </div>

    <BaseAlert v-if="loadError" variant="danger">{{ loadError }}</BaseAlert>
    <BaseAlert v-if="success" variant="success">{{ success }}</BaseAlert>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <template v-else>
      <!-- My codes -->
      <section>
        <p v-if="codes.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
          {{ t('admin.myDiscountCodes.noCodes') }}
        </p>
        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div
            v-for="row in codes"
            :key="row.id"
            class="rounded-[--radius-card] border bg-white p-4 shadow-[--shadow-card]"
            :class="row.state === 'active' ? 'border-primary-300' : 'border-neutral-200 opacity-80'"
          >
            <div class="flex items-start justify-between gap-2">
              <p class="font-mono text-lg font-bold tracking-wide text-neutral-900">{{ row.code }}</p>
              <BaseBadge :variant="stateVariant[row.state]">{{ stateLabel(row.state) }}</BaseBadge>
            </div>
            <p class="mt-0.5 text-sm text-neutral-600">{{ row.name }}</p>
            <p class="mt-2 text-base font-semibold text-primary-700">{{ t('admin.myDiscountCodes.off', { discount: discountText(row) }) }}</p>
            <dl class="mt-2 space-y-0.5 text-xs text-neutral-500">
              <div>{{ t('admin.discountCodes.startsOn') }}: {{ formatDate(row.starts_on) }}</div>
              <div>{{ t('admin.discountCodes.expiresOn') }}: {{ row.expires_on ? formatDate(row.expires_on) : t('admin.discountCodes.noEnd') }}</div>
              <div v-if="row.used_at">
                {{ t('admin.myDiscountCodes.usedOn', { date: formatDate(row.used_at), amount: row.used_amount != null ? formatMoney(row.used_amount, row.currency) : '' }) }}
              </div>
              <div v-if="row.audience === 'personal'">{{ t('admin.myDiscountCodes.justForYou') }}</div>
            </dl>
            <BaseButton v-if="row.state === 'active' && invoices.length" size="sm" variant="outline" class="mt-3" @click="useOnInvoice(row.code)">
              {{ t('admin.myDiscountCodes.useIt') }}
            </BaseButton>
          </div>
        </div>
      </section>

      <!-- Unpaid invoices a code can go on -->
      <section>
        <h2 class="mb-2 text-base font-semibold text-neutral-900">{{ t('admin.myDiscountCodes.invoicesTitle') }}</h2>
        <p v-if="invoices.length === 0" class="text-sm text-neutral-500">{{ t('admin.myDiscountCodes.noInvoices') }}</p>
        <div v-else class="space-y-2">
          <div
            v-for="invoice in invoices"
            :key="invoice.id"
            class="flex flex-col gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card] sm:flex-row sm:items-end sm:justify-between"
          >
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ invoice.invoice_number }}</p>
              <p class="text-xs text-neutral-500">
                <template v-if="invoice.course">{{ invoice.course }} · </template>{{ formatDate(invoice.invoice_date) }}
              </p>
              <p class="mt-1 text-sm font-semibold text-neutral-800">{{ formatMoney(invoice.balance, invoice.currency) }}</p>
            </div>
            <div v-if="entry[invoice.id]" class="flex items-end gap-2 sm:w-80">
              <BaseInput
                :model-value="entry[invoice.id].code"
                class="flex-1"
                :label="t('admin.discountCodes.useCode')"
                :placeholder="t('admin.discountCodes.useCodePlaceholder')"
                :error="entry[invoice.id].error ?? undefined"
                @update:model-value="entry[invoice.id].code = $event.toUpperCase()"
              />
              <BaseButton :loading="entry[invoice.id].saving" :disabled="!entry[invoice.id].code.trim()" @click="apply(invoice)">
                {{ t('admin.discountCodes.apply') }}
              </BaseButton>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
