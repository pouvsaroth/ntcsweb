import { apiDelete, apiDownload, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Professional Development — see backend TrainingController and
 * TrainingRecordController (docs/hrm/professional-development.md).
 */
export const TRAINING_STATUSES = ['planned', 'completed', 'cancelled'] as const
export type TrainingStatus = (typeof TRAINING_STATUSES)[number]
export const PROVIDER_TYPES = ['internal', 'external'] as const
export type ProviderType = (typeof PROVIDER_TYPES)[number]
export const TRAINING_MODES = ['in_person', 'online'] as const
export type TrainingMode = (typeof TRAINING_MODES)[number]
export const ATTENDANCES = ['registered', 'attended', 'absent'] as const
export type Attendance = (typeof ATTENDANCES)[number]
export const RESULTS = ['passed', 'failed'] as const
export type TrainingResult = (typeof RESULTS)[number]
export type TrainingWhen = 'upcoming' | 'past' | ''
export type CertificateState = 'issued' | 'pending' | 'expiring'

export interface PdStaffOption {
  id: number
  name: string
  employee_code: string | null
  is_teacher: boolean
}

export interface TrainingCertificate {
  no: string | null
  issued_on: string
  expires_on: string | null
  expired: boolean
  file_name: string | null
  has_file: boolean
}

export interface TrainingParticipant {
  id: number
  training_id: number
  staff_id: number
  staff?: { id: number; name: string; employee_code: string | null } | null
  training?: {
    id: number
    title: string
    category: string | null
    provider_type: ProviderType
    trainer: string | null
    start_date: string
    end_date: string
    hours: number | null
    status: TrainingStatus
  }
  attendance: Attendance
  result: TrainingResult | null
  score: number | null
  note: string | null
  certificate: TrainingCertificate | null
}

export interface Training {
  id: number
  title: string
  category: string | null
  provider_type: ProviderType
  trainer: string | null
  mode: TrainingMode
  location: string | null
  start_date: string
  end_date: string
  start_time: string | null
  end_time: string | null
  hours: number | null
  cost: number | null
  description: string | null
  status: TrainingStatus
  participants_count?: number
  attended_count?: number
  participants?: TrainingParticipant[]
  created_at: string
}

export interface TrainingInput {
  title: string
  category: string | null
  provider_type: ProviderType
  trainer: string | null
  mode: TrainingMode
  location: string | null
  start_date: string
  end_date: string
  start_time: string | null
  end_time: string | null
  hours: number | null
  cost: number | null
  description: string | null
  status?: TrainingStatus
  participant_ids?: number[]
}

export interface TrainingSummary {
  upcoming: number
  this_month: number
  completed_this_year: number
  staff_trained_this_year: number
}

export interface RecordTotals {
  trainings: number
  attended: number
  hours: number
  certificates: number
}

export interface AttendanceRow {
  id: number
  attendance: Attendance
  result: TrainingResult | null
  score: number | null
  note: string | null
}

export interface RecordFilters {
  staff_id?: number | null
  year?: number | null
  attendance?: string | null
}

const BASE = '/professional-development'

function clean<T extends Record<string, unknown>>(params: T): Partial<T> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')) as Partial<T>
}

async function paged<T>(url: string, query: PaginatedQuery, extra: Record<string, unknown>): Promise<PaginatedResult<T>> {
  const result = await apiGetWithMeta<T[]>(url, {
    params: clean({ page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...extra }),
  })
  return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
}

export const trainingsService = {
  list: (query: PaginatedQuery & { when?: TrainingWhen; year?: number | null }) =>
    paged<Training>(`${BASE}/trainings`, query, { when: query.when || undefined, year: query.year }),
  calendar: (month: string) => apiGet<Training[]>(`${BASE}/trainings/calendar`, { params: { month } }),
  summary: () => apiGet<TrainingSummary>(`${BASE}/trainings/summary`),
  categories: () => apiGet<string[]>(`${BASE}/trainings/categories`),
  staffOptions: () => apiGet<PdStaffOption[]>(`${BASE}/staff-options`),
  get: (id: number) => apiGet<Training>(`${BASE}/trainings/${id}`),
  create: (input: TrainingInput) => apiPost<Training>(`${BASE}/trainings`, input),
  update: (id: number, input: Partial<TrainingInput>) => apiPut<Training>(`${BASE}/trainings/${id}`, input),
  remove: (id: number) => apiDelete(`${BASE}/trainings/${id}`),
  saveAttendance: (id: number, rows: AttendanceRow[]) => apiPut<Training>(`${BASE}/trainings/${id}/attendance`, { rows }),
}

export const trainingRecordsService = {
  list: (query: PaginatedQuery & RecordFilters) =>
    paged<TrainingParticipant>(`${BASE}/records`, query, { staff_id: query.staff_id, year: query.year, attendance: query.attendance }),
  totals: (filters: RecordFilters & { search?: string }) => apiGet<RecordTotals>(`${BASE}/records/totals`, { params: clean({ ...filters }) }),

  certificates: (query: PaginatedQuery & RecordFilters & { state: CertificateState }) =>
    paged<TrainingParticipant>(`${BASE}/certificates`, query, { state: query.state, staff_id: query.staff_id, year: query.year }),
  issueCertificate(id: number, input: { certificate_no: string | null; issued_on: string; expires_on: string | null; file: File | null }): Promise<TrainingParticipant> {
    const form = new FormData()
    for (const [key, value] of Object.entries(input)) {
      if (value !== null && value !== undefined && value !== '') form.append(key, value instanceof File ? value : String(value))
    }
    return apiPost<TrainingParticipant>(`${BASE}/records/${id}/certificate`, form)
  },
  downloadCertificate: (row: TrainingParticipant) => apiDownload(`${BASE}/records/${row.id}/certificate/file`, row.certificate?.file_name ?? `certificate-${row.id}`),
  revokeCertificate: (id: number) => apiDelete(`${BASE}/records/${id}/certificate`),
}

// --- Classroom observation (step 2) -------------------------------------------

export const OBSERVATION_STATUSES = ['scheduled', 'completed', 'cancelled'] as const
export type ObservationStatus = (typeof OBSERVATION_STATUSES)[number]
export type ObservationMine = '' | 'observed' | 'observer'

export interface PersonRef {
  id: number
  name: string
  employee_code: string | null
}

export interface ObservationFormCriterion {
  id?: number
  section: string | null
  criterion: string
}

export interface ObservationForm {
  id: number
  name: string
  description: string | null
  is_active: boolean
  criteria: ObservationFormCriterion[]
}

export interface ObservationRating {
  id: number
  section: string | null
  criterion: string
  rating: number | null
  comment: string | null
}

export interface Observation {
  id: number
  staff_id: number
  teacher: PersonRef | null
  observer_staff_id: number | null
  observer: PersonRef | null
  class_id: number | null
  class_name: string | null
  book_id: number | null
  subject: string | null
  observation_form_id: number | null
  form_name: string | null
  observed_on: string
  start_time: string | null
  end_time: string | null
  focus: string | null
  status: ObservationStatus
  average_rating: number | null
  completed_at: string | null
  acknowledged_at: string | null
  teacher_comment: string | null
  strengths: string | null
  improvements: string | null
  next_steps: string | null
  ratings: ObservationRating[] | null
  can: { record: boolean; manage: boolean; acknowledge: boolean }
}

export interface ObservationInput {
  staff_id: number
  observer_staff_id: number | null
  class_id: number | null
  book_id: number | null
  observation_form_id: number
  observed_on: string
  start_time: string | null
  end_time: string | null
  focus: string | null
  status?: 'scheduled' | 'cancelled'
}

export interface ObservationOptions {
  teachers: PersonRef[]
  observers: PersonRef[]
  forms: { id: number; name: string; criteria_count: number }[]
  classes: { id: number; name: string; teacher_ids: number[] }[]
  subjects: { id: number; title: string }[]
}

export interface ObservationSummary {
  scheduled: number
  completed_this_year: number
  awaiting_acknowledgement: number
  average_this_year: number | null
}

export interface ObservationRecordInput {
  ratings: { id: number; rating: number | null; comment: string | null }[]
  strengths: string | null
  improvements: string | null
  next_steps: string | null
  complete?: boolean
}

export const observationsService = {
  list: (query: PaginatedQuery & { status?: string; mine?: ObservationMine; awaiting?: boolean }) =>
    paged<Observation>(`${BASE}/observations`, query, { status: query.status, mine: query.mine || undefined, awaiting: query.awaiting ? 'acknowledgement' : undefined }),
  summary: () => apiGet<ObservationSummary>(`${BASE}/observations/summary`),
  options: () => apiGet<ObservationOptions>(`${BASE}/observations/options`),
  get: (id: number) => apiGet<Observation>(`${BASE}/observations/${id}`),
  create: (input: ObservationInput) => apiPost<Observation>(`${BASE}/observations`, input),
  update: (id: number, input: Partial<ObservationInput>) => apiPut<Observation>(`${BASE}/observations/${id}`, input),
  record: (id: number, input: ObservationRecordInput) => apiPut<Observation>(`${BASE}/observations/${id}/record`, input),
  acknowledge: (id: number, comment: string | null) => apiPost<Observation>(`${BASE}/observations/${id}/acknowledge`, { comment }),
  remove: (id: number) => apiDelete(`${BASE}/observations/${id}`),

  forms: () => apiGet<ObservationForm[]>(`${BASE}/observation-forms`),
  createForm: (input: Omit<ObservationForm, 'id'>) => apiPost<ObservationForm>(`${BASE}/observation-forms`, input),
  updateForm: (id: number, input: Omit<ObservationForm, 'id'>) => apiPut<ObservationForm>(`${BASE}/observation-forms/${id}`, input),
  removeForm: (id: number) => apiDelete(`${BASE}/observation-forms/${id}`),
}

// --- Coaching & mentoring (step 2) ----------------------------------------------

export const COACHING_TYPES = ['coaching', 'mentoring'] as const
export type CoachingType = (typeof COACHING_TYPES)[number]
export const COACHING_STATUSES = ['active', 'completed', 'cancelled'] as const
export type CoachingStatus = (typeof COACHING_STATUSES)[number]
export type CoachingMine = '' | 'coach' | 'coached'

export interface CoachingSession {
  id: number
  session_date: string
  duration_minutes: number | null
  topic: string
  notes: string | null
  action_items: string | null
}

export interface Coaching {
  id: number
  type: CoachingType
  coach_staff_id: number
  staff_id: number
  classroom_observation_id: number | null
  goal: string
  description: string | null
  status: CoachingStatus
  outcome: string | null
  start_date: string
  end_date: string | null
  coach: PersonRef | null
  staff: PersonRef | null
  sessions_count: number
  last_session_date: string | null
  observation?: { id: number; observed_on: string; form_name: string | null } | null
  sessions?: CoachingSession[]
  can: { manage: boolean; sessions: boolean; close: boolean }
}

export interface CoachingInput {
  type: CoachingType
  coach_staff_id: number
  staff_id: number
  classroom_observation_id: number | null
  goal: string
  description: string | null
  start_date: string
  end_date: string | null
  status?: CoachingStatus
  outcome?: string | null
}

export interface CoachingSessionInput {
  session_date: string
  duration_minutes: number | null
  topic: string
  notes: string | null
  action_items: string | null
}

export interface CoachingSummary {
  active: number
  completed_this_year: number
  sessions_this_month: number
  coaches: number
}

export const coachingService = {
  list: (query: PaginatedQuery & { status?: string; type?: string; mine?: CoachingMine }) =>
    paged<Coaching>(`${BASE}/coachings`, query, { status: query.status, type: query.type, mine: query.mine || undefined }),
  summary: () => apiGet<CoachingSummary>(`${BASE}/coachings/summary`),
  get: (id: number) => apiGet<Coaching>(`${BASE}/coachings/${id}`),
  create: (input: CoachingInput) => apiPost<Coaching>(`${BASE}/coachings`, input),
  update: (id: number, input: Partial<CoachingInput>) => apiPut<Coaching>(`${BASE}/coachings/${id}`, input),
  remove: (id: number) => apiDelete(`${BASE}/coachings/${id}`),
  addSession: (id: number, input: CoachingSessionInput) => apiPost<Coaching>(`${BASE}/coachings/${id}/sessions`, input),
  updateSession: (sessionId: number, input: CoachingSessionInput) => apiPut<Coaching>(`${BASE}/coaching-sessions/${sessionId}`, input),
  removeSession: (sessionId: number) => apiDelete<Coaching>(`${BASE}/coaching-sessions/${sessionId}`),
}

// --- Development plan (DevelopmentPlanController) ---------------------------
export const PLAN_STATUSES = ['draft', 'active', 'completed', 'cancelled'] as const
export type PlanStatus = (typeof PLAN_STATUSES)[number]
export const GOAL_STATUSES = ['not_started', 'in_progress', 'done', 'dropped'] as const
export type GoalStatus = (typeof GOAL_STATUSES)[number]
export type PlanMine = '' | 'own' | 'reviewing'

export interface PlanGoal {
  id: number
  title: string
  area: string | null
  actions: string | null
  support: string | null
  training_id: number | null
  coaching_id: number | null
  target_date: string | null
  status: GoalStatus
  progress: number
  staff_note: string | null
  overdue: boolean
  training: { id: number; title: string; start_date: string | null; status: TrainingStatus } | null
  coaching: { id: number; goal: string; type: CoachingType; status: CoachingStatus } | null
}

export interface DevelopmentPlan {
  id: number
  staff_id: number
  reviewer_staff_id: number | null
  title: string
  start_date: string
  end_date: string | null
  status: PlanStatus
  summary: string | null
  review_note: string | null
  completed_at: string | null
  staff: PersonRef | null
  reviewer: PersonRef | null
  goals_count: number
  goals_done: number
  progress: number
  can: { manage: boolean; review: boolean; progress: boolean }
  goals?: PlanGoal[]
}

export interface PlanGoalInput {
  id?: number | null
  title: string
  area?: string | null
  actions?: string | null
  support?: string | null
  training_id?: number | null
  coaching_id?: number | null
  target_date?: string | null
}

export interface DevelopmentPlanInput {
  staff_id: number
  reviewer_staff_id: number | null
  title: string
  start_date: string
  end_date: string | null
  status?: PlanStatus
  summary: string | null
  review_note?: string | null
  goals: PlanGoalInput[]
}

export interface PlanSummary {
  active: number
  drafts: number
  goals_done_percent: number | null
  overdue_goals: number
}

export interface PlanOptions {
  trainings: { id: number; title: string; start_date: string | null; status: TrainingStatus }[]
  coachings: { id: number; goal: string; type: CoachingType; start_date: string | null; status: CoachingStatus }[]
}

export const developmentPlansService = {
  list: (query: PaginatedQuery & { status?: string; mine?: PlanMine }) =>
    paged<DevelopmentPlan>(`${BASE}/development-plans`, query, { status: query.status, mine: query.mine || undefined }),
  summary: () => apiGet<PlanSummary>(`${BASE}/development-plans/summary`),
  options: (staffId?: number | null) => apiGet<PlanOptions>(`${BASE}/development-plans/options`, { params: clean({ staff_id: staffId }) }),
  get: (id: number) => apiGet<DevelopmentPlan>(`${BASE}/development-plans/${id}`),
  create: (input: DevelopmentPlanInput) => apiPost<DevelopmentPlan>(`${BASE}/development-plans`, input),
  update: (id: number, input: Partial<DevelopmentPlanInput>) => apiPut<DevelopmentPlan>(`${BASE}/development-plans/${id}`, input),
  remove: (id: number) => apiDelete(`${BASE}/development-plans/${id}`),
  progress: (goalId: number, input: { status: GoalStatus; progress: number | null; staff_note: string | null }) =>
    apiPut<DevelopmentPlan>(`${BASE}/development-plan-goals/${goalId}/progress`, input),
}

// --- Training reports (TrainingReportController) ----------------------------
export interface TrainingReport {
  from: string
  to: string
  totals: {
    trainings: number
    completed: number
    cancelled: number
    assigned: number
    attended: number
    attendance_rate: number | null
    staff_trained: number
    hours: number
    cost: number
    certificates: number
    observations: number
    average_rating: number | null
    coaching_sessions: number
    active_plans: number
    plan_goals_done: number
  }
  by_month: { month: string; trainings: number; attended: number; hours: number }[]
  by_category: { category: string | null; trainings: number; attended: number; hours: number; cost: number }[]
  by_staff: {
    staff_id: number
    name: string
    employee_code: string | null
    department: string | null
    is_teacher: boolean
    trainings: number
    hours: number
    certificates: number
    last_training: string | null
    observations: number
    average_rating: number | null
  }[]
}

export const trainingReportsService = {
  get: (from: string, to: string) => apiGet<TrainingReport>(`${BASE}/reports`, { params: { from, to } }),
  downloadCsv: (from: string, to: string) => apiDownload(`${BASE}/reports?format=csv&from=${from}&to=${to}`, `training-report-${from}-to-${to}.csv`),
}
