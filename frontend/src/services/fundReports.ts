import { apiDownload, apiGet } from '@/services/http'

type Currency = 'USD' | 'KHR'

// Fund > Reports: one period. Each row is in its own currency and also in
// USD and KHR (`_usd` / `_khr`): money in / out at the rate on each
// transaction's date, balances at the rate on the day they are measured.

export interface FundReportMoney {
  opening: number
  money_in: number
  money_out: number
  closing: number
}

export type FundMoneyField = keyof FundReportMoney

export type FundReportBoth = Record<`${FundMoneyField}_usd` | `${FundMoneyField}_khr`, number>

export interface FundReportTotal extends FundReportMoney, FundReportBoth {
  currency: Currency
  funds: number
}

export interface FundReportType extends FundReportTotal {
  fund_type: string
}

export interface FundReportFund extends FundReportMoney, FundReportBoth {
  id: number
  fund_no: string | null
  name: string
  fund_type: string
  currency: Currency
  status: 'active' | 'closed'
  target_amount: number | null
}

/** Money in during the period by who gave it: a provider or a staff member; both null = no one named. */
export interface FundReportProvider {
  provider_id: number | null
  /** A staff member (HRM > Staff); `code` is then their employee code. */
  staff_id: number | null
  code: string | null
  name: string | null
  currency: Currency
  entries: number
  money_in: number
  money_in_usd: number
  money_in_khr: number
}

export interface FundReport {
  from: string
  to: string
  totals: FundReportTotal[]
  /** Every fund together, in USD and in KHR. */
  all: FundReportBoth & { funds: number }
  types: FundReportType[]
  funds: FundReportFund[]
  providers: FundReportProvider[]
}

export const fundReportsService = {
  get: (params: { from?: string; to?: string; fund_type?: string }) => apiGet<FundReport>('/fund-reports', { params }),
}

// Fund > Providers by month: money in per provider / staff member, one
// column per month (by For month; without one, the month of its date).

export interface FundProvidersByMonthRow {
  key: string
  provider_id: number | null
  staff_id: number | null
  code: string | null
  /** null: money in saved without a provider or staff member. */
  name: string | null
  currency: Currency
  /** 'YYYY-MM' -> amount, every month of the period. */
  months: Record<string, number>
  total: number
  entries: number
}

export interface FundProvidersByMonthTotal {
  currency: Currency
  months: Record<string, number>
  total: number
  /** Named providers / staff with money in. */
  people: number
}

export interface FundProvidersByMonth {
  from_month: string
  to_month: string
  months: string[]
  rows: FundProvidersByMonthRow[]
  /** One per currency, USD first. */
  totals: FundProvidersByMonthTotal[]
}

/** who: '' everyone, 'providers', 'staff', 'provider:<id>', 'staff:<id>'. */
export interface FundProvidersByMonthFilters {
  from_month?: string
  to_month?: string
  who?: string
  fund_type?: string
}

const byMonthParams = (filters: FundProvidersByMonthFilters): Record<string, string> =>
  Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) as Record<string, string>

export const fundProvidersByMonthService = {
  get: (filters: FundProvidersByMonthFilters) => apiGet<FundProvidersByMonth>('/fund-reports/providers-by-month', { params: byMonthParams(filters) }),
  excel: (filters: FundProvidersByMonthFilters) =>
    apiDownload(
      `/fund-reports/providers-by-month/excel?${new URLSearchParams(byMonthParams(filters)).toString()}`,
      `fund-providers-by-month-${new Date().toISOString().slice(0, 10)}.xlsx`,
    ),
}
