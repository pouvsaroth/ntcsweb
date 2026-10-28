<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import InquiryDetailModal from '@/components/admin/InquiryDetailModal.vue'
import InquiryFormModal from '@/components/admin/InquiryFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { INQUIRY_SOURCES, INQUIRY_STATUSES, inquiriesService, type Inquiry, type InquirySummary, type StaffOption } from '@/services/inquiries'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * Front Office > Inquiry: everyone who asked about the school. Cards on top
 * count them (and how many are due a follow-up, which filters the list);
 * a row opens its follow-up history.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const dueOnly = ref(false)
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Inquiry>((query) =>
  inquiriesService.list({ ...query, due: dueOnly.value }),
)

const canCreate = computed(() => auth.can('inquiries.create'))
const canUpdate = computed(() => auth.can('inquiries.update'))
const canDelete = computed(() => auth.can('inquiries.delete'))

const summary = ref<InquirySummary | null>(null)
const staff = ref<StaffOption[]>([])
const statusFilter = ref('')
const sourceFilter = ref('')
const actionError = ref<string | null>(null)

async function refresh() {
  await Promise.all([fetch(), loadSummary()])
}

async function loadSummary() {
  try {
    summary.value = await inquiriesService.summary()
  } catch {
    // The cards are a convenience; the list still works without them.
  }
}

function setStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

function setSource(value: string) {
  sourceFilter.value = value
  setFilter('source', value || undefined)
}

function toggleDue() {
  dueOnly.value = !dueOnly.value
  void fetch()
}

function showStatus(value: string) {
  dueOnly.value = false
  setStatus(statusFilter.value === value ? '' : value)
}

const cards = computed(() => [
  { key: 'this_month', label: t('frontOffice.inquiries.cards.thisMonth'), value: summary.value?.this_month ?? 0, tone: 'text-neutral-900', active: false, click: null },
  { key: 'new', label: t('frontOffice.inquiries.statuses.new'), value: summary.value?.by_status.new ?? 0, tone: 'text-primary-700', active: statusFilter.value === 'new', click: () => showStatus('new') },
  { key: 'following_up', label: t('frontOffice.inquiries.statuses.following_up'), value: summary.value?.by_status.following_up ?? 0, tone: 'text-amber-700', active: statusFilter.value === 'following_up', click: () => showStatus('following_up') },
  { key: 'due', label: t('frontOffice.inquiries.cards.due'), value: summary.value?.due ?? 0, tone: 'text-red-700', active: dueOnly.value, click: toggleDue },
  { key: 'enrolled', label: t('frontOffice.inquiries.statuses.enrolled'), value: summary.value?.by_status.enrolled ?? 0, tone: 'text-green-700', active: statusFilter.value === 'enrolled', click: () => showStatus('enrolled') },
])

const statusOptions = computed(() => [{ value: '', label: t('frontOffice.inquiries.allStatuses') }, ...INQUIRY_STATUSES.map((s) => ({ value: s, label: t(`frontOffice.inquiries.statuses.${s}`) }))])
const sourceOptions = computed(() => [{ value: '', label: t('frontOffice.inquiries.allSources') }, ...INQUIRY_SOURCES.map((s) => ({ value: s, label: t(`frontOffice.inquiries.sources.${s}`) }))])

const columns = computed(() => [
  { key: 'inquiry_date', label: t('frontOffice.inquiries.date') },
  { key: 'name', label: t('frontOffice.inquiries.name') },
  { key: 'interested_in', label: t('frontOffice.inquiries.interestedIn') },
  { key: 'source', label: t('frontOffice.inquiries.source') },
  { key: 'status', label: t('frontOffice.inquiries.status') },
  { key: 'next_follow_up_on', label: t('frontOffice.inquiries.nextFollowUp') },
  { key: 'assigned', label: t('frontOffice.inquiries.assignedTo') },
  { key: 'actions', label: t('frontOffice.inquiries.actions'), align: 'text-right' },
])

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const isDue = (row: Inquiry) => row.next_follow_up_on !== null && row.next_follow_up_on <= todayIso() && ['new', 'following_up'].includes(row.status)
const statusVariant = (s: string) => (({ new: 'primary', following_up: 'warning', enrolled: 'success', not_interested: 'neutral' }) as const)[s as 'new'] ?? 'neutral'

// --- Popups -----------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Inquiry | null>(null)
const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(row: Inquiry) {
  editing.value = row
  detailOpen.value = false
  formOpen.value = true
}

function openDetail(row: Inquiry) {
  detailId.value = row.id
  detailOpen.value = true
}

function onSaved(saved: Inquiry) {
  void refresh()
  if (!editing.value) openDetail(saved)
}

async function remove(row: Inquiry) {
  if (!(await confirmDialog.confirm({ message: t('frontOffice.inquiries.deleteConfirm', { name: row.name }), danger: true }))) return
  actionError.value = null
  try {
    await inquiriesService.remove(row.id)
    await refresh()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.inquiries.deleteFailed')
  }
}

onMounted(async () => {
  void refresh()
  try {
    staff.value = await inquiriesService.staffOptions()
  } catch {
    staff.value = []
  }
})
</script>

<template>
  <div>
    <!-- Counts; New / Following up / Due / Enrolled filter the list -->
    <div class="mb-5 grid grid-cols-3 gap-2 sm:gap-3 lg:grid-cols-5">
      <component
        :is="card.click ? 'button' : 'div'"
        v-for="card in cards"
        :key="card.key"
        :type="card.click ? 'button' : undefined"
        class="rounded-[--radius-card] border bg-white p-2.5 text-left shadow-[--shadow-card] transition sm:p-4"
        :class="[card.active ? 'border-primary-500 ring-2 ring-primary-200' : 'border-neutral-200', card.click ? 'hover:border-primary-300' : '']"
        :aria-pressed="card.click ? card.active : undefined"
        @click="card.click?.()"
      >
        <p class="truncate text-xs font-medium text-neutral-500">{{ card.label }}</p>
        <p class="mt-0.5 text-xl font-semibold sm:mt-1 sm:text-2xl" :class="card.tone">{{ card.value }}</p>
      </component>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <input
        type="search"
        :placeholder="t('frontOffice.inquiries.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <select
        :value="statusFilter"
        class="rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :aria-label="t('frontOffice.inquiries.status')"
        @change="setStatus(($event.target as HTMLSelectElement).value)"
      >
        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <select
        :value="sourceFilter"
        class="rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :aria-label="t('frontOffice.inquiries.source')"
        @change="setSource(($event.target as HTMLSelectElement).value)"
      >
        <option v-for="o in sourceOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <BaseButton v-if="canCreate" class="sm:ml-auto" @click="openCreate">{{ t('frontOffice.inquiries.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Desktop / tablet -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('frontOffice.inquiries.empty')">
        <template #cell-inquiry_date="{ row }">{{ formatDate(row.inquiry_date) }}</template>
        <template #cell-name="{ row }">
          <button type="button" class="text-left" @click="openDetail(row)">
            <span class="block font-medium text-neutral-900 hover:underline">{{ row.name }}</span>
            <span class="block text-xs text-neutral-500">{{ row.phone }}</span>
          </button>
        </template>
        <template #cell-interested_in="{ row }">{{ row.interested_in || '—' }}</template>
        <template #cell-source="{ row }">{{ t(`frontOffice.inquiries.sources.${row.source}`) }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`frontOffice.inquiries.statuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-next_follow_up_on="{ row }">
          <span :class="isDue(row) ? 'font-medium text-danger-600' : ''">{{ row.next_follow_up_on ? formatDate(row.next_follow_up_on) : '—' }}</span>
        </template>
        <template #cell-assigned="{ row }">{{ row.assigned_staff?.name ?? '—' }}</template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openDetail(row)">
              {{ t('frontOffice.inquiries.followUps') }}<template v-if="row.follow_ups_count"> ({{ row.follow_ups_count }})</template>
            </button>
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('frontOffice.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Phone -->
    <div class="space-y-2 sm:hidden">
      <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">
        {{ t('frontOffice.inquiries.empty') }}
      </p>
      <button
        v-for="row in items"
        :key="row.id"
        type="button"
        class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm"
        @click="openDetail(row)"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-900">{{ row.name }}</p>
            <p class="text-xs text-neutral-500">{{ row.phone }} · {{ t(`frontOffice.inquiries.sources.${row.source}`) }}</p>
          </div>
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`frontOffice.inquiries.statuses.${row.status}`) }}</BaseBadge>
        </div>
        <p v-if="row.interested_in" class="mt-1 truncate text-sm text-neutral-700">{{ row.interested_in }}</p>
        <p class="mt-1 text-xs text-neutral-500">
          {{ formatDate(row.inquiry_date) }}
          <template v-if="row.next_follow_up_on">
            · <span :class="isDue(row) ? 'font-medium text-danger-600' : ''">{{ t('frontOffice.inquiries.nextFollowUp') }}: {{ formatDate(row.next_follow_up_on) }}</span>
          </template>
        </p>
      </button>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <InquiryFormModal v-model="formOpen" :inquiry="editing" :staff="staff" @saved="onSaved" />
    <InquiryDetailModal v-model="detailOpen" :inquiry-id="detailId" @changed="refresh" @edit="openEdit" />
  </div>
</template>
