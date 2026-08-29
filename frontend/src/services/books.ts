import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Book {
  id: number
  title: string
  author: string | null
  isbn: string | null
  publisher: string | null
  description: string | null
  cover_image: string | null
  quantity: number
  /** Default/list price — not what a specific enrolled student is charged, see Enrollment.fee. */
  fee: number | null
  status: 'active' | 'inactive'
  classes_count?: number
  created_at: string
}

export interface BookInput {
  title: string
  author: string
  isbn: string
  publisher: string
  description: string
  quantity: number
  fee: number | null
  status: 'active' | 'inactive'
}

export const booksService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Book>> {
    const result = await apiGetWithMeta<Book[]>('/books', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /** Every active book, for the class form's book-menu multi-select — a school's catalog is small. */
  async listAll(): Promise<Book[]> {
    const result = await apiGetWithMeta<Book[]>('/books', { params: { per_page: 200, filter: { status: 'active' } } })
    return result.data
  },

  create: (input: BookInput) => apiPost<Book>('/books', input),
  update: (id: number, input: BookInput) => apiPut<Book>(`/books/${id}`, input),
  remove: (id: number) => apiDelete(`/books/${id}`),
}
