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
  party: string | null
  /** Money in: the provider picked (Fund > Providers); their name is in `party`. */
  fund_provider_id?: number | null
  remark: string | null
  cash_account: { id: number; code: string; name: string } | null
  created_by?: string | null
  posted: boolean
  /** Balance after this entry (statement only). */
  balance?: number
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
  description: string | null
  total_in: number
  total_out: number
  balance: number
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
  cash_account_id: number
  party: string | null
  /** Money in only. */
  fund_provider_id?: number | null
  remark: string | null
}

export const fundsService = {
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
  removeEntry: (entryId: number) => apiDelete<Fund>(`/fund-entries/${entryId}`),
}
