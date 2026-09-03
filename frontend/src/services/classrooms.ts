import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ClassroomStatus = 'active' | 'inactive'

export interface Classroom {
  id: number
  name: string
  code: string | null
  capacity: number | null
  location: string | null
  building_id: number | null
  building: { id: number; name: string } | null
  status: ClassroomStatus
  classes_count?: number
  created_at: string
}

export interface ClassroomInput {
  name: string
  code: string
  capacity: number | null
  location: string
  building_id: number | null
  status: ClassroomStatus
}

export const classroomsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Classroom>> {
    const result = await apiGetWithMeta<Classroom[]>('/classrooms', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Classroom>(`/classrooms/${id}`).then((r) => r.data),

  /** All active classrooms, for a select dropdown — the table is small, no pagination UI needed here. */
  async listAll(): Promise<Classroom[]> {
    const result = await apiGetWithMeta<Classroom[]>('/classrooms', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },

  create: (input: ClassroomInput) => apiPost<Classroom>('/classrooms', input),
  update: (id: number, input: ClassroomInput) => apiPut<Classroom>(`/classrooms/${id}`, input),
  remove: (id: number) => apiDelete(`/classrooms/${id}`),
}
