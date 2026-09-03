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
export type CoursePackageCurrency = 'USD' | 'KHR'

export interface CoursePackage {
  id: number
  code: string
  name: string
  academic_program_id: number
  academic_program: { id: number; code: string; name: string } | null
  description: string | null
  /** The legacy catalog price — still what enrollment billing reads today; derived server-side from whichever fee tier is set. */
  price: number
  fee_monthly: number | null
  fee_term: number | null
  fee_video: number | null
  fee_monthly_online: number | null
  fee_term_online: number | null
  currency: CoursePackageCurrency
  duration: string | null
  product_id: number | null
  is_active: boolean
  /** Independent of `is_active` — a package can be sold in-house but kept off the public site, or vice versa. */
  show_on_website: boolean
  /** Independent of `show_on_website` — controls the homepage's "Popular Programs" section, not the full public course catalog. */
  show_in_popular: boolean
  books?: { id: number; title: string; sort_order: number; is_required: boolean }[]
  created_at: string
}

export interface CoursePackageInput {
  code: string
  name: string
  academic_program_id: number
  description: string
  fee_monthly: number | null
  fee_term: number | null
  fee_video: number | null
  fee_monthly_online: number | null
  fee_term_online: number | null
  currency: CoursePackageCurrency
  duration: string
  is_active: boolean
  show_on_website: boolean
  show_in_popular: boolean
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
