import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { AcademicProgram } from '@/services/academicPrograms'
import type { Classroom } from '@/services/classrooms'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ClassStatus = 'upcoming' | 'active' | 'completed' | 'cancelled'

export const classStatuses: ClassStatus[] = ['upcoming', 'active', 'completed', 'cancelled']

export interface ClassSchedule {
  id?: number
  day_of_week: number
  day_name?: string
  start_time: string
  end_time: string
}

export interface SchoolClass {
  id: number
  name: string
  code: string | null
  capacity: number | null
  start_date: string | null
  end_date: string | null
  status: ClassStatus
  /** Staff members holding the "Teacher" position, assigned as this class's main teacher(s). */
  teachers: { id: number; name: string }[]
  /** Same eligibility as `teachers`, but tagged as an assistant rather than a main teacher — see class_teachers.role on the backend. */
  assistant_teachers: { id: number; name: string }[]
  classroom: Classroom | null
  schedules: ClassSchedule[]
  /** Which Academic Program this session belongs to — required before a package-based enrollment can target this class. */
  academic_program_id: number | null
  academic_program: AcademicProgram | null
  enrollments_count?: number
  created_at: string
}

export interface ClassInput {
  name: string
  code: string
  teacher_ids: number[]
  assistant_teacher_ids: number[]
  classroom_id: number | null
  academic_program_id: number | null
  start_date: string
  end_date: string
  status: ClassStatus
  schedules: { day_of_week: number; start_time: string; end_time: string }[]
}

export const classesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<SchoolClass>> {
    const result = await apiGetWithMeta<SchoolClass[]>('/classes', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<SchoolClass>(`/classes/${id}`).then((r) => r.data),

  /** Which tables in this class's room are still free — drives the enrollment form's seat picker. `total_tables` is 0 when the room has none configured (nothing to require). */
  availableTables: (id: number) =>
    apiGetWithMeta<{ total_tables: number; available: { id: number; name: string }[] }>(`/classes/${id}/available-tables`).then((r) => r.data),

  /** All active classes, for the enrollment form's class picker. */
  async listAll(): Promise<SchoolClass[]> {
    const result = await apiGetWithMeta<SchoolClass[]>('/classes', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },

  create: (input: ClassInput) => apiPost<SchoolClass>('/classes', input),
  update: (id: number, input: ClassInput) => apiPut<SchoolClass>(`/classes/${id}`, input),
  remove: (id: number) => apiDelete(`/classes/${id}`),
}
