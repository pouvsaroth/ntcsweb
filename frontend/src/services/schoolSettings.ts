import { apiGet, apiPost } from '@/services/http'

export interface SchoolSettings {
  name: string
  email: string | null
  phone: string | null
  address: string | null
  logo_url: string | null
}

export interface SchoolSettingsInput {
  name: string
  email: string
  phone: string
  address: string
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
  if (input.logo) form.append('logo', input.logo)

  return form
}

export const schoolSettingsService = {
  get: () => apiGet<SchoolSettings>('/settings/school'),
  save: (input: SchoolSettingsInput) => apiPost<SchoolSettings>('/settings/school', toFormData(input)),
}
