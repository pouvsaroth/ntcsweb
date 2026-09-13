/**
 * Dashboard totals arrive pre-converted into one currency (see
 * BillingDashboardController/AccountingDashboardController) — this just
 * renders that currency correctly. KHR is conventionally shown as a rounded,
 * thousands-separated whole number (no subunit in everyday use); USD keeps
 * its usual two decimal places.
 */
export function formatMoney(amount: number, currency: 'USD' | 'KHR'): string {
  if (currency === 'KHR') {
    return `${Math.round(amount).toLocaleString()} ៛`
  }

  return `$${amount.toFixed(2)}`
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
