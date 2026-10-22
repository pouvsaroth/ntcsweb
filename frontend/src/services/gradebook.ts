import { apiDelete, apiGet, apiPost, apiPut } from '@/services/http'

/**
 * Academic > Examination — gradebook & assessment (docs/academics/gradebook.md).
 * Step 7a: assessment setup per grade level, and the exam schedule.
 */

export const ASSESSMENT_MODES = ['score', 'competency'] as const
export type AssessmentMode = (typeof ASSESSMENT_MODES)[number]

export const COMPONENT_KINDS = ['monthly', 'quiz', 'assignment', 'midterm', 'exam', 'project', 'other'] as const
export type ComponentKind = (typeof COMPONENT_KINDS)[number]

export interface AssessmentComponent {
  id?: number
  name: string
  name_km: string | null
  kind: ComponentKind
  max_score: number
  weight: number
  occurrences: number
}
export interface GradeBand {
  id?: number
  letter: string
  label: string | null
  label_km: string | null
  min_percent: number
}
export interface CompetencyCriterion {
  id?: number
  book: { id: number; title: string } | null
  name: string
  name_km: string | null
}
export interface CompetencyLevel {
  id?: number
  code: string
  label: string
  label_km: string | null
  points: number
  is_passing: boolean
}

export type SchemeWarning = 'no_components' | 'weights_not_100' | 'no_bands' | 'no_levels' | 'no_criteria'

export interface AssessmentScheme {
  id: number
  grade_level: { id: number; code: string; name: string } | null
  mode: AssessmentMode
  name: string
  max_score: number
  decimals: number
  absent_counts_as_zero: boolean
  notes: string | null
  weights_total: number
  warnings: SchemeWarning[]
  components: AssessmentComponent[]
  bands: GradeBand[]
  criteria: CompetencyCriterion[]
  levels: CompetencyLevel[]
}

export interface AssessmentSchemeInput {
  grade_level_id: number | null
  mode: AssessmentMode
  name: string
  max_score: number
  decimals: number
  absent_counts_as_zero: boolean
  notes: string | null
  components?: AssessmentComponent[]
  bands?: GradeBand[]
  criteria?: (Omit<CompetencyCriterion, 'book'> & { book_id: number | null })[]
  levels?: CompetencyLevel[]
}

export const assessmentSchemesService = {
  list: () => apiGet<AssessmentScheme[]>('/assessment-schemes'),
  create: (input: AssessmentSchemeInput) => apiPost<AssessmentScheme>('/assessment-schemes', input),
  update: (id: number, input: AssessmentSchemeInput) => apiPut<AssessmentScheme>(`/assessment-schemes/${id}`, input),
  remove: (id: number) => apiDelete(`/assessment-schemes/${id}`),
  starter: (id: number) => apiPost<AssessmentScheme>(`/assessment-schemes/${id}/starter`),
}

export interface ExamScheduleRow {
  id: number
  academic_year: { id: number; name: string } | null
  term: { id: number; name: string } | null
  component: { id: number; name: string; name_km: string | null } | null
  grade_level: { id: number; code: string; name: string } | null
  class: { id: number; name: string } | null
  book: { id: number; title: string } | null
  exam_date: string
  start_time: string
  end_time: string
  classroom: { id: number; name: string } | null
  invigilator: { id: number; name: string } | null
  notes: string | null
}

export interface ExamScheduleInput {
  academic_year_id: number
  academic_term_id: number | null
  assessment_component_id: number | null
  grade_level_id: number | null
  class_id: number | null
  book_id: number
  exam_date: string
  start_time: string
  end_time: string
  classroom_id: number | null
  invigilator_staff_id: number | null
  notes: string | null
}

export interface ExamScheduleFilter {
  academic_year_id?: number
  academic_term_id?: number
  grade_level_id?: number
  class_id?: number
}

export const examSchedulesService = {
  list: (filter: ExamScheduleFilter) => apiGet<ExamScheduleRow[]>('/exam-schedules', { params: filter }),
  create: (input: ExamScheduleInput) => apiPost<ExamScheduleRow>('/exam-schedules', input),
  update: (id: number, input: ExamScheduleInput) => apiPut<ExamScheduleRow>(`/exam-schedules/${id}`, input),
  remove: (id: number) => apiDelete(`/exam-schedules/${id}`),
}

// --- Step 7b: grade entry, validation, calculation ----------------------------

export type SheetStatus = 'draft' | 'pending' | 'approved' | 'returned'

export interface GradebookClass {
  id: number
  name: string
  grade_level: { id: number; code: string; name: string } | null
  academic_year: { id: number; name: string } | null
  subjects: { id: number; title: string; can_enter: boolean }[]
}

/** What the gradebook tabs' picker (GradebookPicker.vue) has chosen. */
export interface GradebookPick {
  yearId: number | null
  termId: number | null
  classId: number | null
  subjectId: number | null
  klass: GradebookClass | null
}

export interface SheetTarget {
  class_id: number
  book_id: number
  academic_term_id: number
}

/** A score column (component × time) or a competency criterion. */
export interface SheetColumn {
  key: string
  name: string
  name_km: string | null
  component_id?: number
  occurrence?: number
  occurrences?: number
  max_score?: number
  weight?: number
  criterion_id?: number
}

export interface SheetCell {
  score: number | null
  absent: boolean
  level_id: number | null
  remark: string | null
}

export interface SubjectResultRow {
  percent: number | null
  score: number | null
  band: string | null
  passed: boolean | null
  rank: number | null
  complete: boolean
}

export interface SheetStudent {
  id: number
  code: string
  name: string
  cells: Record<string, SheetCell>
  result: SubjectResultRow | null
}

export interface GradeSheet {
  class: { id: number; name: string; grade_level_id: number | null }
  subject: { id: number; title: string }
  term: { id: number; name: string }
  sheet: { id: number; status: SheetStatus; decision_note: string | null } | null
  can_enter: boolean
  /** May submit for approval (teaches it, still a draft or returned). */
  can_submit: boolean
  /** Approved: changes go through a grade correction. */
  can_request_correction: boolean
  /** "studentId|columnKey" of cells with a correction waiting. */
  pending_corrections: string[]
  history_count: number
  pass_mark: number
  scheme: {
    id: number
    name: string
    mode: AssessmentMode
    max_score: number
    decimals: number
    absent_counts_as_zero: boolean
    bands: { letter: string; label: string | null; label_km: string | null; min_percent: number }[]
    levels: { id: number; code: string; label: string; label_km: string | null; points: number; is_passing: boolean }[]
  } | null
  columns: SheetColumn[]
  students: SheetStudent[]
}

export interface CellInput {
  student_id: number
  component_id?: number
  occurrence?: number
  criterion_id?: number
  score?: number | null
  absent?: boolean
  level_id?: number | null
  remark?: string | null
}

export type ValidationIssue =
  | 'no_scheme'
  | 'weights_not_100'
  | 'setup_incomplete'
  | 'not_started'
  | 'missing'
  | 'absent_without_remark'
  | 'over_max'
  | 'no_students'

export interface ValidationRow {
  class: { id: number; name: string }
  subject: { id: number; title: string }
  status: SheetStatus | null
  students: number
  expected: number
  entered: number
  missing: number
  absent_without_remark: number
  over_max: number
  issues: ValidationIssue[]
  ready: boolean
}

export interface ClassResults {
  class: { id: number; name: string }
  term: { id: number; name: string }
  scheme: { name: string; mode: AssessmentMode; max_score: number } | null
  pass_mark: number
  subjects: { id: number; title: string; status: SheetStatus | null }[]
  students: {
    id: number
    code: string
    name: string
    cells: Record<string, { percent: number | null; score: number | null; band: string | null; passed: boolean | null; complete: boolean } | null>
    average: number | null
    score: number | null
    band: string | null
    passed: boolean | null
    failed_subjects: number
    complete: boolean
    rank: number | null
  }[]
}

export const gradebookService = {
  classes: (academicYearId?: number | null) => apiGet<GradebookClass[]>('/gradebook/classes', { params: { academic_year_id: academicYearId || undefined } }),
  sheet: (target: SheetTarget) => apiGet<GradeSheet>('/gradebook/sheet', { params: target }),
  save: (target: SheetTarget, cells: CellInput[]) => apiPut<GradeSheet>('/gradebook/sheet', { ...target, cells }),
  validation: (academicTermId: number, classId?: number | null) =>
    apiGet<ValidationRow[]>('/gradebook/validation', { params: { academic_term_id: academicTermId, class_id: classId || undefined } }),
  results: (academicTermId: number, classId: number) => apiGet<ClassResults>('/gradebook/results', { params: { academic_term_id: academicTermId, class_id: classId } }),
}

// --- Step 7c: result approval and grade corrections (eApprovals) ---------------

export interface ApprovalProgress {
  step: number
  total: number
  group: string | null
  can_act: boolean
}

export interface ResultSheetRow {
  id: number
  class: { id: number; name: string } | null
  subject: { id: number; title: string } | null
  term: { id: number; name: string } | null
  status: SheetStatus
  requested_by: string | null
  submitted_at: string | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  approval_flow: ApprovalProgress | null
  can_submit: boolean
  can_decide: boolean
  created_at: string
}

export type CorrectionStatus = 'pending' | 'approved' | 'rejected'

export interface GradeCorrectionRow {
  id: number
  reference: string
  class: { id: number; name: string } | null
  subject: { id: number; title: string } | null
  term: { id: number; name: string } | null
  student: { id: number; code: string; name: string } | null
  what: { name: string | null; name_km: string | null; occurrence: number | null; occurrences: number | null; max_score: number | null }
  old: string
  new: string
  reason: string
  status: CorrectionStatus
  requested_by: string | null
  decided_by: string | null
  decided_at: string | null
  decision_reason: string | null
  approval_flow: ApprovalProgress | null
  can_decide: boolean
  can_withdraw: boolean
  created_at: string
}

export interface CorrectionInput extends SheetTarget {
  student_id: number
  component_id?: number
  occurrence?: number
  criterion_id?: number
  score?: number | null
  absent?: boolean
  level_id?: number | null
  reason: string
}

export interface HistoryRow {
  id: number
  student: string | null
  what: string | null
  old: string
  new: string
  reason: string | null
  correction: string | null
  changed_by: string | null
  changed_at: string
}

export interface ApprovalListFilter {
  academic_term_id?: number
  class_id?: number
  status?: string
  approval_queue?: 1
}

export const resultApprovalsService = {
  sheets: (filter: ApprovalListFilter) => apiGet<ResultSheetRow[]>('/gradebook/sheets', { params: filter }),
  submit: (target: SheetTarget) => apiPost<{ id: number; status: SheetStatus }>('/gradebook/sheets/submit', target),
  approve: (id: number) => apiPost<{ id: number; status: SheetStatus }>(`/gradebook/sheets/${id}/approve`),
  return: (id: number, reason: string) => apiPost<{ id: number; status: SheetStatus }>(`/gradebook/sheets/${id}/return`, { reason }),
  corrections: (filter: ApprovalListFilter) => apiGet<GradeCorrectionRow[]>('/gradebook/corrections', { params: filter }),
  requestCorrection: (input: CorrectionInput) => apiPost<{ id: number; status: CorrectionStatus }>('/gradebook/corrections', input),
  approveCorrection: (id: number) => apiPost<{ id: number; status: CorrectionStatus }>(`/gradebook/corrections/${id}/approve`),
  rejectCorrection: (id: number, reason: string) => apiPost<{ id: number; status: CorrectionStatus }>(`/gradebook/corrections/${id}/reject`, { reason }),
  withdrawCorrection: (id: number) => apiDelete(`/gradebook/corrections/${id}`),
  history: (target: SheetTarget) => apiGet<HistoryRow[]>('/gradebook/history', { params: target }),
}
