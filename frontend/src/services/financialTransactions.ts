import { apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type TransactionType = 'INCOME' | 'EXPENSE' | 'TRANSFER' | 'REFUND' | 'ADJUSTMENT'

export const transactionTypes: TransactionType[] = ['INCOME', 'EXPENSE', 'TRANSFER', 'REFUND', 'ADJUSTMENT']

export interface FinancialTransaction {
  id: number
  transaction_number: string
  transaction_date: string
  type: TransactionType
  debit_account: { id: number; code: string; name: string }
  credit_account: { id: number; code: string; name: string }
  amount: number
  currency: string
  description: string | null
  reference_type: string | null
  reference_id: number | null
  status: 'POSTED' | 'REVERSED'
  created_by: string | null
  created_at: string
}

export interface TransferInput {
  from_account_id: number | null
  to_account_id: number | null
  amount: string
  date: string
  description: string
}

export interface AdjustmentInput {
  debit_account_id: number | null
  credit_account_id: number | null
  amount: string
  date: string
  description: string
}

export interface IncomeInput {
  revenue_account_id: number | null
  cash_account_id: number | null
  amount: string
  date: string
  description: string
}

export const financialTransactionsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<FinancialTransaction>> {
    const result = await apiGetWithMeta<FinancialTransaction[]>('/financial-transactions', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  transfer: (input: TransferInput) =>
    apiPost<FinancialTransaction>('/financial-transactions/transfer', {
      from_account_id: input.from_account_id,
      to_account_id: input.to_account_id,
      amount: Number(input.amount) || 0,
      date: input.date || undefined,
      description: input.description || null,
    }),

  adjustment: (input: AdjustmentInput) =>
    apiPost<FinancialTransaction>('/financial-transactions/adjustment', {
      debit_account_id: input.debit_account_id,
      credit_account_id: input.credit_account_id,
      amount: Number(input.amount) || 0,
      date: input.date || undefined,
      description: input.description,
    }),
}

export const incomeService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<FinancialTransaction>> {
    const result = await apiGetWithMeta<FinancialTransaction[]>('/income', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create: (input: IncomeInput) =>
    apiPost<FinancialTransaction>('/income', {
      revenue_account_id: input.revenue_account_id,
      cash_account_id: input.cash_account_id,
      amount: Number(input.amount) || 0,
      date: input.date || undefined,
      description: input.description || null,
    }),
}
