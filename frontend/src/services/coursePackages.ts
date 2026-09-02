import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

/**
 * The priced, purchasable registration item a student actually pays for —
 * e.g. "MS Word 2024" at $24 — bundling several Books taught together as
 * one class curriculum. There is no separate "Course" concept — a Book
 * already is "a subject a student can take, with a fee". `price` is the
 * current catalog price; an already-issued invoice keeps whatever price it
 * was billed at forever, see backend/app/Services/Academic/CoursePackageService.php.
 */
export interface CoursePackage {
  id: number
  code: string
  name: string
  academic_program_id: number
  academic_program: { id: number; code: string; name: string } | null
  description: string | null
  price: number
  duration: string | null
  product_id: number | null
  is_active: boolean
  books?: { id: number; title: string; fee: number | null; sort_order: number; is_required: boolean }[]
  created_at: string
}

export interface CoursePackageInput {
  code: string
  name: string
  academic_program_id: number
  description: string
  price: number
  duration: string
  is_active: boolean
  book_ids: number[]
}

export const coursePackagesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<CoursePackage>> {
    const result = await apiGetWithMeta<CoursePackage[]>('/course-packages', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })
    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  async listAll(): Promise<CoursePackage[]> {
    const result = await apiGetWithMeta<CoursePackage[]>('/course-packages', { params: { per_page: 200, filter: { is_active: 'true' } } })
    return result.data
  },

  get: (id: number) => apiGetWithMeta<CoursePackage>(`/course-packages/${id}`).then((r) => r.data),
  create: (input: CoursePackageInput) => apiPost<CoursePackage>('/course-packages', input),
  update: (id: number, input: Partial<CoursePackageInput>) => apiPut<CoursePackage>(`/course-packages/${id}`, input),
  remove: (id: number) => apiDelete(`/course-packages/${id}`),
}
