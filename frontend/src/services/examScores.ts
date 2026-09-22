import { apiGetWithMeta, apiPost } from '@/services/http'

/** One Grades-tab row: an approved exam application plus its score (null until entered). See ExamScoreEntryResource. */
export interface ExamScoreEntry {
  exam_application_id: number
  enrollment_code: string | null
  student: {
    id: number
    student_code: string
    name: string
    english_name: string | null
    gender: string | null
  } | null
  course_package: { id: number; name: string } | null
  school_class: { id: number; name: string } | null
  book: { id: number; title: string } | null
  exam_date: string | null
  /** Laravel's `decimal:2` cast serializes as a string, e.g. "87.50". */
  score: string | null
  remark: string | null
  recorded_by: string | null
  recorded_at: string | null
  /** Whether a STATUS_MAKE_UP application was already generated from this row (see ExamScoreService::record()'s `make_up` entry flag) — drives the Make-up Exam checkbox's checked+disabled state. */
  has_make_up: boolean
}

/** One real Course/Class/Book combination that has at least one scoreable application — see ExamScoreService::options(). */
export interface ExamScoreOption {
  course_package: { id: number; name: string }
  school_class: { id: number; name: string }
  book: { id: number; title: string } | null
}

export interface ExamScoreFilters {
  course_package_id?: number | null
  class_id?: number | null
  book_id?: number | null
  student_id?: number | null
  scored?: boolean | null
  search?: string
}

export interface ExamScoreEntryInput {
  exam_application_id: number
  /** null clears an existing score rather than storing one. */
  score: number | null
  remark?: string | null
  /** Generates a scoreable STATUS_MAKE_UP application for the same student/enrollment/book — see ExamScoreService::record(). Ignored when clearing a score, and a no-op if a retake already exists for this row. */
  make_up?: boolean
}

export const examScoresService = {
  options: () => apiGetWithMeta<ExamScoreOption[]>('/exam-scores/options').then((r) => r.data),

  /**
   * Capped at 500 rows server-side (ApiQuery::maxPerPage) — the Grades tab
   * is a batch-edit spreadsheet, not a paginated list.
   *
   * `scored` is sent as `1`/`0`, not a JS boolean: axios serializes a query
   * param `false` as the literal string "false", which Laravel's `boolean`
   * validation rule rejects (it only accepts 1/0/'1'/'0', not "true"/"false").
   */
  list: (filters: ExamScoreFilters) =>
    apiGetWithMeta<ExamScoreEntry[]>('/exam-scores', {
      params: { ...filters, scored: filters.scored == null ? undefined : filters.scored ? 1 : 0, per_page: 500 },
    }).then((r) => r.data),

  record: (entries: ExamScoreEntryInput[]) => apiPost<{ saved: number }>('/exam-scores', { entries }),
}
