import { apiGet } from '@/services/http'

type Currency = 'USD' | 'KHR'

// Fund > Reports: one period, per currency (USD and KHR never added together).

export interface FundReportMoney {
  opening: number
  money_in: number
  money_out: number
  closing: number
}

export interface FundReportTotal extends FundReportMoney {
  currency: Currency
  funds: number
}

export interface FundReportType extends FundReportTotal {
  fund_type: string
}

export interface FundReportFund extends FundReportMoney {
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
}

export interface FundReport {
  from: string
  to: string
  totals: FundReportTotal[]
  types: FundReportType[]
  funds: FundReportFund[]
  providers: FundReportProvider[]
}

export const fundReportsService = {
  get: (params: { from?: string; to?: string; fund_type?: string }) => apiGet<FundReport>('/fund-reports', { params }),
}
