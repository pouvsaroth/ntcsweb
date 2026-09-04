import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
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
  thumbnail_url: string | null
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
  /** Whether this package's video lessons appear on the public Video Lesson page — independent of `show_on_website`/`show_in_popular`. */
  show_videos: boolean
  books?: { id: number; title: string; sort_order: number; is_required: boolean }[]
  created_at: string
}

export interface CoursePackageInput {
  code: string
  name: string
  academic_program_id: number
  description: string
  /** Omitted on update when the admin isn't replacing the thumbnail. */
  thumbnail?: File
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
  show_videos: boolean
  book_ids: number[]
}

/**
 * A real file can't travel as JSON — every write here sends multipart
 * form-data, same as HomeSlide's own toFormData(). Laravel can't parse a
 * file out of a literal HTTP PUT body, so an update includes Laravel's
 * standard `_method` override field and is sent as a POST — the backend's
 * apiResource `update` route still receives it via that override.
 */
function toFormData(input: Partial<CoursePackageInput>, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.code !== undefined) form.append('code', input.code)
  if (input.name !== undefined) form.append('name', input.name)
  if (input.academic_program_id !== undefined) form.append('academic_program_id', String(input.academic_program_id))
  if (input.description !== undefined) form.append('description', input.description)
  if (input.thumbnail) form.append('thumbnail', input.thumbnail)
  if (input.fee_monthly !== undefined && input.fee_monthly !== null) form.append('fee_monthly', String(input.fee_monthly))
  if (input.fee_term !== undefined && input.fee_term !== null) form.append('fee_term', String(input.fee_term))
  if (input.fee_video !== undefined && input.fee_video !== null) form.append('fee_video', String(input.fee_video))
  if (input.fee_monthly_online !== undefined && input.fee_monthly_online !== null) form.append('fee_monthly_online', String(input.fee_monthly_online))
  if (input.fee_term_online !== undefined && input.fee_term_online !== null) form.append('fee_term_online', String(input.fee_term_online))
  if (input.currency !== undefined) form.append('currency', input.currency)
  if (input.duration !== undefined) form.append('duration', input.duration)
  if (input.is_active !== undefined) form.append('is_active', input.is_active ? '1' : '0')
  if (input.show_on_website !== undefined) form.append('show_on_website', input.show_on_website ? '1' : '0')
  if (input.show_in_popular !== undefined) form.append('show_in_popular', input.show_in_popular ? '1' : '0')
  if (input.show_videos !== undefined) form.append('show_videos', input.show_videos ? '1' : '0')
  if (input.book_ids !== undefined) input.book_ids.forEach((id) => form.append('book_ids[]', String(id)))
  if (methodOverride) form.append('_method', methodOverride)

  return form
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
  create: (input: CoursePackageInput) => apiPost<CoursePackage>('/course-packages', toFormData(input)),
  update: (id: number, input: Partial<CoursePackageInput>) => apiPost<CoursePackage>(`/course-packages/${id}`, toFormData(input, 'PUT')),
  remove: (id: number) => apiDelete(`/course-packages/${id}`),
}
