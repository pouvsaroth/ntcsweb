import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type LoanCustomerGender = 'male' | 'female'

/** Loan > Customers: people who keep savings with the owner or borrow from them. */
export interface LoanCustomer {
  id: number
  /** C00001 — given from the id when added. */
  code: string | null
  name: string
  gender: LoanCustomerGender | null
  phone: string | null
  id_card_no: string | null
  /** yyyy-mm-dd */
  date_of_birth: string | null
  address: string | null
  notes: string | null
  is_active: boolean
  created_at: string | null
}

export interface LoanCustomerInput {
  name: string
  gender: LoanCustomerGender | ''
  phone: string
  id_card_no: string
  date_of_birth: string
  address: string
  notes: string
  is_active: boolean
}

/** Blank boxes go up as null, not empty strings. */
const payload = (input: LoanCustomerInput) => ({
  ...input,
  gender: input.gender || null,
  phone: input.phone || null,
  id_card_no: input.id_card_no || null,
  date_of_birth: input.date_of_birth || null,
  address: input.address || null,
  notes: input.notes || null,
})

export const loanCustomersService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<LoanCustomer>> {
    const result = await apiGetWithMeta<LoanCustomer[]>('/loan-customers', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active customer — for the Savings and Owe forms. */
  async listAll(): Promise<LoanCustomer[]> {
    const result = await apiGetWithMeta<LoanCustomer[]>('/loan-customers', { params: { per_page: 500, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: LoanCustomerInput) => apiPost<LoanCustomer>('/loan-customers', payload(input)),
  update: (id: number, input: LoanCustomerInput) => apiPut<LoanCustomer>(`/loan-customers/${id}`, payload(input)),
  remove: (id: number) => apiDelete(`/loan-customers/${id}`),
}
