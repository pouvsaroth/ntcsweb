<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import EnrollmentEditModal from '@/components/admin/EnrollmentEditModal.vue'
import EnrollmentStatusHistoryModal from '@/components/admin/EnrollmentStatusHistoryModal.vue'
import EnrollmentStatusModal from '@/components/admin/EnrollmentStatusModal.vue'
import EnrollmentTransferModal from '@/components/admin/EnrollmentTransferModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import EditIconButton from '@/components/ui/EditIconButton.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import DataTable from '@/components/ui/DataTable.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { enrollmentsService, type Enrollment, type EnrollmentStatus } from '@/services/enrollments'

const { t } = useI18n()

const { items, meta, loading, error, setPage, fetch } = usePaginatedResource<Enrollment>((query) =>
  enrollmentsService.list(query),
)

const columns = [
  { key: 'student', label: t('admin.enrollments.columnStudent') },
  { key: 'class', label: t('admin.enrollments.columnClass') },
  { key: 'book', label: t('admin.enrollments.columnBook') },
  { key: 'table', label: t('admin.enrollments.columnTable') },
  { key: 'fee', label: t('admin.enrollments.columnFee') },
  { key: 'status', label: t('admin.enrollments.columnStatus') },
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

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.enrollments.title') }}</h1>
      </div>
      <BaseButton to="/admin/enrollments/new">{{ t('admin.enrollments.createPackageTitle') }}</BaseButton>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <DataTable :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.enrollments.emptyMessage')">
      <template #cell-student="{ row }">
        <p class="font-medium text-neutral-800">{{ row.student.full_name }}</p>
        <p class="text-xs text-neutral-500">{{ row.student.student_code }}</p>
      </template>
      <template #cell-class="{ row }">{{ row.class.name }}</template>
      <template #cell-book="{ row }">{{ row.book?.title ?? row.course_package?.name ?? '—' }}</template>
      <template #cell-table="{ row }">{{ row.table?.name ?? '—' }}</template>
      <template #cell-fee="{ row }">{{ row.fee.toFixed(2) }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusBadgeVariant[row.status]">
          {{ t(`admin.enrollments.status${statusKey(row.status)}`) }}
        </BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap justify-end gap-x-3 gap-y-1">
          <EditIconButton @click="openEdit(row)" />
          <button type="button" class="text-sm font-medium text-neutral-600 hover:text-neutral-800" @click="openHistory(row)">
            {{ t('admin.enrollments.statusHistory') }}
          </button>
          <template v-if="row.status !== 'dropped'">
            <button type="button" class="text-sm font-medium text-neutral-600 hover:text-neutral-800" @click="openStatus(row)">
              {{ t('admin.enrollments.changeStatus') }}
            </button>
            <button
              v-if="row.status === 'active'"
              type="button"
              class="text-sm font-medium text-neutral-600 hover:text-neutral-800"
              @click="openTransfer(row)"
            >
              {{ t('admin.enrollments.changeClass') }}
            </button>
          </template>
          <button type="button" class="text-sm font-medium text-danger-600 hover:text-red-700" @click="remove(row)">
            {{ t('admin.enrollments.delete') }}
          </button>
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
