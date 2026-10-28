<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import VisitorFormModal from '@/components/admin/VisitorFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { frontDeskService, type Visitor, type VisitorSummary } from '@/services/frontDesk'
import type { StaffOption } from '@/services/inquiries'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatClock, formatDate } from '@/utils/date'

/**
 * Front Office > Visitors: the visitor log for a day (today by default), or
 * everyone still on site. Walk-ins are checked in here; Check out stamps
 * the time they left.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

function todayIso(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const day = ref(todayIso())
const onSite = ref(false)
const { items, meta, loading, error, setPage, setSearch, fetch } = usePaginatedResource<Visitor>((query) =>
  frontDeskService.visitors({ ...query, date: onSite.value ? undefined : day.value, onSite: onSite.value }),
)

const canCreate = computed(() => auth.can('visitors.create'))
const canUpdate = computed(() => auth.can('visitors.update'))
const canDelete = computed(() => auth.can('visitors.delete'))

const summary = ref<VisitorSummary | null>(null)
const staff = ref<StaffOption[]>([])
const actionError = ref<string | null>(null)

async function refresh() {
  await Promise.all([fetch(), loadSummary()])
}

async function loadSummary() {
  try {
    summary.value = await frontDeskService.visitorSummary()
  } catch {
    // The cards are a convenience; the list still works without them.
  }
}

function setDay(value: string) {
  day.value = value
  onSite.value = false
  setPage(1)
}

function showOnSite() {
  onSite.value = !onSite.value
  setPage(1)
}

const cards = computed(() => [
  { key: 'on_site', label: t('frontOffice.visitors.cards.onSite'), value: summary.value?.on_site ?? 0, tone: 'text-amber-700', active: onSite.value, click: showOnSite },
  { key: 'today', label: t('frontOffice.visitors.cards.today'), value: summary.value?.today ?? 0, tone: 'text-primary-700', active: false, click: null },
  { key: 'people_today', label: t('frontOffice.visitors.cards.peopleToday'), value: summary.value?.people_today ?? 0, tone: 'text-neutral-900', active: false, click: null },
  { key: 'this_month', label: t('frontOffice.visitors.cards.thisMonth'), value: summary.value?.this_month ?? 0, tone: 'text-neutral-900', active: false, click: null },
])

const columns = computed(() => [
  { key: 'visitor', label: t('frontOffice.inquiries.name') },
  { key: 'purpose', label: t('frontOffice.appointments.purpose') },
  { key: 'host', label: t('frontOffice.appointments.host') },
  { key: 'people', label: t('frontOffice.visitors.people') },
  { key: 'in', label: t('frontOffice.visitors.checkIn') },
  { key: 'out', label: t('frontOffice.visitors.checkOut') },
  { key: 'actions', label: t('frontOffice.inquiries.actions'), align: 'text-right' },
])

/** The time, with the date too when it isn't the day being shown. */
function stamp(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const sameDay = formatDate(d) === formatDate(day.value) && !onSite.value
  return sameDay ? formatClock(d) : `${formatDate(d)} ${formatClock(d)}`
}

// --- Popups -----------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Visitor | null>(null)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(row: Visitor) {
  editing.value = row
  formOpen.value = true
}

async function checkOut(row: Visitor) {
  actionError.value = null
  try {
    await frontDeskService.checkOut(row.id)
    await refresh()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.visitors.saveFailed')
  }
}

async function remove(row: Visitor) {
  if (!(await confirmDialog.confirm({ message: t('frontOffice.visitors.deleteConfirm', { name: row.name }), danger: true }))) return
  actionError.value = null
  try {
    await frontDeskService.removeVisitor(row.id)
    await refresh()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.visitors.deleteFailed')
  }
}

onMounted(async () => {
  void refresh()
  try {
    staff.value = await frontDeskService.staffOptions()
  } catch {
    staff.value = []
  }
})
</script>

<template>
  <div>
    <div class="mb-5 grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
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

    <div class="mb-4 flex flex-wrap items-end gap-3">
      <div class="w-44">
        <BaseInput :model-value="day" type="date" :aria-label="t('frontOffice.meetings.date')" :disabled="onSite" @update:model-value="setDay(String($event))" />
      </div>
      <BaseButton v-if="day !== todayIso() || onSite" variant="outline" @click="setDay(todayIso())">{{ t('frontOffice.appointments.today') }}</BaseButton>
      <label class="inline-flex items-center gap-2 py-2 text-sm text-neutral-700">
        <input type="checkbox" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-200" :checked="onSite" @change="showOnSite" />
        {{ t('frontOffice.visitors.onSiteOnly') }}
      </label>
      <input
        type="search"
        :placeholder="t('frontOffice.visitors.searchPlaceholder')"
        class="block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200 sm:max-w-xs"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseButton v-if="canCreate" class="sm:ml-auto" @click="openCreate">{{ t('frontOffice.visitors.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Desktop / tablet -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('frontOffice.visitors.empty')">
        <template #cell-visitor="{ row }">
          <span class="block font-medium text-neutral-900">{{ row.name }}</span>
          <span class="block text-xs text-neutral-500">{{ [row.organization, row.phone].filter(Boolean).join(' · ') || '—' }}</span>
        </template>
        <template #cell-purpose="{ row }">
          <span class="line-clamp-2">{{ row.purpose }}</span>
          <span v-if="row.appointment_id" class="text-xs text-neutral-500">{{ t('frontOffice.visitors.byAppointment') }}</span>
        </template>
        <template #cell-host="{ row }">{{ row.host?.name ?? '—' }}</template>
        <template #cell-people="{ row }">
          {{ row.people_count }}<span v-if="row.badge_no" class="block text-xs text-neutral-500">{{ t('frontOffice.visitors.badge') }} {{ row.badge_no }}</span>
        </template>
        <template #cell-in="{ row }">{{ stamp(row.check_in_at) }}</template>
        <template #cell-out="{ row }">
          <span v-if="row.check_out_at">{{ stamp(row.check_out_at) }}</span>
          <BaseBadge v-else variant="warning">{{ t('frontOffice.visitors.onSite') }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <BaseButton v-if="canUpdate && !row.check_out_at" size="sm" variant="outline" @click="checkOut(row)">{{ t('frontOffice.visitors.checkOutNow') }}</BaseButton>
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">{{ t('frontOffice.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <!-- Phone -->
    <div class="space-y-2 sm:hidden">
      <p v-if="loading && items.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="items.length === 0" class="rounded-lg border border-dashed border-neutral-300 py-6 text-center text-sm text-neutral-500">
        {{ t('frontOffice.visitors.empty') }}
      </p>
      <div v-for="row in items" :key="row.id" class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-900">{{ row.name }}<span v-if="row.people_count > 1" class="text-neutral-500"> +{{ row.people_count - 1 }}</span></p>
            <p class="truncate text-sm text-neutral-700">{{ row.purpose }}</p>
          </div>
          <BaseBadge v-if="!row.check_out_at" variant="warning">{{ t('frontOffice.visitors.onSite') }}</BaseBadge>
        </div>
        <p class="mt-1 text-xs text-neutral-500">
          {{ t('frontOffice.visitors.checkIn') }} {{ stamp(row.check_in_at) }}<template v-if="row.check_out_at"> · {{ t('frontOffice.visitors.checkOut') }} {{ stamp(row.check_out_at) }}</template>
          <template v-if="row.host"> · {{ row.host.name }}</template>
        </p>
        <div class="mt-2 flex items-center gap-3">
          <BaseButton v-if="canUpdate && !row.check_out_at" size="sm" variant="outline" @click="checkOut(row)">{{ t('frontOffice.visitors.checkOutNow') }}</BaseButton>
          <button v-if="canUpdate" type="button" class="text-sm font-medium text-secondary-700" @click="openEdit(row)">{{ t('common.edit') }}</button>
        </div>
      </div>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <VisitorFormModal v-model="formOpen" :visitor="editing" :staff="staff" @saved="refresh" />
  </div>
</template>
