import { apiGet } from '@/services/http'

/**
 * The website's plans page (docs/backoffice/stage-8-signup.md, 8a): the
 * plans ticked "Show on the website" in back office → Billing → Plans.
 */
export interface PublicPlan {
  code: string
  name: string
  name_km: string | null
  tagline_en: string | null
  tagline_km: string | null
  features_en: string[]
  features_km: string[]
  price: string
  currency: 'USD' | 'KHR'
  /** Months one payment covers; null = paid once. */
  period_months: number | null
  max_students: number | null
  recommended: boolean
}

export interface PublicPlans {
  trial_days: number
  plans: PublicPlan[]
}

export const publicPlansService = {
  list: () => apiGet<PublicPlans>('/public/plans'),
}
