import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface ClassroomTable {
  id: number
  name: string
  sort_order: number
  /** Several students may sit here at once; never shown as taken. */
  allow_many_students: boolean
  classroom_id: number
  classroom?: { id: number; name: string } | null
  created_at: string
}

export interface ClassroomTableInput {
  name: string
  sort_order?: number
  allow_many_students?: boolean
  classroom_id: number
}

/** A numbered range (prefix + 01..N) or a copy of another room's tables. */
export interface BulkTablesInput {
  classroom_id: number
  copy_from_classroom_id?: number | null
  prefix?: string | null
  count?: number | null
  start_number?: number
  allow_many_students?: boolean
}

export interface BulkTablesResult {
  created: number
  skipped: number
}

export const classroomTablesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<ClassroomTable>> {
    const result = await apiGetWithMeta<ClassroomTable[]>('/classroom-tables', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: ClassroomTableInput) => apiPost<ClassroomTable>('/classroom-tables', input),
  bulk: (input: BulkTablesInput) => apiPost<BulkTablesResult>('/classroom-tables/bulk', input),
  update: (id: number, input: Partial<ClassroomTableInput>) => apiPut<ClassroomTable>(`/classroom-tables/${id}`, input),
  remove: (id: number) => apiDelete(`/classroom-tables/${id}`),

  /** Every table in one room — independent of any class/enrollment context (an exam can sit in a different room than the student's regular class). */
  async listByClassroom(classroomId: number): Promise<ClassroomTable[]> {
    const result = await apiGetWithMeta<ClassroomTable[]>('/classroom-tables', { params: { per_page: 200, filter: { classroom_id: classroomId } } })
    return result.data
  },
}
