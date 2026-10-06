import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Organization Management's simple lists — each is the same
 * code/name/description/active record (Branch adds phone/address), served
 * by one backend controller (OrganizationUnitController), so one service
 * covers them all, keyed by the API path.
 */
export type OrganizationUnitKind = 'branches' | 'teams' | 'job-grades' | 'job-levels'

export interface OrganizationUnit {
  id: number
  code: string
  name: string
  description: string | null
  is_active: boolean
  /** Branch only. */
  phone?: string | null
  /** Branch only. */
  address?: string | null
  /** Team only — the Department it sits in. */
  department_id?: number | null
  department?: { id: number; name: string } | null
  created_at: string
}

export interface OrganizationUnitInput {
  code: string
  name: string
  description: string
  is_active: boolean
  phone?: string
  address?: string
  department_id?: number | null
}

export function organizationUnitsService(kind: OrganizationUnitKind) {
  return {
    async list(query: PaginatedQuery): Promise<PaginatedResult<OrganizationUnit>> {
      const result = await apiGetWithMeta<OrganizationUnit[]>(`/${kind}`, {
        params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
      })
      return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
    },

    /** Every active one, for pickers — these lists are small. */
    async listAll(): Promise<OrganizationUnit[]> {
      const result = await apiGetWithMeta<OrganizationUnit[]>(`/${kind}`, { params: { per_page: 200, filter: { is_active: 'true' } } })
      return result.data
    },

    create: (input: OrganizationUnitInput) => apiPost<OrganizationUnit>(`/${kind}`, input),
    update: (id: number, input: OrganizationUnitInput) => apiPut<OrganizationUnit>(`/${kind}/${id}`, input),
    remove: (id: number) => apiDelete(`/${kind}/${id}`),
  }
}
