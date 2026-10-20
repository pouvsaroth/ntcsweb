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
  /** Its main menus (modules), shown on the card. */
  modules: { label: string; label_km: string }[]
}

/** The system a plans page sells (stage 9). */
export interface PublicSystem {
  code: string
  name: string
  name_km: string | null
  description: string | null
  description_km: string | null
  logo_url: string | null
  is_school: boolean
}

export interface PublicPlans {
  system: PublicSystem
  trial_days: number
  plans: PublicPlan[]
}

export const publicPlansService = {
  /** One system's plans; none = the school system. */
  list: (system?: string | null) => apiGet<PublicPlans>('/public/plans', { params: { system: system || undefined } }),
}
