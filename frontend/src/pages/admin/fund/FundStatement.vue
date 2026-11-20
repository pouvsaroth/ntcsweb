<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import FundCopyModal from '@/components/admin/FundCopyModal.vue'
import FundEntryModal from '@/components/admin/FundEntryModal.vue'
import FundFormModal from '@/components/admin/FundFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { fundsService, type Fund, type FundEntry, type FundEntryType } from '@/services/funds'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { moneyOrDash } from '@/utils/currency'
import { formatDate, formatMonth } from '@/utils/date'

/**
 * Fund > Funds: one fund's statement — every money in and out with who it
 * came from / was paid to, the remark and the balance after it. Print
 * prints only the statement (.print-area). Month shows one For month; tick
 * money in rows and Copy adds them again (e.g. the same providers next month).
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

const todayText = formatDate(new Date())
const printPage = () => window.print()
const allEntries = computed<FundEntry[]>(() => fund.value?.statement ?? [])
const monthOf = (entry: FundEntry) => entry.for_month ?? entry.transacted_on.slice(0, 7)
const monthFilter = ref('')
const entries = computed<FundEntry[]>(() => (monthFilter.value ? allEntries.value.filter((e) => monthOf(e) === monthFilter.value) : allEntries.value))

// Copy: money in rows ticked (only those shown count).
const selected = ref<Set<number>>(new Set())
const canCopy = computed(() => canCreate.value && isActive.value)
const shownIn = computed(() => entries.value.filter((e) => e.type === 'in'))
const picked = computed(() => shownIn.value.filter((e) => selected.value.has(e.id)))
const allPicked = computed(() => shownIn.value.length > 0 && picked.value.length === shownIn.value.length)
function toggle(entry: FundEntry) {
  const next = new Set(selected.value)
  if (next.has(entry.id)) next.delete(entry.id)
  else next.add(entry.id)
  selected.value = next
}
function toggleAll() {
  selected.value = allPicked.value ? new Set() : new Set(shownIn.value.map((e) => e.id))
}
const copyOpen = ref(false)
function copied(updated: Fund) {
  fund.value = updated
  selected.value = new Set()
}
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
    <div v-if="fund" class="mb-4 flex flex-wrap items-end gap-2 print:hidden">
      <div class="w-44">
        <BaseInput v-model="monthFilter" type="month" :label="t('admin.funds.filterMonth')" />
      </div>
      <BaseButton v-if="monthFilter" variant="outline" @click="monthFilter = ''">{{ t('admin.funds.allMonths') }}</BaseButton>
      <BaseButton v-if="canCopy" :disabled="picked.length === 0" @click="copyOpen = true">{{ t('admin.funds.copyPicked', { n: picked.length }) }}</BaseButton>
      <p v-if="canCopy && picked.length === 0 && shownIn.length" class="text-xs text-neutral-500">{{ t('admin.funds.copyTip') }}</p>
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
        <div><dt class="text-neutral-500">{{ t('admin.funds.totalIn') }}</dt><dd class="font-semibold tabular-nums text-green-700">{{ moneyOrDash(fund.total_in_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(fund.total_in_khr, 'KHR') }}</span></dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.funds.totalOut') }}</dt><dd class="font-semibold tabular-nums text-amber-700">{{ moneyOrDash(fund.total_out_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(fund.total_out_khr, 'KHR') }}</span></dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.funds.balance') }}</dt><dd class="text-base font-semibold tabular-nums text-neutral-900">{{ moneyOrDash(fund.balance_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(fund.balance_khr, 'KHR') }}</span></dd></div>
      </dl>

      <div v-if="fund.target_amount" class="mt-3">
        <div class="flex justify-between text-xs text-neutral-500">
          <span>{{ t('admin.funds.target') }}: {{ moneyOrDash(fund.target_amount_usd, 'USD') }} / {{ moneyOrDash(fund.target_amount_khr, 'KHR') }}</span>
          <span>{{ progress }}%</span>
        </div>
        <div class="mt-1 h-2 rounded-full bg-neutral-100">
          <div class="h-2 rounded-full bg-primary-500" :style="{ width: `${Math.max(0, progress ?? 0)}%` }"></div>
        </div>
      </div>

      <p v-if="monthFilter" class="mt-3 text-sm font-medium text-neutral-700">{{ t('admin.funds.forMonth') }}: {{ formatMonth(monthFilter) }}</p>
      <p v-if="entries.length === 0" class="mt-6 rounded-lg border border-dashed border-neutral-300 py-8 text-center text-sm text-neutral-500">{{ t('admin.funds.noEntries') }}</p>

      <template v-else>
        <!-- Phone: one card per entry. -->
        <div class="mt-5 space-y-2 sm:hidden print:hidden">
          <label v-if="canCopy && shownIn.length" class="flex items-center gap-2 px-1 text-sm text-neutral-700">
            <input type="checkbox" class="h-4 w-4 rounded border-neutral-300" :checked="allPicked" @change="toggleAll" />
            {{ t('admin.funds.selectAllIn') }}
          </label>
          <div v-for="entry in entries" :key="entry.id" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex items-start justify-between gap-2">
              <input v-if="canCopy && entry.type === 'in'" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-neutral-300" :checked="selected.has(entry.id)" :aria-label="t('admin.funds.select')" @change="toggle(entry)" />
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-neutral-900">{{ t(`admin.funds.entryTypes.${entry.type}`) }} · {{ formatDate(entry.transacted_on) }}</p>
                <p v-if="entry.for_month" class="mt-0.5 text-xs text-neutral-500">{{ t('admin.funds.forMonth') }}: {{ formatMonth(entry.for_month) }}</p>
                <p v-if="entry.party" class="mt-0.5 text-xs text-neutral-600">{{ entry.party }}</p>
                <p v-if="entry.remark" class="mt-0.5 text-xs text-neutral-500">{{ entry.remark }}</p>
              </div>
              <p class="font-semibold tabular-nums" :class="entry.type === 'out' ? 'text-amber-700' : 'text-green-700'">
                {{ entry.type === 'out' ? '-' : '+' }}{{ moneyOrDash(entry.amount_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(entry.amount_khr, 'KHR') }}</span>
              </p>
            </div>
            <div class="mt-1 flex items-center justify-between text-xs text-neutral-500">
              <span>{{ t('admin.funds.balance') }}: <span class="font-medium text-neutral-800">{{ moneyOrDash(entry.balance_usd, 'USD') }} / {{ moneyOrDash(entry.balance_khr, 'KHR') }}</span></span>
              <button v-if="canDelete && isActive" type="button" class="font-medium text-danger-600 hover:text-red-700" @click="removeEntry(entry)">{{ t('admin.funds.removeEntry') }}</button>
            </div>
          </div>
        </div>

        <div class="mt-5 hidden overflow-x-auto sm:block print:block">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="canCopy" class="w-8 py-2 pr-2 print:hidden">
                  <input v-if="shownIn.length" type="checkbox" class="h-4 w-4 rounded border-neutral-300" :checked="allPicked" :aria-label="t('admin.funds.selectAllIn')" @change="toggleAll" />
                </th>
                <th class="py-2 pr-3 font-medium">{{ t('admin.funds.date') }}</th>
                <th class="py-2 pr-3 font-medium">{{ t('admin.funds.forMonth') }}</th>
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
                <td v-if="canCopy" class="py-2 pr-2 print:hidden">
                  <input v-if="entry.type === 'in'" type="checkbox" class="h-4 w-4 rounded border-neutral-300" :checked="selected.has(entry.id)" :aria-label="t('admin.funds.select')" @change="toggle(entry)" />
                </td>
                <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ formatDate(entry.transacted_on) }}</td>
                <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ entry.for_month ? formatMonth(entry.for_month) : '' }}</td>
                <td class="py-2 pr-3">{{ entry.party ?? '' }}</td>
                <td class="py-2 pr-3 text-neutral-600">{{ entry.remark ?? '' }}</td>
                <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-green-700"><template v-if="entry.type === 'in'">{{ moneyOrDash(entry.amount_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(entry.amount_khr, 'KHR') }}</span></template></td>
                <td class="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-amber-700"><template v-if="entry.type === 'out'">{{ moneyOrDash(entry.amount_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(entry.amount_khr, 'KHR') }}</span></template></td>
                <td class="whitespace-nowrap py-2 pr-3 text-right font-medium tabular-nums">{{ moneyOrDash(entry.balance_usd, 'USD') }}<span class="block text-xs font-normal text-neutral-500">{{ moneyOrDash(entry.balance_khr, 'KHR') }}</span></td>
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
    <FundCopyModal v-model="copyOpen" :fund="fund" :entries="picked" @saved="copied" />
    <FundFormModal v-model="editOpen" :fund="fund" @saved="load" />
  </div>
</template>
