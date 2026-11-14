/**
 * Dashboard totals arrive pre-converted into one currency (see
 * BillingDashboardController/AccountingDashboardController) — this just
 * renders that currency correctly. KHR is conventionally shown as a rounded,
 * thousands-separated whole number (no subunit in everyday use); USD keeps
 * its usual two decimal places.
 */
export function formatMoney(amount: number, currency: 'USD' | 'KHR'): string {
  // `|| 0` / the '-0.00' check: a value that rounds to zero (including
  // negative zero) shows as 0, never "-0".
  if (currency === 'KHR') {
    return `${(Math.round(amount) || 0).toLocaleString()} ៛`
  }

  // Thousands separators like the printed documents: "$29,242.65".
  const rounded = Math.round(amount * 100) / 100 || 0
  const text = Math.abs(rounded).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
  return rounded < 0 ? `-$${text}` : `$${text}`
}

/**
 * Mirrors the backend's CurrencyConversionService::convert() exactly — see
 * EnrollmentPackageForm.vue, which uses this to show a course package's
 * (usually USD) fee in the school's own billing currency before the
 * enrollment is actually submitted (the real conversion happens again,
 * authoritatively, server-side in EnrollmentService using that day's rate).
 * `khrPerUsd` null (no rate entered yet) passes the amount through
 * unconverted, same as the backend does.
 */
export function convertCurrency(amount: number, from: 'USD' | 'KHR', to: 'USD' | 'KHR', khrPerUsd: number | null): number {
  if (from === to || khrPerUsd === null || khrPerUsd <= 0) return amount

  return from === 'USD' && to === 'KHR' ? amount * khrPerUsd : amount / khrPerUsd
}
