import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { LookupCategory } from '@/services/lookupCategories'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface LookupValueTranslationFields {
  name: string
  description: string
}

/** Admin/translation-management shape — every configured language is present, even with an empty name/description if untranslated. */
export interface LookupValue {
  id: number
  code: string
  lookup_category_id: number
  category: LookupCategory | null
  is_active: boolean
  sort_order: number
  translations: Record<string, LookupValueTranslationFields>
  created_at: string
}

export interface LookupValueInput {
  lookup_category_id: number
  code: string
  is_active: boolean
  sort_order: number
  translations: Record<string, Partial<LookupValueTranslationFields>>
}

export const lookupValuesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<LookupValue>> {
    const result = await apiGetWithMeta<LookupValue[]>('/lookup-values', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: Partial<LookupValueInput>) => apiPost<LookupValue>('/lookup-values', input),
  update: (id: number, input: Partial<LookupValueInput>) => apiPut<LookupValue>(`/lookup-values/${id}`, input),
  remove: (id: number) => apiDelete(`/lookup-values/${id}`),
}
