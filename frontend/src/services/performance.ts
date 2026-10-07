import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Performance Management — the KPI library, evaluation forms, review
 * cycles, score weights and staff goals. See KpiController,
 * EvaluationFormController, PerformanceCycleController and
 * PerformanceGoalController on the backend. Ratings are 1–5.
 */
export interface Kpi {
  id: number
  code: string
  name: string
  description: string | null
  measurement: string | null
  unit: string | null
  target: number | null
  higher_is_better: boolean
  default_weight: number
  department: { id: number; name: string } | null
  position: { id: number; name: string } | null
  is_active: boolean
}

export interface KpiInput {
  code: string
  name: string
  description: string | null
  measurement: string | null
  unit: string | null
  target: number | null
  higher_is_better: boolean
  default_weight: number
  department_id: number | null
  position_id: number | null
  is_active: boolean
}

export type QuestionType = 'rating' | 'text'

export interface EvaluationQuestion {
  id?: number
  section: string | null
  question: string
  type: QuestionType
  is_required: boolean
}

export interface EvaluationForm {
  id: number
  name: string
  description: string | null
  is_active: boolean
  cycles_count: number | null
  questions: EvaluationQuestion[]
}

export interface EvaluationFormInput {
  name: string
  description: string | null
  is_active: boolean
  questions: EvaluationQuestion[]
}

export type CycleStatus = 'draft' | 'active' | 'closed'

export interface PerformanceCycle {
  id: number
  name: string
  start_date: string
  end_date: string
  self_assessment_due: string | null
  manager_assessment_due: string | null
  evaluation_form: { id: number; name: string } | null
  status: CycleStatus
  description: string | null
  goals_count: number | null
}

export interface PerformanceCycleInput {
  name: string
  start_date: string
  end_date: string
  self_assessment_due: string | null
  manager_assessment_due: string | null
  evaluation_form_id: number | null
  status: CycleStatus
  description: string | null
}

export interface PerformanceWeights {
  kpi_weight: number
  goal_weight: number
  manager_weight: number
}

export type GoalStatus = 'not_started' | 'in_progress' | 'completed' | 'cancelled'

export interface PerformanceGoal {
  id: number
  staff: { id: number; name: string; employee_code: string | null } | null
  cycle: { id: number; name: string } | null
  title: string
  description: string | null
  due_date: string | null
  weight: number
  progress: number
  status: GoalStatus
  self_rating: number | null
  manager_rating: number | null
}

export interface PerformanceGoalInput {
  staff_id?: number
  performance_cycle_id: number | null
  title: string
  description: string | null
  due_date: string | null
  weight: number
  progress: number
  status: GoalStatus
}

async function page<T>(url: string, query: PaginatedQuery): Promise<PaginatedResult<T>> {
  const result = await apiGetWithMeta<T[]>(url, {
    params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
  })
  return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
}

async function all<T>(url: string): Promise<T[]> {
  return (await apiGetWithMeta<T[]>(url, { params: { per_page: 200 } })).data
}

export const kpisService = {
  list: (query: PaginatedQuery) => page<Kpi>('/kpis', query),
  create: (input: KpiInput) => apiPost<Kpi>('/kpis', input),
  update: (id: number, input: Partial<KpiInput>) => apiPut<Kpi>(`/kpis/${id}`, input),
  remove: (id: number) => apiDelete(`/kpis/${id}`),
}

export const evaluationFormsService = {
  list: (query: PaginatedQuery) => page<EvaluationForm>('/evaluation-forms', query),
  listAll: () => all<EvaluationForm>('/evaluation-forms'),
  create: (input: EvaluationFormInput) => apiPost<EvaluationForm>('/evaluation-forms', input),
  update: (id: number, input: Partial<EvaluationFormInput>) => apiPut<EvaluationForm>(`/evaluation-forms/${id}`, input),
  remove: (id: number) => apiDelete(`/evaluation-forms/${id}`),
}

export const performanceCyclesService = {
  list: (query: PaginatedQuery) => page<PerformanceCycle>('/performance-cycles', query),
  listAll: () => all<PerformanceCycle>('/performance-cycles'),
  create: (input: PerformanceCycleInput) => apiPost<PerformanceCycle>('/performance-cycles', input),
  update: (id: number, input: Partial<PerformanceCycleInput>) => apiPut<PerformanceCycle>(`/performance-cycles/${id}`, input),
  remove: (id: number) => apiDelete(`/performance-cycles/${id}`),
  weights: () => apiGetWithMeta<PerformanceWeights>('/performance-settings').then((r) => r.data),
  updateWeights: (input: PerformanceWeights) => apiPut<PerformanceWeights>('/performance-settings', input),
}

export const performanceGoalsService = {
  list: (query: PaginatedQuery) => page<PerformanceGoal>('/performance-goals', query),
  create: (input: PerformanceGoalInput) => apiPost<PerformanceGoal>('/performance-goals', input),
  update: (id: number, input: Partial<PerformanceGoalInput>) => apiPut<PerformanceGoal>(`/performance-goals/${id}`, input),
  remove: (id: number) => apiDelete(`/performance-goals/${id}`),
}

// --- Stage 2: reviews --------------------------------------------------------------------

export type ReviewStatus = 'self_assessment' | 'manager_assessment' | 'completed'

export interface ReviewPerson {
  id: number
  name: string
  employee_code: string | null
  position: string | null
  department: string | null
}

export interface PerformanceReviewRow {
  id: number
  cycle: { id: number; name: string; status: CycleStatus; self_assessment_due: string | null; manager_assessment_due: string | null } | null
  staff: ReviewPerson | null
  reviewer: ReviewPerson | null
  status: ReviewStatus
  self_submitted_at: string | null
  manager_submitted_at: string | null
  kpi_score: number | null
  goal_score: number | null
  manager_score: number | null
  self_score: number | null
  final_score: number | null
}

export interface ReviewKpi {
  id: number
  name: string
  measurement: string | null
  unit: string | null
  target: number | null
  higher_is_better: boolean
  weight: number
  actual: number | null
  self_rating: number | null
  manager_rating: number | null
  comment: string | null
}

export interface ReviewGoal {
  id: number
  title: string
  description: string | null
  due_date: string | null
  weight: number
  progress: number
  status: GoalStatus
  self_rating: number | null
  manager_rating: number | null
}

export interface ReviewAnswer {
  id: number
  section: string | null
  question: string
  type: QuestionType
  is_required: boolean
  self_rating: number | null
  self_answer: string | null
  manager_rating: number | null
  manager_answer: string | null
}

export interface PerformanceReviewDetail extends PerformanceReviewRow {
  self_comment: string | null
  manager_comment: string | null
  manager_overall_rating: number | null
  kpis: ReviewKpi[]
  goals: ReviewGoal[]
  answers: ReviewAnswer[]
}

export interface ReviewCandidate {
  id: number
  name: string
  employee_code: string | null
  department: string | null
  position: string | null
  reports_to: { id: number; name: string } | null
}

export interface PerformanceScores {
  counts: { total: number; self_assessment: number; manager_assessment: number; completed: number }
  averages: { final_score: number | null; kpi_score: number | null; goal_score: number | null; manager_score: number | null; self_score: number | null }
  bands: Record<'1' | '2' | '3' | '4' | '5', number>
  rows: PerformanceReviewRow[]
}

/** What one side saves — only its own fields are sent. */
export interface ReviewSave {
  kpis?: { id: number; actual?: number | null; self_rating?: number | null; manager_rating?: number | null; comment?: string | null }[]
  goals?: { id: number; self_rating?: number | null; manager_rating?: number | null; progress?: number }[]
  answers?: { id: number; self_rating?: number | null; self_answer?: string | null; manager_rating?: number | null; manager_answer?: string | null }[]
  self_comment?: string | null
  manager_comment?: string | null
  manager_overall_rating?: number | null
}

export const performanceReviewsService = {
  list: (query: PaginatedQuery) => page<PerformanceReviewRow>('/performance-reviews', query),
  candidates: (cycleId: number) => apiGetWithMeta<ReviewCandidate[]>(`/performance-cycles/${cycleId}/candidates`).then((r) => r.data),
  launch: (cycleId: number, staffIds: number[]) => apiPost<{ launched: number }>(`/performance-cycles/${cycleId}/launch`, { staff_ids: staffIds }),
  get: (id: number) => apiGetWithMeta<PerformanceReviewDetail>(`/performance-reviews/${id}`).then((r) => r.data),
  setReviewer: (id: number, reviewerStaffId: number | null) => apiPut<PerformanceReviewDetail>(`/performance-reviews/${id}`, { reviewer_staff_id: reviewerStaffId }),
  replaceKpis: (id: number, kpis: Partial<ReviewKpi>[]) => apiPut<PerformanceReviewDetail>(`/performance-reviews/${id}/kpis`, { kpis }),
  sendToManager: (id: number) => apiPost<PerformanceReviewDetail>(`/performance-reviews/${id}/send-to-manager`, {}),
  reopen: (id: number) => apiPost<PerformanceReviewDetail>(`/performance-reviews/${id}/reopen`, {}),
  remove: (id: number) => apiDelete(`/performance-reviews/${id}`),
  scores: (cycleId: number) => apiGetWithMeta<PerformanceScores>('/performance-scores', { params: { performance_cycle_id: cycleId } }).then((r) => r.data),
}

/** The staff member's own reviews (self-assessment) and the ones they manage (manager assessment). */
export const myPerformanceReviewsService = {
  mine: () => apiGetWithMeta<PerformanceReviewRow[]>('/my-performance-reviews').then((r) => r.data),
  getMine: (id: number) => apiGetWithMeta<PerformanceReviewDetail>(`/my-performance-reviews/${id}`).then((r) => r.data),
  saveMine: (id: number, input: ReviewSave) => apiPut<PerformanceReviewDetail>(`/my-performance-reviews/${id}`, input),
  submitMine: (id: number) => apiPost<PerformanceReviewDetail>(`/my-performance-reviews/${id}/submit`, {}),
  team: () => apiGetWithMeta<PerformanceReviewRow[]>('/team-performance-reviews').then((r) => r.data),
  getTeam: (id: number) => apiGetWithMeta<PerformanceReviewDetail>(`/team-performance-reviews/${id}`).then((r) => r.data),
  saveTeam: (id: number, input: ReviewSave) => apiPut<PerformanceReviewDetail>(`/team-performance-reviews/${id}`, input),
  submitTeam: (id: number) => apiPost<PerformanceReviewDetail>(`/team-performance-reviews/${id}/submit`, {}),
}

/** "4.23 / 5 (85%)" — a 1–5 score. */
export function scoreLabel(score: number | null): string {
  if (score === null) return '—'
  return `${score.toFixed(2)} / 5 (${Math.round((score / 5) * 100)}%)`
}

// --- Stage 3: promotion recommendations ---------------------------------------------------

export type PromotionStatus = 'pending' | 'approved' | 'applied' | 'rejected' | 'cancelled'

type Named = { id: number; name: string } | null

export interface PromotionRecommendation {
  id: number
  staff: { id: number; name: string; employee_code: string | null } | null
  review: { id: number; cycle: string | null; final_score: number | null } | null
  from_position: Named
  to_position: Named
  from_job_grade: Named
  to_job_grade: Named
  from_job_level: Named
  to_job_level: Named
  from_basic_salary: number | null
  new_basic_salary: number | null
  salary_currency: 'USD' | 'KHR' | null
  effective_date: string
  reason: string
  status: PromotionStatus
  requested_by: string | null
  decided_by: string | null
  decided_at: string | null
  decision_reason: string | null
  applied_at: string | null
  created_at: string | null
  approval_flow: { step: number; total: number; group: string | null; can_act: boolean } | null
  can_decide: boolean
}

export interface PromotionInput {
  staff_id: number
  performance_review_id: number | null
  to_position_id: number | null
  to_job_grade_id: number | null
  to_job_level_id: number | null
  new_basic_salary: number | null
  effective_date: string
  reason: string
}

export interface PromotionOptions {
  positions: { id: number; name: string }[]
  job_grades: { id: number; name: string }[]
  job_levels: { id: number; name: string }[]
}

export interface PromotionCurrent {
  staff: { id: number; name: string; employee_code: string | null; department: string | null }
  position: Named
  job_grade: Named
  job_level: Named
  basic_salary: number | null
  currency: 'USD' | 'KHR' | null
  latest_review: { id: number; cycle: string | null; final_score: number | null } | null
}

export interface PromotionSuggestion {
  review_id: number
  staff: { id: number; name: string; employee_code: string | null; position: string | null }
  final_score: number
}

export const promotionsService = {
  list: (query: PaginatedQuery) => page<PromotionRecommendation>('/promotion-recommendations', query),
  create: (input: PromotionInput) => apiPost<PromotionRecommendation>('/promotion-recommendations', input),
  approve: (id: number) => apiPost<PromotionRecommendation>(`/promotion-recommendations/${id}/approve`, {}),
  reject: (id: number, reason: string) => apiPost<PromotionRecommendation>(`/promotion-recommendations/${id}/reject`, { reason }),
  cancel: (id: number) => apiPost<PromotionRecommendation>(`/promotion-recommendations/${id}/cancel`, {}),
  options: () => apiGetWithMeta<PromotionOptions>('/promotion-recommendations/options').then((r) => r.data),
  current: (staffId: number) => apiGetWithMeta<PromotionCurrent>(`/promotion-recommendations/current/${staffId}`).then((r) => r.data),
  suggestions: (cycleId: number) =>
    apiGetWithMeta<PromotionSuggestion[]>('/promotion-recommendations/suggestions', { params: { performance_cycle_id: cycleId } }).then((r) => r.data),
}
