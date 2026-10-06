<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseInput from '@/components/ui/BaseInput.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDays, leaveTypesService, type LeaveType } from '@/services/leaveManagement'
import { leaveRequestsService, type LeaveDayPart, type LeaveRequest, type LeaveRequestStatus } from '@/services/leaveRequests'
import { staffService, type Staff } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Leave Management > Leave request — every staff member's leave by
 * year, type and status. Requests are decided in E-Approvals > Approvals
 * (with its approval flow, if one is set); HR can also file one on a staff
 * member's behalf here, checked like their own minus the notice period.
 * Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()

const canFile = computed(() => auth.can('leave-management.manage'))

const statusVariant: Record<LeaveRequestStatus, 'warning' | 'success' | 'danger'> = { pending: 'warning', approved: 'success', rejected: 'danger' }

function statusLabel(status: LeaveRequestStatus): string {
  return t(`admin.leaveRequests.status${status.charAt(0).toUpperCase()}${status.slice(1)}`)
}

// --- Filters -----------------------------------------------------------------------

const thisYear = new Date().getFullYear()
const year = ref(thisYear)
const yearOptions = [thisYear - 2, thisYear - 1, thisYear, thisYear + 1].map((y) => ({ value: String(y), label: String(y) }))
const typeFilter = ref('')
const statusFilter = ref('')

const leaveTypes = ref<LeaveType[]>([])
const typeFilterOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.policies.allTypes') },
  ...leaveTypes.value.map((type) => ({ value: String(type.id), label: type.name })),
])
const statusOptions = computed(() => [
  { value: '', label: t('admin.leaveRequests.filterAllStatuses') },
  ...(['pending', 'approved', 'rejected'] as const).map((status) => ({ value: status, label: statusLabel(status) })),
])

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<LeaveRequest>((query) =>
  leaveRequestsService.list(
    {
      ...query,
      sort: '-from_date',
      filter: {
        ...(typeFilter.value ? { leave_type_id: typeFilter.value } : {}),
        ...(statusFilter.value ? { status: statusFilter.value } : {}),
      },
    },
    { staffOnly: true, year: year.value },
  ),
)

watch([year, typeFilter, statusFilter], () => void fetch())

function dates(row: LeaveRequest): string {
  const range = row.from_date === row.to_date ? formatDate(row.from_date) : `${formatDate(row.from_date)} – ${formatDate(row.to_date)}`
  return row.day_part === 'morning' || row.day_part === 'afternoon' ? `${range} (${t(`leaveRequest.day${row.day_part === 'morning' ? 'Morning' : 'Afternoon'}`)})` : range
}

const columns = computed(() => [
  { key: 'staff', label: t('admin.leaveManagement.requests.staff') },
  { key: 'type', label: t('admin.leaveManagement.policies.leaveType') },
  { key: 'dates', label: t('admin.leaveRequests.columnDates') },
  { key: 'days', label: t('admin.leaveManagement.requests.days'), align: 'text-right' },
  { key: 'status', label: t('admin.leaveRequests.columnStatus') },
])

const detail = ref<LeaveRequest | null>(null)

// --- HR files one for a staff member -------------------------------------------------

const staff = ref<Staff[]>([])
const staffOptions = computed(() => staff.value.map((s) => ({ value: String(s.id), label: s.full_name, hint: s.employee_code })))
const activeTypeOptions = computed(() => leaveTypes.value.filter((type) => type.is_active).map((type) => ({ value: String(type.id), label: type.name })))
const dayPartOptions = computed(() => [
  { value: 'full', label: t('leaveRequest.dayFull') },
  { value: 'morning', label: t('leaveRequest.dayMorning') },
  { value: 'afternoon', label: t('leaveRequest.dayAfternoon') },
])

const formOpen = ref(false)
const form = reactive({ staff_id: '', leave_type_id: '', from_date: '', to_date: '', day_part: 'full' as LeaveDayPart, reason: '' })
const attachments = ref<File[]>([])
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)

const formType = computed(() => leaveTypes.value.find((type) => String(type.id) === form.leave_type_id) ?? null)
const canHalfDay = computed(() => form.from_date !== '' && (form.to_date === '' || form.to_date === form.from_date) && (formType.value?.allow_half_day ?? false))
watch(canHalfDay, (allowed) => {
  if (!allowed) form.day_part = 'full'
})

async function openForm() {
  Object.assign(form, { staff_id: '', leave_type_id: '', from_date: '', to_date: '', day_part: 'full', reason: '' })
  attachments.value = []
  errors.value = {}
  saveError.value = null
  formOpen.value = true
  if (staff.value.length === 0) staff.value = await staffService.listAll().catch(() => [])
}

function onFiles(event: Event) {
  const input = event.target as HTMLInputElement
  attachments.value = [...attachments.value, ...Array.from(input.files ?? [])]
  input.value = ''
}

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  try {
    await leaveRequestsService.createForStaff(Number(form.staff_id), {
      leave_type_id: form.leave_type_id ? Number(form.leave_type_id) : null,
      day_part: form.day_part,
      from_date: form.from_date,
      to_date: form.to_date || form.from_date,
      from_time: null,
      to_time: null,
      reason: form.reason,
      attachments: attachments.value,
    })
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  void fetch()
  leaveTypes.value = await leaveTypesService.listAll().catch(() => [])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-28" :model-value="String(year)" :options="yearOptions" @update:model-value="year = Number($event)" />
      <BaseSelect class="w-full sm:w-48" :model-value="typeFilter" :options="typeFilterOptions" @update:model-value="typeFilter = $event" />
      <BaseSelect class="w-full sm:w-40" :model-value="statusFilter" :options="statusOptions" @update:model-value="statusFilter = $event" />
      <BaseButton v-if="canFile" class="ml-auto" @click="openForm">{{ t('admin.leaveManagement.requests.add') }}</BaseButton>
    </div>
    <p class="mb-4 text-sm text-neutral-500">{{ t('admin.leaveManagement.requests.hint') }}</p>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.leaveManagement.requests.empty') }}
      </p>
      <div v-else class="space-y-2">
        <button
          v-for="row in items"
          :key="row.id"
          type="button"
          class="block w-full rounded-[--radius-card] border border-neutral-200 bg-white p-3 text-left shadow-[--shadow-card]"
          @click="detail = row"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.staff?.name ?? '—' }}</p>
              <p class="flex items-center gap-1.5 text-xs text-neutral-500">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type?.color ?? '#9ca3af' }" />
                {{ row.leave_type?.name ?? t('admin.leaveManagement.requests.noType') }}
              </p>
            </div>
            <BaseBadge :variant="statusVariant[row.status]" class="shrink-0">{{ statusLabel(row.status) }}</BaseBadge>
          </div>
          <p class="mt-2 text-sm text-neutral-700">
            {{ dates(row) }}<template v-if="row.days != null"> · {{ t('admin.leaveManagement.requests.daysN', { days: formatDays(row.days) }) }}</template>
          </p>
        </button>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.leaveManagement.requests.empty')">
        <template #cell-staff="{ row }">
          <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="detail = row as LeaveRequest">{{ row.staff?.name ?? '—' }}</button>
          <p class="text-xs text-neutral-500">{{ row.staff?.employee_code }}</p>
        </template>
        <template #cell-type="{ row }">
          <p class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type?.color ?? '#9ca3af' }" />
            {{ row.leave_type?.name ?? t('admin.leaveManagement.requests.noType') }}
          </p>
        </template>
        <template #cell-dates="{ row }">{{ dates(row as LeaveRequest) }}</template>
        <template #cell-days="{ row }"><span class="tabular-nums">{{ row.days != null ? formatDays(row.days) : '—' }}</span></template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status as LeaveRequestStatus]">{{ statusLabel(row.status) }}</BaseBadge>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <!-- Details -->
    <BaseModal :model-value="detail !== null" :title="detail?.staff?.name ?? ''" @update:model-value="detail = null">
      <dl v-if="detail" class="grid gap-y-2 text-sm">
        <div><dt class="text-neutral-500">{{ t('admin.leaveManagement.policies.leaveType') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave_type?.name ?? t('admin.leaveManagement.requests.noType') }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnDates') }}</dt><dd class="font-medium text-neutral-900">{{ dates(detail) }}</dd></div>
        <div v-if="detail.days != null"><dt class="text-neutral-500">{{ t('admin.leaveManagement.requests.days') }}</dt><dd class="font-medium text-neutral-900">{{ formatDays(detail.days) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.reason }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnStatus') }}</dt><dd><BaseBadge :variant="statusVariant[detail.status]">{{ statusLabel(detail.status) }}</BaseBadge></dd></div>
        <div v-if="detail.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.decision_reason }}</dd></div>
        <div v-if="detail.attachments.length > 0">
          <dt class="text-neutral-500">{{ t('admin.leaveRequests.attachments') }}</dt>
          <dd class="mt-1 flex flex-col gap-1">
            <a v-for="file in detail.attachments" :key="file.id" :href="file.url" target="_blank" rel="noopener" class="text-primary-700 hover:underline">{{ file.file_name }}</a>
          </dd>
        </div>
      </dl>
      <p v-if="detail?.status === 'pending'" class="mt-4 text-sm text-neutral-500">
        {{ t('admin.leaveManagement.requests.decideIn') }}
        <RouterLink to="/admin/approvals/queue" class="font-medium text-primary-700 hover:underline">{{ t('admin.leaveManagement.requests.openApprovals') }}</RouterLink>
      </p>
      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
      </template>
    </BaseModal>

    <!-- HR files one -->
    <BaseModal v-model="formOpen" :title="t('admin.leaveManagement.requests.add')">
      <form class="space-y-4" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>
        <SearchableSelect v-model="form.staff_id" required :options="staffOptions" :label="t('admin.leaveManagement.requests.staff')" :error="errors.staff_id?.[0]" />
        <BaseSelect
          v-model="form.leave_type_id"
          required
          :options="activeTypeOptions"
          :placeholder="t('leaveRequest.pickType')"
          :label="t('admin.leaveManagement.policies.leaveType')"
          :error="errors.leave_type_id?.[0]"
        />
        <div class="grid grid-cols-2 gap-3">
          <BaseInput v-model="form.from_date" type="date" required :label="t('leaveRequest.fromDate')" :error="errors.from_date?.[0]" />
          <BaseInput v-model="form.to_date" type="date" :label="t('leaveRequest.toDate')" :hint="t('leaveRequest.toDateHint')" :error="errors.to_date?.[0]" />
        </div>
        <BaseSelect
          v-if="canHalfDay"
          :model-value="form.day_part"
          :options="dayPartOptions"
          :label="t('leaveRequest.dayPart')"
          :error="errors.day_part?.[0]"
          @update:model-value="form.day_part = $event as LeaveDayPart"
        />
        <BaseInput v-model="form.reason" required :label="t('leaveRequest.reason')" :error="errors.reason?.[0]" />
        <div>
          <label class="mb-1.5 block text-sm font-medium text-neutral-700">
            {{ t('leaveRequest.attachments') }} <span v-if="formType?.requires_attachment" class="text-danger-600">*</span>
          </label>
          <input
            type="file"
            multiple
            accept="image/jpeg,image/png,image/webp,application/pdf"
            class="block w-full text-sm text-neutral-600 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-800 hover:file:bg-primary-100"
            @change="onFiles"
          />
          <p v-if="attachments.length" class="mt-1 text-xs text-neutral-500">{{ attachments.map((f) => f.name).join(', ') }}</p>
          <p v-if="errors.attachments?.[0]" class="mt-1.5 text-sm text-danger-600">{{ errors.attachments[0] }}</p>
        </div>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('leaveRequest.submit') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
