import { apiGet, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ResignationRequestStatus = 'pending' | 'approved' | 'rejected'

export interface ResignationRequestStaff {
  id: number
  name: string
  gender: string | null
  position: string | null
}

export interface ResignationRequest {
  id: number
  staff?: ResignationRequestStaff
  resignation_date: string
  reason: string
  status: ResignationRequestStatus
  decision_reason: string | null
  decided_by?: string | null
  decided_at: string | null
  created_at: string
}

export interface ResignationRequestInput {
  resignation_date: string
  reason: string
}

/** The resignation form's read-only auto-filled identity fields — see MyResignationRequestController::profile(). */
export interface MyStaffProfile {
  first_name: string | null
  last_name: string | null
  gender: string | null
  position: string | null
}

/** Staff self-service — own requests only, scoped server-side. See ResignationFormModal.vue. */
export const myResignationRequestsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<ResignationRequest>> {
    const result = await apiGetWithMeta<ResignationRequest[]>('/my-resignation-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: ResignationRequestInput) => apiPost<ResignationRequest>('/my-resignation-requests', input),

  profile: () => apiGet<MyStaffProfile>('/my-resignation-requests/profile'),
}

/** Admin approve/reject queue — see the eApprovals "Approvals" page. */
export const resignationRequestsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<ResignationRequest>> {
    const result = await apiGetWithMeta<ResignationRequest[]>('/resignation-requests', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<ResignationRequest>(`/resignation-requests/${id}`).then((r) => r.data),
  approve: (id: number) => apiPost<ResignationRequest>(`/resignation-requests/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<ResignationRequest>(`/resignation-requests/${id}/reject`, { reason }),
}
