<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { approvalRequestsService, type ApprovalRequest, type ApprovalRequestStatus } from '@/services/approvalRequests'
import { leaveRequestsService, type LeaveRequest } from '@/services/leaveRequests'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * The approval queue — every pending/decided item across both the generic
 * ApprovalRequest catalog and the dedicated LeaveRequest flow, merged into
 * one table. See MyRequests.vue's docblock for why merging is done
 * client-side rather than through usePaginatedResource. Each source is only
 * fetched if the current user actually holds its view permission, same
 * gating the sidebar nav already applies.
 */
type MergedRow = {
  kind: 'approval' | 'leave'
  id: number
  reference: string
  requestor: string
  subject: string
  status: ApprovalRequestStatus
  createdAt: string
  approval?: ApprovalRequest
  leave?: LeaveRequest
}

const { t } = useI18n()
const auth = useAuthStore()

const rows = ref<MergedRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)
const approving = ref(false)
const activeTab = ref<ApprovalRequestStatus>('pending')

const canViewApprovals = computed(() => auth.can('approval-requests.view'))
const canViewLeave = computed(() => auth.can('leave-requests.view'))

const tabs: { key: ApprovalRequestStatus; labelKey: string }[] = [
  { key: 'pending', labelKey: 'admin.approvals.tabNew' },
  { key: 'approved', labelKey: 'admin.approvals.tabApproved' },
  { key: 'rejected', labelKey: 'admin.approvals.tabRejected' },
]

const counts = computed(() => ({
  pending: rows.value.filter((r) => r.status === 'pending').length,
  approved: rows.value.filter((r) => r.status === 'approved').length,
  rejected: rows.value.filter((r) => r.status === 'rejected').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

const columns = [
  { key: 'date', label: t('admin.approvals.columnDate') },
  { key: 'requestor', label: t('admin.approvals.columnRequestor') },
  { key: 'subject', label: t('admin.approvals.columnSubject') },
  { key: 'reference', label: t('admin.approvals.columnReference') },
  { key: 'status', label: t('admin.approvals.columnStatus') },
  { key: 'actions', label: t('admin.approvals.columnActions'), align: 'text-right' },
]

const statusVariant: Record<ApprovalRequestStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const detail = ref<MergedRow | null>(null)
const rejectTarget = ref<MergedRow | null>(null)
const rejectOpen = ref(false)
const rejectSubmitting = ref(false)
const rejectError = ref<string | null>(null)

function canApprove(row: MergedRow): boolean {
  return row.kind === 'approval' ? auth.can('approval-requests.approve') : auth.can('leave-requests.approve')
}

function canReject(row: MergedRow): boolean {
  return row.kind === 'approval' ? auth.can('approval-requests.reject') : auth.can('leave-requests.reject')
}

async function load() {
  loading.value = true
  error.value = null

  try {
    const [approvals, leaves] = await Promise.all([
      canViewApprovals.value ? approvalRequestsService.list() : Promise.resolve({ data: [] as ApprovalRequest[], pagination: undefined }),
      canViewLeave.value
        ? leaveRequestsService.list({ page: 1, per_page: 100, filter: {} })
        : Promise.resolve({ data: [] as LeaveRequest[], pagination: undefined }),
    ])

    const approvalRows: MergedRow[] = approvals.data.map((r) => ({
      kind: 'approval',
      id: r.id,
      reference: r.reference,
      requestor: r.requested_by ?? '—',
      subject: r.subject,
      status: r.status,
      createdAt: r.created_at,
      approval: r,
    }))

    const leaveRows: MergedRow[] = leaves.data.map((r) => ({
      kind: 'leave',
      id: r.id,
      reference: `LR-${String(r.id).padStart(6, '0')}`,
      requestor: r.student?.name ?? '—',
      subject: t('admin.myRequests.leaveSubject', { from: r.from_date, to: r.to_date }),
      status: r.status,
      createdAt: r.created_at,
      leave: r,
    }))

    rows.value = [...approvalRows, ...leaveRows].sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.approvals.loadFailed')
  } finally {
    loading.value = false
  }
}

function openReject(row: MergedRow) {
  rejectTarget.value = row
  rejectError.value = null
  rejectOpen.value = true
}

async function approve(row: MergedRow) {
  if (!window.confirm(t('admin.approvals.approveConfirm'))) return

  approving.value = true
  actionError.value = null

  try {
    if (row.kind === 'approval') {
      await approvalRequestsService.approve(row.id)
    } else {
      await leaveRequestsService.approve(row.id)
    }
    detail.value = null
    await load()
  } catch (e) {
    actionError.value = e instanceof ApiRequestError ? e.message : t('admin.approvals.actionFailed')
  } finally {
    approving.value = false
  }
}

async function confirmReject(reason: string) {
  const row = rejectTarget.value
  if (!row) return

  rejectSubmitting.value = true
  rejectError.value = null

  try {
    if (row.kind === 'approval') {
      await approvalRequestsService.reject(row.id, reason)
    } else {
      await leaveRequestsService.reject(row.id, reason)
    }
    rejectOpen.value = false
    detail.value = null
    await load()
  } catch (e) {
    rejectError.value = e instanceof ApiRequestError ? e.message : t('admin.approvals.actionFailed')
  } finally {
    rejectSubmitting.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.approvals.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.approvals.subtitle') }}</p>
    </div>

    <BaseAlert v-if="error" variant="danger" class="mb-4">{{ error }}</BaseAlert>

    <div class="mb-4 flex gap-1 border-b border-neutral-200">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="activeTab = tab.key"
      >
        {{ t(tab.labelKey) }} ({{ counts[tab.key] }})
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><BaseSpinner /></div>

    <EmptyState
      v-else-if="visibleRows.length === 0"
      :title="t('admin.approvals.emptyTitle')"
      :message="t('admin.approvals.emptyMessage')"
    />

    <DataTable v-else :columns="columns" :rows="visibleRows" row-key="id">
      <template #cell-date="{ row }">{{ new Date(row.createdAt).toLocaleDateString() }}</template>
      <template #cell-requestor="{ row }">{{ row.requestor }}</template>
      <template #cell-subject="{ row }">
        <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="detail = row">
          {{ row.subject }}
        </button>
      </template>
      <template #cell-reference="{ row }">{{ row.reference }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.myRequests.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}</BaseBadge>
      </template>
      <template #cell-actions="{ row }">
        <div v-if="row.status === 'pending'" class="flex justify-end gap-2">
          <BaseButton v-if="canApprove(row)" size="sm" :loading="approving" @click="approve(row)">{{ t('admin.approvals.approve') }}</BaseButton>
          <BaseButton v-if="canReject(row)" size="sm" variant="danger" @click="openReject(row)">{{ t('admin.approvals.reject') }}</BaseButton>
        </div>
        <span v-else class="text-xs text-neutral-400">—</span>
      </template>
    </DataTable>

    <BaseModal :model-value="detail !== null" :title="detail?.subject" size="lg" @update:model-value="detail = null">
      <template v-if="detail">
        <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

        <dl v-if="detail.kind === 'approval' && detail.approval" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.approvals.columnReference') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.reference }}</dd></div>
          <div v-if="detail.approval.details"><dt class="text-neutral-500">{{ t('admin.forms.details') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.details }}</dd></div>
          <div v-if="detail.approval.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'leave' && detail.leave" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnDates') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.from_date }} – {{ detail.leave.to_date }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.reason }}</dd></div>
          <div v-if="detail.leave.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.decision_reason }}</dd></div>
        </dl>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
        <template v-if="detail?.status === 'pending'">
          <BaseButton v-if="canReject(detail)" variant="danger" @click="openReject(detail)">{{ t('admin.approvals.reject') }}</BaseButton>
          <BaseButton v-if="canApprove(detail)" :loading="approving" @click="approve(detail)">{{ t('admin.approvals.approve') }}</BaseButton>
        </template>
      </template>
    </BaseModal>

    <ConfirmReasonModal
      v-model="rejectOpen"
      :title="t('admin.approvals.reject')"
      :label="t('admin.leaveRequests.reasonLabel')"
      :confirm-label="t('admin.approvals.reject')"
      danger
      :submitting="rejectSubmitting"
      :error="rejectError"
      @confirm="confirmReject"
    />
  </div>
</template>
