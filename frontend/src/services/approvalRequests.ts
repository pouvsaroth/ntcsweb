import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ApprovalRequestStatus = 'pending' | 'approved' | 'rejected'

export interface ApprovalRequest {
  id: number
  reference: string
  form_template_id: number
  template_code: string | null
  template_name: string | null
  requested_by: string | null
  subject: string
  details: string | null
  status: ApprovalRequestStatus
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  created_at: string
}

export interface ApprovalRequestInput {
  form_template_id: number
  subject: string
  details?: string | null
}

/** Self-service: "my requests" — own rows only, scoped server-side. See Forms.vue/MyRequests.vue. */
export const myApprovalRequestsService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<ApprovalRequest>> {
    const result = await apiGetWithMeta<ApprovalRequest[]>('/my-approval-requests', {
      params: { page: query.page ?? 1, per_page: query.per_page ?? 100, sort: query.sort ?? '-created_at', filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: ApprovalRequestInput) => apiPost<ApprovalRequest>('/my-approval-requests', input),
}

/** Admin/approver queue — see Approvals.vue under eApprovals. */
export const approvalRequestsService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<ApprovalRequest>> {
    const result = await apiGetWithMeta<ApprovalRequest[]>('/approval-requests', {
      params: { page: query.page ?? 1, per_page: query.per_page ?? 100, sort: query.sort ?? '-created_at', filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  approve: (id: number) => apiPost<ApprovalRequest>(`/approval-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<ApprovalRequest>(`/approval-requests/${id}/reject`, { reason }),
}
