import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface LookupCategory {
  id: number
  code: string
  name: string
  description: string | null
  is_active: boolean
  sort_order: number
  values_count?: number
  created_at: string
}

export interface LookupCategoryInput {
  code: string
  name: string
  description: string
  is_active: boolean
  sort_order: number
}

export const lookupCategoriesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<LookupCategory>> {
    const result = await apiGetWithMeta<LookupCategory[]>('/lookup-categories', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<LookupCategory>(`/lookup-categories/${id}`).then((r) => r.data),
  create: (input: Partial<LookupCategoryInput>) => apiPost<LookupCategory>('/lookup-categories', input),
  update: (id: number, input: Partial<LookupCategoryInput>) => apiPut<LookupCategory>(`/lookup-categories/${id}`, input),
  remove: (id: number) => apiDelete(`/lookup-categories/${id}`),
}
