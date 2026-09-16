import { apiGet, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ExamApplicationStatus = 'pending' | 'approved' | 'rejected'

export interface MyExamApplicationEnrollment {
  id: number
  course_package: { id: number; name: string } | null
  school_class: { id: number; name: string } | null
}

export interface MyExamApplication {
  id: number
  file_code: string | null
  enrollment: { id: number; course_package: { id: number; name: string } | null; school_class: { id: number; name: string } | null }
  exam_date: string
  exam_time: string
  table_no: string
  fee_amount: string | number | null
  fee_currency: string | null
  status: ExamApplicationStatus
  decision_reason: string | null
  created_at: string
}

export interface MyExamApplicationInput {
  enrollment_id: number
  exam_date: string
  exam_time: string
  table_no: string
  has_paid: boolean
}

export interface ExamFee {
  amount: string | number | null
  currency: string | null
}

/** Student self-service — own applications only, scoped server-side. */
export const myExamApplicationsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<MyExamApplication>> {
    const result = await apiGetWithMeta<MyExamApplication[]>('/my-exam-applications', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  submit: (input: MyExamApplicationInput) => apiPost<MyExamApplication>('/my-exam-applications', input),

  enrollments: () => apiGet<MyExamApplicationEnrollment[]>('/my-exam-applications/enrollments'),

  fee: () => apiGet<ExamFee>('/my-exam-applications/fee'),
}
