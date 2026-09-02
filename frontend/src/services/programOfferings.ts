import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** Program + Study Mode + academic/session config — e.g. "Computer - Part Time - 2026". */
export interface ProgramOffering {
  id: number
  name: string
  academic_program_id: number
  academic_program: { id: number; code: string; name: string } | null
  study_mode_id: number
  study_mode: { id: number; code: string; name: string } | null
  academic_year_id: number | null
  academic_year: { id: number; name: string } | null
  status: 'active' | 'closed'
  created_at: string
}

export interface ProgramOfferingInput {
  academic_program_id: number
  study_mode_id: number
  academic_year_id: number | null
  name: string
  status: 'active' | 'closed'
}

export const programOfferingsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<ProgramOffering>> {
    const result = await apiGetWithMeta<ProgramOffering[]>('/program-offerings', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async listAll(): Promise<ProgramOffering[]> {
    const result = await apiGetWithMeta<ProgramOffering[]>('/program-offerings', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },

  create: (input: ProgramOfferingInput) => apiPost<ProgramOffering>('/program-offerings', input),
  update: (id: number, input: Partial<ProgramOfferingInput>) => apiPut<ProgramOffering>(`/program-offerings/${id}`, input),
  remove: (id: number) => apiDelete(`/program-offerings/${id}`),
}
