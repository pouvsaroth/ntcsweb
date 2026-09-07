import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface FormCategory {
  id: number
  name: string
  order: number
  is_active: boolean
  created_at: string
}

export interface FormCategoryInput {
  name: string
  order?: number
  is_active?: boolean
}

/** Browsing (list) is open to any authenticated user — see the backend policy's docblock. */
export const formCategoriesService = {
  async list(query: Partial<PaginatedQuery> = {}): Promise<PaginatedResult<FormCategory>> {
    const result = await apiGetWithMeta<FormCategory[]>('/form-categories', {
      params: { page: query.page, per_page: query.per_page ?? 100, sort: query.sort ?? 'order' },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: FormCategoryInput) => apiPost<FormCategory>('/form-categories', input),
  update: (id: number, input: FormCategoryInput) => apiPut<FormCategory>(`/form-categories/${id}`, input),
  remove: (id: number) => apiDelete(`/form-categories/${id}`),
}
