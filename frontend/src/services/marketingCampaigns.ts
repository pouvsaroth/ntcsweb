import { apiDelete, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Marketing > Campaigns — see backend MarketingCampaignController. */
export const CAMPAIGN_STATUSES = ['planned', 'active', 'completed', 'cancelled'] as const
export type CampaignStatus = (typeof CAMPAIGN_STATUSES)[number]
export type CampaignCurrency = 'USD' | 'KHR'

export interface MarketingCampaign {
  id: number
  /** C00001 — given from the id when added. */
  code: string | null
  name: string
  /** A MARKETING_CHANNEL Base Data code. */
  channel: string
  start_on: string
  end_on: string | null
  currency: CampaignCurrency
  budget: number | null
  /** The real cost so far. */
  spent: number | null
  academic_program_id: number | null
  program?: { id: number; name: string } | null
  target: string | null
  status: CampaignStatus
  description: string | null
  /** Inquiries that picked this campaign. */
  leads_count: number
  /** Of those, the ones that became students. */
  enrolled_count: number
  cost_per_lead: number | null
  cost_per_student: number | null
  created_at: string
}

export interface MarketingCampaignInput {
  name: string
  channel: string
  start_on: string
  end_on: string | null
  currency: CampaignCurrency
  budget: number | null
  spent: number | null
  academic_program_id: number | null
  target: string | null
  status: CampaignStatus
  description: string | null
}

/** A campaign in the Inquiry form's picker. */
export interface CampaignOption {
  id: number
  code: string | null
  name: string
  channel: string
  status: CampaignStatus
}

export const marketingCampaignsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<MarketingCampaign>> {
    const result = await apiGetWithMeta<MarketingCampaign[]>('/marketing-campaigns', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  get: (id: number) => apiGet<MarketingCampaign>(`/marketing-campaigns/${id}`),
  create: (input: MarketingCampaignInput) => apiPost<MarketingCampaign>('/marketing-campaigns', input),
  update: (id: number, input: Partial<MarketingCampaignInput>) => apiPut<MarketingCampaign>(`/marketing-campaigns/${id}`, input),
  remove: (id: number) => apiDelete(`/marketing-campaigns/${id}`),
  /** For the Inquiry form (front-desk users may not see Marketing); `include` keeps the picked one. */
  options: (include?: number | null) => apiGet<CampaignOption[]>('/inquiries/campaign-options', { params: { include: include ?? undefined } }),
}
