import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface ClassroomTable {
  id: number
  name: string
  classroom_id: number
  classroom?: { id: number; name: string } | null
  created_at: string
}

export interface ClassroomTableInput {
  name: string
  classroom_id: number
}

export const classroomTablesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<ClassroomTable>> {
    const result = await apiGetWithMeta<ClassroomTable[]>('/classroom-tables', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: ClassroomTableInput) => apiPost<ClassroomTable>('/classroom-tables', input),
  update: (id: number, input: ClassroomTableInput) => apiPut<ClassroomTable>(`/classroom-tables/${id}`, input),
  remove: (id: number) => apiDelete(`/classroom-tables/${id}`),
}
