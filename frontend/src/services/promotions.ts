import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface Promotion {
  id: number
  image_url: string
  title: string | null
  sort_order: number
  status: 'active' | 'inactive'
  created_at: string
}

export interface PromotionInput {
  /** Omitted on update when the admin isn't replacing the image. */
  image?: File
  title: string
  sort_order: number
  status: 'active' | 'inactive'
}

/**
 * A real file can't travel as JSON — see gallery.ts's toFormData for why
 * this sends multipart form-data with a `_method=PUT` override on update.
 */
function toFormData(input: PromotionInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.image) form.append('image', input.image)
  if (input.title) form.append('title', input.title)
  form.append('sort_order', String(input.sort_order))
  form.append('status', input.status)
  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const promotionsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Promotion>> {
    const result = await apiGetWithMeta<Promotion[]>('/promotions', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create(input: PromotionInput) {
    return apiPost<Promotion>('/promotions', toFormData(input))
  },

  update(id: number, input: PromotionInput) {
    return apiPost<Promotion>(`/promotions/${id}`, toFormData(input, 'PUT'))
  },

  remove(id: number) {
    return apiDelete(`/promotions/${id}`)
  },
}
