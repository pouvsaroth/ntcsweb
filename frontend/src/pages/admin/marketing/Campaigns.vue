<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import CampaignFormModal from '@/components/admin/CampaignFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { lookupsService, type LookupOption } from '@/services/lookups'
import { CAMPAIGN_STATUSES, marketingCampaignsService, type CampaignStatus, type MarketingCampaign } from '@/services/marketingCampaigns'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatMoney } from '@/utils/currency'
import { formatDate } from '@/utils/date'

// Marketing > Campaigns: each campaign, its cost, and the leads and students it brought.
const { t, locale } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const canCreate = computed(() => auth.can('campaigns.create'))
const canUpdate = computed(() => auth.can('campaigns.update'))
const canDelete = computed(() => auth.can('campaigns.delete'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<MarketingCampaign>((query) => marketingCampaignsService.list(query))

// Channels come from Base Data (MARKETING_CHANNEL), named in the current language.
const channels = ref<LookupOption[]>([])
async function loadChannels() {
  channels.value = await lookupsService.values('MARKETING_CHANNEL', locale.value).catch(() => [])
}
watch(locale, loadChannels)
const channelName = (code: string) => channels.value.find((c) => c.code === code)?.name ?? code

const statusFilter = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('marketing.campaigns.allStatuses') },
  ...CAMPAIGN_STATUSES.map((s) => ({ value: s, label: t(`marketing.campaigns.statuses.${s}`) })),
])
function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}
const channelFilter = ref('')
const channelOptions = computed(() => [{ value: '', label: t('marketing.campaigns.allChannels') }, ...channels.value.map((c) => ({ value: c.code, label: c.name }))])
function onChannel(value: string) {
  channelFilter.value = value
  setFilter('channel', value || undefined)
}

const statusVariant = (s: CampaignStatus) => (s === 'active' ? 'success' : s === 'planned' ? 'primary' : s === 'cancelled' ? 'danger' : 'neutral')

const columns = computed(() => [
  { key: 'code', label: t('marketing.campaigns.code') },
  { key: 'name', label: t('marketing.campaigns.name') },
  { key: 'dates', label: t('marketing.campaigns.dates') },
  { key: 'cost', label: t('marketing.campaigns.cost'), align: 'text-right' },
  { key: 'leads', label: t('marketing.campaigns.leads'), align: 'text-right' },
  { key: 'enrolled', label: t('marketing.campaigns.enrolled'), align: 'text-right' },
  { key: 'status', label: t('marketing.campaigns.status') },
  { key: 'actions', label: '', align: 'text-right' },
])

const money = (row: MarketingCampaign, value: number | null) => (value === null ? '—' : formatMoney(value, row.currency))
const dates = (row: MarketingCampaign) => `${formatDate(row.start_on)} – ${row.end_on ? formatDate(row.end_on) : t('marketing.campaigns.noEnd')}`

const formOpen = ref(false)
const editing = ref<MarketingCampaign | null>(null)
function openAdd() {
  editing.value = null
  formOpen.value = true
}
function openEdit(row: MarketingCampaign) {
  editing.value = row
  formOpen.value = true
}

const actionError = ref<string | null>(null)
async function remove(row: MarketingCampaign) {
  if (!(await confirmDialog.confirm({ message: t('marketing.campaigns.deleteConfirm', { name: row.name }), danger: true }))) return
  actionError.value = null
  try {
    await marketingCampaignsService.remove(row.id)
    fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('marketing.campaigns.deleteFailed')
  }
}

onMounted(() => {
  fetch()
  loadChannels()
})
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('marketing.campaigns.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('marketing.campaigns.subtitle') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('marketing.campaigns.searchPlaceholder')"
        class="block w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-36" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseSelect class="w-44" :model-value="channelFilter" :options="channelOptions" @update:model-value="onChannel" />
      <BaseButton v-if="canCreate" class="ml-auto shrink-0" @click="openAdd">{{ t('marketing.campaigns.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

    <!-- Cards on small screens (below sm) instead of the table. -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('marketing.campaigns.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">{{ row.code }} · {{ channelName(row.channel) }}</p>
              <p class="text-xs text-neutral-500">{{ dates(row) }}</p>
            </div>
            <BaseBadge :variant="statusVariant(row.status)">{{ t(`marketing.campaigns.statuses.${row.status}`) }}</BaseBadge>
          </div>
          <dl class="mt-2 grid grid-cols-3 gap-2 text-center text-xs">
            <div class="rounded-md bg-neutral-50 p-1.5">
              <dt class="text-neutral-500">{{ t('marketing.campaigns.cost') }}</dt>
              <dd class="font-semibold tabular-nums text-neutral-900">{{ money(row, row.spent) }}</dd>
            </div>
            <div class="rounded-md bg-neutral-50 p-1.5">
              <dt class="text-neutral-500">{{ t('marketing.campaigns.leads') }}</dt>
              <dd class="font-semibold tabular-nums text-neutral-900">{{ row.leads_count }}</dd>
            </div>
            <div class="rounded-md bg-neutral-50 p-1.5">
              <dt class="text-neutral-500">{{ t('marketing.campaigns.enrolled') }}</dt>
              <dd class="font-semibold tabular-nums text-neutral-900">{{ row.enrolled_count }}</dd>
            </div>
          </dl>
          <div v-if="canUpdate || canDelete" class="mt-2 flex items-center justify-end gap-3">
            <button v-if="canUpdate" type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="openEdit(row)">{{ t('common.edit') }}</button>
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('frontOffice.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <DataTable class="hidden sm:block" :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('marketing.campaigns.empty')">
      <template #cell-code="{ row }"><span class="tabular-nums text-neutral-600">{{ row.code }}</span></template>
      <template #cell-name="{ row }">
        <p class="font-medium text-neutral-900">{{ row.name }}</p>
        <p class="text-xs text-neutral-500">{{ channelName(row.channel) }}<template v-if="row.program"> · {{ row.program.name }}</template></p>
      </template>
      <template #cell-dates="{ row }"><span class="whitespace-nowrap">{{ dates(row) }}</span></template>
      <template #cell-cost="{ row }">
        <p class="font-semibold tabular-nums">{{ money(row, row.spent) }}</p>
        <p v-if="row.budget !== null" class="text-xs text-neutral-500">{{ t('marketing.campaigns.ofBudget', { budget: money(row, row.budget) }) }}</p>
      </template>
      <template #cell-leads="{ row }">
        <p class="tabular-nums">{{ row.leads_count }}</p>
        <p v-if="row.cost_per_lead !== null" class="text-xs text-neutral-500">{{ t('marketing.campaigns.each', { amount: money(row, row.cost_per_lead) }) }}</p>
      </template>
      <template #cell-enrolled="{ row }">
        <p class="tabular-nums">{{ row.enrolled_count }}</p>
        <p v-if="row.cost_per_student !== null" class="text-xs text-neutral-500">{{ t('marketing.campaigns.each', { amount: money(row, row.cost_per_student) }) }}</p>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant(row.status)">{{ t(`marketing.campaigns.statuses.${row.status}`) }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-3 whitespace-nowrap">
          <button v-if="canUpdate" type="button" class="text-sm font-medium text-primary-600 hover:text-primary-700" @click="openEdit(row)">{{ t('common.edit') }}</button>
          <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('frontOffice.delete') }}</button>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CampaignFormModal v-model="formOpen" :campaign="editing" @saved="fetch" />
  </div>
</template>
