import { apiDelete, apiDownload, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { PlaceBrief, PlaceLevel } from '@/services/partyLocations'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Picked place ids ('' = none), as PlaceSelects edits them. */
export interface PlacePick {
  province_id: string
  district_id: string
  commune_id: string
  village_id: string
}

/** Political Party > Branches: a branch / office at a province, district, commune or village. */
export interface PartyBranch {
  id: number
  code: string | null
  name: string
  level: PlaceLevel
  province: PlaceBrief | null
  district: PlaceBrief | null
  commune: PlaceBrief | null
  village: PlaceBrief | null
  leader_name: string | null
  leader_phone: string | null
  address: string | null
  /** yyyy-mm-dd */
  opened_on: string | null
  is_active: boolean
  remark: string | null
  created_at: string | null
}

export interface PartyBranchInput {
  name: string
  level: PlaceLevel
  /** The place at `level` (its province, district, commune or village id). */
  location_id: number
  leader_name: string | null
  leader_phone: string | null
  address: string | null
  opened_on: string | null
  is_active: boolean
  remark: string | null
}

const listParams = (query: PaginatedQuery) => ({ page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter })

export const partyBranchesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<PartyBranch>> {
    const result = await apiGetWithMeta<PartyBranch[]>('/party-branches', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  create: (input: PartyBranchInput) => apiPost<PartyBranch>('/party-branches', input),
  update: (id: number, input: PartyBranchInput) => apiPut<PartyBranch>(`/party-branches/${id}`, input),
  remove: (id: number) => apiDelete(`/party-branches/${id}`),
  /** Every branch the filters and search keep, as Excel. */
  excel: (search: string, filter: Record<string, string>) => {
    const params = new URLSearchParams()
    if (search) params.set('search', search)
    for (const [key, value] of Object.entries(filter)) if (value) params.set(`filter[${key}]`, value)
    return apiDownload(`/party-branches/excel?${params.toString()}`, `party-branches-${new Date().toISOString().slice(0, 10)}.xlsx`)
  },
}
