import { apiDelete, apiGetWithMeta, apiPost } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export interface GalleryPhoto {
  id: number
  image_url: string
  caption: string | null
  sort_order: number
  status: 'active' | 'inactive'
  created_at: string
}

export interface GalleryPhotoInput {
  /** Omitted on update when the admin isn't replacing the image. */
  image?: File
  caption: string
  sort_order: number
  status: 'active' | 'inactive'
}

/**
 * A real file can't travel as JSON — see homeSlides.ts's toFormData for why
 * this sends multipart form-data with a `_method=PUT` override on update.
 */
function toFormData(input: GalleryPhotoInput, methodOverride?: 'PUT'): FormData {
  const form = new FormData()

  if (input.image) form.append('image', input.image)
  if (input.caption) form.append('caption', input.caption)
  form.append('sort_order', String(input.sort_order))
  form.append('status', input.status)
  if (methodOverride) form.append('_method', methodOverride)

  return form
}

export const galleryService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<GalleryPhoto>> {
    const result = await apiGetWithMeta<GalleryPhoto[]>('/gallery', {
      params: { page: query.page, per_page: query.per_page, sort: query.sort },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  create(input: GalleryPhotoInput) {
    return apiPost<GalleryPhoto>('/gallery', toFormData(input))
  },

  update(id: number, input: GalleryPhotoInput) {
    return apiPost<GalleryPhoto>(`/gallery/${id}`, toFormData(input, 'PUT'))
  },

  remove(id: number) {
    return apiDelete(`/gallery/${id}`)
  },
}
