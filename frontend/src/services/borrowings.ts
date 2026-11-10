import { apiDelete, apiDownload, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Accounting > Borrowings: money borrowed from companies and people. */
export type LenderType = 'company' | 'person'
/** interest_only is for Owe loans: interest monthly, the whole amount with the last month. */
export type InterestType = 'none' | 'flat' | 'reducing' | 'interest_only'
export type InterestPeriod = 'month' | 'year'
export type BorrowingStatus = 'active' | 'paid_off' | 'cancelled'
export type BorrowingCurrency = 'USD' | 'KHR'

export interface Lender {
  id: number
  name: string
  type: LenderType
  contact_person: string | null
  phone: string | null
  email: string | null
  address: string | null
  notes: string | null
  is_active: boolean
  borrowings_count?: number
  created_at: string
}

export interface LenderInput {
  name: string
  type: LenderType
  contact_person: string
  phone: string
  email: string
  address: string
  notes: string
  is_active: boolean
}

export interface ScheduleRow {
  no: number
  due_on: string
  /** Owed at the start of the month (before this month's principal). */
  start?: number
  principal: number
  interest: number
  total: number
  balance: number
  /** Only on a saved loan's plan: covered by the principal paid so far / unpaid and past due / principal still owed for this month. */
  paid?: boolean
  late?: boolean
  due_principal?: number
}

export interface BorrowingRepayment {
  id: number
  borrowing_id: number
  loan?: {
    id: number
    reference: string | null
    currency: BorrowingCurrency
    lender: { id: number; name: string } | null
    borrowed_for: { id: number; name: string } | null
  }
  paid_on: string
  principal: string
  interest: string
  amount: string
  cash_account?: { id: number; code: string; name: string } | null
  interest_expense_id: number | null
  note: string | null
  created_at: string
}

export interface RepaymentInput {
  paid_on: string
  principal: number
  interest: number
  cash_account_id: number | null
  note: string | null
}

export interface ScheduleTotals {
  principal: number
  interest: number
  total: number
}

export interface Borrowing {
  id: number
  reference: string | null
  lender: { id: number; name: string; type: LenderType } | null
  borrowed_for: { id: number; name: string } | null
  cash_account: { id: number; code: string; name: string } | null
  amount: string
  currency: BorrowingCurrency
  received_on: string
  interest_type: InterestType
  interest_rate: string
  interest_period: InterestPeriod
  /** Empty on a flexible loan: no fixed plan, paid any date, any amount. */
  term_months: number | null
  first_payment_on: string | null
  flexible: boolean
  last_payment_on: string | null
  purpose: string | null
  contract_name: string | null
  has_contract: boolean
  /** Money received was put into the ledger (it has a Cash/Bank account). */
  posted: boolean
  status: BorrowingStatus
  totals: ScheduleTotals
  monthly_payment: number
  paid_principal: number
  paid_interest: number
  outstanding: number
  late_count: number
  next_payment: ScheduleRow | null
  /** One month's interest on what is owed now (suggested on a flexible loan). */
  month_interest: number
  schedule?: ScheduleRow[]
  repayments?: BorrowingRepayment[]
  created_at: string
}

export interface BorrowingInput {
  lender_id: number | null
  expense_entity_id: number | null
  reference: string | null
  amount: number
  currency: BorrowingCurrency
  received_on: string
  cash_account_id: number | null
  interest_type: InterestType
  interest_rate: number
  interest_period: InterestPeriod
  term_months: number | null
  first_payment_on: string | null
  purpose: string | null
}

export interface CurrencyAmount {
  currency: BorrowingCurrency
  amount: number
}

export interface BorrowingGroupRow {
  id: number
  name: string | null
  currency: BorrowingCurrency
  loans: number
  borrowed: number
  paid_principal: number
  paid_interest: number
  outstanding: number
}

/** Reports tab and the dashboard card; every amount is per currency (USD and KHR never added together). */
export interface BorrowingSummary {
  owed: CurrencyAmount[]
  late: CurrencyAmount[]
  late_loans: number
  due_soon: CurrencyAmount[]
  active_loans: number
  next_payment: { loan_id: number; lender: string | null; borrowed_for: string | null; due_on: string; amount: number; currency: BorrowingCurrency; late: boolean } | null
  by_lender: BorrowingGroupRow[]
  by_borrowed_for: BorrowingGroupRow[]
  paid: { from: string; to: string; rows: { id: number; name: string | null; currency: BorrowingCurrency; principal: number; interest: number; payments: number }[] }
}

export type SchedulePreviewInput = Pick<BorrowingInput, 'amount' | 'currency' | 'interest_type' | 'interest_rate' | 'interest_period' | 'term_months' | 'first_payment_on'>

const nullable = (input: LenderInput) => ({
  ...input,
  contact_person: input.contact_person || null,
  phone: input.phone || null,
  email: input.email || null,
  address: input.address || null,
  notes: input.notes || null,
})

export const lendersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Lender>> {
    const result = await apiGetWithMeta<Lender[]>('/lenders', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active lender, for the loan form and the filter. */
  async listAll(): Promise<Lender[]> {
    const result = await apiGetWithMeta<Lender[]>('/lenders', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: LenderInput) => apiPost<Lender>('/lenders', nullable(input)),
  update: (id: number, input: LenderInput) => apiPut<Lender>(`/lenders/${id}`, nullable(input)),
  remove: (id: number) => apiDelete(`/lenders/${id}`),
}

export const borrowingsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Borrowing>> {
    const result = await apiGetWithMeta<Borrowing[]>('/borrowings', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGet<Borrowing>(`/borrowings/${id}`),
  preview: (input: SchedulePreviewInput) => apiGet<{ schedule: ScheduleRow[]; totals: ScheduleTotals }>('/borrowings/schedule-preview', { params: input }),
  create: (input: BorrowingInput) => apiPost<Borrowing>('/borrowings', input),
  update: (id: number, input: BorrowingInput) => apiPut<Borrowing>(`/borrowings/${id}`, input),
  cancel: (id: number) => apiPost<Borrowing>(`/borrowings/${id}/cancel`),
  remove: (id: number) => apiDelete(`/borrowings/${id}`),
  uploadContract: (id: number, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return apiPost<Borrowing>(`/borrowings/${id}/contract`, form)
  },
  summary: (range: { from?: string; to?: string } = {}) => apiGet<BorrowingSummary>('/borrowings/summary', { params: range }),
  /** The next unpaid month of every active loan due within `days` (late ones included). */
  due: (days = 30) => apiGet<Borrowing[]>('/borrowings/due', { params: { days } }),
  repay: (id: number, input: RepaymentInput) => apiPost<Borrowing>(`/borrowings/${id}/repayments`, input),
  removeRepayment: (repaymentId: number) => apiDelete<Borrowing>(`/borrowing-repayments/${repaymentId}`),
  async repayments(query: PaginatedQuery): Promise<PaginatedResult<BorrowingRepayment>> {
    const result = await apiGetWithMeta<BorrowingRepayment[]>('/borrowing-repayments', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  downloadContract: (loan: Borrowing) => apiDownload(`/borrowings/${loan.id}/contract`, loan.contract_name ?? 'contract'),
}
