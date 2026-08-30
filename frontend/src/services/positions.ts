import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Position {
  id: number
  name: string
  description: string | null
  status: 'active' | 'inactive'
  role: { id: number; name: string; slug: string } | null
  staff_count?: number
  created_at: string
}

export interface PositionInput {
  name: string
  role_id: number
  description: string
  status: 'active' | 'inactive'
}

export const positionsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Position>> {
    const result = await apiGetWithMeta<Position[]>('/positions', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active position, for the Staff form's position select — a school's list of job titles is small. */
  async listAll(): Promise<Position[]> {
    const result = await apiGetWithMeta<Position[]>('/positions', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },

  create: (input: PositionInput) => apiPost<Position>('/positions', input),
  update: (id: number, input: PositionInput) => apiPut<Position>(`/positions/${id}`, input),
  remove: (id: number) => apiDelete(`/positions/${id}`),
}
