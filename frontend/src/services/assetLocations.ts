import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type AssetLocationType = 'CAMPUS' | 'BUILDING' | 'FLOOR' | 'ROOM' | 'OTHER'

export const assetLocationTypes: AssetLocationType[] = ['CAMPUS', 'BUILDING', 'FLOOR', 'ROOM', 'OTHER']

export interface AssetLocation {
  id: number
  code: string
  name: string
  type: AssetLocationType
  parent_id: number | null
  parent: { id: number; code: string; name: string } | null
  classroom_id: number | null
  is_active: boolean
  created_at: string
}

export interface AssetLocationInput {
  code: string
  name: string
  type: AssetLocationType
  parent_id: number | null
  classroom_id: number | null
  is_active: boolean
}

export const assetLocationsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AssetLocation>> {
    const result = await apiGetWithMeta<AssetLocation[]>('/asset-locations', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every location, for pickers — a school's location list is small. */
  async listAll(): Promise<AssetLocation[]> {
    const result = await apiGetWithMeta<AssetLocation[]>('/asset-locations', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: AssetLocationInput) => apiPost<AssetLocation>('/asset-locations', input),
  update: (id: number, input: AssetLocationInput) => apiPut<AssetLocation>(`/asset-locations/${id}`, input),
  remove: (id: number) => apiDelete(`/asset-locations/${id}`),
}
