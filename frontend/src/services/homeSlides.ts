import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface HomeSlide {
  id: number
  image_url: string
  title: string | null
  subtitle: string | null
  link_url: string | null
  sort_order: number
  status: 'active' | 'inactive'
  created_at: string
}

export interface HomeSlideInput {
  /** Omitted on update when the admin isn't replacing the image. */
  image?: File
  title: string
  subtitle: string
  link_url: string
  sort_order: number
  status: 'active' | 'inactive'
}

/**
 * A real file can't travel as JSON — every write here sends multipart
 * form-data. Laravel can't parse a file out of a literal HTTP PUT body
 * (PHP itself only populates $_FILES for POST, regardless of client), so an
 * update includes Laravel's standard `_method` override field and is sent as
 * a POST; the backend's apiResource `update` route still receives it via
 * that override, as documented on UpdateHomeSlideRequest.
 */
function toFormData(input: HomeSlideInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.image) form.append('image', input.image)
  if (input.title) form.append('title', input.title)
  if (input.subtitle) form.append('subtitle', input.subtitle)
  if (input.link_url) form.append('link_url', input.link_url)
  form.append('sort_order', String(input.sort_order))
  form.append('status', input.status)
  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const homeSlidesService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<HomeSlide>> {
    const result = await apiGetWithMeta<HomeSlide[]>('/home-slides', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create(input: HomeSlideInput) {
    return apiPost<HomeSlide>('/home-slides', toFormData(input))
  },

  update(id: number, input: HomeSlideInput) {
    return apiPost<HomeSlide>(`/home-slides/${id}`, toFormData(input, 'PUT'))
  },

  remove(id: number) {
    return apiDelete(`/home-slides/${id}`)
  },
}
