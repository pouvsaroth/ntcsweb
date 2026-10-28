<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import AppointmentFormModal from '@/components/admin/AppointmentFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { APPOINTMENT_STATUSES, frontDeskService, type Appointment, type AppointmentStatus, type AppointmentSummary, type AppointmentWhen } from '@/services/frontDesk'
import type { StaffOption } from '@/services/inquiries'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate, formatTimeOfDay } from '@/utils/date'

/**
 * Front Office > Appointments: today's bookings first. Check in when the
 * person arrives — that adds them to the Visitors log and tells their host.
 */
const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const confirmDialog = useConfirmDialogStore()

const when = ref<AppointmentWhen>('today')
const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<Appointment>((query) =>
  frontDeskService.appointments({ ...query, when: when.value }),
)

const canCreate = computed(() => auth.can('appointments.create'))
const canUpdate = computed(() => auth.can('appointments.update'))
const canDelete = computed(() => auth.can('appointments.delete'))
const canCheckIn = computed(() => canUpdate.value && auth.can('visitors.create'))

const summary = ref<AppointmentSummary | null>(null)
const staff = ref<StaffOption[]>([])
const statusFilter = ref('')
const actionError = ref<string | null>(null)

async function refresh() {
  await Promise.all([fetch(), loadSummary()])
}

async function loadSummary() {
  try {
    summary.value = await frontDeskService.appointmentSummary()
  } catch {
    // The cards are a convenience; the list still works without them.
  }
}

function setWhen(value: AppointmentWhen) {
  when.value = value
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

const cards = computed(() => [
  { key: 'today', label: t('frontOffice.appointments.cards.today'), value: summary.value?.today ?? 0, tone: 'text-primary-700' },
  { key: 'waiting', label: t('frontOffice.appointments.cards.waiting'), value: summary.value?.waiting ?? 0, tone: 'text-amber-700' },
  { key: 'upcoming', label: t('frontOffice.appointments.cards.upcoming'), value: summary.value?.upcoming ?? 0, tone: 'text-neutral-900' },
  { key: 'no_show', label: t('frontOffice.appointments.cards.noShow'), value: summary.value?.no_show ?? 0, tone: 'text-red-700' },
])

const whenOptions = computed(() => [
  { value: 'today' as const, label: t('frontOffice.appointments.today') },
  { value: 'upcoming' as const, label: t('frontOffice.meetings.upcoming') },
  { value: 'past' as const, label: t('frontOffice.meetings.past') },
  { value: '' as const, label: t('frontOffice.meetings.all') },
])
const statusOptions = computed(() => [
  { value: '', label: t('frontOffice.meetings.allStatuses') },
  ...APPOINTMENT_STATUSES.map((s) => ({ value: s, label: t(`frontOffice.appointments.statuses.${s}`) })),
])

const columns = computed(() => [
  { key: 'when', label: t('frontOffice.meetings.when') },
  { key: 'visitor', label: t('frontOffice.appointments.visitorName') },
  { key: 'purpose', label: t('frontOffice.appointments.purpose') },
  { key: 'host', label: t('frontOffice.appointments.host') },
  { key: 'status', label: t('frontOffice.meetings.status') },
  { key: 'actions', label: t('frontOffice.inquiries.actions'), align: 'text-right' },
])

const timeRange = (row: Appointment) => (row.end_time ? `${formatTimeOfDay(row.start_time)} – ${formatTimeOfDay(row.end_time)}` : formatTimeOfDay(row.start_time))
const statusVariant = (s: string) =>
  (({ scheduled: 'primary', checked_in: 'warning', completed: 'success', cancelled: 'neutral', no_show: 'danger' }) as const)[s as 'scheduled'] ?? 'neutral'

// --- Book / edit --------------------------------------------------------------
const formOpen = ref(false)
const editing = ref<Appointment | null>(null)

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(row: Appointment) {
  editing.value = row
  formOpen.value = true
}

// --- Check in -----------------------------------------------------------------
const checkInOpen = ref(false)
const checkInFor = ref<Appointment | null>(null)
const checkInForm = reactive({ people_count: '1', id_number: '', badge_no: '' })
const checkInErrors = ref<Record<string, string[]>>({})
const checkingIn = ref(false)

function openCheckIn(row: Appointment) {
  checkInFor.value = row
  Object.assign(checkInForm, { people_count: '1', id_number: '', badge_no: '' })
  checkInErrors.value = {}
  checkInOpen.value = true
}

async function checkIn() {
  if (!checkInFor.value) return
  checkingIn.value = true
  checkInErrors.value = {}
  actionError.value = null
  try {
    await frontDeskService.checkIn(checkInFor.value.id, {
      people_count: Math.max(1, Number(checkInForm.people_count) || 1),
      id_number: checkInForm.id_number.trim() || null,
      badge_no: checkInForm.badge_no.trim() || null,
    })
    checkInOpen.value = false
    await refresh()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) checkInErrors.value = e.errors
    else actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.appointments.saveFailed')
  } finally {
    checkingIn.value = false
  }
}

async function setAppointmentStatus(row: Appointment, status: AppointmentStatus) {
  if (status === 'cancelled' && !(await confirmDialog.confirm({ message: t('frontOffice.appointments.cancelConfirm', { name: row.visitor_name }), danger: true }))) return
  actionError.value = null
  try {
    await frontDeskService.updateAppointment(row.id, { status })
    await refresh()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.appointments.saveFailed')
  }
}

async function remove(row: Appointment) {
  if (!(await confirmDialog.confirm({ message: t('frontOffice.appointments.deleteConfirm', { name: row.visitor_name }), danger: true }))) return
  actionError.value = null
  try {
    await frontDeskService.removeAppointment(row.id)
    await refresh()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('frontOffice.appointments.deleteFailed')
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
        :placeholder="t('frontOffice.appointments.searchPlaceholder')"
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
      <BaseButton v-if="canCreate" class="sm:ml-auto" @click="openCreate">{{ t('frontOffice.appointments.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Desktop / tablet -->
    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('frontOffice.appointments.empty')">
        <template #cell-when="{ row }">
          <span class="block font-medium text-neutral-900">{{ formatDate(row.appointment_date) }}</span>
          <span class="block text-xs text-neutral-500">{{ timeRange(row) }}</span>
        </template>
        <template #cell-visitor="{ row }">
          <span class="block font-medium text-neutral-900">{{ row.visitor_name }}</span>
          <span class="block text-xs text-neutral-500">{{ [row.organization, row.phone].filter(Boolean).join(' · ') || '—' }}</span>
        </template>
        <template #cell-purpose="{ row }"><span class="line-clamp-2">{{ row.purpose }}</span></template>
        <template #cell-host="{ row }">{{ row.host?.name ?? '—' }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`frontOffice.appointments.statuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
            <BaseButton v-if="canCheckIn && row.status === 'scheduled'" size="sm" @click="openCheckIn(row)">{{ t('frontOffice.appointments.checkIn') }}</BaseButton>
            <button v-if="row.visitor" type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="router.push('/admin/front-office/visitors')">
              {{ t('frontOffice.appointments.seeVisit') }}
            </button>
            <button v-if="canUpdate && row.status === 'scheduled'" type="button" class="text-sm font-medium text-neutral-600 hover:underline" @click="setAppointmentStatus(row, 'no_show')">
              {{ t('frontOffice.appointments.noShow') }}
            </button>
            <button v-if="canUpdate && row.status === 'scheduled'" type="button" class="text-sm font-medium text-neutral-600 hover:underline" @click="setAppointmentStatus(row, 'cancelled')">
              {{ t('frontOffice.appointments.cancel') }}
            </button>
            <button v-if="canUpdate && ['cancelled', 'no_show'].includes(row.status)" type="button" class="text-sm font-medium text-secondary-700 hover:underline" @click="setAppointmentStatus(row, 'scheduled')">
              {{ t('frontOffice.meetings.reopen') }}
            </button>
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
        {{ t('frontOffice.appointments.empty') }}
      </p>
      <div v-for="row in items" :key="row.id" class="rounded-lg border border-neutral-200 bg-white p-3 shadow-sm">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="truncate font-medium text-neutral-900">{{ row.visitor_name }}</p>
            <p class="text-sm text-neutral-700">{{ formatDate(row.appointment_date) }} · {{ timeRange(row) }}</p>
          </div>
          <BaseBadge :variant="statusVariant(row.status)">{{ t(`frontOffice.appointments.statuses.${row.status}`) }}</BaseBadge>
        </div>
        <p class="mt-1 truncate text-sm text-neutral-700">{{ row.purpose }}</p>
        <p v-if="row.host" class="text-xs text-neutral-500">{{ t('frontOffice.appointments.host') }}: {{ row.host.name }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-3">
          <BaseButton v-if="canCheckIn && row.status === 'scheduled'" size="sm" @click="openCheckIn(row)">{{ t('frontOffice.appointments.checkIn') }}</BaseButton>
          <button v-if="canUpdate && row.status === 'scheduled'" type="button" class="text-sm font-medium text-neutral-600" @click="setAppointmentStatus(row, 'no_show')">
            {{ t('frontOffice.appointments.noShow') }}
          </button>
          <button v-if="canUpdate" type="button" class="text-sm font-medium text-secondary-700" @click="openEdit(row)">{{ t('common.edit') }}</button>
        </div>
      </div>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <AppointmentFormModal v-model="formOpen" :appointment="editing" :staff="staff" @saved="refresh" />

    <BaseModal v-model="checkInOpen" size="sm" :title="t('frontOffice.appointments.checkInTitle', { name: checkInFor?.visitor_name ?? '' })">
      <form id="check-in-form" class="space-y-4" novalidate @submit.prevent="checkIn">
        <p class="text-sm text-neutral-600">{{ t('frontOffice.appointments.checkInLede') }}</p>
        <BaseInput v-model="checkInForm.people_count" type="number" :label="t('frontOffice.visitors.people')" :error="checkInErrors.people_count?.[0]" />
        <BaseInput v-model="checkInForm.id_number" :label="t('frontOffice.visitors.idNumber')" :error="checkInErrors.id_number?.[0]" />
        <BaseInput v-model="checkInForm.badge_no" :label="t('frontOffice.visitors.badge')" :error="checkInErrors.badge_no?.[0]" />
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="checkInOpen = false">{{ t('common.cancel') }}</BaseButton>
        <BaseButton type="submit" form="check-in-form" :loading="checkingIn">{{ t('frontOffice.visitors.checkInNow') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
