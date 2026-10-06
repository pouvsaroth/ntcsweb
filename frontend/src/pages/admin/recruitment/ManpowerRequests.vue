<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import ManpowerRequestFormModal from '@/components/admin/ManpowerRequestFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { manpowerRequestsService, type ManpowerRequest, type ManpowerRequestStatus } from '@/services/manpowerRequests'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Recruitment > Manpower request: requests to hire. A new one goes
 * straight into E-Approvals > Approvals; it can be edited while it waits,
 * and deleted unless approved. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()
const router = useRouter()

const canCreate = computed(() => auth.can('recruitment.create'))
const canUpdate = computed(() => auth.can('recruitment.update'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<ManpowerRequest>((query) => manpowerRequestsService.list(query))

const statusFilter = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  { value: 'pending', label: t('admin.myRequests.statusPending') },
  { value: 'approved', label: t('admin.myRequests.statusApproved') },
  { value: 'rejected', label: t('admin.myRequests.statusRejected') },
])

function onStatusChange(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const statusVariant: Record<ManpowerRequestStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const statusLabelKey: Record<ManpowerRequestStatus, string> = {
  pending: 'admin.myRequests.statusPending',
  approved: 'admin.myRequests.statusApproved',
  rejected: 'admin.myRequests.statusRejected',
}

const columns = computed(() => [
  { key: 'reference', label: t('admin.recruitment.manpower.reference') },
  { key: 'job_title', label: t('admin.recruitment.manpower.jobTitle') },
  { key: 'department', label: t('admin.recruitment.manpower.department') },
  { key: 'headcount', label: t('admin.recruitment.manpower.headcount') },
  { key: 'needed_by', label: t('admin.recruitment.manpower.neededBy') },
  { key: 'requested_by', label: t('admin.recruitment.manpower.requestedBy') },
  { key: 'status', label: t('admin.recruitment.manpower.status') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

function mayEdit(row: ManpowerRequest): boolean {
  return canUpdate.value && row.status === 'pending'
}

function mayDelete(row: ManpowerRequest): boolean {
  return canDelete.value && row.status !== 'approved'
}

/** An approved request with no job opened for it yet. */
function mayOpenJob(row: ManpowerRequest): boolean {
  return canCreate.value && row.status === 'approved' && (row.job_positions_count ?? 0) === 0
}

function openJob(row: ManpowerRequest) {
  void router.push({ path: '/admin/recruitment/job-positions', query: { from: row.id } })
}

const modalOpen = ref(false)
const editing = ref<ManpowerRequest | null>(null)
const actionError = ref<string | null>(null)

function openCreate() {
  editing.value = null
  modalOpen.value = true
}

function openEdit(row: ManpowerRequest) {
  editing.value = row
  modalOpen.value = true
}

async function remove(row: ManpowerRequest) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.manpower.deleteConfirm', { reference: row.reference }), danger: true }))) return
  actionError.value = null
  try {
    await manpowerRequestsService.remove(row.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
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
      <BaseSelect class="w-44" :model-value="statusFilter" :options="statusOptions" @update:model-value="onStatusChange" />
      <BaseButton v-if="canCreate" class="ml-auto" @click="openCreate">{{ t('admin.recruitment.manpower.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.manpower.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.job_title }} × {{ row.headcount }}</p>
              <p class="text-xs text-neutral-500">{{ row.reference }} · {{ row.department ?? '—' }}</p>
            </div>
            <BaseBadge :variant="statusVariant[row.status]" class="shrink-0">{{ t(statusLabelKey[row.status]) }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-500">
            {{ t('admin.recruitment.manpower.neededBy') }}: {{ formatDate(row.needed_by) }} · {{ t(`admin.recruitment.employmentTypes.${row.employment_type}`) }}
          </p>
          <p v-if="row.decision_reason" class="mt-1 text-xs text-danger-600">{{ row.decision_reason }}</p>
          <p v-if="row.status === 'approved' && (row.job_positions_count ?? 0) > 0" class="mt-1 text-xs font-medium text-success-600">{{ t('admin.recruitment.manpower.jobOpened') }}</p>
          <div v-if="mayEdit(row) || mayDelete(row) || mayOpenJob(row)" class="mt-2 flex items-center justify-end gap-3">
            <BaseButton v-if="mayOpenJob(row)" size="sm" @click="openJob(row)">{{ t('admin.recruitment.manpower.openJob') }}</BaseButton>
            <EditIconButton v-if="mayEdit(row)" @click="openEdit(row)" />
            <button v-if="mayDelete(row)" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.manpower.empty')">
        <template #cell-job_title="{ row }">
          <p class="font-medium text-neutral-800">{{ row.job_title }}</p>
          <p class="text-xs text-neutral-500">{{ t(`admin.recruitment.employmentTypes.${row.employment_type}`) }}</p>
        </template>
        <template #cell-department="{ row }">{{ row.department ?? '—' }}</template>
        <template #cell-needed_by="{ row }">{{ formatDate(row.needed_by) }}</template>
        <template #cell-requested_by="{ row }">{{ row.requested_by ?? '—' }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status as ManpowerRequestStatus]">{{ t(statusLabelKey[row.status as ManpowerRequestStatus]) }}</BaseBadge>
          <p v-if="row.decision_reason" class="mt-1 max-w-48 text-xs text-danger-600">{{ row.decision_reason }}</p>
          <p v-if="row.status === 'approved' && (row.job_positions_count ?? 0) > 0" class="mt-1 text-xs font-medium text-success-600">{{ t('admin.recruitment.manpower.jobOpened') }}</p>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-2">
            <BaseButton v-if="mayOpenJob(row)" size="sm" @click="openJob(row)">{{ t('admin.recruitment.manpower.openJob') }}</BaseButton>
            <EditIconButton v-if="mayEdit(row)" @click="openEdit(row)" />
            <button v-if="mayDelete(row)" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <ManpowerRequestFormModal v-model="modalOpen" :request="editing" @saved="fetch" />
  </div>
</template>
