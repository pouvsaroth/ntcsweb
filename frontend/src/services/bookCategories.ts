import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface BookCategory {
  id: number
  name: string
  academic_program_id: number
  academic_program?: { id: number; code: string; name: string } | null
  is_active: boolean
  books_count?: number
  created_at: string
}

export interface BookCategoryInput {
  name: string
  academic_program_id: number
  is_active: boolean
}

export const bookCategoriesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<BookCategory>> {
    const result = await apiGetWithMeta<BookCategory[]>('/book-categories', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active category for one academic program — drives the Book form's Category dropdown once a program is picked. */
  async listAllForProgram(academicProgramId: number): Promise<BookCategory[]> {
    const result = await apiGetWithMeta<BookCategory[]>('/book-categories', {
      params: { per_page: 200, filter: { academic_program_id: academicProgramId, is_active: 'true' } },
    })
    return result.data
  },

  create: (input: BookCategoryInput) => apiPost<BookCategory>('/book-categories', input),
  update: (id: number, input: BookCategoryInput) => apiPut<BookCategory>(`/book-categories/${id}`, input),
  remove: (id: number) => apiDelete(`/book-categories/${id}`),
}
