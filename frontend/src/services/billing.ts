import { apiGetWithMeta } from '@/services/http'

export interface BillingSummary {
  todays_sales: number
  todays_payments: number
  outstanding: number
  overdue: number
  invoice_counts: {
    total: number
    paid: number
    partial: number
    unpaid: number
    overdue: number
    cancelled_or_void: number
  }
}

export interface PaymentsByMethodRow {
  payment_method: string
  count: number
  total: number
}

export const billingService = {
  summary: () => apiGetWithMeta<BillingSummary>('/billing/dashboard').then((r) => r.data),

  paymentsByMethod: (params: { date_from?: string; date_to?: string } = {}) =>
    apiGetWithMeta<PaymentsByMethodRow[]>('/billing/reports/payments-by-method', { params }).then((r) => r.data),
}
