import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface StudyMode {
  id: number
  code: string
  name: string
  is_active: boolean
  sort_order: number
  created_at: string
}

export interface StudyModeInput {
  code: string
  name: string
  is_active: boolean
  sort_order: number
}

export const studyModesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<StudyMode>> {
    const result = await apiGetWithMeta<StudyMode[]>('/study-modes', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every study mode, for pickers — the list is always small (Full Time/Part Time plus whatever a school adds). */
  async listAll(): Promise<StudyMode[]> {
    const result = await apiGetWithMeta<StudyMode[]>('/study-modes', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  create: (input: StudyModeInput) => apiPost<StudyMode>('/study-modes', input),
  update: (id: number, input: StudyModeInput) => apiPut<StudyMode>(`/study-modes/${id}`, input),
  remove: (id: number) => apiDelete(`/study-modes/${id}`),
}
