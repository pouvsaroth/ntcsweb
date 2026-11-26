import { apiGetWithMeta } from '@/services/http'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type PlaceLevel = 'province' | 'district' | 'commune' | 'village'

export interface PlaceBrief {
  id: number
  code: string
  name_km: string
  name_latin: string
}

/** Political Party > Locations: one province, district, commune or village, with its path. */
export interface Place extends PlaceBrief {
  level: PlaceLevel
  unit_km: string
  unit_en: string
  province: PlaceBrief | null
  district: PlaceBrief | null
  commune: PlaceBrief | null
  /** How many places sit directly under it (0 for a village). */
  children: number
}

export interface PlaceQuery {
  page: number
  per_page?: number
  province_id?: number | null
  district_id?: number | null
  commune_id?: number | null
  level?: PlaceLevel | ''
  search?: string
}

export const partyLocationsService = {
  /**
   * No search: the level under the deepest pick (provinces when none).
   * A search looks through every level under the picks (or only `level`).
   */
  async list(query: PlaceQuery): Promise<PaginatedResult<Place>> {
    const params = Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined))
    const result = await apiGetWithMeta<Place[]>('/party/locations', { params })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
}
