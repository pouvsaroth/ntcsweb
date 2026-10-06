import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { ApplicantStage } from '@/services/applicants'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Recruitment > Interview / Interview evaluation — see
 * InterviewController and InterviewEvaluationController on the backend.
 */
export const INTERVIEW_STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'] as const
export type InterviewStatus = (typeof INTERVIEW_STATUSES)[number]

export const INTERVIEW_MODES = ['in_person', 'online', 'phone'] as const
export type InterviewMode = (typeof INTERVIEW_MODES)[number]

/** Mirrors InterviewEvaluation::CRITERIA. */
export const EVALUATION_CRITERIA = ['communication', 'knowledge', 'experience', 'attitude', 'teamwork'] as const
export type EvaluationCriterion = (typeof EVALUATION_CRITERIA)[number]

export const RECOMMENDATIONS = ['hire', 'maybe', 'no_hire'] as const
export type Recommendation = (typeof RECOMMENDATIONS)[number]

export const INTERVIEW_STATUS_VARIANT: Record<InterviewStatus, 'primary' | 'success' | 'neutral' | 'danger'> = {
  scheduled: 'primary',
  completed: 'success',
  cancelled: 'neutral',
  no_show: 'danger',
}

export const RECOMMENDATION_VARIANT: Record<Recommendation, 'success' | 'warning' | 'danger'> = {
  hire: 'success',
  maybe: 'warning',
  no_hire: 'danger',
}

export interface Interviewer {
  id: number
  name: string
  email?: string
}

export interface Interview {
  id: number
  applicant_id: number
  applicant?: { id: number; name: string; phone: string; stage: ApplicantStage; job_title: string | null } | null
  round: number
  scheduled_at: string
  duration_minutes: number
  mode: InterviewMode
  location: string | null
  status: InterviewStatus
  notes: string | null
  interviewers?: Interviewer[]
  evaluations_count?: number
  average_score?: number | null
  /** The signed-in user sits on it. */
  is_my_interview?: boolean
  /** The signed-in user's own evaluation of it, if any. */
  my_evaluation_id?: number | null
  created_at: string
}

export interface InterviewInput {
  applicant_id: number
  round: number
  scheduled_at: string
  duration_minutes: number
  mode: InterviewMode
  location: string | null
  status: InterviewStatus
  notes: string | null
  interviewer_ids: number[]
}

export interface InterviewEvaluation {
  id: number
  interview_id: number
  interview?: {
    id: number
    round: number
    scheduled_at: string | null
    applicant: { id: number; name: string; job_title: string | null } | null
  } | null
  evaluator_id: number
  evaluator?: string | null
  scores: Record<EvaluationCriterion, number>
  overall_score: number
  recommendation: Recommendation
  strengths: string | null
  concerns: string | null
  created_at: string
  updated_at: string
}

export interface InterviewEvaluationInput {
  interview_id: number
  scores: Record<EvaluationCriterion, number>
  recommendation: Recommendation
  strengths: string | null
  concerns: string | null
}

export const interviewsService = {
  async list(query: PaginatedQuery, extra: Record<string, string | number> = {}): Promise<PaginatedResult<Interview>> {
    const result = await apiGetWithMeta<Interview[]>('/interviews', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter, ...extra },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Interviews the signed-in user sits on and hasn't evaluated yet. */
  async awaitingMyEvaluation(): Promise<Interview[]> {
    const result = await apiGetWithMeta<Interview[]>('/interviews', { params: { per_page: 100, awaiting_my_evaluation: '1' } })
    return result.data
  },

  interviewers: () => apiGet<Interviewer[]>('/interviews/interviewers'),
  create: (input: InterviewInput) => apiPost<Interview>('/interviews', input),
  update: (id: number, input: Partial<InterviewInput>) => apiPut<Interview>(`/interviews/${id}`, input),
  remove: (id: number) => apiDelete(`/interviews/${id}`),
}

export const interviewEvaluationsService = {
  async list(query: PaginatedQuery, extra: Record<string, string | number> = {}): Promise<PaginatedResult<InterviewEvaluation>> {
    const result = await apiGetWithMeta<InterviewEvaluation[]>('/interview-evaluations', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter, ...extra },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Creates it, or replaces the user's own earlier evaluation of the same interview. */
  save: (input: InterviewEvaluationInput) => apiPost<InterviewEvaluation>('/interview-evaluations', input),
  remove: (id: number) => apiDelete(`/interview-evaluations/${id}`),
}

/** The value a `<input type="datetime-local">` expects, from an ISO string (browser time). */
export function toDateTimeLocal(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** A `<input type="datetime-local">` value (browser time) as an absolute ISO time — the server keeps UTC. */
export function fromDateTimeLocal(value: string): string {
  return new Date(value).toISOString()
}
