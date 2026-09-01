import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface AssetCategory {
  id: number
  code: string
  name: string
  description: string | null
  parent_id: number | null
  parent: { id: number; code: string; name: string } | null
  is_active: boolean
  created_at: string
}

export interface AssetCategoryInput {
  code: string
  name: string
  description: string
  parent_id: number | null
  is_active: boolean
}

export const assetCategoriesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AssetCategory>> {
    const result = await apiGetWithMeta<AssetCategory[]>('/asset-categories', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every category, for pickers — a school's category list is small. */
  async listAll(): Promise<AssetCategory[]> {
    const result = await apiGetWithMeta<AssetCategory[]>('/asset-categories', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: AssetCategoryInput) => apiPost<AssetCategory>('/asset-categories', input),
  update: (id: number, input: AssetCategoryInput) => apiPut<AssetCategory>(`/asset-categories/${id}`, input),
  remove: (id: number) => apiDelete(`/asset-categories/${id}`),
}
