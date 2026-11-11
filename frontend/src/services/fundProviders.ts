import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Fund > Providers: people (or companies) who give money to the funds. */
export interface FundProvider {
  id: number
  /** P00001 — given from the id when added. */
  code: string | null
  name: string
  /** A GENDER base data code, e.g. male / female. */
  gender: string | null
  phone: string | null
  address: string | null
  notes: string | null
  is_active: boolean
  /** Money given so far per currency (on the list only). */
  given?: { USD: number; KHR: number }
  created_at: string | null
}

export interface FundProviderInput {
  name: string
  gender: string
  phone: string
  address: string
  notes: string
  is_active: boolean
}

/** Blank boxes go up as null, not empty strings. */
const payload = (input: FundProviderInput) => ({
  ...input,
  gender: input.gender || null,
  phone: input.phone || null,
  address: input.address || null,
  notes: input.notes || null,
})

export const fundProvidersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<FundProvider>> {
    const result = await apiGetWithMeta<FundProvider[]>('/fund-providers', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active provider — for the Money in pickers. */
  async listAll(): Promise<FundProvider[]> {
    const result = await apiGetWithMeta<FundProvider[]>('/fund-providers', { params: { per_page: 500, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: FundProviderInput) => apiPost<FundProvider>('/fund-providers', payload(input)),
  update: (id: number, input: FundProviderInput) => apiPut<FundProvider>(`/fund-providers/${id}`, payload(input)),
  remove: (id: number) => apiDelete(`/fund-providers/${id}`),
}
