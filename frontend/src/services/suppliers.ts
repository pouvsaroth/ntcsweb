import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Supplier {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  address: string | null
  notes: string | null
  is_active: boolean
  created_at: string
}

export interface SupplierInput {
  name: string
  contact_person: string
  phone: string
  email: string
  address: string
  notes: string
  is_active: boolean
}

export const suppliersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Supplier>> {
    const result = await apiGetWithMeta<Supplier[]>('/suppliers', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every supplier, for the Asset form's supplier picker — a school's supplier list is small. */
  async listAll(): Promise<Supplier[]> {
    const result = await apiGetWithMeta<Supplier[]>('/suppliers', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: SupplierInput) => apiPost<Supplier>('/suppliers', input),
  update: (id: number, input: SupplierInput) => apiPut<Supplier>(`/suppliers/${id}`, input),
  remove: (id: number) => apiDelete(`/suppliers/${id}`),
}
