import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type SavingsCurrency = 'USD' | 'KHR'
export type SavingsStatus = 'open' | 'closed'
/** `interest` is kept for later, when savings earn interest. */
export type SavingsEntryType = 'deposit' | 'withdrawal' | 'interest'

/** Loan > Savings: one deposit or withdrawal, with its remark. */
export interface SavingsEntry {
  id: number
  savings_account_id: number
  type: SavingsEntryType
  amount: number
  /** yyyy-mm-dd */
  transacted_on: string
  remark: string | null
  cash_account: { id: number; code: string; name: string } | null
  created_by?: string | null
  posted: boolean
  /** Balance after this entry (statement only). */
  balance?: number
}

/** Loan > Savings: one customer's savings in one currency. */
export interface SavingsAccount {
  id: number
  /** S00001 — given from the id when opened. */
  account_no: string | null
  currency: SavingsCurrency
  status: SavingsStatus
  opened_on: string
  notes: string | null
  customer: { id: number; code: string | null; name: string; phone: string | null; gender: string | null } | null
  total_in: number
  total_out: number
  balance: number
  entries_count: number
  last_entry_on: string | null
  /** Oldest first, with the running balance — only when one account is loaded. */
  statement?: SavingsEntry[]
}

export interface SavingsSummaryRow {
  currency: SavingsCurrency
  accounts: number
  balance: number
}

export interface OpenSavingsInput {
  loan_customer_id: number
  currency: SavingsCurrency
  opened_on: string
  notes: string | null
  first_deposit: number | null
  cash_account_id: number | null
  remark: string | null
}

export interface SavingsEntryInput {
  type: 'deposit' | 'withdrawal'
  amount: number
  transacted_on: string
  cash_account_id: number
  remark: string | null
}

export const savingsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<SavingsAccount>> {
    const result = await apiGetWithMeta<SavingsAccount[]>('/savings-accounts', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  summary: () => apiGet<SavingsSummaryRow[]>('/savings-accounts/summary'),
  get: (id: number) => apiGet<SavingsAccount>(`/savings-accounts/${id}`),
  open: (input: OpenSavingsInput) => apiPost<SavingsAccount>('/savings-accounts', input),
  update: (id: number, input: Partial<{ opened_on: string; notes: string | null; status: SavingsStatus }>) => apiPut<SavingsAccount>(`/savings-accounts/${id}`, input),
  remove: (id: number) => apiDelete(`/savings-accounts/${id}`),
  record: (id: number, input: SavingsEntryInput) => apiPost<SavingsEntry>(`/savings-accounts/${id}/entries`, input),
  removeEntry: (entryId: number) => apiDelete<SavingsAccount>(`/savings-entries/${entryId}`),
}
