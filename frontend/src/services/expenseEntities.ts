import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Accounting > Expense for: who an expense is for (Newtech, Flexi, Family...). */
export interface ExpenseEntity {
  id: number
  name: string
  description: string | null
  is_active: boolean
  expenses_count?: number
  created_at: string
}

export interface ExpenseEntityInput {
  name: string
  description: string
  is_active: boolean
}

export const expenseEntitiesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<ExpenseEntity>> {
    const result = await apiGetWithMeta<ExpenseEntity[]>('/expense-entities', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active entry, for the Expense form and the Expenses filter; the list is short. */
  async listAll(): Promise<ExpenseEntity[]> {
    const result = await apiGetWithMeta<ExpenseEntity[]>('/expense-entities', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: ExpenseEntityInput) => apiPost<ExpenseEntity>('/expense-entities', { ...input, description: input.description || null }),
  update: (id: number, input: ExpenseEntityInput) => apiPut<ExpenseEntity>(`/expense-entities/${id}`, { ...input, description: input.description || null }),
  remove: (id: number) => apiDelete(`/expense-entities/${id}`),
}
