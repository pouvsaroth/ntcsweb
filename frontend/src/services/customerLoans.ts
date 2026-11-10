import { apiDelete, apiDownload, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { InterestPeriod, InterestType, ScheduleRow, ScheduleTotals, SchedulePreviewInput } from '@/services/borrowings'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type CustomerLoanStatus = 'active' | 'paid_off' | 'cancelled'
export type CustomerLoanCurrency = 'USD' | 'KHR'

/** Loan > Owe: one payment from a customer, split into principal and interest. */
export interface CustomerLoanPayment {
  id: number
  customer_loan_id: number
  paid_on: string
  principal: number
  interest: number
  amount: number
  cash_account: { id: number; code: string; name: string } | null
  created_by?: string | null
  note: string | null
}

/** Loan > Owe: money lent to a customer (they owe it back). */
export interface CustomerLoan {
  id: number
  /** L00001 — given from the id. */
  loan_no: string | null
  reference: string | null
  customer: { id: number; code: string | null; name: string; phone: string | null } | null
  cash_account: { id: number; code: string; name: string } | null
  amount: number
  currency: CustomerLoanCurrency
  lent_on: string
  interest_type: InterestType
  interest_rate: number
  interest_period: InterestPeriod
  term_months: number | null
  /** No months: paid any date, any amount. */
  flexible: boolean
  first_payment_on: string | null
  last_payment_on: string | null
  purpose: string | null
  /** The money lent was put into the ledger (it has a Cash/Bank account). */
  posted: boolean
  status: CustomerLoanStatus
  totals: ScheduleTotals
  monthly_payment: number
  paid_principal: number
  paid_interest: number
  outstanding: number
  late_count: number
  next_payment: ScheduleRow | null
  /** One month of interest as the loan stands (suggested for a flexible loan). */
  month_interest: number
  schedule?: ScheduleRow[]
  payments?: CustomerLoanPayment[]
}

export interface CustomerLoanInput {
  loan_customer_id: number | null
  reference: string | null
  amount: number
  currency: CustomerLoanCurrency
  lent_on: string
  cash_account_id: number | null
  interest_type: InterestType
  interest_rate: number
  interest_period: InterestPeriod
  term_months: number | null
  first_payment_on: string | null
  purpose: string | null
}

export interface CustomerLoanPaymentInput {
  paid_on: string
  principal: number
  interest: number
  cash_account_id: number | null
  note: string | null
}

export interface CustomerLoanSummary {
  currencies: { currency: CustomerLoanCurrency; owed: number; late: number; due_soon: number; interest_this_year: number; loans: number }[]
  late_loans: number
}

export const customerLoansService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<CustomerLoan>> {
    const result = await apiGetWithMeta<CustomerLoan[]>('/customer-loans', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  summary: () => apiGet<CustomerLoanSummary>('/customer-loans/summary'),
  preview: (input: SchedulePreviewInput) => apiGet<{ schedule: ScheduleRow[]; totals: ScheduleTotals }>('/customer-loans/schedule-preview', { params: input }),
  get: (id: number) => apiGet<CustomerLoan>(`/customer-loans/${id}`),
  /** The payment list as Excel or PDF. */
  downloadPlan: (id: number, format: 'excel' | 'pdf', filename: string) => apiDownload(`/customer-loans/${id}/plan/${format}`, filename),
  create: (input: CustomerLoanInput) => apiPost<CustomerLoan>('/customer-loans', input),
  update: (id: number, input: Partial<CustomerLoanInput>) => apiPut<CustomerLoan>(`/customer-loans/${id}`, input),
  cancel: (id: number) => apiPost<CustomerLoan>(`/customer-loans/${id}/cancel`),
  remove: (id: number) => apiDelete(`/customer-loans/${id}`),
  collect: (id: number, input: CustomerLoanPaymentInput) => apiPost<CustomerLoanPayment>(`/customer-loans/${id}/payments`, input),
  removePayment: (paymentId: number) => apiDelete<CustomerLoan>(`/customer-loan-payments/${paymentId}`),
}
