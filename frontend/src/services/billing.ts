import { apiGetWithMeta } from '@/services/http'

export type BillingSummaryMoney = 'todays_sales' | 'todays_payments' | 'outstanding' | 'overdue'

/**
 * The plain amounts are in `currency` (the school's); each also comes as
 * `<field>_usd` / `<field>_khr`, every row at the rate on its own date.
 */
export type BillingSummary = { currency: 'USD' | 'KHR' } & Record<BillingSummaryMoney | `${BillingSummaryMoney}_usd` | `${BillingSummaryMoney}_khr`, number> & {
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
  total_usd: number
  total_khr: number
}

export const billingService = {
  summary: () => apiGetWithMeta<BillingSummary>('/billing/dashboard').then((r) => r.data),

  paymentsByMethod: (params: { date_from?: string; date_to?: string } = {}) =>
    apiGetWithMeta<PaymentsByMethodRow[]>('/billing/reports/payments-by-method', { params }).then((r) => r.data),
}
