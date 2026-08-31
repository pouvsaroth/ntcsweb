import { apiDownload, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type PaymentMethodValue = 'CASH' | 'BANK_TRANSFER' | 'ABA' | 'ACLEDA' | 'CARD' | 'OTHER'

export const paymentMethods: PaymentMethodValue[] = ['CASH', 'BANK_TRANSFER', 'ABA', 'ACLEDA', 'CARD', 'OTHER']

export type PaymentStatusValue = 'COMPLETED' | 'CANCELLED' | 'REFUNDED'

export const paymentStatuses: PaymentStatusValue[] = ['COMPLETED', 'CANCELLED', 'REFUNDED']

export interface Payment {
  id: number
  payment_number: string
  invoice_id: number
  invoice_number?: string | null
  student_id: number
  amount: number
  payment_method: PaymentMethodValue
  status: PaymentStatusValue
  payment_date: string | null
  reference_number: string | null
  received_by?: string | null
  notes: string | null
  cancellation_reason: string | null
  cancelled_at: string | null
  created_at: string
}

export interface PaymentInput {
  /** String while it travels through a form field; converted on submit. */
  amount: string
  payment_method: PaymentMethodValue
  payment_date: string
  reference_number: string
  notes: string
}

export function toPaymentPayload(input: PaymentInput) {
  return {
    amount: Number(input.amount) || 0,
    payment_method: input.payment_method,
    payment_date: input.payment_date || undefined,
    reference_number: input.reference_number || undefined,
    notes: input.notes || undefined,
  }
}

export const paymentsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Payment>> {
    const result = await apiGetWithMeta<Payment[]>('/payments', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  get: (id: number) => apiGetWithMeta<Payment>(`/payments/${id}`).then((r) => r.data),

  cancel: (id: number, reason: string) => apiPost<Payment>(`/payments/${id}/cancel`, { reason }),

  refund: (id: number, reason: string) => apiPost<Payment>(`/payments/${id}/refund`, { reason }),

  downloadReceipt: (id: number, paymentNumber: string) => apiDownload(`/payments/${id}/receipt`, `${paymentNumber}.pdf`),
}
