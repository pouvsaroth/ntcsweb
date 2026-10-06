<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
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
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatDays, leavePoliciesService, leaveTypesService, type LeavePolicy, type LeaveType } from '@/services/leaveManagement'
import { organizationUnitsService, type OrganizationUnit } from '@/services/organizationUnits'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'

/**
 * HRM > Leave Management > Leave policies — how many days of each leave
 * type a staff member gets a year, for everyone or one job grade (a grade's
 * own policy wins), with eligibility, proration, long-service days and
 * carry forward. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canManage = computed(() => auth.can('leave-management.manage'))

const leaveTypes = ref<LeaveType[]>([])
const jobGrades = ref<OrganizationUnit[]>([])

const typeFilter = ref('')
const typeFilterOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.policies.allTypes') },
  ...leaveTypes.value.map((type) => ({ value: String(type.id), label: type.name })),
])

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<LeavePolicy>((query) =>
  leavePoliciesService.list({ ...query, filter: typeFilter.value ? { leave_type_id: typeFilter.value } : undefined }),
)

function onTypeFilter(value: string) {
  typeFilter.value = value
  void fetch()
}

function appliesTo(policy: LeavePolicy): string {
  return policy.job_grade ? policy.job_grade.name : t('admin.leaveManagement.policies.everyone')
}

/** "After 3 months · Prorated · +1 day every 3 years (max 21)" — the rules beyond the yearly days. */
function rules(policy: LeavePolicy): string {
  return [
    policy.min_service_months > 0 ? t('admin.leaveManagement.policies.afterMonths', { count: policy.min_service_months }) : null,
    policy.prorate_first_year ? t('admin.leaveManagement.policies.prorated') : null,
    policy.service_bonus_every_years && policy.service_bonus_days > 0
      ? t('admin.leaveManagement.policies.bonusLine', { days: formatDays(policy.service_bonus_days), years: policy.service_bonus_every_years }) +
        (policy.max_days_per_year != null ? ` (${t('admin.leaveManagement.policies.maxN', { days: formatDays(policy.max_days_per_year) })})` : '')
      : null,
    policy.max_consecutive_days ? t('admin.leaveManagement.policies.maxConsecutiveLine', { count: policy.max_consecutive_days }) : null,
    policy.min_notice_days > 0 ? t('admin.leaveManagement.policies.noticeLine', { count: policy.min_notice_days }) : null,
  ]
    .filter(Boolean)
    .join(' · ')
}

function carryForward(policy: LeavePolicy): string {
  if (policy.max_carry_forward_days <= 0) return t('admin.leaveManagement.policies.noCarryForward')
  const days = t('admin.leaveManagement.policies.upToDays', { days: formatDays(policy.max_carry_forward_days) })
  return policy.carry_forward_expiry_months
    ? `${days} · ${t('admin.leaveManagement.policies.expiresAfter', { count: policy.carry_forward_expiry_months })}`
    : days
}

const columns = computed(() => [
  { key: 'type', label: t('admin.leaveManagement.policies.leaveType') },
  { key: 'applies', label: t('admin.leaveManagement.policies.appliesTo') },
  { key: 'days', label: t('admin.leaveManagement.policies.daysPerYear'), align: 'text-right' },
  { key: 'rules', label: t('admin.leaveManagement.policies.rules') },
  { key: 'carry', label: t('admin.leaveManagement.policies.carryForward') },
  { key: 'is_active', label: t('admin.organization.status') },
  ...(canManage.value ? [{ key: 'actions', label: t('admin.organization.actions'), align: 'text-right' }] : []),
])

// --- Form ----------------------------------------------------------------------

const typeOptions = computed(() => leaveTypes.value.filter((type) => type.is_active || type.id === Number(form.leave_type_id)).map((type) => ({ value: String(type.id), label: type.name })))
const gradeOptions = computed(() => [
  { value: '', label: t('admin.leaveManagement.policies.everyone') },
  ...jobGrades.value.map((grade) => ({ value: String(grade.id), label: grade.name })),
])

const formOpen = ref(false)
const editing = ref<LeavePolicy | null>(null)
const form = reactive({
  leave_type_id: '',
  job_grade_id: '',
  name: '',
  days_per_year: '',
  min_service_months: '0',
  prorate_first_year: true,
  service_bonus_every_years: '',
  service_bonus_days: '0',
  max_days_per_year: '',
  max_carry_forward_days: '0',
  carry_forward_expiry_months: '',
  max_consecutive_days: '',
  min_notice_days: '0',
  description: '',
  is_active: true,
})
const errors = ref<Record<string, string[]>>({})
const saveError = ref<string | null>(null)
const saving = ref(false)
const actionError = ref<string | null>(null)

const optional = (value: number | null | undefined) => (value == null ? '' : String(value))

function open(policy: LeavePolicy | null) {
  editing.value = policy
  form.leave_type_id = policy ? String(policy.leave_type_id) : typeFilter.value
  form.job_grade_id = optional(policy?.job_grade_id)
  form.name = policy?.name ?? ''
  form.days_per_year = optional(policy?.days_per_year)
  form.min_service_months = String(policy?.min_service_months ?? 0)
  form.prorate_first_year = policy?.prorate_first_year ?? true
  form.service_bonus_every_years = optional(policy?.service_bonus_every_years)
  form.service_bonus_days = String(policy?.service_bonus_days ?? 0)
  form.max_days_per_year = optional(policy?.max_days_per_year)
  form.max_carry_forward_days = String(policy?.max_carry_forward_days ?? 0)
  form.carry_forward_expiry_months = optional(policy?.carry_forward_expiry_months)
  form.max_consecutive_days = optional(policy?.max_consecutive_days)
  form.min_notice_days = String(policy?.min_notice_days ?? 0)
  form.description = policy?.description ?? ''
  form.is_active = policy?.is_active ?? true
  errors.value = {}
  saveError.value = null
  formOpen.value = true
}

const numberOrNull = (value: string) => (value.trim() === '' ? null : Number(value))

async function save() {
  saving.value = true
  errors.value = {}
  saveError.value = null
  const input = {
    leave_type_id: Number(form.leave_type_id),
    job_grade_id: numberOrNull(form.job_grade_id),
    name: form.name,
    days_per_year: Number(form.days_per_year || 0),
    min_service_months: Number(form.min_service_months || 0),
    prorate_first_year: form.prorate_first_year,
    service_bonus_every_years: numberOrNull(form.service_bonus_every_years),
    service_bonus_days: Number(form.service_bonus_days || 0),
    max_days_per_year: numberOrNull(form.max_days_per_year),
    max_carry_forward_days: Number(form.max_carry_forward_days || 0),
    carry_forward_expiry_months: numberOrNull(form.carry_forward_expiry_months),
    max_consecutive_days: numberOrNull(form.max_consecutive_days),
    min_notice_days: Number(form.min_notice_days || 0),
    description: form.description.trim() || null,
    is_active: form.is_active,
  }
  try {
    if (editing.value) await leavePoliciesService.update(editing.value.id, input)
    else await leavePoliciesService.create(input)
    formOpen.value = false
    await fetch()
  } catch (e) {
    if (e instanceof ApiRequestError && e.errors) errors.value = e.errors
    else saveError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveManagement.saveFailed')
  } finally {
    saving.value = false
  }
}

async function remove(policy: LeavePolicy) {
  if (!(await confirmDialog.confirm({ message: t('admin.leaveManagement.policies.deleteConfirm', { name: policy.name }), danger: true }))) return
  actionError.value = null
  try {
    await leavePoliciesService.remove(policy.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.organization.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()
  ;[leaveTypes.value, jobGrades.value] = await Promise.all([
    leaveTypesService.listAll().catch(() => []),
    organizationUnitsService('job-grades').listAll().catch(() => []),
  ])
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <BaseSelect class="w-full sm:w-56" :model-value="typeFilter" :options="typeFilterOptions" @update:model-value="onTypeFilter" />
      <p class="hidden text-sm text-neutral-500 lg:block">{{ t('admin.leaveManagement.policies.hint') }}</p>
      <BaseButton v-if="canManage" class="ml-auto" :disabled="leaveTypes.length === 0" @click="open(null)">{{ t('admin.leaveManagement.policies.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.leaveManagement.policies.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="flex items-center gap-2 text-sm font-semibold text-neutral-800">
                <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type?.color ?? '#9ca3af' }" />
                {{ row.leave_type?.name }}
              </p>
              <p class="text-xs text-neutral-500">{{ row.name }} · {{ appliesTo(row) }}</p>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-lg font-semibold tabular-nums text-neutral-900">{{ formatDays(row.days_per_year) }}</p>
              <p class="text-xs text-neutral-500">{{ t('admin.leaveManagement.policies.perYear') }}</p>
            </div>
          </div>
          <p v-if="rules(row)" class="mt-2 text-xs text-neutral-600">{{ rules(row) }}</p>
          <p class="text-xs text-neutral-600">{{ t('admin.leaveManagement.policies.carryForward') }}: {{ carryForward(row) }}</p>
          <div class="mt-2 flex items-center justify-between gap-3">
            <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
            <div v-if="canManage" class="flex gap-3">
              <EditIconButton @click="open(row)" />
              <button type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.organization.delete') }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.leaveManagement.policies.empty')">
        <template #cell-type="{ row }">
          <p class="flex items-center gap-2 font-medium text-neutral-800">
            <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type?.color ?? '#9ca3af' }" />
            {{ row.leave_type?.name }}
          </p>
          <p class="text-xs text-neutral-500">{{ row.name }}</p>
        </template>
        <template #cell-applies="{ row }">{{ appliesTo(row as LeavePolicy) }}</template>
        <template #cell-days="{ row }"><span class="font-semibold tabular-nums">{{ formatDays(row.days_per_year) }}</span></template>
        <template #cell-rules="{ row }"><span class="text-xs text-neutral-600">{{ rules(row as LeavePolicy) || '—' }}</span></template>
        <template #cell-carry="{ row }"><span class="text-xs text-neutral-600">{{ carryForward(row as LeavePolicy) }}</span></template>
        <template #cell-is_active="{ row }">
          <BaseBadge :variant="row.is_active ? 'success' : 'neutral'">{{ row.is_active ? t('admin.organization.statusActive') : t('admin.organization.statusInactive') }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton @click="open(row as LeavePolicy)" />
            <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row as LeavePolicy)">{{ t('admin.organization.delete') }}</button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal v-model="formOpen" size="lg" :title="editing ? t('admin.leaveManagement.policies.edit') : t('admin.leaveManagement.policies.add')">
      <form class="space-y-5" @submit.prevent="save">
        <BaseAlert v-if="saveError" variant="danger">{{ saveError }}</BaseAlert>

        <div class="grid gap-4 sm:grid-cols-2">
          <BaseSelect
            v-model="form.leave_type_id"
            required
            :options="typeOptions"
            :placeholder="t('admin.leaveManagement.policies.pickType')"
            :label="t('admin.leaveManagement.policies.leaveType')"
            :error="errors.leave_type_id?.[0]"
          />
          <BaseSelect
            v-model="form.job_grade_id"
            :options="gradeOptions"
            :label="t('admin.leaveManagement.policies.appliesTo')"
            :hint="t('admin.leaveManagement.policies.appliesToHint')"
            :error="errors.job_grade_id?.[0]"
          />
          <BaseInput v-model="form.name" required :label="t('admin.leaveManagement.policies.name')" :error="errors.name?.[0]" />
          <BaseInput
            v-model="form.days_per_year"
            type="number"
            min="0"
            step="0.5"
            required
            :label="t('admin.leaveManagement.policies.daysPerYear')"
            :error="errors.days_per_year?.[0]"
          />
        </div>

        <fieldset class="space-y-4 rounded-lg border border-neutral-200 p-4">
          <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('admin.leaveManagement.policies.eligibility') }}</legend>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput
              v-model="form.min_service_months"
              type="number"
              min="0"
              :label="t('admin.leaveManagement.policies.minServiceMonths')"
              :hint="t('admin.leaveManagement.policies.minServiceMonthsHint')"
              :error="errors.min_service_months?.[0]"
            />
            <label class="flex items-center gap-2 text-sm text-neutral-700 sm:mt-7">
              <input v-model="form.prorate_first_year" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
              {{ t('admin.leaveManagement.policies.prorateFirstYear') }}
            </label>
            <BaseInput
              v-model="form.service_bonus_days"
              type="number"
              min="0"
              step="0.5"
              :label="t('admin.leaveManagement.policies.bonusDays')"
              :error="errors.service_bonus_days?.[0]"
            />
            <BaseInput
              v-model="form.service_bonus_every_years"
              type="number"
              min="1"
              :label="t('admin.leaveManagement.policies.bonusEveryYears')"
              :hint="t('admin.leaveManagement.policies.bonusHint')"
              :error="errors.service_bonus_every_years?.[0]"
            />
            <BaseInput
              v-model="form.max_days_per_year"
              type="number"
              min="0"
              step="0.5"
              :label="t('admin.leaveManagement.policies.maxDaysPerYear')"
              :hint="t('admin.leaveManagement.policies.maxDaysPerYearHint')"
              :error="errors.max_days_per_year?.[0]"
            />
          </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-neutral-200 p-4">
          <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('admin.leaveManagement.policies.requestLimits') }}</legend>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput
              v-model="form.max_consecutive_days"
              type="number"
              min="1"
              :label="t('admin.leaveManagement.policies.maxConsecutive')"
              :hint="t('admin.leaveManagement.policies.noLimitHint')"
              :error="errors.max_consecutive_days?.[0]"
            />
            <BaseInput
              v-model="form.min_notice_days"
              type="number"
              min="0"
              :label="t('admin.leaveManagement.policies.minNotice')"
              :error="errors.min_notice_days?.[0]"
            />
          </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-neutral-200 p-4">
          <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('admin.leaveManagement.policies.carryForward') }}</legend>
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput
              v-model="form.max_carry_forward_days"
              type="number"
              min="0"
              step="0.5"
              :label="t('admin.leaveManagement.policies.maxCarryForward')"
              :hint="t('admin.leaveManagement.policies.maxCarryForwardHint')"
              :error="errors.max_carry_forward_days?.[0]"
            />
            <BaseInput
              v-model="form.carry_forward_expiry_months"
              type="number"
              min="1"
              max="12"
              :label="t('admin.leaveManagement.policies.carryForwardExpiry')"
              :hint="t('admin.leaveManagement.policies.carryForwardExpiryHint')"
              :error="errors.carry_forward_expiry_months?.[0]"
            />
          </div>
        </fieldset>

        <BaseInput v-model="form.description" :label="t('admin.organization.description')" />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          {{ t('admin.organization.statusActive') }}
        </label>
      </form>
      <template #footer>
        <BaseButton variant="outline" @click="formOpen = false">{{ t('common.close') }}</BaseButton>
        <BaseButton :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
      </template>
    </BaseModal>
  </div>
</template>
