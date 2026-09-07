<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { myApprovalRequestsService, type ApprovalRequest, type ApprovalRequestStatus } from '@/services/approvalRequests'
import { myLeaveRequestsService, type LeaveRequest } from '@/services/leaveRequests'
import { useAuthStore } from '@/stores/auth'
import { ApiRequestError } from '@/types/api'

/**
 * "My Request" under eApprovals — every request the current user has
 * submitted, whether it's a generic ApprovalRequest or a LeaveRequest (which
 * keeps its own dedicated backend flow; see AskForPermissionModal). Both
 * self-service endpoints are fetched at a generous per_page and merged
 * client-side rather than through usePaginatedResource, since combining two
 * independently-paginated sources behind one page control isn't meaningful
 * here — a user's own request list is never large enough to need it.
 */
type MergedRow = {
  kind: 'approval' | 'leave'
  id: number
  reference: string
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
const activeTab = ref<ApprovalRequestStatus>('pending')

const tabs: { key: ApprovalRequestStatus; labelKey: string }[] = [
  { key: 'pending', labelKey: 'admin.myRequests.tabPending' },
  { key: 'approved', labelKey: 'admin.myRequests.tabApproved' },
  { key: 'rejected', labelKey: 'admin.myRequests.tabRejected' },
]

const counts = computed(() => ({
  pending: rows.value.filter((r) => r.status === 'pending').length,
  approved: rows.value.filter((r) => r.status === 'approved').length,
  rejected: rows.value.filter((r) => r.status === 'rejected').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

const columns = [
  { key: 'date', label: t('admin.myRequests.columnDate') },
  { key: 'requestor', label: t('admin.myRequests.columnRequestor') },
  { key: 'subject', label: t('admin.myRequests.columnSubject') },
  { key: 'reference', label: t('admin.myRequests.columnReference') },
  { key: 'status', label: t('admin.myRequests.columnStatus') },
]

const statusVariant: Record<ApprovalRequestStatus, 'warning' | 'success' | 'danger'> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const detail = ref<MergedRow | null>(null)

async function load() {
  loading.value = true
  error.value = null

  try {
    const [approvals, leaves] = await Promise.all([
      myApprovalRequestsService.list(),
      myLeaveRequestsService.list({ page: 1, per_page: 100, filter: {} }),
    ])

    const approvalRows: MergedRow[] = approvals.data.map((r) => ({
      kind: 'approval',
      id: r.id,
      reference: r.reference,
      subject: r.subject,
      status: r.status,
      createdAt: r.created_at,
      approval: r,
    }))

    const leaveRows: MergedRow[] = leaves.data.map((r) => ({
      kind: 'leave',
      id: r.id,
      reference: `LR-${String(r.id).padStart(6, '0')}`,
      subject: t('admin.myRequests.leaveSubject', { from: r.from_date, to: r.to_date }),
      status: r.status,
      createdAt: r.created_at,
      leave: r,
    }))

    rows.value = [...approvalRows, ...leaveRows].sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myRequests.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myRequests.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myRequests.subtitle') }}</p>
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
      :title="t('admin.myRequests.emptyTitle')"
      :message="t('admin.myRequests.emptyMessage')"
    />

    <DataTable v-else :columns="columns" :rows="visibleRows" row-key="id">
      <template #cell-date="{ row }">{{ new Date(row.createdAt).toLocaleDateString() }}</template>
      <template #cell-requestor>{{ auth.user?.name }}</template>
      <template #cell-subject="{ row }">
        <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="detail = row">
          {{ row.subject }}
        </button>
      </template>
      <template #cell-reference="{ row }">{{ row.reference }}</template>
      <template #cell-status="{ row }">
        <BaseBadge :variant="statusVariant[row.status]">{{ t(`admin.myRequests.status${row.status.charAt(0).toUpperCase()}${row.status.slice(1)}`) }}</BaseBadge>
      </template>
    </DataTable>

    <BaseModal :model-value="detail !== null" :title="detail?.subject" @update:model-value="detail = null">
      <template v-if="detail?.kind === 'approval' && detail.approval">
        <dl class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.myRequests.columnReference') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.reference }}</dd></div>
          <div v-if="detail.approval.details"><dt class="text-neutral-500">{{ t('admin.forms.details') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.details }}</dd></div>
          <div v-if="detail.approval.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.decision_reason }}</dd></div>
        </dl>
      </template>
      <template v-else-if="detail?.kind === 'leave' && detail.leave">
        <dl class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnDates') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.from_date }} – {{ detail.leave.to_date }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.reason }}</dd></div>
          <div v-if="detail.leave.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.decision_reason }}</dd></div>
        </dl>
      </template>
    </BaseModal>
  </div>
</template>
