import { apiGet, apiPut } from '@/services/http'

/** Every item that can have an approval flow — mirrors backend App\Support\Approvals\DocumentType. */
export const APPROVAL_DOCUMENT_TYPES = ['student_leave', 'staff_leave', 'resignation', 'make_up_class', 'exam_application', 'form_request'] as const

export type ApprovalDocumentType = (typeof APPROVAL_DOCUMENT_TYPES)[number]

export interface ApprovalFlowStep {
  step_order: number
  group: { id: number; name: string | null; member_count: number }
}

/** One item's flow — an empty `steps` list means no flow (the approve permission decides). */
export interface ApprovalFlow {
  document_type: ApprovalDocumentType
  steps: ApprovalFlowStep[]
}

/**
 * On a pending row in the Approvals queue whose item has a flow: which step
 * it waits on, and whether the signed-in user (a member of that step's
 * group) can approve/reject it now. Absent/null otherwise.
 */
export interface ApprovalFlowProgress {
  step: number
  total: number
  group: string | null
  can_act: boolean
}

export const approvalFlowsService = {
  list: () => apiGet<ApprovalFlow[]>('/approval-flows'),
  /** Replaces the item's whole flow — `groupIds` in approval order; empty removes the flow. */
  save: (type: ApprovalDocumentType, groupIds: number[]) => apiPut<ApprovalFlow[]>(`/approval-flows/${type}`, { group_ids: groupIds }),
}
