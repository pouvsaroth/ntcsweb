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

export interface ReportLine {
  account_id: number
  account_code: string
  account_name: string
  amount: number
}

export interface ProfitLossReport {
  revenue: { lines: { account_name: string; amount: number }[]; total: number }
  expenses: { lines: { account_name: string; amount: number }[]; total: number }
  net_profit: number
  is_profit: boolean
}

export interface CashFlowReport {
  opening: number
  student_payments: number
  other_income: number
  expenses: number
  closing: number
}

export interface DateRangeFilter {
  date_from?: string
  date_to?: string
}

export const accountingReportsService = {
  dashboard: (params: DateRangeFilter = {}) => apiGetWithMeta<AccountingSummary>('/accounting/dashboard', { params }).then((r) => r.data),

  revenue: (params: DateRangeFilter = {}) =>
    apiGetWithMeta<{ lines: ReportLine[]; total: number }>('/accounting/reports/revenue', { params }).then((r) => r.data),

  expenses: (params: DateRangeFilter = {}) =>
    apiGetWithMeta<{ lines: ReportLine[]; total: number }>('/accounting/reports/expenses', { params }).then((r) => r.data),

  profitLoss: (params: DateRangeFilter = {}) => apiGetWithMeta<ProfitLossReport>('/accounting/reports/profit-loss', { params }).then((r) => r.data),

  cashFlow: (params: DateRangeFilter = {}) => apiGetWithMeta<CashFlowReport>('/accounting/reports/cash-flow', { params }).then((r) => r.data),
}
