import { apiGet, apiPost } from '@/services/http'

/** What resources/lang/{locale}/invoice.php actually has translations for — see UpdateSchoolSettingsRequest. */
export type InvoiceLocale = 'en' | 'km'

/** The only two currencies anything in this app is ever recorded in. */
export type Currency = 'USD' | 'KHR'

export interface SchoolSettings {
  name: string
  email: string | null
  phone: string | null
  address: string | null
  /** Drives every invoice's language (labels + font) — see resources/views/pdf/invoice.blade.php. */
  locale: InvoiceLocale
  /** Which currency the admin Dashboard and Billing Dashboard convert mixed USD/KHR totals into — see CurrencyConversionService. */
  default_currency: Currency
  logo_url: string | null
  /** The school's own static Bakong KHQR string (e.g. from ACLEDA Toanchet's "My QR") — see backend App\Support\Billing\Khqr. */
  khqr_template: string | null
}

export interface SchoolSettingsInput {
  name: string
  email: string
  phone: string
  address: string
  locale: InvoiceLocale
  default_currency: Currency
  khqr_template: string
  /** Omitted when the admin isn't replacing the logo. */
  logo?: File
}

/**
 * Optional text fields travel as an omitted key rather than an empty string —
 * FormRequest's `nullable` rule exempts a missing/null value from the
 * `email` format check, but an empty string is neither, so it would fail
 * validation instead of being treated as "not set".
 */
function toFormData(input: SchoolSettingsInput): FormData {
  const form = new FormData()

  form.append('name', input.name)
  if (input.email.trim()) form.append('email', input.email.trim())
  if (input.phone.trim()) form.append('phone', input.phone.trim())
  if (input.address.trim()) form.append('address', input.address.trim())
  form.append('locale', input.locale)
  form.append('default_currency', input.default_currency)
  if (input.khqr_template.trim()) form.append('khqr_template', input.khqr_template.trim())
  if (input.logo) form.append('logo', input.logo)

  return form
}

export const schoolSettingsService = {
  get: () => apiGet<SchoolSettings>('/settings/school'),
  save: (input: SchoolSettingsInput) => apiPost<SchoolSettings>('/settings/school', toFormData(input)),
}
