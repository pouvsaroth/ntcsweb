import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { AcademicProgram } from '@/services/academicPrograms'
import type { Book } from '@/services/books'
import type { SchoolClass } from '@/services/classes'
import type { CoursePackage } from '@/services/coursePackages'
import type { StudyMode } from '@/services/studyModes'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * `dropped` is never chosen from the status-management menu — it only ever
 * happens as a side effect of EnrollmentService::cancel()/transferClass()
 * (a superseded row). `not_started`/`active`/`exam_ready`/`completed` are
 * routine; `abandoned`/`stopped`/`suspended` require a reason + effective
 * date — see STATUSES_REQUIRING_REASON below.
 */
export type EnrollmentStatus = 'not_started' | 'active' | 'exam_ready' | 'completed' | 'abandoned' | 'stopped' | 'suspended' | 'dropped'

/** Mirrors Enrollment::STATUSES_MANAGEABLE on the backend — the options the "change status" menu actually offers. */
export const enrollmentStatusesManageable: EnrollmentStatus[] = [
  'not_started', 'active', 'exam_ready', 'completed', 'abandoned', 'stopped', 'suspended',
]

/** Mirrors Enrollment::STATUSES_REQUIRING_REASON on the backend. */
export const enrollmentStatusesRequiringReason: EnrollmentStatus[] = ['abandoned', 'stopped', 'suspended']

export interface EnrollmentStatusHistoryEntry {
  id: number
  from_status: EnrollmentStatus
  to_status: EnrollmentStatus
  reason: string | null
  effective_date: string | null
  changed_by: string | null
  created_at: string
}

export interface EnrollmentStudent {
  id: number
  full_name: string
  student_code: string
}

export type FeeType = 'monthly' | 'term' | 'video' | 'monthly_online' | 'term_online'

export interface Enrollment {
  id: number
  enrolled_at: string
  /** Snapshotted at enrollment time — editing it never touches the book's/package's own catalog price, see docs/database.md. */
  fee: number
  /** Which of the package's 5 fee tiers this was billed under — null for the legacy book-billed path. */
  fee_type: FeeType | null
  status: EnrollmentStatus
  /** Set only for a status in enrollmentStatusesRequiringReason. */
  status_reason: string | null
  status_effective_date: string | null
  /** Whether any money has been received against this enrollment — gates changing the course (not the class) via transfer(). */
  is_paid: boolean
  student: EnrollmentStudent
  class: SchoolClass
  /** Which physical table in the class's classroom this student sits at — null when the room has no tables configured. */
  table_id: number | null
  table: { id: number; name: string } | null
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
  table_id: number | null
  book_id: number
  enrolled_at: string
  fee: number
  status: EnrollmentStatus
}

/**
 * Deliberately has no fee/total field — the server computes it from the
 * package's `fee_type` tier, see EnrollmentService::enrollInPackage().
 * `discount_price`/`received_amount` are genuinely client-supplied (no
 * catalog value to derive them from), but both are capped server-side.
 */
export interface EnrollmentPackageInput {
  student_id: number
  class_id: number
  table_id: number | null
  course_package_id: number
  enrolled_at: string
  fee_type: FeeType
  discount_reason: string | null
  discount_price: number
  received_amount: number
  payment_method: string | null
}

export const enrollmentsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Enrollment>> {
    const result = await apiGetWithMeta<Enrollment[]>('/enrollments', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: EnrollmentInput) => apiPost<Enrollment>('/enrollments', input),
  /** Status isn't editable here — see changeStatus() below, the one path that also logs history. */
  update: (id: number, input: Pick<EnrollmentInput, 'enrolled_at' | 'fee'>) => apiPut<Enrollment>(`/enrollments/${id}`, input),
  remove: (id: number) => apiDelete(`/enrollments/${id}`),

  enrollInPackage: (input: EnrollmentPackageInput) => apiPost<Enrollment>('/enrollments/package', input),
  cancel: (id: number, reason: string) => apiPost<Enrollment>(`/enrollments/${id}/cancel`, { reason }),

  /**
   * Omitting `course_package_id` (or passing the enrollment's current one)
   * moves only the class/room — always allowed. Passing a *different*
   * package also changes the course, which the backend refuses once
   * anything has been paid (see Enrollment.is_paid).
   */
  transfer: (id: number, input: { class_id: number; table_id?: number | null; course_package_id?: number | null; fee_type?: FeeType | null }) =>
    apiPost<Enrollment>(`/enrollments/${id}/transfer`, input),

  changeStatus: (id: number, input: { status: EnrollmentStatus; reason?: string | null; effective_date?: string | null }) =>
    apiPost<Enrollment>(`/enrollments/${id}/status`, input),

  statusHistory: (id: number) =>
    apiGetWithMeta<EnrollmentStatusHistoryEntry[]>(`/enrollments/${id}/status-history`).then((r) => r.data),
}
