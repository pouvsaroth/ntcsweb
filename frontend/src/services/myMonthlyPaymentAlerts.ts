import { apiGet } from '@/services/http'

export interface MyMonthlyPaymentAlert {
  course: string | null
  next_payment_date: string | null
}

/** Student self-service: does *this* student have a monthly payment coming due soon? Backs the public-site payment-due popup. */
export const myMonthlyPaymentAlertsService = {
  list: () => apiGet<MyMonthlyPaymentAlert[]>('/my-monthly-payment-alerts'),
}
