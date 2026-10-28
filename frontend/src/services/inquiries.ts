import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Front Office > Inquiry — see backend InquiryController. */
export const INQUIRY_SOURCES = ['walk_in', 'phone', 'facebook', 'telegram', 'website', 'referral', 'other'] as const
export const INQUIRY_STATUSES = ['new', 'following_up', 'enrolled', 'not_interested'] as const
export const FOLLOW_UP_METHODS = ['call', 'message', 'visit', 'other'] as const

export type InquirySource = (typeof INQUIRY_SOURCES)[number]
export type InquiryStatus = (typeof INQUIRY_STATUSES)[number]
export type FollowUpMethod = (typeof FOLLOW_UP_METHODS)[number]

export interface InquiryFollowUp {
  id: number
  followed_up_on: string
  method: FollowUpMethod
  note: string
  next_follow_up_on: string | null
  created_by_name: string | null
}

export interface Inquiry {
  id: number
  inquiry_date: string
  name: string
  gender: 'male' | 'female' | null
  phone: string
  email: string | null
  interested_in: string | null
  source: InquirySource
  status: InquiryStatus
  next_follow_up_on: string | null
  assigned_staff_id: number | null
  assigned_staff?: { id: number; name: string } | null
  student_id: number | null
  student?: { id: number; name: string; student_code: string | null } | null
  note: string | null
  follow_ups_count?: number
  follow_ups?: InquiryFollowUp[]
  created_at: string
}

export interface InquiryInput {
  inquiry_date: string
  name: string
  gender: 'male' | 'female' | null
  phone: string
  email: string | null
  interested_in: string | null
  source: InquirySource
  status?: InquiryStatus
  next_follow_up_on: string | null
  assigned_staff_id: number | null
  note: string | null
}

export interface FollowUpInput {
  followed_up_on: string
  method: FollowUpMethod
  note: string
  next_follow_up_on: string | null
  status: 'following_up' | 'not_interested' | null
}

export interface InquirySummary {
  total: number
  by_status: Record<InquiryStatus, number>
  due: number
  this_month: number
}

export interface StaffOption {
  id: number
  name: string
  employee_code: string | null
}

export const inquiriesService = {
  async list(query: PaginatedQuery & { due?: boolean }): Promise<PaginatedResult<Inquiry>> {
    const result = await apiGetWithMeta<Inquiry[]>('/inquiries', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter, due: query.due ? 1 : undefined },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  summary: () => apiGet<InquirySummary>('/inquiries/summary'),
  staffOptions: () => apiGet<StaffOption[]>('/inquiries/staff-options'),
  get: (id: number) => apiGet<Inquiry>(`/inquiries/${id}`),
  create: (input: InquiryInput) => apiPost<Inquiry>('/inquiries', input),
  update: (id: number, input: Partial<InquiryInput>) => apiPut<Inquiry>(`/inquiries/${id}`, input),
  remove: (id: number) => apiDelete(`/inquiries/${id}`),
  addFollowUp: (id: number, input: FollowUpInput) => apiPost<Inquiry>(`/inquiries/${id}/follow-ups`, input),
  enroll: (id: number, studentId: number) => apiPost<Inquiry>(`/inquiries/${id}/enroll`, { student_id: studentId }),
}
