<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import AskForPermissionModal from '@/components/layout/AskForPermissionModal.vue'
import MakeUpClassRequestModal from '@/components/layout/MakeUpClassRequestModal.vue'
import ResignationFormModal from '@/components/layout/ResignationFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { myApprovalRequestsService, type ApprovalRequest, type ApprovalRequestStatus } from '@/services/approvalRequests'
import { myLeaveRequestsService, type LeaveRequest } from '@/services/leaveRequests'
import { formatDays } from '@/services/leaveManagement'
import { makeUpClassCourseLabel, myMakeUpClassRequestsService, type MakeUpClassRequest, type MakeUpClassRequestStatus } from '@/services/makeUpClassRequests'
import { myResignationRequestsService, type ResignationRequest } from '@/services/resignationRequests'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'

/**
 * "My Request" under eApprovals — every request the current user has
 * submitted, whether it's a generic ApprovalRequest, a LeaveRequest, (for
 * staff accounts) a ResignationRequest, or (for students) a MakeUpClassRequest — each keeps its own dedicated
 * backend flow; see AskForPermissionModal/ResignationFormModal. Every
 * source is fetched at a generous per_page and merged client-side rather
 * than through usePaginatedResource, since combining independently-
 * paginated sources behind one page control isn't meaningful here — a
 * user's own request list is never large enough to need it.
 */
type RowStatus = ApprovalRequestStatus | MakeUpClassRequestStatus

type MergedRow = {
  kind: 'approval' | 'leave' | 'resignation' | 'makeUp'
  id: number
  reference: string
  subject: string
  status: RowStatus
  createdAt: string
  /** The approver's reason, when rejected. */
  reason: string | null
  approval?: ApprovalRequest
  leave?: LeaveRequest
  resignation?: ResignationRequest
  makeUp?: MakeUpClassRequest
}

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

// Resignation only makes sense for a staff account — a student reaching
// this page (see the router's studentAllowed guard) never has a staff
// record, so the self-service resignation endpoint would just 422 for them.
const canResign = computed(() => !auth.hasRole('student'))
// The inverse: make-up class requests are student-only (the endpoint 422s
// for an account with no student record).
const canRequestMakeUp = computed(() => auth.hasRole('student'))

const rows = ref<MergedRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const activeTab = ref<RowStatus>('pending')

// "Approved to study" — the student's make-up classes they may now come to
// (see MakeUpClassRequestStatus).
const tabs = computed<{ key: RowStatus; labelKey: string }[]>(() => [
  { key: 'pending', labelKey: 'admin.myRequests.tabPending' },
  ...(canRequestMakeUp.value ? [{ key: 'approved_to_study' as const, labelKey: 'admin.myRequests.tabApprovedToStudy' }] : []),
  { key: 'approved', labelKey: 'admin.myRequests.tabApproved' },
  { key: 'rejected', labelKey: 'admin.myRequests.tabRejected' },
])

const counts = computed(() => ({
  pending: rows.value.filter((r) => r.status === 'pending').length,
  approved_to_study: rows.value.filter((r) => r.status === 'approved_to_study').length,
  approved: rows.value.filter((r) => r.status === 'approved').length,
  rejected: rows.value.filter((r) => r.status === 'rejected').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

// A student may withdraw their own request while it is still waiting —
// resignation is staff-only, so it never shows here.
const canDelete = computed(() => auth.hasRole('student') && activeTab.value === 'pending')

const columns = computed(() => [
  ...(canDelete.value ? [{ key: 'actions', label: t('admin.myRequests.columnAction') }] : []),
  { key: 'status', label: t('admin.myRequests.columnStatus') },
  { key: 'date', label: t('admin.myRequests.columnDate') },
  { key: 'requestor', label: t('admin.myRequests.columnRequestor') },
  { key: 'subject', label: t('admin.myRequests.columnSubject') },
  { key: 'reference', label: t('admin.myRequests.columnReference') },
])

const statusVariant: Record<RowStatus, 'warning' | 'primary' | 'success' | 'danger'> = {
  pending: 'warning',
  approved_to_study: 'primary',
  approved: 'success',
  rejected: 'danger',
}

const statusLabelKey: Record<RowStatus, string> = {
  pending: 'admin.myRequests.statusPending',
  approved_to_study: 'admin.myRequests.statusApprovedToStudy',
  approved: 'admin.myRequests.statusApproved',
  rejected: 'admin.myRequests.statusRejected',
}

const detail = ref<MergedRow | null>(null)
const showLeaveModal = ref(false)
const showResignationModal = ref(false)
const showMakeUpModal = ref(false)

function onLeaveModalChange(open: boolean) {
  showLeaveModal.value = open
  if (!open) load()
}

function onMakeUpModalChange(open: boolean) {
  showMakeUpModal.value = open
  if (!open) load()
}

function onResignationModalChange(open: boolean) {
  showResignationModal.value = open
  if (!open) load()
}

const deleting = ref(false)

async function remove(row: MergedRow) {
  if (!(await confirmDialog.confirm({ message: t('admin.myRequests.deleteConfirm', { subject: row.subject }), danger: true }))) return

  deleting.value = true
  error.value = null
  try {
    if (row.kind === 'approval') await myApprovalRequestsService.remove(row.id)
    else if (row.kind === 'leave') await myLeaveRequestsService.remove(row.id)
    else if (row.kind === 'makeUp') await myMakeUpClassRequestsService.remove(row.id)
    await load()
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myRequests.deleteFailed')
  } finally {
    deleting.value = false
  }
}

async function load() {
  loading.value = true
  error.value = null

  try {
    const [approvals, leaves, resignations, makeUps] = await Promise.all([
      myApprovalRequestsService.list(),
      myLeaveRequestsService.list({ page: 1, per_page: 100, filter: {} }),
      canResign.value
        ? myResignationRequestsService.list({ page: 1, per_page: 100, filter: {} })
        : Promise.resolve({ data: [] as ResignationRequest[], pagination: undefined }),
      canRequestMakeUp.value
        ? myMakeUpClassRequestsService.list({ page: 1, per_page: 100, filter: {} })
        : Promise.resolve({ data: [] as MakeUpClassRequest[], pagination: undefined }),
    ])

    const approvalRows: MergedRow[] = approvals.data.map((r) => ({
      kind: 'approval',
      id: r.id,
      reference: r.reference,
      subject: r.subject,
      status: r.status,
      createdAt: r.created_at,
      reason: r.decision_reason ?? null,
      approval: r,
    }))

    const leaveRows: MergedRow[] = leaves.data.map((r) => ({
      kind: 'leave',
      id: r.id,
      reference: `LR-${String(r.id).padStart(6, '0')}`,
      subject: leaveSubject(r),
      status: r.status,
      createdAt: r.created_at,
      reason: r.decision_reason ?? null,
      leave: r,
    }))

    const resignationRows: MergedRow[] = resignations.data.map((r) => ({
      kind: 'resignation',
      id: r.id,
      reference: `RS-${String(r.id).padStart(6, '0')}`,
      subject: t('admin.myRequests.resignationSubject', { date: formatDate(r.resignation_date) }),
      status: r.status,
      createdAt: r.created_at,
      reason: r.decision_reason ?? null,
      resignation: r,
    }))

    const makeUpRows: MergedRow[] = makeUps.data.map((r) => ({
      kind: 'makeUp',
      id: r.id,
      reference: `MU-${String(r.id).padStart(6, '0')}`,
      subject: t('makeUpClassRequest.subject', { from: formatDate(r.from_date), to: formatDate(r.to_date) }),
      status: r.status,
      createdAt: r.created_at,
      reason: r.decision_reason ?? null,
      makeUp: r,
    }))

    rows.value = [...approvalRows, ...leaveRows, ...resignationRows, ...makeUpRows].sort((a, b) => b.createdAt.localeCompare(a.createdAt))
  } catch (e) {
    error.value = e instanceof ApiRequestError ? e.message : t('admin.myRequests.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => load())

/** "Annual leave: 12-10-2026 – 16-10-2026" for a staff request with a leave type, else the plain dates line. */
function leaveSubject(r: LeaveRequest): string {
  const dates = t('admin.myRequests.leaveSubject', { from: formatDate(r.from_date), to: formatDate(r.to_date) })
  return r.leave_type ? `${r.leave_type.name}: ${dates}` : dates
}
</script>

<template>
  <div>
    <div class="mb-6 flex items-start justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('admin.myRequests.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('admin.myRequests.subtitle') }}</p>
      </div>
      <div class="flex gap-2">
        <BaseButton @click="showLeaveModal = true">{{ t('leaveRequest.title') }}</BaseButton>
        <BaseButton v-if="canRequestMakeUp" variant="outline" @click="showMakeUpModal = true">{{ t('makeUpClassRequest.title') }}</BaseButton>
        <BaseButton v-if="canResign" variant="outline" @click="showResignationModal = true">{{ t('resignationRequest.title') }}</BaseButton>
      </div>
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

    <template v-else>
      <!-- Phones: one card per request; the table below from sm up. -->
      <div class="space-y-3 sm:hidden">
        <div
          v-for="row in visibleRows"
          :key="`${row.kind}-${row.id}`"
          class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]"
        >
          <div class="flex items-start justify-between gap-3">
            <button type="button" class="min-w-0 text-left text-sm font-semibold text-primary-700 hover:underline" @click="detail = row">
              {{ row.subject }}
            </button>
            <BaseBadge :variant="statusVariant[row.status]" class="shrink-0">{{ t(statusLabelKey[row.status]) }}</BaseBadge>
          </div>

          <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
            <dt class="text-neutral-500">{{ t('admin.myRequests.columnDate') }}</dt>
            <dd class="text-neutral-800">{{ formatDate(row.createdAt) }}</dd>
            <dt class="text-neutral-500">{{ t('admin.myRequests.columnRequestor') }}</dt>
            <dd class="truncate font-medium text-neutral-800">{{ auth.user?.name }}</dd>
            <dt class="text-neutral-500">{{ t('admin.myRequests.columnReference') }}</dt>
            <dd class="truncate text-neutral-800">{{ row.reference }}</dd>
          </dl>

          <p v-if="row.status === 'rejected' && row.reason" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            <span class="font-medium">{{ t('admin.leaveRequests.decisionReason') }}:</span> {{ row.reason }}
          </p>

          <div v-if="canDelete && row.kind !== 'resignation'" class="mt-3 flex justify-end border-t border-neutral-100 pt-3">
            <BaseButton size="sm" variant="danger" :disabled="deleting" @click="remove(row)">{{ t('admin.myRequests.delete') }}</BaseButton>
          </div>
        </div>
      </div>

      <div class="hidden sm:block">
        <DataTable :columns="columns" :rows="visibleRows" row-key="id">
          <template #cell-date="{ row }">{{ formatDate(row.createdAt) }}</template>
          <template #cell-requestor>{{ auth.user?.name }}</template>
          <template #cell-subject="{ row }">
            <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="detail = row">
              {{ row.subject }}
            </button>
          </template>
          <template #cell-reference="{ row }">{{ row.reference }}</template>
          <template #cell-actions="{ row }">
            <button
              v-if="row.kind !== 'resignation'"
              type="button"
              class="text-sm font-medium text-danger-600 hover:text-red-700 disabled:opacity-50"
              :disabled="deleting"
              @click="remove(row)"
            >
              {{ t('admin.myRequests.delete') }}
            </button>
          </template>
          <template #cell-status="{ row }">
            <BaseBadge :variant="statusVariant[row.status]">{{ t(statusLabelKey[row.status]) }}</BaseBadge>
          </template>
        </DataTable>
      </div>
    </template>

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
          <div v-if="detail.leave.leave_type"><dt class="text-neutral-500">{{ t('admin.leaveManagement.policies.leaveType') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.leave_type.name }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnDates') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.leave.from_date) }} – {{ formatDate(detail.leave.to_date) }}<template v-if="detail.leave.day_part === 'morning' || detail.leave.day_part === 'afternoon'"> ({{ t(detail.leave.day_part === 'morning' ? 'leaveRequest.dayMorning' : 'leaveRequest.dayAfternoon') }})</template></dd></div>
          <div v-if="detail.leave.days != null"><dt class="text-neutral-500">{{ t('admin.leaveManagement.requests.days') }}</dt><dd class="font-medium text-neutral-900">{{ formatDays(detail.leave.days) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.reason }}</dd></div>
          <div v-if="detail.leave.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.decision_reason }}</dd></div>
        </dl>
      </template>
      <template v-else-if="detail?.kind === 'resignation' && detail.resignation">
        <dl class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('resignationRequest.resignationDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.resignation.resignation_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.reason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.resignation.reason }}</dd></div>
          <div v-if="detail.resignation.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.resignation.decision_reason }}</dd></div>
        </dl>
      </template>
      <template v-else-if="detail?.kind === 'makeUp' && detail.makeUp">
        <dl class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('makeUpClassRequest.course') }}</dt><dd class="font-medium text-neutral-900">{{ makeUpClassCourseLabel(detail.makeUp) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('makeUpClassRequest.dates') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.makeUp.from_date) }} – {{ formatDate(detail.makeUp.to_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('makeUpClassRequest.time') }}</dt><dd class="font-medium text-neutral-900">{{ detail.makeUp.from_time }} – {{ detail.makeUp.to_time }}</dd></div>
          <div v-if="detail.makeUp.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.makeUp.decision_reason }}</dd></div>
        </dl>
      </template>
    </BaseModal>

    <AskForPermissionModal :model-value="showLeaveModal" @update:model-value="onLeaveModalChange" />
    <MakeUpClassRequestModal v-if="canRequestMakeUp" :model-value="showMakeUpModal" @update:model-value="onMakeUpModalChange" />
    <ResignationFormModal v-if="canResign" :model-value="showResignationModal" @update:model-value="onResignationModalChange" />
  </div>
</template>
