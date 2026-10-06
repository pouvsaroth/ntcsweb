<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import CorrectionFormModal from '@/components/admin/CorrectionFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { staffService, type Staff } from '@/services/staff'
import { attendanceCorrectionsService, clock, type AttendanceCorrection, type OvertimeStatus } from '@/services/staffAttendance'
import { useAuthStore } from '@/stores/auth'
import { formatDate } from '@/utils/date'

/**
 * HRM > Attendance & Time > Attendance correction: requests to fix a day's
 * check-in / check-out, what was recorded next to what's asked, and their
 * decision (made in E-Approvals > Approvals). HR can file one for someone.
 * A list that works the same on a phone.
 */
const { t } = useI18n()
const auth = useAuthStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<AttendanceCorrection>((query) => attendanceCorrectionsService.list(query))

const statusFilter = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  ...(['pending', 'approved', 'rejected'] as const).map((s) => ({ value: s, label: t(`admin.timeAttendance.overtime.claim.${s}`) })),
])
const statusVariant: Record<OvertimeStatus, 'warning' | 'success' | 'danger'> = { pending: 'warning', approved: 'success', rejected: 'danger' }

function onStatus(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const staff = ref<Staff[]>([])
const formOpen = ref(false)

async function openForm() {
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
  formOpen.value = true
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <input
        type="search"
        :placeholder="t('common.searchPlaceholder')"
        class="block w-full max-w-xs rounded-lg border border-neutral-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-200"
        @input="setSearch(($event.target as HTMLInputElement).value)"
      />
      <BaseSelect class="w-40" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatus" />
      <BaseButton v-if="canManage" class="ml-auto" @click="openForm">{{ t('admin.timeAttendance.corrections.forSomeone') }}</BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.timeAttendance.corrections.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
    <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
      {{ t('admin.timeAttendance.corrections.empty') }}
    </p>

    <ul v-else class="divide-y divide-neutral-100 rounded-[--radius-card] border border-neutral-200 bg-white">
      <li v-for="row in items" :key="row.id" class="px-4 py-3">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-neutral-800">{{ row.staff?.name }} <span class="font-normal text-neutral-500">{{ row.staff?.employee_code }}</span></p>
            <p class="text-xs text-neutral-500">{{ row.reference }} · {{ formatDate(row.date) }}<template v-if="row.requested_by"> · {{ row.requested_by }}</template></p>
          </div>
          <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.timeAttendance.overtime.claim.${row.status}`) }}</BaseBadge>
        </div>
        <div class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
          <p class="rounded-lg bg-neutral-50 px-3 py-1.5">
            <span class="text-xs text-neutral-500">{{ t('admin.timeAttendance.corrections.recorded') }}</span><br />
            <span class="tabular-nums">{{ clock(row.recorded.check_in_at) }} – {{ clock(row.recorded.check_out_at) }}</span>
            <span v-if="row.recorded.status" class="text-xs text-neutral-500"> · {{ t(`admin.timeAttendance.statuses.${row.recorded.status}`) }}</span>
          </p>
          <p class="rounded-lg bg-primary-50 px-3 py-1.5">
            <span class="text-xs text-primary-700">{{ t('admin.timeAttendance.corrections.requested') }}</span><br />
            <span class="font-medium tabular-nums">{{ row.check_in ?? clock(row.recorded.check_in_at) }} – {{ row.check_out ?? clock(row.recorded.check_out_at) }}</span>
          </p>
        </div>
        <p class="mt-1 text-xs text-neutral-600">{{ row.reason }}</p>
        <p v-if="row.decision_reason" class="text-xs text-danger-600">{{ row.decision_reason }}</p>
      </li>
    </ul>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <CorrectionFormModal v-model="formOpen" :staff="staff" @saved="fetch" />
  </div>
</template>
