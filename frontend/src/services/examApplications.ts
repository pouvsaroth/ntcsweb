import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { ApprovalFlowProgress } from '@/services/approvalFlows'

export type ExamApplicationStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'not_exam' | 'make_up'

export const examApplicationStatuses: ExamApplicationStatus[] = ['draft', 'pending', 'approved', 'rejected', 'not_exam', 'make_up']

export interface ExamApplicationStudent {
  id: number
  student_code: string
  name: string
  /** Only present on the lookup response — everywhere else just uses the combined `name`. */
  first_name?: string
  last_name?: string
  english_name: string | null
  gender: string | null
  date_of_birth: string | null
  phone: string | null
  address: string | null
  photo_url: string | null
  /** Only present on the lookup response — everywhere else just uses the resolved `address`/`address_parts` strings. */
  village_code?: string | null
  /** Only present on the lookup response — the grid's "Address" column uses the single `address` string instead. */
  address_parts?: { province: string | null; district: string | null; commune: string | null; village: string | null }
}

export interface ExamApplication {
  id: number
  enrollment_code: string | null
  student: ExamApplicationStudent
  enrollment: {
    id: number
    course_package: { id: number; name: string } | null
    school_class: { id: number; name: string } | null
  }
  file_code: string | null
  book: { id: number; title: string } | null
  exam_date: string | null
  exam_time: string | null
  exam_time_out: string | null
  table_no: string | null
  classroom: { id: number; name: string } | null
  table: { id: number; name: string } | null
  fee_amount: string | null
  fee_currency: string | null
  student_marked_paid_at: string | null
  status: ExamApplicationStatus
  /** Approvals queue only — see ApprovalFlowProgress. */
  approval_flow?: ApprovalFlowProgress | null
  decision_reason: string | null
  decided_by: string | null
  decided_at: string | null
  remark: string | null
  sold_at: string | null
  received_at: string | null
  paid_back_at: string | null
  /** Examination → Certificate only — the score and its mention key (see admin.myScores.mentions). */
  score?: {
    score: string
    mention: 'excellent' | 'very_good' | 'good' | 'fail'
    /** The academic year the score was entered in (null for an older score outside every year). */
    academic_year: { id: number; name: string } | null
  } | null
  /** Examination → Certificate: the day the student handed in their certificate photo. */
  photo_received_date?: string | null
  photo_received_remark?: string | null
  /** Examination → Certificate: the day the certificate was handed to the student. */
  certificate_issued_date?: string | null
  certificate_issued_remark?: string | null
  created_at: string
}

/** The Application Form's "Show Data" lookup result — a resolved enrollment plus its most recent exam application, if any. */
export interface ExamApplicationLookup {
  enrollment_id: number
  enrollment_code: string
  student: ExamApplicationStudent
  course_package: { id: number; name: string } | null
  exam_application: ExamApplication | null
}

/** Everything the Application Form can actually edit — see StoreExamApplicationRequest/UpdateExamApplicationRequest on the backend. */
export interface ExamApplicationInput {
  file_code?: string | null
  book_id?: number | null
  exam_date?: string | null
  exam_time?: string | null
  exam_time_out?: string | null
  table_no?: string | null
  classroom_id?: number | null
  table_id?: number | null
  status: ExamApplicationStatus
  remark?: string | null
}

/** The "Print" popup's fields — see RecordExamApplicationFeeRequest on the backend. */
export interface ExamApplicationFeeInput {
  fee: number
  currency?: 'USD' | 'KHR' | null
  payment_method: string
  print_date: string
}

export const examApplicationsService = {
  /**
   * `awaitingAction` is a plain top-level query param (see
   * ExamApplicationController::index()), not a `filter[...]` entry — it
   * means "draft/pending, or approved but not yet scored," which isn't a
   * single column value ApiQuery's generic filterable() could express. Used
   * by Exams.vue's default "Awaiting action" view, mutually exclusive with
   * `query.filter.status`.
   */
  async list(
    query: PaginatedQuery,
    opts: {
      awaitingAction?: boolean
      approvalQueue?: boolean
      certificate?: boolean
      photoReceived?: 'yes' | 'no'
      certificateIssued?: 'yes' | 'no'
      academicYearId?: number
    } = {},
  ): Promise<PaginatedResult<ExamApplication>> {
    const result = await apiGetWithMeta<ExamApplication[]>('/exam-applications', {
      params: {
        page: query.page,
        per_page: query.per_page,
        sort: query.sort,
        filter: query.filter,
        ...(opts.awaitingAction ? { awaiting_action: '1' } : {}),
        // The Approvals queue's view — see ApprovalFlowProgress.
        ...(opts.approvalQueue ? { approval_queue: '1' } : {}),
        // Examination → Certificate: passed students only (score ≥ 85), with their score.
        ...(opts.certificate ? { certificate: '1' } : {}),
        ...(opts.photoReceived ? { photo_received: opts.photoReceived } : {}),
        ...(opts.certificateIssued ? { certificate_issued: opts.certificateIssued } : {}),
        ...(opts.academicYearId ? { academic_year_id: opts.academicYearId } : {}),
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /**
   * The "Exam Application Approval" tab's data source — pending applications
   * a student filed themselves. `student_submitted` is a plain top-level
   * query param (see ExamApplicationController::index()), not a `filter[...]`
   * entry — ApiQuery's generic filterable() never sees it.
   */
  async listPendingStudentSubmissions(query: PaginatedQuery): Promise<PaginatedResult<ExamApplication>> {
    const result = await apiGetWithMeta<ExamApplication[]>('/exam-applications', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: { ...query.filter, status: 'pending' }, student_submitted: '1' },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  lookup: (enrollmentCode: string) =>
    apiGetWithMeta<ExamApplicationLookup>('/exam-applications/lookup', { params: { enrollment_code: enrollmentCode } }).then((r) => r.data),

  create: (enrollmentId: number, input: ExamApplicationInput) =>
    apiPost<ExamApplication>('/exam-applications', { enrollment_id: enrollmentId, ...input }),

  update: (id: number, input: ExamApplicationInput) => apiPut<ExamApplication>(`/exam-applications/${id}`, input),

  remove: (id: number) => apiDelete(`/exam-applications/${id}`),

  /** "Print": records the exam fee as a real Invoice + Payment and stamps sold_at — the actual document print happens client-side afterward. */
  recordFeeAndPrint: (id: number, input: ExamApplicationFeeInput) => apiPost<ExamApplication>(`/exam-applications/${id}/print`, input),

  receive: (ids: number[]) => apiPost<ExamApplication[]>('/exam-applications/receive', { ids }),
  payBack: (ids: number[]) => apiPost<ExamApplication[]>('/exam-applications/pay-back', { ids }),
  /** Examination → Certificate's "Received photo" — passed students only. */
  markPhotoReceived: (ids: number[], receivedDate: string, remark: string | null) =>
    apiPost<ExamApplication[]>('/exam-applications/photo-received', { ids, received_date: receivedDate, remark }),

  markCertificateIssued: (ids: number[], issuedDate: string, remark: string | null) =>
    apiPost<ExamApplication[]>('/exam-applications/certificate-issued', { ids, issued_date: issuedDate, remark }),

  approve: (id: number) => apiPost<ExamApplication>(`/exam-applications/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<ExamApplication>(`/exam-applications/${id}/reject`, { reason }),

  /** "Not Exam" — only valid on a still-draft row (see ExamApplicationService::markNotExam()). Also completes the enrollment server-side. */
  markNotExam: (id: number) => apiPost<ExamApplication>(`/exam-applications/${id}/not-exam`),
}
