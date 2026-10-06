import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { EmploymentType } from '@/services/manpowerRequests'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import { i18n } from '@/i18n'

/**
 * HRM > Recruitment > Job positions (vacancies) and Job postings (where each
 * is advertised), plus the public Careers page they feed — see
 * JobPositionController / JobPostingController / CareerController.
 */
export const JOB_POSITION_STATUSES = ['open', 'on_hold', 'filled', 'closed'] as const
export type JobPositionStatus = (typeof JOB_POSITION_STATUSES)[number]

export const JOB_POSTING_CHANNELS = ['website', 'facebook', 'telegram', 'linkedin', 'job_board', 'other'] as const
export type JobPostingChannel = (typeof JOB_POSTING_CHANNELS)[number]

export type SalaryCurrency = 'USD' | 'KHR'

export interface JobPosition {
  id: number
  /** "JP-000007". */
  reference: string
  manpower_request_id: number | null
  /** The manpower request's reference, e.g. "MP-000012". */
  manpower_request?: string | null
  title: string
  department_id: number | null
  department?: string | null
  position_id: number | null
  position?: string | null
  branch_id: number | null
  branch?: string | null
  headcount: number
  employment_type: EmploymentType
  salary_min: number | null
  salary_max: number | null
  salary_currency: SalaryCurrency
  description: string | null
  requirements: string | null
  status: JobPositionStatus
  opened_on: string | null
  closes_on: string | null
  postings_count?: number
  /** Listed on the public Careers page right now. */
  on_careers_page?: boolean
  created_at: string
}

export interface JobPositionInput {
  manpower_request_id: number | null
  title: string
  department_id: number | null
  position_id: number | null
  branch_id: number | null
  headcount: number
  employment_type: EmploymentType
  salary_min: number | null
  salary_max: number | null
  salary_currency: SalaryCurrency
  description: string
  requirements: string
  status: JobPositionStatus
  opened_on: string | null
  closes_on: string | null
}

export interface JobPosting {
  id: number
  job_position_id: number
  job_position?: { id: number; reference: string; title: string; status: JobPositionStatus } | null
  channel: JobPostingChannel
  url: string | null
  posted_on: string
  expires_on: string | null
  is_active: boolean
  note: string | null
  created_at: string
}

export interface JobPostingInput {
  job_position_id: number
  channel: JobPostingChannel
  url: string | null
  posted_on: string
  expires_on: string | null
  is_active: boolean
  note: string
}

function listParams(query: PaginatedQuery) {
  return { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter }
}

export const jobPositionsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<JobPosition>> {
    const result = await apiGetWithMeta<JobPosition[]>('/job-positions', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every job (newest first), for pickers — a school's vacancy list is small. */
  async listAll(filter: Record<string, string> = {}): Promise<JobPosition[]> {
    const result = await apiGetWithMeta<JobPosition[]>('/job-positions', { params: { per_page: 200, filter } })
    return result.data
  },

  create: (input: JobPositionInput) => apiPost<JobPosition>('/job-positions', input),
  update: (id: number, input: Partial<JobPositionInput>) => apiPut<JobPosition>(`/job-positions/${id}`, input),
  remove: (id: number) => apiDelete(`/job-positions/${id}`),
}

export const jobPostingsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<JobPosting>> {
    const result = await apiGetWithMeta<JobPosting[]>('/job-postings', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: JobPostingInput) => apiPost<JobPosting>('/job-postings', input),
  update: (id: number, input: JobPostingInput) => apiPut<JobPosting>(`/job-postings/${id}`, input),
  remove: (id: number) => apiDelete(`/job-postings/${id}`),
}

/** A job as the public Careers page shows it. */
export interface CareerJob {
  id: number
  title: string
  department: string | null
  location: string | null
  headcount: number
  employment_type: EmploymentType
  salary_min: number | null
  salary_max: number | null
  salary_currency: SalaryCurrency
  closes_on: string | null
  /** Detail only. */
  description?: string | null
  requirements?: string | null
}

export const careersService = {
  list: () => apiGet<CareerJob[]>('/public/careers'),

  /** Null for a job that isn't (or is no longer) on the Careers page. */
  async get(id: number): Promise<CareerJob | null> {
    try {
      return await apiGet<CareerJob>(`/public/careers/${id}`)
    } catch {
      return null
    }
  },
}

/** "USD 400 – 600", "From USD 400", "Up to USD 600", or "Negotiable". */
export function formatSalary(job: { salary_min: number | null; salary_max: number | null; salary_currency: SalaryCurrency }): string {
  const number = (value: number) => value.toLocaleString(undefined, { maximumFractionDigits: 2 })
  const { salary_min: min, salary_max: max, salary_currency: currency } = job

  if (min !== null && max !== null) return min === max ? `${currency} ${number(min)}` : `${currency} ${number(min)} – ${number(max)}`
  if (min !== null) return i18n.global.t('admin.recruitment.salaryFrom', { amount: `${currency} ${number(min)}` })
  if (max !== null) return i18n.global.t('admin.recruitment.salaryUpTo', { amount: `${currency} ${number(max)}` })
  return i18n.global.t('admin.recruitment.salaryNegotiable')
}
