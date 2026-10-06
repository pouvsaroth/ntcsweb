<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import JobPositionFormModal from '@/components/admin/JobPositionFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { formatSalary, JOB_POSITION_STATUSES, jobPositionsService, type JobPosition, type JobPositionStatus } from '@/services/jobPositions'
import { manpowerRequestsService, type ManpowerRequest } from '@/services/manpowerRequests'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * HRM > Recruitment > Job positions — the school's vacancies. `?from=<id>`
 * (the Manpower request tab's "Open job") opens the form pre-filled from
 * that approved request. Cards on a phone, a table from `sm` up.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const canCreate = computed(() => auth.can('recruitment.create'))
const canUpdate = computed(() => auth.can('recruitment.update'))
const canDelete = computed(() => auth.can('recruitment.delete'))

const { items, meta, loading, error, setPage, setSearch, setFilter, fetch } = usePaginatedResource<JobPosition>((query) => jobPositionsService.list(query))

const statusFilter = ref('')
const statusOptions = computed(() => [
  { value: '', label: t('admin.recruitment.manpower.allStatuses') },
  ...JOB_POSITION_STATUSES.map((status) => ({ value: status, label: t(`admin.recruitment.jobStatuses.${status}`) })),
])

function onStatusChange(value: string) {
  statusFilter.value = value
  setFilter('status', value || undefined)
}

const statusVariant: Record<JobPositionStatus, 'success' | 'warning' | 'primary' | 'neutral'> = {
  open: 'success',
  on_hold: 'warning',
  filled: 'primary',
  closed: 'neutral',
}

const columns = computed(() => [
  { key: 'reference', label: t('admin.recruitment.manpower.reference') },
  { key: 'title', label: t('admin.recruitment.positions.title') },
  { key: 'department', label: t('admin.recruitment.manpower.department') },
  { key: 'headcount', label: t('admin.recruitment.manpower.headcount') },
  { key: 'salary', label: t('admin.recruitment.positions.salary') },
  { key: 'closes_on', label: t('admin.recruitment.positions.closesOn') },
  { key: 'postings', label: t('admin.recruitment.tabs.jobPostings') },
  { key: 'status', label: t('admin.recruitment.positions.status') },
  { key: 'actions', label: t('admin.recruitment.manpower.actions'), align: 'text-right' },
])

const modalOpen = ref(false)
const editing = ref<JobPosition | null>(null)
const fromRequest = ref<ManpowerRequest | null>(null)
const actionError = ref<string | null>(null)

function openCreate() {
  editing.value = null
  fromRequest.value = null
  modalOpen.value = true
}

function openEdit(job: JobPosition) {
  editing.value = job
  fromRequest.value = null
  modalOpen.value = true
}

async function remove(job: JobPosition) {
  if (!(await confirmDialog.confirm({ message: t('admin.recruitment.positions.deleteConfirm', { reference: job.reference }), danger: true }))) return
  actionError.value = null
  try {
    await jobPositionsService.remove(job.id)
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.recruitment.manpower.deleteFailed')
  }
}

onMounted(async () => {
  void fetch()

  const fromId = Number(route.query.from)
  if (Number.isInteger(fromId) && fromId > 0 && canCreate.value) {
    void router.replace({ query: {} })
    try {
      fromRequest.value = await manpowerRequestsService.get(fromId)
      editing.value = null
      modalOpen.value = true
    } catch {
      actionError.value = t('admin.recruitment.positions.requestNotFound')
    }
  }
})
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
      <BaseButton v-if="canCreate" class="ml-auto" @click="openCreate">{{ t('admin.recruitment.positions.add') }}</BaseButton>
    </div>

    <BaseAlert v-if="error || actionError" variant="danger" class="mb-4">{{ error || actionError }}</BaseAlert>

    <!-- Cards on a phone (below sm) -->
    <div class="sm:hidden">
      <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>
      <p v-else-if="items.length === 0" class="rounded-[--radius-card] border border-dashed border-neutral-300 py-10 text-center text-sm text-neutral-500">
        {{ t('admin.recruitment.positions.empty') }}
      </p>
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-[--radius-card] border border-neutral-200 bg-white p-3 shadow-[--shadow-card]">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-neutral-800">{{ row.title }} × {{ row.headcount }}</p>
              <p class="text-xs text-neutral-500">{{ row.reference }} · {{ row.department ?? '—' }}</p>
            </div>
            <BaseBadge :variant="statusVariant[row.status]" class="shrink-0">{{ t(`admin.recruitment.jobStatuses.${row.status}`) }}</BaseBadge>
          </div>
          <p class="mt-2 text-xs text-neutral-600">{{ formatSalary(row) }} · {{ t(`admin.recruitment.employmentTypes.${row.employment_type}`) }}</p>
          <p class="text-xs text-neutral-500">
            {{ t('admin.recruitment.positions.closesOn') }}: {{ formatDate(row.closes_on) }} ·
            {{ t('admin.recruitment.positions.postingsCount', { count: row.postings_count ?? 0 }) }}
            <span v-if="row.on_careers_page" class="font-medium text-success-600"> · {{ t('admin.recruitment.positions.onCareersPage') }}</span>
          </p>
          <div v-if="canUpdate || canDelete" class="mt-2 flex justify-end gap-3">
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600" @click="remove(row)">{{ t('admin.recruitment.manpower.delete') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div class="hidden sm:block">
      <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.recruitment.positions.empty')">
        <template #cell-reference="{ row }">
          <p>{{ row.reference }}</p>
          <p v-if="row.manpower_request" class="text-xs text-neutral-500">{{ row.manpower_request }}</p>
        </template>
        <template #cell-title="{ row }">
          <p class="font-medium text-neutral-800">{{ row.title }}</p>
          <p class="text-xs text-neutral-500">{{ t(`admin.recruitment.employmentTypes.${row.employment_type}`) }}<template v-if="row.branch"> · {{ row.branch }}</template></p>
        </template>
        <template #cell-department="{ row }">{{ row.department ?? '—' }}</template>
        <template #cell-salary="{ row }">{{ formatSalary(row) }}</template>
        <template #cell-closes_on="{ row }">{{ formatDate(row.closes_on) }}</template>
        <template #cell-postings="{ row }">
          <p>{{ row.postings_count ?? 0 }}</p>
          <p v-if="row.on_careers_page" class="text-xs font-medium text-success-600">{{ t('admin.recruitment.positions.onCareersPage') }}</p>
        </template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status as JobPositionStatus]">{{ t(`admin.recruitment.jobStatuses.${row.status}`) }}</BaseBadge>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <EditIconButton v-if="canUpdate" @click="openEdit(row)" />
            <button v-if="canDelete" type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
              {{ t('admin.recruitment.manpower.delete') }}
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <JobPositionFormModal v-model="modalOpen" :job="editing" :from-request="fromRequest" @saved="fetch" />
  </div>
</template>
