import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { AcademicProgram } from '@/services/academicPrograms'
import type { Book } from '@/services/books'
import type { SchoolClass } from '@/services/classes'
import type { CoursePackage } from '@/services/coursePackages'
import type { StudyMode } from '@/services/studyModes'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type EnrollmentStatus = 'active' | 'completed' | 'dropped'

export interface EnrollmentStudent {
  id: number
  full_name: string
  student_code: string
}

export interface Enrollment {
  id: number
  enrolled_at: string
  /** Snapshotted at enrollment time — editing it never touches the book's/package's own catalog price, see docs/database.md. */
  fee: number
  status: EnrollmentStatus
  student: EnrollmentStudent
  class: SchoolClass
  /** Set for the legacy book-billed path; null (or omitted from the package-enrollment response) for a package-billed enrollment. */
  book?: Book | null
  course_package_id: number | null
  course_package: CoursePackage | null
  academic_program_id: number | null
  academic_program: AcademicProgram | null
  study_mode_id: number | null
  study_mode: StudyMode | null
  created_at: string
}

export interface EnrollmentInput {
  student_id: number
  class_id: number
  book_id: number
  enrolled_at: string
  fee: number
  status: EnrollmentStatus
}

/** Deliberately has no fee/total field — the server computes it from the package's current price, see EnrollmentService::enrollInPackage(). */
export interface EnrollmentPackageInput {
  student_id: number
  class_id: number
  course_package_id: number
  enrolled_at: string
}

export const enrollmentsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Enrollment>> {
    const result = await apiGetWithMeta<Enrollment[]>('/enrollments', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: EnrollmentInput) => apiPost<Enrollment>('/enrollments', input),
  update: (id: number, input: Pick<EnrollmentInput, 'enrolled_at' | 'fee' | 'status'>) =>
    apiPut<Enrollment>(`/enrollments/${id}`, input),
  remove: (id: number) => apiDelete(`/enrollments/${id}`),

  enrollInPackage: (input: EnrollmentPackageInput) => apiPost<Enrollment>('/enrollments/package', input),
  cancel: (id: number, reason: string) => apiPost<Enrollment>(`/enrollments/${id}/cancel`, { reason }),
  transfer: (id: number, classId: number) => apiPost<Enrollment>(`/enrollments/${id}/transfer`, { class_id: classId }),
}
