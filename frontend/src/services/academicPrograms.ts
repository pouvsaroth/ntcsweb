import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { Book } from '@/services/books'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * The broad curriculum area a school teaches (English/Chinese/Computer) —
 * NOT the public marketing "Program" shown on the website. See
 * backend/app/Models/AcademicProgram.php for why these are two separate
 * concepts sharing a similar name.
 */
export interface AcademicProgram {
  id: number
  code: string
  name: string
  description: string | null
  is_active: boolean
  sort_order: number
  books?: Book[]
  created_at: string
}

export interface AcademicProgramInput {
  code: string
  name: string
  description: string
  is_active: boolean
  sort_order: number
}

export const academicProgramsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<AcademicProgram>> {
    const result = await apiGetWithMeta<AcademicProgram[]>('/academic-programs', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async listAll(): Promise<AcademicProgram[]> {
    const result = await apiGetWithMeta<AcademicProgram[]>('/academic-programs', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: AcademicProgramInput) => apiPost<AcademicProgram>('/academic-programs', input),
  update: (id: number, input: AcademicProgramInput) => apiPut<AcademicProgram>(`/academic-programs/${id}`, input),
  remove: (id: number) => apiDelete(`/academic-programs/${id}`),
}
