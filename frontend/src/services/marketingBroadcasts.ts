import { apiDelete, apiGet, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Marketing > Broadcast — see backend MarketingBroadcastController. */
export type BroadcastStatus = 'sent' | 'partial' | 'failed'

export interface BroadcastResult {
  group_id: number
  group_name: string
  ok: boolean
  /** Telegram's reason when it failed. */
  message: string | null
}

export interface MarketingBroadcast {
  id: number
  title: string
  message: string
  status: BroadcastStatus
  results: BroadcastResult[]
  campaign: { id: number; code: string | null; name: string } | null
  marketing_campaign_id: number | null
  sent_at: string
  created_by_name: string | null
}

export interface BroadcastTargets {
  bot_ready: boolean
  /** Linked under Settings > Telegram groups. */
  groups: { id: number; name: string; telegram_title: string | null }[]
  campaigns: { id: number; code: string | null; name: string }[]
}

export interface BroadcastInput {
  title: string
  message: string
  marketing_campaign_id: number | null
  group_ids: number[]
}

export const marketingBroadcastsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<MarketingBroadcast>> {
    const result = await apiGetWithMeta<MarketingBroadcast[]>('/marketing-broadcasts', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  targets: () => apiGet<BroadcastTargets>('/marketing-broadcasts/groups'),
  send: (input: BroadcastInput) => apiPost<MarketingBroadcast>('/marketing-broadcasts', input),
  remove: (id: number) => apiDelete(`/marketing-broadcasts/${id}`),
}
