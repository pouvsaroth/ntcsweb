import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPostWithMeta, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { Invoice } from '@/services/invoices'

/**
 * Invoices > Discount codes. A main code (public) works for every student;
 * assigning it makes a student their own code (personal), e.g.
 * NTS000543_D01_101126. A student with their own codes uses only those.
 */
export type DiscountAudience = 'public' | 'personal'
export type DiscountType = 'percent' | 'amount'
export type DiscountCodeStatus = 'active' | 'inactive'
/** What the list shows: the saved status, or why an active code can't be used today. */
export type DiscountEffectiveStatus = 'active' | 'inactive' | 'scheduled' | 'expired'

export interface DiscountCodeStudent {
  id: number
  student_code: string | null
  name: string
}

/** A student given a main code, with their own code. */
export interface DiscountCodeAssignee extends DiscountCodeStudent {
  code: string
  uses_count: number
  assigned_on: string | null
}

export interface DiscountCode {
  id: number
  parent_id: number | null
  code: string
  name: string
  audience: DiscountAudience
  discount_type: DiscountType
  value: number
  max_discount: number | null
  currency: 'USD' | 'KHR'
  starts_on: string
  /** null = no end date */
  expires_on: string | null
  uses_count: number
  status: DiscountCodeStatus
  effective_status: DiscountEffectiveStatus
  notes: string | null
  students?: DiscountCodeAssignee[]
  students_count?: number | null
  created_at: string
}

export interface DiscountCodeInput {
  code: string
  name: string
  discount_type: DiscountType
  value: number | null
  max_discount: number | null
  starts_on: string
  expires_on: string | null
  status: DiscountCodeStatus
  notes: string | null
}

export interface DiscountCodeOptions {
  students: DiscountCodeStudent[]
}

/** What a code takes off a course's fee — the forms' live check before saving. */
export interface DiscountCodeCheck {
  code: string
  name: string
  discount_type: DiscountType
  value: number
  amount: number
  fee: number
  total: number
  currency: 'USD' | 'KHR'
}

/** A code the Enroll form offers in its dropdown: usable by this student on this course today. */
export interface AvailableDiscountCode {
  code: string
  name: string
  audience: DiscountAudience
  discount_type: DiscountType
  value: number
  max_discount: number | null
  currency: 'USD' | 'KHR'
  expires_on: string | null
}

export interface DiscountCodeCheckInput {
  discount_code: string
  student_id?: number | null
  course_package_id: number
  fee_type: string
}

export const discountCodesService = {
  /** Staff Enroll form (needs Add enrollments). */
  check: (input: DiscountCodeCheckInput) => apiPost<DiscountCodeCheck>('/discount-codes/check', input),
  /** The forms' dropdown: the student's own codes that work today, or every main code when they have none (or no student). */
  available: (studentId?: number | null) =>
    apiGet<AvailableDiscountCode[]>('/discount-codes/available', { params: studentId ? { student_id: studentId } : {} }),
  /** Record payment: what the code takes off what is still owed on this invoice (needs Add payments). */
  checkInvoice: (code: string, invoiceId: number) => apiPost<DiscountCodeCheck>('/discount-codes/check-invoice', { discount_code: code, invoice_id: invoiceId }),
  /** Website registration form (public codes only). */
  checkPublic: (input: Omit<DiscountCodeCheckInput, 'student_id'>) => apiPost<DiscountCodeCheck>('/public/discount-codes/check', input),

  async list(query: PaginatedQuery): Promise<PaginatedResult<DiscountCode>> {
    const result = await apiGetWithMeta<DiscountCode[]>('/discount-codes', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGet<DiscountCode>(`/discount-codes/${id}`),

  /** Students matching `search` (empty search = no students). */
  options: (search = '') => apiGet<DiscountCodeOptions>('/discount-codes/options', { params: { search } }),

  create: (input: DiscountCodeInput) => apiPost<DiscountCode>('/discount-codes', input),
  /** Assign discount to student: each student gets their own code (students who have one keep it). */
  async assign(id: number, studentIds: number[]): Promise<{ code: DiscountCode; made: string[] }> {
    const result = await apiPostWithMeta<DiscountCode>(`/discount-codes/${id}/students`, { student_ids: studentIds })
    return { code: result.data, made: (result.meta?.codes as string[] | undefined) ?? [] }
  },
  /** Take the discount back from a student (their own code is deleted, or kept inactive when already used). */
  unassign: (id: number, studentId: number) => apiDelete<DiscountCode>(`/discount-codes/${id}/students/${studentId}`),
  update: (id: number, input: DiscountCodeInput) => apiPut<DiscountCode>(`/discount-codes/${id}`, input),
  remove: (id: number) => apiDelete(`/discount-codes/${id}`),
}

/** Student portal > My discount codes: one code as this student sees it. */
export interface MyDiscountCode {
  id: number
  code: string
  name: string
  audience: DiscountAudience
  discount_type: DiscountType
  value: number
  max_discount: number | null
  currency: 'USD' | 'KHR'
  starts_on: string
  /** null = no end date */
  expires_on: string | null
  /** The code's own state (a code can be used again); `used` is no longer sent. */
  state: DiscountEffectiveStatus | 'used'
  /** This student's last use of the code, if any. */
  used_at: string | null
  used_amount: number | null
}

export const myDiscountCodesService = {
  /** My codes, plus my unpaid course invoices a code can still go on. */
  list: () => apiGet<{ codes: MyDiscountCode[]; invoices: Invoice[] }>('/my-discount-codes'),
  apply: (invoiceId: number, code: string) => apiPost<Invoice>(`/my-invoices/${invoiceId}/discount-code`, { discount_code: code }),
}
