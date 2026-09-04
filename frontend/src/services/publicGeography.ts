import { apiGet } from '@/services/http'
import type { GeographyOption } from '@/services/geography'

/**
 * Same Cambodia province/district/commune/village data as geography.ts, but
 * against the unauthenticated `/public/geo/*` routes — for the self-
 * registration wizard, where no visitor is signed in yet. No `lookup()`
 * counterpart: a fresh registration never has an existing village_code to
 * resolve back into its ancestry the way editing a student does.
 */
export const publicGeographyService = {
  provinces: () => apiGet<GeographyOption[]>('/public/geo/provinces'),
  districts: (provinceId: number) => apiGet<GeographyOption[]>('/public/geo/districts', { params: { province_id: provinceId } }),
  communes: (districtId: number) => apiGet<GeographyOption[]>('/public/geo/communes', { params: { district_id: districtId } }),
  villages: (communeId: number) => apiGet<GeographyOption[]>('/public/geo/villages', { params: { commune_id: communeId } }),
}
