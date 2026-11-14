import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/currency'

type Currency = 'USD' | 'KHR'

/**
 * Shows an amount in the currency it is really in: the record's own
 * currency when it has one, otherwise the school's default currency (e.g. a
 * product price, an asset's value, or a report total the server already
 * converted to the school's currency). Never assume dollars — a KHR school's
 * 30,000 is 30,000 ៛, not $30,000.00.
 */
export function useMoney() {
  const auth = useAuthStore()

  const schoolCurrency = (): Currency => auth.tenantDefaultCurrency ?? 'USD'

  /** `currency` null/undefined/unknown = the school's currency. */
  const money = (amount: number | string | null | undefined, currency?: string | null): string =>
    formatMoney(Number(amount ?? 0), currency === 'KHR' || currency === 'USD' ? currency : schoolCurrency())

  return { money, schoolCurrency }
}
