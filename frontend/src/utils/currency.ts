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
