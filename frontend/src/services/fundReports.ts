import { apiGet } from '@/services/http'

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

/** Money in during the period by who gave it; provider_id null = money in with no provider named. */
export interface FundReportProvider {
  provider_id: number | null
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
