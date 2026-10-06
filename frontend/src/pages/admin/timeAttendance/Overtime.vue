<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { staffService, type Staff } from '@/services/staff'
import {
  attendanceReportService,
  clock,
  overtimeRequestsService,
  thisMonthRange,
  type OvertimeRequest,
  type OvertimeStatus,
  type ReportItem,
} from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Attendance & Time > Overtime: the time worked past each shift (from
 * check-ins), next to the overtime actually claimed — claims go through
 * E-Approvals > Approvals. HR can file a claim for someone. Lists that work
 * the same on a phone.
 */
const { t } = useI18n()
const auth = useAuthStore()

const canManage = computed(() => auth.can('staff-attendance.manage'))

const range = thisMonthRange()
const from = ref(range.from)
const to = ref(range.to)
const statusFilter = ref('')
const recorded = ref<ReportItem[]>([])
const requests = ref<OvertimeRequest[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const statusVariant: Record<OvertimeStatus, 'warning' | 'success' | 'danger'> = { pending: 'warning', approved: 'success', rejected: 'danger' }
const statusOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  ...(['pending', 'approved', 'rejected'] as const).map((s) => ({ value: s, label: t(`admin.myRequests.status${s[0]!.toUpperCase()}${s.slice(1)}`) })),
])

/** The claim for that staff member on that day, if any (the latest). */
const claimFor = computed(() => {
  const map = new Map<string, OvertimeRequest>()
  for (const r of requests.value) if (r.status !== 'rejected' || !map.has(`${r.staff_id}|${r.date}`)) map.set(`${r.staff_id}|${r.date}`, r)
  return map
})

const shownRequests = computed(() => (statusFilter.value ? requests.value.filter((r) => r.status === statusFilter.value) : requests.value))
const approvedMinutes = computed(() => requests.value.filter((r) => r.status === 'approved').reduce((sum, r) => sum + r.minutes, 0))

async function load() {
  loading.value = true
  error.value = null
  try {
    const [report, claims] = await Promise.all([
      attendanceReportService.get({ type: 'overtime', from: from.value, to: to.value }),
      overtimeRequestsService.list({ page: 1, per_page: 100, filter: {} }, { from: from.value, to: to.value }),
    ])
    recorded.value = report.items
    requests.value = claims.data
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.loadFailed')
  } finally {
    loading.value = false
  }
}

// --- HR files a claim ---------------------------------------------------------------

const staff = ref<Staff[]>([])
const claimOpen = ref(false)
const claim = reactive({ staff_id: '', date: '', minutes: '', reason: '' })
const claimErrors = ref<Record<string, string[]>>({})
const claimError = ref<string | null>(null)
const saving = ref(false)
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: `${s.full_name} (${s.employee_code})` })))

async function openClaim(item: ReportItem | null) {
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
  claim.staff_id = item ? String(item.staff.id) : ''
  claim.date = item?.date ?? ''
  claim.minutes = item ? String(item.minutes) : ''
  claim.reason = ''
  claimErrors.value = {}
  claimError.value = null
  claimOpen.value = true
}

async function saveClaim() {
  saving.value = true
  claimErrors.value = {}
  claimError.value = null
  try {
    await overtimeRequestsService.create({ staff_id: Number(claim.staff_id), date: claim.date, minutes: Number(claim.minutes), reason: claim.reason })
    claimOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) claimErrors.value = e.errors
    else claimError.value = e instanceof ApiRequestError ? e.message : t('admin.timeAttendance.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-end gap-2">
      <BaseInput v-model="from" type="date" class="w-40" :label="t('admin.timeAttendance.holidays.from')" @update:model-value="load" />
      <BaseInput v-model="to" type="date" class="w-40" :label="t('admin.timeAttendance.holidays.to')" @update:model-value="load" />
      <p class="text-sm text-neutral-600">{{ t('admin.timeAttendance.overtime.approvedTotal', { time: formatMinutes(approvedMinutes) }) }}</p>
      <BaseButton v-if="canManage" class="ml-auto" @click="openClaim(null)">{{ t('admin.timeAttendance.overtime.fileClaim') }}</BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.timeAttendance.overtime.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>
    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <div v-else class="grid gap-4 lg:grid-cols-2">
      <!-- Worked past the shift -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white">
        <h2 class="border-b border-neutral-100 px-4 py-2 text-sm font-semibold text-neutral-800">
          {{ t('admin.timeAttendance.overtime.recorded') }} <span class="font-normal text-neutral-500">({{ recorded.length }})</span>
        </h2>
        <p v-if="recorded.length === 0" class="px-4 py-6 text-center text-sm text-neutral-400">{{ t('admin.timeAttendance.reports.overtime.empty') }}</p>
        <ul class="divide-y divide-neutral-100">
          <li v-for="item in recorded" :key="`${item.staff.id}-${item.date}`" class="flex items-center justify-between gap-3 px-4 py-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-800">{{ item.staff.name }}</p>
              <p class="text-xs text-neutral-500">{{ formatDate(item.date) }} · {{ clock(item.check_in_at) }} – {{ clock(item.check_out_at) }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <span class="text-sm font-semibold text-primary-700">+{{ formatMinutes(item.minutes) }}</span>
              <BaseBadge v-if="claimFor.get(`${item.staff.id}|${item.date}`)" :variant="statusVariant[claimFor.get(`${item.staff.id}|${item.date}`)!.status]">
                {{ t(`admin.timeAttendance.overtime.claim.${claimFor.get(`${item.staff.id}|${item.date}`)!.status}`) }}
              </BaseBadge>
              <button v-else-if="canManage" type="button" class="text-xs font-medium text-primary-700 hover:underline" @click="openClaim(item)">
                {{ t('admin.timeAttendance.overtime.claimIt') }}
              </button>
              <span v-else class="text-xs text-neutral-400">{{ t('admin.timeAttendance.overtime.notClaimed') }}</span>
            </div>
          </li>
        </ul>
      </section>

      <!-- Claims -->
      <section class="rounded-[--radius-card] border border-neutral-200 bg-white">
        <div class="flex items-center justify-between gap-2 border-b border-neutral-100 px-4 py-1.5">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('admin.timeAttendance.overtime.requests') }} <span class="font-normal text-neutral-500">({{ shownRequests.length }})</span></h2>
          <BaseSelect v-model="statusFilter" class="w-36" :options="statusOptions" />
        </div>
        <p v-if="shownRequests.length === 0" class="px-4 py-6 text-center text-sm text-neutral-400">{{ t('admin.timeAttendance.overtime.noRequests') }}</p>
        <ul class="divide-y divide-neutral-100">
          <li v-for="r in shownRequests" :key="r.id" class="px-4 py-2">
            <div class="flex items-center justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-neutral-800">{{ r.staff?.name }}</p>
                <p class="text-xs text-neutral-500">{{ r.reference }} · {{ formatDate(r.date) }} · {{ formatMinutes(r.minutes) }}</p>
              </div>
              <BaseBadge :variant="statusVariant[r.status]" class="shrink-0">{{ t(`admin.timeAttendance.overtime.claim.${r.status}`) }}</BaseBadge>
            </div>
            <p class="mt-1 text-xs text-neutral-600">{{ r.reason }}</p>
            <p v-if="r.decision_reason" class="text-xs text-danger-600">{{ r.decision_reason }}</p>
          </li>
        </ul>
      </section>
    </div>

    <BaseModal v-model="claimOpen" :title="t('admin.timeAttendance.overtime.fileClaim')">
      <form class="space-y-4" @submit.prevent="saveClaim">
        <BaseAlert v-if="claimError" variant="danger">{{ claimError }}</BaseAlert>
        <BaseSelect v-model="claim.staff_id" required :options="staffOptions" :placeholder="t('admin.timeAttendance.entry.selectStaff')" :label="t('admin.timeAttendance.entry.staff')" :error="claimErrors.staff_id?.[0]" />
        <div class="grid grid-cols-2 gap-4">
          <BaseInput v-model="claim.date" type="date" required :label="t('admin.timeAttendance.holidays.date')" :error="claimErrors.date?.[0]" />
          <BaseInput v-model="claim.minutes" type="number" min="1" required :label="t('admin.timeAttendance.overtime.minutes')" :error="claimErrors.minutes?.[0]" />
        </div>
        <BaseInput v-model="claim.reason" required :label="t('admin.timeAttendance.overtime.reason')" :error="claimErrors.reason?.[0]" />
        <p class="text-xs text-neutral-500">{{ t('admin.timeAttendance.overtime.goesToApprovals') }}</p>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="claimOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="saveClaim">{{ t('admin.timeAttendance.overtime.submit') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
