import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type BuildingStatus = 'active' | 'inactive'

export interface Building {
  id: number
  name: string
  code: string | null
  address: string | null
  status: BuildingStatus
  classrooms_count?: number
  created_at: string
}

export interface BuildingInput {
  name: string
  code: string
  address: string
  status: BuildingStatus
}

export const buildingsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Building>> {
    const result = await apiGetWithMeta<Building[]>('/buildings', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active building, for a select dropdown — a school's building list is small. */
  async listAll(): Promise<Building[]> {
    const result = await apiGetWithMeta<Building[]>('/buildings', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },

  create: (input: BuildingInput) => apiPost<Building>('/buildings', input),
  update: (id: number, input: BuildingInput) => apiPut<Building>(`/buildings/${id}`, input),
  remove: (id: number) => apiDelete(`/buildings/${id}`),
}
