import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ExpenseStatus = 'DRAFT' | 'PENDING_APPROVAL' | 'APPROVED' | 'PAID' | 'REJECTED' | 'CANCELLED'

export const expenseStatuses: ExpenseStatus[] = ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'PAID', 'REJECTED', 'CANCELLED']

export interface ExpenseAttachment {
  id: number
  file_name: string
  mime_type: string | null
  url: string
  uploaded_by: string | null
  created_at: string
}

export interface Expense {
  id: number
  expense_number: string
  expense_date: string
  account: { id: number; code: string; name: string }
  cash_account: { id: number; code: string; name: string } | null
  amount: number
  payment_method: string | null
  vendor: string | null
  description: string | null
  reference_number: string | null
  status: ExpenseStatus
  created_by: string | null
  approved_by: string | null
  approved_at: string | null
  rejected_reason: string | null
  paid_at: string | null
  cancellation_reason: string | null
  cancelled_by: string | null
  cancelled_at: string | null
  attachments: ExpenseAttachment[]
  created_at: string
}

export interface ExpenseInput {
  expense_date: string
  account_id: number | null
  amount: string
  payment_method: string
  vendor: string
  description: string
  reference_number: string
}

function toExpensePayload(input: ExpenseInput) {
  return {
    expense_date: input.expense_date,
    account_id: input.account_id,
    amount: Number(input.amount) || 0,
    payment_method: input.payment_method || null,
    vendor: input.vendor || null,
    description: input.description || null,
    reference_number: input.reference_number || null,
  }
}

export const expensesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Expense>> {
    const result = await apiGetWithMeta<Expense[]>('/expenses', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Expense>(`/expenses/${id}`).then((r) => r.data),

  create: (input: ExpenseInput) => apiPost<Expense>('/expenses', toExpensePayload(input)),
  update: (id: number, input: ExpenseInput) => apiPut<Expense>(`/expenses/${id}`, toExpensePayload(input)),

  approve: (id: number) => apiPost<Expense>(`/expenses/${id}/approve`),
  reject: (id: number, reason: string) => apiPost<Expense>(`/expenses/${id}/reject`, { reason }),
  pay: (id: number, cashAccountId: number, paidDate?: string) =>
    apiPost<Expense>(`/expenses/${id}/pay`, { cash_account_id: cashAccountId, paid_date: paidDate || undefined }),
  cancel: (id: number, reason: string) => apiPost<Expense>(`/expenses/${id}/cancel`, { reason }),

  uploadAttachment: (expenseId: number, file: File) => {
    const form = new FormData()
    form.append('file', file)
    return apiPost<ExpenseAttachment>(`/expenses/${expenseId}/attachments`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
  },
  removeAttachment: (expenseId: number, attachmentId: number) => apiDelete(`/expenses/${expenseId}/attachments/${attachmentId}`),
}
