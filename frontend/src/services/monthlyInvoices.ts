import { apiGetWithMeta } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface MonthlyInvoiceStudent {
  id: number
  student_code: string
  name: string
}

export interface MonthlyInvoice {
  id: number
  student?: MonthlyInvoiceStudent
  course: string | null
  class: string | null
  start_date: string | null
  end_date: string | null
  status: string
  monthly_invoices_count: number
  next_payment_date: string | null
}

export const monthlyInvoicesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<MonthlyInvoice>> {
    const result = await apiGetWithMeta<MonthlyInvoice[]>('/monthly-invoices', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort, filter: query.filter },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
}
