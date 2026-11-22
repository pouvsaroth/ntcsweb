import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type FundCurrency = 'USD' | 'KHR'
export type FundStatus = 'active' | 'closed'
export type FundEntryType = 'in' | 'out'

/** Fund > Funds: one money in or money out, with who it came from / was paid to. */
export interface FundEntry {
  id: number
  fund_id: number
  type: FundEntryType
  amount: number
  /** yyyy-mm-dd */
  transacted_on: string
  /** 'YYYY-MM': the month this money is for. */
  for_month?: string | null
  party: string | null
  /** Money in: the provider picked (Fund > Providers); their name is in `party`. */
  fund_provider_id?: number | null
  /** Money in: the staff member picked (HRM > Staff); their name is in `party`. */
  staff_id?: number | null
  remark: string | null
  cash_account: { id: number; code: string; name: string } | null
  created_by?: string | null
  posted: boolean
  /** Balance after this entry (statement only). */
  balance?: number
  /** Statement: the amount and balance in USD and KHR at the entry date's rate. */
  amount_usd?: number | null
  amount_khr?: number | null
  balance_usd?: number | null
  balance_khr?: number | null
}

/** Fund > Funds: one pot of the school's money in one currency. */
export interface Fund {
  id: number
  /** F00001 — given from the id when added. */
  fund_no: string | null
  name: string
  /** A FUND_TYPE Base Data code. */
  fund_type: string
  currency: FundCurrency
  status: FundStatus
  opened_on: string
  target_amount: number | null
  target_amount_usd?: number | null
  target_amount_khr?: number | null
  description: string | null
  total_in: number
  /** Statement: money in / out summed entry by entry, each at its own date's rate. */
  total_in_usd?: number | null
  total_in_khr?: number | null
  total_out_usd?: number | null
  total_out_khr?: number | null
  total_out: number
  balance: number
  /** The balance in USD and KHR at today's rate. */
  balance_usd?: number | null
  balance_khr?: number | null
  entries_count: number
  last_entry_on: string | null
  /** Oldest first, with the running balance — only when one fund is loaded. */
  statement?: FundEntry[]
}

export interface FundSummaryRow {
  currency: FundCurrency
  funds: number
  balance: number
}

export interface FundInput {
  name: string
  fund_type: string
  opened_on: string
  target_amount: number | null
  description: string | null
}

export interface CreateFundInput extends FundInput {
  currency: FundCurrency
  opening_amount: number | null
  cash_account_id: number | null
  party: string | null
  fund_provider_id: number | null
  staff_id: number | null
  remark: string | null
}

/** A type just added with the "+" beside Fund type. */
export interface NewFundType {
  id: number
  code: string
  name: string
}

export interface FundEntryInput {
  type: FundEntryType
  amount: number
  transacted_on: string
  /** 'YYYY-MM'; left out, the month of the date. */
  for_month?: string | null
  cash_account_id: number
  party: string | null
  /** Money in only. */
  fund_provider_id?: number | null
  staff_id?: number | null
  remark: string | null
}

/** Copy: one money in of several saved at once. */
export interface FundCopyRow {
  amount: number
  transacted_on: string
  for_month: string
  cash_account_id: number
  party: string | null
  fund_provider_id: number | null
  staff_id: number | null
  remark: string | null
}

/** "Provided by": a staff member from HRM > Staff. */
export interface FundStaffOption {
  id: number
  name: string
  employee_code: string | null
}

/**
 * "Provided by" picks a staff member or a provider; the box's value is
 * 'staff:12' or 'provider:3' ('' = none). These turn it into the API's ids.
 */
export function providedByIds(value: string): { fund_provider_id: number | null; staff_id: number | null } {
  const [kind, id] = value.split(':')
  return {
    fund_provider_id: kind === 'provider' && id ? Number(id) : null,
    staff_id: kind === 'staff' && id ? Number(id) : null,
  }
}

export function providedByValue(entry: { fund_provider_id?: number | null; staff_id?: number | null }): string {
  if (entry.fund_provider_id) return `provider:${entry.fund_provider_id}`
  if (entry.staff_id) return `staff:${entry.staff_id}`
  return ''
}

export const fundsService = {
  /** "Provided by": active staff (HRM > Staff). */
  staffOptions: () => apiGet<FundStaffOption[]>('/funds/staff-options'),
  /** The "+" beside Fund type: add a FUND_TYPE Base Data value (needs Add funds). */
  addType: (input: { name: string; name_km: string | null }) => apiPost<NewFundType>('/fund-types', input),
  async list(query: PaginatedQuery): Promise<PaginatedResult<Fund>> {
    const result = await apiGetWithMeta<Fund[]>('/funds', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  summary: () => apiGet<FundSummaryRow[]>('/funds/summary'),
  get: (id: number) => apiGet<Fund>(`/funds/${id}`),
  create: (input: CreateFundInput) => apiPost<Fund>('/funds', input),
  update: (id: number, input: Partial<FundInput & { status: FundStatus }>) => apiPut<Fund>(`/funds/${id}`, input),
  remove: (id: number) => apiDelete(`/funds/${id}`),
  record: (id: number, input: FundEntryInput) => apiPost<FundEntry>(`/funds/${id}/entries`, input),
  /** Copy: several money in at once (all saved, or none). */
  copyEntries: (id: number, entries: FundCopyRow[]) => apiPost<{ count: number; fund: Fund }>(`/funds/${id}/entries/copy`, { entries }),
  removeEntry: (entryId: number) => apiDelete<Fund>(`/fund-entries/${entryId}`),
}
