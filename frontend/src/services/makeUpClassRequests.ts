import { apiGet, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { ApprovalFlowProgress } from '@/services/approvalFlows'

/**
 * Two approval stages: pending → approved_to_study (the student may come;
 * a status change only) → approved, once the approver saw them come at the
 * set time — only then do the hours count. See backend MakeUpClassRequest.
 */
export type MakeUpClassRequestStatus = 'pending' | 'approved_to_study' | 'approved' | 'rejected'

export interface MakeUpClassRequestStudent {
  id: number
  name: string
  student_code: string | null
}

export interface MakeUpClassRequest {
  id: number
  student?: MakeUpClassRequestStudent
  enrollment_id: number
  course_package?: { id: number; name: string } | null
  school_class?: { id: number; name: string } | null
  from_date: string
  to_date: string
  from_time: string
  to_time: string
  status: MakeUpClassRequestStatus
  /** Approvals queue only — see ApprovalFlowProgress. */
  approval_flow?: ApprovalFlowProgress | null
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  created_at: string
}

export interface MakeUpClassRequestInput {
  enrollment_id: number
  from_date: string
  to_date: string
  from_time: string
  to_time: string
}

/** One of the student's active enrollments, for the form's Course picker — see MyMakeUpClassRequestController::enrollments(). */
export interface MakeUpClassEnrollmentOption {
  id: number
  course_package: { id: number; name: string } | null
  school_class: { id: number; name: string } | null
}

/** Student self-service — own requests only, scoped server-side. See MakeUpClassRequestModal.vue. */
export const myMakeUpClassRequestsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<MakeUpClassRequest>> {
    const result = await apiGetWithMeta<MakeUpClassRequest[]>('/my-make-up-class-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: MakeUpClassRequestInput) => apiPost<MakeUpClassRequest>('/my-make-up-class-requests', input),

  enrollments: () => apiGet<MakeUpClassEnrollmentOption[]>('/my-make-up-class-requests/enrollments'),
}

/** Admin approve/reject queue — see the eApprovals "Approvals" page. */
export const makeUpClassRequestsService = {
  /** `approvalQueue`: the Approvals queue's view — a pending request of an item with an approval flow is only listed to the group it waits on. */
  async list(query: PaginatedQuery, opts: { approvalQueue?: boolean } = {}): Promise<PaginatedResult<MakeUpClassRequest>> {
    const result = await apiGetWithMeta<MakeUpClassRequest[]>('/make-up-class-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter, ...(opts.approvalQueue ? { approval_queue: '1' } : {}) },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  approveToStudy: (id: number) => apiPost<MakeUpClassRequest>(`/make-up-class-requests/${id}/approve-to-study`),
  /** Only once approved to study — the student came. */
  approve: (id: number) => apiPost<MakeUpClassRequest>(`/make-up-class-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<MakeUpClassRequest>(`/make-up-class-requests/${id}/reject`, { reason }),
}

/** "Course — Class" label shared by the picker and the request detail views. */
export function makeUpClassCourseLabel(row: { course_package?: { name: string } | null; school_class?: { name: string } | null }): string {
  return [row.course_package?.name, row.school_class?.name].filter(Boolean).join(' — ') || '—'
}
