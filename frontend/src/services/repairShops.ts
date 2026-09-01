import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface RepairShop {
  id: number
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  address: string | null
  specialization: string | null
  notes: string | null
  is_active: boolean
  created_at: string
}

export interface RepairShopInput {
  name: string
  contact_person: string
  phone: string
  email: string
  address: string
  specialization: string
  notes: string
  is_active: boolean
}

export const repairShopsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<RepairShop>> {
    const result = await apiGetWithMeta<RepairShop[]>('/repair-shops', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every repair shop, for the "send to repair" picker — a school's list of repair shops is small. */
  async listAll(): Promise<RepairShop[]> {
    const result = await apiGetWithMeta<RepairShop[]>('/repair-shops', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: RepairShopInput) => apiPost<RepairShop>('/repair-shops', input),
  update: (id: number, input: RepairShopInput) => apiPut<RepairShop>(`/repair-shops/${id}`, input),
  remove: (id: number) => apiDelete(`/repair-shops/${id}`),
}
