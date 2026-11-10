<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import FundEntryModal from '@/components/admin/FundEntryModal.vue'
import FundFormModal from '@/components/admin/FundFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { fundsService, type Fund, type FundEntry, type FundEntryType } from '@/services/funds'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

/**
 * Fund > Funds: one fund's statement — every money in and out with who it
 * came from / was paid to, the remark and the balance after it. Print
 * prints only the statement (.print-area).
 */
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('funds.create'))
const canUpdate = computed(() => auth.can('funds.update'))
const canDelete = computed(() => auth.can('funds.delete'))

const fund = ref<Fund | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)
const types = ref<LookupOption[]>([])

const money = (value: number) => formatMoney(value, fund.value?.currency ?? 'USD')
const todayText = formatDate(new Date())
const printPage = () => window.print()
const entries = computed<FundEntry[]>(() => fund.value?.statement ?? [])
const isActive = computed(() => fund.value?.status === 'active')
const typeName = computed(() => (fund.value ? (types.value.find((type) => type.code === fund.value!.fund_type)?.name ?? fund.value.fund_type) : ''))
const progress = computed(() => (fund.value?.target_amount ? Math.min(100, Math.round((fund.value.balance / fund.value.target_amount) * 100)) : null))

async function load() {
  try {
    fund.value = await fundsService.get(Number(route.params.id))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.funds.loadFailed')
  } finally {
    loading.value = false
  }
}

const entryModal = ref(false)
const entryType = ref<FundEntryType>('in')
function record(type: FundEntryType) {
  entryType.value = type
  entryModal.value = true
}
const editOpen = ref(false)

function failed(e: unknown, fallback: string) {
  if (e instanceof ApiRequestError) {
    const first = e.errors ? Object.values(e.errors)[0]?.[0] : undefined
    actionError.value = first ?? e.message
  } else {
    actionError.value = fallback
  }
}

async function removeEntry(entry: FundEntry) {
  if (!(await confirmDialog.confirm({ message: t('admin.funds.removeEntryConfirm'), danger: true }))) return
  actionError.value = null
  try {
    fund.value = await fundsService.removeEntry(entry.id)
  } catch (e) {
    failed(e, t('admin.funds.removeFailed'))
  }
}

async function setStatus(status: 'active' | 'closed') {
  if (!fund.value) return
  if (status === 'closed' && !(await confirmDialog.confirm({ message: t('admin.funds.closeConfirm') }))) return
  actionError.value = null
  try {
    fund.value = await fundsService.update(fund.value.id, { status })
  } catch (e) {
    failed(e, t('admin.funds.saveFailed'))
  }
}

async function removeFund() {
  if (!fund.value) return
  if (!(await confirmDialog.confirm({ message: t('admin.funds.deleteConfirm'), danger: true }))) return
  actionError.value = null
  try {
    await fundsService.remove(fund.value.id)
    router.push('/admin/funds')
  } catch (e) {
    failed(e, t('admin.funds.deleteFailed'))
  }
}

onMounted(async () => {
  types.value = await lookupsService.values('FUND_TYPE', locale.value).catch(() => [])
  await load()
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
      <BaseButton variant="outline" @click="router.push('/admin/funds')">{{ t('admin.funds.backToList') }}</BaseButton>
      <template v-if="fund">
        <template v-if="canCreate && isActive">
          <BaseButton @click="record('in')">{{ t('admin.funds.moneyIn') }}</BaseButton>
          <BaseButton variant="outline" :disabled="fund.balance <= 0" @click="record('out')">{{ t('admin.funds.moneyOut') }}</BaseButton>
        </template>
        <BaseButton variant="outline" @click="printPage">{{ t('admin.funds.print') }}</BaseButton>
        <div class="ml-auto flex flex-wrap gap-2">
          <BaseButton v-if="canUpdate" variant="outline" @click="editOpen = true">{{ t('admin.funds.edit') }}</BaseButton>
          <BaseButton v-if="canUpdate && isActive" variant="outline" @click="setStatus('closed')">{{ t('admin.funds.close') }}</BaseButton>
          <BaseButton v-if="canUpdate && !isActive" variant="outline" @click="setStatus('active')">{{ t('admin.funds.reopen') }}</BaseButton>
          <BaseButton v-if="canDelete && fund.entries_count === 0" variant="danger" @click="removeFund">{{ t('admin.funds.delete') }}</BaseButton>
        </div>
      </template>
    </div>
    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4 print:hidden">{{ error || actionError }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-if="fund" class="print-area rounded-[--radius-card] border border-neutral-200 bg-white p-5 print:border-0 print:p-0">
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 pb-3">
        <div>
          <p class="text-xs text-neutral-500">{{ auth.tenantName }}</p>
          <h1 class="text-lg font-semibold text-neutral-900">{{ fund.name }}</h1>
          <p class="text-sm text-neutral-600">{{ t('admin.funds.fundLine', { no: fund.fund_no, type: typeName, currency: fund.currency, date: formatDate(fund.opened_on) }) }}</p>
        </div>
        <div class="text-right">
          <p class="text-xs text-neutral-500">{{ t('admin.funds.asOf', { date: todayText }) }}</p>
          <BaseBadge :variant="isActive ? 'success' : 'neutral'">{{ t(`admin.funds.statuses.${fund.status}`) }}</BaseBadge>
        </div>
      </div>
      <p v-if="fund.description" class="mt-3 text-sm text-neutral-500">{{ fund.description }}</p>

      <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
        <div><dt class="text-neutral-500">{{ t('admin.funds.totalIn') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ money(fund.total_in) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.funds.totalOut') }}</dt><dd class="font-semibold tabular-nums text-amber-700">{{ money(fund.total_out) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.funds.balance') }}</dt><dd class="text-base font-semibold tabular-nums text-neutral-900">{{ money(fund.balance) }}</dd></div>
      </dl>

      <div v-if="fund.target_amount" class="mt-3">
        <div class="flex justify-between text-xs text-neutral-500">
          <span>{{ t('admin.funds.target') }}: {{ money(fund.target_amount) }}</span>
          <span>{{ progress }}%</span>
        </div>
        <div class="mt-1 h-2 rounded-full bg-neutral-100">
          <div class="h-2 rounded-full bg-primary-500" :style="{ width: `${Math.max(0, progress ?? 0)}%` }"></div>
        </div>
      </div>

      <p v-if="entries.length === 0" class="mt-6 rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.funds.noEntries') }}</p>

      <template v-else>
        <!-- Phone: one card per entry. -->
        <div class="mt-5 space-y-2 sm:hidden print:hidden">
          <div v-for="entry in entries" :key="entry.id" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-medium text-neutral-900">{{ t(`admin.funds.entryTypes.${entry.type}`) }} · {{ formatDate(entry.transacted_on) }}</p>
                <p v-if="entry.party" class="mt-0.5 text-xs text-neutral-600">{{ entry.party }}</p>
                <p v-if="entry.remark" class="mt-0.5 text-xs text-neutral-500">{{ entry.remark }}</p>
              </div>
              <p class="font-semibold tabular-nums" :class="entry.type === 'out' ? 'text-amber-700' : 'text-green-700'">
                {{ entry.type === 'out' ? '-' : '+' }}{{ money(entry.amount) }}
              </p>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-neutral-500">
              <span>{{ t('admin.funds.balance') }}: <span class="font-medium text-neutral-800">{{ money(entry.balance ?? 0) }}</span></span>
              <button v-if="canDelete && isActive" type="button" class="font-medium text-danger-600 hover:text-red-700" @click="removeEntry(entry)">{{ t('admin.funds.removeEntry') }}</button>
            </div>
          </div>
        </div>

        <div class="mt-5 hidden overflow-x-auto sm:block print:block">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="py-2 pr-3 font-medium">{{ t('admin.funds.date') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('admin.funds.party') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('admin.funds.remark') }}</th>
                <th class="py-2 pr-3 text-right font-medium">{{ t('admin.funds.moneyIn') }}</th>
                <th class="py-2 pr-3 text-right font-medium">{{ t('admin.funds.moneyOut') }}</th>
                <th class="py-2 pr-3 text-right font-medium">{{ t('admin.funds.balance') }}</th>
                <th v-if="canDelete && isActive" class="py-2 print:hidden"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in entries" :key="entry.id" class="border-b border-neutral-100">
                <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ formatDate(entry.transacted_on) }}</td>
                <td class="py-2 pr-3">{{ entry.party ?? '' }}</td>
                <td class="py-2 pr-3 text-neutral-600">{{ entry.remark ?? '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-green-700">{{ entry.type === 'in' ? money(entry.amount) : '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-amber-700">{{ entry.type === 'out' ? money(entry.amount) : '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right font-medium tabular-nums">{{ money(entry.balance ?? 0) }}</td>
                <td v-if="canDelete && isActive" class="py-2 text-right print:hidden">
                  <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="removeEntry(entry)">{{ t('admin.funds.removeEntry') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>

    <FundEntryModal v-model="entryModal" :fund="fund" :type="entryType" @saved="load" />
    <FundFormModal v-model="editOpen" :fund="fund" @saved="load" />
  </div>
</template>
