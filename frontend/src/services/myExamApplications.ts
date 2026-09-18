import { apiGet, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

// Never 'draft' — the backend excludes a not-yet-applied-to row from this
// student's own list entirely (see MyExamApplicationController::index()).
export type ExamApplicationStatus = 'pending' | 'approved' | 'rejected' | 'not_exam'

export interface MyExamApplicationEnrollment {
  id: number
  course_package: { id: number; name: string } | null
  school_class: { id: number; name: string } | null
}

export interface MyExamApplication {
  id: number
  file_code: string | null
  student: {
    id: number
    student_code: string
    name: string
    english_name: string | null
    gender: string | null
    date_of_birth: string | null
    phone: string | null
    address: string | null
    photo_url: string | null
  } | null
  enrollment: { id: number; course_package: { id: number; name: string } | null; school_class: { id: number; name: string } | null }
  book: { id: number; title: string } | null
  exam_date: string | null
  exam_time: string | null
  exam_time_out: string | null
  table_no: string | null
  classroom: { id: number; name: string } | null
  table: { id: number; name: string } | null
  remark: string | null
  fee_amount: string | number | null
  fee_currency: string | null
  status: ExamApplicationStatus
  decision_reason: string | null
  sold_at: string | null
  received_at: string | null
  created_at: string
}

/**
 * The "Show Data" step's result once a student picks a course — their own
 * personal info (editable) plus whatever exam_application already exists
 * for that enrollment (display-only: null exam-day fields if a teacher
 * hasn't sent them to exam yet, or already-set ones if a teacher has —
 * either way the student never edits book/room/table/date, see
 * ExamApplicationService::applyOnline() on the backend).
 */
export interface MyExamApplicationLookup {
  enrollment_id: number
  student: {
    first_name: string
    last_name: string
    english_name: string | null
    gender: string | null
    date_of_birth: string | null
    phone: string | null
    village_code: string | null
    address: string | null
    photo_url: string | null
  }
  course_package: { id: number; name: string } | null
  exam_application: MyExamApplication | null
}

export interface MyExamApplicationInput {
  enrollment_id: number
  first_name: string
  last_name: string
  english_name: string
  gender: string
  date_of_birth: string
  phone: string
  village_code: string
  photo?: File | null
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

  /** Multipart — `photo` is only appended when the student actually picked a new file. */
  submit(input: MyExamApplicationInput) {
    const form = new FormData()
    form.append('enrollment_id', String(input.enrollment_id))
    form.append('first_name', input.first_name)
    form.append('last_name', input.last_name)
    form.append('english_name', input.english_name)
    form.append('gender', input.gender)
    form.append('date_of_birth', input.date_of_birth)
    form.append('phone', input.phone)
    form.append('village_code', input.village_code)
    form.append('has_paid', input.has_paid ? '1' : '0')
    if (input.photo) form.append('photo', input.photo)

    return apiPost<MyExamApplication>('/my-exam-applications', form)
  },

  enrollments: () => apiGet<MyExamApplicationEnrollment[]>('/my-exam-applications/enrollments'),

  lookup: (enrollmentId: number) => apiGet<MyExamApplicationLookup>(`/my-exam-applications/lookup/${enrollmentId}`),

  fee: () => apiGet<ExamFee>('/my-exam-applications/fee'),
}
