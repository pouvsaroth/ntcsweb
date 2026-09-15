import { apiGet } from '@/services/http'
import type { MonthlyInvoice } from '@/services/monthlyInvoices'

/** The admin dashboard's "students due for monthly payment" widget — a plain list, not paginated (see MonthlyPaymentAlertController). */
export const monthlyPaymentAlertsService = {
  list: () => apiGet<MonthlyInvoice[]>('/monthly-payment-alerts'),
}
