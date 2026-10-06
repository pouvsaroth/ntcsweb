import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { ApplicantSource, ApplicantStage } from '@/services/applicants'
import type { SalaryCurrency } from '@/services/jobPositions'
import type { EmploymentType } from '@/services/manpowerRequests'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Recruitment > Candidate selection / Offer letter, and the Hire step
 * — see CandidateSelectionController and OfferLetterController.
 */
export const OFFER_STATUSES = ['draft', 'sent', 'accepted', 'declined', 'withdrawn'] as const
export type OfferStatus = (typeof OFFER_STATUSES)[number]

export const OFFER_STATUS_VARIANT: Record<OfferStatus, 'neutral' | 'primary' | 'success' | 'danger' | 'warning'> = {
  draft: 'neutral',
  sent: 'primary',
  accepted: 'success',
  declined: 'danger',
  withdrawn: 'warning',
}

export interface OfferLetter {
  id: number
  /** "OL-000004". */
  reference: string
  applicant_id: number
  applicant?: {
    id: number
    first_name: string
    last_name: string
    name: string
    gender: string | null
    date_of_birth: string | null
    phone: string
    email: string | null
    address: string | null
    stage: ApplicantStage
  } | null
  job_position_id: number | null
  job_position?: { id: number; reference: string; title: string; position_id: number | null; branch_id: number | null } | null
  position_title: string
  department_id: number | null
  department?: string | null
  employment_type: EmploymentType
  salary: number
  salary_currency: SalaryCurrency
  start_date: string
  probation_months: number | null
  expires_on: string | null
  benefits: string | null
  terms: string | null
  status: OfferStatus
  sent_at: string | null
  responded_at: string | null
  /** The staff member this offer became — see StaffForm.vue's `?from_offer=`. */
  hired_staff_id: number | null
  created_at: string
}

export interface OfferLetterInput {
  applicant_id?: number
  position_title: string
  department_id: number | null
  employment_type: EmploymentType
  salary: number
  salary_currency: SalaryCurrency
  start_date: string
  probation_months: number | null
  expires_on: string | null
  benefits: string | null
  terms: string | null
}

/** One row of Candidate selection — a job's candidate and how their interviews went. */
export interface Candidate {
  id: number
  name: string
  phone: string
  stage: ApplicantStage
  source: ApplicantSource
  expected_salary: number | null
  applied_on: string
  interviews: number
  evaluations: number
  average_score: number | null
  votes: { hire: number; maybe: number; no_hire: number }
  offer: { id: number; reference: string; status: OfferStatus } | null
}

export const offerLettersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<OfferLetter>> {
    const result = await apiGetWithMeta<OfferLetter[]>('/offer-letters', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<OfferLetter>(`/offer-letters/${id}`).then((r) => r.data),
  create: (input: OfferLetterInput) => apiPost<OfferLetter>('/offer-letters', input),
  update: (id: number, input: Partial<OfferLetterInput> & { status?: OfferStatus }) => apiPut<OfferLetter>(`/offer-letters/${id}`, input),
  remove: (id: number) => apiDelete(`/offer-letters/${id}`),
  /** Links the accepted offer to the staff member just created from it. */
  hire: (id: number, staffId: number) => apiPost<OfferLetter>(`/offer-letters/${id}/hire`, { staff_id: staffId }),

  candidates: (jobPositionId: number) => apiGet<Candidate[]>('/candidate-selection', { params: { job_position_id: jobPositionId } }),
}
