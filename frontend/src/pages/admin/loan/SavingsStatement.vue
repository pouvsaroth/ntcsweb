<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import SavingsEntryModal from '@/components/admin/SavingsEntryModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { savingsService, type SavingsAccount, type SavingsEntry } from '@/services/savings'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Loan > Savings: one account's statement — every deposit and withdrawal
 * with its remark and the balance after it. Print prints only the
 * statement (.print-area).
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('savings.create'))
const canUpdate = computed(() => auth.can('savings.update'))
const canDelete = computed(() => auth.can('savings.delete'))

const account = ref<SavingsAccount | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)

const money = (value: number) => formatMoney(value, account.value?.currency ?? 'USD')
const todayText = formatDate(new Date())
const printPage = () => window.print()
const entries = computed<SavingsEntry[]>(() => account.value?.statement ?? [])
const isOpen = computed(() => account.value?.status === 'open')

async function load() {
  try {
    account.value = await savingsService.get(Number(route.params.id))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.savings.loadFailed')
  } finally {
    loading.value = false
  }
}

const entryModal = ref(false)
const entryType = ref<'deposit' | 'withdrawal'>('deposit')
function record(type: 'deposit' | 'withdrawal') {
  entryType.value = type
  entryModal.value = true
}

function failed(e: unknown, fallback: string) {
  if (e instanceof ApiRequestError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined
    actionError.value = first ?? e.message
  } else {
    actionError.value = fallback
  }
}

async function removeEntry(entry: SavingsEntry) {
  if (!(await confirmDialog.confirm({ message: t('admin.savings.removeEntryConfirm'), danger: true }))) return
  actionError.value = null
  try {
    account.value = await savingsService.removeEntry(entry.id)
  } catch (e) {
    failed(e, t('admin.savings.removeFailed'))
  }
}

async function setStatus(status: 'open' | 'closed') {
  if (!account.value) return
  if (status === 'closed' && !(await confirmDialog.confirm({ message: t('admin.savings.closeConfirm') }))) return
  actionError.value = null
  try {
    account.value = await savingsService.update(account.value.id, { status })
  } catch (e) {
    failed(e, t('admin.savings.saveFailed'))
  }
}

async function removeAccount() {
  if (!account.value) return
  // With entries, the deposits and withdrawals go too (their ledger entries are reversed).
  const message = account.value.entries_count > 0 ? t('admin.savings.deleteWithEntriesConfirm', { count: account.value.entries_count }) : t('admin.savings.deleteConfirm')
  if (!(await confirmDialog.confirm({ message, danger: true }))) return
  actionError.value = null
  try {
    await savingsService.remove(account.value.id)
    router.push('/admin/savings')
  } catch (e) {
    failed(e, t('admin.savings.deleteFailed'))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseButton variant="outline" @click="router.push('/admin/savings')">{{ t('admin.savings.backToList') }}</BaseButton>
      <template v-if="account">
        <template v-if="canCreate && isOpen">
          <BaseButton @click="record('deposit')">{{ t('admin.savings.deposit') }}</BaseButton>
          <BaseButton variant="outline" :disabled="account.balance <= 0" @click="record('withdrawal')">{{ t('admin.savings.withdraw') }}</BaseButton>
        </template>
        <BaseButton variant="outline" @click="printPage">{{ t('admin.savings.print') }}</BaseButton>
        <div class="ml-auto flex flex-wrap gap-2">
          <BaseButton v-if="canUpdate && isOpen" variant="outline" @click="setStatus('closed')">{{ t('admin.savings.close') }}</BaseButton>
          <BaseButton v-if="canUpdate && !isOpen" variant="outline" @click="setStatus('open')">{{ t('admin.savings.reopen') }}</BaseButton>
          <BaseButton v-if="canDelete" variant="danger" @click="removeAccount">{{ t('admin.savings.delete') }}</BaseButton>
        </div>
      </template>
    </div>
    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4 print:hidden">{{ error || actionError }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="account" class="print-area rounded-[--radius-card] border border-neutral-200 bg-white p-5 print:border-0 print:p-0">
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 pb-3">
        <div>
          <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
          <h1 class="text-lg font-semibold text-neutral-900">{{ t('admin.savings.statementTitle') }}</h1>
          <p class="text-sm text-neutral-600">
            {{ account.customer?.name ?? '—' }}<template v-if="account.customer?.code"> ({{ account.customer.code }})</template><template v-if="account.customer?.phone"> · {{ account.customer.phone }}</template>
          </p>
          <p class="text-sm text-neutral-600">{{ t('admin.savings.accountLine', { no: account.account_no, currency: account.currency, date: formatDate(account.opened_on) }) }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs text-neutral-500">{{ t('admin.savings.asOf', { date: todayText }) }}</p>
          <BaseBadge :variant="isOpen ? 'success' : 'neutral'">{{ t(`admin.savings.statuses.${account.status}`) }}</BaseBadge>
        </div>
      </div>
      <p v-if="account.notes" class="mt-3 text-sm text-neutral-500">{{ account.notes }}</p>

      <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
        <div><dt class="text-neutral-500">{{ t('admin.savings.totalIn') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ money(account.total_in) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.savings.totalOut') }}</dt><dd class="font-semibold tabular-nums text-amber-700">{{ money(account.total_out) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.savings.balance') }}</dt><dd class="text-base font-semibold tabular-nums text-neutral-900">{{ money(account.balance) }}</dd></div>
      </dl>

      <p v-if="entries.length === 0" class="mt-6 rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.savings.noEntries') }}</p>

      <template v-else>
        <!-- Phone: one card per entry. -->
        <div class="mt-5 space-y-2 sm:hidden print:hidden">
          <div v-for="entry in entries" :key="entry.id" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-medium text-neutral-900">{{ t(`admin.savings.entryTypes.${entry.type}`) }} · {{ formatDate(entry.transacted_on) }}</p>
                <p v-if="entry.remark" class="mt-0.5 text-xs text-neutral-600">{{ entry.remark }}</p>
              </div>
              <p class="font-semibold tabular-nums" :class="entry.type === 'withdrawal' ? 'text-amber-700' : 'text-green-700'">
                {{ entry.type === 'withdrawal' ? '-' : '+' }}{{ money(entry.amount) }}
              </p>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-neutral-500">
              <span>{{ t('admin.savings.balance') }}: <span class="font-medium text-neutral-800">{{ money(entry.balance ?? 0) }}</span></span>
              <button v-if="canDelete && isOpen" type="button" class="font-medium text-danger-600 hover:text-red-700" @click="removeEntry(entry)">{{ t('admin.savings.removeEntry') }}</button>
            </div>
          </div>
        </div>

        <div class="mt-5 hidden overflow-x-auto sm:block print:block">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="py-2 pr-3 font-medium">{{ t('admin.savings.date') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('admin.savings.type') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('admin.savings.remark') }}</th>
                <th class="py-2 pr-3 text-right font-medium">{{ t('admin.savings.moneyIn') }}</th>
                <th class="py-2 pr-3 text-right font-medium">{{ t('admin.savings.moneyOut') }}</th>
                <th class="py-2 pr-3 text-right font-medium">{{ t('admin.savings.balance') }}</th>
                <th v-if="canDelete && isOpen" class="py-2 print:hidden"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in entries" :key="entry.id" class="border-b border-neutral-100">
                <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ formatDate(entry.transacted_on) }}</td>
                <td class="whitespace-nowrap py-2 pr-3">{{ t(`admin.savings.entryTypes.${entry.type}`) }}</td>
                <td class="py-2 pr-3 text-neutral-600">{{ entry.remark ?? '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-green-700">{{ entry.type !== 'withdrawal' ? money(entry.amount) : '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-amber-700">{{ entry.type === 'withdrawal' ? money(entry.amount) : '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right font-medium tabular-nums">{{ money(entry.balance ?? 0) }}</td>
                <td v-if="canDelete && isOpen" class="py-2 text-right print:hidden">
                  <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeEntry(entry)">{{ t('admin.savings.removeEntry') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <SavingsEntryModal v-model="entryModal" :account="account" :type="entryType" @saved="load" />
  </div>
</template>
