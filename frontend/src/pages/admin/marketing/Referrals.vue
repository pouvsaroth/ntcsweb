<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ReferralSettingsModal from '@/components/admin/ReferralSettingsModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { referralsService, type Referral, type ReferralSettings, type ReferralSummary } from '@/services/referrals'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

// Marketing > Referrals: who brought whom; the friend's first payment gives the referrer their own reward code.
const { t } = useI18n()
const auth = useAuthStore()
const canUpdate = computed(() => auth.can('referrals.update'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Referral>((query) => referralsService.list(query))

const summary = ref<ReferralSummary | null>(null)
const settings = ref<ReferralSettings | null>(null)
async function loadTop() {
  ;[summary.value, settings.value] = await Promise.all([referralsService.summary().catch(() => null), referralsService.settings().catch(() => null)])
}

const statusFilter = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('marketing.referrals.allStatuses') },
  { value: 'pending', label: t('marketing.referrals.statuses.pending') },
  { value: 'rewarded', label: t('marketing.referrals.statuses.rewarded') },
])
function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const rewardText = computed(() => {
  const c = settings.value?.reward_code
  if (!c) return t('marketing.referrals.noReward')
  const off = c.discount_type === 'amount' ? formatMoney(c.value, c.currency) : `${Number(c.value)}%`
  const days = settings.value?.valid_days ? t('marketing.referrals.forDays', { n: settings.value.valid_days }) : t('marketing.referrals.mainEnd')
  return `${c.code} (${off}) · ${days}`
})

const columns = computed(() => [
  { key: 'referrer', label: t('marketing.referrals.referrer') },
  { key: 'referred', label: t('marketing.referrals.friend') },
  { key: 'recorded_on', label: t('marketing.referrals.recordedOn') },
  { key: 'status', label: t('marketing.referrals.status') },
  { key: 'reward', label: t('marketing.referrals.reward') },
  { key: 'actions', label: '', align: 'text-right' },
])

const settingsOpen = ref(false)
const actionError = ref<string | null>(null)
const giving = ref<number | null>(null)
async function give(row: Referral) {
  actionError.value = null
  giving.value = row.id
  try {
    await referralsService.give(row.id)
    fetch()
    loadTop()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('marketing.referrals.giveFailed')
  } finally {
    giving.value = null
  }
}

onMounted(() => {
  fetch()
  loadTop()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('marketing.referrals.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('marketing.referrals.subtitle') }}</p>
    </div>

    <!-- Settings: on / off and the reward. -->
    <div v-if="settings" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
      <div class="min-w-0">
        <p class="text-sm font-medium text-neutral-900">
          {{ t('marketing.referrals.rewardLine') }}
          <BaseBadge :variant="settings.enabled ? 'success' : 'neutral'" class="ml-1">{{ settings.enabled ? t('marketing.referrals.on') : t('marketing.referrals.off') }}</BaseBadge>
        </p>
        <p class="mt-0.5 text-sm text-neutral-600">{{ rewardText }}</p>
      </div>
      <BaseButton v-if="canUpdate" variant="outline" @click="settingsOpen = true">{{ t('marketing.referrals.settings') }}</BaseButton>
    </div>

    <div v-if="summary" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="tile in [
        { k: 'total', v: summary.total },
        { k: 'pending', v: summary.pending },
        { k: 'rewarded', v: summary.rewarded },
        { k: 'referrers', v: summary.referrers },
      ]" :key="tile.k" class="rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t(`marketing.referrals.tiles.${tile.k}`) }}</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-neutral-900">{{ tile.v }}</p>
      </div>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('marketing.referrals.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-40" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('marketing.referrals.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.referrer?.name ?? '—' }}</p>
              <p class="text-xs text-neutral-500">{{ t('marketing.referrals.brought', { name: row.referred?.name ?? '—' }) }}</p>
              <p class="text-xs text-neutral-500">{{ row.recorded_on ? formatDate(row.recorded_on) : '' }}</p>
            </div>
            <BaseBadge :variant="row.status === 'rewarded' ? 'success' : 'warning'">{{ t(`marketing.referrals.statuses.${row.status}`) }}</BaseBadge>
          </div>
          <p v-if="row.reward_code" class="mt-2 font-mono text-xs text-primary-800">{{ row.reward_code.code }}</p>
          <div v-if="canUpdate && row.status === 'pending'" class="mt-2 flex justify-end">
            <button type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" :disabled="giving === row.id" @click="give(row)">{{ t('marketing.referrals.giveNow') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('marketing.referrals.empty')">
      <template #cell-referrer="{ row }">
        <p class="font-medium text-neutral-900">{{ row.referrer?.name ?? '—' }}</p>
        <p v-if="row.referrer?.student_code" class="text-xs text-neutral-500">{{ row.referrer.student_code }}</p>
      </template>
      <template #cell-referred="{ row }">
        <p class="text-neutral-900">{{ row.referred?.name ?? '—' }}</p>
        <p v-if="row.referred?.student_code" class="text-xs text-neutral-500">{{ row.referred.student_code }}</p>
      </template>
      <template #cell-recorded_on="{ row }">{{ row.recorded_on ? formatDate(row.recorded_on) : '—' }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="row.status === 'rewarded' ? 'success' : 'warning'">{{ t(`marketing.referrals.statuses.${row.status}`) }}</BaseBadge>
      </template>
      <template #cell-reward="{ row }">
        <template v-if="row.reward_code">
          <p class="font-mono text-xs text-primary-800">{{ row.reward_code.code }}</p>
          <p class="text-xs text-neutral-500">
            {{ row.rewarded_on ? formatDate(row.rewarded_on) : '' }}
            <template v-if="row.reward_code.expires_on"> · {{ t('marketing.referrals.until', { date: formatDate(row.reward_code.expires_on) }) }}</template>
          </p>
        </template>
        <span v-else class="text-xs text-neutral-500">{{ t('marketing.referrals.waitingPayment') }}</span>
      </template>
      <template #cell-actions="{ row }">
        <button
          v-if="canUpdate && row.status === 'pending'"
          type="button"
          class="whitespace-nowrap text-sm font-medium text-primary-600 hover:text-primary-700"
          :disabled="giving === row.id"
          @click="give(row)"
        >
          {{ t('marketing.referrals.giveNow') }}
        </button>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ReferralSettingsModal v-model="settingsOpen" :settings="settings" @saved="(s) => (settings = s)" />
  </div>
</template>
