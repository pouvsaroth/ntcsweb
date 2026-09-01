import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Department {
  id: number
  code: string
  name: string
  description: string | null
  is_active: boolean
  created_at: string
}

export interface DepartmentInput {
  code: string
  name: string
  description: string
  is_active: boolean
}

export const departmentsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Department>> {
    const result = await apiGetWithMeta<Department[]>('/departments', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every department, for pickers — a school's department list is small. */
  async listAll(): Promise<Department[]> {
    const result = await apiGetWithMeta<Department[]>('/departments', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: DepartmentInput) => apiPost<Department>('/departments', input),
  update: (id: number, input: DepartmentInput) => apiPut<Department>(`/departments/${id}`, input),
  remove: (id: number) => apiDelete(`/departments/${id}`),
}
