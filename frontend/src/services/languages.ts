import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Language {
  id: number
  code: string
  name: string
  native_name: string
  is_active: boolean
  is_default: boolean
  sort_order: number
  created_at: string
}

export interface LanguageInput {
  code: string
  name: string
  native_name: string
  is_active: boolean
  is_default: boolean
  sort_order: number
}

export const languagesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Language>> {
    const result = await apiGetWithMeta<Language[]>('/languages', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async listAll(): Promise<Language[]> {
    const result = await apiGetWithMeta<Language[]>('/languages', { params: { per_page: 200, sort: 'sort_order' } })
    return result.data
  },

  create: (input: Partial<LanguageInput>) => apiPost<Language>('/languages', input),
  update: (id: number, input: Partial<LanguageInput>) => apiPut<Language>(`/languages/${id}`, input),
  remove: (id: number) => apiDelete(`/languages/${id}`),
}
