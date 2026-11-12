import type { ApprovalFlowProgress } from '@/services/approvalFlows'
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
  /** 'YYYY-MM': the month it is for (may differ from the date paid). */
  for_month: string | null
  account: { id: number; code: string; name: string }
  cash_account: { id: number; code: string; name: string } | null
  /** Who it is for (Accounting > Expense for); null on older expenses. */
  expense_entity: { id: number; name: string } | null
  amount: number
  currency: 'USD' | 'KHR'
  payment_method: string | null
  /** The vendor picked from the Suppliers list; `vendor` is its name (or a name typed on older expenses). */
  supplier_id: number | null
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
  /** Pending + an Expense approval flow is set: the step it waits on, and whether you can act on it. */
  approval_flow?: ApprovalFlowProgress | null
}

export interface ExpenseInput {
  expense_date: string
  /** 'YYYY-MM' */
  for_month: string
  account_id: number | null
  expense_entity_id: number | null
  amount: string
  currency: 'USD' | 'KHR'
  payment_method: string
  supplier_id: number | null
  vendor: string
  description: string
  reference_number: string
}

/** Expenses > Add many: one line of the batch. */
export interface ExpenseBatchLine {
  account_id: number | null
  amount: string
  description: string
  reference_number: string
}

/** Same as StoreExpenseBatchRequest::MAX_LINES. */
export const MAX_EXPENSE_LINES = 50

export interface ExpenseBatchInput {
  expense_date: string
  for_month: string
  expense_entity_id: number | null
  currency: 'USD' | 'KHR'
  payment_method: string
  supplier_id: number | null
  lines: ExpenseBatchLine[]
}

function toExpensePayload(input: ExpenseInput) {
  return {
    expense_date: input.expense_date,
    for_month: input.for_month || null,
    account_id: input.account_id,
    expense_entity_id: input.expense_entity_id,
    amount: Number(input.amount) || 0,
    currency: input.currency,
    payment_method: input.payment_method || null,
    supplier_id: input.supplier_id,
    vendor: input.vendor || null,
    description: input.description || null,
    reference_number: input.reference_number || null,
  }
}

export const expensesService = {
  /** `approvalQueue`: the Approvals queue's view — a pending expense with a flow only reaches its current step's group. */
  async list(query: PaginatedQuery, opts: { approvalQueue?: boolean } = {}): Promise<PaginatedResult<Expense>> {
    const result = await apiGetWithMeta<Expense[]>('/expenses', {
      params: {
        page: query.page,
        per_page: query.per_page,
        search: query.search,
        sort: query.sort,
        filter: query.filter,
        ...(opts.approvalQueue ? { approval_queue: '1' } : {}),
      },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Expense>(`/expenses/${id}`).then((r) => r.data),

  create: (input: ExpenseInput) => apiPost<Expense>('/expenses', toExpensePayload(input)),
  /** Add many: all lines saved together, or none (errors name `lines.N.field`). */
  createMany: (input: ExpenseBatchInput) =>
    apiPost<Expense[]>('/expenses/batch', {
      expense_date: input.expense_date,
      for_month: input.for_month || null,
      expense_entity_id: input.expense_entity_id,
      currency: input.currency,
      payment_method: input.payment_method || null,
      supplier_id: input.supplier_id,
      lines: input.lines.map((l) => ({
        account_id: l.account_id,
        amount: l.amount === '' ? null : Number(l.amount),
        description: l.description.trim() || null,
        reference_number: l.reference_number.trim() || null,
      })),
    }),
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
