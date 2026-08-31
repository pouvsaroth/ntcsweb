import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { Book } from '@/services/books'
import type { SchoolClass } from '@/services/classes'
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
  /** Snapshotted at enrollment time — editing it never touches the book's own catalog fee, see docs/database.md. */
  fee: number
  status: EnrollmentStatus
  student: EnrollmentStudent
  class: SchoolClass
  book: Book
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
}
