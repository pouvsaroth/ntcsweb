import { apiGet } from '@/services/http'
import type { InquirySource } from '@/services/inquiries'

/** Marketing > Report — see backend MarketingReport. */
export interface MarketingCounts {
  leads: number
  enrolled: number
  not_interested: number
  open: number
  /** Enrolled ÷ leads, in %. */
  conversion: number
}

/** Per currency — USD and KHR are never added together. */
export interface MoneyRow {
  currency: 'USD' | 'KHR'
  amount: number
}

export interface MarketingCampaignRow extends MarketingCounts {
  /** null: inquiries with no campaign. */
  id: number | null
  code: string | null
  name: string | null
  channel: string | null
  currency: 'USD' | 'KHR' | null
  spent: number | null
  cost_per_lead: number | null
  cost_per_student: number | null
  /** Paid by the students these leads became, since they asked. */
  fees: MoneyRow[]
}

export interface MarketingReportData {
  from: string
  to: string
  totals: MarketingCounts & { fees: MoneyRow[] }
  by_source: (MarketingCounts & { source: InquirySource })[]
  by_campaign: MarketingCampaignRow[]
}

export interface MarketingSummary extends MarketingCounts {
  from: string
  to: string
  top_source: InquirySource | null
  top_source_leads: number
}

export const marketingReportService = {
  get: (params: { from?: string; to?: string }) => apiGet<MarketingReportData>('/marketing-report', { params }),
  /** The dashboard card (this month by default). */
  summary: (params: { from?: string; to?: string } = {}) => apiGet<MarketingSummary>('/marketing-report/summary', { params }),
}
