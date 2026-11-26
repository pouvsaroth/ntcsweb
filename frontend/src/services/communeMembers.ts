import { apiDelete, apiDownload, apiGet, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { PlaceBrief } from '@/services/partyLocations'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type MemberRole = 'full' | 'reserve'

/** Political Party > Commune members: an HRM staff member on a commune's full or reserve list for an election year. */
export interface CommuneMember {
  id: number
  election_year: number
  province: PlaceBrief | null
  district: PlaceBrief | null
  commune: PlaceBrief | null
  village: PlaceBrief | null
  role: MemberRole
  /** No. in the commune's full or reserve list (1, 2, …). */
  position: number
  remark: string | null
  /** From the staff record (edit it in HRM > Staff). */
  staff: {
    id: number
    employee_code: string | null
    name: string
    other_name: string | null
    gender: string | null
    /** yyyy-mm-dd */
    date_of_birth: string | null
    national_id: string | null
    phone: string | null
    position: string | null
    village: { name_km: string; name_latin: string } | null
    photo_url: string | null
    /** No longer working (left, suspended or deleted). */
    left: boolean
  } | null
}

export interface CommuneMemberInput {
  election_year: number
  commune_id: number
  staff_id: number
  role: MemberRole
  /** null = at the end of the list. */
  position: number | null
  remark: string | null
}

/** The pick's counts: full members vs the communes' council seats (Election offices). */
export interface MemberSummary {
  election_year: number
  place: PlaceBrief | null
  full: number
  reserve: number
  communes: number
  seats: number
  communes_with_seats: number
}

/** A working staff member to pick, and the list they are already on in the year. */
export interface MemberStaffOption {
  id: number
  name: string
  employee_code: string | null
  gender: string | null
  phone: string | null
  taken: { member_id: number; role: MemberRole; commune: PlaceBrief | null } | null
}

export interface MemberImportResult {
  added: number
  moved: number
  problems: { sheet: string; row: number; message: string }[]
}

/** The year, place picks and role go as plain query params. */
const listParams = (query: PaginatedQuery) => ({ page: query.page, per_page: query.per_page, search: query.search, ...query.filter })

export const communeMembersService = {
  years: () => apiGet<number[]>('/commune-members/years'),
  async list(query: PaginatedQuery): Promise<PaginatedResult<CommuneMember> & { summary: MemberSummary }> {
    const result = await apiGetWithMeta<CommuneMember[]>('/commune-members', { params: listParams(query) })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta, summary: result.meta?.summary as MemberSummary }
  },
  staffOptions: (year: number) => apiGet<MemberStaffOption[]>('/commune-members/staff-options', { params: { election_year: year } }),
  create: (input: CommuneMemberInput) => apiPost<CommuneMember>('/commune-members', input),
  update: (id: number, input: CommuneMemberInput) => apiPut<CommuneMember>(`/commune-members/${id}`, input),
  remove: (id: number) => apiDelete(`/commune-members/${id}`),
  template: () => apiDownload('/commune-members/template', 'commune-members-template.xlsx'),
  importFile(year: number, file: File) {
    const form = new FormData()
    form.append('election_year', String(year))
    form.append('file', file)
    return apiPost<MemberImportResult>('/commune-members/import', form)
  },
}
