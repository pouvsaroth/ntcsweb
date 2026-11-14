import { apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type AccountType = 'ASSET' | 'LIABILITY' | 'EQUITY' | 'REVENUE' | 'EXPENSE'

export const accountTypes: AccountType[] = ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE']

export interface Account {
  id: number
  code: string
  name: string
  type: AccountType
  parent_id: number | null
  parent: { id: number; code: string; name: string } | null
  description: string | null
  is_bank_or_cash: boolean
  is_active: boolean
  created_at: string
}

export interface AccountInput {
  code: string
  name: string
  type: AccountType
  parent_id: number | null
  description: string
  is_bank_or_cash: boolean
}

function toAccountPayload(input: AccountInput) {
  return {
    code: input.code,
    name: input.name,
    type: input.type,
    parent_id: input.parent_id,
    description: input.description || null,
    is_bank_or_cash: input.is_bank_or_cash,
  }
}

export const accountsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Account>> {
    const result = await apiGetWithMeta<Account[]>('/accounts', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** The whole Chart of Accounts, for pickers — a school's chart is small, same assumption as classesService.listAll(). */
  async listAll(): Promise<Account[]> {
    const result = await apiGetWithMeta<Account[]>('/accounts', { params: { per_page: 200, sort: 'code' } })
    return result.data
  },

  create: (input: AccountInput) => apiPost<Account>('/accounts', toAccountPayload(input)),
  update: (id: number, input: AccountInput) => apiPut<Account>(`/accounts/${id}`, toAccountPayload(input)),
  deactivate: (id: number) => apiPost<Account>(`/accounts/${id}/deactivate`),
  reactivate: (id: number) => apiPost<Account>(`/accounts/${id}/reactivate`),
}

export interface AccountingSummary {
  /** Every amount below is already converted to this currency — see AccountingDashboardController. */
  currency: 'USD' | 'KHR'
  total_revenue: number
  total_expenses: number
  net_profit: number
  todays_income: number
  todays_expenses: number
  outstanding_receivables: number
  overdue_receivables: number
  cash_accounts: { id: number; code: string; name: string; balance: number }[]
  total_cash_balance: number
}

/** Each report figure in the school's currency plus USD and KHR (each record converted at its own date's rate). */
export interface ReportLine {
  account_id: number
  account_code: string
  account_name: string
  amount: number
  amount_usd: number
  amount_khr: number
}

export interface ReportTotals<L> {
  lines: L[]
  total: number
  total_usd: number
  total_khr: number
}

export interface ProfitLossReport {
  revenue: ReportTotals<{ account_name: string; amount: number; amount_usd: number; amount_khr: number }>
  expenses: ReportTotals<{ account_name: string; amount: number; amount_usd: number; amount_khr: number }>
  net_profit: number
  net_profit_usd: number
  net_profit_khr: number
  is_profit: boolean
}

/** Accounting > Borrowings: loans_received / loan_repayments = money borrowed in / principal paid back. */
export type CashFlowKey =
  | 'opening'
  | 'student_payments'
  | 'other_income'
  | 'loans_received'
  | 'savings_deposits'
  | 'savings_withdrawals'
  | 'loans_given'
  | 'loan_collections'
  | 'loan_interest_received'
  | 'fund_in'
  | 'fund_out'
  | 'expenses'
  | 'loan_repayments'
  | 'closing'

export type CashFlowReport = Record<CashFlowKey | `${CashFlowKey}_usd` | `${CashFlowKey}_khr`, number>

export interface DateRangeFilter {
  date_from?: string
  date_to?: string
}

/** One posting behind the dashboard's income tile — see AccountingDashboardController::income(). */
export interface IncomeDetailItem {
  id: number
  date: string
  description: string | null
  /** Signed: a cancelled/refunded payment's reversal is negative. In the row's own currency. */
  amount: number
  currency: 'USD' | 'KHR'
}

export interface IncomeDetail {
  /** `total` is converted to this currency — the same figure as the dashboard tile. */
  currency: 'USD' | 'KHR'
  total: number
  items: IncomeDetailItem[]
}

export const accountingReportsService = {
  /** `today` — the viewer's own calendar day for the daily tiles; the server's clock runs on UTC. */
  /** `school_only`: the main Dashboard's tiles — the school's own money (no Loan/Fund, no non-school Expense for). */
  dashboard: (params: DateRangeFilter & { today?: string; school_only?: boolean } = {}) => apiGetWithMeta<AccountingSummary>('/accounting/dashboard', { params }).then((r) => r.data),

  income: (params: Required<DateRangeFilter> & { school_only?: boolean }) =>
    apiGetWithMeta<IncomeDetail>('/accounting/dashboard/income', { params }).then((r) => r.data),

  /** The paid expenses behind the dashboard's expense tiles — same shape as income(). */
  expenseDetail: (params: Required<DateRangeFilter> & { school_only?: boolean }) =>
    apiGetWithMeta<IncomeDetail>('/accounting/dashboard/expenses', { params }).then((r) => r.data),

  revenue: (params: DateRangeFilter = {}) =>
    apiGetWithMeta<ReportTotals<ReportLine>>('/accounting/reports/revenue', { params }).then((r) => r.data),

  expenses: (params: DateRangeFilter = {}) =>
    apiGetWithMeta<ReportTotals<ReportLine>>('/accounting/reports/expenses', { params }).then((r) => r.data),

  profitLoss: (params: DateRangeFilter = {}) => apiGetWithMeta<ProfitLossReport>('/accounting/reports/profit-loss', { params }).then((r) => r.data),

  cashFlow: (params: DateRangeFilter = {}) => apiGetWithMeta<CashFlowReport>('/accounting/reports/cash-flow', { params }).then((r) => r.data),
}
