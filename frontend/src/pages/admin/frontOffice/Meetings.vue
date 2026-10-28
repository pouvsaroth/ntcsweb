<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import MeetingDetailModal from '@/components/admin/MeetingDetailModal.vue'
import MeetingFormModal from '@/components/admin/MeetingFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import type { StaffOption } from '@/services/inquiries'
import { MEETING_STATUSES, meetingsService, type Meeting, type MeetingSummary, type MeetingWhen } from '@/services/meetings'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatTimeOfDay } from '@/utils/date'

/**
 * Front Office > Meetings: upcoming meetings first (soonest on top), past
 * ones a click away. A row opens the agenda, participants and meeting note.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const when = ref<MeetingWhen>('upcoming')
const mine = ref(false)
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Meeting>((query) =>
  meetingsService.list({ ...query, when: when.value, mine: mine.value }),
)

const canCreate = computed(() => auth.can('meetings.create'))
const canUpdate = computed(() => auth.can('meetings.update'))
const canDelete = computed(() => auth.can('meetings.delete'))

const summary = ref<MeetingSummary | null>(null)
const staff = ref<StaffOption[]>([])
const statusFilter = ref('')
const actionError = ref<string | null>(null)

async function refresh() {
  await Promise.all([fetch(), loadSummary()])
}

async function loadSummary() {
  try {
    summary.value = await meetingsService.summary()
  } catch {
    // The cards are a convenience; the list still works without them.
  }
}

function setWhen(value: MeetingWhen) {
  when.value = value
  // Upcoming means scheduled; a status filter only makes sense for the rest.
  if (value === 'upcoming' && statusFilter.value) {
    statusFilter.value = ''
    setFilter('status', undefined)
    return
  }
  setPage(1)
}

function setStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

function toggleMine() {
  mine.value = !mine.value
  setPage(1)
}

const cards = computed(() => [
  { key: 'today', label: t('frontOffice.meetings.cards.today'), value: summary.value?.today ?? 0, tone: 'text-primary-700' },
  { key: 'upcoming', label: t('frontOffice.meetings.cards.upcoming'), value: summary.value?.upcoming ?? 0, tone: 'text-amber-700' },
  { key: 'this_month', label: t('frontOffice.meetings.cards.thisMonth'), value: summary.value?.this_month ?? 0, tone: 'text-neutral-900' },
  { key: 'completed', label: t('frontOffice.meetings.statuses.completed'), value: summary.value?.completed ?? 0, tone: 'text-green-700' },
])

const whenOptions = computed(() => [
  { value: 'upcoming' as const, label: t('frontOffice.meetings.upcoming') },
  { value: 'past' as const, label: t('frontOffice.meetings.past') },
  { value: '' as const, label: t('frontOffice.meetings.all') },
])
const statusOptions = computed(() => [{ value: '', label: t('frontOffice.meetings.allStatuses') }, ...MEETING_STATUSES.map((s) => ({ value: s, label: t(`frontOffice.meetings.statuses.${s}`) }))])

const columns = computed(() => [
  { key: 'when', label: t('frontOffice.meetings.when') },
  { key: 'title', label: t('frontOffice.meetings.title') },
  { key: 'location', label: t('frontOffice.meetings.location') },
  { key: 'organizer', label: t('frontOffice.meetings.organizer') },
  { key: 'participants', label: t('frontOffice.meetings.participants') },
  { key: 'status', label: t('frontOffice.meetings.status') },
  { key: 'actions', label: t('frontOffice.inquiries.actions'), align: 'text-right' },
])

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const isToday = (row: Meeting) => row.meeting_date === todayIso() && row.status === 'scheduled'
const timeRange = (row: Meeting) => (row.end_time ? `${formatTimeOfDay(row.start_time)} – ${formatTimeOfDay(row.end_time)}` : formatTimeOfDay(row.start_time))
const statusVariant = (s: string) => (({ scheduled: 'primary', completed: 'success', cancelled: 'neutral' }) as const)[s as 'scheduled'] ?? 'neutral'
const guestCount = (row: Meeting) => (row.guests ?? '').split('\n').filter((g) => g.trim()).length

// --- Popups -----------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Meeting | null>(null)
const detailOpen = ref(false)
const detailId = ref<number | null>(null)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(row: Meeting) {
  editing.value = row
  detailOpen.value = false
  formOpen.value = true
}

function openDetail(row: Meeting) {
  detailId.value = row.id
  detailOpen.value = true
}

function onSaved(saved: Meeting) {
  void refresh()
  if (!editing.value) openDetail(saved)
}

async function remove(row: Meeting) {
  if (!(await confirmDialog.confirm({ message: t('frontOffice.meetings.deleteConfirm', { title: row.title }), danger: true }))) return
  actionError.value = null
  try {
    await meetingsService.remove(row.id)
    await refresh()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.meetings.deleteFailed')
  }
}

onMounted(async () => {
  void refresh()
  try {
    staff.value = await meetingsService.staffOptions()
  } catch {
    staff.value = []
  }
})
</script>

<template>
  <div>
    <div class="mb-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
      <div v-for="card in cards" :key="card.key" class="rounded-[--radius-card] border border-neutral-200 bg-white p-2.5 shadow-[--shadow-card] sm:p-4">
        <p class="truncate text-xs font-medium text-neutral-500">{{ card.label }}</p>
        <p class="mt-0.5 text-xl font-semibold sm:mt-1 sm:text-2xl" :class="card.tone">{{ card.value }}</p>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
      <div class="inline-flex rounded-lg border border-neutral-300 bg-white p-0.5 shadow-sm" role="group">
        <button
          v-for="o in whenOptions"
          :key="o.value"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="when === o.value ? 'bg-primary-500 text-secondary-900' : 'text-neutral-600 hover:bg-neutral-100'"
          :aria-pressed="when === o.value"
          @click="setWhen(o.value)"
        >
          {{ o.label }}
        </button>
      </div>
      <input
        type="search"
        :placeholder="t('frontOffice.meetings.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <select
        v-if="when !== 'upcoming'"
        :value="statusFilter"
        class="rounded-lg border border-neutral-300 py-2 pl-3 pr-9 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        :aria-label="t('frontOffice.meetings.status')"
        @change="setStatus(($event.target as HTMLSelectElement).value)"
      >
        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
        <input type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-200" :checked="mine" @change="toggleMine" />
        {{ t('frontOffice.meetings.onlyMine') }}
      </label>
      <BaseButton v-if="canCreate" class="sm:ml-auto" @click="openCreate">{{ t('frontOffice.meetings.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Desktop / tablet -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('frontOffice.meetings.empty')">
        <template #cell-when="{ row }">
          <span class="block font-medium" :class="isToday(row) ? 'text-primary-700' : 'text-neutral-900'">
            {{ formatDate(row.meeting_date) }}<template v-if="isToday(row)"> · {{ t('frontOffice.meetings.today') }}</template>
          </span>
          <span class="block text-xs text-neutral-500">{{ timeRange(row) }}</span>
        </template>
        <template #cell-title="{ row }">
          <button type="button" class="text-left font-medium text-neutral-900 hover:underline" @click="openDetail(row)">{{ row.title }}</button>
        </template>
        <template #cell-location="{ row }">{{ row.location || '—' }}</template>
        <template #cell-organizer="{ row }">{{ row.organizer?.name ?? '—' }}</template>
        <template #cell-participants="{ row }">
          {{ (row.participants_count ?? 0) + guestCount(row) }}
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`frontOffice.meetings.statuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <button type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="openDetail(row)">{{ t('frontOffice.meetings.open') }}</button>
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
        {{ t('frontOffice.meetings.empty') }}
      </p>
      <button
        v-for="row in items"
        :key="row.id"
        type="button"
        class="block w-full rounded-lg border border-neutral-200 bg-white p-3 text-left shadow-sm"
        @click="openDetail(row)"
      >
        <div class="flex items-start justify-between gap-2">
          <p class="min-w-0 truncate font-medium text-neutral-900">{{ row.title }}</p>
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`frontOffice.meetings.statuses.${row.status}`) }}</BaseBadge>
        </div>
        <p class="mt-1 text-sm" :class="isToday(row) ? 'font-medium text-primary-700' : 'text-neutral-700'">
          {{ formatDate(row.meeting_date) }} · {{ timeRange(row) }}
        </p>
        <p class="mt-0.5 text-xs text-neutral-500">
          <template v-if="row.location">{{ row.location }} · </template>{{ t('frontOffice.meetings.peopleCount', { n: (row.participants_count ?? 0) + guestCount(row) }) }}
        </p>
      </button>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <MeetingFormModal v-model="formOpen" :meeting="editing" :staff="staff" @saved="onSaved" />
    <MeetingDetailModal v-model="detailOpen" :meeting-id="detailId" @changed="refresh" @edit="openEdit" />
  </div>
</template>
