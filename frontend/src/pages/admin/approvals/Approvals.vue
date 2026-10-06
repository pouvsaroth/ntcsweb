<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

import ConfirmReasonModal from '@/components/admin/ConfirmReasonModal.vue'
import ExamApplicationFormModal from '@/components/admin/ExamApplicationFormModal.vue'
import BaseAlert from '@/components/ui/BaseAlert.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import DataTable from '@/components/ui/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { approvalRequestsService, type ApprovalRequest, type ApprovalRequestStatus } from '@/services/approvalRequests'
import { examApplicationsService, type ExamApplication } from '@/services/examApplications'
import { leaveRequestsService, type LeaveRequest } from '@/services/leaveRequests'
import { makeUpClassCourseLabel, makeUpClassRequestsService, type MakeUpClassRequest, type MakeUpClassRequestStatus } from '@/services/makeUpClassRequests'
import { manpowerRequestsService, type ManpowerRequest } from '@/services/manpowerRequests'
import { attendanceCorrectionsService, clock, overtimeRequestsService, type AttendanceCorrection, type OvertimeRequest } from '@/services/staffAttendance'
import { formatMinutes } from '@/services/timeAttendance'
import { resignationRequestsService, type ResignationRequest } from '@/services/resignationRequests'
import { studentRegistrationsService, type StudentRegistration } from '@/services/studentRegistrations'
import { useAuthStore } from '@/stores/auth'
import { useConfirmDialogStore } from '@/stores/confirmDialog'
import type { ApprovalFlowProgress } from '@/services/approvalFlows'
import { ApiRequestError } from '@/types/api'
import { formatDate } from '@/utils/date'
import { genderLabel } from '@/utils/gender'

/**
 * The approval queue — every pending/decided item across the generic
 * ApprovalRequest catalog, the dedicated LeaveRequest flow, the dedicated
 * ResignationRequest flow, the dedicated MakeUpClassRequest flow, and exam applications (moved here from their own
 * "Exam Application Approval" tab under Examination — see ExaminationTabs.vue),
 * and pending student self-registrations (moved here from their own
 * Students > Registrations page), merged into one table. See MyRequests.vue's docblock for why merging is
 * done client-side rather than through usePaginatedResource. Each source is
 * only fetched if the current user actually holds its view permission, same
 * gating the sidebar nav already applies.
 */
type RowStatus = ApprovalRequestStatus | MakeUpClassRequestStatus

type MergedRow = {
  kind: 'approval' | 'leave' | 'resignation' | 'makeUp' | 'exam' | 'registration' | 'manpower' | 'overtime' | 'correction'
  id: number
  reference: string
  requestor: string
  subject: string
  status: RowStatus
  createdAt: string
  /** Pending + the item has an approval flow: the step it waits on. */
  flow: ApprovalFlowProgress | null
  approval?: ApprovalRequest
  leave?: LeaveRequest
  resignation?: ResignationRequest
  makeUp?: MakeUpClassRequest
  exam?: ExamApplication
  registration?: StudentRegistration
  manpower?: ManpowerRequest
  overtime?: OvertimeRequest
  correction?: AttendanceCorrection
}

const { t } = useI18n()
const auth = useAuthStore()
const confirmDialog = useConfirmDialogStore()

const rows = ref<MergedRow[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const actionError = ref<string | null>(null)
const approving = ref(false)
const activeTab = ref<RowStatus>('pending')

// A member of an Approval Flow group sees that item's queue even without its
// view permission — only the requests involving them (see backend ApprovalFlow).
const canViewApprovals = computed(() => auth.can('approval-requests.view') || auth.isFlowApprover(['form_request']))
const canViewLeave = computed(() => auth.can('leave-requests.view') || auth.isFlowApprover(['student_leave', 'staff_leave']))
const canViewResignation = computed(() => auth.can('resignation-requests.view') || auth.isFlowApprover(['resignation']))
const canViewMakeUp = computed(() => auth.can('make-up-class-requests.view') || auth.isFlowApprover(['make_up_class']))
const canViewExams = computed(() => auth.can('exam-applications.view') || auth.isFlowApprover(['exam_application']))
// No approval flow for registrations — only whoever may approve them.
const canViewRegistrations = computed(() => auth.can('students.approve-registration'))
// HRM > Recruitment's manpower requests.
const canViewManpower = computed(() => auth.can('recruitment.view') || auth.isFlowApprover(['manpower_request']))
// HRM > Attendance & Time's overtime claims.
const canViewOvertime = computed(() => auth.can('staff-attendance.view') || auth.isFlowApprover(['overtime_request']))
const canViewCorrections = computed(() => auth.can('staff-attendance.view') || auth.isFlowApprover(['attendance_correction']))
// Only exam applications can be edited from this queue — a reviewer fixing
// the student's info or the room/table/date assignment before deciding.
// Leave/resignation requests have no equivalent "amend before deciding" step.
const canUpdateExam = computed(() => auth.can('exam-applications.update'))

// "Approved to study" — make-up classes waiting for the approver to see the
// student come on the day (see MakeUpClassRequestStatus).
const tabs = computed<{ key: RowStatus; labelKey: string }[]>(() => [
  { key: 'pending', labelKey: 'admin.approvals.tabNew' },
  ...(canViewMakeUp.value ? [{ key: 'approved_to_study' as const, labelKey: 'admin.approvals.tabApprovedToStudy' }] : []),
  { key: 'approved', labelKey: 'admin.approvals.tabApproved' },
  { key: 'rejected', labelKey: 'admin.approvals.tabRejected' },
])

const counts = computed(() => ({
  pending: rows.value.filter((r) => r.status === 'pending').length,
  approved_to_study: rows.value.filter((r) => r.status === 'approved_to_study').length,
  approved: rows.value.filter((r) => r.status === 'approved').length,
  rejected: rows.value.filter((r) => r.status === 'rejected').length,
}))

const visibleRows = computed(() => rows.value.filter((r) => r.status === activeTab.value))

const columns = [
  { key: 'actions', label: t('admin.approvals.columnActions') },
  { key: 'date', label: t('admin.approvals.columnDate') },
  { key: 'requestor', label: t('admin.approvals.columnRequestor') },
  { key: 'subject', label: t('admin.approvals.columnSubject') },
  { key: 'reference', label: t('admin.approvals.columnReference') },
  { key: 'status', label: t('admin.approvals.columnStatus') },
]

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

/** Still waiting on a decision — a make-up class approved to study waits on its second Approve. */
function isOpen(row: MergedRow): boolean {
  return row.status === 'pending' || row.status === 'approved_to_study'
}

/** A pending make-up class is first only approved to study. */
function approvesToStudy(row: MergedRow): boolean {
  return row.kind === 'makeUp' && row.status === 'pending'
}

function approveLabel(row: MergedRow): string {
  return approvesToStudy(row) ? t('admin.approvals.approveToStudy') : t('admin.approvals.approve')
}

const detail = ref<MergedRow | null>(null)
const rejectTarget = ref<MergedRow | null>(null)
const rejectOpen = ref(false)
const rejectSubmitting = ref(false)
const rejectError = ref<string | null>(null)

const approvePermission: Record<MergedRow['kind'], string> = {
  approval: 'approval-requests.approve',
  leave: 'leave-requests.approve',
  resignation: 'resignation-requests.approve',
  makeUp: 'make-up-class-requests.approve',
  exam: 'exam-applications.approve',
  registration: 'students.approve-registration',
  manpower: 'manpower-requests.approve',
  overtime: 'overtime-requests.approve',
  correction: 'attendance-corrections.approve',
}

const rejectPermission: Record<MergedRow['kind'], string> = {
  approval: 'approval-requests.reject',
  leave: 'leave-requests.reject',
  resignation: 'resignation-requests.reject',
  makeUp: 'make-up-class-requests.reject',
  exam: 'exam-applications.reject',
  registration: 'students.approve-registration',
  manpower: 'manpower-requests.reject',
  overtime: 'overtime-requests.reject',
  correction: 'attendance-corrections.reject',
}

// With an approval flow, only the group the request waits on decides it —
// the backend says whether that's this user (`can_act`).
function canApprove(row: MergedRow): boolean {
  return row.flow ? row.flow.can_act : auth.can(approvePermission[row.kind])
}

function canReject(row: MergedRow): boolean {
  return row.flow ? row.flow.can_act : auth.can(rejectPermission[row.kind])
}

// --- Exam application edit (see ExamApplicationFormModal — the same
// Student Information + Examination Information layout the applicant's own
// "Apply for an exam" form uses, but with the exam-day fields editable too,
// since a reviewer may need to correct a room/table/date before deciding) ---

const examFormModalOpen = ref(false)
const editingEnrollmentCode = ref<string | null>(null)

function openEditExam(row: MergedRow) {
  editingEnrollmentCode.value = row.exam?.enrollment_code ?? null
  examFormModalOpen.value = true
}

async function load() {
  loading.value = true
  error.value = null

  try {
    const [approvals, leaves, resignations, makeUps, exams, registrations, manpowers, overtimes, corrections] = await Promise.all([
      canViewApprovals.value ? approvalRequestsService.list({}, { approvalQueue: true }) : Promise.resolve({ data: [] as ApprovalRequest[], pagination: undefined }),
      canViewLeave.value
        ? leaveRequestsService.list({ page: 1, per_page: 100, filter: {} }, { approvalQueue: true })
        : Promise.resolve({ data: [] as LeaveRequest[], pagination: undefined }),
      canViewResignation.value
        ? resignationRequestsService.list({ page: 1, per_page: 100, filter: {} }, { approvalQueue: true })
        : Promise.resolve({ data: [] as ResignationRequest[], pagination: undefined }),
      canViewMakeUp.value
        ? makeUpClassRequestsService.list({ page: 1, per_page: 100, filter: {} }, { approvalQueue: true })
        : Promise.resolve({ data: [] as MakeUpClassRequest[], pagination: undefined }),
      // draft/not_exam/make_up applications belong to their own Exams tab,
      // not this decision queue — only the three statuses this page's own
      // tabs cover are fetched here.
      canViewExams.value
        ? examApplicationsService.list({ page: 1, per_page: 100, filter: { status: 'pending,approved,rejected' } }, { approvalQueue: true })
        : Promise.resolve({ data: [] as ExamApplication[], pagination: undefined }),
      // Pending only — a decided registration just becomes an active or
      // inactive Student, with nothing marking it as a past registration,
      // so these never appear under Approved/Rejected.
      canViewRegistrations.value
        ? studentRegistrationsService.list({ page: 1, per_page: 100 })
        : Promise.resolve({ data: [] as StudentRegistration[], pagination: undefined }),
      canViewManpower.value
        ? manpowerRequestsService.list({ page: 1, per_page: 100, filter: {} }, { approvalQueue: true })
        : Promise.resolve({ data: [] as ManpowerRequest[], pagination: undefined }),
      canViewOvertime.value
        ? overtimeRequestsService.list({ page: 1, per_page: 100, filter: {} }, { approvalQueue: true })
        : Promise.resolve({ data: [] as OvertimeRequest[], pagination: undefined }),
      canViewCorrections.value
        ? attendanceCorrectionsService.list({ page: 1, per_page: 100, filter: {} }, { approvalQueue: true })
        : Promise.resolve({ data: [] as AttendanceCorrection[], pagination: undefined }),
    ])

    const approvalRows: MergedRow[] = approvals.data.map((r) => ({
      kind: 'approval',
      id: r.id,
      reference: r.reference,
      requestor: r.requested_by ?? '—',
      subject: r.subject,
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      approval: r,
    }))

    const leaveRows: MergedRow[] = leaves.data.map((r) => ({
      kind: 'leave',
      id: r.id,
      reference: `LR-${String(r.id).padStart(6, '0')}`,
      requestor: r.student?.name ?? '—',
      subject: t('admin.myRequests.leaveSubject', { from: formatDate(r.from_date), to: formatDate(r.to_date) }),
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      leave: r,
    }))

    const resignationRows: MergedRow[] = resignations.data.map((r) => ({
      kind: 'resignation',
      id: r.id,
      reference: `RS-${String(r.id).padStart(6, '0')}`,
      requestor: r.staff?.name ?? '—',
      subject: t('admin.myRequests.resignationSubject', { date: formatDate(r.resignation_date) }),
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      resignation: r,
    }))

    const makeUpRows: MergedRow[] = makeUps.data.map((r) => ({
      kind: 'makeUp',
      id: r.id,
      reference: `MU-${String(r.id).padStart(6, '0')}`,
      requestor: r.student?.name ?? '—',
      subject: t('makeUpClassRequest.subject', { from: formatDate(r.from_date), to: formatDate(r.to_date) }),
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      makeUp: r,
    }))

    // Narrows ExamApplicationStatus down to the three this queue's own tabs
    // cover — the status filter above should already guarantee this, but a
    // draft/not_exam/make_up row slipping through would otherwise crash
    // `counts`/`visibleRows`, which only know about ApprovalRequestStatus.
    const examRows: MergedRow[] = exams.data
      .filter((r): r is ExamApplication & { status: ApprovalRequestStatus } => r.status === 'pending' || r.status === 'approved' || r.status === 'rejected')
      .map((r) => ({
        kind: 'exam',
        id: r.id,
        reference: r.enrollment_code ?? `EX-${String(r.id).padStart(6, '0')}`,
        requestor: r.student.name,
        subject: t('admin.approvals.examSubject', { date: formatDate(r.exam_date) }),
        status: r.status,
        createdAt: r.created_at,
        flow: r.approval_flow ?? null,
        exam: r,
      }))

    const registrationRows: MergedRow[] = registrations.data.map((r) => ({
      kind: 'registration',
      id: r.id,
      reference: r.student_code,
      requestor: r.full_name,
      subject: t('admin.approvals.registrationSubject', { course: r.enrollment?.course_package?.name ?? '—' }),
      status: 'pending',
      createdAt: r.created_at,
      flow: null,
      registration: r,
    }))

    const manpowerRows: MergedRow[] = manpowers.data.map((r) => ({
      kind: 'manpower',
      id: r.id,
      reference: r.reference,
      requestor: r.requested_by ?? '—',
      subject: t('admin.approvals.manpowerSubject', { job: r.job_title, count: r.headcount }),
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      manpower: r,
    }))

    const overtimeRows: MergedRow[] = overtimes.data.map((r) => ({
      kind: 'overtime',
      id: r.id,
      reference: r.reference,
      requestor: r.staff?.name ?? '—',
      subject: t('admin.approvals.overtimeSubject', { time: formatMinutes(r.minutes), date: formatDate(r.date) }),
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      overtime: r,
    }))

    const correctionRows: MergedRow[] = corrections.data.map((r) => ({
      kind: 'correction',
      id: r.id,
      reference: r.reference,
      requestor: r.staff?.name ?? '—',
      subject: t('admin.approvals.correctionSubject', { date: formatDate(r.date) }),
      status: r.status,
      createdAt: r.created_at,
      flow: r.approval_flow ?? null,
      correction: r,
    }))

    rows.value = [...approvalRows, ...leaveRows, ...resignationRows, ...makeUpRows, ...examRows, ...registrationRows, ...manpowerRows, ...overtimeRows, ...correctionRows].sort((a, b) =>
      b.createdAt.localeCompare(a.createdAt),
    )
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
  // Approving a registration also records the payment of its invoice
  // balance (see StudentRegistrationService::approve()), so it keeps its own
  // confirm text naming the student.
  const message =
    row.kind === 'registration'
      ? t('admin.studentRegistrations.approveConfirm', { name: row.requestor })
      : t(
          approvesToStudy(row)
            ? 'admin.approvals.approveToStudyConfirm'
            : row.kind === 'makeUp'
              ? 'admin.approvals.approveMakeUpCameConfirm'
              : 'admin.approvals.approveConfirm',
        )
  if (!(await confirmDialog.confirm(message))) return

  approving.value = true
  actionError.value = null

  try {
    if (row.kind === 'approval') {
      await approvalRequestsService.approve(row.id)
    } else if (row.kind === 'leave') {
      await leaveRequestsService.approve(row.id)
    } else if (row.kind === 'resignation') {
      await resignationRequestsService.approve(row.id)
    } else if (row.kind === 'makeUp') {
      await (approvesToStudy(row) ? makeUpClassRequestsService.approveToStudy(row.id) : makeUpClassRequestsService.approve(row.id))
    } else if (row.kind === 'registration') {
      await studentRegistrationsService.approve(row.id)
    } else if (row.kind === 'manpower') {
      await manpowerRequestsService.approve(row.id)
    } else if (row.kind === 'overtime') {
      await overtimeRequestsService.approve(row.id)
    } else if (row.kind === 'correction') {
      await attendanceCorrectionsService.approve(row.id)
    } else {
      await examApplicationsService.approve(row.id)
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
    } else if (row.kind === 'leave') {
      await leaveRequestsService.reject(row.id, reason)
    } else if (row.kind === 'resignation') {
      await resignationRequestsService.reject(row.id, reason)
    } else if (row.kind === 'makeUp') {
      await makeUpClassRequestsService.reject(row.id, reason)
    } else if (row.kind === 'registration') {
      await studentRegistrationsService.reject(row.id, reason)
    } else if (row.kind === 'manpower') {
      await manpowerRequestsService.reject(row.id, reason)
    } else if (row.kind === 'overtime') {
      await overtimeRequestsService.reject(row.id, reason)
    } else if (row.kind === 'correction') {
      await attendanceCorrectionsService.reject(row.id, reason)
    } else {
      await examApplicationsService.reject(row.id, reason)
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

    <!-- On a phone (below sm, same as Students.vue) each request is a card;
         tapping the subject opens its details. Wider screens keep the table. -->
    <div v-else-if="visibleRows.length > 0" class="space-y-3 sm:hidden">
      <div
        v-for="row in visibleRows"
        :key="row.id"
        class="flex flex-col rounded-[--radius-card] border border-neutral-200 bg-white p-4 shadow-[--shadow-card]"
      >
        <div class="flex items-start justify-between gap-3">
          <button type="button" class="min-w-0 text-left text-sm font-semibold text-primary-700 hover:underline" @click="detail = row">
            {{ row.subject }}
          </button>
          <BaseBadge :variant="statusVariant[row.status]" class="shrink-0">{{ t(statusLabelKey[row.status]) }}</BaseBadge>
        </div>

        <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
          <dt class="text-neutral-500">{{ t('admin.approvals.columnRequestor') }}</dt>
          <dd class="truncate font-medium text-neutral-800">{{ row.requestor }}</dd>
          <dt class="text-neutral-500">{{ t('admin.approvals.columnDate') }}</dt>
          <dd class="text-neutral-800">{{ formatDate(row.createdAt) }}</dd>
          <dt class="text-neutral-500">{{ t('admin.approvals.columnReference') }}</dt>
          <dd class="truncate text-neutral-800">{{ row.reference }}</dd>
        </dl>

        <p v-if="row.flow" class="mt-2 text-xs text-neutral-500">
          {{ t('admin.approvals.flowStep', { step: row.flow.step, total: row.flow.total, group: row.flow.group ?? '—' }) }}
        </p>

        <div v-if="isOpen(row) && (canApprove(row) || canReject(row) || (row.kind === 'exam' && canUpdateExam))" class="mt-3 flex flex-wrap justify-end gap-2 border-t border-neutral-100 pt-3">
          <BaseButton v-if="row.kind === 'exam' && canUpdateExam" size="sm" variant="outline" @click="openEditExam(row)">{{ t('common.edit') }}</BaseButton>
          <BaseButton v-if="canApprove(row)" size="sm" :loading="approving" @click="approve(row)">{{ approveLabel(row) }}</BaseButton>
          <BaseButton v-if="canReject(row)" size="sm" variant="danger" @click="openReject(row)">{{ t('admin.approvals.reject') }}</BaseButton>
        </div>
      </div>
    </div>

    <div v-if="!loading && visibleRows.length > 0" class="hidden sm:block">
      <DataTable :columns="columns" :rows="visibleRows" row-key="id">
        <template #cell-date="{ row }">{{ formatDate(row.createdAt) }}</template>
        <template #cell-requestor="{ row }">{{ row.requestor }}</template>
        <template #cell-subject="{ row }">
          <button type="button" class="text-left font-medium text-primary-700 hover:underline" @click="detail = row">
            {{ row.subject }}
          </button>
        </template>
        <template #cell-reference="{ row }">{{ row.reference }}</template>
        <template #cell-status="{ row }">
          <BaseBadge :variant="statusVariant[row.status]">{{ t(statusLabelKey[row.status]) }}</BaseBadge>
          <p v-if="row.flow" class="mt-1 text-xs text-neutral-500">
            {{ t('admin.approvals.flowStep', { step: row.flow.step, total: row.flow.total, group: row.flow.group ?? '—' }) }}
          </p>
        </template>
        <template #cell-actions="{ row }">
          <div v-if="isOpen(row)" class="flex gap-2">
            <BaseButton v-if="row.kind === 'exam' && canUpdateExam" size="sm" variant="outline" @click="openEditExam(row)">{{ t('common.edit') }}</BaseButton>
            <BaseButton v-if="canApprove(row)" size="sm" :loading="approving" @click="approve(row)">{{ approveLabel(row) }}</BaseButton>
            <BaseButton v-if="canReject(row)" size="sm" variant="danger" @click="openReject(row)">{{ t('admin.approvals.reject') }}</BaseButton>
          </div>
          <span v-else class="text-xs text-neutral-400">—</span>
        </template>
      </DataTable>
    </div>

    <BaseModal :model-value="detail !== null" :title="detail?.subject" size="lg" @update:model-value="detail = null">
      <template v-if="detail">
        <BaseAlert v-if="actionError" variant="danger" class="mb-4">{{ actionError }}</BaseAlert>

        <dl v-if="detail.kind === 'approval' && detail.approval" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.approvals.columnReference') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.reference }}</dd></div>
          <div v-if="detail.approval.details"><dt class="text-neutral-500">{{ t('admin.forms.details') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.details }}</dd></div>
          <div v-if="detail.approval.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.approval.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'leave' && detail.leave" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnDates') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.leave.from_date) }} – {{ formatDate(detail.leave.to_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.leaveRequests.columnReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.reason }}</dd></div>
          <div v-if="detail.leave.attachments.length > 0">
            <dt class="text-neutral-500">{{ t('admin.leaveRequests.attachments') }}</dt>
            <dd class="mt-1 flex flex-col gap-1">
              <a
                v-for="attachment in detail.leave.attachments"
                :key="attachment.id"
                :href="attachment.url"
                target="_blank"
                rel="noopener noreferrer"
                class="font-medium text-primary-700 hover:underline"
              >
                {{ attachment.file_name }}
              </a>
            </dd>
          </div>
          <div v-if="detail.leave.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.leave.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'resignation' && detail.resignation" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('resignationRequest.gender') }}</dt><dd class="font-medium text-neutral-900">{{ genderLabel(detail.resignation.staff?.gender) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.position') }}</dt><dd class="font-medium text-neutral-900">{{ detail.resignation.staff?.position || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.resignationDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.resignation.resignation_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('resignationRequest.reason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.resignation.reason }}</dd></div>
          <div v-if="detail.resignation.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.resignation.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'makeUp' && detail.makeUp" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('makeUpClassRequest.course') }}</dt><dd class="font-medium text-neutral-900">{{ makeUpClassCourseLabel(detail.makeUp) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('makeUpClassRequest.dates') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.makeUp.from_date) }} – {{ formatDate(detail.makeUp.to_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('makeUpClassRequest.time') }}</dt><dd class="font-medium text-neutral-900">{{ detail.makeUp.from_time }} – {{ detail.makeUp.to_time }}</dd></div>
          <div v-if="detail.makeUp.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.makeUp.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'exam' && detail.exam" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnBook') }}</dt><dd class="font-medium text-neutral-900">{{ detail.exam.book?.title ?? detail.exam.enrollment.course_package?.name ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnExamDate') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.exam.exam_date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnTimeExam') }}</dt><dd class="font-medium text-neutral-900">{{ detail.exam.exam_time?.slice(0, 5) ?? '—' }} – {{ detail.exam.exam_time_out?.slice(0, 5) ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.exams.columnTableNumber') }}</dt><dd class="font-medium text-neutral-900">{{ detail.exam.table?.name ?? detail.exam.table_no ?? '—' }}</dd></div>
          <div v-if="detail.exam.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.exam.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'manpower' && detail.manpower" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.recruitment.manpower.jobTitle') }}</dt><dd class="font-medium text-neutral-900">{{ detail.manpower.job_title }}<template v-if="detail.manpower.position"> ({{ detail.manpower.position }})</template></dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.recruitment.manpower.department') }}</dt><dd class="font-medium text-neutral-900">{{ detail.manpower.department ?? '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.recruitment.manpower.headcount') }}</dt><dd class="font-medium text-neutral-900">{{ detail.manpower.headcount }} · {{ t(`admin.recruitment.employmentTypes.${detail.manpower.employment_type}`) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.recruitment.manpower.neededBy') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.manpower.needed_by) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.recruitment.manpower.reason') }}</dt><dd class="whitespace-pre-line font-medium text-neutral-900">{{ detail.manpower.reason }}</dd></div>
          <div v-if="detail.manpower.requirements"><dt class="text-neutral-500">{{ t('admin.recruitment.manpower.requirements') }}</dt><dd class="whitespace-pre-line font-medium text-neutral-900">{{ detail.manpower.requirements }}</dd></div>
          <div v-if="detail.manpower.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.manpower.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'correction' && detail.correction" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.holidays.date') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.correction.date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.corrections.recorded') }}</dt><dd class="font-medium tabular-nums text-neutral-900">{{ clock(detail.correction.recorded.check_in_at) }} – {{ clock(detail.correction.recorded.check_out_at) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.corrections.requested') }}</dt><dd class="font-medium tabular-nums text-primary-700">{{ detail.correction.check_in ?? clock(detail.correction.recorded.check_in_at) }} – {{ detail.correction.check_out ?? clock(detail.correction.recorded.check_out_at) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.overtime.reason') }}</dt><dd class="whitespace-pre-line font-medium text-neutral-900">{{ detail.correction.reason }}</dd></div>
          <div v-if="detail.correction.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.correction.decision_reason }}</dd></div>
        </dl>
        <dl v-else-if="detail.kind === 'overtime' && detail.overtime" class="grid gap-y-2 text-sm">
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.holidays.date') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.overtime.date) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.overtime.minutes') }}</dt><dd class="font-medium text-neutral-900">{{ formatMinutes(detail.overtime.minutes) }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('admin.timeAttendance.overtime.reason') }}</dt><dd class="whitespace-pre-line font-medium text-neutral-900">{{ detail.overtime.reason }}</dd></div>
          <div v-if="detail.overtime.decision_reason"><dt class="text-neutral-500">{{ t('admin.leaveRequests.decisionReason') }}</dt><dd class="font-medium text-neutral-900">{{ detail.overtime.decision_reason }}</dd></div>
        </dl>
        <div v-else-if="detail.kind === 'registration' && detail.registration">
          <div class="flex gap-4">
            <div class="h-24 w-24 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
              <img v-if="detail.registration.photo_url" :src="detail.registration.photo_url" alt="" class="h-full w-full object-cover" />
            </div>
            <dl class="grid flex-1 grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.columnPhone') }}</dt><dd class="font-medium text-neutral-900">{{ detail.registration.phone }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.email') }}</dt><dd class="font-medium text-neutral-900">{{ detail.registration.email ?? '—' }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.gender') }}</dt><dd class="font-medium text-neutral-900">{{ genderLabel(detail.registration.gender) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('admin.studentRegistrations.dateOfBirth') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(detail.registration.date_of_birth) }}</dd></div>
              <div class="col-span-2">
                <dt class="text-neutral-500">{{ t('admin.studentRegistrations.address') }}</dt>
                <dd class="font-medium text-neutral-900">{{ [detail.registration.house_no, detail.registration.street_no, detail.registration.other_address].filter(Boolean).join(', ') || '—' }}</dd>
              </div>
            </dl>
          </div>

          <div v-if="detail.registration.enrollment" class="mt-4 rounded-lg border border-neutral-200 p-3 text-sm">
            <p class="font-medium text-neutral-900">{{ detail.registration.enrollment.course_package?.name }}</p>
            <p class="text-neutral-500">{{ detail.registration.enrollment.academic_program?.name }} — {{ detail.registration.enrollment.class?.name }}</p>
            <p v-if="detail.registration.invoice" class="mt-1 text-neutral-700">{{ detail.registration.invoice.currency }} {{ detail.registration.invoice.total.toFixed(2) }}</p>
          </div>

          <div v-if="detail.registration.invoice" class="mt-3 flex items-center justify-between rounded-lg bg-warning-50 px-3 py-2 text-sm">
            <div>
              <span class="text-neutral-700">{{ t('admin.studentRegistrations.balanceDue') }}</span>
              <BaseBadge :variant="detail.registration.invoice.intended_payment_method === 'QR' ? 'primary' : 'neutral'" class="ml-2">
                {{ t(detail.registration.invoice.intended_payment_method === 'QR' ? 'admin.studentRegistrations.paymentQr' : 'admin.studentRegistrations.paymentCash') }}
              </BaseBadge>
            </div>
            <span class="font-semibold text-neutral-900">{{ detail.registration.invoice.currency }} {{ detail.registration.invoice.balance.toFixed(2) }}</span>
          </div>
          <p v-if="detail.registration.invoice?.intended_payment_method === 'QR'" class="mt-1.5 text-xs text-neutral-500">
            {{ t('admin.studentRegistrations.qrVerifyHint') }}
          </p>
        </div>
      </template>

      <template #footer>
        <BaseButton variant="outline" @click="detail = null">{{ t('common.close') }}</BaseButton>
        <template v-if="detail && isOpen(detail)">
          <BaseButton v-if="detail.kind === 'exam' && canUpdateExam" variant="outline" @click="openEditExam(detail)">{{ t('common.edit') }}</BaseButton>
          <BaseButton v-if="canReject(detail)" variant="danger" @click="openReject(detail)">{{ t('admin.approvals.reject') }}</BaseButton>
          <BaseButton v-if="canApprove(detail)" :loading="approving" @click="approve(detail)">{{ approveLabel(detail) }}</BaseButton>
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

    <ExamApplicationFormModal v-model="examFormModalOpen" :initial-enrollment-code="editingEnrollmentCode" @saved="load" />
  </div>
</template>
