import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/** A real, tenant-owned school year (e.g. "2026") that a Program Offering is scheduled under. */
export interface AcademicYear {
  id: number
  name: string
  start_date: string | null
  end_date: string | null
  is_current: boolean
  created_at: string
}

export interface AcademicYearInput {
  name: string
  start_date: string
  end_date: string
  is_current: boolean
}

export const academicYearsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AcademicYear>> {
    const result = await apiGetWithMeta<AcademicYear[]>('/academic-years', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async listAll(): Promise<AcademicYear[]> {
    const result = await apiGetWithMeta<AcademicYear[]>('/academic-years', { params: { per_page: 200, sort: '-name' } })
    return result.data
  },

  create: (input: Partial<AcademicYearInput>) => apiPost<AcademicYear>('/academic-years', input),
  update: (id: number, input: Partial<AcademicYearInput>) => apiPut<AcademicYear>(`/academic-years/${id}`, input),
  remove: (id: number) => apiDelete(`/academic-years/${id}`),
}
