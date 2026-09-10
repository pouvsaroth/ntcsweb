<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EnrollmentEditModal from '@/components/admin/EnrollmentEditModal.vue'
import EnrollmentStatusHistoryModal from '@/components/admin/EnrollmentStatusHistoryModal.vue'
import EnrollmentStatusModal from '@/components/admin/EnrollmentStatusModal.vue'
import EnrollmentTransferModal from '@/components/admin/EnrollmentTransferModal.vue'
import ActionIconButton from '@/components/ui/ActionIconButton.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { enrollmentsService, type Enrollment, type EnrollmentStatus } from '@/services/enrollments'
import { type LookupOption, lookupsService } from '@/services/lookups'

const { t, locale } = useI18n()

const { items, meta, loading, error, sort, setPage, setSort, setFilter, fetch } = usePaginatedResource<Enrollment>((query) =>
  enrollmentsService.list(query),
)

/**
 * Options come straight from the ENROLLMENT_STATUS base-data category
 * (BaseDataSeeder) rather than a hardcoded array — codes match
 * EnrollmentStatus exactly (see the seeder's own note on why `dropped` is
 * excluded). Labels are the lookup's own translated `name`, not a second,
 * separately-maintained i18n string — only the row's status *badge* still
 * uses the app's own i18n keys, since that's a display concern unrelated to
 * this filter.
 */
const statusLookup = ref<LookupOption[]>([])
const selectedStatus = ref('')

const statusFilterOptions = computed(() => [
  { value: '', label: t('admin.enrollments.filterAllStatuses') },
  ...statusLookup.value.map((option) => ({ value: option.code, label: option.name })),
])

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const columns = [
  { key: 'code', label: t('admin.enrollments.columnCode') },
  { key: 'student', label: t('admin.enrollments.columnStudent') },
  { key: 'class', label: t('admin.enrollments.columnClass'), sortable: true, sortKey: 'class_id' },
  { key: 'book', label: t('admin.enrollments.columnBook') },
  { key: 'table', label: t('admin.enrollments.columnTable'), sortable: true, sortKey: 'table_id' },
  { key: 'enrolled_at', label: t('admin.enrollments.columnEnrolledAt'), sortable: true },
  { key: 'status', label: t('admin.enrollments.columnStatus'), sortable: true },
  { key: 'actions', label: t('admin.enrollments.columnActions'), align: 'text-right' },
]

function statusKey(status: EnrollmentStatus): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

const statusBadgeVariant: Record<EnrollmentStatus, 'success' | 'neutral' | 'danger' | 'warning' | 'primary'> = {
  not_started: 'neutral',
  active: 'success',
  exam_ready: 'primary',
  completed: 'neutral',
  abandoned: 'danger',
  stopped: 'danger',
  suspended: 'warning',
  dropped: 'neutral',
}

const editModalOpen = ref(false)
const statusModalOpen = ref(false)
const historyModalOpen = ref(false)
const transferModalOpen = ref(false)
const activeEnrollment = ref<Enrollment | null>(null)

function openEdit(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  editModalOpen.value = true
}

function openStatus(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  statusModalOpen.value = true
}

function openHistory(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  historyModalOpen.value = true
}

function openTransfer(enrollment: Enrollment) {
  activeEnrollment.value = enrollment
  transferModalOpen.value = true
}

async function remove(enrollment: Enrollment) {
  if (!window.confirm(t('admin.enrollments.deleteConfirm'))) return
  await enrollmentsService.remove(enrollment.id)
  await fetch()
}

onMounted(() => {
  void fetch()
  void lookupsService.values('ENROLLMENT_STATUS', locale.value).then((result) => (statusLookup.value = result))
})
</script>

<template>
  <div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.enrollments.title') }}</h1>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <BaseSelect
          :model-value="selectedStatus"
          :options="statusFilterOptions"
          class="w-48"
          @update:model-value="onStatusFilterChange"
        />
        <BaseButton to="/admin/enrollments/new">{{ t('admin.enrollments.createPackageTitle') }}</BaseButton>
      </div>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable
      :columns="columns"
      :rows="items"
      row-key="id"
      :loading="loading"
      :sort="sort"
      :empty-message="t('admin.enrollments.emptyMessage')"
      @sort="(col) => setSort(sort === col ? `-${col}` : col)"
    >
      <template #cell-code="{ row }">{{ row.enrollments_code ?? '—' }}</template>
      <template #cell-student="{ row }">
        <p class="font-medium text-neutral-800">{{ row.student.full_name }}</p>
        <p class="text-xs text-neutral-500">{{ row.student.student_code }}</p>
      </template>
      <template #cell-class="{ row }">{{ row.class.name }}</template>
      <template #cell-book="{ row }">{{ row.course_package?.name ?? '—' }}</template>
      <template #cell-table="{ row }">{{ row.table?.name ?? '—' }}</template>
      <template #cell-enrolled_at="{ row }">{{ new Date(row.enrolled_at).toLocaleDateString() }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.status]">
          {{ t(`admin.enrollments.status${statusKey(row.status)}`) }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap items-center justify-end gap-x-1 gap-y-1">
          <EditIconButton @click="openEdit(row)" />
          <ActionIconButton :title="t('admin.enrollments.statusHistory')" @click="openHistory(row)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </ActionIconButton>
          <template v-if="row.status !== 'dropped'">
            <ActionIconButton :title="t('admin.enrollments.changeStatus')" @click="openStatus(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"
                />
              </svg>
            </ActionIconButton>
            <ActionIconButton v-if="row.status === 'active'" :title="t('admin.enrollments.changeClass')" @click="openTransfer(row)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
              </svg>
            </ActionIconButton>
          </template>
          <ActionIconButton variant="danger" :title="t('admin.enrollments.delete')" @click="remove(row)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
              />
            </svg>
          </ActionIconButton>
        </div>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <EnrollmentEditModal v-model="editModalOpen" :enrollment="activeEnrollment" @saved="fetch" />
    <EnrollmentStatusModal v-model="statusModalOpen" :enrollment="activeEnrollment" @saved="fetch" />
    <EnrollmentStatusHistoryModal v-model="historyModalOpen" :enrollment="activeEnrollment" />
    <EnrollmentTransferModal v-model="transferModalOpen" :enrollment="activeEnrollment" @saved="fetch" />
  </div>
</template>
