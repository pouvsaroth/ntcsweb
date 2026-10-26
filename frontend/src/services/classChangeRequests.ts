import { apiDelete, apiGet, apiPost } from '@/services/http'
import type { ApprovalFlowProgress } from '@/services/approvalFlows'

/**
 * A student's request to change class: asked from eApprovals > My Request,
 * decided in Approvals; approving moves the same enrollment to the new class
 * and saves the transfer history (backend ClassChangeRequestService).
 */
export type ClassChangeStatus = 'pending' | 'approved' | 'rejected'

export interface ClassChangeRequest {
  id: number
  reference: string
  student: { id: number; code: string | null; name: string } | null
  enrollment: { id: number; code: string | null; course: string | null }
  from_class: { id: number; name: string } | null
  to_class: { id: number; name: string } | null
  start_date: string
  reason: string | null
  status: ClassChangeStatus
  decided_by: string | null
  decided_at: string | null
  decision_reason: string | null
  created_at: string
  approval_flow: ApprovalFlowProgress | null
  can_approve: boolean
  can_reject: boolean
}

export interface ClassChangeOptions {
  enrollments: { id: number; code: string | null; class: { id: number; name: string } | null; course: string | null; has_pending_request: boolean }[]
  classes: { id: number; name: string; status: string; academic_year: string | null; grade_level: string | null; start_date: string | null }[]
}

export interface ClassChangeInput {
  enrollment_id: number
  to_class_id: number
  start_date: string
  reason?: string | null
}

export const myClassChangeRequestsService = {
  options: () => apiGet<ClassChangeOptions>('/my-class-change-requests/options'),
  list: () => apiGet<ClassChangeRequest[]>('/my-class-change-requests'),
  submit: (input: ClassChangeInput) => apiPost<ClassChangeRequest>('/my-class-change-requests', input),
  remove: (id: number) => apiDelete(`/my-class-change-requests/${id}`),
}

export const classChangeRequestsService = {
  list: () => apiGet<ClassChangeRequest[]>('/class-change-requests'),
  approve: (id: number) => apiPost<ClassChangeRequest>(`/class-change-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<ClassChangeRequest>(`/class-change-requests/${id}/reject`, { reason }),
}

/** "Morning A → Evening B" */
export const classChangeLabel = (r: Pick<ClassChangeRequest, 'from_class' | 'to_class'>) => `${r.from_class?.name ?? '—'} → ${r.to_class?.name ?? '—'}`
