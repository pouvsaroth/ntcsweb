import { apiDelete, apiDownload, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * HRM > Recruitment > Applicant management and CV/resume, plus the public
 * Careers page's Apply form — see ApplicantController /
 * ApplicantDocumentController / CareerController::apply() on the backend.
 */
export const APPLICANT_STAGES = ['new', 'screening', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'] as const
export type ApplicantStage = (typeof APPLICANT_STAGES)[number]

export const APPLICANT_SOURCES = ['website', 'facebook', 'telegram', 'linkedin', 'job_board', 'referral', 'walk_in', 'other'] as const
export type ApplicantSource = (typeof APPLICANT_SOURCES)[number]

export const DOCUMENT_TYPES = ['cv', 'cover_letter', 'certificate', 'other'] as const
export type ApplicantDocumentType = (typeof DOCUMENT_TYPES)[number]

/** Matches ApplicantDocument::EXTENSIONS / MAX_KB on the backend. */
export const DOCUMENT_ACCEPT = '.pdf,.doc,.docx,.jpg,.jpeg,.png'
export const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024

export const STAGE_VARIANT: Record<ApplicantStage, 'neutral' | 'primary' | 'warning' | 'success' | 'danger'> = {
  new: 'neutral',
  screening: 'primary',
  shortlisted: 'primary',
  interview: 'warning',
  offer: 'warning',
  hired: 'success',
  rejected: 'danger',
  withdrawn: 'neutral',
}

export interface ApplicantDocument {
  id: number
  applicant_id: number
  applicant?: { id: number; name: string; job_title: string | null } | null
  type: ApplicantDocumentType
  original_name: string
  mime_type: string | null
  size: number
  /** Null when the applicant uploaded it on the Careers page. */
  uploaded_by?: string | null
  created_at: string
}

export interface Applicant {
  id: number
  job_position_id: number | null
  job_position?: { id: number; reference: string; title: string } | null
  first_name: string
  last_name: string
  full_name: string
  gender: string | null
  date_of_birth: string | null
  phone: string
  email: string | null
  address: string | null
  source: ApplicantSource
  expected_salary: number | null
  available_from: string | null
  cover_letter: string | null
  stage: ApplicantStage
  notes: string | null
  documents_count?: number
  documents?: ApplicantDocument[]
  created_at: string
}

export interface ApplicantInput {
  job_position_id: number | null
  first_name: string
  last_name: string
  gender: string
  date_of_birth: string
  phone: string
  email: string
  address: string
  source: ApplicantSource
  expected_salary: string
  available_from: string
  cover_letter: string
  stage: ApplicantStage
  notes: string
  /** New applicants only. */
  cv?: File | null
}

function listParams(query: PaginatedQuery, extra: Record<string, string | number> = {}) {
  return { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, ...extra }
}

/** Empty strings become nulls, so clearing a field on edit really clears it. */
function body(input: ApplicantInput) {
  const { cv: _cv, ...fields } = input
  return Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, value === '' ? null : value]))
}

export const applicantsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Applicant>> {
    const result = await apiGetWithMeta<Applicant[]>('/applicants', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every applicant (newest first), for pickers. */
  async listAll(): Promise<Applicant[]> {
    const result = await apiGetWithMeta<Applicant[]>('/applicants', { params: { per_page: 500 } })
    return result.data
  },

  get: (id: number) => apiGetWithMeta<Applicant>(`/applicants/${id}`).then((r) => r.data),

  create(input: ApplicantInput): Promise<Applicant> {
    const form = new FormData()
    for (const [key, value] of Object.entries(body(input))) {
      if (value !== null && value !== undefined) form.append(key, String(value))
    }
    if (input.cv) form.append('cv', input.cv)
    return apiPost<Applicant>('/applicants', form)
  },

  update: (id: number, input: Partial<ApplicantInput>) =>
    apiPut<Applicant>(`/applicants/${id}`, body({ ...(input as ApplicantInput) })),

  remove: (id: number) => apiDelete(`/applicants/${id}`),
}

export const applicantDocumentsService = {
  async list(query: PaginatedQuery, extra: { job_position_id?: number } = {}): Promise<PaginatedResult<ApplicantDocument>> {
    const result = await apiGetWithMeta<ApplicantDocument[]>('/applicant-documents', { params: listParams(query, extra) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  upload(applicantId: number, type: ApplicantDocumentType, file: File): Promise<ApplicantDocument> {
    const form = new FormData()
    form.append('applicant_id', String(applicantId))
    form.append('type', type)
    form.append('file', file)
    return apiPost<ApplicantDocument>('/applicant-documents', form)
  },

  /** Private files — fetched with the session, then saved under their original name. */
  download: (document: ApplicantDocument) => apiDownload(`/applicant-documents/${document.id}/download`, document.original_name),

  remove: (id: number) => apiDelete(`/applicant-documents/${id}`),
}

export interface JobApplicationInput {
  first_name: string
  last_name: string
  gender: string
  date_of_birth: string
  phone: string
  email: string
  address: string
  expected_salary: string
  available_from: string
  cover_letter: string
  cv: File
}

/** The public Careers page's Apply form. */
export function applyForJob(jobId: number, input: JobApplicationInput): Promise<unknown> {
  const form = new FormData()
  for (const [key, value] of Object.entries(input)) {
    if (value instanceof File) form.append(key, value)
    else if (value) form.append(key, value)
  }
  return apiPost(`/public/careers/${jobId}/apply`, form)
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
