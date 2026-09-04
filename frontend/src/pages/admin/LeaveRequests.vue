<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { usePaginatedResource } from '@/composables/usePaginatedResource'
import { leaveRequestsService, type LeaveRequest, type LeaveRequestStatus } from '@/services/leaveRequests'
import { ApiRequestError } from '@/types/api'

const { t } = useI18n()

const { items, meta, loading, error, setPage, setFilter, fetch } = usePaginatedResource<LeaveRequest>((query) =>
  leaveRequestsService.list(query),
)

const selectedStatus = ref('')
const statusFilterOptions = [
  { value: 'pending', label: t('admin.leaveRequests.statusPending') },
  { value: 'approved', label: t('admin.leaveRequests.statusApproved') },
  { value: 'rejected', label: t('admin.leaveRequests.statusRejected') },
]

function onStatusFilterChange(value: string) {
  selectedStatus.value = value
  setFilter('status', value || undefined)
}

const statusVariant: Record<LeaveRequestStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const columns = [
  { key: 'student', label: t('admin.leaveRequests.columnStudent') },
  { key: 'dates', label: t('admin.leaveRequests.columnDates') },
  { key: 'reason', label: t('admin.leaveRequests.columnReason') },
  { key: 'status', label: t('admin.leaveRequests.columnStatus') },
  { key: 'created_at', label: t('admin.leaveRequests.columnSubmitted') },
  { key: 'actions', label: t('admin.leaveRequests.columnActions'), align: 'text-right' },
]

const detail = ref<LeaveRequest | null>(null)
const actionError = ref<string | null>(null)
const approving = ref(false)

const rejectTarget = ref<LeaveRequest | null>(null)
const rejectOpen = ref(false)
const rejectSubmitting = ref(false)
const rejectError = ref<string | null>(null)

function openDetail(row: LeaveRequest) {
  detail.value = row
  actionError.value = null
}

function openReject(row: LeaveRequest) {
  rejectTarget.value = row
  rejectError.value = null
  rejectOpen.value = true
}

async function approve(row: LeaveRequest) {
  if (!window.confirm(t('admin.leaveRequests.approveConfirm', { name: row.student?.name ?? '' }))) return

  approving.value = true
  actionError.value = null

  try {
    await leaveRequestsService.approve(row.id)
    detail.value = null
    await fetch()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveRequests.actionFailed')
  } finally {
    approving.value = false
  }
}

async function confirmReject(reason: string) {
  if (!rejectTarget.value) return

  rejectSubmitting.value = true
  rejectError.value = null

  try {
    await leaveRequestsService.reject(rejectTarget.value.id, reason)
    rejectOpen.value = false
    detail.value = null
    await fetch()
  } catch (e) {
    rejectError.value = e instanceof ApiRequestError ? e.message : t('admin.leaveRequests.actionFailed')
  } finally {
    rejectSubmitting.value = false
  }
}

onMounted(() => fetch())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.leaveRequests.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.leaveRequests.pageSubtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <BaseSelect
        :model-value="selectedStatus"
        :options="statusFilterOptions"
        :placeholder="t('admin.leaveRequests.filterAllStatuses')"
        @update:model-value="onStatusFilterChange"
      />
    </div>

    <EmptyState
      v-if="!loading && items.length === 0"
      :title="t('admin.leaveRequests.emptyTitle')"
      :message="t('admin.leaveRequests.emptyMessage')"
    />

    <DataTable v-else :columns="columns" :rows="items" row-key="id" :loading="loading" :empty-message="t('admin.leaveRequests.emptyMessage')">
      <template #cell-student="{ row }">
        <button type="button" class="font-medium text-primary-700 hover:underline" @click="openDetail(row)">{{ row.student?.name }}</button>
        <p class="text-xs text-neutral-500">{{ row.student?.student_code }}</p>
      </template>
      <template #cell-dates="{ row }">
        <p class="text-neutral-800">{{ row.from_date }} – {{ row.to_date }}</p>
        <p v-if="row.from_time" class="text-xs text-neutral-500">{{ row.from_time }} – {{ row.to_time }}</p>
      </template>
      <template #cell-reason="{ row }">
        <p class="max-w-xs truncate text-neutral-700">{{ row.reason }}</p>
      </template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.leaveRequests.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}</BaseBadge>
      </template>
      <template #cell-created_at="{ row }">{{ new Date(row.created_at).toLocaleDateString() }}</template>
      <template #cell-actions="{ row }">
        <div v-if="row.status === 'pending'" class="flex justify-end gap-2">
          <BaseButton size="sm" :loading="approving" @click="approve(row)">{{ t('admin.leaveRequests.approve') }}</BaseButton>
          <BaseButton size="sm" variant="danger" @click="openReject(row)">{{ t('admin.leaveRequests.reject') }}</BaseButton>
        </div>
        <span v-else class="text-xs text-neutral-400">—</span>
      </template>
    </DataTable>

    <BasePagination v-if="meta" :meta="meta" sticky class="mt-4" @update:page="setPage" />

    <BaseModal :model-value="detail !== null" :title="detail?.student?.name" size="lg" @update:model-value="detail = null">
      <template v-if="detail">
        <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnDates') }}</dt><dd class="font-medium text-neutral-900">{{ detail.from_date }} – {{ detail.to_date }}</dd></div>
          <div v-if="detail.from_time"><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnTime') }}</dt><dd class="font-medium text-neutral-900">{{ detail.from_time }} – {{ detail.to_time }}</dd></div>
          <div class="col-span-2"><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.reason }}</dd></div>
          <div v-if="detail.decision_reason" class="col-span-2"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.decision_reason }}</dd></div>
        </dl>

        <div v-if="detail.attachments.length" class="mt-4">
          <p class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('admin.leaveRequests.attachments') }}</p>
          <ul class="space-y-1">
            <li v-for="attachment in detail.attachments" :key="attachment.id">
              <a :href="attachment.url" target="_blank" rel="noopener" class="text-sm text-primary-700 hover:underline">{{ attachment.file_name }}</a>
            </li>
          </ul>
        </div>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
        <template v-if="detail?.status === 'pending'">
          <BaseButton variant="danger" @click="openReject(detail)">{{ t('admin.leaveRequests.reject') }}</BaseButton>
          <BaseButton :loading="approving" @click="approve(detail)">{{ t('admin.leaveRequests.approve') }}</BaseButton>
        </template>
      </template>
    </BaseModal>

    <ConfirmReasonModal
      v-model="rejectOpen"
      :title="t('admin.leaveRequests.reject')"
      :label="t('admin.leaveRequests.reasonLabel')"
      :confirm-label="t('admin.leaveRequests.reject')"
      danger
      :submitting="rejectSubmitting"
      :error="rejectError"
      @confirm="confirmReject"
    />
  </div>
</template>
