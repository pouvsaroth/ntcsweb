import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface CurrencyRate {
  id: number
  effective_date: string
  khr_per_usd: number
  created_by: string | null
  created_at: string
}

export interface CurrencyRateInput {
  effective_date: string
  khr_per_usd: number
}

export const currencyRatesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<CurrencyRate>> {
    const result = await apiGetWithMeta<CurrencyRate[]>('/currency-rates', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: CurrencyRateInput) => apiPost<CurrencyRate>('/currency-rates', input),
  update: (id: number, input: CurrencyRateInput) => apiPut<CurrencyRate>(`/currency-rates/${id}`, input),
  remove: (id: number) => apiDelete(`/currency-rates/${id}`),
}
