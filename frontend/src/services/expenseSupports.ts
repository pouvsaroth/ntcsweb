import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Accounting > Expenses > Support: money or food someone gave towards an Expense for (e.g. hospital visitors). */
export type SupportKind = 'money' | 'food'
export type SupportRelationship = 'relative' | 'friend' | 'neighbour' | 'colleague' | 'other'

export const supportRelationships: SupportRelationship[] = ['relative', 'friend', 'neighbour', 'colleague', 'other']

export interface ExpenseSupport {
  id: number
  name: string
  phone: string | null
  relationship: SupportRelationship | null
  support_date: string
  kind: SupportKind
  /** Money: the amount; food: an optional estimate. */
  amount: number | null
  currency: 'USD' | 'KHR' | null
  /** Food: what they brought. */
  items: string | null
  expense_entity_id: number
  expense_entity?: { id: number; name: string } | null
  note: string | null
  created_at: string
}

export interface ExpenseSupportInput {
  name: string
  phone: string
  relationship: SupportRelationship | ''
  support_date: string
  kind: SupportKind
  amount: string
  currency: 'USD' | 'KHR'
  items: string
  expense_entity_id: number | null
  note: string
}

function toPayload(input: ExpenseSupportInput) {
  const amount = input.amount === '' ? null : Number(input.amount)
  return {
    name: input.name.trim(),
    phone: input.phone.trim() || null,
    relationship: input.relationship || null,
    support_date: input.support_date,
    kind: input.kind,
    amount,
    // Food with no estimate keeps no currency.
    currency: amount === null ? null : input.currency,
    items: input.items.trim() || null,
    expense_entity_id: input.expense_entity_id,
    note: input.note.trim() || null,
  }
}

export const expenseSupportsService = {
  /** `range`: support dates from / to (yyyy-mm-dd), either may be empty. */
  async list(query: PaginatedQuery, range: { from?: string; to?: string } = {}): Promise<PaginatedResult<ExpenseSupport>> {
    const result = await apiGetWithMeta<ExpenseSupport[]>('/expense-supports', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        from: range.from || undefined,
        to: range.to || undefined,
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: ExpenseSupportInput) => apiPost<ExpenseSupport>('/expense-supports', toPayload(input)),
  update: (id: number, input: ExpenseSupportInput) => apiPut<ExpenseSupport>(`/expense-supports/${id}`, toPayload(input)),
  remove: (id: number) => apiDelete(`/expense-supports/${id}`),
}
