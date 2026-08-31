import { apiDelete, apiGetWithMeta, apiPostWithMeta, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { Position } from '@/services/positions'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Staff {
  id: number
  employee_code: string
  name: string
  email: string | null
  phone: string
  hire_date: string | null
  status: 'active' | 'inactive'
  position: Position | null
  user: { id: number; email: string | null; phone: string | null; status: string } | null
  created_at: string
}

export interface StaffInput {
  employee_code: string
  name: string
  phone: string
  email: string
  position_id: number | null
  hire_date: string
  status: 'active' | 'inactive'
}

export interface StaffCreated {
  staff: Staff
  /** Shown once, right after creation — see UserProvisioningService on the backend. */
  temporaryPassword: string | null
}

export const staffService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Staff>> {
    const result = await apiGetWithMeta<Staff[]>('/staff', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async create(input: StaffInput): Promise<StaffCreated> {
    const result = await apiPostWithMeta<Staff>('/staff', input)
    return { staff: result.data, temporaryPassword: (result.meta?.temporary_password as string) ?? null }
  },

  update: (id: number, input: Partial<StaffInput>) => apiPut<Staff>(`/staff/${id}`, input),
  remove: (id: number) => apiDelete(`/staff/${id}`),
}
