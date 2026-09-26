import { apiGet, apiPost } from '@/services/http'

/** What resources/lang/{locale}/invoice.php actually has translations for — see UpdateSchoolSettingsRequest. */
export type InvoiceLocale = 'en' | 'km'

/** The only two currencies anything in this app is ever recorded in. */
export type Currency = 'USD' | 'KHR'

export interface SchoolSettings {
  name: string
  /** Optional Latin-script name shown below `name` on the Login page. */
  name_en: string | null
  email: string | null
  phone: string | null
  address: string | null
  /** Drives every invoice's language (labels + font) — see resources/views/pdf/invoice.blade.php. */
  locale: InvoiceLocale
  /** Which currency the admin Dashboard and Billing Dashboard convert mixed USD/KHR totals into — see CurrencyConversionService. */
  default_currency: Currency
  logo_url: string | null
  /** The school's own stamp/seal image — printed on invoices/receipts next to the issuing staff member's signature. */
  stamp_url: string | null
  /** The school's own static Bakong KHQR string (e.g. from ACLEDA Toanchet's "My QR") — see backend App\Support\Billing\Khqr. */
  khqr_template: string | null
  /** How many days before a monthly-billed student's next payment is due the dashboard/student popup starts alerting. Always a number — the backend defaults it to 3. */
  monthly_payment_alert_days: number
  student_inactive_after_days: number
  /** The school-wide default exam fee — used to default the Print modal's Fee field, and the exam application self-service flow's own fee snapshot. Null until an admin sets one. */
  exam_fee_amount: string | null
}

export interface SchoolSettingsInput {
  name: string
  name_en: string
  email: string
  phone: string
  address: string
  locale: InvoiceLocale
  default_currency: Currency
  khqr_template: string
  /** Empty string means "use the default" — omitted from the request rather than sent as 0/blank. */
  monthly_payment_alert_days: string
  student_inactive_after_days: string
  /** Empty string means "not set yet" — omitted from the request rather than sent as 0/blank. Required before a student can self-submit an exam application (see ExamApplicationService::applyOnline()). */
  exam_fee_amount: string
  /** Omitted when the admin isn't replacing the logo. */
  logo?: File
  /** Omitted when the admin isn't replacing the stamp. */
  stamp?: File
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
  if (input.name_en.trim()) form.append('name_en', input.name_en.trim())
  if (input.email.trim()) form.append('email', input.email.trim())
  if (input.phone.trim()) form.append('phone', input.phone.trim())
  if (input.address.trim()) form.append('address', input.address.trim())
  form.append('locale', input.locale)
  form.append('default_currency', input.default_currency)
  if (input.khqr_template.trim()) form.append('khqr_template', input.khqr_template.trim())
  if (input.monthly_payment_alert_days.trim()) form.append('monthly_payment_alert_days', input.monthly_payment_alert_days.trim())
  if (input.student_inactive_after_days.trim()) form.append('student_inactive_after_days', input.student_inactive_after_days.trim())
  if (input.exam_fee_amount.trim()) form.append('exam_fee_amount', input.exam_fee_amount.trim())
  if (input.logo) form.append('logo', input.logo)
  if (input.stamp) form.append('stamp', input.stamp)

  return form
}

export const schoolSettingsService = {
  get: () => apiGet<SchoolSettings>('/settings/school'),
  save: (input: SchoolSettingsInput) => apiPost<SchoolSettings>('/settings/school', toFormData(input)),
}
