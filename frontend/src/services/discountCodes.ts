import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'
import type { Invoice } from '@/services/invoices'

/** Invoices > Discount codes. */
export type DiscountAudience = 'public' | 'personal'
export type DiscountType = 'percent' | 'amount'
export type DiscountCodeStatus = 'active' | 'inactive'
/** What the list shows: the saved status, or why an active code can't be used today. */
export type DiscountEffectiveStatus = 'active' | 'inactive' | 'scheduled' | 'expired' | 'used_up'

export interface DiscountCodeStudent {
  id: number
  student_code: string | null
  name: string
}

export interface DiscountCode {
  id: number
  code: string
  name: string
  audience: DiscountAudience
  discount_type: DiscountType
  value: number
  max_discount: number | null
  currency: 'USD' | 'KHR'
  starts_on: string
  expires_on: string
  max_uses: number | null
  uses_count: number
  status: DiscountCodeStatus
  effective_status: DiscountEffectiveStatus
  notes: string | null
  course_packages?: { id: number; name: string }[]
  students?: DiscountCodeStudent[]
  students_count?: number
  created_at: string
}

export interface DiscountCodeInput {
  code: string
  name: string
  audience: DiscountAudience
  discount_type: DiscountType
  value: number | null
  max_discount: number | null
  starts_on: string
  expires_on: string
  max_uses: number | null
  status: DiscountCodeStatus
  notes: string | null
  course_package_ids: number[]
  student_ids: number[]
}

export interface DiscountCodeOptions {
  course_packages: { id: number; name: string }[]
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
  expires_on: string
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
  /** Enroll form dropdown: the student's personal codes + public codes, valid today for this course. */
  available: (studentId: number, coursePackageId: number) =>
    apiGet<AvailableDiscountCode[]>('/discount-codes/available', { params: { student_id: studentId, course_package_id: coursePackageId } }),
  /** Website registration form (public codes only). */
  checkPublic: (input: Omit<DiscountCodeCheckInput, 'student_id'>) => apiPost<DiscountCodeCheck>('/public/discount-codes/check', input),

  async list(query: PaginatedQuery): Promise<PaginatedResult<DiscountCode>> {
    const result = await apiGetWithMeta<DiscountCode[]>('/discount-codes', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGet<DiscountCode>(`/discount-codes/${id}`),

  /** Every course, and students matching `search` (empty search = no students). */
  options: (search = '') => apiGet<DiscountCodeOptions>('/discount-codes/options', { params: { search } }),

  create: (input: DiscountCodeInput) => apiPost<DiscountCode>('/discount-codes', input),
  /** Assign discount to student: give a personal code to more students (any time; existing ones kept). */
  assign: (id: number, studentIds: number[]) => apiPost<DiscountCode>(`/discount-codes/${id}/students`, { student_ids: studentIds }),
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
  expires_on: string
  course_packages: { id: number; name: string }[]
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
