import { apiGet } from '@/services/http'

type Currency = 'USD' | 'KHR'

export interface LoanReportSavingsTotal {
  currency: Currency
  accounts: number
  open_accounts: number
  deposits: number
  withdrawals: number
  balance: number
}
export interface LoanReportSavingsRow {
  customer_id: number
  name: string
  code: string | null
  currency: Currency
  status: 'open' | 'closed'
  deposits: number
  withdrawals: number
  balance: number
}
export interface LoanReportLoansTotal {
  currency: Currency
  active_loans: number
  late_loans: number
  lent: number
  collected: number
  interest: number
  owed: number
  late: number
}
export interface LoanReportLoansRow {
  customer_id: number
  name: string
  code: string | null
  currency: Currency
  loans: number
  lent: number
  collected: number
  interest: number
  owed: number
  late: number
}
export interface LoanReportPosition {
  customer_id: number
  name: string
  code: string | null
  currency: Currency
  savings: number
  owed: number
  /** owed − savings: above zero, they owe more than they keep with you. */
  net: number
}

/** Loan > Reports. A part is null when the person cannot see that menu. */
export interface LoanReport {
  from: string
  to: string
  savings: { totals: LoanReportSavingsTotal[]; customers: LoanReportSavingsRow[] } | null
  loans: { totals: LoanReportLoansTotal[]; customers: LoanReportLoansRow[] } | null
  positions: LoanReportPosition[] | null
}

export const loanReportsService = {
  get: (params: { from?: string; to?: string }) => apiGet<LoanReport>('/loan-reports', { params }),
}
