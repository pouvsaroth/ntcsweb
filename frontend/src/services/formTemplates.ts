import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface FormTemplate {
  id: number
  form_category_id: number
  category: string | null
  code: string
  name: string
  description: string | null
  order: number
  is_active: boolean
  created_at: string
}

export interface FormTemplateInput {
  form_category_id: number
  code: string
  name: string
  description?: string | null
  order?: number
  is_active?: boolean
}

/** Browsing (list) is open to any authenticated user — see the backend policy's docblock. */
export const formTemplatesService = {
  async list(query: Partial<PaginatedQuery> & { categoryId?: number } = {}): Promise<PaginatedResult<FormTemplate>> {
    const result = await apiGetWithMeta<FormTemplate[]>('/form-templates', {
      params: {
        page: query.page,
        per_page: query.per_page ?? 100,
        sort: query.sort ?? 'order',
        filter: query.categoryId ? { form_category_id: String(query.categoryId) } : undefined,
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: FormTemplateInput) => apiPost<FormTemplate>('/form-templates', input),
  update: (id: number, input: FormTemplateInput) => apiPut<FormTemplate>(`/form-templates/${id}`, input),
  remove: (id: number) => apiDelete(`/form-templates/${id}`),
}
