import { apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Marketing > Referrals — see backend ReferralController. */
export type ReferralStatus = 'pending' | 'rewarded'

export interface ReferralStudent {
  id: number
  name: string
  student_code: string | null
}

export interface Referral {
  id: number
  status: ReferralStatus
  /** The student who brought the friend. */
  referrer: ReferralStudent | null
  /** The friend. */
  referred: ReferralStudent | null
  recorded_on: string | null
  rewarded_on: string | null
  /** The referrer's own copy of the reward code. */
  reward_code: { id: number; code: string; expires_on: string | null } | null
}

export interface ReferralSummary {
  total: number
  pending: number
  rewarded: number
  referrers: number
}

export interface RewardCodeOption {
  id: number
  code: string
  name: string
  discount_type: 'percent' | 'amount'
  value: number
  max_discount: number | null
  currency: 'USD' | 'KHR'
  status: 'active' | 'inactive'
  expires_on: string | null
}

export interface ReferralSettings {
  enabled: boolean
  reward_discount_code_id: number | null
  reward_code: RewardCodeOption | null
  /** Days the referrer's code lasts; null = the main code's own end date. */
  valid_days: number | null
}

export const referralsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Referral>> {
    const result = await apiGetWithMeta<Referral[]>('/referrals', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  summary: () => apiGet<ReferralSummary>('/referrals/summary'),
  settings: () => apiGet<ReferralSettings>('/referrals/settings'),
  saveSettings: (input: { enabled: boolean; reward_discount_code_id: number | null; valid_days: number | null }) => apiPut<ReferralSettings>('/referrals/settings', input),
  rewardOptions: () => apiGet<RewardCodeOption[]>('/referrals/reward-options'),
  give: (id: number) => apiPost<Referral>(`/referrals/${id}/give`, {}),
  /** "Referred by" search (Inquiry and Student forms). */
  students: (search: string, exclude?: number | null) => apiGet<ReferralStudent[]>('/referrals/student-options', { params: { search, exclude: exclude ?? undefined } }),
}
