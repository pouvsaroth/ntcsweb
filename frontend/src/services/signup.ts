import { apiGet, apiPost } from '@/services/http'

/** Self-service sign-up on the website (docs/backoffice/stage-8-signup.md, 8b). */
export interface SignupForm {
  /** A plan code, or 'trial'. */
  plan: string
  periods: number | null
  school_name: string
  school_name_en: string
  slug: string
  owner_name: string
  owner_email: string
  owner_phone: string
  password: string
  password_confirmation: string
  locale: string
  /** Hidden from people; a bot that fills it is turned away. */
  website: string
}

export type SignupStatus = 'pending_payment' | 'paid' | 'created' | 'expired' | 'failed'

export interface SignupState {
  token: string
  kind: 'trial' | 'paid'
  status: SignupStatus
  plan: { code: string; name: string; name_km: string | null } | null
  periods: number | null
  amount: string
  currency: 'USD' | 'KHR'
  school_name: string
  slug: string
  owner_email: string
  expires_at: string | null
  /** The KHQR to scan while unpaid; null when online payment is not set up. */
  khqr: string | null
  paid_at: string | null
  receipt_number: string | null
  /** provisioning → active · failed; null until the school exists. */
  school_status: 'provisioning' | 'active' | 'failed' | string | null
  sign_in_url: string | null
}

export const signupService = {
  checkSlug: (slug: string, email?: string) =>
    apiGet<{ slug: string; available: boolean; message: string | null }>('/public/signup/slug', { params: { slug, email: email || undefined } }),
  start: (form: SignupForm) => apiPost<SignupState>('/public/signup', form),
  show: (token: string) => apiGet<SignupState>(`/public/signup/${token}`),
}
