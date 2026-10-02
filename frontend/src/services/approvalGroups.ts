import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface ApprovalGroupUser {
  id: number
  name: string
  email: string
}

/** Approval Flow → Groups — a named group of users (see backend ApprovalGroupController). */
export interface ApprovalGroup {
  id: number
  name: string
  description: string | null
  members: ApprovalGroupUser[]
  created_at: string
}

export interface ApprovalGroupInput {
  name: string
  description: string | null
  /** The group's whole member list — replaces whatever it had. */
  user_ids: number[]
}

export const approvalGroupsService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<ApprovalGroup>> {
    const result = await apiGetWithMeta<ApprovalGroup[]>('/approval-groups', {
      params: { page: query.page, per_page: query.per_page ?? 25, search: query.search || undefined },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active user of this school, for the member picker. */
  users: () => apiGet<ApprovalGroupUser[]>('/approval-groups/users'),

  create: (input: ApprovalGroupInput) => apiPost<ApprovalGroup>('/approval-groups', input),
  update: (id: number, input: ApprovalGroupInput) => apiPut<ApprovalGroup>(`/approval-groups/${id}`, input),
  remove: (id: number) => apiDelete(`/approval-groups/${id}`),
}
