import { apiGetWithMeta } from '@/services/http'

/** The lightweight shape every LookupSelect renders — never the full multi-language admin shape (see lookupValues.ts for that). */
export interface LookupOption {
  id: number
  code: string
  name: string
}

export interface LookupCategorySummary {
  id: number
  code: string
  name: string
}

export const lookupsService = {
  /** Active categories, e.g. for an admin picker when creating a new LookupValue. */
  async categories(): Promise<LookupCategorySummary[]> {
    const result = await apiGetWithMeta<LookupCategorySummary[]>('/lookups')
    return result.data
  },

  /**
   * A category's active values, resolved (with fallback) in the given
   * language — see backend/app/Services/BaseData/LookupQueryService.php.
   * Returns [] for an unknown/inactive category rather than throwing.
   */
  async values(categoryCode: string, lang: string): Promise<LookupOption[]> {
    const result = await apiGetWithMeta<LookupOption[]>(`/lookups/${categoryCode}`, { params: { lang } })
    return result.data
  },
}
