import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Billing > Companies: who pays one total for a company class. */
export interface Company {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  address: string | null
  tax_number: string | null
  notes: string | null
  is_active: boolean
  classes_count?: number
  created_at: string
}

export interface CompanyInput {
  name: string
  contact_person: string
  phone: string
  email: string
  address: string
  tax_number: string
  notes: string
  is_active: boolean
}

export const companiesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Company>> {
    const result = await apiGetWithMeta<Company[]>('/companies', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active company, for the class form's "Company (paid by)" picker. */
  async listAll(): Promise<Company[]> {
    const result = await apiGetWithMeta<Company[]>('/companies', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: CompanyInput) => apiPost<Company>('/companies', input),
  update: (id: number, input: CompanyInput) => apiPut<Company>(`/companies/${id}`, input),
  remove: (id: number) => apiDelete(`/companies/${id}`),
}
