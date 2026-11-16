import { apiGet } from '@/services/http'

type Currency = 'USD' | 'KHR'

/** `<field>_usd` / `<field>_khr`: moved money at its own date's rate, balances (balance, owed, late) at today's rate. */
type Both<F extends string> = Record<`${F}_usd` | `${F}_khr`, number>
export type SavingsMoney = 'deposits' | 'withdrawals' | 'balance'
export type LoanMoney = 'lent' | 'collected' | 'interest' | 'owed' | 'late'
export type PositionMoney = 'savings' | 'owed' | 'net'

export interface LoanReportSavingsTotal extends Both<SavingsMoney> {
  currency: Currency
  accounts: number
  open_accounts: number
  deposits: number
  withdrawals: number
  balance: number
}
export interface LoanReportSavingsRow extends Both<SavingsMoney> {
  customer_id: number
  name: string
  code: string | null
  currency: Currency
  status: 'open' | 'closed'
  deposits: number
  withdrawals: number
  balance: number
}
export interface LoanReportLoansTotal extends Both<LoanMoney> {
  currency: Currency
  active_loans: number
  late_loans: number
  lent: number
  collected: number
  interest: number
  owed: number
  late: number
}
export interface LoanReportLoansRow extends Both<LoanMoney> {
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
export interface LoanReportPosition extends Both<PositionMoney> {
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
  /** `all`: both currencies together, in USD and in KHR. */
  savings: { totals: LoanReportSavingsTotal[]; all: Both<SavingsMoney>; customers: LoanReportSavingsRow[] } | null
  loans: { totals: LoanReportLoansTotal[]; all: Both<LoanMoney>; customers: LoanReportLoansRow[] } | null
  positions: LoanReportPosition[] | null
}

export const loanReportsService = {
  get: (params: { from?: string; to?: string }) => apiGet<LoanReport>('/loan-reports', { params }),
}
