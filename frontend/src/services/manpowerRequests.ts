import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { ApprovalFlowProgress } from '@/services/approvalFlows'

/**
 * HRM > Recruitment > Manpower request — a request to hire, decided in
 * E-Approvals > Approvals (see ManpowerRequestController on the backend).
 */
export type ManpowerRequestStatus = 'pending' | 'approved' | 'rejected'

export const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'internship'] as const
export type EmploymentType = (typeof EMPLOYMENT_TYPES)[number]

export interface ManpowerRequest {
  id: number
  /** "MP-000012". */
  reference: string
  department_id: number | null
  department?: string | null
  position_id: number | null
  position?: string | null
  job_title: string
  headcount: number
  employment_type: EmploymentType
  needed_by: string | null
  reason: string
  requirements: string | null
  requested_by?: string | null
  status: ManpowerRequestStatus
  /** Approvals queue only — see ApprovalFlowProgress. */
  approval_flow?: ApprovalFlowProgress | null
  /** Jobs opened for it — see JobPositions.vue's `?from=`. */
  job_positions_count?: number
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  created_at: string
}

export interface ManpowerRequestInput {
  department_id: number | null
  position_id: number | null
  job_title: string
  headcount: number
  employment_type: EmploymentType
  needed_by: string | null
  reason: string
  requirements: string
}

export const manpowerRequestsService = {
  /** `approvalQueue`: the Approvals queue's view — a pending request of an item with an approval flow is only listed to the group it waits on. */
  async list(query: PaginatedQuery, opts: { approvalQueue?: boolean } = {}): Promise<PaginatedResult<ManpowerRequest>> {
    const result = await apiGetWithMeta<ManpowerRequest[]>('/manpower-requests', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        ...(opts.approvalQueue ? { approval_queue: '1' } : {}),
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<ManpowerRequest>(`/manpower-requests/${id}`).then((r) => r.data),
  create: (input: ManpowerRequestInput) => apiPost<ManpowerRequest>('/manpower-requests', input),
  update: (id: number, input: ManpowerRequestInput) => apiPut<ManpowerRequest>(`/manpower-requests/${id}`, input),
  remove: (id: number) => apiDelete(`/manpower-requests/${id}`),
  approve: (id: number) => apiPost<ManpowerRequest>(`/manpower-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<ManpowerRequest>(`/manpower-requests/${id}/reject`, { reason }),
}
