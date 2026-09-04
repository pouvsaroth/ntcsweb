import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { AcademicProgram } from '@/services/academicPrograms'
import type { Book } from '@/services/books'
import type { Classroom } from '@/services/classrooms'
import type { CoursePackage } from '@/services/coursePackages'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ClassStatus = 'upcoming' | 'active' | 'completed' | 'cancelled'

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
  /** A Staff member holding the "Teacher" position — see TeacherPositionSeeder on the backend. */
  teacher: { id: number; name: string } | null
  classroom: Classroom | null
  schedules: ClassSchedule[]
  /** The session's book menu — which books this class offers, not "the curriculum everyone shares" (see docs/database.md). */
  books: Book[]
  /** Which Academic Program this session belongs to — required before a package-based enrollment can target this class. */
  academic_program_id: number | null
  academic_program: AcademicProgram | null
  /** The session's course-package menu — mirrors `books` but for the package-based (Computer-class) enrollment path. */
  course_packages: CoursePackage[]
  enrollments_count?: number
  created_at: string
}

export interface ClassInput {
  name: string
  code: string
  teacher_id: number | null
  classroom_id: number | null
  academic_program_id: number | null
  start_date: string
  end_date: string
  status: ClassStatus
  schedules: { day_of_week: number; start_time: string; end_time: string }[]
  /** Which course packages this class offers — required for a package-based (Computer-class) enrollment, and for it to appear in the public registration wizard's Schedule step. */
  course_package_ids: number[]
}

export const classesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<SchoolClass>> {
    const result = await apiGetWithMeta<SchoolClass[]>('/classes', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
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
