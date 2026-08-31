import { apiGet } from '@/services/http'

export interface GeographyOption {
  id: number
  code: string
  name_km: string
  name_latin: string
}

export interface VillageAncestry {
  province: GeographyOption
  district: GeographyOption
  commune: GeographyOption
  village: GeographyOption
}

export const geographyService = {
  provinces: () => apiGet<GeographyOption[]>('/geo/provinces'),
  districts: (provinceId: number) => apiGet<GeographyOption[]>('/geo/districts', { params: { province_id: provinceId } }),
  communes: (districtId: number) => apiGet<GeographyOption[]>('/geo/communes', { params: { district_id: districtId } }),
  villages: (communeId: number) => apiGet<GeographyOption[]>('/geo/villages', { params: { commune_id: communeId } }),

  /** Resolves a village's full ancestry from its code — for pre-selecting all four dropdowns when editing an existing student. */
  lookup: (villageCode: string) => apiGet<VillageAncestry>('/geo/lookup', { params: { village_code: villageCode } }),
}
