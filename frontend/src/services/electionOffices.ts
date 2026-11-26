import { apiDelete, apiDownload, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { PlaceBrief } from '@/services/partyLocations'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Political Party > Election offices: where people vote in one election year. */
export interface ElectionOffice {
  id: number
  election_year: number
  code: string
  name: string
  province: PlaceBrief | null
  district: PlaceBrief | null
  commune: PlaceBrief | null
  village: PlaceBrief | null
  location: string | null
  voters: number | null
}

export interface ElectionOfficeInput {
  election_year: number
  code: string
  name: string
  commune_id: number
  village_id: number | null
  location: string | null
  voters: number | null
}

/** A commune's council seats in one election year, with its offices and voters. */
export interface CommuneSeats {
  id: number
  election_year: number
  province: PlaceBrief | null
  district: PlaceBrief | null
  commune: PlaceBrief | null
  seats: number
  offices: number
  voters: number
}

/** The picked place's totals; seats are null when a village is picked (seats belong to communes). */
export interface ElectionSummary {
  election_year: number
  /** The deepest place picked, or null for everywhere. */
  place: PlaceBrief | null
  offices: number
  voters: number
  seats: number | null
  communes_with_seats: number | null
}

export interface ElectionImportResult {
  offices_added: number
  offices_changed: number
  seats_added: number
  seats_changed: number
  problems: { sheet: string; row: number; message: string }[]
}

/** The place picks and year go as plain query params (not filter[…]). */
const listParams = (query: PaginatedQuery) => ({ page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, ...query.filter })

export const electionOfficesService = {
  years: () => apiGet<number[]>('/election-offices/years'),
  async list(query: PaginatedQuery): Promise<PaginatedResult<ElectionOffice> & { summary: ElectionSummary }> {
    const result = await apiGetWithMeta<ElectionOffice[]>('/election-offices', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta, summary: result.meta?.summary as ElectionSummary }
  },
  create: (input: ElectionOfficeInput) => apiPost<ElectionOffice>('/election-offices', input),
  update: (id: number, input: ElectionOfficeInput) => apiPut<ElectionOffice>(`/election-offices/${id}`, input),
  remove: (id: number) => apiDelete(`/election-offices/${id}`),

  async seats(query: PaginatedQuery): Promise<PaginatedResult<CommuneSeats>> {
    const result = await apiGetWithMeta<CommuneSeats[]>('/election-seats', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },
  saveSeats: (input: { election_year: number; commune_id: number; seats: number }) => apiPut<CommuneSeats>('/election-seats', input),
  removeSeats: (id: number) => apiDelete(`/election-seats/${id}`),

  template: () => apiDownload('/election-offices/template', 'election-offices-template.xlsx'),
  importFile(year: number, file: File) {
    const form = new FormData()
    form.append('election_year', String(year))
    form.append('file', file)
    return apiPost<ElectionImportResult>('/election-offices/import', form)
  },
}
